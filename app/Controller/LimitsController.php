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
            'storageTime' => $config['storageTime'],
            'maxLength' => $config['maxLength'],
            'maxLines' => $config['maxLines'],
        ]);
    }
}
