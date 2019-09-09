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

namespace Concurrent\Http\Event;

use Concurrent\Http\StreamAdapter;
use Concurrent\Sync\Condition;

class EventServerClient extends StreamAdapter
{
    protected $id;

    protected $state;

    protected $condition;

    protected $buffer = '';

    protected $bufferSize;

    protected $callback;

    public function __construct(EventServerState $state, string $id, int $bufferSize, ?callable $disconnect)
    {
        $this->id = $id;
        $this->state = $state;
        $this->bufferSize = $bufferSize;
        $this->callback = $disconnect;

        $this->condition = new Condition();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function close(?\Throwable $e = null)
    {
        if ($this->buffer === null) {
            return;
        }

        unset($this->state->clients[$this->id]);

        $this->buffer = null;
        $this->condition->close($e);

        if ($this->callback) {
            ($this->callback)($this);
        }
    }

    public function send(Event $event): void
    {
        $this->buffer .= (string) $event;
        $this->condition->signal();
        
        if ($this->buffer !== null && \strlen($this->buffer) > $this->bufferSize) {
            $this->close($e = new \Error('Disconnected slow client'));
            
            throw $e;
        }
    }

    public function append(string $event)
    {
        $this->buffer .= $event;
        $this->condition->signal();
        
        if ($this->buffer !== null && \strlen($this->buffer) > $this->bufferSize) {
            $this->close(new \Error('Disconnected slow client'));
        }
    }

    protected function readNextChunk(): string
    {
        while ($this->buffer === '') {
            $this->condition->wait();
        }
        
        if ($this->buffer === null) {
            return '';
        }
        
        if (\strlen($this->buffer) <= 8192) {
            try {
                return $this->buffer;
            } finally {
                $this->buffer = '';
            }
        }
        
        try {
            return \substr($this->buffer, 0, 8192);
        } finally {
            $this->buffer = \substr($this->buffer, 8192);
        }
    }
}
