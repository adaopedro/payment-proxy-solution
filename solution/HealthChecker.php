<?php

declare(strict_types=1);

namespace Solution;

use Psr\Http\Message\ResponseInterface;
use React\Promise\{PromiseInterface, Deferred};
use React\Http\Browser as HttpClient;

final class HealthChecker
{
    public const string CACHE_KEY = 'health_check_cache';
    public const string LOCK_KEY = 'health_check_lock';
    public const int CACHE_TTL = 5;   // in seconds
    public const int LOCK_TTL = 2;    // in seconds

    public function __construct(
        private RedisWrapper $redisWrapper,
        private HttpClient $httpClient,
        private array $processors
    ) {}

    public function getServiceDefaultHealth(): PromiseInterface
    {
        $processor = "default";
        $cacheKey = self::CACHE_KEY . "_$processor";
        $lockKey = self::LOCK_KEY . "_$processor";

        $deferred = new Deferred();

        // try to get health status from cache, else do the request and update the cache
        $this->tryToGetFromCache($processor, $cacheKey, $lockKey)
            ->then(fn($result) => $deferred->resolve($result))
            ->catch(fn(\Throwable $e) => $deferred->reject($e));

        return $deferred->promise();
    }

    public function getServiceFallbackHealth(): PromiseInterface
    {
        $processor = "fallback";
        $cacheKey = self::CACHE_KEY . "_$processor";
        $lockKey = self::LOCK_KEY . "_$processor";

        $deferred = new Deferred();

        // try to get health status from cache, else do the request and update the cache
        $this->tryToGetFromCache($processor, $cacheKey, $lockKey)
            ->then(fn($result) => $deferred->resolve($result))
            ->catch(fn(\Throwable $e) => $deferred->reject($e));

        return $deferred->promise();
    }

    private function tryToGetFromCache(string $processor, string $cacheKey, string $lockKey): PromiseInterface
    {
        $deferred = new Deferred();

        $this->redisWrapper->getValueByKey($cacheKey)->then(function ($cached) use ($deferred, $lockKey, $processor, $cacheKey) {
            if ($cached !== null) {
                $deferred->resolve(\json_decode($cached, true));
                return;
            }

            // Try to acquire the lock
            $this->redisWrapper->tryObtainLock($lockKey)->then(
                function (bool $acquired) use ($deferred, $lockKey, $processor, $cacheKey) {
                    if (!$acquired) {
                        // Another instance is updating; wait for the cache
                        $this->waitForCache($cacheKey);
                        return;
                    }

                    // Set the lock's TTL to avoid deadlock
                    $this->redisWrapper->setOrRefreshLock($lockKey, self::LOCK_TTL)
                        ->then(function () use ($deferred, $processor, $cacheKey) {
                            // Do the requests to update the cache
                            $this->fetchEndpointHealth($processor)
                                ->then(function ($result) use ($cacheKey, $deferred,) {
                                    $this->redisWrapper->setCacheWithExpiration($cacheKey, (string) \json_encode($result),  self::CACHE_TTL)
                                        ->then(function () use ($deferred, $result) {
                                            $deferred->resolve($result);
                                        });
                                });
                        });
                }
            );
        });

        return $deferred->promise();
    }

    private function fetchEndpointHealth(string $processor): PromiseInterface
    {

        $deferred = new Deferred();

        $startTime = microtime(true);

        $this->httpClient->get($this->processors[$processor] . "/payments/service-health")
            ->then(function (ResponseInterface $response) use ($deferred, $startTime) {
                $endTime = microtime(true);
                $latencyInMs = round(($endTime - $startTime) * 1000, 2);

                $statusCode = $response->getStatusCode();
                $message = (array) \json_decode($response->getBody()->getContents(), true);

                $result = [
                    "failing" => $message["failing"],
                    "minResponseTime" => $message["minResponseTime"],
                    "latency" => $latencyInMs
                ];

                $deferred->resolve($result);
            })
            ->catch(function (\Throwable $e) use ($deferred) {
                $deferred->reject($e);
            });

        return $deferred->promise();
    }

    public function resetCache(): PromiseInterface
    {
        return $this->redisWrapper->deleteCache([
            self::CACHE_KEY . "_default",
            self::CACHE_KEY . "_fallback",
            self::LOCK_KEY . "_default",
            self::LOCK_KEY . "_fallback",
        ]);
    }

    private function waitForCache(string $cacheKey, int $retries = 5, float $interval = 0.1): PromiseInterface
    {
        $deferred = new Deferred();

        if ($retries <= 0) {
            // echo "WARN: Timeout waiting for the cache" . PHP_EOL;
            $deferred->reject(new \RuntimeException('Timeout waiting for the cache'));
            return $deferred->promise();
        }

        // Wait a moment and try reading the cache again
        $this->redisWrapper->getValueByKey($cacheKey)->then(function ($cached) use ($deferred, $retries, $interval, $cacheKey) {
            if ($cached !== null) {
                $deferred->resolve(\json_decode($cached, true));
                return;
            }

            // If it is not set, wait a moment and try again
            \React\EventLoop\Loop::addTimer($interval, function () use ($deferred, $retries, $interval, $cacheKey) {
                $this->waitForCache($cacheKey, $retries - 1, $interval);
            });
        });

        return $deferred->promise();
    }
}
