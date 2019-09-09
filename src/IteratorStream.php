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

class IteratorStream extends StreamAdapter
{
    protected $it;
    
    protected $primed = false;

    public function __construct(\Iterator $it)
    {
        $this->it = $it;
    }

    public static function generate(callable $callback, ...$args)
    {
        $result = $callback(...$args);

        if ($result instanceof \Generator) {
            return new static($result);
        }

        return new static(new \ArrayIterator([
            $result
        ]));
    }
    
    public function close()
    {
        if ($this->buffer !== null) {
            $this->buffer = null;

            if ($this->it instanceof \Generator && $this->it->valid()) {
                $e = new \RuntimeException('Stream has been closed');

                do {
                    try {
                        $this->it->throw($e);
                    } catch (\Throwable $e) {
                        // Forward error into generator if still valid, ignore it otherwise.
                    }
                } while ($this->it->valid());
            }
        }
    }

    protected function readNextChunk(): string
    {
        $val = '';

        do {
            if ($this->primed) {
                $this->it->next();
            } else {
                $this->primed = true;
            }

            if (!$this->it->valid()) {
                break;
            }

            if ('' !== ($val = $this->it->current())) {
                break;
            }
        } while (true);

        return $val;
    }
}
