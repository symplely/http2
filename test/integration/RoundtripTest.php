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
use Concurrent\Network\TcpServer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RoundtripTest extends AsyncTestCase implements RequestHandlerInterface
{
    protected $logger;

    protected $manager;

    protected $factory;

    protected $client;

    protected $server;

    protected $address;

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

        $server = new HttpServer($this->factory, $this->factory, $this->logger);
        $tcp = TcpServer::listen('127.0.0.1', 0);

        $this->server = $server->run($tcp, $this);
        $this->address = 'localhost:' . $tcp->getPort();
    }

    protected function tearDown()
    {
        $this->manager = null;
        $this->client = null;

        $this->server->shutdown();

        $this->address = null;
        $this->server = null;

        parent::tearDown();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = rtrim(urldecode(preg_replace("'\?.*$'", '', $request->getRequestTarget())), '/');
        $m = null;

        if (preg_match("'^/status/([1-5][0-9]{2})'i", $path, $m)) {
            return $this->factory->createResponse((int) $m[1]);
        }

        if ($path == '/payload') {
            $response = $this->factory->createResponse();
            $response = $response->withHeader('Content-Type', $request->getHeaderLine('Content-Type'));
            $response = $response->withBody($this->factory->createStream(strtoupper($request->getBody()->getContents())));

            return $response;
        }

        return $this->factory->createResponse(404);
    }

    public function testMultipleRequests()
    {
        $request = $this->factory->createRequest('GET', "http://{$this->address}/status/201");
        $response = $this->client->sendRequest($request);

        $this->assertEquals(201, $response->getStatusCode());

        $request = $this->factory->createRequest('GET', "http://{$this->address}/status/204?foo=bar");
        $response = $this->client->sendRequest($request);

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function testParallelRequests()
    {
        $expect = range(400, 420);
        $tasks = [];

        foreach ($expect as $code) {
            $tasks[] = Task::async(function (int $code) {
                $request = $this->factory->createRequest('GET', sprintf('http://%s/status/%u', $this->address, $code));

                return $this->client->sendRequest($request)->getStatusCode();
            }, $code);
        }

        $this->assertEquals($expect, Task::await(all($tasks)));
    }

    public function testBodyStream()
    {
        $request = $this->factory->createRequest('POST', "http://{$this->address}/payload");
        $request = $request->withHeader('Content-Type', 'text/plain; charset="utf-8"');
        $request = $request->withBody($this->factory->createStreamFromFile(__FILE__, 'rb'));

        $response = $this->client->sendRequest($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(strtoupper(file_get_contents(__FILE__)), $response->getBody()->getContents());
    }
}
