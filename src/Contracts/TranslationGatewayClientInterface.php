<?php

declare(strict_types=1);

namespace TranslationSdk\Contracts;

use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\FetchPackageIncrementalRequestDto;
use TranslationSdk\Dto\FetchPackageIncrementalResultDto;
use TranslationSdk\Dto\SyncTargetsDto;

interface TranslationGatewayClientInterface
{
    public function collect(CollectBatchDto $batch): void;

    public function fetchPackageIncremental(FetchPackageIncrementalRequestDto $request): FetchPackageIncrementalResultDto;

    public function fetchSyncTargets(): SyncTargetsDto;
}
