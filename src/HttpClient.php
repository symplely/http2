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

use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Network\SocketStream;
use Concurrent\Network\TcpSocket;
use Concurrent\Stream\StreamClosedException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpClient extends HttpCodec implements ClientInterface
{
    protected $manager;

    protected $factory;

    protected $logger;
    
    protected $http2;
    
    protected $upgraded = [];
    
    protected $checking = [];

    public function __construct(HttpClientConfig $config, ?LoggerInterface $logger = null)
    {
        $this->manager = $config->getConnectionManager();
        $this->factory = $config->getResponseFactory();
        $this->logger = $logger ?? new NullLogger();
        $this->http2 = $config->getHttp2Connector();
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();

        $encrypted = ($uri->getScheme() == 'https') ? true : false;
        $host = $uri->getHost();
        $port = $uri->getPort();

        if ($encrypted) {
            if ($this->http2) {
                $alpn = \array_merge($this->http2->getProtocols(), [
                    'http/1.1'
                ]);
            } else {
                $alpn = [
                    'http/1.1'
                ];
            }
        } else {
            $alpn = [];
        }
        
        $key = $this->manager->getKey($host, $port, $encrypted);

        if (isset($this->checking[$key])) {
            Task::await($this->checking[$key]);
        }

        if (!empty($this->upgraded[$key])) {
            return $this->upgraded[$key]->sendRequest($request, $this->factory);
        }

        if (!isset($this->upgraded[$key])) {
            $defer = new Deferred();
            $this->checking[$key] = $defer->awaitable();
        } else {
            $defer = null;
        }

        for ($i = 0; $i < 3; $i++) {
            try {
                $conn = $this->manager->checkout($host, $port, $encrypted, $alpn);
            } catch (\Throwable $e) {
                if ($defer) {
                    unset($this->checking[$key]);
                    $defer->resolve();
                }

                throw $e;
            }

            if ($encrypted && ($conn->tls->alpn_protocol ?? null) == 'h2') {
                try {
                    $this->manager->detach($conn);
                    $this->upgraded[$key] = $this->http2->connect($conn->socket);
                } catch (\Throwable $e) {
                    if ($defer) {
                        $defer->resolve();
                    }

                    throw $e;
                } finally {
                    unset($this->checking[$key]);
                }

                return $this->upgraded[$key]->sendRequest($request, $this->factory);
            }

            if ($defer) {
                unset($this->checking[$key]);
                
                $this->upgraded[$key] = null;
                $defer->resolve();
            }

            try {
                $this->writeRequest($conn->socket, clone $request);

                $conn->socket->setOption(TcpSocket::NODELAY, false);
            } catch (\Throwable $e) {
                $this->manager->release($conn, $e);

                if ($e instanceof StreamClosedException) {
                    continue;
                }

                throw $e;
            }

            $response = $this->readResponse($conn, $request);

            if ($response !== null) {
                return $response;
            }
        }

        throw new \RuntimeException(\sprintf('Failed to send HTTP request after %u attempts', $i - 1));
    }

    public function upgrade(RequestInterface $request): UpgradeStream
    {
        $uri = $request->getUri();

        $encrypted = ($uri->getScheme() == 'https') ? true : false;
        $host = $uri->getHost();
        $port = $uri->getPort();

        if (empty($port)) {
            $port = $encrypted ? 443 : 80;
        }

        for ($i = 0; $i < 3; $i++) {
            $conn = $this->manager->checkout($host, $port, $encrypted);

            try {
                $this->manager->detach($conn);

                $this->writeRequest($conn->socket, $request, true);

                $conn->socket->setOption(TcpSocket::NODELAY, true);

                $response = $this->readResponse($conn, $request, true);

                if ($response === null) {
                    continue;
                }
            } catch (\Throwable $e) {
                $conn->socket->close($e);

                throw $e;
            }

            return new UpgradeStream($request, $response, $conn->socket, $conn->buffer);
        }

        throw new \RuntimeException(\sprintf('Failed to perform HTTP upgrade after %u attempts', $i - 1));
    }

    protected function writeRequest(SocketStream $socket, RequestInterface $request, bool $upgrade = false): void
    {
        static $remove = [
            'Connection',
            'Content-Length',
            'Expect',
            'Keep-Alive',
            'TE',
            'Trailer',
            'Transfer-Encoding'
        ];

        foreach ($remove as $name) {
            $request = $request->withoutHeader($name);
        }

        if ($upgrade) {
            $request = $request->withHeader('Connection', 'upgrade');
        }

        $body = $request->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $eof = false;
            $chunk = self::readBufferedChunk($body, 0x8000, $eof);

            if ($eof) {
                $this->writeHeader($socket, $request, $chunk, false, \strlen($chunk), true);
                return;
            }

            if ($request->getProtocolVersion() == '1.0') {
                $chunk .= $body->getContents();

                $this->writeHeader($socket, $request, $chunk, false, \strlen($chunk), true);
                return;
            }

            $this->writeHeader($socket, $request, \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk), false, -1);

            do {
                $chunk = self::readBufferedChunk($body, 8192, $eof);
                $len = \strlen($chunk);

                if ($eof) {
                    $socket->setOption(TcpSocket::NODELAY, true);

                    if ($len == 0) {
                        $socket->write("0\r\n\r\n");
                    } else {
                        $socket->write(\sprintf("%x\r\n%s\r\n0\r\n\r\n", $len, $chunk));
                    }
                } else {
                    $socket->write(\sprintf("%x\r\n%s\r\n", $len, $chunk));
                }
            } while (!$eof);
        } finally {
            $body->close();
        }
    }

    protected function writeHeader(SocketStream $socket, RequestInterface $request, string $contents, bool $close, int $len, bool $nodelay = false): void
    {
        if (!$request->hasHeader('Connection')) {
            if ($close) {
                $request = $request->withHeader('Connection', 'close');
            } else {
                $request = $request->withHeader('Connection', 'keep-alive');
            }
        }

        if ($len < 0) {
            if ($request->getProtocolVersion() != '1.0') {
                $request = $request->withHeader('Transfer-Encoding', 'chunked');
            }
        } else {
            $request = $request->withHeader('Content-Length', (string) $len);
        }

        $buffer = \sprintf("%s %s HTTP/%s\r\n", $request->getMethod(), $request->getRequestTarget(), $request->getProtocolVersion());

        foreach ($request->getHeaders() as $k => $values) {
            foreach ($values as $v) {
                $buffer .= \sprintf("%s: %s\r\n", $k, $v);
            }
        }
        
        $socket->write($buffer . "\r\n" . $contents);

        if ($nodelay) {
            $socket->setOption(TcpSocket::NODELAY, true);
        }
    }

    protected function readResponse(Connection $conn, RequestInterface $request, bool $upgrade = false): ?ResponseInterface
    {
        try {
            while (false === ($pos = \strpos($conn->buffer, "\r\n\r\n"))) {
                $chunk = $conn->socket->read();

                if ($chunk === null) {
                    if ($conn->buffer === '') {
                        return null;
                    }
                    
                    throw new \RuntimeException('Failed to read next HTTP response');
                }

                $conn->buffer .= $chunk;
            }

            $header = \substr($conn->buffer, 0, $pos + 2);
            $conn->buffer = \substr($conn->buffer, $pos + 4);

            $pos = \strpos($header, "\n");
            $line = \substr($header, 0, $pos);
            $m = null;

            if (!\preg_match("'^\s*HTTP/(1\\.[01])\s+([1-5][0-9]{2})\s*(.*)$'is", $line, $m)) {
                throw new \RuntimeException('Invalid HTTP response line received');
            }

            $response = $this->factory->createResponse((int) $m[2], \trim($m[3]));
            $response = $response->withProtocolVersion($m[1]);
            $response = $this->populateHeaders($response, \substr($header, $pos + 1));

            $tokens = \array_fill_keys(\array_map('strtolower', \preg_split("'\s*,\s*'", $response->getHeaderLine('Connection'))), true);

            if ($response->getProtocolVersion() == '1.0') {
                if (isset($tokens['close']) || empty($tokens['keep-alive'])) {
                    $conn->maxRequests = 1;
                }
            } else {
                if (isset($tokens['close'])) {
                    $conn->maxRequests = 1;
                }
            }

            if ($upgrade && empty($tokens['upgrade'])) {
                throw new \RuntimeException('Missing upgrade in connection header');
            }
        } catch (\Throwable $e) {
            if (!$upgrade) {
                $this->manager->release($conn, $e);
            }

            throw $e;
        }

        return $this->decodeBody(new ClientStream($this->manager, $conn, !$upgrade), $response, $conn->buffer);
    }
}
