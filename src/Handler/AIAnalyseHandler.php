<?php

namespace Handler;

class AIAnalyseHandler extends \Handler
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

        if (empty($content)) {
            $error = new \ApiError(400, "Content is required.");
            $error->output();
        }

        \Client\AIClient::analyzeStream($content, "ai:analysis:hash:" . hash('sha256', $content));
    }
}
