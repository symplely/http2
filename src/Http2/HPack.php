<?php

/*
 * This file is part of Concurrent PHP HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Concurrent\Http\Http2;

/**
 * Implementation of HPACK header compression as specified in RFC 7541.
 * 
 * @link https://tools.ietf.org/html/rfc7541
 *
 * @author Martin Schröder
 */
class HPack
{
    /**
     * Max size of the dynamic table used by decoder.
     *
     * @var int
     */
    protected $decoderTableMaxSize = 4096;
    
    /**
     * Size of the dynmic table used by decoder.
     *
     * @var int
     */
    protected $decoderTableSize = 0;
    
    /**
     * Dynamic table being used by decoder.
     *
     * @var array
     */
    protected $decoderTable = [];
    
    /**
     * Size of the dynmic table used by encoder.
     *
     * @var int
     */
    protected $encoderTableSize = 0;
    
    /**
     * Max size of the dynamic table used by encoder.
     *
     * @var int
     */
    protected $encoderTableMaxSize = 4096;
    
    /**
     * Dynamic table being used by encoder.
     *
     * @var array
     */
    protected $encoderTable = [];
    
    /**
     * HPACK context.
     *
     * @var HPackContext
     */
    protected $context;
    
    /**
     * Huffman compression / decompression handler.
     *
     * @var HPackCompression
     */
    protected $compression;

    /**
     * Use compression in sent headers.
     * 
     * @var bool
     */
    protected $compressionEnabled;

    /**
     * Create a new HPACK encoder / decoder.
     */
    public function __construct(?HPackContext $context = null)
    {
        $this->context = $context ?? new HPackContext();
        
        $this->compression = $this->context->getCompression();
        $this->compressionEnabled = $this->context->isCompressionEnabled();
    }
    
    /**
     * Encode the given HTTP headers using HPACK header compression.
     *
     * Eeach header must be an array, element 0 must be the lowercased header name, element 1 holds the value.
     *
     * @param array $headers
     * @return string
     */
    public function encode(array $headers): string
    {
        $result = '';
        
        foreach ($headers as list ($k, $v)) {
            $index = self::STATIC_TABLE_LOOKUP[$k . ':' . $v] ?? null;
            
            if ($index !== null) {
                // Indexed Header Field
                $result .= \chr($index | 0x80);
                
                continue;
            }
            
            $index = self::STATIC_TABLE_LOOKUP[$k] ?? null;
            $encoding = $this->context->getEncoding($k);
            
            if ($encoding === HPackContext::INDEXED) {
                foreach ($this->encoderTable as $i => $header) {
                    if ($header[0] === $k && $header[1] === $v) {
                        $i += self::STATIC_TABLE_SIZE + 1;
                        
                        // Indexed Header Field
                        if ($i < 0x7F) {
                            $result .= \chr($i | 0x80);
                        } else {
                            $result .= "\xFF" . $this->encodeInt($i - 0x7F);
                        }
                        
                        continue 2;
                    }
                }
                
                \array_unshift($this->encoderTable, [
                    $k,
                    $v
                ]);
                
                $this->encoderTableSize += 32 + \strlen($k) + \strlen($v);
                
                while ($this->encoderTableSize > $this->decoderTableMaxSize) {
                    list ($name, $value) = \array_pop($this->encoderTable);
                    $this->encoderTableSize -= 32 + \strlen($name) + \strlen($value);
                }
                
                if ($index !== null) {
                    // Literal Header Field with Incremental Indexing — Indexed Name
                    if ($index < 0x40) {
                        $result .= \chr($index | 0x40);
                    } else {
                        $result .= "\x7F" . $this->encodeInt($index - 0x40);
                    }
                } else {
                    // Literal Header Field with Incremental Indexing — New Name
                    $result .= "\x40" . $this->encodeString($k);
                }
            } elseif ($index !== null) {
                // Literal Header Field without Indexing / never indexed — Indexed Name
                if ($index < 0x10) {
                    $result .= \chr($index | (($encoding === HPackContext::NEVER_INDEXED) ? 0x10 : 0x00));
                } else {
                    $result .= (($encoding === HPackContext::NEVER_INDEXED) ? "\x1F" : "\x0F") . $this->encodeInt($index - 0x0F);
                }
            } else {
                // Literal Header Field without Indexing / never indexed — New Name
                $result .= (($encoding === HPackContext::NEVER_INDEXED) ? "\x10" : "\x00") . $this->encodeString($k);
            }
            
            $result .= $this->encodeString($v);
        }
        
        return $result;
    }
    
