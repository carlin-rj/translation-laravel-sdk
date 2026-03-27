<?php

declare(strict_types=1);

namespace TranslationSdk\Console;

use Illuminate\Console\Command;
use TranslationSdk\Services\ActiveCollector;
use TranslationSdk\Services\ModuleResolver;

/**
 * 全局扫描命令。
 */
class CollectActiveCommand extends Command
{
    protected $signature = 'translation-sdk:collect-active
        {--module= : 默认模块，未显式传 module 的 tc/__ 扫描结果将使用它}
        {--path=* : 扫描路径，默认扫描配置 translation_sdk.collect.scan_paths}
        {--batch= : 批量推送条数}';

    protected $description = '全局扫描项目中的 __/trans/@lang/tc 调用，并批量采集到翻译中台';

    public function handle(ActiveCollector $collector, ModuleResolver $moduleResolver): int
    {
        // 没有显式传 path 时，回落到配置中的全局扫描路径。
        $paths = (array) $this->option('path');
        if ($paths === []) {
            $paths = (array) config('translation_sdk.collect.scan_paths', []);
        }

        // 扫描阶段没有显式 module 的 key，统一走默认模块。
        $defaultModule = $moduleResolver->resolve($this->option('module'));
        $batchSize = (int) ($this->option('batch') ?: config('translation_sdk.collect.batch_size', 200));
        $result = $collector->collect($paths, $defaultModule, $batchSize);

        $this->info(sprintf(
            'scanned_files=%d, discovered=%d, pushed_batches=%d, pushed_items=%d',
            $result->scanned_files,
            $result->discovered_items,
            $result->pushed_batches,
            $result->pushed_items
        ));

        return self::SUCCESS;
    }
}
