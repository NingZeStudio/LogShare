<?php

$config = [

    /**
     * Filters applied before saving the log
     *
     * The classes should use static filter() method
     */
    'pre' => [
        '\\Filter\\TrimFilter',
        '\\Filter\\LimitBytesFilter',
        '\\Filter\\LimitLinesFilter',
        '\\Filter\\IPv4Filter',
        '\\Filter\\IPv6Filter',
        '\\Filter\\UsernameFilter',
        '\\Filter\\AccessTokenFilter'
    ],
];