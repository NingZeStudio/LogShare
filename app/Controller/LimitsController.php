<?php

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class LimitsController extends AbstractController
{
    #[GetMapping(path: 'limits')]
    public function limits(): ResponseInterface
    {
        $config = \App\Config::Get('storage');

        return $this->respondJson([
            'storageTime' => (int) ($config['storageTime'] ?? (7 * 24 * 60 * 60)),
            'maxLength' => (int) ($config['maxLength'] ?? (10 * 1024 * 1024)),
            'maxLines' => (int) ($config['maxLines'] ?? 50_000),
        ]);
    }
}
