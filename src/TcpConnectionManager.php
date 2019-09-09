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

use Concurrent\Context;
use Concurrent\Deferred;
use Concurrent\Task;
use Concurrent\Timer;
use Concurrent\Network\TcpSocket;
use Concurrent\Network\TlsClientEncryption;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class TcpConnectionManager implements ConnectionManager
{
    protected $counts = [];

    protected $conns = [];

    protected $connecting = [];

    protected $expires;

    protected $interval;

    protected $lifetime;

    protected $encryption;
    
    protected $timer;

    protected $logger;

    /**
     * Create a new connection manager with limited concurrency and socket lifetime.
     */
    public function __construct(?TcpConnectionManagerConfig $config = null, ?LoggerInterface $logger = null)
    {
        if ($config === null) {
            $config = new TcpConnectionManagerConfig();
        }

        $this->lifetime = $config->getMaxIdleTime();
        $this->interval = $config->getIdleCheckInterval() * 1000;
        $this->encryption = $config->getCustomEncryption();
        
        $this->logger = $logger ?? new NullLogger();

        $this->expires = new \SplPriorityQueue();
        $this->expires->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    public function __destruct()
    {
        $this->close();

        $count = 0;

        foreach ($this->conns as $conns) {
            foreach ($conns as $conn) {
                $conn->socket->close();

                $count++;
            }
        }

        $e = new \RuntimeException('Connection manager has been disposed');

        foreach ($this->connecting as $attempts) {
            foreach ($attempts as $defer) {
                $defer->fail($e);
            }
        }

        $this->logger->debug('Disposed of {num} connections', [
            'num' => $count
        ]);
    }
    
    public function getKey(string $host, ?int $port, bool $encrypted = false): string
    {
        return \sprintf('%s|%u', $host, $port ?? ($encrypted ? 443 : 80));
    }

    public function close(?\Throwable $e = null): void
    {
        if ($this->timer !== null) {
            $this->timer->close($e);
            $this->timer = null;
        }
    }

    public function checkout(string $host, ?int $port, bool $encrypted = false, array $protocols = []): Connection
    {
        if ($this->timer === null) {
            $this->timer = new Timer($this->interval);

            Task::asyncWithContext(Context::background(), \Closure::fromCallable([
                $this,
                'gc'
            ]));
        }

        if ($port === null) {
            $port = $encrypted ? 443 : 80;
        }
        
        $key = \sprintf('%s|%u|%s', $ip = \gethostbyname($host), $port, $encrypted ? $host : '');
        
        do {
            if (!empty($this->conns[$key])) {
                $this->logger->debug('Reuse connection tcp://{ip}:{port}', [
                    'ip' => $ip,
                    'port' => $port
                ]);

                $conn = \array_shift($this->conns[$key]);
                $conn->expires = 0;

                break;
            }

            if (($this->counts[$key] ?? 0) < 8) {
                $this->logger->debug('Connect to tcp://{ip}:{port}', [
                    'ip' => $ip,
                    'port' => $port
                ]);

                $conn = $this->connect($host, $key, $protocols);

                break;
            }

            $this->logger->debug('Await connection tcp://{ip}:{port}', [
                'ip' => $ip,
                'port' => $port
            ]);

            $this->connecting[$key][] = $defer = new Deferred();

            $conn = Task::await($defer->awaitable());
        } while ($conn === null);

        $conn->requests++;

        return $conn;
    }

    public function checkin(Connection $conn): void
    {
        if ($conn->maxRequests > 0 && $conn->requests >= $conn->maxRequests) {
            $this->release($conn);

            return;
        }

        list ($ip, $port) = \explode('|', $conn->key);

        $this->logger->debug('Checkin connection tcp://{ip}:{port}', [
            'ip' => $ip,
            'port' => (int) $port
        ]);

        if (empty($this->connecting[$conn->key])) {
            $this->conns[$conn->key][] = $conn;

            $conn->expires = \time() + $this->lifetime;
            $this->expires->insert($conn, -$conn->expires);
        } else {
            $defer = \array_shift($this->connecting[$conn->key]);

            if (empty($this->connecting[$conn->key])) {
                unset($this->connecting[$conn->key]);
            }

            $defer->resolve($conn);
        }
    }

    public function detach(Connection $conn): void
    {
        list ($ip, $port) = \explode('|', $conn->key);

        $this->logger->debug('Detach connection tcp://{ip}:{port}', [
            'ip' => $ip,
            'port' => (int) $port
        ]);

        $this->dispose($conn);
    }

    public function release(Connection $conn, ?\Throwable $e = null): void
    {
        list ($ip, $port) = \explode('|', $conn->key);

        $this->logger->debug('Release connection tcp://{ip}:{port}', [
            'ip' => $ip,
            'port' => (int) $port
        ]);

        $this->dispose($conn);

        $conn->socket->close($e);
    }

    protected function connect(string $host, string $key, array $protocols): Connection
    {
        if (isset($this->counts[$key])) {
            $this->counts[$key]++;
        } else {
            $this->counts[$key] = 1;
        }

        try {
            list ($ip, $port, $encrypt) = \explode('|', $key);

            if ($encrypt !== '') {
                $tls = new TlsClientEncryption();

                if (isset($this->encryption[$host])) {
                    $tls = $this->encryption[$host]($tls);
                }

                $host .= ':' . $port;

                if (isset($this->encryption[$host])) {
                    $tls = $this->encryption[$host]($tls);
                }

                $tls = $tls->withPeerName($encrypt);

                if (!empty($protocols)) {
                    $tls = $tls->withAlpnProtocols(...$protocols);
                }
            } else {
                $tls = null;
            }

            $socket = TcpSocket::connect($ip, (int) $port, $tls);

            try {
                if ($encrypt) {
                    $info = $socket->encrypt();
                } else {
                    $info = null;
                }

                return new Connection($key, $socket, $info);
            } catch (\Throwable $e) {
                $socket->close();

                throw $e;
            }
        } catch (\Throwable $e) {
            $this->counts[$key]--;

            if (empty($this->counts[$key])) {
                unset($this->counts[$key]);
            }

            throw $e;
        }
    }

    protected function dispose(Connection $conn): void
    {
        $conn->expires = 0;

        $this->counts[$conn->key]--;

        if (empty($this->counts[$conn->key])) {
            unset($this->counts[$conn->key]);
        }

        if (!empty($this->conns[$conn->key])) {
            if (false !== ($key = \array_search($conn, $this->conns[$conn->key], true))) {
                unset($this->conns[$conn->key][$key]);

                if (empty($this->conns[$conn->key])) {
                    unset($this->conns[$conn->key]);
                }
            }
        }

        if (!empty($this->connecting[$conn->key])) {
            $defer = \array_shift($this->connecting[$conn->key]);

            if (empty($this->connecting[$conn->key])) {
                unset($this->connecting[$conn->key]);
            }

            $defer->resolve();
        }
    }

    protected function gc()
    {
        while (true) {
            $this->timer->awaitTimeout();

            $time = \time();
            $purged = 0;

            while (!$this->expires->isEmpty()) {
                $entry = $this->expires->top();

                if ($entry['priority'] != -$entry['data']->expires) {
                    $this->expires->extract();

                    continue;
                }

                if ($entry['data']->expires < $time) {
                    $this->expires->extract();

                    $this->release($entry['data']);

                    $purged++;

                    continue;
                }

                break;
            }

            if ($purged) {
                $this->logger->debug('Disposed of {num} expired connections', [
                    'num' => $purged
                ]);
            }
        }
    }
}
