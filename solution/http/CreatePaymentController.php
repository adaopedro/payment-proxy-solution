<?php

declare(strict_types=1);

namespace Solution\Http;

use React\Http\Message\Response;
use Psr\Http\Message\ServerRequestInterface;
use React\Promise\{PromiseInterface, Deferred};
use Solution\PaymentManager;
use Solution\RecordAlreadyExistsException;

final class CreatePaymentController
{

    public function __construct(
        private PaymentManager $paymentManager,
        private CreatePaymentRequestValidator $paymentRequestValidator
    ) {}

    public function __invoke(ServerRequestInterface $request): PromiseInterface
    {

        // echo "POST Request handled by " .  gethostname() . PHP_EOL;

        $requestBodyJsonContent = (string) $request->getBody()->getContents();
        $data = (array) \json_decode($requestBodyJsonContent);

        $deferred = new Deferred();

        try {
            $this->paymentRequestValidator->validate($data);

            $this->paymentManager->processRequestAsync($data)
                ->then(
                    function () use ($deferred) {
                        $deferred->resolve(
                            new Response(
                                201, //Created
                                ["Content-Type" => "application/json"],
                                \json_encode(["success-message" => "Payment sent"])
                            )
                        );
                    },
                    function (\InvalidArgumentException|RecordAlreadyExistsException $e) use ($deferred) {
                        $code = null;

                        if ($e instanceof \InvalidArgumentException) {
                            $code = 422; //Unprocessable Entity
                        }

                        if ($e instanceof RecordAlreadyExistsException) {
                            $code = 409; //Conflict
                        }

                        $deferred->resolve(
                            new Response(
                                $code,
                                ["Content-Type" => "application/json"],
                                \json_encode(["error-message" =>  $e->getMessage()])
                            )
                        );
                    }
                )
                ->catch(function (\Throwable $e) use ($deferred) {
                    echo "[Http Server] Fatal error. " . $e->getMessage() . PHP_EOL;

                    $deferred->resolve(
                        new Response(500) //Internal Server Error
                    );
                });
        } catch (\InvalidArgumentException $e) {
            $deferred->resolve(
                new Response(
                    422, //Unprocessable Entity
                    ["Content-Type" => "application/json"],
                    \json_encode(["error-message" =>  $e->getMessage()])
                )
            );
        }

        return $deferred->promise();
    }
}
