<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use TranslationSdk\Contracts\MissingBufferInterface;
use TranslationSdk\Dto\CollectItemDto;
use TranslationSdk\Services\PassiveCollector;
use TranslationSdk\Services\SourceTextResolver;
use TranslationSdk\Tests\Fakes\InMemoryMissingBuffer;
use TranslationSdk\Tests\TestCase;

class PassiveCollectorTest extends TestCase
{
    public function test_capture_missing_pushes_resolved_item(): void
    {
        $buffer = new InMemoryMissingBuffer();
        $sourceTextResolver = $this->createMock(SourceTextResolver::class);
        $sourceTextResolver->expects($this->once())
            ->method('resolve')
            ->with('order.status.pending')
            ->willReturn('待支付');
        $collector = new PassiveCollector($buffer, $sourceTextResolver, app('cache'));

        $size = $collector->captureMissing('order.status.pending');

        $this->assertSame(1, $size);
        $items = $buffer->all();
        $this->assertCount(1, $items);
        $this->assertSame('order.status.pending', $items[0]->key_name);
        $this->assertSame('待支付', $items[0]->source_text);
    }

    public function test_capture_missing_uses_original_key_as_source_text(): void
    {
        $buffer = new InMemoryMissingBuffer();
        $sourceTextResolver = $this->createMock(SourceTextResolver::class);
        $sourceTextResolver->expects($this->once())
            ->method('resolve')
            ->with('order.created')
            ->willReturn('order.created');
        $collector = new PassiveCollector($buffer, $sourceTextResolver, app('cache'));

        $collector->captureMissing('order.created');

        $items = $buffer->all();
        $this->assertSame('order.created', $items[0]->source_text);
    }

    public function test_capture_missing_skips_blank_key(): void
    {
        $buffer = new InMemoryMissingBuffer();
        $sourceTextResolver = $this->createMock(SourceTextResolver::class);
        $sourceTextResolver->expects($this->never())->method('resolve');
        $collector = new PassiveCollector($buffer, $sourceTextResolver, app('cache'));

        $size = $collector->captureMissing('  ');

        $this->assertSame(0, $size);
        $this->assertCount(0, $buffer->all());
    }

    public function test_should_flush_uses_threshold(): void
    {
        config()->set('translation_sdk.collect.passive.flush_threshold', 2);
        $buffer = new InMemoryMissingBuffer();
        $sourceTextResolver = $this->createMock(SourceTextResolver::class);
        $sourceTextResolver->expects($this->exactly(2))
            ->method('resolve')
            ->willReturnCallback(static fn (string $key): string => $key);
        $collector = new PassiveCollector($buffer, $sourceTextResolver, app('cache'));

        $collector->captureMissing('a');
        $this->assertFalse($collector->shouldFlush());

        $collector->captureMissing('b');
        $this->assertTrue($collector->shouldFlush());
    }

    public function test_capture_missing_respects_report_cooldown(): void
    {
        config()->set('translation_sdk.collect.passive.report_cooldown_seconds', 600);
        $buffer = $this->createMock(MissingBufferInterface::class);
        $sourceTextResolver = $this->createMock(SourceTextResolver::class);
        $sourceTextResolver->expects($this->once())
            ->method('resolve')
            ->with('order.status.pending')
            ->willReturn('待支付');
        $buffer->expects($this->once())
            ->method('push')
            ->with($this->callback(static function (CollectItemDto $item): bool {
                return $item->key_name === 'order.status.pending'
                    && $item->source_text === '待支付';
            }))
            ->willReturn(1);
        $buffer->method('size')->willReturn(1);

        $collector = new PassiveCollector($buffer, $sourceTextResolver, app('cache'));
        $collector->captureMissing('order.status.pending');
        $collector->captureMissing('order.status.pending');
    }

    public function test_capture_missing_with_zero_cooldown_reports_every_time(): void
    {
        config()->set('translation_sdk.collect.passive.report_cooldown_seconds', 0);
        $buffer = $this->createMock(MissingBufferInterface::class);
        $sourceTextResolver = $this->createMock(SourceTextResolver::class);
        $sourceTextResolver->expects($this->exactly(2))
            ->method('resolve')
            ->with('order.status.pending')
            ->willReturn('待支付');
        $buffer->expects($this->exactly(2))
            ->method('push')
            ->willReturnOnConsecutiveCalls(1, 2);

        $collector = new PassiveCollector($buffer, $sourceTextResolver, app('cache'));
        $first = $collector->captureMissing('order.status.pending');
        $second = $collector->captureMissing('order.status.pending');

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }

    public function test_capture_missing_cooldown_isolated_by_key(): void
    {
        config()->set('translation_sdk.collect.passive.report_cooldown_seconds', 600);
        $buffer = $this->createMock(MissingBufferInterface::class);
        $sourceTextResolver = $this->createMock(SourceTextResolver::class);
        $sourceTextResolver->expects($this->exactly(2))
            ->method('resolve')
            ->willReturnCallback(static fn (string $key): string => $key);
        $buffer->expects($this->exactly(2))
            ->method('push')
            ->with($this->callback(static function (CollectItemDto $item): bool {
                return in_array($item->key_name, ['order.status.pending', 'order.status.paid'], true);
            }))
            ->willReturn(1);
        $buffer->method('size')->willReturn(1);

        $collector = new PassiveCollector($buffer, $sourceTextResolver, app('cache'));
        $collector->captureMissing('order.status.pending');
        $collector->captureMissing('order.status.paid');
    }

    public function test_should_flush_uses_one_when_threshold_non_positive(): void
    {
        config()->set('translation_sdk.collect.passive.flush_threshold', 0);
        $buffer = new InMemoryMissingBuffer();
        $sourceTextResolver = $this->createMock(SourceTextResolver::class);
        $sourceTextResolver->expects($this->once())
            ->method('resolve')
            ->with('order.status.pending')
            ->willReturnCallback(static fn (string $key): string => $key);

        $collector = new PassiveCollector($buffer, $sourceTextResolver, app('cache'));
        $collector->captureMissing('order.status.pending');

        $this->assertTrue($collector->shouldFlush());
    }
}
