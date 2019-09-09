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

class ClientStream implements ReadableStream
{
    protected $conn;
    
    protected $manager;
    
    protected $managed;    
    
    public function __construct(ConnectionManager $manager, Connection $conn, bool $managed = true)
    {
        $this->manager = $manager;
        $this->conn = $conn;
        $this->managed = $managed;
    }

    public function __destruct()
    {
        if ($this->conn !== null && $this->managed) {
            $this->manager->release($this->conn);
        }
    }
    
    public function markDisposed(): void
    {
        if ($this->conn !== null) {
            $this->conn->maxRequests = 1;
        }
    }

    public function release(): void
    {
        if ($this->conn !== null) {
            if ($this->managed) {
                $this->conn = $this->manager->checkin($this->conn);
            } else {
                $this->conn = null;
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        if ($this->managed) {
            $this->conn = $this->manager->release($this->conn, $e);
        } else {
            $this->conn = null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(?int $length = null): ?string
    {
        if ($this->conn === null) {
            return null;
        }

        if ($this->conn->buffer === '') {
            $chunk = $this->conn->socket->read();

            if ($chunk === null) {
                $this->release();

                return null;
            }

            $this->conn->buffer = $chunk;
        }

        $chunk = \substr($this->conn->buffer, 0, $length ?? 0xFFFF);
        $this->conn->buffer = \substr($this->conn->buffer, \strlen($chunk));

        return $chunk;
    }
}
