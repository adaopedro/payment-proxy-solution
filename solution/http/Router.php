<?php

declare(strict_types=1);

namespace Solution\Http;

use \Psr\Http\Message\ServerRequestInterface;
use \React\Http\Message\Response;
use FastRoute\RouteCollector;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased;

final class Router
{

    private Dispatcher $dispatcher;

    public function __construct(RouteCollector $routes)
    {
        $this->dispatcher = new GroupCountBased($routes->getData());
    }

    public function __invoke(ServerRequestInterface $request)
    {

        $routeInfo = $this->dispatcher->dispatch(
            $request->getMethod(),
            $request->getUri()->getPath()
        );

        $result = $routeInfo[0];

        switch ($result) {
            case Dispatcher::NOT_FOUND:
                return new Response(
                    404,
                    ["Content-Type" => "text/plain"],
                    "Not found"
                );

            case Dispatcher::METHOD_NOT_ALLOWED:
                return new Response(
                    405,
                    ["Content-Type" => "text/plain"],
                    "Method not allowed"
                );

            case Dispatcher::FOUND:
                $handler = $routeInfo[1];

                $response = $handler($request);

                return $response;
        }

        throw new \LogicException("Something went wrong with routing logic");
    }
}
