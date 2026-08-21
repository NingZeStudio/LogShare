<?php

namespace App\Controller;

use App\ApiError;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class RawController extends AbstractController
{
    #[GetMapping(path: 'raw/{id}')]
    public function raw(string $id): ResponseInterface
    {
        return $this->serve($id, null);
    }

    #[GetMapping(path: 'raw/{id}/{filename:.+}')]
    public function rawFile(string $id, string $filename): ResponseInterface
    {
        return $this->serve($id, $filename);
    }

    private function serve(string $id, ?string $filename): ResponseInterface
    {
        $logId = new \App\Id($id);
        $log = new \App\Log($logId);

        if (!$log->exists()) {
            throw new ApiError(404, "Log not found.");
        }

        $log->renew();

        if ($filename !== null) {
            $content = $log->getFile($filename);
            if ($content === null) {
                throw new ApiError(404, "File not found.");
            }
            return $this->respondText($content, 'text/plain');
        }

        return $this->respondText($log->getContent(), 'text/plain');
    }
}
