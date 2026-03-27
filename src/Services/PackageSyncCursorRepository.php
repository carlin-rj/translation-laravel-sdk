<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * 记录远程翻译包同步游标。
 *
 * 游标按 `locale + module` 独立保存，这样各个目标语言和模块可以分别增量同步。
 */
class PackageSyncCursorRepository
{
    private const CURSOR_KEY_PREFIX = 'translation_sdk:sync:cursor';

    private CacheRepository $cache;

    private string $keyPrefix;

    public function __construct(CacheFactory $cacheFactory)
    {
        $store = config('translation_sdk.sync.state_store');
        $this->cache = $store ? $cacheFactory->store($store) : $cacheFactory->store();
        $this->keyPrefix = self::CURSOR_KEY_PREFIX;
    }

    public function getCursor(string $locale, ?string $module): int
    {
        $cursor = $this->cache->get($this->buildKey($locale, $module), 0);

        return max(0, (int) $cursor);
    }

    /**
     * 同步游标属于长期状态，所以直接永久保存。
     */
    public function saveCursor(string $locale, ?string $module, int $cursor): void
    {
        $this->cache->forever($this->buildKey($locale, $module), max(0, $cursor));
    }

    private function buildKey(string $locale, ?string $module): string
    {
        $moduleText = trim((string) ($module ?? ''));
        $moduleKey = $moduleText === '' ? '__all__' : $moduleText;

        return sprintf('%s:%s:%s', $this->keyPrefix, $locale, $moduleKey);
    }
}
