<?php

namespace Filter;

class LimitLinesFilter extends Filter
{
    public static function filter(string $data): string
    {
        $config = \Config::Get('storage');
        return implode("\n", array_slice(explode("\n", $data), 0, $config['maxLines']));
    }
}
