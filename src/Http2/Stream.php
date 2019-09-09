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

namespace Concurrent\Http\Http2;

use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Http\HttpCodec;
use Concurrent\Http\HttpServer;
use Concurrent\Stream\StreamClosedException;
use Concurrent\Sync\Condition;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

class Stream
{
    protected $id;

    protected $state;

    protected $defer;

    protected $buffer;

    protected $receiveWindow;
    
    protected $receiver;

    protected $sendWindow;
    
    protected $sender;
    
    protected $body;
    
    protected $data;

    public function __construct(int $id, ConnectionState $state)
    {
        $this->id = $id;
        $this->state = $state;

        $this->receiveWindow = $state->localSettings[Connection::SETTING_INITIAL_WINDOW_SIZE];
        $this->sendWindow = $state->remoteSettings[Connection::SETTING_INITIAL_WINDOW_SIZE];
        
        $this->sender = new Condition();
    }

    public function __debugInfo(): array
    {
        return [
            'id' => $this->id
        ];
    }

    public function close(?\Throwable $e = null): void
    {
        if (empty($this->state->streams[$this->id])) {
            return;
        }

        unset($this->state->streams[$this->id]);

        if ($this->receiver !== null) {
            if ($this->data !== null) {
                $this->data = null;
                
                $this->state->sendFrameAsync(new Frame(Frame::RST_STREAM, $this->id, \pack('n', 0)));
            }

            $this->receiver->signal();
            $this->receiver->close($e);
        }

        $this->sender->close($e ?? new StreamClosedException('Stream has been closed'));

        if ($this->body) {
            $this->body->close();
        }
    }

    public function processFrame(Frame $frame): void
    {
        switch ($frame->type) {
            case Frame::HEADERS:
                $data = $frame->getPayload();

                if ($frame->flags & Frame::PRIORITY_FLAG) {
                    $data = \substr($data, 5);
                }

                $this->buffer = $data;

                if ($frame->flags & Frame::END_HEADERS) {
                    if (!($frame->flags & Frame::END_STREAM)) {
                        $this->receiver = new Condition();
                        $this->data = '';
                    }

                    try {
                        if ($this->defer) {
                            $this->defer->resolve($this->buffer);
                        } else {
                            $this->acceptRequest();
                        }
                    } finally {
                        $this->buffer = null;
                    }
                }
                break;
            case Frame::CONTINUATION:
                $this->buffer = $frame->getPayload();

                if ($frame->flags & Frame::END_HEADERS) {
                    try {
                        if ($this->defer) {
                            $this->defer->resolve($this->buffer);
                        } else {
                            $this->acceptRequest();
                        }
                    } finally {
                        $this->buffer = null;
                    }
                }
                break;
            case Frame::DATA:
                $data = $frame->getPayload();
                $len = \strlen($data);
                
                $this->state->receiveWindow -= $len;

                if ($this->state->receiveWindow < 0) {
                    $this->state->close($e = new \RuntimeException('Connection flow control violation'));

                    throw $e;
                }

                $this->receiveWindow -= $len;

                if ($this->receiveWindow < 0) {
                    $this->close(new \RuntimeException('Stream flow control violation'));

                    return;
                }

                try {
                    if ($data !== '') {
                        $this->data .= $data;
                        $this->receiver->signal();
                    }
                } finally {
                    if ($frame->flags & Frame::END_STREAM) {
                        $this->buffer = null;
                        $this->data = null;

                        $this->close();
                    }
                }
                break;
            case Frame::WINDOW_UPDATE:
                $this->sendWindow += (int) \unpack('N', $frame->getPayload())[1];
                $this->sender->signal();
                break;
        }
    }
    
