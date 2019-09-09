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

use Concurrent\CancellationException;
use Concurrent\Context;
use Concurrent\Network\Server;
use Concurrent\Network\SocketStream;
use Concurrent\Network\TcpSocket;
use Concurrent\Network\TlsServerEncryption;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class HttpServer extends HttpCodec
{
    public const STREAM_HEADER_NAME = 'X-Stream-Body';
    
    protected $requestFactory;
    
    protected $responseFactory;

    protected $logger;
    
    protected $http2;
    
    protected $upgrades = [];

    public function __construct(HttpServerConfig $config, ?LoggerInterface $logger = null)
    {
        $this->requestFactory = $config->getRequestFactory();
        $this->responseFactory = $config->getResponseFactory();
        $this->http2 = $config->getHttp2Driver();
        $this->upgrades = $config->getUpgradeHandlers();

        $this->logger = $logger ?? new NullLogger();
    }

    public function createEncryption(): TlsServerEncryption
    {
        $tls = new TlsServerEncryption();

        if ($this->http2) {
            return $tls->withAlpnProtocols('h2', 'http/1.1');
        }

        return $tls->withAlpnProtocols('http/1.1');
    }

    public function run(Server $server, RequestHandlerInterface $handler, ?TlsServerEncryption $tls = null): HttpServerListener
    {
        return new HttpServerListener($server, function (SocketStream $socket, Context $context) use ($handler, $tls) {
            $params = [
                'SERVER_ADDR' => $socket->getAddress(),
                'SERVER_PORT' => $socket->getPort(),
                'REMOTE_ADDR' => $socket->getRemoteAddress(),
                'REMOTE_PORT' => $socket->getRemotePort()
            ];
            
            if ($tls) {
                $info = $socket->encrypt();
                
                if ($info->alpn_protocol === 'h2') {
                    return $this->http2->serve($socket, $context, $this->requestFactory, $this->responseFactory, $handler, $params);
                }
            }
            
            $buffer = '';
            $first = true;
            $close = true;

            do {
                $socket->setOption(TcpSocket::NODELAY, false);

                if (null === ($request = $this->readRequest($context, $socket, $buffer, $first, $params))) {
                    break;
                }

                if ($first) {
                    $first = false;
                }

                $tokens = \array_fill_keys(\array_map('strtolower', \preg_split("'\s*,\s*'", $request->getHeaderLine('Connection'))), true);

                if (!empty($tokens['upgrade'])) {
                    $protocol = \strtolower($request->getHeaderLine('Upgrade'));

                    if (isset($this->upgrades[$protocol])) {
                        return $this->handleUpgrade($this->upgrades[$protocol], $socket, $buffer, $request);
                    }

                    $this->logger->warning('No upgrade handler found for {protocol}', [
                        'protocol' => $protocol
                    ]);
                }

                $response = $handler->handle($request);
                $response = $response->withoutHeader('Connection');

                if ($request->getProtocolVersion() == '1.0') {
                    $close = empty($tokens['keep-alive']);
                } else {
                    $close = !empty($tokens['close']);
                }

                $this->sendResponse($socket, $request, $response, $close);
            } while (!$close);
        }, $this->logger);
    }
    
    protected function handleUpgrade(UpgradeHandler $handler, SocketStream $socket, string $buffer, ServerRequestInterface $request)
    {
        $response = $this->responseFactory->createResponse(101);
        $response = $response->withHeader('Connection', 'upgrade');

        $response = $handler->populateResponse($request, $response);
        $close = false;

        $socket->setOption(TcpSocket::NODELAY, true);

        $this->sendResponse($socket, $request, $response, $close);

        $handler->handleConnection(new UpgradeStream($request, $response, $socket, $buffer));
    }

    protected function readRequest(Context $context, SocketStream $socket, string & $buffer, bool $first, array $params): ?ServerRequestInterface
    {
        $remaining = 0x4000;

        while (false === ($pos = \strpos($buffer, "\r\n\r\n"))) {
            if ($remaining == 0) {
                throw new \RuntimeException('Maximum HTTP header size exceeded');
            }

            if (!$first && $buffer === '') {
                $chunk = $context->run(static function () use ($socket, $remaining) {
                    try {
                        return $socket->read($remaining);
                    } catch (CancellationException $e) {
                        // Graceful shutdown requested, treat this like EOF.
                    }
                });
            } else {
                $chunk = $socket->read($remaining);
            }

            if ($chunk === null) {
                if ($buffer === '') {
                    return null;
                }

                throw new \RuntimeException('Failed to read next HTTP request');
            }

            $buffer .= $chunk;
            $remaining -= \strlen($chunk);
        }

        $header = \substr($buffer, 0, $pos + 2);
        $buffer = \substr($buffer, $pos + 4);

        $pos = \strpos($header, "\n");
        $line = \substr($header, 0, $pos);
        $m = null;

        if (!\preg_match("'^\s*(\S+)\s+(\S+)\s+HTTP/(1\\.[01])\s*$'is", $line, $m)) {
            throw new \RuntimeException('Invalid HTTP request line received');
        }

        $request = $this->requestFactory->createServerRequest($m[1], $m[2], $params);
        $request = $request->withProtocolVersion($m[3]);
        $request = $this->populateHeaders($request, \substr($header, $pos + 1));

        if (false !== ($i = \strpos($m[2], '?'))) {
            $query = null;
            \parse_str(\substr($m[2], $i + 1), $query);

            $request = $request->withQueryParams((array) $query);
        }

        return $this->decodeBody($socket, $request, $buffer);
    }

    protected function normalizeResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        static $remove = [
            'Content-Length',
            'Keep-Alive',
            'TE',
            'Trailer',
            'Transfer-Encoding'
        ];

        foreach ($remove as $name) {
            $response = $response->withoutHeader($name);
        }

        $response = $response->withProtocolVersion($request->getProtocolVersion());
        $response = $response->withHeader('Date', \gmdate(self::DATE_RFC1123));

        return $response;
    }

    protected function sendResponse(SocketStream $socket, ServerRequestInterface $request, ResponseInterface $response, bool & $close): void
    {
        $body = $request->getBody();

        try {
            while (!$body->eof()) {
                $body->read(0xFFFF);
            }
        } finally {
            $body->close();
        }

        $response = $this->normalizeResponse($request, $response);
        $body = $response->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            // Stream server-sent events without buffering.
            if ($response->getHeaderLine(self::STREAM_HEADER_NAME) != '') {
                $response = $response->withoutHeader(self::STREAM_HEADER_NAME);
                $close = true;
                
                $this->writeHeader($socket, $response, '', $close, -2, true, true);

                while (!$body->eof()) {
                    $chunk = $body->read(8192);

                    if ($chunk !== '') {
                        $socket->write($chunk);
                    }
                }

                return;
            }
                         
            $eof = false;
            $chunk = self::readBufferedChunk($body, 0x8000, $eof);

            if ($eof) {
                $this->writeHeader($socket, $response, $chunk, $close, \strlen($chunk), !$close);
                return;
            }

            if ($request->getProtocolVersion() == '1.0') {
                $close = true;

                $this->writeHeader($socket, $response, $chunk, $close, -1);

                do {
                    $chunk = self::readBufferedChunk($body, 8192, $eof);

                    if (\strlen($chunk) > 0) {
                        $socket->write($chunk);
                    }
                } while (!$eof);

                return;
            }

            $this->writeHeader($socket, $response, \sprintf("%x\r\n%s\r\n", \strlen($chunk), $chunk), $close, -1);

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
    
    protected function writeHeader(SocketStream $socket, ResponseInterface $response, string $contents, bool $close, int $len, bool $nodelay = false): void
    {
        if (!$response->hasHeader('Connection')) {
            if ($close) {
                $response = $response->withHeader('Connection', 'close');
            } else {
                $response = $response->withHeader('Connection', 'keep-alive');
            }
        }
        
        if ($len == -1) {
            if ($response->getProtocolVersion() != '1.0') {
                $response = $response->withHeader('Transfer-Encoding', 'chunked');
            }
        } else if ($len >= 0) {
            $response = $response->withHeader('Content-Length', (string) $len);
        }

        $reason = \trim($response->getReasonPhrase());
        $buffer = \sprintf("HTTP/%s %u%s\r\n", $response->getProtocolVersion(), $response->getStatusCode(), \rtrim(' ' . $reason));

        foreach ($response->getHeaders() as $k => $values) {
            foreach ($values as $v) {
                $buffer .= \sprintf("%s: %s\r\n", $k, $v);
            }
        }
        
        $socket->write($buffer . "\r\n" . $contents);
        
        if ($nodelay) {
            $socket->setOption(TcpSocket::NODELAY, true);
        }
    }
}
