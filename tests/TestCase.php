<?php

declare(strict_types=1);

namespace TranslationSdk\Tests;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use TranslationSdk\TranslationSdkServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            TranslationSdkServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.locale', 'zh-CN');
        $app['config']->set('app.fallback_locale', 'zh-CN');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array', ['driver' => 'array']);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('translation_sdk.cache.store', 'array');
        config()->set('translation_sdk.cache.ttl', 600);
        config()->set('translation_sdk.default_module', 'default');
        config()->set('translation_sdk.collect.passive.enabled', false);
        config()->set('translation_sdk.collect.passive.flush_threshold', 2);
        config()->set('translation_sdk.collect.passive.report_cooldown_seconds', 600);
        config()->set('translation_sdk.collect.batch_size', 2);
        config()->set('translation_sdk.sync.batch_size', 2);
        config()->set('translation_sdk.sync.max_pages', 10);
        config()->set('app.locale', 'zh-CN');
        config()->set('app.fallback_locale', 'zh-CN');

        app()->instance('request', Request::create('/', 'GET'));
        cache()->store()->flush();
    }
}
