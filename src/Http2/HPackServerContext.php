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

class HPackServerContext extends HPackContext
{
    protected const INDEXED_ENTRIES = [
        'accept-ranges',
        'cache-control',
        'content-encoding',
        'content-language',
        'content-type',
        'p3p',
        'server',
        'vary',
        'via',
        'x-frame-options',
        'x-xss-protection',
        'x-content-type-options',
        'x-powered-by',
        'x-ua-compatible'
    ];

    protected const NEVER_INDEXED_ENTRIES = [
        'age',
        'content-range',
        'date',
        'etag',
        'expires',
        'last-modified',
        'location',
        'proxy-authenticate',
        'set-cookie',
        'www-authenticate'
    ];

    public function __construct(bool $indexing = true, bool $compression = true)
    {
        parent::__construct($indexing, $compression);
        
        $indexed = \array_fill_keys(self::INDEXED_ENTRIES, self::INDEXED);
        $never = \array_fill_keys(self::NEVER_INDEXED_ENTRIES, self::NEVER_INDEXED);
        
        $this->encodings = \array_merge($indexed, $never);
    }
}
