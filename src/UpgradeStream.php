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

use Concurrent\Network\SocketStream;
use Concurrent\Stream\ReadableStream;
use Concurrent\Stream\WritableStream;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class UpgradeStream implements ReadableStream, WritableStream
{
    protected $request;

    protected $response;

    protected $socket;

    protected $buffer;

    public function __construct(RequestInterface $request, ResponseInterface $response, SocketStream $socket, string $buffer = '')
    {
        $this->request = $request;
        $this->response = $response;
        $this->socket = $socket;
        $this->buffer = $buffer;
    }

    public function __destruct()
    {
        $this->socket->close();
    }
    
    public function getProtocol(): string
    {
        return \strtolower($this->response->getHeaderLine('Upgrade'));
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
    
    public function getSocket(): SocketStream
    {
        return $this->socket;
    }

    /**
     * {@inheritdoc}
     */
    public function close(?\Throwable $e = null): void
    {
        $this->socket->close($e);
        $this->buffer = '';
    }

    /**
     * {@inheritdoc}
     */
    public function read(?int $length = null): ?string
    {
        if ($this->buffer === '') {
            $this->buffer = $this->socket->read();
            
            if ($this->buffer === '') {
                return null;
            }
        }
        
        $chunk = \substr($this->buffer, 0, $length ?? 8192);
        $this->buffer = \substr($this->buffer, \strlen($chunk));

        return $chunk;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $data): void
    {
        $this->socket->write($data);
    }
}
