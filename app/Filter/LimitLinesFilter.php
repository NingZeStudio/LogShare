<?php

namespace App\Filter;

use App\ApiError;

class LimitLinesFilter extends Filter
{
    public static function filter(string $data): string
    {
        $config = \App\Config::Get('storage');
        $maxLines = (int) ($config['maxLines'] ?? 50_000);
        $lines = explode("\n", $data);

        if (count($lines) > $maxLines) {
            throw new ApiError(400, '日志行数超过限制（最大 ' . number_format($maxLines) . ' 行），请手动裁剪后再上传');
        }

        return $data;
    }
}
