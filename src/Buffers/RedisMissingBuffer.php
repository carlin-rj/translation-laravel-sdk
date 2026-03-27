<?php

declare(strict_types=1);

namespace TranslationSdk\Buffers;

use Illuminate\Redis\RedisManager;
use TranslationSdk\Contracts\MissingBufferInterface;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\CollectItemDto;

/**
 * Redis 版缺失项 buffer。
 *
 * 设计目标:
 * - 用 hash 保存去重后的 payload
 * - 用 queue 保存消费顺序
 * - 用 bucket 避免单个 key 过大
 */
class RedisMissingBuffer implements MissingBufferInterface
{
    private const BUFFER_KEY_PREFIX = 'translation_sdk:missing:buffer';

    // push 时同时写 hash + queue，保证去重和消费顺序都能兼顾。
    private const PUSH_SCRIPT = <<<'LUA'
local hashKey = KEYS[1]
local queueKey = KEYS[2]
local fingerprint = ARGV[1]
local payload = ARGV[2]
local maxBucketSize = tonumber(ARGV[3]) or 0

if redis.call('HEXISTS', hashKey, fingerprint) == 1 then
    return redis.call('HLEN', hashKey)
end

local currentSize = redis.call('HLEN', hashKey)
if maxBucketSize > 0 and currentSize >= maxBucketSize then
    return currentSize
end

redis.call('HSET', hashKey, fingerprint, payload)
redis.call('RPUSH', queueKey, fingerprint)

return currentSize + 1
LUA;

    // drain 时按 queue 出队，再去 hash 里取完整 payload。
    private const DRAIN_SCRIPT = <<<'LUA'
local hashKey = KEYS[1]
local queueKey = KEYS[2]
local limit = tonumber(ARGV[1]) or 1
local items = {}

for i = 1, limit do
    local fingerprint = redis.call('LPOP', queueKey)
    if not fingerprint then
        break
    end

    local payload = redis.call('HGET', hashKey, fingerprint)
    if payload then
        redis.call('HDEL', hashKey, fingerprint)
        table.insert(items, payload)
    end
end

return items
LUA;

    private RedisManager $redis;

    private string $connection;

    private string $keyPrefix;

    private int $bucketCount;

    private int $maxBucketSize;

    public function __construct(RedisManager $redis)
    {
        $this->redis = $redis;
        $this->connection = (string) config('translation_sdk.collect.passive.redis_connection', 'default');
        $this->keyPrefix = self::BUFFER_KEY_PREFIX;
        $this->bucketCount = max(1, min(256, (int) config('translation_sdk.collect.passive.redis_bucket_count', 16)));
        $this->maxBucketSize = max(0, (int) config('translation_sdk.collect.passive.redis_max_bucket_size', 5000));
    }

    /**
     * push 时先按 fingerprint 去重，再写入对应 bucket。
     */
    public function push(CollectItemDto $item): int
    {
        $conn = $this->redis->connection($this->connection);
        $fingerprint = $this->fingerprint($item);
        $bucket = $this->resolveBucket($fingerprint);
        [$hashKey, $queueKey] = $this->buildBucketKeys($bucket);
        $payload = json_encode([
            'module' => $item->module,
            'key_name' => $item->key_name,
            'source_text' => $item->source_text,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            return $this->size();
        }

        $conn->command('eval', [
            self::PUSH_SCRIPT,
            2,
            $hashKey,
            $queueKey,
            $fingerprint,
            $payload,
            (string) $this->maxBucketSize,
        ]);

        return $this->size();
    }

    /**
     * flush 失败时会把数据恢复回 buffer。
     */
    public function restore(CollectBatchDto $batch): void
    {
        foreach ($batch->items as $item) {
            if (! $item instanceof CollectItemDto) {
                continue;
            }
            $this->push($item);
        }
    }

    /**
     * drain 时会随机遍历 bucket，避免总是偏向同一个 bucket。
     */
    public function drain(int $limit): CollectBatchDto
    {
        $safeLimit = max(1, $limit);
        $conn = $this->redis->connection($this->connection);
        $items = [];
        $remaining = $safeLimit;
        foreach ($this->drainBucketOrder() as $bucket) {
            [$hashKey, $queueKey] = $this->buildBucketKeys($bucket);
            $rawItems = $conn->command('eval', [
                self::DRAIN_SCRIPT,
                2,
                $hashKey,
                $queueKey,
                (string) $remaining,
            ]);
            if (! is_array($rawItems) || $rawItems === []) {
                continue;
            }

            foreach ($rawItems as $raw) {
                if (! is_string($raw) || $raw === '') {
                    continue;
                }

                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    continue;
                }

                $items[] = [
                    'module' => (string) ($decoded['module'] ?? ''),
                    'key_name' => (string) ($decoded['key_name'] ?? ''),
                    'source_text' => (string) ($decoded['source_text'] ?? ''),
                ];
                $remaining--;
                if ($remaining <= 0) {
                    break 2;
                }
            }
        }

        return CollectBatchDto::from(['items' => $items]);
    }

    /**
     * size 会统计所有 bucket 的 hash 长度总和。
     */
    public function size(): int
    {
        $conn = $this->redis->connection($this->connection);
        $size = 0;
        for ($bucket = 0; $bucket < $this->bucketCount; $bucket++) {
            [$hashKey] = $this->buildBucketKeys($bucket);
            $size += (int) $conn->hlen($hashKey);
        }

        return $size;
    }

    /**
     * fingerprint 代表一条缺失项的唯一身份。
     */
    private function fingerprint(CollectItemDto $item): string
    {
        return sha1($item->module . '|' . $item->key_name . '|' . $item->source_text);
    }

    /**
     * 用一致性 hash 把数据稳定打到某个 bucket。
     */
    private function resolveBucket(string $fingerprint): int
    {
        $hash = (int) sprintf('%u', crc32($fingerprint));

        return $hash % $this->bucketCount;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function buildBucketKeys(int $bucket): array
    {
        $suffix = (string) $bucket;
        $hashKey = sprintf('%s:hash:%s', $this->keyPrefix, $suffix);
        $queueKey = sprintf('%s:queue:%s', $this->keyPrefix, $suffix);

        return [$hashKey, $queueKey];
    }

    /**
     * @return array<int, int>
     */
    private function drainBucketOrder(): array
    {
        $buckets = range(0, $this->bucketCount - 1);
        shuffle($buckets);

        return $buckets;
    }
}
