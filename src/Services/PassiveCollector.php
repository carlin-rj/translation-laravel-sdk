<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use TranslationSdk\Contracts\MissingBufferInterface;
use TranslationSdk\Dto\CollectItemDto;

/**
 * 运行时 miss 之后的被动收集入口。
 *
 * 这个类只做两件事:
 * 1. 用 cooldown 避免同一个 key 被高频重复塞进 buffer
 * 2. 把缺失项写进 buffer，等待阈值 flush 或定时任务推送
 */
class PassiveCollector
{
    private const REPORT_CACHE_KEY_PREFIX = 'translation_sdk:passive:reported';

    private MissingBufferInterface $buffer;

    private ModuleResolver $moduleResolver;

    private SourceTextResolver $sourceTextResolver;

    private CacheRepository $reportCache;

    private int $reportCooldownSeconds;

    public function __construct(
        MissingBufferInterface $buffer,
        ModuleResolver $moduleResolver,
        SourceTextResolver $sourceTextResolver,
        CacheFactory $cacheFactory
    )
    {
        $this->buffer = $buffer;
        $this->moduleResolver = $moduleResolver;
        $this->sourceTextResolver = $sourceTextResolver;
        $this->reportCache = $cacheFactory->store();
        $this->reportCooldownSeconds = max(0, (int) config('translation_sdk.collect.passive.report_cooldown_seconds', 600));
    }

    /**
     * 记录一个运行时 miss。
     *
     * source_text 统一交给 SourceTextResolver 处理，
     * 保证运行时被动收集和全局扫描使用同一套规则。
     */
    public function captureMissing(string $key, ?string $module = null): int
    {
        $keyName = trim($key);
        if ($keyName === '') {
            return $this->buffer->size();
        }

        $resolvedModule = $this->moduleResolver->resolve($module);
        // 冷却窗口内同一条缺失翻译只上报一次，避免未翻译期间高频重复上报。
        if ($this->shouldSkipByCooldown($resolvedModule, $keyName)) {
            return $this->buffer->size();
        }

        $size = $this->buffer->push(CollectItemDto::from([
            'module' => $resolvedModule,
            'key_name' => $keyName,
            'source_text' => $this->sourceTextResolver->resolve($keyName),
        ]));
        $this->markReported($resolvedModule, $keyName);

        return $size;
    }

    /**
     * 请求结束时是否应该触发一次自动 flush。
     */
    public function shouldFlush(): bool
    {
        $threshold = max(1, (int) config('translation_sdk.collect.passive.flush_threshold', 100));

        return $this->buffer->size() >= $threshold;
    }

    /**
     * cooldown 键只按 `module + key` 去重，不再混入隐式推断值。
     */
    private function shouldSkipByCooldown(string $module, string $keyName): bool
    {
        if ($this->reportCooldownSeconds <= 0) {
            return false;
        }

        return (bool) $this->reportCache->get($this->buildReportCacheKey($module, $keyName), false);
    }

    /**
     * 标记“这个缺失项刚刚已经上报过”。
     */
    private function markReported(string $module, string $keyName): void
    {
        if ($this->reportCooldownSeconds <= 0) {
            return;
        }

        // 记录最近一次上报时间，作为下一次采集的冷却判断依据。
        $this->reportCache->put(
            $this->buildReportCacheKey($module, $keyName),
            true,
            $this->reportCooldownSeconds
        );
    }

    /**
     * 用短 hash 缩短 cache key，避免 key 太长不易管理。
     */
    private function buildReportCacheKey(string $module, string $keyName): string
    {
        return sprintf('%s:%s', self::REPORT_CACHE_KEY_PREFIX, sha1($module . '|' . $keyName));
    }
}
