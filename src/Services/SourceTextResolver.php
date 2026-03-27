<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Translation\Translator as LaravelTranslator;

/**
 * 统一解析收集 payload 里的 source_text。
 *
 * 规则保持非常简单：
 * - 普通文本直接返回原值
 * - 类似 `order.pay`、`package::group.key` 的 key，优先读取默认语言里的本地文案
 * - 本地拿不到时，再回退为原始 key
 *
 * 这里刻意使用原生 Laravel Translator，而不是 SDK translator，
 * 避免 source_text 解析时再次进入“远程缓存 / 被动收集”链路。
 */
class SourceTextResolver
{
    private Loader $loader;

    /**
     * 单次请求内缓存解析结果，避免同一个 key 反复读 lang 文件。
     *
     * @var array<string, string>
     */
    private array $resolved = [];

    public function __construct(Loader $loader)
    {
        $this->loader = $loader;
    }

    public function resolve(string $key): string
    {
        $keyName = trim($key);
        if ($keyName === '') {
            return '';
        }

        if (! $this->looksLikeTranslationKey($keyName)) {
            return $keyName;
        }

        $sourceLocale = $this->resolveSourceLocale();
        if ($sourceLocale === '') {
            return $keyName;
        }

        $cacheKey = $sourceLocale . '|' . $keyName;
        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        $translator = new LaravelTranslator($this->loader, $sourceLocale);
        $translator->setFallback($sourceLocale);

        $line = $translator->get($keyName, [], $sourceLocale, false);
        if (is_string($line) && $line !== '' && $line !== $keyName) {
            return $this->resolved[$cacheKey] = $line;
        }

        return $this->resolved[$cacheKey] = $keyName;
    }

    /**
     * 这里优先使用 fallback locale，让 source_text 尽量稳定指向“基础语言”。
     */
    private function resolveSourceLocale(): string
    {
        return trim((string) (
            config('app.fallback_locale')
            ?: config('app.locale')
            ?: ''
        ));
    }

    /**
     * 只把典型 translation key 当成“需要回查 lang”的候选值。
     *
     * 这样像 `你好`、`订单创建 :name` 这种直接文本不会误判成 key。
     */
    private function looksLikeTranslationKey(string $key): bool
    {
        if (str_contains($key, ' ')) {
            return false;
        }

        if (str_contains($key, '::')) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+$/', $key) === 1;
    }
}
