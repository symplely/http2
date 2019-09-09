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
use Concurrent\Channel;
use Concurrent\ChannelClosedException;
use Concurrent\Context;
use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Network\Server;
use Concurrent\Network\SocketDisconnectException;
use Concurrent\Network\SocketListenException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Listens on a socket for connections and handles them using a pool of workers.
 * 
 * @author martin Schröder
 */
class HttpServerListener
{
    /**
     * task that handles incoming connection.
     * 
     * @var Task
     */
    private $task;

    /**
     * Cancellation handler of the acceptor task (will be NULL after use).
     * 
     * @var callable
     */
    private $cancel;

    /**
     * Async context being used to handle HTTP requests.
     * 
     * @var Context
     */
    private $context;

    /**
     * Cancellable context being used to run the acceptor task.
     * 
     * @var Context
     */
    private $accept;

    /**
     * Maximum number of concurrent connections that can be handled.
     * 
     * @var int
     */
    private $concurrency;

    /**
     * Number of active worker tasks.
     * 
     * @var int
     */
    private $count = 0;

    /**
     * PSR logger being used to log errors.
     * 
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Create a new HTTP server listener.
     * 
     * @param Server $server Server to be used when accepting connections.
     * @param callable $callback Callback to be used to process a connection.
     * @param LoggerInterface $logger Logger being used to log errors.
     * @param int $concurrency Limits the maximum number of concurrent connections that can be handled.
     */
    public function __construct(Server $server, callable $callback, ?LoggerInterface $logger = null, int $concurrency = 10000)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->concurrency = $concurrency;

        $this->context = Context::current();
        $this->accept = $this->context->withCancel($this->cancel);

        $this->task = Task::asyncWithContext($this->accept, function () use ($server, $callback) {
            $this->acceptTask($server, $callback);
        });
    }

    /**
     * Perform graceful shutdown of the HTTP server.
     */
    public function shutdown(): void
    {
        if ($this->cancel !== null) {
            $cancel = $this->cancel;
            $this->cancel = null;

            $cancel();
        }
    }
    
    /**
     * Wait for all pending HTTP requests to be handled.
     */
    public function join(): void
    {
        Task::await($this->task);
    }

    /**
     * Log error thrown within acceptor or worker task.
     */
    private function logError(\Throwable $e): void
    {
        if (!$e instanceof SocketDisconnectException) {
            $this->logger->error(\sprintf('%s: %s', \get_class($e), $e->getMessage()), [
                'exception' => $e
            ]);
        }
    }

    /**
     * Task that accepts incoming socket connections and delegates them to a worker pool.
     */
    private function acceptTask(Server $server, callable $callback)
    {
        $context = Context::background();

        $defer = new Deferred();
        $channel = new Channel();

        // Worker job that pulls sockets from a channel and executes the HTTP server callback.
        $worker = function (iterable $conns) use ($callback, $defer): void {
            try {
                foreach ($conns as $socket) {
                    try {
                        $this->context->run($callback, $socket, $this->accept);
                    } catch (\Throwable $e) {
                        $this->logError($e);
                    } finally {
                        $socket->close();
                    }
                }
            } catch (\Throwable $e) {
                $this->logError($e);
            } finally {
                // Resolve deferred when all workers have finished execution.
                if (0 == --$this->count && $this->cancel === null) {
                    $defer->resolve();
                }
            }
        };

        // Accept incoming connections and delegate them to workers.
        while (true) {
            try {
                try {
                    $socket = $server->accept();
                } catch (SocketListenException | CancellationException $e) {
                    $channel->close();

                    break;
                }

                // Spawn new worker if concurrency limit has not been reached yet.
                if ($this->count < $this->concurrency) {
                    $this->count++;

                    Task::asyncWithContext($context, $worker, $channel->getIterator());
                }

                try {
                    $channel->send($socket);
                } catch (ChannelClosedException | CancellationException $e) {
                    $channel->close();

                    // Handle last connection in acceptor task.
                    $this->context->run($worker, [
                        $socket
                    ]);

                    break;
                }
            } catch (\Throwable $e) {
                $this->logError($e);
            }
        }

        // Wait for busy workers before resolving shutdown awaitable.
        if ($this->count > 0) {
            return $defer->awaitable();
        }
    }
}
