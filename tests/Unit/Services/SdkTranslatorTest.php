<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use Illuminate\Translation\ArrayLoader;
use RuntimeException;
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

        $translator = new SdkTranslator($loader, 'en', $cache, $collector);
        $text = $translator->translate('order.status.pending', ['name' => 'Tom'], 'en');

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
            ->with('en', 'order.status.pending')
            ->willReturn('Order :name is :status');

        $collector = $this->getMockBuilder(PassiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['captureMissing'])
            ->getMock();
        $collector->expects($this->never())->method('captureMissing');

        $translator = new SdkTranslator($loader, 'en', $cache, $collector);
        $text = $translator->translate(
            'order.status.pending',
            ['name' => 'Tom', 'status' => 'pending'],
            'en'
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
        $cache->expects($this->once())
            ->method('get')
            ->with('en', 'order.status.pending')
            ->willReturn(null);

        $collector = $this->getMockBuilder(PassiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['captureMissing'])
            ->getMock();
        $collector->expects($this->once())
            ->method('captureMissing')
            ->with('order.status.pending');

        $translator = new SdkTranslator($loader, 'en', $cache, $collector);
        $text = $translator->translate('order.status.pending', [], 'en');

        $this->assertSame('order.status.pending', $text);
    }

    public function test_translate_ignores_collector_error_and_keeps_response(): void
    {
        config()->set('translation_sdk.collect.passive.enabled', true);
        config()->set('app.locale', 'zh-CN');

        $loader = new ArrayLoader();
        $cache = $this->createStub(TranslationCacheRepository::class);
        $cache->method('get')->willReturn(null);

        $collector = $this->createStub(PassiveCollector::class);
        $collector->method('captureMissing')->willThrowException(new RuntimeException('flush error'));

        $translator = new SdkTranslator($loader, 'zh-CN', $cache, $collector);
        $text = $translator->translate('order.status.created', [], null);

        $this->assertSame('order.status.created', $text);
    }

    public function test_choice_uses_cached_translation(): void
    {
        $loader = new ArrayLoader();
        $cache = $this->getMockBuilder(TranslationCacheRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'has'])
            ->getMock();
        $cache->expects($this->once())
            ->method('has')
            ->with('en', 'order.items')
            ->willReturn(true);
        $cache->expects($this->once())
            ->method('get')
            ->with('en', 'order.items')
            ->willReturn('{1} One item|[2,*] :count items');

        $collector = $this->getMockBuilder(PassiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['captureMissing'])
            ->getMock();
        $collector->expects($this->never())->method('captureMissing');

        $translator = new SdkTranslator($loader, 'en', $cache, $collector);
        $text = $translator->choice('order.items', 3, [], 'en');

        $this->assertSame('3 items', $text);
    }
}
