<?php

namespace App\Controller;

use App\ApiError;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Psr\Http\Message\ResponseInterface;

#[Controller(prefix: '/{version:v?1}')]
class AnalyseController extends AbstractController
{
    #[PostMapping(path: 'analyse')]
    public function analyse(): ResponseInterface
    {
        $contentResult = $this->parseContent();
        $contentResult = $this->validateContentExists($contentResult);

        $content = is_array($contentResult) ? $contentResult['content'] : $contentResult;

        $log = new \App\Log();

        try {
            $log->setData($content);
        } catch (\Exception $e) {
            throw new ApiError(400, $e->getMessage());
        }

        $log->analyse();

        $codexLog = $log->get();
        $codexLog->setIncludeEntries(false);

        return $this->respondJson($codexLog);
    }
}
