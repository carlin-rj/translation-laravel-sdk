<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use Illuminate\Translation\ArrayLoader;
use RuntimeException;
use TranslationSdk\Services\ModuleResolver;
use TranslationSdk\Services\PassiveCollector;
use TranslationSdk\Services\SdkTranslator;
use TranslationSdk\Services\TranslationCacheRepository;
use TranslationSdk\Tests\TestCase;

class SdkTranslatorTest extends TestCase
{
    public function test_translate_prefers_local_laravel_translation(): void
    {
        $loader = new ArrayLoader();
        $loader->addMessages('en', '*', [
            'order.status.pending' => 'Local :name',
        ]);

        $cache = $this->getMockBuilder(TranslationCacheRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $cache->expects($this->never())->method('get');

        $collector = $this->getMockBuilder(PassiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['captureMissing'])
            ->getMock();
        $collector->expects($this->never())->method('captureMissing');

        $moduleResolver = $this->getMockBuilder(ModuleResolver::class)
            ->onlyMethods(['resolve'])
            ->getMock();
        $moduleResolver->expects($this->never())->method('resolve');

        $translator = new SdkTranslator($loader, 'en', $cache, $collector, $moduleResolver);
        $text = $translator->translate('order.status.pending', ['name' => 'Tom'], 'en', 'order');

        $this->assertSame('Local Tom', $text);
    }

    public function test_translate_returns_cached_text_with_replacements(): void
    {
        $loader = new ArrayLoader();
        $cache = $this->getMockBuilder(TranslationCacheRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $cache->expects($this->once())
            ->method('get')
            ->with('en', 'order', 'order.status.pending')
            ->willReturn('Order :name is :status');

        $collector = $this->getMockBuilder(PassiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['captureMissing'])
            ->getMock();
        $collector->expects($this->never())->method('captureMissing');

        $moduleResolver = $this->getMockBuilder(ModuleResolver::class)
            ->onlyMethods(['resolve'])
            ->getMock();
        $moduleResolver->expects($this->once())
            ->method('resolve')
            ->with('order')
            ->willReturn('order');

        $translator = new SdkTranslator($loader, 'en', $cache, $collector, $moduleResolver);
        $text = $translator->translate(
            'order.status.pending',
            ['name' => 'Tom', 'status' => 'pending'],
            'en',
            'order'
        );

        $this->assertSame('Order Tom is pending', $text);
    }

    public function test_translate_capture_missing_and_return_fallback_text(): void
    {
        config()->set('translation_sdk.collect.passive.enabled', true);

        $loader = new ArrayLoader();
        $cache = $this->getMockBuilder(TranslationCacheRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $cache->expects($this->once())->method('get')->willReturn(null);

        $collector = $this->getMockBuilder(PassiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['captureMissing'])
            ->getMock();
        $collector->expects($this->once())
            ->method('captureMissing')
            ->with('order.status.pending', 'order');

        $moduleResolver = $this->getMockBuilder(ModuleResolver::class)
            ->onlyMethods(['resolve'])
            ->getMock();
        $moduleResolver->method('resolve')->willReturn('order');

        $translator = new SdkTranslator($loader, 'en', $cache, $collector, $moduleResolver);
        $text = $translator->translate('order.status.pending', [], 'en', 'order');

        $this->assertSame('order.status.pending', $text);
    }

    public function test_translate_ignores_collector_error_and_keeps_response(): void
    {
        config()->set('translation_sdk.collect.passive.enabled', true);
        config()->set('app.locale', 'zh-CN');

        $loader = new ArrayLoader();
        $cache = $this->getMockBuilder(TranslationCacheRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $cache->method('get')->willReturn(null);

        $collector = $this->getMockBuilder(PassiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['captureMissing'])
            ->getMock();
        $collector->method('captureMissing')->willThrowException(new RuntimeException('flush error'));

        $moduleResolver = $this->getMockBuilder(ModuleResolver::class)
            ->onlyMethods(['resolve'])
            ->getMock();
        $moduleResolver->method('resolve')->willReturn('order');

        $translator = new SdkTranslator($loader, 'zh-CN', $cache, $collector, $moduleResolver);
        $text = $translator->translate('order.status.created', [], null, null);

        $this->assertSame('order.status.created', $text);
    }

    public function test_choice_with_module_uses_cached_translation(): void
    {
        $loader = new ArrayLoader();
        $cache = $this->getMockBuilder(TranslationCacheRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $cache->expects($this->once())
            ->method('get')
            ->with('en', 'order', 'order.items')
            ->willReturn('{1} One item|[2,*] :count items');

        $collector = $this->getMockBuilder(PassiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['captureMissing'])
            ->getMock();
        $collector->expects($this->never())->method('captureMissing');

        $moduleResolver = $this->getMockBuilder(ModuleResolver::class)
            ->onlyMethods(['resolve'])
            ->getMock();
        $moduleResolver->expects($this->once())
            ->method('resolve')
            ->with('order')
            ->willReturn('order');

        $translator = new SdkTranslator($loader, 'en', $cache, $collector, $moduleResolver);
        $text = $translator->choiceWithModule('order.items', 3, [], 'en', 'order');

        $this->assertSame('3 items', $text);
    }
}
