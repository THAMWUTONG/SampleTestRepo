<?php
/**
 * GeminiService.php
 * ------------------------------------------------------------
 * 纯 function 写法，不使用 class。
 * 职责：对应 DFD 9.2 Call Gemini API。
 * 专门处理跟 Gemini API 的 cURL 通信、API key 读取、超时与失败处理。
 *
 * 不用 throw exception，改用返回值 ['success'=>bool, ...] 的方式
 * 把结果/错误往上传给 ChatbotLogic.php 判断，符合纯 function 风格。
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/Env.php';

/**
 * 调用 Gemini API，传入组好的 prompt package（对应 9.1 输出的 Prompt Package）
 *
 * @param string $systemPrompt 系统提示词（角色设定、回答规范等）
 * @param string $studentQuestion 学生的问题
 * @return array{success: bool, text?: string, error?: string}
 */
function callGeminiApi(string $systemPrompt, string $studentQuestion): array
{
    $apiKey = (string) env('GEMINI_API_KEY', '');
    $apiUrl = (string) env(
        'GEMINI_API_URL',
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent'
    );
    $timeoutSeconds = (int) env('GEMINI_TIMEOUT_SECONDS', 15);

    if ($apiKey === '') {
        error_log('[GeminiService] GEMINI_API_KEY 未设置，请检查 .env 文件');
        return ['success' => false, 'error' => 'API_KEY_MISSING'];
    }

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $systemPrompt . "\n\n学生问题：" . $studentQuestion],
                ],
            ],
        ],
    ];

    $url = $apiUrl . '?key=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('[GeminiService] cURL 失败: ' . $curlError);
        return ['success' => false, 'error' => 'NETWORK_ERROR'];
    }

    if ($httpCode !== 200) {
        error_log("[GeminiService] Gemini API 返回非 200: {$httpCode}, body: {$response}");
        return ['success' => false, 'error' => 'API_BAD_STATUS'];
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('[GeminiService] Gemini API 返回内容不是合法 JSON');
        return ['success' => false, 'error' => 'INVALID_JSON'];
    }

    // Gemini 标准返回结构：candidates[0].content.parts[0].text
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

    if ($text === null) {
        error_log('[GeminiService] Gemini API 返回结构异常，找不到 answer 文本');
        return ['success' => false, 'error' => 'UNEXPECTED_RESPONSE_SHAPE'];
    }

    return ['success' => true, 'text' => $text];
}