    protected function acceptRequest()
    {
        $buffer = $this->buffer;

        $this->state->requests->send(function (ServerRequestFactoryInterface $f1, ResponseFactoryInterface $f2, RequestHandlerInterface $handler, array $params = []) use ($buffer) {
            $headers = $this->state->hpack->decode($buffer);
            $path = $this->getFirstHeader(':path', $headers);

            $uri = \vsprintf('%s://%s%s', [
                $this->getFirstHeader(':scheme', $headers),
                $this->getFirstHeader(':authority', $headers),
                $path
            ]);

            $request = $f1->createServerRequest($this->getFirstHeader(':method', $headers), $uri, array_filter(array_merge($params, [
                'REQUEST_TIME' => \time(),
                'REQUEST_TIME_FLOAT' => \microtime(true)
            ])));

            $request = $request->withProtocolVersion('2.0');

            if (false !== ($i = \strpos($path, '?'))) {
                $query = null;
                \parse_str(\substr($path, $i + 1), $query);

                $request = $request->withQueryParams((array) $query);
            }

            foreach ($headers as $entry) {
                if (($entry[0][0] ?? null) !== ':') {
                    $request = $request->withAddedHeader(...$entry);
                }
            }

            if ($this->data !== null) {
                $request = $request->withBody(new EntityStream($this, $this->receiver, $this->data));
            }

            $response = $handler->handle($request);
            $body = $request->getBody();

            try {
                while (!$body->eof()) {
                    $body->read(0xFFFF);
                }
            } finally {
                $body->close();
            }

            $body = $response->getBody();

            try {
                if ($body->isSeekable()) {
                    $body->rewind();
                }

                $stream = ($response->getHeaderLine(HttpServer::STREAM_HEADER_NAME) != '');

                if ($stream) {
                    $chunk = null;
                } else {
                    $chunk = $body->read(8192);
                }

                $this->sendHeaders($this->encodeHeaders($response, [
                    ':status' => (string) $response->getStatusCode()
                ]), !$stream && ($chunk === ''));

                if ($stream || $chunk !== '') {
                    $this->sendBody($body, $chunk, $stream);
                }
            } finally {
                $body->close();
            }
        });
    }

    public function updateReceiveWindow(int $size): void
    {
        $frame = new Frame(Frame::WINDOW_UPDATE, 0, \pack('N', $size));

        $this->state->sendFramesAsync([
            $frame,
            new Frame(Frame::WINDOW_UPDATE, $this->id, $frame->data)
        ]);
        
        $this->state->receiveWindow += $size;
        $this->receiveWindow += $size;
    }

    public function sendRequest(RequestInterface $request, ResponseFactoryInterface $factory): ResponseInterface
    {
        $uri = $request->getUri();
        $target = $request->getRequestTarget();

        if ($target === '*') {
            $path = '*';
        } else {
            $path = '/' . \ltrim($target, '/');
        }
        
        $headers = [
            ':method' => $request->getMethod(),
            ':scheme' => $uri->getScheme(),
            ':authority' => $uri->getAuthority(),
            ':path' => $path
        ];

        $body = $request->getBody();

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $chunk = $body->read(8192);

            $this->sendHeaders($this->encodeHeaders($request, $headers, [
                'host'
            ]), $chunk === '');

            if ($chunk !== '') {
                $this->sendBody($body, $chunk);
            }
        } finally {
            $body->close();
        }

        $this->defer = new Deferred();

        try {
            $headers = $this->state->hpack->decode(Task::await($this->defer->awaitable()));
        } finally {
            $this->defer = null;
        }

        $response = $factory->createResponse((int) $this->getFirstHeader(':status', $headers));
        $response = $response->withProtocolVersion('2.0');

        foreach ($headers as $entry) {
            if (($entry[0][0] ?? null) !== ':') {
                $response = $response->withAddedHeader(...$entry);
            }
        }

        if ($this->data !== null) {
            $response = $response->withBody(new EntityStream($this, $this->receiver, $this->data));
        }

