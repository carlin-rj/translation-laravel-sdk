<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use Illuminate\Redis\RedisManager;
use TranslationSdk\Buffers\RedisMissingBuffer;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\CollectItemDto;
use TranslationSdk\Tests\TestCase;

class RedisMissingBufferTest extends TestCase
{
    public function test_push_and_drain_with_deduplicate(): void
    {
        $conn = new class {
            /**
             * @var array<string, array<string, string>>
             */
            public array $hash = [];

            /**
             * @var array<string, array<int, string>>
             */
            public array $queue = [];

            /**
             * @param  array<int, mixed>  $parameters
             * @return mixed
             */
            public function command(string $method, array $parameters = [])
            {
                if (strtolower($method) !== 'eval') {
                    return null;
                }

                $script = (string) ($parameters[0] ?? '');
                if (str_contains($script, "redis.call('RPUSH', queueKey, fingerprint)")) {
                    $hashKey = (string) ($parameters[2] ?? '');
                    $queueKey = (string) ($parameters[3] ?? '');
                    $fingerprint = (string) ($parameters[4] ?? '');
                    $payload = (string) ($parameters[5] ?? '');
                    $maxBucketSize = (int) ($parameters[6] ?? 0);

                    if (isset($this->hash[$hashKey][$fingerprint])) {
                        return count($this->hash[$hashKey]);
                    }
                    $currentSize = count($this->hash[$hashKey] ?? []);
                    if ($maxBucketSize > 0 && $currentSize >= $maxBucketSize) {
                        return $currentSize;
                    }

                    $this->hash[$hashKey][$fingerprint] = $payload;
                    $this->queue[$queueKey][] = $fingerprint;

                    return $currentSize + 1;
                }

                if (str_contains($script, "redis.call('LPOP', queueKey)")) {
                    $hashKey = (string) ($parameters[2] ?? '');
                    $queueKey = (string) ($parameters[3] ?? '');
                    $limit = (int) ($parameters[4] ?? 1);

                    $items = [];
                    for ($i = 0; $i < $limit; $i++) {
                        $queue = $this->queue[$queueKey] ?? [];
                        $fingerprint = array_shift($queue);
                        $this->queue[$queueKey] = $queue;
                        if (! is_string($fingerprint) || $fingerprint === '') {
                            break;
                        }

                        $payload = $this->hash[$hashKey][$fingerprint] ?? null;
                        if (is_string($payload) && $payload !== '') {
                            $items[] = $payload;
                            unset($this->hash[$hashKey][$fingerprint]);
                        }
                    }

                    return $items;
                }

                return null;
            }

            public function hlen(string $key): int
            {
                return count($this->hash[$key] ?? []);
            }
        };

        $redis = $this->createStub(RedisManager::class);
        $redis->method('connection')->willReturn($conn);

        config()->set('translation_sdk.collect.passive.redis_connection', 'default');
        config()->set('translation_sdk.collect.passive.redis_bucket_count', 4);
        config()->set('translation_sdk.collect.passive.redis_max_bucket_size', 100);

        $buffer = new RedisMissingBuffer($redis);
        $item = CollectItemDto::from([
            'key_name' => 'order.status.pending',
            'source_text' => 'pending',
        ]);

        $buffer->push($item);
        $buffer->push($item);
        $this->assertSame(1, $buffer->size());

        $batch = $buffer->drain(10);
        $this->assertCount(1, $batch->items);
        $this->assertSame(0, $buffer->size());
    }

    public function test_push_respects_bucket_size_limit(): void
    {
        $conn = new class {
            /**
             * @var array<string, array<string, string>>
             */
            public array $hash = [];

            /**
             * @var array<string, array<int, string>>
             */
            public array $queue = [];

            /**
             * @param  array<int, mixed>  $parameters
             * @return mixed
             */
            public function command(string $method, array $parameters = [])
            {
                $script = (string) ($parameters[0] ?? '');
                if (! str_contains($script, "redis.call('RPUSH', queueKey, fingerprint)")) {
                    return [];
                }

                $hashKey = (string) ($parameters[2] ?? '');
                $queueKey = (string) ($parameters[3] ?? '');
                $fingerprint = (string) ($parameters[4] ?? '');
                $payload = (string) ($parameters[5] ?? '');
                $maxBucketSize = (int) ($parameters[6] ?? 0);

                if (isset($this->hash[$hashKey][$fingerprint])) {
                    return count($this->hash[$hashKey]);
                }
                $currentSize = count($this->hash[$hashKey] ?? []);
                if ($maxBucketSize > 0 && $currentSize >= $maxBucketSize) {
                    return $currentSize;
                }

                $this->hash[$hashKey][$fingerprint] = $payload;
                $this->queue[$queueKey][] = $fingerprint;

                return $currentSize + 1;
            }

            public function hlen(string $key): int
            {
                return count($this->hash[$key] ?? []);
            }
        };

        $redis = $this->createStub(RedisManager::class);
        $redis->method('connection')->willReturn($conn);

        config()->set('translation_sdk.collect.passive.redis_connection', 'default');
        config()->set('translation_sdk.collect.passive.redis_bucket_count', 1);
        config()->set('translation_sdk.collect.passive.redis_max_bucket_size', 1);

        $buffer = new RedisMissingBuffer($redis);
        $buffer->push(CollectItemDto::from([
            'key_name' => 'k1',
            'source_text' => 's1',
        ]));
        $buffer->push(CollectItemDto::from([
            'key_name' => 'k2',
            'source_text' => 's2',
        ]));

        $this->assertSame(1, $buffer->size());
    }

