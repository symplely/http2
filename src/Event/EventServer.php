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

use Concurrent\Http\HttpServer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class EventServer
{
    protected $factory;

    protected $state;

    public function __construct(ResponseFactoryInterface $factory, int $bufferSize = 64)
    {
        $this->factory = $factory;
        $this->state = new EventServerState($bufferSize);
    }

    public function __destruct()
    {
        $this->close();
    }
    
    public function isClosed(): bool
    {
        return $this->state->closed;
    }

    public function close(): void
    {
        $this->state->close();
    }

    public function connect(?callable $disconnect = null): EventServerClient
    {
        return $this->state->connect($disconnect);
    }

    public function createResponse(EventServerClient $client): ResponseInterface
    {
        $response = $this->factory->createResponse();
        $response = $response->withHeader('Content-Type', 'text/event-stream');
        $response = $response->withHeader('Cache-Control', 'no-cache');
        $response = $response->withHeader(HttpServer::STREAM_HEADER_NAME, '1');
        
        return $response->withBody($client);
    }

    public function broadcast(Event $event, ?array $exclude = null): void
    {
        $data = (string) $event;
        
        if ($exclude) {
            foreach ($this->state->clients as $id => $client) {
                if (empty($exclude[$id])) {
                    $client->append($data);
                }
            }
        } else {
            foreach ($this->state->clients as $client) {
                $client->append($data);
            }
        }
    }
}
