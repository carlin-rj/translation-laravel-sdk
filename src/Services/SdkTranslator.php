<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

use Countable;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Translation\Translator as LaravelTranslator;
use Throwable;
use TranslationSdk\Contracts\SdkTranslatorInterface;

/**
 * SDK 的核心翻译器。
 *
 * 它继承 Laravel 原生 Translator，所以:
 * - `__()`
 * - `trans()`
 * - `trans_choice()`
 * - Validator 消息
 *
 * 都会落到这里。
 *
 * SDK 只在 Laravel 原生流程上增加两层能力:
 * 1. 本地 lang miss 后查远程缓存
 * 2. 还 miss 时写入被动收集队列
 */
class SdkTranslator extends LaravelTranslator implements SdkTranslatorInterface
{
    private TranslationCacheRepository $cacheRepository;

    private PassiveCollector $passiveCollector;

    private ModuleResolver $moduleResolver;

    public function __construct(
        Loader $loader,
        string $locale,
        TranslationCacheRepository $cacheRepository,
        PassiveCollector $passiveCollector,
        ModuleResolver $moduleResolver
    ) {
        parent::__construct($loader, $locale);

        $this->cacheRepository = $cacheRepository;
        $this->passiveCollector = $passiveCollector;
        $this->moduleResolver = $moduleResolver;
    }

    /**
     * 接管 Laravel 原生 `get()`。
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if ($key === null) {
            return null;
        }

        return $this->getWithModule((string) $key, $replace, $this->normalizeLocale($locale), null, (bool) $fallback);
    }

    /**
     * `has()` 既要看本地 lang，也要看远程缓存。
     */
    public function has($key, $locale = null, $fallback = true)
    {
        $keyName = trim((string) $key);
        if ($keyName === '') {
            return false;
        }

        if ($this->getLocalTranslation($keyName, [], $this->normalizeLocale($locale), (bool) $fallback) !== null) {
            return true;
        }

        return $this->hasCachedTranslation($keyName, $this->normalizeLocale($locale), (bool) $fallback, null);
    }

    /**
     * 和 Laravel 行为保持一致: 只检查指定 locale，不走 fallback。
     */
    public function hasForLocale($key, $locale = null)
    {
        return $this->has($key, $locale, false);
    }

    /**
     * 接管 Laravel 原生复数翻译。
     */
    public function choice($key, $number, array $replace = [], $locale = null)
    {
        return $this->choiceWithModule(
            (string) $key,
            $number,
            $replace,
            $this->normalizeLocale($locale),
            null
        );
    }

    /**
     * `tc()` 对外暴露的简单入口。
     */
    public function translate(
        string $key,
        array $replace = [],
        ?string $locale = null,
        ?string $module = null
    ): string|array {
        return $this->getWithModule($key, $replace, $locale, $module);
    }

    public function getWithModule(
        string $key,
        array $replace = [],
        ?string $locale = null,
        ?string $module = null,
        bool $fallback = true
    ): string|array {
        $keyName = trim($key);
        if ($keyName === '') {
            return '';
        }

        // 第一层: Laravel 本地 lang。
        $localLine = $this->getLocalTranslation($keyName, $replace, $locale, $fallback);
        if ($localLine !== null) {
            return $localLine;
        }

        // 第二层: 远程翻译系统同步到本地的缓存。
        $resolvedModule = $this->moduleResolver->resolve($module);
        foreach ($this->resolveCandidateLocales($locale, $fallback) as $candidateLocale) {
            $cachedText = $this->cacheRepository->get($candidateLocale, $resolvedModule, $keyName);
            if (is_string($cachedText)) {
                return $this->makeReplacements($cachedText, $replace);
            }
        }

        // 第三层: 记录 miss，但最终仍然按 Laravel 风格返回原始 key。
        $this->captureMissing($keyName, $resolvedModule);

        $missingKey = $this->handleMissingTranslationKey(
            $keyName,
            $replace,
            $this->normalizeLocale($locale),
            $fallback
        );

        return $this->makeReplacements($missingKey, $replace);
    }

