<?php

declare(strict_types=1);

namespace TranslationSdk\Clients;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use TranslationSdk\Contracts\TranslationGatewayClientInterface;
use TranslationSdk\Dto\CollectBatchDto;
use TranslationSdk\Dto\FetchPackageIncrementalRequestDto;
use TranslationSdk\Dto\FetchPackageIncrementalResultDto;
use TranslationSdk\Dto\SyncTargetsDto;

/**
 * 远程翻译系统 HTTP 客户端。
 *
 * 这个类只负责协议转换:
 * - 把 SDK 内部 DTO 转成远程接口 payload
 * - 把远程返回 data 转回 DTO
 */
class TranslationGatewayClient implements TranslationGatewayClientInterface
{
    private const SUCCESS_STATE = '000001';

    private HttpFactory $http;

    private string $baseUrl;

    private string $systemToken;

    private int $timeout;

    public function __construct(HttpFactory $http)
    {
        $this->http = $http;
        $this->baseUrl = (string) config('translation_sdk.gateway.base_url', '');
        $this->systemToken = (string) config('translation_sdk.gateway.system_token', '');
        // Laravel HTTP client 的 timeout 这里使用整数秒，所以配置值统一向上取整。
        $this->timeout = max(1, (int) ceil((float) config('translation_sdk.gateway.timeout', 2)));
    }

    /**
     * 批量上报主动扫描或被动收集的结果。
     */
    public function collect(CollectBatchDto $batch): void
    {
        if ($batch->items === []) {
            return;
        }

        $this->post('/interact/translation/collect', [
            'items' => array_map(static function ($item): array {
                return [
                    'module' => (string) $item->module,
                    'key_name' => (string) $item->key_name,
                    'source_text' => (string) $item->source_text,
                ];
            }, $batch->items),
        ]);
    }

    /**
     * 按游标拉取远程翻译包增量。
     */
    public function fetchPackageIncremental(FetchPackageIncrementalRequestDto $request): FetchPackageIncrementalResultDto
    {
        $data = $this->post('/interact/translation/package/incremental', [
            'module' => $request->module,
            'locale' => $request->locale,
            'cursor' => $request->cursor,
            'limit' => $request->limit,
        ]);

        return FetchPackageIncrementalResultDto::from($data);
    }

    /**
     * 读取远程系统配置的同步目标语言。
     */
    public function fetchSyncTargets(): SyncTargetsDto
    {
        $data = $this->post('/interact/translation/sync-targets', []);

        return SyncTargetsDto::from($data);
    }

    /**
     * 统一封装所有 POST 请求，减少错误处理分散在各个方法里。
     *
     * @return array<string, mixed>
     */
    private function post(string $uri, array $payload): array
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('translation_sdk.gateway.base_url 不能为空');
        }
        if ($this->systemToken === '') {
            throw new RuntimeException('translation_sdk.gateway.system_token 不能为空');
        }

        // 远程接口统一使用 JSON body + 系统级 token。
        $response = $this->http
            ->baseUrl(rtrim($this->baseUrl, '/'))
            ->timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-System-Token' => $this->systemToken,
            ])
            ->post($uri, $payload);

        if ($response->failed()) {
            throw new RuntimeException('translation gateway 请求失败: ' . $response->status());
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException('translation gateway 响应格式错误');
        }

        // 远程系统使用业务 state，而不是只看 HTTP 200/500。
        $state = (string) ($body['state'] ?? '');
        if ($state !== self::SUCCESS_STATE) {
            $msg = (string) ($body['msg'] ?? 'translation gateway 调用失败');
            throw new RuntimeException($msg);
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}
