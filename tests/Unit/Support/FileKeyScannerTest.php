<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Support;

use Illuminate\Translation\ArrayLoader;
use TranslationSdk\Services\SourceTextResolver;
use TranslationSdk\Support\FileKeyScanner;
use TranslationSdk\Tests\TestCase;

class FileKeyScannerTest extends TestCase
{
    public function test_scan_covers_multi_style_writing_across_php_and_blade_files(): void
    {
        $baseDir = $this->createTempDirectory('scan-multi-style');
        file_put_contents(
            $baseDir . '/a.php',
            <<<'PHP'
            <?php
            __ ( "order.status.pending" );
            trans(
                key: 'order.status.pending'
            );
            trans_choice(
                'order.item_count',
                $count
            );
            lang( "feature.enabled" );
            PHP
        );
        file_put_contents(
            $baseDir . '/b.php',
            <<<'PHP'
            <?php
            __("Direct translation text");
            trans('Direct translation text');
            Lang::get("payment.success");
            PHP
        );
        file_put_contents(
            $baseDir . '/c.blade.php',
            <<<'BLADE'
            @lang("payment.success")
            @lang('Direct translation text')
            BLADE
        );

        $scanner = new FileKeyScanner($this->makeSourceTextResolver());
        $result = $scanner->scan([$baseDir], 'order', ['php', 'blade.php']);

        $map = [];
        foreach ($result->items as $item) {
            $map[$item->key_name] = $item->source_text;
            $this->assertSame('order', $item->module);
        }

        $this->assertSame(3, $result->scanned_files);
        $this->assertCount(5, $result->items);
        $this->assertSame('待支付', $map['order.status.pending'] ?? null);
        $this->assertSame('订单数量', $map['order.item_count'] ?? null);
        $this->assertSame('功能已启用', $map['feature.enabled'] ?? null);
        $this->assertSame('支付成功', $map['payment.success'] ?? null);
        $this->assertSame('Direct translation text', $map['Direct translation text'] ?? null);

        $this->removeTempDirectory($baseDir);
    }

    public function test_scan_handles_escaped_quotes_and_ignores_dynamic_expression_calls(): void
    {
        $baseDir = $this->createTempDirectory('scan-escaped-and-dynamic');
        file_put_contents(
            $baseDir . '/a.php',
            <<<'PHP'
            <?php
            __('I\'m ready');
            __("He said \"OK\"");
            __('order.status.' . $status);
            trans($dynamicKey);
            trans("order.status." . $status);
            trans(key: 'order.status.' . $status);
            trans("order.status.pending");
            PHP
        );
        mkdir($baseDir . '/nested', 0777, true);
        file_put_contents(
            $baseDir . '/nested/b.php',
            <<<'PHP'
            <?php
            __("He said \"OK\"");
            __('I\'m ready');
            PHP
        );

        $scanner = new FileKeyScanner($this->makeSourceTextResolver());
        $result = $scanner->scan([$baseDir], 'order', ['php']);

        $map = [];
        foreach ($result->items as $item) {
            $map[$item->key_name] = $item->source_text;
        }

        $this->assertSame(2, $result->scanned_files);
        $this->assertCount(3, $result->items);
        $this->assertSame("I'm ready", $map["I'm ready"] ?? null);
        $this->assertSame('He said "OK"', $map['He said "OK"'] ?? null);
        $this->assertSame('待支付', $map['order.status.pending'] ?? null);
        $this->assertArrayNotHasKey('order.status.', $map);

        $this->removeTempDirectory($baseDir);
    }

