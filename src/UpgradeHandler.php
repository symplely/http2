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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface UpgradeHandler
{
    public function getProtocol(): string;

    public function populateResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;

    public function handleConnection(UpgradeStream $stream);
}
