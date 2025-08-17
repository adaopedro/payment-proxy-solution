<?php

declare(strict_types=1);

namespace Solution\Workers\Payments;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Solution\HealthChecker;
use Solution\PaymentManager;
use Solution\RedisWrapper;

use function React\Promise\all;

class PaymentStreamConsumer
{
    private $consumerGroup = 'payment_processors';
    private $consumerName;
    private $streamName = 'payment_stream';

    public function __construct(
        private RedisWrapper $redisWrapper,
        private PaymentManager $paymentManager,
        private HealthChecker $healthChecker,
        private LoopInterface $loop,
        private array $serviceEndpoints
    ) {
        $this->consumerName = 'consumer_' . getmypid(); // Unique name per process
    }

    public function start(): void
    {
        // Criar consumer group (só precisa fazer uma vez)
        $this->createConsumerGroup()
            ->then(function () {
                $this->startConsuming();
            });
    }

    private function createConsumerGroup(): PromiseInterface
    {
        return $this->redisWrapper->initializeStreamGroupIfNotExists($this->streamName, $this->consumerGroup)
            ->catch(function (\Throwable $e) {
                // Group provavelmente já existe, continua normalmente
                if (strpos($e->getMessage(), "BUSYGROUP") !== false) {
                    return true;
                }
                throw $e;
            });
    }

    private function startConsuming(): void
    {
        echo "[Worker Payments] Ready!" . PHP_EOL;
        echo "[Worker Payments] INFO - Starting a consumer: {$this->consumerName}" . PHP_EOL;

        // Timer para processar mensagens continuamente
        $this->loop->addPeriodicTimer(0.1, function () {
            $this->processMessages();
        });

        // Timer para processar mensagens pendentes (reprocessamento)
        // $this->loop->addPeriodicTimer(1, function () {
        //     $this->processPendingMessages();
        // });
    }

    private function processMessages(): void
    {
        $this->redisWrapper->consumeNextStreamMessage($this->consumerGroup, $this->consumerName, $this->streamName)
            ->then(function ($streams) {
                if (empty($streams)) {
                    // echo "[Worker Payments] INFO - Empty stream" . PHP_EOL;
                    return;
                }

                foreach ($streams as $streamData) {

                    // Estrutura de dados do $streamData
                    // [
                    //     0 => "payment_stream",           // Nome do stream
                    //     1 => [                          // Array de mensagens
                    //         0 => [                      // Primeira mensagem
                    //             0 => "1755401499477-0", // ID da mensagem
                    //             1 => [                  // Campos da mensagem (array indexed, não associativo!)
                    //                 0 => "campo1",      // Nome do campo
                    //                 1 => "valor1",      // Valor do campo
                    //                 2 => "campo2",      // Nome do próximo campo
                    //                 3 => "valor2"       // Valor do próximo campo
                    //             ]
                    //         ]
                    //     ]
                    // ]

                    $streamName = $streamData[0];    // Nome do stream
                    $messages = $streamData[1];      // Array de mensagens

                    foreach ($streams as $streamData) {
                        $streamName = $streamData[0];
                        $messages = $streamData[1];

                        foreach ($messages as $messageData) {
                            $messageId = $messageData[0];
                            $rawFields = $messageData[1];

                            $payload = $this->convertRedisFieldsToArray($rawFields);

                            // echo "[Worker Payments] Processing message: {$messageId}" . PHP_EOL;
                            $this->handleMessage((string)$messageId, $payload);
                        }
                    }
                }
            })->catch(function ($error) {
                echo "[Worker Payments] ERROR - It wasn't able to read from stream: " . $error->getMessage() . PHP_EOL;
            });
    }

