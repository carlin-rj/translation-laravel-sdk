<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;


class FetchPackageItemDto extends BaseDto
{
    public int $id = 0;

    public int $translation_key_id = 0;

    public string $locale = '';

    public ?string $key_name = null;

    public ?string $translation_text = null;

    public ?string $final_text = null;

    public ?string $mt_text = null;

    public ?string $updated_at = null;
}
