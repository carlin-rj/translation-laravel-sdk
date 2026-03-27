<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use Illuminate\Translation\ArrayLoader;
use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Services\ActiveCollector;
use TranslationSdk\Services\SourceTextResolver;
use TranslationSdk\Support\FileKeyScanner;
use TranslationSdk\Tests\Fakes\StubGatewayClient;
use TranslationSdk\Tests\TestCase;

class ActiveCollectorTest extends TestCase
{
    public function test_collect_chunks_and_pushes_batches(): void
    {
        config()->set('translation_sdk.collect.scan_extensions', ['php']);
        config()->set('translation_sdk.collect.exclude_paths', ['vendor']);

        $scanner = $this->getMockBuilder(FileKeyScanner::class)
            ->onlyMethods(['scan'])
            ->getMock();
        $scanner->expects($this->once())
            ->method('scan')
            ->with(['/app'], ['php'], ['vendor'])
            ->willReturn(CollectBatchDto::from([
                'scanned_files' => 3,
                'items' => [
                    ['key_name' => 'k1', 'source_text' => 'k1'],
                    ['key_name' => 'k2', 'source_text' => 'k2'],
                    ['key_name' => 'k3', 'source_text' => 'k3'],
                    ['key_name' => 'k4', 'source_text' => 'k4'],
                    ['key_name' => 'k5', 'source_text' => 'k5'],
                ],
            ]));

        $sizes = [];
        $gateway = $this->createMock(TranslationGatewayClientInterface::class);
        $gateway->expects($this->exactly(3))
            ->method('collect')
            ->willReturnCallback(static function (CollectBatchDto $batch) use (&$sizes): void {
                $sizes[] = count($batch->items);
            });

        $collector = new ActiveCollector($scanner, $gateway);
        $result = $collector->collect(['/app'], 2);

        $this->assertSame([2, 2, 1], $sizes);
        $this->assertSame(3, $result->scanned_files);
        $this->assertSame(5, $result->discovered_items);
        $this->assertSame(3, $result->pushed_batches);
        $this->assertSame(5, $result->pushed_items);
    }

    public function test_collect_skips_push_when_no_items(): void
    {
        $scanner = $this->createStub(FileKeyScanner::class);
        $scanner->method('scan')->willReturn(CollectBatchDto::from(['items' => []]));

        $gateway = $this->createMock(TranslationGatewayClientInterface::class);
        $gateway->expects($this->never())->method('collect');

        $collector = new ActiveCollector($scanner, $gateway);
        $result = $collector->collect(['/app'], 2);

        $this->assertSame(0, $result->scanned_files);
        $this->assertSame(0, $result->discovered_items);
        $this->assertSame(0, $result->pushed_batches);
        $this->assertSame(0, $result->pushed_items);
    }

    public function test_collect_handles_quote_variants_in_multiple_files(): void
    {
        config()->set('translation_sdk.collect.scan_extensions', ['php']);
        config()->set('translation_sdk.collect.exclude_paths', []);

        $baseDir = $this->createTempDirectory('active-collect-quotes');
        file_put_contents(
            $baseDir . '/a.php',
            <<<'PHP'
            <?php
            __('order.status.pending');
            trans("order.status.pending");
            PHP
        );
        file_put_contents(
            $baseDir . '/b.php',
            <<<'PHP'
            <?php
            __("Direct translation text");
            trans('Direct translation text');
            PHP
        );

        $gateway = new StubGatewayClient();
        $collector = new ActiveCollector(
            new FileKeyScanner($this->makeSourceTextResolver()),
            $gateway
        );
        $result = $collector->collect([$baseDir], 10);

        $this->assertSame(2, $result->scanned_files);
        $this->assertSame(2, $result->discovered_items);
        $this->assertSame(1, $result->pushed_batches);
        $this->assertSame(2, $result->pushed_items);

        $this->assertCount(1, $gateway->collected);
        $batch = $gateway->collected[0];
        $map = [];
        foreach ($batch->items as $item) {
            $map[$item->key_name] = $item->source_text;
        }
        $this->assertSame('待支付', $map['order.status.pending'] ?? null);
        $this->assertSame('Direct translation text', $map['Direct translation text'] ?? null);

        $this->removeTempDirectory($baseDir);
    }

    public function test_collect_supports_mixed_styles_and_batches_across_multiple_files(): void
    {
        config()->set('translation_sdk.collect.scan_extensions', ['php', 'blade.php']);
        config()->set('translation_sdk.collect.exclude_paths', []);

        $baseDir = $this->createTempDirectory('active-collect-mixed-styles');
        file_put_contents(
            $baseDir . '/a.php',
            <<<'PHP'
            <?php
            __('order.status.pending');
            trans("order.item_count");
            __('Direct translation text');
            PHP
        );
        file_put_contents(
            $baseDir . '/b.php',
            <<<'PHP'
            <?php
            trans(
                key: 'order.status.pending'
            );
            trans("order.status." . $status);
            trans($dynamicKey);
            PHP
        );
        file_put_contents(
            $baseDir . '/c.blade.php',
            <<<'BLADE'
            @lang("payment.success")
            @lang('Direct translation text')
            BLADE
        );

        $gateway = new StubGatewayClient();
        $collector = new ActiveCollector(
            new FileKeyScanner($this->makeSourceTextResolver()),
            $gateway
        );
        $result = $collector->collect([$baseDir], 2);

        $this->assertSame(3, $result->scanned_files);
        $this->assertSame(4, $result->discovered_items);
        $this->assertSame(2, $result->pushed_batches);
        $this->assertSame(4, $result->pushed_items);

        $this->assertCount(2, $gateway->collected);
        $batchSizes = array_map(static fn (CollectBatchDto $batch): int => count($batch->items), $gateway->collected);
        $this->assertSame([2, 2], $batchSizes);

        $map = [];
        foreach ($gateway->collected as $batch) {
            foreach ($batch->items as $item) {
                $map[$item->key_name] = $item->source_text;
            }
        }
        $this->assertSame('待支付', $map['order.status.pending'] ?? null);
        $this->assertSame('订单数量', $map['order.item_count'] ?? null);
        $this->assertSame('支付成功', $map['payment.success'] ?? null);
        $this->assertSame('Direct translation text', $map['Direct translation text'] ?? null);
        $this->assertArrayNotHasKey('order.status.', $map);

        $this->removeTempDirectory($baseDir);
    }

    private function makeSourceTextResolver(): SourceTextResolver
    {
        $loader = new ArrayLoader();
        $loader->addMessages('zh-CN', 'order', [
            'status' => [
                'pending' => '待支付',
            ],
            'item_count' => '订单数量',
        ]);
        $loader->addMessages('zh-CN', 'payment', [
            'success' => '支付成功',
        ]);

        return new SourceTextResolver($loader);
    }

    private function createTempDirectory(string $suffix): string
    {
        $path = sys_get_temp_dir() . '/translation-sdk-' . $suffix . '-' . uniqid('', true);
        mkdir($path, 0777, true);

        return $path;
    }

    private function removeTempDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir((string) $file->getPathname());
            } else {
                unlink((string) $file->getPathname());
            }
        }
        rmdir($path);
    }
}
