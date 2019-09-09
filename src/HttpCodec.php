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

namespace Concurrent\Http;

use Concurrent\Stream\ReadableStream;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

abstract class HttpCodec
{
    public const DATE_RFC1123 = 'D, d M Y H:i:s \G\M\T';
        
    private const HEADER_REGEX = "(^([^()<>@,;:\\\"/[\]?={}\x01-\x20\x7F]++):[ \t]*+((?:[ \t]*+[\x21-\x7E\x80-\xFF]++)*+)[ \t]*+\r\n)m";

    private const HEADER_FOLD_REGEX = "(\r\n[ \t]++)";
    
    public static function readBufferedChunk(StreamInterface $body, int $chunkSize, bool & $eof): string
    {
        $remaining = $chunkSize;
        $buffer = '';

        while ($remaining > 0) {
            $chunk = $body->read($remaining);
            $len = \strlen($chunk);

            $eof = $body->eof();

            if ($len == $chunkSize) {
                return $chunk;
            }

            $buffer .= $chunk;
            $remaining -= $len;

            if ($eof || $remaining == 0) {
                break;
            }
        }

        return $buffer;
    }

    protected function populateHeaders(MessageInterface $message, string $header): MessageInterface
    {
        $m = null;
        $count = \preg_match_all(self::HEADER_REGEX, $header, $m, \PREG_SET_ORDER);

        if ($count !== \substr_count($header, "\n")) {
            if (\preg_match(self::HEADER_FOLD_REGEX, $header)) {
                throw new \RuntimeException("Invalid HTTP header syntax: Obsolete line folding");
            }

            throw new \RuntimeException("Invalid HTTP header syntax");
        }

        foreach ($m as $v) {
            $message = $message->withAddedHeader($v[1], $v[2]);
        }

        return $message;
    }

    protected function decodeBody(ReadableStream $stream, MessageInterface $message, string & $buffer): MessageInterface
    {
        if ($message->hasHeader('Content-Length')) {
            $message = $message->withoutHeader('Transfer-Encoding');

            if (($len = (int) $message->getHeaderLine('Content-Length')) > 0) {
                $message = $message->withBody(new IteratorStream($this->readLengthDelimitedBody($stream, $len, $buffer)));
            } elseif ($stream instanceof ClientStream) {
                $stream->release();
            }

            return $message;
        }

        if ('chunked' == \strtolower($message->getHeaderLine('Transfer-Encoding'))) {
            $message = $message->withoutHeader('Transfer-Encoding');
            $message = $message->withBody(new IteratorStream($this->readChunkEncodedBody($stream, $buffer)));

            return $message;
        }

        if ($stream instanceof ClientStream) {
            if ($message->getProtocolVersion() == '1.0') {
                if ('keep-alive' === \strtolower($message->getHeaderLine('Connection'))) {
                    $stream->release();
                } else {
                    $stream->markDisposed();
                    $message = $message->withBody(new AsyncStream($stream));
                }
            } else {
                if ('close' === \strtolower($message->getHeaderLine('Connection'))) {
                    $stream->markDisposed();
                    $message = $message->withBody(new AsyncStream($stream));
                } else {
                    $stream->release();
                }
            }
        }

        return $message;
    }

    protected function readLengthDelimitedBody(ReadableStream $stream, int $len, string & $buffer): \Generator
    {
        try {
            while ($len > 0) {
                if ($buffer === '') {
                    $buffer = $stream->read();

                    if ($buffer === null) {
                        throw new \RuntimeException('Unexpected end of HTTP body stream');
                    }
                }

                $chunk = \substr($buffer, 0, $len);
                $buffer = \substr($buffer, \strlen($chunk));

                $len -= \strlen($chunk);

                yield $chunk;
            }

            if ($stream instanceof ClientStream) {
                $stream->release();
            }
        } catch (\Throwable $e) {
            if ($stream instanceof ClientStream) {
                $stream->close($e);
            }

            throw $e;
        }
    }

    protected function readChunkEncodedBody(ReadableStream $stream, string & $buffer): \Generator
    {
        try {
            while (true) {
                while (false === ($pos = \strpos($buffer, "\n"))) {
                    if (null === ($chunk = $stream->read())) {
                        throw new \RuntimeException('Unexpected end of HTTP body stream');
                    }
                    
                    $buffer .= $chunk;
                }
                
                $line = \trim(\preg_replace("';.*$'", '', \substr($buffer, 0, $pos)));
                $buffer = \substr($buffer, $pos + 1);
                
                if (!\ctype_xdigit($line) || \strlen($line) > 6) {
                    throw new \RuntimeException(\sprintf('Invalid HTTP chunk length received: "%s"', $line));
                }
                
                $remainder = \hexdec($line);
                
                if ($remainder === 0) {
                    $buffer = \substr($buffer, 2);
                    break;
                }
                
                while ($remainder > 0) {
                    if ($buffer === '') {
                        if (null === ($buffer = $stream->read())) {
                            throw new \RuntimeException('Unexpected end of HTTP body stream');
                        }
                    }
                    
                    $len = \strlen($buffer);
                    
                    if ($remainder > $len) {
                        $chunk = $buffer;
                        $remainder -= $len;
                        
                        $buffer = '';
                        
                        yield $chunk;
                    } else {
                        $chunk = \substr($buffer, 0, $remainder);
                        $len = \strlen($chunk);
                        
                        $buffer = \substr($buffer, $len);
                        $remainder -= $len;
                        
                        yield $chunk;
                    }
                }
                
                $buffer = \substr($buffer, 2);
            }
            
            if ($stream instanceof ClientStream) {
                $stream->release();
            }
        } catch (\Throwable $e) {
            if ($stream instanceof ClientStream) {
                $stream->close($e);
            }
            
            throw $e;
        }
    }
}
