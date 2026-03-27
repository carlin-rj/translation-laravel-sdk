<?php

declare(strict_types=1);

namespace TranslationSdk\Contracts;

use Countable;
use Illuminate\Contracts\Translation\Translator as LaravelTranslatorContract;

/**
 * SDK translator 接口。
 *
 * 这个接口的目标不是替代 Laravel Translator，而是在 Laravel Translator 的基础上
 * 增加一个显式的 module 维度，让 `__()` 和 `tc()` 可以共享同一套运行时实现。
 */
interface SdkTranslatorInterface extends LaravelTranslatorContract
{
    /**
     * `tc()` 对外暴露的简单入口。
     *
     * @param  array<string, scalar|null>  $replace
     */
    public function translate(
        string $key,
        array $replace = [],
        ?string $locale = null,
        ?string $module = null
    ): string|array;

    /**
     * 和 Laravel `get()` 一样，只是额外允许显式传入 module。
     *
     * @param  array<string, scalar|null>  $replace
     */
    public function getWithModule(
        string $key,
        array $replace = [],
        ?string $locale = null,
        ?string $module = null,
        bool $fallback = true
    ): string|array;

    /**
     * 和 Laravel `choice()` 一样，只是额外允许显式传入 module。
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
    ): string;
}
