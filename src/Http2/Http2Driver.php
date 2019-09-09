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

use Concurrent\Context;
use Concurrent\Task;
use Concurrent\Network\SocketStream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class Http2Driver
{
    protected $settings;

    public function __construct(array $settings = [], ?LoggerInterface $logger = null)
    {
        $config = [
            Connection::SETTING_ENABLE_PUSH => 0,
            Connection::SETTING_MAX_CONCURRENT_STREAMS => 256,
            Connection::SETTING_INITIAL_WINDOW_SIZE => Connection::INITIAL_WINDOW_SIZE,
            Connection::SETTING_MAX_FRAME_SIZE => 0x4000,
            Connection::SETTING_HEADER_TABLE_SIZE => 0
        ];

        foreach ($settings as $k => $v) {
            switch ($k) {
                case Connection::SETTING_MAX_CONCURRENT_STREAMS:
                    $config[$k] = (int) $v;
                    break;
                case Connection::SETTING_MAX_FRAME_SIZE:
                    $config[$k] = (int) $v;
                    break;
            }
        }

        $this->settings = $config;
        $this->logger = $logger;
    }

    public function getProtocols(): array
    {
        return [
            'h2'
        ];
    }

    public function serve(SocketStream $socket, Context $context, ServerRequestFactoryInterface $f1, ResponseFactoryInterface $f2, RequestHandlerInterface $handler, array $params = []): void
    {
        $remaining = \strlen(Connection::PREFACE);
        $buffer = '';

        do {
            $chunk = $socket->read($remaining);

            if ($chunk === null) {
                throw new \RuntimeException('Failed to read HTTP/2 connection preface');
            }

            $buffer .= $chunk;
            $remaining -= \strlen($chunk);
        } while ($remaining > 0);

        if ($buffer != Connection::PREFACE) {
            throw new \RuntimeException('Client did not sent an HTTP/2 connection preface');
        }

        $settings = '';

        foreach ($this->settings as $k => $v) {
            $settings .= \pack('nN', $k, $v);
        }

        Task::async([
            $socket,
            'write'
        ], (new Frame(Frame::SETTINGS, 0, $settings))->encode());
        
        $conn = Connection::serve($socket, new HPack(new HPackServerContext()), $this->settings, $this->logger);

        try {
            foreach ($conn as $callback) {
                Task::async($callback, $f1, $f2, $handler, $params);
            }
        } finally {
            $conn->close();
        }
    }
}
