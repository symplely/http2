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

interface ConnectionManager
{
    public function getKey(string $host, ?int $port, bool $encrypted = false): string;
    
    public function close(?\Throwable $e = null): void;

    public function checkout(string $host, ?int $port, bool $encrypted = false, array $protocols = []): Connection;

    public function checkin(Connection $conn): void;

    public function detach(Connection $conn): void;

    public function release(Connection $conn, ?\Throwable $e = null): void;
}
