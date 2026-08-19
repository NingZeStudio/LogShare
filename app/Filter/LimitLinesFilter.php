<?php

namespace App\Filter;

class LimitLinesFilter extends Filter
{
    public static function filter(string $data): string
    {
        $config = \App\Config::Get('storage');
        $maxLines = $config['maxLines'];
        $lines = explode("\n", $data);

        if (count($lines) > $maxLines) {
            throw new \Exception('日志行数超过限制（最大 ' . number_format($maxLines) . ' 行），请手动裁剪后再上传');
        }

        return $data;
    }
}
