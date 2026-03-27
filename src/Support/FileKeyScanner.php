<?php

declare(strict_types=1);

namespace TranslationSdk\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Services\SourceTextResolver;

/**
 * 全局扫描项目里的翻译调用。
 *
 * 这里故意使用轻量的字符串解析，而不是 AST:
 * - 对 SDK 来说足够实用
 * - 没有额外重依赖
 * - 能覆盖常见的静态字符串场景
 *
 * 目标不是“理解所有 PHP 语法”，而是稳定提取常见翻译 key。
 */
class FileKeyScanner
{
    private ?SourceTextResolver $sourceTextResolver;

    public function __construct(?SourceTextResolver $sourceTextResolver = null)
    {
        $this->sourceTextResolver = $sourceTextResolver;
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, string>  $extensions
     * @param  array<int, string>  $excludePaths
     */
    public function scan(
        array $paths,
        string $defaultModule,
        array $extensions,
        array $excludePaths = []
    ): CollectBatchDto {
        $items = [];
        $seen = [];
        $scannedFiles = 0;

        foreach ($paths as $path) {
            $pathText = trim((string) $path);
            if ($pathText === '' || ! is_dir($pathText)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($pathText, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $filePath = (string) $file->getPathname();
                if ($this->shouldSkip($filePath, $excludePaths)) {
                    continue;
                }
                if (! $this->matchExtension($filePath, $extensions)) {
                    continue;
                }

                $scannedFiles++;

                $content = @file_get_contents($filePath);
                if (! is_string($content) || $content === '') {
                    continue;
                }

                // 同一轮扫描内按 module + key 去重，避免同一个 key 在多个位置重复上报。
                foreach ($this->extractCollectItems($content, $defaultModule) as $item) {
                    $module = trim((string) ($item['module'] ?? $defaultModule));
                    if ($module === '') {
                        $module = $defaultModule;
                    }

                    $keyName = trim((string) ($item['key_name'] ?? ''));
                    if ($keyName === '') {
                        continue;
                    }

                    $sourceText = trim((string) ($item['source_text'] ?? ''));
                    if ($sourceText === '') {
                        $sourceText = $keyName;
                    }

                    $fingerprint = sha1($module . '|' . $keyName);
                    if (isset($seen[$fingerprint])) {
                        continue;
                    }
                    $seen[$fingerprint] = true;

                    $items[] = $this->buildCollectItem($module, $keyName, $sourceText);
                }
            }
        }

        return CollectBatchDto::from([
            'scanned_files' => $scannedFiles,
            'items' => $items,
        ]);
    }

    /**
     * @return array<int, array{module: string, key_name: string, source_text: string}>
     */
    private function extractCollectItems(string $content, string $defaultModule): array
    {
        $items = [];

        // 先扫 Laravel 原生翻译入口。
        $functions = ['__', 'trans', 'trans_choice', 'lang', 'Lang::get', 'Lang::choice', 'Lang::has'];
        foreach ($functions as $functionName) {
            foreach ($this->extractFunctionCalls($content, $functionName) as $callArgumentsText) {
                $keyText = $this->parseFirstStringArgument($callArgumentsText);
                if ($keyText === null || $keyText === '') {
                    continue;
                }
                $items[] = $this->buildCollectItem($defaultModule, $keyText);
            }
        }

        // Blade @lang 不是标准函数调用，单独处理。
        if (preg_match_all('/@lang\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1/s', $content, $matches)) {
            foreach (($matches[2] ?? []) as $rawKey) {
                $keyText = trim((string) stripcslashes((string) $rawKey));
                if ($keyText === '') {
                    continue;
                }
                $items[] = $this->buildCollectItem($defaultModule, $keyText);
            }
        }

        // tc 与 __ 参数保持一致，只额外支持 module 参数。
        foreach ($this->extractFunctionCalls($content, 'tc') as $callArgumentsText) {
            $item = $this->parseTcCollectItem($callArgumentsText, $defaultModule);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
        }

        return $items;
    }

    private function parseFirstStringArgument(string $argumentsText): ?string
    {
        $arguments = $this->splitArguments($argumentsText);
        if ($arguments === []) {
            return null;
        }

        $namedArguments = [];
        $positionalArguments = [];
        foreach ($arguments as $argument) {
            $named = $this->parseNamedArgument($argument);
            if ($named === null) {
                $positionalArguments[] = $argument;
                continue;
            }
            $namedArguments[$named['name']] = $named['value'];
        }

        $first = $this->parseStringLiteral($positionalArguments[0] ?? null);
        if ($first !== null && $first !== '') {
            return $first;
        }

        return $this->parseStringLiteral($namedArguments['key'] ?? null);
    }

    /**
     * 找到形如 `foo(...)` 的调用，并取出括号中的原始参数文本。
     *
     * @return array<int, string>
     */
    private function extractFunctionCalls(string $content, string $functionName): array
    {
        $calls = [];
        $offset = 0;
        $pattern = '/\b' . preg_quote($functionName, '/') . '\s*\(/i';
        $contentLength = strlen($content);

        // 用括号匹配而非简单正则，兼容参数中包含数组/函数调用的情况。
        while ($offset < $contentLength && preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $matchedText = (string) $matches[0][0];
            $start = (int) $matches[0][1];
            $openParenthesisOffset = $start + strlen($matchedText) - 1;
            $closeParenthesisOffset = $this->findMatchingParenthesis($content, $openParenthesisOffset);

            if ($closeParenthesisOffset === null) {
                $offset = $openParenthesisOffset + 1;
                continue;
            }

            $calls[] = substr(
                $content,
                $openParenthesisOffset + 1,
                $closeParenthesisOffset - $openParenthesisOffset - 1
            );
            $offset = $closeParenthesisOffset + 1;
        }

        return $calls;
    }

    /**
     * 手动配对括号，避免简单正则在数组、嵌套调用、字符串里失效。
     */
    private function findMatchingParenthesis(string $content, int $openParenthesisOffset): ?int
    {
        $depth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $escaped = false;
        $length = strlen($content);

        for ($i = $openParenthesisOffset; $i < $length; $i++) {
            $char = $content[$i];

            if ($inSingleQuote) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === "'") {
                    $inSingleQuote = false;
                }
                continue;
            }

            if ($inDoubleQuote) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inDoubleQuote = false;
                }
                continue;
            }

