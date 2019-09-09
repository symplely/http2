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

class HPackClientContext extends HPackContext
{
    protected const INDEXED_ENTRIES = [
        'accept',
        'accept-charset',
        'accept-encoding',
        'accept-language',
        'cache-control',
        'content-type',
        'dnt',
        'expect',
        'host',
        'max-forwards',
        'origin',
        'pragma',
        'user-agent',
        'x-forwarded-proto',
        'x-requested-with'
    ];

    protected const NEVER_INDEXED_ENTRIES = [
        'authorization',
        'date',
        'if-match',
        'if-modified-since',
        'if-none-match',
        'if-range',
        'if-unmodified-since',
        'proxy-authorization',
        'range',
        'referer',
        'x-forwarded-for'
    ];

    public function __construct(bool $indexing = true, bool $compression = true)
    {
        parent::__construct($indexing, $compression);
        
        $indexed = \array_fill_keys(self::INDEXED_ENTRIES, self::INDEXED);
        $never = \array_fill_keys(self::NEVER_INDEXED_ENTRIES, self::NEVER_INDEXED);
        
        $this->encodings = \array_merge($indexed, $never);
    }
}
