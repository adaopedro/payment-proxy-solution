<?php

declare(strict_types=1);

use React\Http\Browser as HttpClient;
use Solution\PaymentManager;
use Solution\HealthChecker;
use Solution\RedisWrapper;
use Solution\Workers\Payments\PaymentStreamConsumer;

require dirname(__DIR__) . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";

$loop = \React\EventLoop\Loop::get();

define(
    "PROCESSORS",
    [
        "default" => $_ENV["PROCESSOR_DEFAULT_URL"],
        "fallback" => $_ENV["PROCESSOR_FALLBACK_URL"],
    ]
);

$redisUri = $_ENV['REDIS_HOST'] . ":" . $_ENV['REDIS_PORT'];

$sharedRedisClient = new \Clue\React\Redis\RedisClient($redisUri);
$streamRedisClient = new \Clue\React\Redis\RedisClient($redisUri);

$paymentManager = new PaymentManager(
    redisWrapper: new RedisWrapper($sharedRedisClient),
    httpClient: new HttpClient
);

$healthChecker = new HealthChecker(
    redisWrapper: new RedisWrapper($sharedRedisClient),
    httpClient: new HttpClient,
    processors: PROCESSORS
);

$consumer = new PaymentStreamConsumer(
    redisWrapper: new RedisWrapper($streamRedisClient),
    paymentManager: $paymentManager,
    healthChecker: $healthChecker,
    loop: $loop,
    serviceEndpoints: PROCESSORS
);

$consumer->start();

$loop->run();
