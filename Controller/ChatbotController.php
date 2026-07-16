<?php
/**
 * ChatbotController.php
 * ------------------------------------------------------------
 * 纯 function 写法，不使用 class。
 * 职责：入口，对应「接收 request」这一步（不属于 9.1-9.4，
 * 是整个 chatbot 流程的门面）。
 *
 *   1. 处理 CORS / HTTP headers
 *   2. 接收前端 POST 来的 question
 *   3. 做基本输入验证（空值、超长文本）
 *   4. 调用 handleQuestion()（ChatbotLogic.php）处理业务逻辑
 *   5. try-catch 兜底任何未预期异常，统一转成规范 JSON 响应
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/ChatbotLogic.php';

const MAX_QUESTION_LENGTH = 1000; // 超长文本上限，可依需求调整

/**
 * 处理 chatbot 问答的 HTTP 请求入口
 * 直接 echo JSON 并设置好 headers，不返回值
 */
function handleAskRequest(): void
{
    setChatbotHeaders();

    // OPTIONS 预检请求直接放行（CORS）
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        return;
    }

    try {
        $validation = validateQuestionInput();

        if (!$validation['valid']) {
            sendChatbotError($validation['error'], 400);
            return;
        }

        $result = handleQuestion($validation['question']);

        if (!$result['success']) {
            handleChatbotLogicError($result['error']);
            return;
        }

        sendChatbotSuccess($result['answerText'], $result['recommendedMaterials']);

    } catch (PDOException $e) {
        error_log('[ChatbotController] DB error: ' . $e->getMessage());
        sendChatbotError('资料查询失败，请稍后再试。', 500);

    } catch (Throwable $e) {
        // 兜底：任何没预料到的错误，都不能让原始报错泄漏给前端
        error_log('[ChatbotController] Unexpected error: ' . $e->getMessage());
        sendChatbotError('服务器发生未知错误，请稍后再试。', 500);
    }
}

/**
 * 根据 ChatbotLogic 返回的 error code，转成对应的 HTTP 状态码和提示语
 * @param string $errorCode
 */
function handleChatbotLogicError(string $errorCode): void
{
    $knownGeminiErrors = [
        'API_KEY_MISSING',
        'NETWORK_ERROR',
        'API_BAD_STATUS',
        'INVALID_JSON',
        'UNEXPECTED_RESPONSE_SHAPE',
    ];

    if (in_array($errorCode, $knownGeminiErrors, true)) {
        // Gemini API 调用失败：服务端依赖出问题，用 502（Bad Gateway）
        sendChatbotError('AI 服务暂时无法响应，请稍后再试。', 502);
        return;
    }

    // 其他未分类错误
    sendChatbotError('服务器发生错误，请稍后再试。', 500);
}

/**
 * 设置 CORS 与 Content-Type headers
 */
function setChatbotHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Content-Type: application/json; charset=UTF-8');
}

/**
 * 从 request body 取出并校验 question 字段
 * @return array{valid: bool, question?: string, error?: string}
 */
function validateQuestionInput(): array
{
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    // 同时兼容表单提交 $_POST，方便前端 debug 用 Postman 测试
    $question = $data['question'] ?? $_POST['question'] ?? null;

    if ($question === null || trim((string) $question) === '') {
        return ['valid' => false, 'error' => '问题不能为空'];
    }

    $question = trim((string) $question);

    if (mb_strlen($question) > MAX_QUESTION_LENGTH) {
        return [
            'valid' => false,
            'error' => '问题长度超过限制（最多 ' . MAX_QUESTION_LENGTH . ' 字）',
        ];
    }

    return ['valid' => true, 'question' => $question];
}

/**
 * 输出成功响应
 * @param string $answerText
 * @param array $recommendedMaterials
 */
function sendChatbotSuccess(string $answerText, array $recommendedMaterials): void
{
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'answerText' => $answerText,
        'recommendedMaterials' => $recommendedMaterials,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 输出统一格式的错误响应
 * @param string $message 给前端展示的错误信息（不能包含内部细节）
 * @param int $httpCode HTTP 状态码
 */
function sendChatbotError(string $message, int $httpCode): void
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * ------------------------------------------------------------
 * 用法：ask.php 现在应该只需要这样写
 * ------------------------------------------------------------
 *
 *   <?php
 *   require_once __DIR__ . '/ChatbotController.php';
 *   handleAskRequest();
 *
 * 前端继续 POST 到 ask.php 即可，接口行为不变。
 * ------------------------------------------------------------
 */