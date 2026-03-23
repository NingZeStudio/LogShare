<?php

namespace Filter;

class LimitBytesFilter extends Filter
{
    public static function filter(string $data): string
    {
        $config = \Config::Get('storage');
        return mb_strcut($data, 0, $config['maxLength']);
    }
}
