<?php

declare(strict_types=1);

namespace Solution\Http;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\{PromiseInterface, Deferred};
use Solution\PaymentManager;

final class GetPaymentsController
{

    public function __construct(
        private PaymentManager $paymentManager,
    ) {}

    public function __invoke(ServerRequestInterface $request): PromiseInterface
    {

        // echo "GET Request handled by " .  gethostname() . PHP_EOL;

        try {

            $dates = $this->getDatesFromRequest($request);

            $deferred = new Deferred();

            $this->paymentManager->getPaymentsByDateRangeAsync(...$dates)
                ->then(
                    function (array $payments) use ($deferred) {
                        $deferred->resolve(
                            new Response(
                                200, //Ok
                                ["Content-Type" => "application/json"],
                                \json_encode($payments)
                            )
                        );
                    },
                    function (\InvalidArgumentException $e) use ($deferred) {
                        $code = null;

                        if ($e instanceof \InvalidArgumentException) {
                            $code = 400; //Bad Request
                        }

                        $deferred->resolve(
                            new Response(
                                $code,
                                ["Content-Type" => "application/json"],
                                \json_encode(["error-message" => $e->getMessage()])
                            )
                        );
                    }
                );
        } catch (\InvalidArgumentException $e) {
            echo $e->getTraceAsString() . PHP_EOL;

            $deferred->resolve(
                new Response(
                    400, //Bad Request
                    ["Content-Type" => "application/json"],
                    \json_encode(["error-message" => $e->getMessage()])
                )
            );
        }

        return $deferred->promise();
    }

    private function getDatesFromRequest(ServerRequestInterface $request): array
    {
        $from = null;
        $to = null;

        try {
            $from = $request->getQueryParams()["from"] ?? null;
            $to = $request->getQueryParams()["to"] ?? null;

            if ($from) {
                $from = new \DateTime($from);
            }

            if ($to) {
                $to = new \DateTime($to);
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Invalid params");
        }

        return [$from, $to];
    }
}
