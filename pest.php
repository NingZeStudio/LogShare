<?php

declare(strict_types=1);

use Pest\TestCase;
use App\Data\Token;
use App\Filter\Filter;
use App\Filter\UuidFilter;
use App\Filter\XuidFilter;
use App\Filter\SessionTokenFilter;
use App\Filter\ClientIdFilter;
use App\Filter\CoordinateFilter;
use App\Filter\IPv4Filter;
use App\Filter\IPv6Filter;
use App\Filter\IPv6ShortFilter;
use App\Filter\UsernameFilter;
use App\Filter\AccessTokenFilter;
use App\Filter\TrimFilter;
use App\Filter\LimitBytesFilter;
use App\Filter\LimitLinesFilter;

return [
    'test_case' => TestCase::class,
    'bootstraps' => [
        __DIR__ . '/tests/bootstrap.php',
    ],
    'coverage' => [
        'include' => [
            'app/*',
        ],
        'exclude' => [
            'app/Data/*',
            'app/Cache/*',
            'app/Client/*',
            'app/Storage/*',
        ],
    ],
    'architectural' => [
        'namespace' => 'App',
        'rules' => [
            'no direct use of \$_SERVER in handlers',
            'no direct use of \$_GET/\$_POST in handlers',
        ],
    ],
];
