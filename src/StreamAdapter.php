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

use Psr\Http\Message\StreamInterface;

abstract class StreamAdapter implements StreamInterface
{
    protected $buffer = '';

    protected $offset = 0;

    public function __destruct()
    {
        $this->close();
    }

    public function __toString()
    {
        return $this->getContents();
    }

    public function close()
    {
        $this->buffer = null;
    }

    public function isReadable()
    {
        return true;
    }

    public function isSeekable()
    {
        return false;
    }

    public function isWritable()
    {
        return false;
    }

    public function getContents()
    {
        $buffer = '';

        while (!$this->eof()) {
            $buffer .= $this->read(0xFFFF);
        }

        return $buffer;
    }

    public function read($length)
    {
        if ($this->buffer === null) {
            throw new \RuntimeException('Cannot read from closed stream');
        }

        if ($this->buffer === '') {
            $this->buffer = $this->readNextChunk();

            if ($this->buffer === '') {
                return '';
            }
        }

        $chunk = \substr($this->buffer, 0, $length);
        $len = \strlen($chunk);

        $this->buffer = \substr($this->buffer, $len);
        $this->offset += $len;

        return $chunk;
    }

    public function tell()
    {
        return $this->offset;
    }

    public function getMetadata($key = null)
    {
        return ($key === null) ? [] : null;
    }

    public function seek($offset, $whence = SEEK_SET)
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function getSize()
    {
        return null;
    }

    public function rewind()
    {
        throw new \RuntimeException('Stream is not seekable');
    }

    public function detach()
    {
        return null;
    }

    public function eof()
    {
        if ($this->buffer === null) {
            return true;
        }

        if ($this->buffer === '') {
            $this->buffer = $this->readNextChunk();
        }

        return $this->buffer === '';
    }

    public function write($string)
    {
        throw new \RuntimeException('Stream is not writable');
    }

    protected abstract function readNextChunk(): string;
}
