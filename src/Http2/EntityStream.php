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

use Concurrent\Http\StreamAdapter;
use Concurrent\Sync\Condition;

class EntityStream extends StreamAdapter
{
    protected $stream;
    
    protected $condition;
    
    protected $data;
    
    public function __construct(Stream $stream, Condition $condition, string & $data)
    {
        $this->stream = $stream;
        $this->condition = $condition;
        $this->data = & $data;
    }
    
    public function __destruct()
    {
        $this->stream->close();
    }

    public function __debugInfo(): array
    {
        return [
            'stream' => $this->stream,
            'closed' => ($this->buffer === null),
            'buffer' => \sprintf('%u bytes buffered', \strlen($this->buffer ?? ''))
        ];
    }

    public function close()
    {
        if ($this->buffer !== null) {
            $this->buffer = null;
            $this->stream->close();
        }
    }

    protected function readNextChunk(): string
    {
        while ($this->data === '') {
            $this->condition->wait();
        }

        if ($this->data === null) {
            return '';
        }

        $this->stream->updateReceiveWindow(\strlen($this->data));

        try {
            return $this->data;
        } finally {
            $this->data = '';
        }
    }
}
