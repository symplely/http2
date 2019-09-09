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

class AsyncStream extends StreamAdapter
{
    protected $stream;

    public function __construct(ReadableStream $stream)
    {
        $this->stream = $stream;
    }

    public function close()
    {
        $this->stream->close();
    }

    protected function readNextChunk(): string
    {
        return (string) $this->stream->read();
    }
}
