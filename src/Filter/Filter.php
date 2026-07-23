<?php

namespace Filter;

abstract class Filter
{
    /**
     * @var Filter[]|null
     */
    protected static ?array $filter = null;

    /**
     * Get all filters
     *
     * @return Filter[]
     */
    public static function getAll(): array
    {
        if (static::$filter !== null) {
            return static::$filter;
        }
        return static::$filter = [
            new TrimFilter(),
            new LimitBytesFilter(),
            new LimitLinesFilter(),
            new IPv4Filter(),
            new IPv6Filter(),
            new UsernameFilter(),
            new AccessTokenFilter(),
        ];
    }

    /**
     * Filter the $data string with all filters and return it
     *
     * @param string $data
     * @return string
     */
    public static function filterAll(string $data): string
    {
        foreach (static::getAll() as $filter) {
            $data = $filter::filter($data);
        }
        return $data;
    }

    /**
     * Filter the $data string and return it
     *
     * @param string $data
     * @return string
     */
    abstract public static function filter(string $data): string;
}
