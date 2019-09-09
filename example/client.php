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

use Concurrent\Http\ConnectionManager;
use Concurrent\Http\HttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(-1);
ini_set('display_errors', (DIRECTORY_SEPARATOR == '\\') ? '0' : '1');

$client = new HttpClient(new ConnectionManager(), $factory = new Psr17Factory());
$i = 0;

while (true) {
    $request = $factory->createRequest('GET', 'http://localhost:8080/');    
    $response = $client->sendRequest($request);

    print_r(array_map(function ($v) {
        return \implode(', ', $v);
    }, $response->getHeaders()));

    var_dump($response->getBody()->getContents());

    if (++$i == 3) {
        break;
    }

    sleep(3);
}