    public function test_restore_pushes_only_collect_item_instances(): void
    {
        $buffer = $this->getMockBuilder(RedisMissingBuffer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['push'])
            ->getMock();
        $buffer->expects($this->once())
            ->method('push')
            ->with($this->callback(static function (CollectItemDto $item): bool {
                return $item->key_name === 'order.status.pending'
                    && $item->source_text === 'pending';
            }))
            ->willReturn(1);

        $batch = CollectBatchDto::from([
            'items' => [
                [
                    'key_name' => 'order.status.pending',
                    'source_text' => 'pending',
                ],
                'invalid-item',
            ],
        ]);

        $buffer->restore($batch);
    }

    public function test_push_returns_current_size_when_payload_encode_fails(): void
    {
        $conn = new class {
            public int $evalCalls = 0;

            public function command(string $method, array $parameters = []): mixed
            {
                if (strtolower($method) === 'eval') {
                    $this->evalCalls++;
                }

                return null;
            }

            public function hlen(string $key): int
            {
                return 3;
            }
        };

        $redis = $this->createStub(RedisManager::class);
        $redis->method('connection')->willReturn($conn);

        config()->set('translation_sdk.collect.passive.redis_connection', 'default');
        config()->set('translation_sdk.collect.passive.redis_bucket_count', 1);
        config()->set('translation_sdk.collect.passive.redis_max_bucket_size', 100);

        $buffer = new RedisMissingBuffer($redis);
        $size = $buffer->push(CollectItemDto::from([
            'key_name' => 'order.status.pending',
            'source_text' => "\xB1\x31",
        ]));

        $this->assertSame(3, $size);
        $this->assertSame(0, $conn->evalCalls);
    }

    public function test_drain_normalizes_limit_and_skips_invalid_payloads(): void
    {
        $conn = new class {
            public string $receivedLimit = '';

            public function command(string $method, array $parameters = []): mixed
            {
                if (strtolower($method) !== 'eval') {
                    return null;
                }

                $script = (string) ($parameters[0] ?? '');
                if (! str_contains($script, "redis.call('LPOP', queueKey)")) {
                    return [];
                }

                $this->receivedLimit = (string) ($parameters[4] ?? '');

                return [
                    123,
                    '',
                    'not-json',
                    json_encode('scalar-json'),
                    json_encode([
                        'key_name' => 'order.status.pending',
                        'source_text' => 'pending',
                    ]),
                ];
            }

            public function hlen(string $key): int
            {
                return 0;
            }
        };

        $redis = $this->createStub(RedisManager::class);
        $redis->method('connection')->willReturn($conn);

        config()->set('translation_sdk.collect.passive.redis_connection', 'default');
        config()->set('translation_sdk.collect.passive.redis_bucket_count', 1);
        config()->set('translation_sdk.collect.passive.redis_max_bucket_size', 100);

        $buffer = new RedisMissingBuffer($redis);
        $batch = $buffer->drain(0);

        $this->assertSame('1', $conn->receivedLimit);
        $this->assertCount(1, $batch->items);
        $this->assertSame('order.status.pending', $batch->items[0]->key_name);
    }

    public function test_drain_returns_empty_when_eval_result_is_not_array(): void
    {
        $conn = new class {
            public function command(string $method, array $parameters = []): mixed
            {
                if (strtolower($method) !== 'eval') {
                    return null;
                }

                $script = (string) ($parameters[0] ?? '');
                if (str_contains($script, "redis.call('LPOP', queueKey)")) {
                    return 'invalid-result';
                }

                return [];
            }

            public function hlen(string $key): int
            {
                return 0;
            }
        };

        $redis = $this->createStub(RedisManager::class);
        $redis->method('connection')->willReturn($conn);

        config()->set('translation_sdk.collect.passive.redis_connection', 'default');
        config()->set('translation_sdk.collect.passive.redis_bucket_count', 1);
        config()->set('translation_sdk.collect.passive.redis_max_bucket_size', 100);

        $buffer = new RedisMissingBuffer($redis);
        $batch = $buffer->drain(5);

        $this->assertCount(0, $batch->items);
    }
}
