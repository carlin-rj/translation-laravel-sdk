<?php

declare(strict_types=1);

namespace TranslationSdk;

use Illuminate\Translation\Translator as LaravelTranslator;
use Illuminate\Support\ServiceProvider;
use Throwable;
use TranslationSdk\Buffers\RedisMissingBuffer;
use TranslationSdk\Clients\TranslationGatewayClient;
use TranslationSdk\Console\CollectActiveCommand;
use TranslationSdk\Console\FlushMissingCommand;
use TranslationSdk\Console\SyncPackageCommand;
use TranslationSdk\Contracts\MissingBufferInterface;
use TranslationSdk\Contracts\SdkTranslatorInterface;
use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Services\ActiveCollector;
use TranslationSdk\Services\MissingFlushService;
use TranslationSdk\Services\PackageSyncService;
use TranslationSdk\Services\PackageSyncCursorRepository;
use TranslationSdk\Services\PassiveCollector;
use TranslationSdk\Services\SdkTranslator;
use TranslationSdk\Services\SourceTextResolver;
use TranslationSdk\Services\TranslationCacheRepository;
use TranslationSdk\Support\FileKeyScanner;

/**
 * SDK 的服务注册入口。
 *
 * 这个 provider 只做三件事:
 * 1. 注册 SDK 需要的服务
 * 2. 把 Laravel 原生 translator 包装成 SDK translator
 * 3. 在请求结束时尝试自动 flush 被动收集数据
 */
class TranslationSdkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/translation_sdk.php', 'translation_sdk');

        $this->app->singleton(TranslationGatewayClientInterface::class, TranslationGatewayClient::class);
        $this->app->singleton(MissingBufferInterface::class, RedisMissingBuffer::class);
        $this->app->singleton(SourceTextResolver::class, function ($app): SourceTextResolver {
            return new SourceTextResolver($app->make('translation.loader'));
        });
        $this->app->singleton(PassiveCollector::class);
        $this->app->singleton(MissingFlushService::class);
        $this->app->singleton(FileKeyScanner::class);
        $this->app->singleton(ActiveCollector::class);
        $this->app->singleton(TranslationCacheRepository::class);
        $this->app->singleton(PackageSyncService::class);
        $this->app->singleton(PackageSyncCursorRepository::class);
        $this->app->extend('translator', function ($translator, $app) {
            return $this->wrapLaravelTranslator($translator);
        });
        $this->app->alias('translator', SdkTranslatorInterface::class);
        $this->app->alias('translator', 'translation_sdk.translator');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/translation_sdk.php' => config_path('translation_sdk.php'),
        ], 'translation-sdk-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CollectActiveCommand::class,
                FlushMissingCommand::class,
                SyncPackageCommand::class,
            ]);
        }

        $this->registerTerminateFlushHook();
    }

    private function registerTerminateFlushHook(): void
    {
        if (! (bool) config('translation_sdk.collect.passive.enabled', true)) {
            return;
        }

        $collector = $this->app->make(PassiveCollector::class);
        $flushService = $this->app->make(MissingFlushService::class);

        // 普通 FPM / CLI 请求结束时兜底 flush 一次。
        $this->app->terminating(function () use ($collector, $flushService): void {
            $this->flushMissingOnRequestEnd($collector, $flushService);
        });

        $this->registerOctaneRequestTerminatedHook($collector, $flushService);
    }

    private function registerOctaneRequestTerminatedHook(
        PassiveCollector $collector,
        MissingFlushService $flushService
    ): void {
        $eventClass = 'Laravel\\Octane\\Events\\RequestTerminated';
        if (! class_exists($eventClass)) {
            return;
        }

        // Octane worker 常驻，所以要监听“每个请求结束”事件。
        $this->app['events']->listen($eventClass, function () use ($collector, $flushService): void {
            $this->flushMissingOnRequestEnd($collector, $flushService);
        });
    }

    /**
     * 请求结束时尝试 flush，但任何异常都不能反向影响业务请求。
     */
    private function flushMissingOnRequestEnd(
        PassiveCollector $collector,
        MissingFlushService $flushService
    ): void {
        try {
            if (! $collector->shouldFlush()) {
                return;
            }

            $batchSize = (int) config('translation_sdk.collect.passive.flush_batch_size', 200);
            $flushService->flush($batchSize);
        } catch (Throwable) {
            // no-op
        }
    }

    /**
     * 复用 Laravel 已经准备好的 loader / locale / selector，
     * 只在 miss 流程上增加 SDK 的远程缓存与被动收集能力。
     */
    private function wrapLaravelTranslator(mixed $translator): mixed
    {
        if ($translator instanceof SdkTranslator) {
            return $translator;
        }

        if (! $translator instanceof LaravelTranslator) {
            return $translator;
        }

        $sdkTranslator = new SdkTranslator(
            $translator->getLoader(),
            $translator->getLocale(),
            $this->app->make(TranslationCacheRepository::class),
            $this->app->make(PassiveCollector::class)
        );
        $sdkTranslator->setFallback($translator->getFallback());
        $sdkTranslator->setSelector($translator->getSelector());

        return $sdkTranslator;
    }
}
