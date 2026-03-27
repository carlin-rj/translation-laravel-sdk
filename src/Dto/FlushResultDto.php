<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;


class FlushResultDto extends BaseDto
{
    public int $drained = 0;

    public int $flushed = 0;

    public int $remaining = 0;

    public bool $success = true;
}
