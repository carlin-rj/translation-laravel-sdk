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

    public function test_tc_helper_calls_bound_translator(): void
    {
        $fake = new class implements SdkTranslatorInterface {
            public function get($key, array $replace = [], $locale = null)
            {
                return 'helper-translated';
            }

            public function choice($key, $number, array $replace = [], $locale = null): string
            {
                return 'choice';
            }

            public function getLocale(): string
            {
                return 'zh-CN';
            }

            public function setLocale($locale): void
            {
            }

            public function translate(
                string $key,
                array $replace = [],
                ?string $locale = null,
                ?string $module = null
            ): string|array {
                return 'helper-translated';
            }

            public function getWithModule(
                string $key,
                array $replace = [],
                ?string $locale = null,
                ?string $module = null,
                bool $fallback = true
            ): string|array {
                return 'helper-translated';
            }

            public function choiceWithModule(
                string $key,
                \Countable|int|float|array $number,
                array $replace = [],
                ?string $locale = null,
                ?string $module = null
            ): string {
                return 'choice';
            }
        };

        app()->instance(SdkTranslatorInterface::class, $fake);

        $text = tc('order.status.pending', module: 'order');

        $this->assertSame('helper-translated', $text);
    }

    public function test_laravel_double_underscore_uses_sdk_cache_when_local_lang_misses(): void
    {
        config()->set('translation_sdk.default_module', 'order');
        app()->setLocale('en');

        $cacheRepository = app(\TranslationSdk\Services\TranslationCacheRepository::class);
        $cacheRepository->mergeTranslations('en', 'order', [
            'order.status.pending' => 'Pending From Cache',
        ]);

        $this->assertSame('Pending From Cache', __('order.status.pending'));
    }
}
