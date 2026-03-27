<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use TranslationSdk\Dto\PackageSyncResultDto;
use TranslationSdk\Dto\SyncTargetsDto;
use TranslationSdk\Services\PackageSyncCursorRepository;
use TranslationSdk\Services\PackageSyncService;
use TranslationSdk\Tests\TestCase;

class SyncPackageCommandTest extends TestCase
{
    public function test_sync_package_command_loads_targets_from_gateway_and_saves_cursor(): void
    {
        $syncService = $this->getMockBuilder(PackageSyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchSyncTargets', 'sync'])
            ->getMock();
        $syncService->expects($this->once())
            ->method('fetchSyncTargets')
            ->willReturn(SyncTargetsDto::from([
                'system_code' => 'purchase',
                'source_locale' => 'zh-CN',
                'target_locales' => ['en-US', 'ja-JP'],
                'modules' => ['order'],
            ]));
        $syncCalls = [];
        $syncService->expects($this->exactly(2))
            ->method('sync')
            ->willReturnCallback(static function (
                string $locale,
                ?string $module,
                int $cursor,
                ?int $limit
            ) use (&$syncCalls): PackageSyncResultDto {
                $syncCalls[] = [$locale, $module, $cursor, $limit];
                if (count($syncCalls) === 1) {
                    return PackageSyncResultDto::from([
                        'locale' => 'en-US',
                        'module' => '*',
                        'start_cursor' => 10,
                        'next_cursor' => 15,
                        'pages' => 1,
                        'synced_items' => 3,
                    ]);
                }

                return PackageSyncResultDto::from([
                    'locale' => 'ja-JP',
                    'module' => '*',
                    'start_cursor' => 20,
                    'next_cursor' => 25,
                    'pages' => 1,
                    'synced_items' => 2,
                ]);
            });

        $cursorRepository = $this->getMockBuilder(PackageSyncCursorRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCursor', 'saveCursor'])
            ->getMock();
        $cursorGetCalls = [];
        $cursorRepository->expects($this->exactly(2))
            ->method('getCursor')
            ->willReturnCallback(static function (string $locale, ?string $module) use (&$cursorGetCalls): int {
                $cursorGetCalls[] = [$locale, $module];

                return count($cursorGetCalls) === 1 ? 10 : 20;
            });
        $cursorSaveCalls = [];
        $cursorRepository->expects($this->exactly(2))
            ->method('saveCursor')
            ->willReturnCallback(static function (string $locale, ?string $module, int $cursor) use (&$cursorSaveCalls): void {
                $cursorSaveCalls[] = [$locale, $module, $cursor];
            });

        app()->instance(PackageSyncService::class, $syncService);
        app()->instance(PackageSyncCursorRepository::class, $cursorRepository);

        $exit = Artisan::call('translation-sdk:sync-package', []);

        $this->assertSame(0, $exit);
        $this->assertSame([
            ['en-US', null, 10, null],
            ['ja-JP', null, 20, null],
        ], $syncCalls);
        $this->assertSame([
            ['en-US', null],
            ['ja-JP', null],
        ], $cursorGetCalls);
        $this->assertSame([
            ['en-US', null, 15],
            ['ja-JP', null, 25],
        ], $cursorSaveCalls);
    }

    public function test_sync_package_command_uses_manual_overrides_without_target_fetch(): void
    {
        $syncService = $this->getMockBuilder(PackageSyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchSyncTargets', 'sync'])
            ->getMock();
        $syncService->expects($this->never())->method('fetchSyncTargets');
        $syncService->expects($this->once())
            ->method('sync')
            ->with('en-US', 'order', 100, 50)
            ->willReturn(PackageSyncResultDto::from([
                'locale' => 'en-US',
                'module' => 'order',
                'start_cursor' => 100,
                'next_cursor' => 150,
                'pages' => 2,
                'synced_items' => 20,
            ]));

        $cursorRepository = $this->getMockBuilder(PackageSyncCursorRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCursor', 'saveCursor'])
            ->getMock();
        $cursorRepository->expects($this->never())->method('getCursor');
        $cursorRepository->expects($this->never())->method('saveCursor');

        app()->instance(PackageSyncService::class, $syncService);
        app()->instance(PackageSyncCursorRepository::class, $cursorRepository);

        $exit = Artisan::call('translation-sdk:sync-package', [
            '--locale' => ['en-US'],
            '--module' => 'order',
            '--cursor' => 100,
            '--limit' => 50,
        ]);

        $this->assertSame(0, $exit);
    }

    public function test_sync_package_command_full_mode_ignores_saved_cursor_and_saves_new_cursor(): void
    {
        $syncService = $this->getMockBuilder(PackageSyncService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchSyncTargets', 'sync'])
            ->getMock();
        $syncService->expects($this->once())
            ->method('fetchSyncTargets')
            ->willReturn(SyncTargetsDto::from([
                'target_locales' => ['en-US'],
                'modules' => ['order'],
            ]));
        $syncService->expects($this->once())
            ->method('sync')
            ->with('en-US', null, 0, null)
            ->willReturn(PackageSyncResultDto::from([
                'locale' => 'en-US',
                'module' => '*',
                'start_cursor' => 0,
                'next_cursor' => 11,
                'pages' => 1,
                'synced_items' => 8,
            ]));

        $cursorRepository = $this->getMockBuilder(PackageSyncCursorRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCursor', 'saveCursor'])
            ->getMock();
        $cursorRepository->expects($this->never())->method('getCursor');
        $cursorRepository->expects($this->once())
            ->method('saveCursor')
            ->with('en-US', null, 11);

        app()->instance(PackageSyncService::class, $syncService);
        app()->instance(PackageSyncCursorRepository::class, $cursorRepository);

        $exit = Artisan::call('translation-sdk:sync-package', [
            '--full' => true,
        ]);

        $this->assertSame(0, $exit);
    }
}