    /**
     * Encode an integer value according to HPACK Integer Representation.
     *
     * @param int $int
     * @return string
     */
    protected function encodeInt(int $int): string
    {
        $result = '';
        $i = 0;
        
        while (($int >> $i) > 0x80) {
            $result .= \chr(0x80 | (($int >> $i) & 0x7F));
            $i += 7;
        }
        
        return $result . \chr($int >> $i);
    }
    
    /**
     * Encode a string literal.
     *
     * @param string $input
     * @return string
     */
    protected function encodeString(string $input): string
    {
        if ($this->compressionEnabled) {
            $input = $this->compression->compress($input);
            
            if (\strlen($input) < 0x7F) {
                return \chr(\strlen($input) | 0x80) . $input;
            }
            
            return "\xFF" . $this->encodeInt(\strlen($input) - 0x7F) . $input;
        }
        
        if (\strlen($input) < 0x7F) {
            return \chr(\strlen($input)) . $input;
        }
        
        return "\x7F" . $this->encodeInt(\strlen($input) - 0x7F) . $input;
    }
    
    /**
     * Decode the given HPACK-encoded HTTP headers.
     *
     * Returns an array of headers, each header is an array, element 0 is the name of the header and element 1 the value.
     *
     * @param string $encoded
     * @return array
     *
     * @throws \RuntimeException
     */
    public function decode(string $encoded): array
    {
        $headers = [];
        $encodedLength = \strlen($encoded);
        $offset = 0;
        
        while ($offset < $encodedLength) {
            $index = \ord($encoded[$offset++]);
            
            // Indexed Header Field Representation
            if ($index & 0x80) {
                if ($index <= self::STATIC_TABLE_SIZE + 0x80) {
                    if ($index === 0x80) {
                        throw new \RuntimeException(\sprintf('Cannot access index %X in static table', $index));
                    }
                    
                    $headers[] = self::STATIC_TABLE[$index - 0x80];
                } else {
                    if ($index == 0xFF) {
                        $index = self::decodeInt($encoded, $offset) + 0xFF;
                    }
                    
                    $index -= 0x81 + self::STATIC_TABLE_SIZE;
                    
                    if (!isset($this->decoderTable[$index])) {
                        throw new \RuntimeException(\sprintf('Missing index %X in dynamic table', $index));
                    }
                    
                    $headers[] = $this->decoderTable[$index];
                }
                
                continue;
            }
            
            // Literal Header Field Representation
            if (($index & 0x60) != 0x20) {
                $dynamic = ($index & 0x40) ? true : false;
                
                if ($index & ($dynamic ? 0x3F : 0x0F)) {
                    if ($dynamic) {
                        if ($index == 0x7F) {
                            $index = $this->decodeInt($encoded, $offset) + 0x3F;
                        } else {
                            $index &= 0x3F;
                        }
                    } else {
                        $index &= 0x0F;
                        
                        if ($index == 0x0F) {
                            $index = $this->decodeInt($encoded, $offset) + 0x0F;
                        }
                    }
                    
                    if ($index <= self::STATIC_TABLE_SIZE) {
                        $name = self::STATIC_TABLE[$index][0];
                    } else {
                        $name = $this->decoderTable[$index - self::STATIC_TABLE_SIZE][0];
                    }
                } else {
                    $name = $this->decodeString($encoded, $encodedLength, $offset);
                }
                
                if ($offset === $encodedLength) {
                    throw new \RuntimeException(\sprintf('Failed to decode value of header "%s"', $name));
                }
                
                $headers[] = $header = [
                    $name,
                    $this->decodeString($encoded, $encodedLength, $offset)
                ];
                
                if ($dynamic) {
                    \array_unshift($this->decoderTable, $header);
                    $this->decoderTableSize += 32 + \strlen($header[0]) + \strlen($header[1]);
                    
                    if ($this->decoderTableMaxSize < $this->decoderTableSize) {
                        $this->resizeDynamicTable();
                    }
                }
                
                continue;
            }
            
            // Dynamic Table Size Update
            if ($index == 0x3F) {
                $index = $this->decodeInt($encoded, $offset) + 0x40;
                
                if ($index > $this->decoderTableMaxSize) {
                    throw new \RuntimeException(\sprintf('Attempting to resize dynamic table to %u, limit is %u', $index, $this->decoderTableMaxSize));
                }
                
                $this->resizeDynamicTable($index);
            }
        }
        
        return $headers;
    }
    
