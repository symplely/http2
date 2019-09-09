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

use Concurrent\CancellationException;
use Concurrent\Context;
use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Network\SocketDisconnectException;
use Concurrent\Network\SocketStream;
use Concurrent\Stream\StreamClosedException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

class Connection implements \IteratorAggregate
{
    /**
     * Connection preface that must be sent by clients.
     */
    public const PREFACE = "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n";
    
    public const INITIAL_WINDOW_SIZE = 0xFFFF;
    
    public const SETTING_HEADER_TABLE_SIZE = 0x01;
    
    public const SETTING_ENABLE_PUSH = 0x02;
    
    public const SETTING_MAX_CONCURRENT_STREAMS = 0x03;
    
    public const SETTING_INITIAL_WINDOW_SIZE = 0x04;

    public const SETTING_MAX_FRAME_SIZE = 0x05;

    public const SETTING_MAX_HEADER_LIST_SIZE = 0x06;

    protected $state;
    
    protected $task;
    
    protected $cancel;

    protected function __construct(SocketStream $socket, HPack $hpack, array $settings, ?Deferred $defer = null, string $buffer = '', ?LoggerInterface $logger = null)
    {
        $this->state = $state = new ConnectionState($socket, $hpack, $settings, $defer !== null, $logger);

        $context = Context::current();
        $background = Context::background()->withCancel($this->cancel);

        $this->task = Task::asyncWithContext($background, static function () use ($state, $defer, $buffer, $logger, $context) {
            $e = null;
            
            try {
                return static::processFrames($state, $defer, $buffer, $logger);
            } catch (CancellationException $e) {
                $context->run(function () use ($state) {
                    $frame = new Frame(Frame::GOAWAY, 0, \pack('NN', $state->lastStreamId, 0));
                    
                    try {
                        $state->socket->write($frame->encode());
                    } finally {
                        $state->socket->close();
                    }
                });
            } catch (StreamClosedException | SocketDisconnectException $e) {
                // Cannot do anything about this if it happens.
            } catch (\Throwable $e) {
                if ($logger) {
                    $logger->error(\sprintf('%s: %s', \get_class($e), $e->getMessage()), [
                        'exception' => $e
                    ]);
                }
            }

            if ($e && $defer) {
                $defer->fail($e);
            }

            if ($state->requests) {
                $state->requests->close($e);
            }
        });
    }

    public function __destruct()
    {
        if ($this->cancel !== null) {
            $cancel = $this->cancel;
            $this->cancel = null;

            $cancel();
        }

        $this->state->close();
    }

    public function __debugInfo(): array
    {
        return [
            'active' => ($this->cancel === null)
        ];
    }

    public static function connect(SocketStream $socket, HPack $hpack, array $settings, string $buffer = '', ?LoggerInterface $logger = null): Connection
    {
        $conn = new Connection($socket, $hpack, $settings, $defer = new Deferred(), $buffer, $logger);

        Task::await($defer->awaitable());

        return $conn;
    }

    public static function serve(SocketStream $socket, HPack $hpack, array $settings, ?LoggerInterface $logger = null): Connection
    {
        return new Connection($socket, $hpack, $settings, null, '', $logger);
    }

    public function getIterator()
    {
        if ($this->state->client) {
            throw new \RuntimeException('Cannot access inbound HTTP requests in client mode');
        }

        return $this->state->requests->getIterator();
    }

    public function close(?\Throwable $e = null): void
    {
        if ($this->cancel !== null) {
            $cancel = $this->cancel;
            $this->cancel = null;

            $cancel();
        }

        Task::await($this->task);

        $this->state->close($e);
    }

    public function ping(): void
    {
        Task::await($this->state->ping());
    }

    public function sendRequest(RequestInterface $request, ResponseFactoryInterface $factory): ResponseInterface
    {
        $id = $this->state->nextStreamId += 2;

        $stream = new Stream($id, $this->state);
        $this->state->streams[$id] = $stream;

        return $stream->sendRequest($request, $factory);
    }

    protected static function processFrames(ConnectionState $state, ?Deferred $defer = null, string $buffer = '', ?LoggerInterface $logger = null)
    {
        $len = \strlen($buffer);

        while (true) {
            while ($len < 9) {
                if (null === ($chunk = $state->socket->read())) {
                    return;
                }

                $buffer .= $chunk;
                $len += \strlen($chunk);
            }

            $header = \substr($buffer, 0, 9);

            $buffer = \substr($buffer, 9);
            $len -= 9;

            $length = \unpack('N', "\x00" . $header)[1];
            $stream = \unpack('N', "\x7F\xFF\xFF\xFF" & \substr($header, 5, 4))[1];

            if ($length == 0) {
                $frame = new Frame(\ord($header[3]), $stream, '', \ord($header[4]));
            } else {
                while ($len < $length) {
                    if (null === ($chunk = $state->socket->read())) {
                        return;
                    }

                    $buffer .= $chunk;
                    $len += \strlen($chunk);
                }

                $frame = new Frame(\ord($header[3]), $stream, \substr($buffer, 0, $length), \ord($header[4]));

                $buffer = \substr($buffer, $length);
                $len -= $length;
            }

            // Ignore upgrade response.
            if ($frame->stream == 1) {
                continue;
            }

            if ($frame->stream == 0) {
                // Handle connection frame.
                switch ($frame->type) {
                    case Frame::GOAWAY:
                        // TODO: Prepare for connection shutdown...
                        break;
                    case Frame::SETTINGS:
                        if (!($frame->flags & Frame::ACK)) {
                            $state->processSettings($frame);

                            if ($defer) {
                                $tmp = $defer;
                                $defer = null;

                                $tmp->resolve();
                            }
                        }
                        break;
                    case Frame::WINDOW_UPDATE:
                        $state->sendWindow += (int) \unpack('N', $frame->getPayload())[1];
                        $state->sender->signal();
                        break;
                    case Frame::PING:
                        if ($frame->flags & Frame::ACK) {
                            $state->processPing($frame);
                        } else {
                            $state->sendFrameAsync(new Frame(Frame::PING, 0, $frame->getPayload(), Frame::ACK));
                        }
                        break;
                    case Frame::DATA:
                    case Frame::HEADERS:
                    case Frame::CONTINUATION:
                    case Frame::PRIORITY:
                    case Frame::RST_STREAM:
                        throw new \Error('Invalid connection frame received');
                }
            } else {
                if (empty($state->streams[$frame->stream])) {
                    if ($state->client) {
                        switch ($frame->type) {
                            case Frame::WINDOW_UPDATE:
                            case Frame::PRIORITY:
                                continue 2;
                        }

                        break;
                    } else {
                        switch ($frame->type) {
                            case Frame::WINDOW_UPDATE:
                            case Frame::SETTINGS:
                            case Frame::PRIORITY:
                            case Frame::HEADERS:
                                $state->streams[$frame->stream] = new Stream($frame->stream, $state);
                                break;
                            default:
                                throw new \RuntimeException('Received invalid frame: ' . $frame);
                        }
                    }
                }

                switch ($frame->type) {
                    case Frame::RST_STREAM:
                        $state->streams[$frame->stream]->close(new StreamClosedException('Remote peer closed the stream'));
                        break;
                    case Frame::SETTINGS:
                    case Frame::PING:
                    case Frame::GOAWAY:
                        throw new \Error('Invalid stream frame received');
                    default:
                        $state->streams[$frame->stream]->processFrame($frame);
                }
            }
        }
    }
}
