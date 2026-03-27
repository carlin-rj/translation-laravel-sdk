<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

/**
 * 统一解析 module。
 *
 * 这里刻意保持简单:
 * - 有显式 module 就用显式值
 * - 否则回落到默认配置
 *
 * 不再从 request path 等上下文猜测模块，避免行为隐式且难以排查。
 */
class ModuleResolver
{
    public function resolve(?string $module = null): string
    {
        $moduleText = trim((string) ($module ?? ''));
        if ($moduleText !== '') {
            return $moduleText;
        }

        return (string) config('translation_sdk.default_module', 'default');
    }
}
