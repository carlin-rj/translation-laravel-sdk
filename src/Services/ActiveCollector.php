<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Dto\ActiveCollectResultDto;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Support\FileKeyScanner;

/**
 * 主动扫描入口。
 *
 * 这个服务本身不解析源码，它只负责:
 * 1. 调用扫描器拿到全量结果
 * 2. 按批次推送到远程系统
 */
class ActiveCollector
{
    private FileKeyScanner $scanner;

    private TranslationGatewayClientInterface $gatewayClient;

    public function __construct(FileKeyScanner $scanner, TranslationGatewayClientInterface $gatewayClient)
    {
        $this->scanner = $scanner;
        $this->gatewayClient = $gatewayClient;
    }

    /**
     * @param  array<int, string>  $paths
     */
    public function collect(array $paths, int $batchSize): ActiveCollectResultDto
    {
        $extensions = (array) config('translation_sdk.collect.scan_extensions', ['php']);
        $excludePaths = (array) config('translation_sdk.collect.exclude_paths', []);
        $scanResult = $this->scanner->scan($paths, $extensions, $excludePaths);
        $safeBatchSize = max(1, $batchSize);

        $pushedBatches = 0;
        $pushedItems = 0;

        // 扫描完之后再统一分批推送，避免单次请求包体过大。
        foreach (array_chunk($scanResult->items, $safeBatchSize) as $chunk) {
            $batch = CollectBatchDto::from(['items' => $chunk]);
            $this->gatewayClient->collect($batch);
            $pushedBatches++;
            $pushedItems += count($chunk);
        }

        return ActiveCollectResultDto::from([
            'scanned_files' => $scanResult->scanned_files,
            'discovered_items' => count($scanResult->items),
            'pushed_batches' => $pushedBatches,
            'pushed_items' => $pushedItems,
        ]);
    }
}
