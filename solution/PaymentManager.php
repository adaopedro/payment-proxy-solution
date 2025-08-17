<?php

declare(strict_types=1);

namespace Solution;

use Psr\Http\Message\ResponseInterface;
use React\Promise\{PromiseInterface, Deferred};
use React\Http\Browser as HttpClient;

use function React\Promise\all;

final class PaymentManager
{
    public const string PAYMENT_KEY_PREFIX = "payment_";

    public function __construct(
        private RedisWrapper $redisWrapper,
        private HttpClient $httpClient
    ) {}

    public function processRequestAsync(array $data): PromiseInterface
    {

        $requestedAt = new \DateTime('now', new \DateTimeZone('UTC'));
        $data["requestedAt"] = $requestedAt->format("Y-m-d\TH:i:s.u\Z");

        $deferred = new Deferred();

        $paymentKey = self::PAYMENT_KEY_PREFIX . $data["correlationId"];

        $this->redisWrapper->keyExistsAsync($paymentKey)
            ->then(function ($exists) use ($data, $deferred) {

                if ($exists) {
                    $deferred->reject(new RecordAlreadyExistsException("correlationId already exists"));
                } else {
                    $this->enqueuePaymentRequest($data)
                        ->then(function ()  use ($deferred) {
                            $deferred->resolve(1);
                        });
                }
            });

        return $deferred->promise();
    }

    public function enqueuePaymentRequest(array $data, ?string $streamName = "payment_stream"): PromiseInterface
    {
        $argumentsList = \convertFlattenedArrayToArgumentList($this->prepareStreamData($data));

        return $this->redisWrapper->publishToStream($streamName, $argumentsList);
    }

    private function prepareStreamData(array $data): array
    {
        $streamData = \flattenArray($data);

        $streamData['enqueuedAtAsTimestamp'] = (string) time();

        return $streamData;
    }

    public function forwardPaymentToProcessor(array $data, string $processorUrl): PromiseInterface
    {

        $headers = ["Content-Type" => "application/json"];
        $body = \json_encode($data);

        $deferred = new Deferred();

        $this->httpClient->post($processorUrl . "/payments", $headers, $body)
            ->then(
                function (ResponseInterface $response) use ($data, $deferred) {
                    $this->savePaymentAsync($data)
                        ->then(function () use ($deferred) {
                            $deferred->resolve(true);
                        })
                        ->catch(function (\Throwable $e) use ($deferred) {

                            $deferred->reject($e);
                        });
                },
            )
            ->catch(function (\Throwable $e) use ($deferred) {

                if (strpos($e->getMessage(), "HTTP status code 422") !== false) {
                    $deferred->resolve(true);
                    return;
                }

                $deferred->reject($e);
            });

        return $deferred->promise();
    }

    private function savePaymentAsync(array $paymentData): PromiseInterface
    {
        $paymentKey = self::PAYMENT_KEY_PREFIX . $paymentData["correlationId"];

        $preparePayload = static function (array $paymentData): array {
            $result = [];

            foreach ($paymentData as $index => $value) {
                $result[] = (string) $index;
                $result[] = (string) $value;
            }

            return $result;
        };

        $preparedPayload = $preparePayload($paymentData);

        $deferred = new Deferred();

        $this->redisWrapper->setMultipleHashFields($paymentKey, $preparedPayload)
            ->then(function ($result) use ($deferred, $paymentData) {
                $correlationId = $paymentData["correlationId"];
                $requestedAt = $paymentData["requestedAt"];

                if (!$requestedAt || !$correlationId) {
                    $deferred->resolve(0);
                    return;
                }

                $score = (float) (strtotime($requestedAt) . '.' . substr(microtime(), 2, 3));

                $this->redisWrapper->saveKeyInSortedSet("payments_by_date", $score, $correlationId)
                    ->then(fn($result) => $deferred->resolve($result));
            });

        return $deferred->promise();
    }

    public function getPaymentsByDateRangeAsync(?\DateTime $from = null, ?\DateTime $to = null): PromiseInterface
    {
        $deferred = new Deferred();

        if ($from === null && $to !== null) {
            $deferred->reject(new \InvalidArgumentException("Invalid params"));
            return $deferred->promise();
        }

        if (!$from && !$to) {
            return $this->fetchAllPaymentsAsync();
        }

        $to = $to ?? new \DateTime('now', new \DateTimeZone('UTC'));

        return $this->fetchPaymentsByDateRangeAsync($from, $to);
    }

    private function fetchAllPaymentsAsync(): PromiseInterface
    {
        $deferred = new Deferred();

        $this->redisWrapper->getAllSortedSetEntries("payments_by_date")
            ->then(function ($entryIds) use ($deferred) {
                $promisesArray = [];

                foreach ($entryIds as $entryId) {
                    $promisesArray[] = $this->redisWrapper->getAllFieldsFromHashEntry(self::PAYMENT_KEY_PREFIX . $entryId);
                }

                all($promisesArray)->then(function (array $entriesData) use ($deferred) {
                    $dataTransformed = [];

                    foreach ($entriesData as $item) {
                        $dataTransformed[] = \unflattenArray($item);
                    }

                    $deferred->resolve(
                        $this->aggregatePaymentsByProcessor($dataTransformed)
                    );
                });
            });

        return $deferred->promise();
    }

    private function fetchPaymentsByDateRangeAsync(\DateTime $from, \DateTime $to): PromiseInterface
    {

        $fromAsStr =  $from->format("Y-m-d\TH:i:s.u\Z");
        $toAsStr =    $to->format("Y-m-d\TH:i:s.u\Z");

        $start = (float) (strtotime($fromAsStr) . '.' . substr(microtime(), 2, 3));
        $end = (float) (strtotime($toAsStr) . '.' . substr(microtime(), 2, 3));

        $deferred = new Deferred();

        $this->redisWrapper->getAllEntriesInScoreInterval("payments_by_date", $start, $end)
            ->then(function ($entryIds) use ($deferred) {
                $promisesArray = [];

                foreach ($entryIds as $entryId) {
                    $promisesArray[] = $this->redisWrapper->getAllFieldsFromHashEntry(self::PAYMENT_KEY_PREFIX . $entryId);
                }

                all($promisesArray)->then(function (array $entriesData) use ($deferred) {
                    $dataTransformed = [];

                    foreach ($entriesData as $item) {
                        $dataTransformed[] = \unflattenArray($item);
                    }

                    $deferred->resolve(
                        $this->aggregatePaymentsByProcessor($dataTransformed)
                    );
                });
            });

        return $deferred->promise();
    }

    private function aggregatePaymentsByProcessor(array $result): array
    {
        $data = [
            "default" => ["totalRequests" => 0, "totalAmount" => 0],
            "fallback" => ["totalRequests" => 0, "totalAmount" => 0]
        ];

        foreach ($result as $payment) {
            $index = $payment["processor"];

            $data[$index]["totalRequests"] += 1;
            $data[$index]["totalAmount"] += (float) $payment["amount"];
        }

        return $data;
    }
}
