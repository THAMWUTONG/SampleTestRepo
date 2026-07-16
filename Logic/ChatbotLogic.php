<?php
/**
 * ChatbotLogic.php
 * ------------------------------------------------------------
 * 纯 function 写法，不使用 class。
 * 职责：
 *   - 对应 DFD 9.1 Receive & Build Prompt：把学生问题 + system prompt 组装好
 *   - 对应 DFD 9.3 Format & Deliver Answer：处理 Gemini 返回的原始内容
 *   - 编排整个流程：callGeminiApi()（9.2）→ findMaterialsByKeyword()（9.4）
 *
 * 文件之间通过 require_once 互相引入，用到别的文件的功能
 * 直接调用对方的 function 名字，不做依赖注入。
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/GeminiService.php';
require_once __DIR__ . '/StudyMaterialRepository.php';

/**
 * 处理一次完整的问答流程
 *
 * @param string $studentQuestion 学生提问（已经过 Controller 基本校验）
 * @return array{success: bool, answerText?: string, recommendedMaterials?: array, error?: string}
 */
function handleQuestion(string $studentQuestion): array
{
    // ---- 9.1 Receive & Build Prompt ----
    $systemPrompt = buildSystemPrompt();

    // ---- 9.2 Call Gemini API ----
    $geminiResult = callGeminiApi($systemPrompt, $studentQuestion);

    if (!$geminiResult['success']) {
        return ['success' => false, 'error' => $geminiResult['error']];
    }

    // ---- 9.3 Format & Deliver Answer ----
    $formattedAnswer = formatAnswer($geminiResult['text']);

    // ---- 9.4 Generate Recommendation ----
    $keyword = extractKeyword($studentQuestion);
    $materialResult = findMaterialsByKeyword($keyword);

    if (!$materialResult['success']) {
        // 查资料失败不应该让整个问答失败，答案照样给学生，资料列表留空
        error_log('[ChatbotLogic] findMaterialsByKeyword failed: ' . $materialResult['error']);
        $materials = [];
    } elseif (empty($materialResult['data'])) {
        // 兜底：查不到相关资料时，给最近上传的资料，而不是空着
        $recentResult = findRecentMaterials();
        $materials = $recentResult['success'] ? $recentResult['data'] : [];
    } else {
        $materials = $materialResult['data'];
    }

    return [
        'success' => true,
        'answerText' => $formattedAnswer,
        'recommendedMaterials' => formatMaterials($materials),
    ];
}

/**
 * 固定的 system prompt，设定 chatbot 的角色和回答规范
 * @return string
 */
function buildSystemPrompt(): string
{
    return <<<PROMPT
你是一个教学助教 chatbot，负责用简洁、准确、易懂的方式回答学生的学习问题。
回答时请遵守：
1. 用中文或学生提问所用的语言回答。
2. 尽量给出结构化、分点的解释。
3. 不确定的内容要明确说明"不确定"，不要编造。
PROMPT;
}

/**
 * 整理 Gemini 返回的原始文字：去掉多余的 markdown 符号、多余空行等
 * @param string $rawAnswer
 * @return string
 */
function formatAnswer(string $rawAnswer): string
{
    $text = trim($rawAnswer);
    // 去掉多余连续空行
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return $text;
}

/**
 * 从学生问题里提取一个简单关键词，用于匹配 study_materials
 * 目前用最朴素的方式（取问题前 20 字符），
 * 之后可以升级成关键词抽取或 embedding 匹配。
 * @param string $question
 * @return string
 */
function extractKeyword(string $question): string
{
    $cleaned = trim($question);
    return mb_substr($cleaned, 0, 20);
}

/**
 * 把数据库查出来的资料转成前端需要的格式（id/title/file_path）
 * @param array $materials
 * @return array
 */
function formatMaterials(array $materials): array
{
    return array_map(function (array $material) {
        return [
            'id' => 'MAT' . str_pad((string) $material['id'], 3, '0', STR_PAD_LEFT),
            'title' => $material['title'],
            'file_path' => $material['file_path'],
        ];
    }, $materials);
}