<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;

class SyncTargetsDto extends BaseDto
{
    public string $system_code = '';

    public string $source_locale = '';

    /**
     * @var array<int, string>
     */
    public array $target_locales = [];

    /**
     * @var array<int, string>
     */
    public array $modules = [];
}
