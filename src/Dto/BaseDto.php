<?php

declare(strict_types=1);

namespace TranslationSdk\Dto;

/**
 * 包内统一使用的轻量 DTO 基类。
 *
 * 这里故意不再依赖外部 data 框架，只保留一个简单的 `from()`:
 * - 传数组时把同名字段填进对象
 * - 需要嵌套 DTO 的字段由子类通过 `casts()` 显式声明
 *
 * 这样数据结构的来源和转换规则都能直接在本仓库里看懂。
 */
abstract class BaseDto
{
    public static function from(array $attributes): static
    {
        $dto = new static();

        foreach ($attributes as $key => $value) {
            if (! property_exists($dto, (string) $key)) {
                continue;
            }

            $dto->{$key} = static::castValue((string) $key, $value);
        }

        return $dto;
    }

    /**
     * 子类可在这里声明“哪个字段需要转换成哪个 DTO”。
     *
     * @return array<string, class-string<BaseDto>>
     */
    protected static function casts(): array
    {
        return [];
    }

    private static function castValue(string $key, mixed $value): mixed
    {
        $castClass = static::casts()[$key] ?? null;
        if ($castClass === null) {
            return $value;
        }

        if ($value instanceof $castClass) {
            return $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static function (mixed $item) use ($castClass): mixed {
                if ($item instanceof $castClass) {
                    return $item;
                }

                return is_array($item) ? $castClass::from($item) : $item;
            }, $value);
        }

        return $castClass::from($value);
    }
}
