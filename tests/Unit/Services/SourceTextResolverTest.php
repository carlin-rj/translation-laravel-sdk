<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use Illuminate\Translation\ArrayLoader;
use TranslationSdk\Services\SourceTextResolver;
use TranslationSdk\Tests\TestCase;

class SourceTextResolverTest extends TestCase
{
    public function test_it_uses_default_locale_local_line_for_key_like_value(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('待支付', $resolver->resolve('order.status.pending'));
    }

    public function test_it_returns_original_text_for_plain_text_value(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('你好 :name', $resolver->resolve('你好 :name'));
    }

    public function test_it_returns_original_key_when_local_line_missing(): void
    {
        $resolver = $this->makeResolver();

        $this->assertSame('order.status.unknown', $resolver->resolve('order.status.unknown'));
    }

    private function makeResolver(): SourceTextResolver
    {
        $loader = new ArrayLoader();
        $loader->addMessages('zh-CN', 'order', [
            'status' => [
                'pending' => '待支付',
            ],
        ]);

        return new SourceTextResolver($loader);
    }
}
