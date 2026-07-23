<?php

namespace Handler;

class FiltersHandler extends \Handler
{
    public function handle(): void
    {
        try {
            $this->validateMethod('GET');
        } catch (\ApiError $e) {
            $e->output();
        }

        $filters = [
            [
                'type' => 'trim',
                'data' => null
            ],
            [
                'type' => 'limit-bytes',
                'data' => [
                    'limit' => \Config::Get('storage')['maxLength']
                ]
            ],
            [
                'type' => 'limit-lines',
                'data' => [
                    'limit' => \Config::Get('storage')['maxLines']
                ]
            ],
            [
                'type' => 'regex',
                'data' => [
                    'patterns' => [
                        [
                            'pattern' => 'IPv4',
                            'replacement' => '**.**.**.**'
                        ],
                        [
                            'pattern' => 'IPv6',
                            'replacement' => '****:****:****:****:****:****:****:****'
                        ],
                        [
                            'pattern' => 'Username',
                            'replacement' => '********'
                        ],
                        [
                            'pattern' => 'AccessToken',
                            'replacement' => '********'
                        ]
                    ]
                ]
            ]
        ];

        $this->respondJson([
            'success' => true,
            'filters' => $filters
        ]);
    }
}
