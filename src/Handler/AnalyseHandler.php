<?php

namespace Handler;

class AnalyseHandler extends \Handler
{
    public function handle(): void
    {
        try {
            $this->validateMethod('POST');
        } catch (\ApiError $e) {
            $e->output();
        }

        $contentResult = $this->parseContent();
        $contentResult = $this->validateContentExists($contentResult);

        $content = is_array($contentResult) ? $contentResult['content'] : $contentResult;

        $log = new \Log();

        try {
            $log->setData($content);
        } catch (\Exception $e) {
            $error = new \ApiError(400, $e->getMessage());
            $error->output();
        }

        $log->analyse();

        $codexLog = $log->get();
        $codexLog->setIncludeEntries(false);

        $this->respondJson($codexLog);
    }
}
