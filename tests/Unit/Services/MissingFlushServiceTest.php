<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use RuntimeException;
use TranslationSdk\Contracts\MissingBufferInterface;
use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\CollectItemDto;
use TranslationSdk\Services\MissingFlushService;
use TranslationSdk\Tests\Fakes\InMemoryMissingBuffer;
use TranslationSdk\Tests\TestCase;

class MissingFlushServiceTest extends TestCase
{
    public function test_flush_successfully_drains_and_pushes(): void
    {
        $buffer = new InMemoryMissingBuffer();
        $buffer->push(CollectItemDto::from([
            'key_name' => 'order.status.pending',
            'source_text' => 'pending',
        ]));
        $buffer->push(CollectItemDto::from([
            'key_name' => 'order.status.created',
            'source_text' => 'created',
        ]));

        $gateway = $this->createMock(TranslationGatewayClientInterface::class);
        $gateway->expects($this->once())->method('collect');

        $service = new MissingFlushService($buffer, $gateway);
        $result = $service->flush(10);

        $this->assertTrue($result->success);
        $this->assertSame(2, $result->drained);
        $this->assertSame(2, $result->flushed);
        $this->assertSame(0, $result->remaining);
    }

    public function test_flush_restore_when_gateway_failed(): void
    {
        $buffer = new InMemoryMissingBuffer();
        $buffer->push(CollectItemDto::from([
            'key_name' => 'order.status.pending',
            'source_text' => 'pending',
        ]));

        $gateway = $this->createMock(TranslationGatewayClientInterface::class);
        $gateway->expects($this->once())
            ->method('collect')
            ->willThrowException(new RuntimeException('network error'));

        $service = new MissingFlushService($buffer, $gateway);
        $result = $service->flush(10);

        $this->assertFalse($result->success);
        $this->assertSame(1, $result->drained);
        $this->assertSame(0, $result->flushed);
        $this->assertSame(1, $result->remaining);
        $this->assertSame(1, $buffer->size());
    }

    public function test_flush_returns_success_when_buffer_is_empty(): void
    {
        $buffer = $this->createMock(MissingBufferInterface::class);
        $buffer->expects($this->once())
            ->method('drain')
            ->with(10)
            ->willReturn(CollectBatchDto::from(['items' => []]));
        $buffer->expects($this->once())
            ->method('size')
            ->willReturn(5);
        $buffer->expects($this->never())->method('restore');

        $gateway = $this->createMock(TranslationGatewayClientInterface::class);
        $gateway->expects($this->never())->method('collect');

        $service = new MissingFlushService($buffer, $gateway);
        $result = $service->flush(10);

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->drained);
        $this->assertSame(0, $result->flushed);
        $this->assertSame(5, $result->remaining);
    }

    public function test_flush_normalizes_non_positive_batch_size_to_one(): void
    {
        $buffer = $this->createMock(MissingBufferInterface::class);
        $buffer->expects($this->once())
            ->method('drain')
            ->with(1)
            ->willReturn(CollectBatchDto::from(['items' => []]));
        $buffer->method('size')->willReturn(0);

        $gateway = $this->createMock(TranslationGatewayClientInterface::class);
        $gateway->expects($this->never())->method('collect');

        $service = new MissingFlushService($buffer, $gateway);
        $result = $service->flush(0);

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->drained);
    }

    public function test_flush_calls_restore_with_same_batch_on_failure(): void
    {
        $batch = CollectBatchDto::from([
            'items' => [
                CollectItemDto::from([
                    'key_name' => 'order.status.pending',
                    'source_text' => 'pending',
                ]),
            ],
        ]);

        $buffer = $this->createMock(MissingBufferInterface::class);
        $buffer->expects($this->once())
            ->method('drain')
            ->with(10)
            ->willReturn($batch);
        $buffer->expects($this->once())
            ->method('restore')
            ->with($this->identicalTo($batch));
        $buffer->expects($this->once())
            ->method('size')
            ->willReturn(1);

        $gateway = $this->createMock(TranslationGatewayClientInterface::class);
        $gateway->expects($this->once())
            ->method('collect')
            ->with($this->identicalTo($batch))
            ->willThrowException(new RuntimeException('network error'));

        $service = new MissingFlushService($buffer, $gateway);
        $result = $service->flush(10);

        $this->assertFalse($result->success);
        $this->assertSame(1, $result->drained);
        $this->assertSame(0, $result->flushed);
        $this->assertSame(1, $result->remaining);
    }
}