    public function test_scan_handles_single_and_double_quotes_across_multiple_files(): void
    {
        $baseDir = $this->createTempDirectory('scan-quotes-multi-files');
        file_put_contents(
            $baseDir . '/a.php',
            <<<'PHP'
            <?php
            __('order.status.pending');
            trans("order.status.pending");
            __('Direct translation text');
            PHP
        );
        mkdir($baseDir . '/nested', 0777, true);
        file_put_contents(
            $baseDir . '/nested/b.php',
            <<<'PHP'
            <?php
            __("order.status.pending");
            trans('order.status.pending');
            __("Direct translation text");
            PHP
        );

        $scanner = new FileKeyScanner($this->makeSourceTextResolver());
        $result = $scanner->scan([$baseDir], 'order', ['php']);

        $map = [];
        foreach ($result->items as $item) {
            $map[$item->key_name] = [
                'module' => $item->module,
                'source' => $item->source_text,
            ];
        }

        $this->assertSame(2, $result->scanned_files);
        $this->assertCount(2, $result->items);
        $this->assertSame('order', $map['order.status.pending']['module'] ?? null);
        $this->assertSame('待支付', $map['order.status.pending']['source'] ?? null);
        $this->assertSame('order', $map['Direct translation text']['module'] ?? null);
        $this->assertSame('Direct translation text', $map['Direct translation text']['source'] ?? null);

        $this->removeTempDirectory($baseDir);
    }

    public function test_scan_extracts_unique_keys_and_source_text(): void
    {
        $baseDir = $this->createTempDirectory('scan-basic');
        file_put_contents(
            $baseDir . '/a.php',
            <<<'PHP'
            <?php
            __('order.status.pending');
            trans('order.status.pending');
            __('Direct translation text');
            PHP
        );
        file_put_contents(
            $baseDir . '/b.blade.php',
            <<<'BLADE'
            @lang('payment.success')
            {{ Lang::get('payment.success') }}
            BLADE
        );

        $scanner = new FileKeyScanner($this->makeSourceTextResolver());
        $result = $scanner->scan([$baseDir], 'order', ['php', 'blade.php']);

        $map = [];
        foreach ($result->items as $item) {
            $map[$item->key_name] = $item->source_text;
            $this->assertSame('order', $item->module);
        }

        $this->assertSame(2, $result->scanned_files);
        $this->assertCount(3, $result->items);
        $this->assertSame('待支付', $map['order.status.pending'] ?? null);
        $this->assertSame('Direct translation text', $map['Direct translation text'] ?? null);
        $this->assertSame('支付成功', $map['payment.success'] ?? null);

        $this->removeTempDirectory($baseDir);
    }

    public function test_scan_respects_exclude_paths(): void
    {
        $baseDir = $this->createTempDirectory('scan-exclude');
        mkdir($baseDir . '/ignore', 0777, true);
        file_put_contents($baseDir . '/ignore/c.php', "<?php __('ignore.me');");
        file_put_contents($baseDir . '/ok.php', "<?php __('ok.key');");

        $scanner = new FileKeyScanner($this->makeSourceTextResolver());
        $result = $scanner->scan([$baseDir], 'order', ['php'], ['/ignore/']);

        $this->assertSame(1, $result->scanned_files);
        $this->assertCount(1, $result->items);
        $this->assertSame('ok.key', $result->items[0]->key_name);

        $this->removeTempDirectory($baseDir);
    }

    public function test_scan_parses_tc_module_and_common_variants(): void
    {
        $baseDir = $this->createTempDirectory('scan-tc');
        file_put_contents(
            $baseDir . '/tc.php',
            <<<'PHP'
            <?php
            tc('order.pending');
            tc('order.paid', ['name' => 'Tom']);
            tc('order.created', [], null, 'order');
            tc('order.confirmed', ['name' => 'Tom'], module: 'pay');
            PHP
        );

        $scanner = new FileKeyScanner($this->makeSourceTextResolver());
        $result = $scanner->scan([$baseDir], 'default-module', ['php']);

        $map = [];
        foreach ($result->items as $item) {
            $map[$item->key_name] = [
                'module' => $item->module,
                'source' => $item->source_text,
            ];
        }

        $this->assertSame(1, $result->scanned_files);
        $this->assertSame('default-module', $map['order.pending']['module'] ?? null);
        $this->assertSame('待处理', $map['order.pending']['source'] ?? null);
        $this->assertSame('default-module', $map['order.paid']['module'] ?? null);
        $this->assertSame('已支付', $map['order.paid']['source'] ?? null);
        $this->assertSame('order', $map['order.created']['module'] ?? null);
        $this->assertSame('已创建', $map['order.created']['source'] ?? null);
        $this->assertSame('pay', $map['order.confirmed']['module'] ?? null);
        $this->assertSame('已确认', $map['order.confirmed']['source'] ?? null);

        $this->removeTempDirectory($baseDir);
    }

