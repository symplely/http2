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

class DeflateStream extends StreamAdapter
{
    protected $stream;
    
    protected $context;

    public function __construct(StreamInterface $stream, int $encoding, int $level = 1)
    {
        $this->stream = $stream;

        $this->context = \deflate_init($encoding, [
            'level' => $level
        ]);
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
            $chunk = \deflate_add($this->context, $this->stream->read(0xFFFF), \ZLIB_SYNC_FLUSH);

            if ($chunk !== '') {
                return $chunk;
            }
        }

        try {
            return \deflate_add($this->context, '', \ZLIB_FINISH);
        } finally {
            $this->context = null;
        }
    }
}
