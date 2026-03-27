<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;

class ActiveCollectResultDto extends BaseDto
{
    public int $scanned_files = 0;

    public int $discovered_items = 0;

    public int $pushed_batches = 0;

    public int $pushed_items = 0;
}
