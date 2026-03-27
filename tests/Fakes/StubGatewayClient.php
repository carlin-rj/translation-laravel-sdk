<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Fakes;

use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\FetchPackageIncrementalRequestDto;
use TranslationSdk\Dto\FetchPackageIncrementalResultDto;
use TranslationSdk\Dto\SyncTargetsDto;

class StubGatewayClient implements TranslationGatewayClientInterface
{
    /**
     * @var array<int, CollectBatchDto>
     */
    public array $collected = [];

    /**
     * @var array<int, FetchPackageIncrementalResultDto>
     */
    private array $pages = [];

    private SyncTargetsDto $syncTargets;

    /**
     * @param  array<int, FetchPackageIncrementalResultDto>  $pages
     */
    public function __construct(array $pages = [])
    {
        $this->pages = array_values($pages);
        $this->syncTargets = SyncTargetsDto::from([
            'system_code' => 'test',
            'source_locale' => 'zh-CN',
            'target_locales' => ['en-US'],
        ]);
    }

    public function collect(CollectBatchDto $batch): void
    {
        $this->collected[] = $batch;
    }

    public function fetchPackageIncremental(FetchPackageIncrementalRequestDto $request): FetchPackageIncrementalResultDto
    {
        if ($this->pages === []) {
            return FetchPackageIncrementalResultDto::from([
                'cursor' => $request->cursor,
                'next_cursor' => $request->cursor,
                'has_more' => false,
                'items' => [],
            ]);
        }

        return array_shift($this->pages);
    }

    public function fetchSyncTargets(): SyncTargetsDto
    {
        return $this->syncTargets;
    }
}
