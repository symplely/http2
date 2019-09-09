# HTTP Client & Server

Provides an HTTP client and server example implementation backed by the `async` PHP extension.

## Supported APIs

| API | Description |
| --- | ----------- |
| [PSR-3](https://www.php-fig.org/psr/psr-3/) | Provides a simple logger interface, [monolog](https://github.com/Seldaek/monolog) is used for testing. |
| [PSR-7](https://www.php-fig.org/psr/psr-7/) | Provides HTTP message abstraction, [Tobias Nyholm PSR7](https://github.com/Nyholm/psr7) is used in examples. |
| [PSR-15](https://www.php-fig.org/psr/psr-15/) | Provides contracts for server HTTP request handlers and HTTP middleware. |
| [PSR-17](https://www.php-fig.org/psr/psr-17/) | Provides HTTP message factories, your PSR-7 implementations should provide these. |
| [PSR-18](https://www.php-fig.org/psr/psr-18/) | Provides a contract for an HTTP client. |

## HTTP Client

The HTTP client is `PSR-18` compliant and implements `ClientInterface`. It requires a `PSR-17` response factory to convert incoming responses into `PSR-7` response objects. The client is completely async yet it does not make use or callbacks, promises, or any other kind of an async API. All network IO is non-blocking but the body stream of the HTTP request object you pass in might be blocking. You can execute multiple concurrent HTTP requests by making use of different `Task` objects (see [ext-async](https://github.com/concurrent-php/ext-async)).

```php
use Concurrent\Http\HttpClient;
use Concurrent\Http\HttpClientConfig;

$factory = new \Nyholm\Psr7\Factory\Psr17Factory();
$client = new HttpClient(new HttpClientConfig($factory));

$request = $factory->createRequest('GET', 'https://httpbin.org/status/201');
$response = $client->sendRequest($request);

print_r($response);
```

### BaseUriClient

Decorator that enhances a PSR-18 HTTP client with base URI handling to allow for relative paths in request URIs.

### Compressing Client

Decorator that enhances an arbitrary PSR-18 HTTP client with support for gzip and deflate HTTP response compression (requires zlib extension to be installed).

### MemoryBufferingClient

Decorator that reads all HTTP response bodies into strings before returning an HTTP response object.

## HTTP Server

The HTTP server uses an async TCP server and translates incoming HTTP requests into `PSR-7` server request objects using a `PSR-17` server request factory. The requests are passed to a `PSR-15` request handler that is responsible for creating an HTTP response. The server can handle many HTTP requests concurrently due to non-blocking socket IO. Performance will degrade if you do blocking IO (filesystem access, DB queries, etc.) or CPU intensive processing in your request handler.

```php
use Concurrent\Http\HttpServer;
use Concurrent\Http\HttpServerConfig;
use Concurrent\Network\TcpServer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$factory = new \Nyholm\Psr7\Factory\Psr17Factory();

$handler = new class($factory) implements RequestHandlerInterface {
    private $factory;
    
    public function __construct(ResponseFactoryInterface $factory) {
        $this->factory = $factory;
    }
    
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return $this->factory->createResponse(204);
    }
};

$server = new HttpServer(new HttpServerConfig($factory, $factory));
$server->run(TcpServer::listen('127.0.0.1', 8080), $handler);
```