    /**
     * Resize HPACK dynamic table.
     *
     * @param int $maxSize
     */
    protected function resizeDynamicTable(int $maxSize = null)
    {
        if ($maxSize !== null) {
            $this->decoderTableMaxSize = $maxSize;
        }
        
        while ($this->decoderTableSize > $this->decoderTableMaxSize) {
            list ($k, $v) = \array_pop($this->decoderTable);
            $this->decoderTableSize -= 32 + \strlen($k) + \strlen($v);
        }
    }
    
    /**
     * Decode an HPACK-encoded integer.
     *
     * @param string $encoded
     * @param int $offset
     * @return int
     */
    protected function decodeInt(string $encoded, int & $offset): int
    {
        $byte = \ord($encoded[$offset++]);
        $int = $byte & 0x7F;
        $i = 0;
        
        while ($byte & 0x80) {
            if (!isset($encoded[$offset])) {
                return -0x80;
            }
            
            $byte = \ord($encoded[$offset++]);
            $int += ($byte & 0x7F) << (++$i * 7);
        }
        
        return $int;
    }
    
    /**
     * Decode an HPACK String Literal.
     *
     * Huffman-encoded string literals are supported.
     *
     * @param string $encoded
     * @param int $encodedLength
     * @param int $offset
     * @return string
     *
     * @throws \RuntimeException
     */
    protected function decodeString(string $encoded, int $encodedLength, int & $offset): string
    {
        $len = \ord($encoded[$offset++]);
        $huffman = ($len & 0x80) ? true : false;
        $len &= 0x7F;
        
        if ($len == 0x7F) {
            $len = $this->decodeInt($encoded, $offset) + 0x7F;
        }
        
        if (($encodedLength - $offset) < $len || $len <= 0) {
            throw new \RuntimeException('Failed to read encoded string');
        }
        
        try {
            if ($huffman) {
                return $this->compression->decompress(\substr($encoded, $offset, $len));
            }
            
            return \substr($encoded, $offset, $len);
        } finally {
            $offset += $len;
        }
    }
    
    /**
     * Size of the static table.
     *
     * @var int
     */
    protected const STATIC_TABLE_SIZE = 61;
    
    /**
     * Static table, indexing starts at 1!
     *
     * @var array
     */
    protected const STATIC_TABLE = [
        1 => [
            ':authority',
            ''
        ],
        [
            ':method',
            'GET'
        ],
        [
            ':method',
            'POST'
        ],
        [
            ':path',
            '/'
        ],
        [
            ':path',
            '/index.html'
        ],
        [
            ':scheme',
            'http'
        ],
        [
            ':scheme',
            'https'
        ],
        [
            ':status',
            '200'
        ],
        [
            ':status',
            '204'
        ],
        [
            ':status',
            '206'
        ],
        [
            ':status',
            '304'
        ],
        [
            ':status',
            '400'
        ],
        [
            ':status',
            '404'
        ],
        [
            ':status',
            '500'
        ],
        [
            'accept-charset',
            ''
        ],
        [
            'accept-encoding',
            'gzip, deflate'
        ],
        [
            'accept-language',
            ''
        ],
        [
            'accept-ranges',
            ''
        ],
        [
            'accept',
            ''
        ],
        [
            'access-control-allow-origin',
            ''
        ],
        [
            'age',
            ''
        ],
        [
            'allow',
            ''
        ],
        [
            'authorization',
            ''
        ],
        [
            'cache-control',
            ''
        ],
        [
            'content-disposition',
            ''
        ],
        [
            'content-encoding',
            ''
        ],
        [
            'content-language',
            ''
        ],
        [
            'content-length',
            ''
        ],
        [
            'content-location',
            ''
        ],
        [
            'content-range',
            ''
        ],
        [
            'content-type',
            ''
        ],
        [
            'cookie',
            ''
        ],
        [
            'date',
            ''
        ],
        [
            'etag',
            ''
        ],
        [
            'expect',
            ''
        ],
        [
            'expires',
            ''
        ],
        [
            'from',
            ''
        ],
        [
            'host',
            ''
        ],
        [
            'if-match',
            ''
        ],
        [
            'if-modified-since',
            ''
        ],
        [
            'if-none-match',
            ''
        ],
        [
            'if-range',
            ''
        ],
        [
            'if-unmodified-since',
            ''
        ],
        [
            'last-modified',
            ''
        ],
        [
            'link',
            ''
        ],
        [
            'location',
            ''
        ],
        [
            'max-forwards',
            ''
        ],
        [
            'proxy-authentication',
            ''
        ],
        [
            'proxy-authorization',
            ''
        ],
        [
            'range',
            ''
        ],
        [
            'referer',
            ''
        ],
        [
            'refresh',
            ''
        ],
        [
            'retry-after',
            ''
        ],
        [
            'server',
            ''
        ],
        [
            'set-cookie',
            ''
        ],
        [
            'strict-transport-security',
            ''
        ],
        [
            'transfer-encoding',
            ''
        ],
        [
            'user-agent',
            ''
        ],
        [
            'vary',
            ''
        ],
        [
            'via',
            ''
        ],
        [
            'www-authenticate',
            ''
        ]
    ];
    
