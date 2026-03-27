<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use TranslationSdk\Services\ModuleResolver;
use TranslationSdk\Tests\TestCase;

class ModuleResolverTest extends TestCase
{
    public function test_resolve_returns_input_module_first(): void
    {
        $resolver = new ModuleResolver();

        $module = $resolver->resolve('order');

        $this->assertSame('order', $module);
    }

    public function test_resolve_uses_config_default_when_module_empty(): void
    {
        config()->set('translation_sdk.default_module', 'payment');

        $resolver = new ModuleResolver();
        $module = $resolver->resolve(null);

        $this->assertSame('payment', $module);
    }

    public function test_resolve_falls_back_to_default_module(): void
    {
        config()->set('translation_sdk.default_module', 'system-default');

        $resolver = new ModuleResolver();
        $module = $resolver->resolve(null);

        $this->assertSame('system-default', $module);
    }
}
