<?php

namespace App\Handler;

class AnalyseHandler extends \App\Handler
{
    public function handle(): void
    {
        $this->validateMethod('POST');

        $contentResult = $this->parseContent();
        $contentResult = $this->validateContentExists($contentResult);

        $content = is_array($contentResult) ? $contentResult['content'] : $contentResult;

        $log = new \App\Log();

        try {
            $log->setData($content);
        } catch (\Exception $e) {
            $error = new \App\ApiError(400, $e->getMessage());
            $error->output();
        }

        $log->analyse();

        $codexLog = $log->get();
        $codexLog->setIncludeEntries(false);

        $this->respondJson($codexLog);
    }
}
