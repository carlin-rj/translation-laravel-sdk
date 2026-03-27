<?php

declare(strict_types=1);

namespace TranslationSdk\Contracts;

use Illuminate\Contracts\Translation\Translator as LaravelTranslatorContract;

/**
 * SDK translator 接口。
 *
 * 这个接口不替代 Laravel Translator，只补一个显式 translate 入口，
 * 方便通过容器注入时统一调用。
 */
interface SdkTranslatorInterface extends LaravelTranslatorContract
{
    /**
     * @param  array<string, scalar|null>  $replace
     */
    public function translate(
        string $key,
        array $replace = [],
        ?string $locale = null
    ): string|array;
}
