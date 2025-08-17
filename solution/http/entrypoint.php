<?php

declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";

use React\EventLoop\Loop;
use React\Http\HttpServer;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use React\Socket\SocketServer;
use FastRoute\DataGenerator\GroupCountBased;
use \Clue\React\Redis\RedisClient;
use React\Http\Browser as HttpClient;
use Solution\PaymentManager;
use Solution\RedisWrapper;
use Solution\Http\CreatePaymentController;
use Solution\Http\CreatePaymentRequestValidator;
use Solution\Http\GetPaymentsController;
use Solution\Http\Router;

$loop = Loop::get();

$ipAddress = "0.0.0.0";
$port = $_ENV['APP_PORT'] ?? 8080;
$socket = new SocketServer("$ipAddress:$port");

$redisUri = $_ENV['REDIS_HOST'] . ":" . $_ENV['REDIS_PORT'];
$redisClient = new RedisClient($redisUri);

$paymentManager = new PaymentManager(
    redisWrapper: new RedisWrapper($redisClient),
    httpClient: new HttpClient(),
);

$routes = new RouteCollector(new Std(), new GroupCountBased());

$routes->post(
    "/payments",
    new CreatePaymentController($paymentManager, new CreatePaymentRequestValidator)
);

$routes->get(
    "/payments-summary",
    new GetPaymentsController($paymentManager)
);

$server = new HttpServer(new Router($routes));
$server->listen($socket);

echo "[HTTP Server] Ready!" . PHP_EOL;

$loop->run();
