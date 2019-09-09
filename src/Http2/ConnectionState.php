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

use Concurrent\Awaitable;
use Concurrent\Channel;
use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Network\SocketStream;
use Concurrent\Sync\Condition;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ConnectionState
{
    public $socket;
    
    public $hpack;
    
    public $client;
    
    public $streams = [];
    
    public $nextStreamId;
    
    public $lastStreamId = 0;
    
    public $receiveWindow = Connection::INITIAL_WINDOW_SIZE;
    
    public $receiver;
    
    public $sendWindow = Connection::INITIAL_WINDOW_SIZE;
    
    public $sender;

    public $requests;
    
    public $localSettings = [];

    public $remoteSettings = [
        Connection::SETTING_ENABLE_PUSH => 1,
        Connection::SETTING_HEADER_TABLE_SIZE => 4096,
        Connection::SETTING_INITIAL_WINDOW_SIZE => 0xFFFF,
        Connection::SETTING_MAX_CONCURRENT_STREAMS => PHP_INT_MAX,
        Connection::SETTING_MAX_FRAME_SIZE => 0x400,
        Connection::SETTING_MAX_HEADER_LIST_SIZE => PHP_INT_MAX
    ];

    protected $pings = [];
    
    protected $logger;

    public function __construct(SocketStream $socket, HPack $hpack, array $settings, bool $client, ?LoggerInterface $logger)
    {
        $this->socket = $socket;
        $this->localSettings = $settings;
        $this->hpack = $hpack;
        $this->client = $client;

        $this->nextStreamId = $client ? 1 : 2;
        $this->sender = new Condition();

        $this->logger = $logger ?? new NullLogger();

        if (!$client) {
            $this->requests = new Channel($settings[Connection::SETTING_MAX_CONCURRENT_STREAMS]);
        }
    }
    
    public function __debugInfo(): array
    {
        return [
            'streams' => \count($this->streams)
        ];
    }
    
    public function close(?\Throwable $e = null): void
    {
        if ($this->requests) {
            $this->requests->close($e);
        }
        
        foreach (\array_values($this->streams) as $stream) {
            $stream->close($e);
        }
    }

    public function sendFrame(Frame $frame): void
    {
        $this->socket->write($frame->encode());
    }

    public function sendFrameAsync(Frame $frame): void
    {
        Task::async([
            $this->socket,
            'write'
        ], $frame->encode());
    }

    public function sendFrames(array $frames): void
    {
        $buffer = '';

        foreach ($frames as $frame) {
            $buffer .= $frame->encode();
        }

        $this->socket->write($buffer);
    }

    public function sendFramesAsync(array $frames): void
    {
        $buffer = '';

        foreach ($frames as $frame) {
            $buffer .= $frame->encode();
        }

        Task::async([
            $this->socket,
            'write'
        ], $buffer);
    }
    
    public function processSettings(Frame $frame): void
    {
        foreach (\str_split($frame->getPayload(), 6) as $setting) {
            $setting = \unpack('nkey/Nvalue', $setting);

            switch ($setting['key']) {
                case Connection::SETTING_INITIAL_WINDOW_SIZE:
                    $this->remoteSettings[Connection::SETTING_INITIAL_WINDOW_SIZE] = $setting['value'];
                    break;
                case Connection::SETTING_MAX_CONCURRENT_STREAMS:
                    $this->remoteSettings[Connection::SETTING_MAX_CONCURRENT_STREAMS] = $setting['value'];
                    break;
                case Connection::SETTING_MAX_FRAME_SIZE:
                    $this->remoteSettings[Connection::SETTING_MAX_FRAME_SIZE] = $setting['value'];
                    break;
            }
        }

        $this->sendFrameAsync(new Frame(Frame::SETTINGS, 0, '', Frame::ACK));
    }

    public function ping(): Awaitable
    {
        $id = \random_bytes(8);

        $this->sendFrameAsync(new Frame(Frame::PING, 0, $id));

        $defer = $this->pings[$id] = new Deferred();

        return $defer->awaitable();
    }

    public function processPing(Frame $frame): void
    {
        $id = $frame->getPayload();

        if (isset($this->pings[$id])) {
            $defer = $this->pings[$id];
            unset($this->pings[$id]);

            $defer->resolve();
        }
    }
}
