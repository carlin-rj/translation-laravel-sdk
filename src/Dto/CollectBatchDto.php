<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;

class CollectBatchDto extends BaseDto
{
    public int $scanned_files = 0;

    /**
     * @var array<int, CollectItemDto>
     */
    public array $items = [];

    protected static function casts(): array
    {
        return [
            'items' => CollectItemDto::class,
        ];
    }
}
