<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use TranslationSdk\Dto\FlushResultDto;
use TranslationSdk\Services\MissingFlushService;
use TranslationSdk\Tests\TestCase;

class FlushMissingCommandTest extends TestCase
{
    public function test_flush_missing_command_runs(): void
    {
        $flushService = $this->getMockBuilder(MissingFlushService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['flush'])
            ->getMock();
        $flushService->expects($this->once())
            ->method('flush')
            ->with(30)
            ->willReturn(FlushResultDto::from([
                'drained' => 10,
                'flushed' => 10,
                'remaining' => 0,
                'success' => true,
            ]));

        app()->instance(MissingFlushService::class, $flushService);

        $exit = Artisan::call('translation-sdk:flush-missing', [
            '--batch' => 30,
        ]);

        $this->assertSame(0, $exit);
    }
}
