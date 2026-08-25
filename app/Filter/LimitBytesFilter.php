<?php

namespace App\Filter;

use App\ApiError;

class LimitBytesFilter extends Filter
{
    public static function filter(string $data): string
    {
        $config = \App\Config::Get('storage');
        $maxLength = (int) ($config['maxLength'] ?? (10 * 1024 * 1024));

        if (strlen($data) > $maxLength) {
            throw new ApiError(400, '日志大小超过限制（最大 ' . round($maxLength / 1024 / 1024, 2) . 'MB），请手动裁剪后再上传');
        }

        return $data;
    }
}