    public function test_scan_covers_placeholder_and_named_argument_variants(): void
    {
        $baseDir = $this->createTempDirectory('scan-variants');
        file_put_contents(
            $baseDir . '/v.php',
            <<<'PHP'
            <?php
            __("订单创建 :orderSn", ["orderSn" => "test"]);
            __ ( '订单支付成功 :orderSn', ['orderSn' => $sn] );
            trans_choice('order.item_count', $count);
            trans(key: 'order.shipped');
            lang('order.finished');
            Lang::choice('cart.total_count', $count);
            Lang::has('feature.enabled');
            tc(key: 'payment.success', module: 'payment');
            tc(key: '支付成功1', module: 'payment1');
            tc(key: '支付成功2', [], null, 'payment1');
            tc(key: '支付成功3');
            PHP
        );
        file_put_contents(
            $baseDir . '/v.blade.php',
            <<<'BLADE'
            @lang ("blade.greeting :name", ['name' => $name])
            BLADE
        );

        $scanner = new FileKeyScanner($this->makeSourceTextResolver());
        $result = $scanner->scan([$baseDir], 'default-module', ['php', 'blade.php']);

        $map = [];
        foreach ($result->items as $item) {
            $map[$item->key_name] = [
                'module' => $item->module,
                'source' => $item->source_text,
            ];
        }

        $this->assertSame(2, $result->scanned_files);
        $this->assertSame('default-module', $map['订单创建 :orderSn']['module'] ?? null);
        $this->assertSame('订单创建 :orderSn', $map['订单创建 :orderSn']['source'] ?? null);
        $this->assertSame('default-module', $map['订单支付成功 :orderSn']['module'] ?? null);
        $this->assertSame('订单支付成功 :orderSn', $map['订单支付成功 :orderSn']['source'] ?? null);
        $this->assertSame('default-module', $map['order.item_count']['module'] ?? null);
        $this->assertSame('订单数量', $map['order.item_count']['source'] ?? null);
        $this->assertSame('default-module', $map['order.shipped']['module'] ?? null);
        $this->assertSame('已发货', $map['order.shipped']['source'] ?? null);
        $this->assertSame('default-module', $map['order.finished']['module'] ?? null);
        $this->assertSame('已完成', $map['order.finished']['source'] ?? null);
        $this->assertSame('default-module', $map['cart.total_count']['module'] ?? null);
        $this->assertSame('购物车数量', $map['cart.total_count']['source'] ?? null);
        $this->assertSame('default-module', $map['feature.enabled']['module'] ?? null);
        $this->assertSame('功能已启用', $map['feature.enabled']['source'] ?? null);
        $this->assertSame('payment', $map['payment.success']['module'] ?? null);
        $this->assertSame('支付成功', $map['payment.success']['source'] ?? null);
        $this->assertSame('default-module', $map['blade.greeting :name']['module'] ?? null);
        $this->assertSame('blade.greeting :name', $map['blade.greeting :name']['source'] ?? null);
        $this->assertSame('支付成功1', $map['支付成功1']['source'] ?? null);
        $this->assertSame('支付成功2', $map['支付成功2']['source'] ?? null);
        $this->assertSame('支付成功3', $map['支付成功3']['source'] ?? null);

        $this->removeTempDirectory($baseDir);
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

    private function makeSourceTextResolver(): SourceTextResolver
    {
        $loader = new ArrayLoader();
        $loader->addMessages('zh-CN', 'order', [
            'status' => [
                'pending' => '待支付',
            ],
            'pending' => '待处理',
            'paid' => '已支付',
            'created' => '已创建',
            'confirmed' => '已确认',
            'item_count' => '订单数量',
            'shipped' => '已发货',
            'finished' => '已完成',
        ]);
        $loader->addMessages('zh-CN', 'payment', [
            'success' => '支付成功',
        ]);
        $loader->addMessages('zh-CN', 'cart', [
            'total_count' => '购物车数量',
        ]);
        $loader->addMessages('zh-CN', 'feature', [
            'enabled' => '功能已启用',
        ]);

        return new SourceTextResolver($loader);
    }
}