    /**
     * 复数翻译也走同样的“本地 -> 远程缓存 -> 被动收集”链路。
     *
     * @param  array<string, scalar|null>  $replace
     * @param  Countable|int|float|array<int, mixed>  $number
     */
    public function choiceWithModule(
        string $key,
        Countable|int|float|array $number,
        array $replace = [],
        ?string $locale = null,
        ?string $module = null
    ): string {
        $resolvedLocale = $this->localeForChoice($locale);
        $line = $this->getWithModule($key, $replace, $resolvedLocale, $module);

        if (is_countable($number)) {
            $number = count($number);
        }

        $replace['count'] = $number;

        return $this->makeReplacements(
            $this->getSelector()->choose((string) $line, $number, $resolvedLocale),
            $replace
        );
    }

    /**
     * 这里完整复用 Laravel 本地翻译读取逻辑。
     *
     * 也就是说，只要 key 能在本地 lang 命中，SDK 就完全不参与额外处理。
     *
     * @param  array<string, scalar|null>  $replace
     */
    private function getLocalTranslation(
        string $key,
        array $replace = [],
        ?string $locale = null,
        bool $fallback = true
    ): string|array|null
    {
        $resolvedLocale = $this->normalizeLocale($locale);

        $this->load('*', '*', $resolvedLocale);

        $line = $this->loaded['*']['*'][$resolvedLocale][$key] ?? null;
        if (isset($line)) {
            return is_string($line) ? $this->makeReplacements($line, $replace) : $line;
        }

        [$namespace, $group, $item] = $this->parseKey($key);
        $locales = $fallback ? $this->localeArray($resolvedLocale) : [$resolvedLocale];

        foreach ($locales as $languageLineLocale) {
            if (! is_null($line = $this->getLine(
                $namespace,
                $group,
                (string) $languageLineLocale,
                $item,
                $replace
            ))) {
                return $line;
            }
        }

        return null;
    }

    /**
     * 远程缓存也跟着 Laravel fallback locale 顺序一起查。
     */
    private function hasCachedTranslation(
        string $key,
        ?string $locale = null,
        bool $fallback = true,
        ?string $module = null
    ): bool {
        $resolvedModule = $this->moduleResolver->resolve($module);

        foreach ($this->resolveCandidateLocales($locale, $fallback) as $candidateLocale) {
            if ($this->cacheRepository->has($candidateLocale, $resolvedModule, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 把当前 locale 和 fallback locale 解析成一个可遍历列表。
     *
     * @return array<int, string>
     */
    private function resolveCandidateLocales(?string $locale, bool $fallback): array
    {
        $resolvedLocale = $this->normalizeLocale($locale);
        $locales = $fallback ? $this->localeArray($resolvedLocale) : [$resolvedLocale];

        $result = [];
        $seen = [];
        foreach ($locales as $candidateLocale) {
            $candidateLocaleText = trim((string) $candidateLocale);
            if ($candidateLocaleText === '' || isset($seen[$candidateLocaleText])) {
                continue;
            }

            $seen[$candidateLocaleText] = true;
            $result[] = $candidateLocaleText;
        }

        return $result === [] ? [$resolvedLocale] : $result;
    }

    /**
     * 统一 locale 解析，优先使用显式传值，其次使用当前 translator locale。
     */
    private function normalizeLocale(?string $locale): string
    {
        $localeText = trim((string) ($locale ?? ''));
        if ($localeText !== '') {
            return $localeText;
        }

        $translatorLocale = trim((string) $this->getLocale());
        if ($translatorLocale !== '') {
            return $translatorLocale;
        }

        $appLocale = trim((string) config('app.locale', 'zh-CN'));

        return $appLocale === '' ? 'zh-CN' : $appLocale;
    }

    /**
     * 运行时 miss 不能影响业务，所以这里只能“尽力而为”。
     */
    private function captureMissing(string $key, string $module): void
    {
        if (! (bool) config('translation_sdk.collect.passive.enabled', true)) {
            return;
        }

        try {
            $this->passiveCollector->captureMissing($key, $module);
        } catch (Throwable) {
            // no-op, never break business response path
        }
    }
}
