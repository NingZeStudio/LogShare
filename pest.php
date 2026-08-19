<?php

declare(strict_types=1);

use Pest\TestCase;
use Data\Token;
use Filter\Filter;
use Filter\UuidFilter;
use Filter\XuidFilter;
use Filter\SessionTokenFilter;
use Filter\ClientIdFilter;
use Filter\CoordinateFilter;
use Filter\IPv4Filter;
use Filter\IPv6Filter;
use Filter\IPv6ShortFilter;
use Filter\UsernameFilter;
use Filter\AccessTokenFilter;
use Filter\TrimFilter;
use Filter\LimitBytesFilter;
use Filter\LimitLinesFilter;

return [
    'test_case' => TestCase::class,
    'bootstraps' => [
        __DIR__ . '/tests/bootstrap.php',
    ],
    'coverage' => [
        'include' => [
            'src/*',
        ],
        'exclude' => [
            'src/Data/*',
            'src/Cache/*',
            'src/Client/*',
            'src/Storage/*',
        ],
    ],
    'architectural' => [
        'namespace' => 'LogShare',
        'rules' => [
            'no direct use of \$_SERVER in handlers',
            'no direct use of \$_GET/\$_POST in handlers',
        ],
    ],
];