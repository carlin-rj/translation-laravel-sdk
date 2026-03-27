<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Dto\FetchPackageIncrementalRequestDto;
use TranslationSdk\Dto\PackageSyncResultDto;
use TranslationSdk\Dto\SyncTargetsDto;

/**
 * 负责把远程翻译包增量同步到本地缓存。
 *
 * 运行时翻译不会直接请求远程系统，所以远程缓存的更新完全依赖这个服务。
 */
class PackageSyncService
{
    private TranslationGatewayClientInterface $gatewayClient;

    private TranslationCacheRepository $cacheRepository;

    public function __construct(
        TranslationGatewayClientInterface $gatewayClient,
        TranslationCacheRepository $cacheRepository
    ) {
        $this->gatewayClient = $gatewayClient;
        $this->cacheRepository = $cacheRepository;
    }

    public function sync(string $locale, ?string $module = null, int $cursor = 0, ?int $limit = null): PackageSyncResultDto
    {
        $batchSize = $limit ?? (int) config('translation_sdk.sync.batch_size', 200);
        $safeLimit = max(1, min(1000, $batchSize));
        $maxPages = max(1, (int) config('translation_sdk.sync.max_pages', 1000));
        $resolvedModule = $this->resolveModule($module);

        $currentCursor = max(0, $cursor);
        $pages = 0;
        $syncedItems = 0;

        // 按 cursor 一页页拉，直到远程说“没有更多”或达到本地安全页数上限。
        do {
            $pages++;
            $page = $this->gatewayClient->fetchPackageIncremental(FetchPackageIncrementalRequestDto::from([
                'locale' => $locale,
                'module' => $resolvedModule,
                'cursor' => $currentCursor,
                'limit' => $safeLimit,
            ]));

            $mapByModule = [];
            foreach ($page->items as $item) {
                $keyName = ($item->key_name ?? '');
                $translationText = ($item->translation_text ?? '');
                if ($keyName === '' || $translationText === '') {
                    continue;
                }

                // all-module 同步时，优先使用远程返回的 item module。
                $itemModule = trim(($item->module ?? ''));
                if ($itemModule === '') {
                    $itemModule = $resolvedModule ?? config('translation_sdk.default_module', 'default');
                }

                $mapByModule[$itemModule][$keyName] = $translationText;
                $syncedItems++;
            }

            // 单页内先按模块分桶，再写入缓存，避免不同模块互相污染。
            foreach ($mapByModule as $itemModule => $map) {
                $this->cacheRepository->mergeTranslations($locale, $itemModule, $map);
            }

            $currentCursor = $page->next_cursor;
            if (! $page->has_more) {
                break;
            }
        } while ($pages < $maxPages);

        return PackageSyncResultDto::from([
            'locale' => $locale,
            'module' => $resolvedModule ?? '*',
            'start_cursor' => $cursor,
            'next_cursor' => $currentCursor,
            'pages' => $pages,
            'synced_items' => $syncedItems,
        ]);
    }

    public function fetchSyncTargets(): SyncTargetsDto
    {
        return $this->gatewayClient->fetchSyncTargets();
    }

    /**
     * 远程同步接口允许 module 为空，表示同步全部模块。
     */
    private function resolveModule(?string $module): ?string
    {
        $moduleText = trim($module ?? '');

        return $moduleText === '' ? null : $moduleText;
    }
}
