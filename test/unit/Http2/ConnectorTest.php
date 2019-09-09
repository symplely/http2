<?php

/*
 * This file is part of Concurrent PHP HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Concurrent\Http\Http2;

use Concurrent\AsyncTestCase;
use Concurrent\Http\ConnectionManager;
use Concurrent\Http\HttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

class ConnectorTest extends AsyncTestCase
{
    public function test()
    {
        $client = new HttpClient(new ConnectionManager(), $factory = new Psr17Factory(), null, new Http2Connector());

        $request = $factory->createRequest('GET', 'https://de.wikipedia.org/wiki/Hypertext_Transfer_Protocol');
        $response = $client->sendRequest($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('2.0', $response->getProtocolVersion());
    }
    
    /*
    public function testAlpnConnect()
    {
        $message = 'Hello World :)';

        $factory = new Psr17Factory();
        $connector = new Http2Connector();

        $request = $factory->createRequest('PUT', 'https://http2.golang.org/ECHO');
        $request = $request->withHeader('Content-Type', 'text/plain');
        $request = $request->withBody($factory->createStream($message));

        $uri = $request->getUri();
        $port = $uri->getPort();

        if ($port === null) {
            $port = ($uri->getScheme() == 'https') ? 443 : 80;
        }

        $tls = new TlsClientEncryption();
        $tls = $tls->withAlpnProtocols('h2', 'http/1.1');

        $socket = TcpSocket::connect($uri->getHost(), $port, $tls);
        $info = $socket->encrypt();

        $this->assertEquals('h2', $info->alpn_protocol);

        $conn = $connector->connect($socket);
        $response = $conn->sendRequest($request, $factory);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(strtoupper($message), $response->getBody()->getContents());
    }

    public function testConnectionUpgrade()
    {
        $factory = new Psr17Factory();
        $connector = new Http2Connector();

        $socket = TcpSocket::connect('127.0.0.1', 80);

        $request = $factory->createRequest('GET', 'http://localhost/composer.phar');

        $conn = $connector->upgrade($socket, $request->getUri()->getHost());
        $response = $conn->sendRequest($request, $factory);

        $this->assertEquals(200, $response->getStatusCode());

        $stream = $response->getBody();

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (!$stream->eof()) {
                $stream->read(0xFFFF);
            }
        } finally {
            $stream->close();
        }
    }
    */
}
