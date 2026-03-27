<?php

declare(strict_types=1);

use TranslationSdk\Contracts\SdkTranslatorInterface;

if (! function_exists('tc')) {
    /**
     * `tc()` 和 `__()` 参数保持一致，只额外增加一个 `$module`。
     *
     * 这样业务代码不需要学习第二套完全不同的调用方式。
     *
     * @param  array<string, scalar|null>  $replace
     */
    function tc(
        string $key,
        array $replace = [],
        ?string $locale = null,
        ?string $module = null
    ): string|array {
        /** @var SdkTranslatorInterface $translator */
        $translator = app(SdkTranslatorInterface::class);

        return $translator->translate($key, $replace, $locale, $module);
    }
}
