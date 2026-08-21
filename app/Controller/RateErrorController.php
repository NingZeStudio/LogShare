<?php

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class RateErrorController extends AbstractController
{
    #[GetMapping(path: 'errors/rate')]
    public function rate(): ResponseInterface
    {
        return $this->respondError(
            "Unfortunately you have exceeded the rate limit for the current time period. Please try again later.",
            429
        );
    }
}
