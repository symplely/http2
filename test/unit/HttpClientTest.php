<?php

/*
 * This file is part of Concurrent PHP HTTP.
 *
 * (c) Martin Schröder <m.schroeder2007@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Concurrent\Http;

use Concurrent\AsyncTestCase;
use Concurrent\Task;
use function Concurrent\all;
use Concurrent\Http\Http2\Http2Connector;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;

class HttpClientTest extends AsyncTestCase
{
    protected $logger;

    protected $manager;

    protected $factory;

    protected $client;

    protected function setUp()
    {
        parent::setUp();

        $this->logger = new Logger('test', [
            new StreamHandler(STDERR, Logger::WARNING)
        ], [
            new PsrLogMessageProcessor()
        ]);

        $this->manager = new ConnectionManager(60, 5, $this->logger);
        $this->factory = new Psr17Factory();

        $this->client = new HttpClient($this->manager, $this->factory, $this->logger);
    }

    protected function tearDown()
    {
        $this->manager = null;
        $this->client = null;

        parent::tearDown();
    }

    public function testStatusCode()
    {
        $request = $this->factory->createRequest('GET', 'https://httpbin.org/status/201');
        $response = $this->client->sendRequest($request);

        $this->assertEquals(201, $response->getStatusCode());

        $request = $this->factory->createRequest('GET', 'https://httpbin.org/status/204');
        $response = $this->client->sendRequest($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testStatusCodeParallel()
    {
        $client = new CompressingClient($this->client);
        $client = new MemoryBufferingClient($client, $this->factory);
        $client = new BaseUriClient($client, $this->factory->createUri('https://httpbin.org/status/'));
        
        $requests = [
            '201',
            'http://httpbin.org/status/204'
        ];

        $responses = Task::await(all(array_map(function (string $uri) use ($client) {
            return Task::async(function () use ($uri, $client) {
                return $client->sendRequest($this->factory->createRequest('GET', $uri))->getStatusCode();
            });
        }, $requests)));

        $this->assertEquals([
            201,
            204
        ], $responses);
    }

    /**
     * @requires function inflate_init
     */
    public function testResponseBody()
    {
        $client = new CompressingClient($this->client);

        $request = $this->factory->createRequest('GET', 'https://httpbin.org/gzip');
        $response = $client->sendRequest($request);

        $this->assertEquals(200, $response->getStatusCode());

        $body = \json_decode($response->getBody()->getContents(), true);

        $this->assertEquals('httpbin.org', $body['headers']['Host']);
    }

    public function testAlpnUpgrade()
    {
        $message = 'Hello World :)';

        $http2 = new Http2Connector([], $this->logger);
        $client = new HttpClient($this->manager, $this->factory, $this->logger, $http2);

        for ($i = 0; $i < 3; $i++) {
            $request = $this->factory->createRequest('PUT', 'https://http2.golang.org/ECHO');
            $request = $request->withHeader('Content-Type', 'text/plain');
            $request = $request->withBody($this->factory->createStream($message . ' #' . $i));

            $response = $client->sendRequest($request);

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('2.0', $response->getProtocolVersion());
            $this->assertEquals(strtoupper($message) . ' #' . $i, $response->getBody()->getContents());
        }
    }
}
