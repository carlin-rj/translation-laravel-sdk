<?php

declare(strict_types=1);

namespace TranslationSdk\Services;

use Throwable;
use TranslationSdk\Contracts\MissingBufferInterface;
use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Dto\FlushResultDto;

/**
 * 把被动收集 buffer 里的缺失项推送到远程系统。
 *
 * 最关键的约束是“不能丢数据”:
 * - 成功则消费掉
 * - 失败则恢复回 buffer
 */
class MissingFlushService
{
    private MissingBufferInterface $buffer;

    private TranslationGatewayClientInterface $gatewayClient;

    public function __construct(
        MissingBufferInterface $buffer,
        TranslationGatewayClientInterface $gatewayClient
    ) {
        $this->buffer = $buffer;
        $this->gatewayClient = $gatewayClient;
    }

    public function flush(int $batchSize): FlushResultDto
    {
        $safeBatch = max(1, $batchSize);
        $batch = $this->buffer->drain($safeBatch);
        if ($batch->items === []) {
            return FlushResultDto::from([
                'drained' => 0,
                'flushed' => 0,
                'remaining' => $this->buffer->size(),
                'success' => true,
            ]);
        }

        try {
            // 推送成功后，本次 drain 出来的数据就算处理完成。
            $this->gatewayClient->collect($batch);

            return FlushResultDto::from([
                'drained' => count($batch->items),
                'flushed' => count($batch->items),
                'remaining' => $this->buffer->size(),
                'success' => true,
            ]);
        } catch (Throwable) {
            // 推送失败时恢复数据，保证被动收集不会因为网络抖动丢失。
            $this->buffer->restore($batch);

            return FlushResultDto::from([
                'drained' => count($batch->items),
                'flushed' => 0,
                'remaining' => $this->buffer->size(),
                'success' => false,
            ]);
        }
    }
}
