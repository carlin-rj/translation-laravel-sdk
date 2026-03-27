<?php

declare(strict_types=1);

namespace TranslationSdk\Console;

use Illuminate\Console\Command;
use TranslationSdk\Services\MissingFlushService;

/**
 * 手动触发被动收集 flush。
 */
class FlushMissingCommand extends Command
{
    protected $signature = 'translation-sdk:flush-missing {--batch= : 本次 flush 批次大小}';

    protected $description = '将被动采集缓冲中的缺失翻译批量推送到翻译中台';

    public function handle(MissingFlushService $flushService): int
    {
        // 手动 batch 优先，没有传时使用配置默认值。
        $batchSize = (int) ($this->option('batch') ?: config('translation_sdk.collect.passive.flush_batch_size', 200));
        $result = $flushService->flush($batchSize);

        $this->info(sprintf(
            'success=%s, drained=%d, flushed=%d, remaining=%d',
            $result->success ? 'true' : 'false',
            $result->drained,
            $result->flushed,
            $result->remaining
        ));

        return self::SUCCESS;
    }
}
