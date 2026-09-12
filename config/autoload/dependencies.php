<?php

declare(strict_types=1);

return [
    Hyperf\HttpMessage\Server\RequestParserInterface::class => App\Parser\RequestParser::class,
];
