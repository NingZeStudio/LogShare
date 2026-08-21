<?php

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class FiltersController extends AbstractController
{
    #[GetMapping(path: 'filters')]
    public function filters(): ResponseInterface
    {
        $filters = [
            [
                'type' => 'trim',
                'data' => null,
            ],
            [
                'type' => 'limit-bytes',
                'data' => [
                    'limit' => \App\Config::Get('storage')['maxLength'],
                ],
            ],
            [
                'type' => 'limit-lines',
                'data' => [
                    'limit' => \App\Config::Get('storage')['maxLines'],
                ],
            ],
            [
                'type' => 'regex',
                'data' => [
                    'patterns' => [
                        ['pattern' => 'IPv4', 'replacement' => '**.**.**.**'],
                        ['pattern' => 'IPv6', 'replacement' => '****:****:****:****:****:****:****:****'],
                        ['pattern' => 'Username', 'replacement' => '********'],
                        ['pattern' => 'AccessToken', 'replacement' => '********'],
                    ],
                ],
            ],
        ];

        return $this->respondJson([
            'success' => true,
            'filters' => $filters,
        ]);
    }
}
