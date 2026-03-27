<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * 远程翻译包在本地的缓存仓库。
 *
 * 缓存按 `locale + module` 分桶，运行时只需要查当前桶即可。
 */
class TranslationCacheRepository
{
    private const CACHE_KEY_PREFIX = 'translation_sdk:package';

    private CacheRepository $cache;

    private string $keyPrefix;

    private int $ttl;

    public function __construct(CacheFactory $cacheFactory)
    {
        $store = config('translation_sdk.cache.store');
        $this->cache = $store ? $cacheFactory->store($store) : $cacheFactory->store();
        $this->keyPrefix = self::CACHE_KEY_PREFIX;
        $this->ttl = max(60, (int) config('translation_sdk.cache.ttl', 86400 * 30));
    }

    /**
     * 把远程同步回来的一批翻译写进对应的缓存桶。
     *
     * @param  array<string, string>  $translations
     */
    public function mergeTranslations(string $locale, string $module, array $translations): void
    {
        if ($translations === []) {
            return;
        }

        $cacheKey = $this->buildCacheKey($locale, $module);
        $current = $this->readBucket($cacheKey);
        $merged = array_merge($current, $translations);
        $this->cache->put($cacheKey, $merged, $this->ttl);
    }

    /**
     * 读取单个翻译值。
     */
    public function get(string $locale, string $module, string $keyName): ?string
    {
        $payload = $this->readBucket($this->buildCacheKey($locale, $module));
        $value = $payload[$keyName] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * 只判断远程缓存里是否存在这个 key。
     */
    public function has(string $locale, string $module, string $keyName): bool
    {
        $payload = $this->readBucket($this->buildCacheKey($locale, $module));

        return array_key_exists($keyName, $payload) && is_string($payload[$keyName]);
    }

    /**
     * 统一读取缓存桶，避免到处重复判断“值是不是数组”。
     *
     * @return array<string, mixed>
     */
    private function readBucket(string $cacheKey): array
    {
        $payload = $this->cache->get($cacheKey, []);

        return is_array($payload) ? $payload : [];
    }

    private function buildCacheKey(string $locale, string $module): string
    {
        return sprintf('%s:%s:%s', $this->keyPrefix, $locale, $module);
    }
}
