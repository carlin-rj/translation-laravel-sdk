<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;

class PackageSyncResultDto extends BaseDto
{
    public string $locale = '';

    public int $start_cursor = 0;

    public int $next_cursor = 0;

    public int $pages = 0;

    public int $synced_items = 0;
}
