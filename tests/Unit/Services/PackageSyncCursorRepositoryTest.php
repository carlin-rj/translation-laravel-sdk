<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use TranslationSdk\Services\PackageSyncCursorRepository;
use TranslationSdk\Tests\TestCase;

class PackageSyncCursorRepositoryTest extends TestCase
{
    public function test_it_persists_and_reads_cursor_by_locale_and_module(): void
    {
        config()->set('translation_sdk.sync.state_store', 'array');
        config()->set('translation_sdk.sync.state_key_prefix', 'translation_sdk:test:cursor');

        $repository = new PackageSyncCursorRepository(app('cache'));
        $this->assertSame(0, $repository->getCursor('en-US', 'order'));

        $repository->saveCursor('en-US', 'order', 23);
        $repository->saveCursor('ja-JP', 'order', 10);
        $repository->saveCursor('en-US', null, 35);

        $this->assertSame(23, $repository->getCursor('en-US', 'order'));
        $this->assertSame(10, $repository->getCursor('ja-JP', 'order'));
        $this->assertSame(0, $repository->getCursor('en-US', 'payment'));
        $this->assertSame(35, $repository->getCursor('en-US', null));
    }
}
