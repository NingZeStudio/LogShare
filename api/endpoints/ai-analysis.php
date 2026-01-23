<?php

require_once("../../core/core.php");

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: *');
header('Content-Type: application/json');

$urlId = substr($_SERVER['REQUEST_URI'], strlen("/1/ai-analysis/"));
$idStr = explode('?', $urlId)[0];
$id = new Id($idStr);
$log = new Log($id);

if(!$log->exists()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'analysis' => 'Log not found']);
    exit;
}

// Load Config
$configPath = __DIR__ . '/../../core/config/ai.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'analysis' => 'AI Configuration missing']);
    exit;
}
$aiConfig = require($configPath);
$apiKey = trim($aiConfig['gemini_api_key']);
$model = $aiConfig['model'];

if (strpos($apiKey, 'YOUR_API_KEY') !== false || empty($apiKey)) {
    echo json_encode(['success' => false, 'analysis' => 'Please configure your Gemini API Key in core/config/ai.php']);
    exit;
}

// Get Log Content
$logData = $log->get();
if (!$logData) {
     http_response_code(500);
     echo json_encode(['success' => false, 'analysis' => 'Could not read log data']);
     exit;
}
$logContent = $logData->getLogfile()->getContent();

// Extract significant lines to keep prompt size reasonable and focus on errors
$lines = explode("\n", $logContent);
$significantLines = [];
foreach ($lines as $line) {
    if (preg_match('/(error|exception|warn|fail|caused by|critical)/i', $line)) {
        $significantLines[] = trim($line);
        if (count($significantLines) >= 50) break; 
    }
}

// If no obvious errors found via keywords, take the last 50 lines which usually contain the crash info
if (empty($significantLines)) {
    $significantLines = array_slice($lines, -50);
}

$prompt = "You are an expert Minecraft Server Log Analyzer.\n";
$prompt = "Your answer must be in simplified Chinese\n";
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
    echo json_encode(['success' => false, 'analysis' => 'Failed to encode payload JSON: ' . json_last_error_msg()]);
    exit;
}

$ch = curl_init("https://gemini.zeink.cc/v1beta/models/$model:generateContent?key=$apiKey");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
// Disable SSL verification for development environments if needed, but risky. 
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($httpCode === 200) {
    $json = json_decode($response, true);
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        echo json_encode([
            'success' => true,
            'analysis' => $json['candidates'][0]['content']['parts'][0]['text']
        ]);
    } else {
        echo json_encode(['success' => false, 'analysis' => 'AI returned an empty response. Response: ' . $response]);
    }
} else {
    $errorMsg = "AI Request Failed (HTTP $httpCode).";
    if ($curlError) {
        $errorMsg .= " Curl Error: $curlError";
    }
    
    // Debug info
    $debug = " [Key Length: " . strlen($apiKey) . ", Key Prefix: " . substr($apiKey, 0, 4) . "***]";

    if ($response) {
        $errData = json_decode($response, true);
        if (isset($errData['error']['message'])) {
            $errorMsg .= " Google API Message: " . $errData['error']['message'];
        } else {
             $errorMsg .= " Response: " . substr($response, 0, 200);
        }
    }
    echo json_encode(['success' => false, 'analysis' => $errorMsg . $debug]);
}

