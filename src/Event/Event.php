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

class Event
{
    public $data;

    public $event;

    public $id;

    public function __construct($data, ?string $event = null, ?string $id = null)
    {
        if (\is_array($data)) {
            $this->data = \json_encode($data, \JSON_UNESCAPED_SLASHES);
        } else {
            $this->data = (string) $data;
        }

        $this->event = $event;
        $this->id = $id;
    }

    public function __toString(): string
    {
        $buffer = ($this->id === null) ? '' : ('id: ' . $this->event . "\n");

        if ($this->event !== null) {
            $buffer .= 'event: ' . $this->event . "\n";
        }

        $buffer .= 'data: ' . \strtr($this->data, [
            "\n" => "\ndata: ",
            "\r" => ''
        ]) . "\n";
        
        return $buffer . "\n";
    }
}
