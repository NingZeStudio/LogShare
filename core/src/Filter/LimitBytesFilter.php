<?php

namespace Filter;

class LimitBytesFilter extends Filter
{
    public static function filter(string $data): string
    {
        $config = \Config::Get('storage');
        $maxLength = $config['maxLength'];

        if (strlen($data) > $maxLength) {
            throw new \Exception('日志大小超过限制（最大 ' . round($maxLength / 1024 / 1024, 2) . 'MB），请手动裁剪后再上传');
        }

        return $data;
    }
}
