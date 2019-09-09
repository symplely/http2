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

class TcpConnectionManagerConfig
{
    protected $interval = 5;

    protected $lifetime = 60;
    
    protected $encryption = [];

    public function getMaxIdleTime(): int
    {
        return $this->lifetime;
    }

    public function getIdleCheckInterval(): int
    {
        return $this->interval;
    }

    /**
     * Configure idle connection cleanup.
     * 
     * @param int $lifetime Maximum idle time in seconds.
     * @param int $interval Garbage collection interval in seconds.
     */
    public function withIdleTimeout(int $lifetime, int $interval): self
    {
        if ($lifetime < 5) {
            throw new \InvalidArgumentException(\sprintf('Connection lifetime must not be less than 5 seconds'));
        }

        if ($interval < 5) {
            throw new \InvalidArgumentException(\sprintf('Expiry check interval must not be less than 5 seconds'));
        }

        $config = clone $this;
        $config->lifetime = $lifetime;
        $config->interval = $interval;

        return $config;
    }
    
    public function getCustomEncryption(): array
    {
        return $this->encryption;
    }

    public function withCustomEncryption(string $host, callable $callback): self
    {
        $config = clone $this;
        $config->encryption[$host] = $callback;

        return $config;
    }
}
