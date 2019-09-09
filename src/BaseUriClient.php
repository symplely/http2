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
use Psr\Http\Message\UriInterface;

/**
 * Adds support for relative request URIs.
 */
class BaseUriClient implements ClientInterface
{
    protected $client;
    
    protected $base;

    public function __construct(ClientInterface $client, UriInterface $base)
    {
        $this->client = $client;
        $this->base = $base->withQuery('')->withFragment('');
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        if ('/' !== ($path[0] ?? null)) {
            $base = $this->base->getPath();

            if (\substr($base, -1) === '/') {
                $uri = $uri->withPath($base . $path);
            } else {
                $uri = $uri->withPath(\substr($base, 0, \strrpos($base, '/') + 1) . $path);
            }

            $request = $request->withUri($uri);
        }

        if ($uri->getHost() === '') {
            $request = $request->withUri($this->base->withPath($uri->getPath()));
        }
        
        return $this->client->sendRequest($request);
    }
}
