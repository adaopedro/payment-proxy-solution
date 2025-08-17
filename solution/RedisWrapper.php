<?php

declare(strict_types=1);

namespace Solution;

use Clue\React\Redis\RedisClient;
use React\Promise\PromiseInterface;

final class RedisWrapper
{

    public function __construct(private RedisClient $redisClient) {}

    // Adds one or more members to a sorted set, or updates their scores. Creates the key if it doesn't exist.
    public function saveKeyInSortedSet(string $sortedSetkey, float $score, string $itemKey): PromiseInterface
    {
        return $this->redisClient->zadd($sortedSetkey, $score, $itemKey);
    }

    // [HMSET] Sets the values of multiple fields. OBS. deprecated in Redis 4.0
    // replaced by [HSET] Creates or modifies the value of a field in a hash.
    public function setMultipleHashFields(string $key, array $fields): PromiseInterface
    {
        return $this->redisClient->hmset($key, ...$fields);
    }

    // Returns members in a sorted set within a range of indexes.
    public function getAllSortedSetEntries(string $sortedSetKey): PromiseInterface
    {
        return $this->redisClient->zrange($sortedSetKey, 0, -1);
    }

    // Returns all fields and values in a hash.
    public function getAllFieldsFromHashEntry(string $entryId): PromiseInterface
    {
        return $this->redisClient->hgetall($entryId);
    }

    // Returns members in a sorted set within a range of scores.
    public function getAllEntriesInScoreInterval(string $key, float $start, float $end): PromiseInterface
    {
        return $this->redisClient->zrangebyscore($key, $start, $end);
    }

    // Appends a new message to a stream. Creates the key if it doesn't exist.
    public function publishToStream(string $streamName, array $argumentsList, ?string $id = "*"): PromiseInterface
    {
        // '*' means the ID will be auto-generated
        return $this->redisClient->xAdd(
            $streamName,
            $id,
            ...$argumentsList
        );
    }

    // Returns the string value of a key.
    public function getValueByKey(string $cacheKey): PromiseInterface
    {
        return $this->redisClient->get($cacheKey);
    }

    // Set the string value of a key only when the key doesn't exist.
    public function tryObtainLock(string $lockKey): PromiseInterface
    {
        return $this->redisClient->setnx($lockKey, 1);
    }

    // Sets the expiration time of a key in seconds.
    public function setOrRefreshLock(string $lockKey, int $timeToLiveInSeconds): PromiseInterface
    {
        return $this->redisClient->expire($lockKey, $timeToLiveInSeconds);
    }

    // Sets the string value and expiration time of a key. Creates the key if it doesn't exist.
    public function setCacheWithExpiration(string $cacheKey, string $data, int $timeToLiveInSeconds): PromiseInterface
    {
        return $this->redisClient->setex($cacheKey, $timeToLiveInSeconds, $data);
    }

    // Deletes one or more keys.
    public function deleteCache(array $cacheKeys): PromiseInterface
    {
        return $this->redisClient->del(...$cacheKeys);
    }

    // Determines whether one or more keys exist.
    public function keyExistsAsync(string $cacheKey): PromiseInterface
    {
        return $this->redisClient->exists($cacheKey);
    }

    // Creates a consumer group. 
    // [MKSTREAM] means create stream if not exists
    // [$] Começa a consumir apenas novos eventos que forem adicionados após a criação do grupo. Ideal para processamento em tempo real.
    // [0] omeça a consumir desde o primeiro item do stream. Ideal para processar tudo.
    // [ID específico (ex: '1692356123456-0')] Começa a consumir a partir de um ponto exato do stream (útil para controle fino ou retomadas).
    public function initializeStreamGroupIfNotExists(string $streamName, string $groupName, ?string $initialReadPosition = "$"): PromiseInterface
    {
        return $this->redisClient->xGroup("CREATE", $streamName, $groupName, $initialReadPosition, "MKSTREAM");
    }

    // Returns new or historical messages from a stream for a consumer in a group. Blocks until a message is available otherwise.
    // Redis Command: XREADGROUP GROUP $consumerGroup $ConsumerName COUNT 1 BLOCK 1000 STREAMS streamName >
    // COUNT 1 => processar 1 mensagem por vez
    // BLOCK 1000 => timeout em ms (1 segundo)
    /*  SEM NOACK - queremos controle manual, ao contrario de NOACK - Mensagens não ficam pendentes.
        Nome do stream + ID. Valores de ID mais comuns: '>' - Apenas mensagens novas (não lidas pelo consumer group).
        '$' - A partir do último ID no stream. '0' - Desde o início do stream. '1234567890-0' - A partir de um ID específico
    */
    public function consumeNextStreamMessage(
        string $consumerGroup,
        string $ConsumerName,
        string $streamName,
        ?string $readPosition = ">"
    ): PromiseInterface {
        return $this->redisClient->xReadGroup(
            "GROUP",
            $consumerGroup,
            $ConsumerName,
            "COUNT",
            1,
            "BLOCK",
            1000,
            "STREAMS",
            $streamName,
            $readPosition
        );
    }

    // Returns the number of messages that were successfully acknowledged by the consumer group member of a stream.
    public function confirmStreamMessageProcessing(string $streamName, string $consumerGroup, array $messageIds): PromiseInterface
    {
        return $this->redisClient->xAck($streamName, $consumerGroup, ...$messageIds);
    }

    // Returns the information and entries from a stream consumer group's pending entries list.
    // '-' e '+' — intervalo completo (do menor ao maior ID pendente);
    // 10 — retorna até 10 mensagens;
    // consumerName — filtra mensagens pendentes atribuídas ao consumidor específico.
    public function getConsumerPendingMessages(
        string $streamName,
        string $consumerGroup,
        string $consumerName,
        ?int $count = 10
    ): PromiseInterface {
        return $this->redisClient->xPending($streamName, $consumerGroup, '-', '+', $count, $consumerName);
    }

    // Changes, or acquires, ownership of a message in a consumer group, as if the message was delivered a consumer group member.
    // Idle time => 60 segundos de idle time. é o tempo que essa mensagem está pendente e sem interação — ou seja, desde a última vez que foi entregue sem ter sido reconhecida
    public function claimPendingMessage(
        string $streamName,
        string $consumerGroup,
        string $consumerName,
        array $messageIds,
        ?int $idleTimeInMs = 60000,
    ): PromiseInterface {
        return $this->redisClient->xClaim(
            $streamName,
            $consumerGroup,
            $consumerName,
            $idleTimeInMs,
            ...$messageIds
        );
    }
}