    private function processPendingMessages(): void
    {
        // echo "[Worker Payments] INFO - Checking pendent messages..." . PHP_EOL;

        $this->redisWrapper->getConsumerPendingMessages($this->streamName, $this->consumerGroup, $this->consumerName, 10)
            ->then(function ($pendingMessages) {
                if (empty($pendingMessages)) {
                    // echo "[Worker Payments] INFO - No pending messages" . PHP_EOL;
                    return;
                }

                // echo "Found " . count($pendingMessages) . " pendent messages" . PHP_EOL;

                foreach ($pendingMessages as $pending) {
                    $messageId = $pending[0];

                    // Reivindicar mensagem pendente
                    $this->redisWrapper->claimPendingMessage(
                        $this->streamName,
                        $this->consumerGroup,
                        $this->consumerName,
                        [$messageId],
                        1000 // 1 segundo
                    )->then(function ($claimedMessages) {
                        foreach ($claimedMessages as $messageId => $data) {
                            if (!empty($data)) {
                                // echo "Reprocessing message: {$messageId}" . PHP_EOL;
                                $this->handleMessage((string) $messageId, $data);
                            }
                        }
                    });
                }
            });
    }

    private function handleMessage(string $messageId, array $paymentData): void
    {
        try {
            // echo "[Worker Payments] INFO - Processing message: {$messageId}" . PHP_EOL;

            // Reconstruir dados originais

            try {

                // $data = (array) json_decode($payload, true);

                // echo "[Worker Payment] Processing payment for amount: " . ($data['amount'] ?? 'unknown') . PHP_EOL;

                all([
                    $this->healthChecker->getServiceDefaultHealth(),
                    $this->healthChecker->getServiceFallbackHealth(),
                ])->then(function (array $servicesHealth) use ($paymentData, $messageId) {
                    [$default, $fallback] = $servicesHealth;

                    $optimalService = \selectOptimalService($default, $fallback);

                    if (!$optimalService) {
                        $this->healthChecker->resetCache();
                        return;
                    }

                    $paymentData["processor"] = $optimalService;

                    // echo sprintf(
                    //     "[Worker Payments] Selected processor: %s (latency: %sms)\n",
                    //     $optimalService,
                    //     $optimalService === 'default' ? ($default['latency'] ?? 'unknown') : ($fallback['latency'] ?? 'unknown')
                    // );

                    $this->processPayment($paymentData, $optimalService)
                        ->then(function () use ($messageId) {
                            // Sucesso - fazer ACK da mensagem
                            return $this->acknowledgeMessage($messageId);
                        })
                        ->then(function () use ($messageId) {
                            // echo "[Worker Payments] INFO - Message processed successfuly: {$messageId}" . PHP_EOL;
                        })
                        ->catch(function (\Throwable $e) use ($messageId) {
                            echo "[Worker Payments] ERROR - It wasn't able to process the message {$messageId}: " . $e->getMessage() . PHP_EOL;
                            // Não fazer ACK - mensagem ficará pendente para reprocessamento
                        });
                })->catch(function (\Throwable $e) {
                    echo "[Worker Payments] ERROR - Health check error: " . $e->getMessage() . PHP_EOL;
                });
            } catch (\Throwable $e) {
                echo "[Worker Payments] ERROR - Unexpected error: ", $e->getMessage() . PHP_EOL;
            }
        } catch (\Throwable $e) {
            echo "[Worker Payments] ERROR - Error on handleMessage: " . $e->getMessage() . PHP_EOL;
        }
    }

    private function processPayment(array $payload, string $service): PromiseInterface
    {
        return $this->paymentManager->forwardPaymentToProcessor(
            data: $payload,
            processorUrl: $this->serviceEndpoints[$service]
        );
    }

    private function acknowledgeMessage(string $messageId): PromiseInterface
    {
        return $this->redisWrapper->confirmStreamMessageProcessing($this->streamName, $this->consumerGroup, [$messageId]);
    }

    private function convertRedisFieldsToArray(array $rawFields): array
    {
        $result = [];

        for ($i = 0; $i < count($rawFields); $i += 2) {
            if (isset($rawFields[$i + 1])) {
                $fieldName = $rawFields[$i];
                $fieldValue = $rawFields[$i + 1];
                $result[$fieldName] = $fieldValue;
            }
        }

        return $result;
    }
}
