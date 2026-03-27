<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;

class FetchPackageIncrementalResultDto extends BaseDto
{
    public int $cursor = 0;

    public int $next_cursor = 0;

    public bool $has_more = false;

    /**
     * @var array<int, FetchPackageItemDto>
     */
    public array $items = [];

    protected static function casts(): array
    {
        return [
            'items' => FetchPackageItemDto::class,
        ];
    }
}
