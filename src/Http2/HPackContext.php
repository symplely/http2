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

/**
 * Provides shared context that can be used by multiple HPACK coder instances.
 *
 * @author Martin Schröder
 */
class HPackContext
{
    public const LITERAL = 0;

    public const INDEXED = 1;

    public const NEVER_INDEXED = 2;

    protected $indexingEnabled = true;

    protected $compressionEnabled = true;

    protected $encodings = [];
    
    protected $compression;

    public function __construct(bool $indexing = true, bool $compression = true)
    {
        $this->indexingEnabled = $indexing;
        $this->compressionEnabled = $compression;
        
        $this->compression = new HPackCompression();
    }

    public function isIndexingEnabled(): bool
    {
        return $this->indexingEnabled;
    }

    public function withIndexingEnabled(bool $indexing): self
    {
        $context = clone $this;
        $context->indexingEnabled = $indexing;
        
        return $context;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->compressionEnabled;
    }

    public function withCompressionEnabled(bool $compression): self
    {
        $context = clone $this;
        $context->compressionEnabled = $compression;
        
        return $context;
    }
    
    public function getCompression(): HPackCompression
    {
        return $this->compression;
    }

    public function withCompression(HPackCompression $compression): self
    {
        $context = clone $this;
        $context->compression = $compression;
        
        return $context;
    }

    public function getEncoding(string $name): int
    {
        $encoding = $this->encodings[$name] ?? self::LITERAL;
        
        return $this->indexingEnabled ? $encoding : (($encoding == self::INDEXED) ? self::LITERAL : $encoding);
    }

    public function withIndex(string $name): self
    {
        $context = clone $this;
        $context->encodings[\strtolower($name)] = self::INDEXED;
        
        return $context;
    }

    public function withLiteral(string $name): self
    {
        $context = clone $this;
        unset($context->encodings[\strtolower($name)]);
        
        return $context;
    }

    public function withNeverIndexed(string $name): self
    {
        $context = clone $this;
        $context->encodings[\strtolower($name)] = self::NEVER_INDEXED;
        
        return $context;
    }
}
