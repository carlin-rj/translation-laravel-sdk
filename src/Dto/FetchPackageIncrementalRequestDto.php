<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;


class FetchPackageIncrementalRequestDto extends BaseDto
{
    public string $locale = '';

    public int $cursor = 0;

    public int $limit = 200;
}
