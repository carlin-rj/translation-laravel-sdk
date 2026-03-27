<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Dto\FetchPackageIncrementalResultDto;
use TranslationSdk\Dto\SyncTargetsDto;
use TranslationSdk\Services\PackageSyncService;
use TranslationSdk\Services\TranslationCacheRepository;
use TranslationSdk\Tests\Fakes\StubGatewayClient;
use TranslationSdk\Tests\TestCase;

class PackageSyncServiceTest extends TestCase
{
    public function test_sync_fetches_multiple_pages_and_merge_cache(): void
    {
        config()->set('translation_sdk.sync.batch_size', 2);
        config()->set('translation_sdk.sync.max_pages', 10);

        $gateway = new StubGatewayClient([
            FetchPackageIncrementalResultDto::from([
                'cursor' => 0,
                'next_cursor' => 2,
                'has_more' => true,
                'items' => [
                    [
                        'id' => 1,
                        'translation_key_id' => 101,
                        'locale' => 'en',
                        'module' => 'order',
                        'key_name' => 'order.status.pending',
                        'translation_text' => 'Pending',
                    ],
                    [
                        'id' => 2,
                        'translation_key_id' => 102,
                        'locale' => 'en',
                        'module' => 'order',
                        'key_name' => '',
                        'translation_text' => 'Ignored',
                    ],
                ],
            ]),
            FetchPackageIncrementalResultDto::from([
                'cursor' => 2,
                'next_cursor' => 3,
                'has_more' => false,
                'items' => [
                    [
                        'id' => 3,
                        'translation_key_id' => 103,
                        'locale' => 'en',
                        'module' => 'order',
                        'key_name' => 'order.status.paid',
                        'translation_text' => 'Paid',
                    ],
                ],
            ]),
        ]);

        $cacheRepository = new TranslationCacheRepository(app('cache'));
        $service = new PackageSyncService($gateway, $cacheRepository);
        $result = $service->sync('en', 'order', 0, 2);

        $this->assertSame('en', $result->locale);
        $this->assertSame('order', $result->module);
        $this->assertSame(0, $result->start_cursor);
        $this->assertSame(3, $result->next_cursor);
        $this->assertSame(2, $result->pages);
        $this->assertSame(2, $result->synced_items);
        $this->assertSame('Pending', $cacheRepository->get('en', 'order', 'order.status.pending'));
        $this->assertSame('Paid', $cacheRepository->get('en', 'order', 'order.status.paid'));
    }

    public function test_sync_stops_by_max_pages(): void
    {
        config()->set('translation_sdk.sync.max_pages', 1);

        $gateway = new StubGatewayClient([
            FetchPackageIncrementalResultDto::from([
                'cursor' => 0,
                'next_cursor' => 10,
                'has_more' => true,
                'items' => [],
            ]),
            FetchPackageIncrementalResultDto::from([
                'cursor' => 10,
                'next_cursor' => 20,
                'has_more' => false,
                'items' => [],
            ]),
        ]);

        $service = new PackageSyncService($gateway, new TranslationCacheRepository(app('cache')));
        $result = $service->sync('en', 'order', 0, 2);

        $this->assertSame(1, $result->pages);
        $this->assertSame(10, $result->next_cursor);
    }

    public function test_fetch_sync_targets_delegates_to_gateway_client(): void
    {
        $gateway = $this->createMock(TranslationGatewayClientInterface::class);
        $gateway->expects($this->once())
            ->method('fetchSyncTargets')
            ->willReturn(SyncTargetsDto::from([
                'system_code' => 'purchase',
                'source_locale' => 'zh-CN',
                'target_locales' => ['en-US'],
                'modules' => ['order'],
            ]));

        $service = new PackageSyncService($gateway, new TranslationCacheRepository(app('cache')));
        $targets = $service->fetchSyncTargets();

        $this->assertSame('purchase', $targets->system_code);
        $this->assertSame(['en-US'], $targets->target_locales);
        $this->assertSame(['order'], $targets->modules);
    }

    public function test_sync_without_module_writes_cache_by_item_module(): void
    {
        config()->set('translation_sdk.sync.batch_size', 20);
        config()->set('translation_sdk.sync.max_pages', 10);

        $gateway = new StubGatewayClient([
            FetchPackageIncrementalResultDto::from([
                'cursor' => 0,
                'next_cursor' => 2,
                'has_more' => false,
                'items' => [
                    [
                        'id' => 1,
                        'translation_key_id' => 101,
                        'locale' => 'en',
                        'module' => 'order',
                        'key_name' => 'order.status.pending',
                        'translation_text' => 'Pending',
                    ],
                    [
                        'id' => 2,
                        'translation_key_id' => 102,
                        'locale' => 'en',
                        'module' => 'payment',
                        'key_name' => 'payment.status.paid',
                        'translation_text' => 'Paid',
                    ],
                ],
            ]),
        ]);

        $cacheRepository = new TranslationCacheRepository(app('cache'));
        $service = new PackageSyncService($gateway, $cacheRepository);
        $result = $service->sync('en', null, 0, 50);

        $this->assertSame('*', $result->module);
        $this->assertSame('Pending', $cacheRepository->get('en', 'order', 'order.status.pending'));
        $this->assertSame('Paid', $cacheRepository->get('en', 'payment', 'payment.status.paid'));
    }
}