        return $response;
    }

    protected function getFirstHeader(string $name, array $headers, string $default = ''): string
    {
        foreach ($headers as $header) {
            if ($header[0] === $name) {
                return $header[1];
            }
        }

        return $default;
    }

    protected function sendHeaders(string $headers, bool $nobody = false)
    {
        $flags = ($nobody ? Frame::END_STREAM : Frame::NOFLAG);

        if (\strlen($headers) > 0x4000) {
            $parts = \str_split($headers, 0x4000);
            $frames = [];

            $frames[] = new Frame(Frame::HEADERS, $this->id, $parts[0], $flags);

            for ($size = \count($parts) - 2, $i = 1; $i < $size; $i++) {
                $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[$i]);
            }

            $frames[] = new Frame(Frame::CONTINUATION, $this->id, $parts[\count($parts) - 1], Frame::END_HEADERS);

            $this->state->sendFrames($frames);
        } else {
            $this->state->sendFrame(new Frame(Frame::HEADERS, $this->id, $headers, Frame::END_HEADERS | $flags));
        }
    }

    protected function sendBody(StreamInterface $body, ?string $chunk, bool $stream = false): int
    {
        $sent = 0;
        $eof = false;

        $this->body = $body;

        do {
            if ($chunk === null) {
                if ($stream) {
                    $chunk = $body->read(8192);
                    $eof = $body->eof();
                } else {
                    $chunk = HttpCodec::readBufferedChunk($body, 8192, $eof);
                }
            } else {
                $eof = $body->eof();
            }

            $len = \strlen($chunk);

            if ($eof && $len == 0) {
                $this->state->sendFrame(new Frame(Frame::DATA, $this->id, '', Frame::END_STREAM));
            }

            while ($len > 0) {
                // Deal with connection flow control:
                while ($this->state->sendWindow == 0) {
                    $this->state->sender->wait();
                }

                $w1 = \min($len, $this->state->sendWindow);
                $this->state->sendWindow -= $w1;

                // Deal with stream flow control.
                while ($this->sendWindow == 0) {
                    $this->sender->wait();
                }

                $w2 = \min($len, $w1, $this->sendWindow);
                $this->sendWindow -= $w2;

                // Enlarge chunk if both flow control windows permit it.
                if ($w2 < $len && $this->sendWindow > 0 && $this->state->sendWindow) {
                    $delta = \min($len - $w2, $this->sendWindow, $this->state->sendWindow);

                    $w2 += $delta;

                    $this->state->sendWindow -= $delta;
                    $this->sendWindow -= $delta;
                }

                if ($w2 < $len) {
                    $this->state->sendFrame(new Frame(Frame::DATA, $this->id, \substr($chunk, 0, $w2)));

                    $chunk = \substr($chunk, $w2);
                } else {
                    $this->state->sendFrame(new Frame(Frame::DATA, $this->id, $chunk, $eof ? Frame::END_STREAM : Frame::NOFLAG));
                }

                $sent += $w2;
                $len -= $w2;
            }

            $chunk = null;
        } while (!$eof);

        return $sent;
    }

    protected function encodeHeaders(MessageInterface $message, array $headers, array $remove = [])
    {
        static $removeDefault = [
            'connection',
            'content-length',
            'keep-alive',
            'transfer-encoding',
            'te',
            'x-stream-body'
        ];

        foreach (\array_change_key_case($message->getHeaders(), \CASE_LOWER) as $k => $v) {
            if (!isset($headers[$k])) {
                $headers[$k] = $v;
            }
        }

        foreach ($removeDefault as $name) {
            unset($headers[$name]);
        }

        foreach ($remove as $name) {
            unset($headers[$name]);
        }

        $headerList = [];

        foreach ($headers as $k => $h) {
            if (\is_array($h)) {
                foreach ($h as $v) {
                    $headerList[] = [
                        $k,
                        $v
                    ];
                }
            } else {
                $headerList[] = [
                    $k,
                    $h
                ];
            }
        }

        return $this->state->hpack->encode($headerList);
    }
}
