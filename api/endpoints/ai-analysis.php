<?php

require_once("../../core/core.php");

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Content-Type: application/json');

try {
    RequestValidator::validateMethod('GET');
    $logId = RequestValidator::extractId('/1/ai-analysis/');
} catch (ApiError $e) {
    $e->output();
}

$id = new Id($logId);
$log = new Log($id);

if (!$log->exists()) {
    ApiResponse::error('Log not found', 404);
}

// 加载 AI 配置
$configPath = __DIR__ . '/../../core/config/ai.php';
$aiConfig = require($configPath);
$apiKey = trim($aiConfig['gemini_api_key']);
$model = $aiConfig['model'];

if (strpos($apiKey, 'YOUR_API_KEY') !== false || empty($apiKey)) {
    ApiResponse::error('Please configure your Gemini API Key in core/config/ai.php', 500);
}

// 获取日志内容
$logData = $log->get();
if (!$logData) {
    ApiResponse::error('Could not read log data', 500);
}
$logContent = $logData->getLogfile()->getContent();

// 提取显著行以保持 prompt 大小合理并专注于错误
$lines = explode("\n", $logContent);
$significantLines = [];
foreach ($lines as $line) {
    if (preg_match('/(error|exception|warn|fail|caused by|critical)/i', $line)) {
        $significantLines[] = trim($line);
        if (count($significantLines) >= 50) break;
    }
}

// 如果没有找到明显错误，取最后 50 行
if (empty($significantLines)) {
    $significantLines = array_slice($lines, -50);
}

$prompt = "You are an expert Minecraft Server Log Analyzer.\n";
$prompt .= "Your answer must be in simplified Chinese\n";
$prompt .= "Analyze the following Minecraft server log and identify the root cause of any crashes or errors.\n";
$prompt .= "Suggest specific, actionable solutions to fix the issues.\n\n";
$prompt .= "### Log Excerpt (Errors/Warnings):\n" . implode("\n", $significantLines) . "\n\n";
$prompt .= "### End of Log (Context):\n" . substr($logContent, -3000) . "\n\n";
$prompt .= "Provide your analysis in Markdown. Be professional, concise, and helpful.";

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ]
];

$jsonPayload = json_encode($payload);
if ($jsonPayload === false) {
    ApiResponse::error('Failed to encode payload JSON: ' . json_last_error_msg(), 500);
}

$ch = curl_init("https://gemini.zeink.cc/v1beta/models/$model:generateContent?key=$apiKey");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($httpCode === 200) {
    $json = json_decode($response, true);
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        ApiResponse::success([
            'analysis' => $json['candidates'][0]['content']['parts'][0]['text']
        ], 'AI analysis completed');
    } else {
        ApiResponse::error('AI returned an empty response. Response: ' . $response, 500);
    }
} else {
    $errorMsg = "AI Request Failed (HTTP $httpCode).";
    if ($curlError) {
        $errorMsg .= " Curl Error: $curlError";
    }

    if ($response) {
        $errData = json_decode($response, true);
        if (isset($errData['error']['message'])) {
            $errorMsg .= " Google API Message: " . $errData['error']['message'];
        } else {
            $errorMsg .= " Response: " . substr($response, 0, 200);
        }
    }
    ApiResponse::error($errorMsg, 500);
}

