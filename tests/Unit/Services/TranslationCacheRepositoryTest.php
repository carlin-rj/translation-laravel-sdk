<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use TranslationSdk\Services\TranslationCacheRepository;
use TranslationSdk\Tests\TestCase;

class TranslationCacheRepositoryTest extends TestCase
{
    public function test_merge_and_get_translation(): void
    {
        $repository = new TranslationCacheRepository(app('cache'));

        $repository->mergeTranslations('en', [
            'order.status.pending' => 'Pending',
            'order.status.paid' => 'Paid',
        ]);

        $this->assertSame('Pending', $repository->get('en', 'order.status.pending'));
        $this->assertSame('Paid', $repository->get('en', 'order.status.paid'));
    }

    public function test_merge_overwrites_existing_key(): void
    {
        $repository = new TranslationCacheRepository(app('cache'));

        $repository->mergeTranslations('en', [
            'order.status.pending' => 'Pending',
        ]);
        $repository->mergeTranslations('en', [
            'order.status.pending' => 'Waiting',
        ]);

        $this->assertSame('Waiting', $repository->get('en', 'order.status.pending'));
    }

    public function test_get_returns_null_when_missing_or_non_string(): void
    {
        $repository = new TranslationCacheRepository(app('cache'));
        $cacheKey = 'translation_sdk:package:en';

        cache()->store()->put($cacheKey, ['order.status.pending' => 1], 60);

        $this->assertNull($repository->get('en', 'order.status.pending'));
        $this->assertNull($repository->get('en', 'not-exists'));
    }

    public function test_has_returns_true_when_string_translation_exists(): void
    {
        $repository = new TranslationCacheRepository(app('cache'));

        $repository->mergeTranslations('en', [
            'order.status.pending' => 'Pending',
        ]);

        $this->assertTrue($repository->has('en', 'order.status.pending'));
        $this->assertFalse($repository->has('en', 'order.status.created'));
    }
}
