<?php

namespace Filter;

class TrimFilter extends Filter
{
    public static function filter(string $data): string
    {
        return trim($data);
    }
}
