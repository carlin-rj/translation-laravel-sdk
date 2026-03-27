<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use Illuminate\Contracts\Translation\Translator as LaravelTranslatorContract;
use TranslationSdk\Contracts\SdkTranslatorInterface;
use TranslationSdk\Services\SdkTranslator;
use TranslationSdk\Tests\TestCase;

class HelperAndProviderTest extends TestCase
{
    public function test_provider_registers_sdk_translator_binding(): void
    {
        $translator = app(SdkTranslatorInterface::class);

        $this->assertInstanceOf(SdkTranslator::class, $translator);
        $this->assertSame($translator, app('translator'));
        $this->assertInstanceOf(LaravelTranslatorContract::class, $translator);
    }

    public function test_laravel_double_underscore_uses_sdk_cache_when_local_lang_misses(): void
    {
        app()->setLocale('en');

        $cacheRepository = app(\TranslationSdk\Services\TranslationCacheRepository::class);
        $cacheRepository->mergeTranslations('en', [
            'order.status.pending' => 'Pending From Cache',
        ]);

        $this->assertSame('Pending From Cache', __('order.status.pending'));
    }
}
