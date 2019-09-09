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

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Buffers response bodies in memory.
 */
class MemoryBufferingClient implements ClientInterface
{
    protected $client;
    
    protected $factory;

    public function __construct(ClientInterface $client, StreamFactoryInterface $factory)
    {
        $this->client = $client;
        $this->factory = $factory;
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->client->sendRequest($request);
        $body = $response->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            return $response->withBody($this->factory->createStream($body->getContents()));
        } finally {
            $body->close();
        }
    }
}
