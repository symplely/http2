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

class InflateStream extends StreamAdapter
{
    protected $stream;
    
    protected $context;

    public function __construct(StreamInterface $stream, int $encoding)
    {
        $this->stream = $stream;

        $this->context = \inflate_init($encoding);
    }

    public function close()
    {
        $this->buffer = null;
        $this->context = null;

        $this->stream->close();
    }

    protected function readNextChunk(): string
    {
        while (!$this->stream->eof()) {
            $chunk = \inflate_add($this->context, $this->stream->read(8192), \ZLIB_SYNC_FLUSH);

            if ($chunk !== '') {
                return $chunk;
            }
        }

        try {
            return \inflate_add($this->context, '', \ZLIB_FINISH);
        } finally {
            $this->context = null;
        }
    }
}
