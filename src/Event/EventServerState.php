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

namespace Concurrent\Http\Event;

class EventServerState
{
    public $closed = false;
    
    public $clients = [];

    public $bufferSize;

    public function __construct(int $bufferSize = 0xFFFF)
    {
        $this->bufferSize = $bufferSize;
    }

    public function close(): void
    {
        $this->closed = true;
        
        foreach ($this->clients as $client) {
            $client->close();
        }
    }

    public function connect(?callable $disconnect = null): EventServerClient
    {
        $uuid = \random_bytes(16);
        $uuid[6] = ($uuid[6] & "\x0F") | "\x40";
        $uuid[8] = ($uuid[8] & "\x3F") | "\x80";

        $uuid = \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($uuid), 4));

        return $this->clients[$uuid] = new EventServerClient($this, $uuid, $this->bufferSize, $disconnect);
    }
}
