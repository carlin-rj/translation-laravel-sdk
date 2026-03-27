<?php

declare(strict_types=1);

namespace TranslationSdk\Tests\Fakes;

use TranslationSdk\Contracts\MissingBufferInterface;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\CollectItemDto;

class InMemoryMissingBuffer implements MissingBufferInterface
{
    /**
     * @var array<string, CollectItemDto>
     */
    private array $items = [];

    public function push(CollectItemDto $item): int
    {
        $this->items[$this->fingerprint($item)] = $item;

        return $this->size();
    }

    public function restore(CollectBatchDto $batch): void
    {
        foreach ($batch->items as $item) {
            if (! $item instanceof CollectItemDto) {
                continue;
            }
            $this->push($item);
        }
    }

    public function drain(int $limit): CollectBatchDto
    {
        $safeLimit = max(1, $limit);
        $keys = array_slice(array_keys($this->items), 0, $safeLimit);
        $drained = [];

        foreach ($keys as $key) {
            $drained[] = $this->items[$key];
            unset($this->items[$key]);
        }

        return CollectBatchDto::from(['items' => $drained]);
    }

    public function size(): int
    {
        return count($this->items);
    }

    /**
     * @return array<int, CollectItemDto>
     */
    public function all(): array
    {
        return array_values($this->items);
    }

    private function fingerprint(CollectItemDto $item): string
    {
        return sha1($item->key_name . '|' . $item->source_text);
    }
}
