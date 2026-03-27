<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;


class CollectItemDto extends BaseDto
{
    public string $module = '';

    public string $key_name = '';

    public string $source_text = '';
}
