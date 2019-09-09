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
 * Implementation of a canonical Huffman encoder / decoder as used by HPACK.
 *
 * Both encoder and decoder operate on input one byte at a time using (nested) lookup
 * tables. This approach is a reasonable compromise between memory usage (no need for a full
 * 4 byte lookup table) and processing time (no tree-walking and access to individual bits).
 *
 * @author Martin Schröder
 */
class HPackCompression extends HPackHuffman
{
    /**
     * Compress input using Huffman encoding.
     */
    public function compress(string $input): string
    {
        $len = \strlen($input);
        $encoded = \str_repeat("\x00", $len * 4);
        $pos = 0;
        
        $offset = 0;
        $buffer = 0;
        
        for ($i = 0; $i < $len; $i++) {
            foreach (self::ENCODER_TABLE[$input[$i]] as $code) {
                $buffer |= $code >> $offset;
                
                if (($offset += $code >> 24) > 7) {
                    $encoded[$pos++] = \chr($buffer >> 8);
                    
                    $offset -= 8;
                    $buffer <<= 8;
                }
            }
        }
        
        if ($offset > 0) {
            $encoded[$pos++] = \chr($buffer >> 8 | 0xFF >> $offset);
        }
        
        return \substr($encoded, 0, $pos);
    }
    
    /**
     * Decompress the given Huffman-encoded input.
     */
    public function decompress(string $input): string
    {
        $len = \strlen($input);
        $decoded = \str_repeat("\x00", (int) \ceil($len / 8 * 5 + 1));
        $pos = 0;
        
        $entry = null;
        
        $buffer = 0;
        $available = 0;
        $consumed = 0;
        
        for ($i = 0; $i < $len; $i++) {
            $buffer |= \ord($input[$i]) << (8 - $available);
            $available += 8;
            
            do {
                $entry = ($entry ?? self::DECODER_TABLE)[$buffer >> 8 & 0xFF];
                
                if (\is_array($entry)) {
                    $consumed = 8;
                } else {
                    $consumed = self::DECODER_LENS[$entry];
                    $decoded[$pos++] = $entry;
                    
                    $entry = null;
                }
                
                $buffer <<= $consumed;
                $available -= $consumed;
            } while ($available > 7);
        }
        
        while ($available > 0) {
            $entry = ($entry ?? self::DECODER_TABLE)[$buffer >> 8 & 0xFF] ?? null;
            
            if ($entry === null || \is_array($entry) || ($consumed = self::DECODER_LENS[$entry]) > $available) {
                break;
            }
            
            $decoded[$pos++] = $entry;
            $entry = null;
            
            $buffer <<= $consumed;
            $available -= $consumed;
        }
        
        if ($available > 0) {
            switch ($trailer = (($buffer >> 8 & 0xFF) >> (8 - $available))) {
                case 0b1:
                case 0b11:
                case 0b111:
                case 0b1111:
                case 0b11111:
                case 0b111111:
                case 0b1111111:
                    // Valid padding detected. :)
                    break;
                default:
                    throw new \RuntimeException(\sprintf('Invalid HPACK padding in compressed string detected: %08b', $trailer));
            }
        }
        
        return \substr($decoded, 0, $pos);
    }
}
