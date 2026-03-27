<?php

declare(strict_types=1);

namespace TranslationSdk\Contracts;

use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\CollectItemDto;

interface MissingBufferInterface
{
    public function push(CollectItemDto $item): int;

    public function restore(CollectBatchDto $batch): void;

    public function drain(int $limit): CollectBatchDto;

    public function size(): int;
}