    /**
     * Lookup table being used to find indexes within the static table without using a loop.
     *
     * @var array
     */
    protected const STATIC_TABLE_LOOKUP = [
        ':authority' => 1,
        ':method' => 2,
        ':path' => 4,
        ':scheme' => 6,
        ':status' => 8,
        'accept-charset' => 15,
        'accept-encoding' => 16,
        'accept-language' => 17,
        'accept-ranges' => 18,
        'accept' => 19,
        'access-control-allow-origin' => 20,
        'age' => 21,
        'allow' => 22,
        'authorization' => 23,
        'cache-control' => 24,
        'content-disposition' => 25,
        'content-encoding' => 26,
        'content-language' => 27,
        'content-length' => 28,
        'content-location' => 29,
        'content-range' => 30,
        'content-type' => 31,
        'cookie' => 32,
        'date' => 33,
        'etag' => 34,
        'expect' => 35,
        'expires' => 36,
        'from' => 37,
        'host' => 38,
        'if-match' => 39,
        'if-modified-since' => 40,
        'if-none-match' => 41,
        'if-range' => 42,
        'if-unmodified-since' => 43,
        'last-modified' => 44,
        'link' => 45,
        'location' => 46,
        'max-forwards' => 47,
        'proxy-authentication' => 48,
        'proxy-authorization' => 49,
        'range' => 50,
        'referer' => 51,
        'retry-after' => 53,
        'server' => 54,
        'set-cookie' => 55,
        'strict-transport-security' => 56,
        'transfer-encoding' => 57,
        'user-agent' => 58,
        'vary' => 59,
        'via' => 60,
        'www-authenticate' => 61,
        ':authority:' => 1,
        ':method:GET' => 2,
        ':method:POST' => 3,
        ':path:/' => 4,
        ':path:/index.html' => 5,
        ':scheme:http' => 6,
        ':scheme:https' => 7,
        ':status:200' => 8,
        ':status:204' => 9,
        ':status:206' => 10,
        ':status:304' => 11,
        ':status:400' => 12,
        ':status:404' => 13,
        ':status:500' => 14,
        'accept-charset:' => 15,
        'accept-encoding:gzip, deflate' => 16,
        'accept-language:' => 17,
        'accept-ranges:' => 18,
        'accept:' => 19,
        'access-control-allow-origin:' => 20,
        'age:' => 21,
        'allow:' => 22,
        'authorization:' => 23,
        'cache-control:' => 24,
        'content-disposition:' => 25,
        'content-encoding:' => 26,
        'content-language:' => 27,
        'content-length:' => 28,
        'content-location:' => 29,
        'content-range:' => 30,
        'content-type:' => 31,
        'cookie:' => 32,
        'date:' => 33,
        'etag:' => 34,
        'expect:' => 35,
        'expires:' => 36,
        'from:' => 37,
        'host:' => 38,
        'if-match:' => 39,
        'if-modified-since:' => 40,
        'if-none-match:' => 41,
        'if-range:' => 42,
        'if-unmodified-since:' => 43,
        'last-modified:' => 44,
        'link:' => 45,
        'location:' => 46,
        'max-forwards:' => 47,
        'proxy-authentication:' => 48,
        'proxy-authorization:' => 49,
        'range:' => 50,
        'referer:' => 51,
        'refresh:' => 52,
        'retry-after:' => 53,
        'server:' => 54,
        'set-cookie:' => 55,
        'strict-transport-security:' => 56,
        'transfer-encoding:' => 57,
        'user-agent:' => 58,
        'vary:' => 59,
        'via:' => 60,
        'www-authenticate:' => 61
    ];
}
