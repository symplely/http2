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

use Concurrent\Http\Http2\Http2Connector;
use Psr\Http\Message\ResponseFactoryInterface;

class HttpClientConfig
{
    protected $factory;

    protected $manager;

    protected $http2;

    public function __construct(ResponseFactoryInterface $factory)
    {
        $this->factory = $factory;
    }

    public function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->factory;
    }

    public function getConnectionManager(): ConnectionManager
    {
        return $this->manager ?? new TcpConnectionManager();
    }

    public function withConnectionManager(ConnectionManager $manager): self
    {
        $config = clone $this;
        $config->manager = $manager;

        return $config;
    }

    public function getHttp2Connector(): ?Http2Connector
    {
        return $this->http2;
    }

    public function withHttp2Connector(Http2Connector $connector): self
    {
        $config = clone $this;
        $config->http2 = $connector;

        return $config;
    }
}
