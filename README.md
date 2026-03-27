# translation-laravel-sdk

Laravel 翻译 SDK，支持以下能力：
- 主动采集：扫描代码中的翻译 key / 文本并批量上报
- 被动采集：运行时 miss 进入缓冲区并按批次 flush
- 翻译包同步：按 locale + cursor 增量拉取并写入本地缓存

## 安装
1. 通过 Composer 引入包
2. 发布配置：
```bash
php artisan vendor:publish --tag=translation-sdk-config
```

## 命令
- `php artisan translation-sdk:collect-active`
- `php artisan translation-sdk:flush-missing`
- `php artisan translation-sdk:sync-package`

`sync-package` 默认行为：
- 自动从网关读取目标 locale（`/interact/translation/sync-targets`）
- 按 locale 持久化 cursor 做增量同步
- `--full` 从 cursor `0` 全量拉取
- `--cursor` 可一次性覆盖起始 cursor（调试用，不写回持久 cursor）

## 运行时翻译
支持 Laravel 原生入口：

```php
__('validation.required');
trans('order.status.pending');
trans_choice('order.items', 3);
```

运行时流程：
- 先查 Laravel 本地 lang
- 未命中时查 SDK 同步缓存
- 仍未命中则上报被动采集并返回原始 key（保持 Laravel 行为）

## 测试
```bash
composer install
composer test
```
