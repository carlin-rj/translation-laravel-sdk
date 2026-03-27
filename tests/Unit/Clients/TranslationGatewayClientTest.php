<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Clients;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use RuntimeException;
use TranslationSdk\Clients\TranslationGatewayClient;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\FetchPackageIncrementalRequestDto;
use TranslationSdk\Tests\TestCase;

class TranslationGatewayClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('translation_sdk.gateway.base_url', 'http://gateway.test');
        config()->set('translation_sdk.gateway.system_token', 'token-123');
        config()->set('translation_sdk.gateway.timeout', 2);
    }

    public function test_collect_posts_batch_with_auth_header(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'http://gateway.test/interact/translation/collect' => HttpFactory::response([
                'state' => '000001',
                'data' => [],
            ], 200),
        ]);

        $client = new TranslationGatewayClient($http);
        $client->collect(CollectBatchDto::from([
            'items' => [
                [
                    'key_name' => 'order.status.pending',
                    'source_text' => 'pending',
                ],
            ],
        ]));

        $http->assertSent(static function (Request $request): bool {
            $body = $request->data();

            return $request->url() === 'http://gateway.test/interact/translation/collect'
                && $request->hasHeader('X-System-Token', 'token-123')
                && ($body['items'][0]['key_name'] ?? '') === 'order.status.pending'
                && ($body['items'][0]['source_text'] ?? '') === 'pending';
        });
    }

    public function test_collect_skips_http_when_items_empty(): void
    {
        $http = new HttpFactory();
        $http->fake();
        $client = new TranslationGatewayClient($http);

        $client->collect(CollectBatchDto::from(['items' => []]));

        $http->assertNothingSent();
    }

    public function test_fetch_package_incremental_returns_dto(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'http://gateway.test/interact/translation/package/incremental' => HttpFactory::response([
                'state' => '000001',
                'data' => [
                    'cursor' => 0,
                    'next_cursor' => 1,
                    'has_more' => false,
                    'items' => [
                        [
                            'id' => 1,
                            'translation_key_id' => 10,
                            'locale' => 'en',
                            'key_name' => 'order.status.pending',
                            'translation_text' => 'Pending',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new TranslationGatewayClient($http);
        $result = $client->fetchPackageIncremental(FetchPackageIncrementalRequestDto::from([
            'locale' => 'en',
            'cursor' => 0,
            'limit' => 200,
        ]));

        $this->assertSame(1, $result->next_cursor);
        $this->assertFalse($result->has_more);
        $this->assertCount(1, $result->items);
        $this->assertSame('Pending', $result->items[0]->translation_text);
    }

    public function test_fetch_sync_targets_returns_dto(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'http://gateway.test/interact/translation/sync-targets' => HttpFactory::response([
                'state' => '000001',
                'data' => [
                    'system_code' => 'purchase',
                    'source_locale' => 'zh-CN',
                    'target_locales' => ['en-US', 'ja-JP'],
                ],
            ], 200),
        ]);

        $client = new TranslationGatewayClient($http);
        $result = $client->fetchSyncTargets();

        $this->assertSame('purchase', $result->system_code);
        $this->assertSame(['en-US', 'ja-JP'], $result->target_locales);
    }

    public function test_collect_throw_when_http_failed(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'http://gateway.test/interact/translation/collect' => HttpFactory::response([], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('translation gateway 请求失败: 500');

        $client = new TranslationGatewayClient($http);
        $client->collect(CollectBatchDto::from([
            'items' => [
                ['key_name' => 'k', 'source_text' => 'k'],
            ],
        ]));
    }

    public function test_collect_throw_when_state_invalid(): void
    {
        $http = new HttpFactory();
        $http->fake([
            'http://gateway.test/interact/translation/collect' => HttpFactory::response([
                'state' => '999999',
                'msg' => 'failed',
                'data' => [],
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed');

        $client = new TranslationGatewayClient($http);
        $client->collect(CollectBatchDto::from([
            'items' => [
                ['key_name' => 'k', 'source_text' => 'k'],
            ],
        ]));
    }

    public function test_collect_throw_when_base_url_missing(): void
    {
        config()->set('translation_sdk.gateway.base_url', '');
        $http = new HttpFactory();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('translation_sdk.gateway.base_url 不能为空');

        $client = new TranslationGatewayClient($http);
        $client->collect(CollectBatchDto::from([
            'items' => [
                ['key_name' => 'k', 'source_text' => 'k'],
            ],
        ]));
    }
}
