<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Unit\Services;

use TranslationSdk\Services\PackageSyncCursorRepository;
use TranslationSdk\Tests\TestCase;

class PackageSyncCursorRepositoryTest extends TestCase
{
    public function test_it_persists_and_reads_cursor_by_locale(): void
    {
        config()->set('translation_sdk.sync.state_store', 'array');

        $repository = new PackageSyncCursorRepository(app('cache'));
        $this->assertSame(0, $repository->getCursor('en-US'));

        $repository->saveCursor('en-US', 23);
        $repository->saveCursor('ja-JP', 10);
        $repository->saveCursor('en-US', 35);

        $this->assertSame(35, $repository->getCursor('en-US'));
        $this->assertSame(10, $repository->getCursor('ja-JP'));
        $this->assertSame(0, $repository->getCursor('fr-FR'));
    }
}
