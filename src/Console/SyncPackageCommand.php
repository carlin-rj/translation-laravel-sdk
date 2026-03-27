<?php

declare(strict_types=1);

namespace TranslationSdk\Console;

use Illuminate\Console\Command;
use TranslationSdk\Services\PackageSyncCursorRepository;
use TranslationSdk\Services\PackageSyncService;

/**
 * 远程翻译包同步命令。
 */
class SyncPackageCommand extends Command
{
    protected $signature = 'translation-sdk:sync-package
        {--locale=* : 目标语种(可多次传入，默认读取系统配置)}
        {--cursor= : 起始游标(覆盖增量游标，调试用)}
        {--limit= : 每页拉取条数}
        {--full : 强制全量同步(忽略已保存游标)}';

    protected $description = '从翻译中台增量拉取已确认翻译并更新本地缓存';

    public function handle(
        PackageSyncService $syncService,
        PackageSyncCursorRepository $cursorRepository
    ): int {
        // 用户没有显式传 locale 时，去远程系统读取同步目标。
        $locales = $this->normalizeOptionValues($this->option('locale'));
        if ($locales === []) {
            $targets = $syncService->fetchSyncTargets();
            $locales = $this->normalizeOptionValues($targets->target_locales);
        }

        if ($locales === []) {
            $this->warn('当前系统未配置可同步目标语种，已跳过');

            return self::SUCCESS;
        }

        $manualCursor = $this->resolveManualCursor();
        $forceFull = (bool) $this->option('full');
        $limitOption = trim((string) $this->option('limit'));
        $limit = $limitOption === '' ? null : (int) $limitOption;

        $targetCount = 0;
        $totalPages = 0;
        $totalItems = 0;
        foreach ($locales as $locale) {
            // 每个 locale 独立决定从哪个游标开始同步。
            $startCursor = $this->resolveStartCursor(
                $cursorRepository,
                $locale,
                $manualCursor,
                $forceFull
            );

            $result = $syncService->sync($locale, $startCursor, $limit);
            $targetCount++;
            $totalPages += $result->pages;
            $totalItems += $result->synced_items;

            // 手动 cursor 为一次性调试模式，不写回持久游标。
            if ($manualCursor === null) {
                $cursorRepository->saveCursor($locale, $result->next_cursor);
            }

            $this->line(sprintf(
                'locale=%s, start_cursor=%d, next_cursor=%d, pages=%d, synced_items=%d',
                $result->locale,
                $result->start_cursor,
                $result->next_cursor,
                $result->pages,
                $result->synced_items
            ));
        }

        $mode = $manualCursor !== null
            ? 'manual-cursor'
            : ($forceFull ? 'full' : 'incremental');
        $this->info(sprintf(
            'mode=%s, targets=%d, pages=%d, synced_items=%d',
            $mode,
            $targetCount,
            $totalPages,
            $totalItems
        ));

        return self::SUCCESS;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private function normalizeOptionValues(mixed $raw): array
    {
        // 允许同时兼容:
        // - `--locale=en-US --locale=ja-JP`
        // - `--locale=en-US,ja-JP`
        $source = is_array($raw) ? $raw : [$raw];
        $result = [];
        $seen = [];
        foreach ($source as $entry) {
            foreach (explode(',', (string) $entry) as $value) {
                $normalized = trim($value);
                if ($normalized === '' || isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;
                $result[] = $normalized;
            }
        }

        return $result;
    }

    /**
     * cursor 只作为一次性调试参数使用。
     */
    private function resolveManualCursor(): ?int
    {
        $cursorOption = trim((string) $this->option('cursor'));
        if ($cursorOption === '') {
            return null;
        }

        return max(0, (int) $cursorOption);
    }

    /**
     * 起始游标优先级:
     * - 手动 cursor
     * - full 模式
     * - 已保存游标
     */
    private function resolveStartCursor(
        PackageSyncCursorRepository $cursorRepository,
        string $locale,
        ?int $manualCursor,
        bool $forceFull
    ): int {
        if ($manualCursor !== null) {
            return $manualCursor;
        }
        if ($forceFull) {
            return 0;
        }

        return $cursorRepository->getCursor($locale);
    }
}
