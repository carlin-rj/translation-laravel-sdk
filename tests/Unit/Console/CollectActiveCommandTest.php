<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use TranslationSdk\Dto\ActiveCollectResultDto;
use TranslationSdk\Services\ActiveCollector;
use TranslationSdk\Services\ModuleResolver;
use TranslationSdk\Tests\TestCase;

class CollectActiveCommandTest extends TestCase
{
    public function test_collect_active_command_runs_with_dependencies(): void
    {
        $collector = $this->getMockBuilder(ActiveCollector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collect'])
            ->getMock();
        $collector->expects($this->once())
            ->method('collect')
            ->with(['/app', '/resources'], 'order', 50)
            ->willReturn(ActiveCollectResultDto::from([
                'scanned_files' => 2,
                'discovered_items' => 3,
                'pushed_batches' => 2,
                'pushed_items' => 3,
            ]));

        $resolver = $this->getMockBuilder(ModuleResolver::class)
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->expects($this->once())
            ->method('resolve')
            ->with('order')
            ->willReturn('order');

        app()->instance(ActiveCollector::class, $collector);
        app()->instance(ModuleResolver::class, $resolver);

        $exit = Artisan::call('translation-sdk:collect-active', [
            '--module' => 'order',
            '--path' => ['/app', '/resources'],
            '--batch' => 50,
        ]);

        $this->assertSame(0, $exit);
    }
}
