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

use Concurrent\Http\Http2\Http2Driver;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;

class HttpServerConfig
{
    protected $requestFactory;
    
    protected $responseFactory;
    
    protected $http2;
    
    protected $upgrades = [];

    public function __construct(ServerRequestFactoryInterface $requestFactory, ResponseFactoryInterface $responseFactory)
    {
        $this->requestFactory = $requestFactory;
        $this->responseFactory = $responseFactory;
    }
    
    public function getRequestFactory(): ServerRequestFactoryInterface
    {
        return $this->requestFactory;
    }
    
    public function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }
    
    public function getHttp2Driver(): ?Http2Driver
    {
        return $this->http2;
    }

    public function withHttp2Driver(Http2Driver $http2): self
    {
        $config = clone $this;
        $config->http2 = $http2;

        return $config;
    }
    
    public function getUpgradeHandlers(): array
    {
        return $this->upgrades;
    }

    public function withUpgradeHandler(UpgradeHandler $handler): self
    {
        $config = clone $this;
        $config->upgrades[$handler->getProtocol()] = $handler;

        return $config;
    }
}
