<?php

declare(strict_types=1);

namespace App\Controller;

use App\Telemetry\TelemetryService;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

/**
 * 客户端前端遥测数据上报控制器（公开端点）。
 *
 * 接收来自 Web 前端轻量遥测 SDK 的性能与错误批量指标上报。
 */
#[Controller(prefix: '/{version:v?1}/telemetry')]
class TelemetryController extends AbstractController
{
    #[PostMapping(path: 'report')]
    public function report(): ResponseInterface
    {
        $body = $this->request->getParsedBody();

        /** @var array<int, mixed> $items */
        $items = [];
        if (is_array($body)) {
            if (isset($body['items']) && is_array($body['items'])) {
                $items = array_values($body['items']);
            } elseif (array_is_list($body)) {
                $items = $body;
            } elseif (isset($body['type'])) {
                // 单条上报直接包成单元素数组
                $items = [$body];
            }
        }

        $processed = TelemetryService::recordBatch($items);

        return $this->respondSuccess([
            'processed' => $processed,
        ], 'Telemetry metrics recorded');
    }
}