            if ($char === "'") {
                $inSingleQuote = true;
                continue;
            }

            if ($char === '"') {
                $inDoubleQuote = true;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 只在“最外层逗号”分割参数，避免把数组和函数调用切碎。
     *
     * @return array<int, string>
     */
    private function splitArguments(string $argumentsText): array
    {
        $arguments = [];
        $buffer = '';
        $parenthesisDepth = 0;
        $bracketDepth = 0;
        $braceDepth = 0;
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $escaped = false;
        $length = strlen($argumentsText);

        // 只在最外层逗号处分割，保证复杂参数结构不被切坏。
        for ($i = 0; $i < $length; $i++) {
            $char = $argumentsText[$i];

            if ($inSingleQuote) {
                $buffer .= $char;
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === "'") {
                    $inSingleQuote = false;
                }
                continue;
            }

            if ($inDoubleQuote) {
                $buffer .= $char;
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $inDoubleQuote = false;
                }
                continue;
            }

            if ($char === "'") {
                $inSingleQuote = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '"') {
                $inDoubleQuote = true;
                $buffer .= $char;
                continue;
            }

            if ($char === '(') {
                $parenthesisDepth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ')') {
                if ($parenthesisDepth > 0) {
                    $parenthesisDepth--;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === '[') {
                $bracketDepth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ']') {
                if ($bracketDepth > 0) {
                    $bracketDepth--;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === '{') {
                $braceDepth++;
                $buffer .= $char;
                continue;
            }

            if ($char === '}') {
                if ($braceDepth > 0) {
                    $braceDepth--;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === ',' && $parenthesisDepth === 0 && $bracketDepth === 0 && $braceDepth === 0) {
                $argument = trim($buffer);
                if ($argument !== '') {
                    $arguments[] = $argument;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $argument = trim($buffer);
        if ($argument !== '') {
            $arguments[] = $argument;
        }

        return $arguments;
    }

    /**
     * `tc()` 只比 `__()` 多一个 module 参数，所以解析逻辑也尽量保持简单。
     *
     * @return array{module: string, key_name: string, source_text: string}|null
     */
    private function parseTcCollectItem(string $argumentsText, string $defaultModule): ?array
    {
        $arguments = $this->splitArguments($argumentsText);
        if ($arguments === []) {
            return null;
        }

        $namedArguments = [];
        $positionalArguments = [];
        foreach ($arguments as $argument) {
            $named = $this->parseNamedArgument($argument);
            if ($named === null) {
                $positionalArguments[] = $argument;
                continue;
            }
            $namedArguments[$named['name']] = $named['value'];
        }

        $keyToken = $namedArguments['key'] ?? ($positionalArguments[0] ?? null);
        $keyName = $this->parseStringLiteral($keyToken);
        if ($keyName === null || $keyName === '') {
            return null;
        }

        $module = $defaultModule;

        $remainingPositional = isset($namedArguments['key']) ? $positionalArguments : array_slice($positionalArguments, 1);
        $moduleArgText = $this->parseStringLiteral($remainingPositional[2] ?? null);
        if ($moduleArgText !== null && $moduleArgText !== '') {
            $module = $moduleArgText;
        }

        $namedModule = $this->parseStringLiteral($namedArguments['module'] ?? null);
        if ($namedModule !== null && $namedModule !== '') {
            $module = $namedModule;
        }

        return $this->buildCollectItem($module, $keyName);
    }

    /**
     * 解析 PHP 8 命名参数，例如 `module: 'order'`。
     *
     * @return array{name: string, value: string}|null
     */
    private function parseNamedArgument(string $argument): ?array
    {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(.+)\s*$/s', $argument, $matches) !== 1) {
            return null;
        }

        $name = strtolower(str_replace(['_', '-'], '', trim((string) $matches[1])));
        $value = trim((string) $matches[2]);
        if ($name === '' || $value === '') {
            return null;
        }

        return ['name' => $name, 'value' => $value];
    }

    /**
     * 只识别静态字符串字面量，动态表达式不参与主动扫描。
     */
    private function parseStringLiteral(?string $token): ?string
    {
        if ($token === null) {
            return null;
        }

        $tokenText = trim($token);
        $length = strlen($tokenText);
        if ($length < 2) {
            return null;
        }

        if ($tokenText[0] === "'" && $tokenText[$length - 1] === "'") {
            $inner = substr($tokenText, 1, -1);

            return str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
        }

        if ($tokenText[0] === '"' && $tokenText[$length - 1] === '"') {
            $inner = substr($tokenText, 1, -1);

            return stripcslashes($inner);
        }

        return null;
    }

    /**
     * 用简单的“路径包含”规则排除目录，配置成本最低。
     *
     * @param  array<int, string>  $excludePaths
     */
    private function shouldSkip(string $filePath, array $excludePaths): bool
    {
        foreach ($excludePaths as $excludePath) {
            $excludeText = trim((string) $excludePath);
            if ($excludeText === '') {
                continue;
            }
            if (str_contains($filePath, $excludeText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 支持 `php`、`blade.php` 这类后缀。
     *
     * @param  array<int, string>  $extensions
     */
    private function matchExtension(string $filePath, array $extensions): bool
    {
        foreach ($extensions as $ext) {
            $extText = trim((string) $ext);
            if ($extText === '') {
                continue;
            }
            if (str_ends_with($filePath, '.' . $extText) || str_ends_with($filePath, $extText)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 统一构建扫描产出的收集项，减少重复数组结构。
     *
     * @return array{module: string, key_name: string, source_text: string}
     */
    private function buildCollectItem(string $module, string $keyName, ?string $sourceText = null): array
    {
        return [
            'module' => $module,
            'key_name' => $keyName,
            'source_text' => $this->resolveSourceText($keyName, $sourceText),
        ];
    }

    private function resolveSourceText(string $keyName, ?string $sourceText = null): string
    {
        $sourceTextValue = trim((string) ($sourceText ?? ''));
        if ($sourceTextValue !== '') {
            return $sourceTextValue;
        }

        if ($this->sourceTextResolver === null) {
            return $keyName;
        }

        return $this->sourceTextResolver->resolve($keyName);
    }
}
