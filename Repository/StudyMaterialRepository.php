<?php
/**
 * StudyMaterialRepository.php
 * ------------------------------------------------------------
 * 纯 function 写法，不使用 class。
 * 职责：对应 DFD 9.4 Generate Recommendation 中「查数据库」这一部分。
 * 唯一负责跟 study_materials 表打交道的地方，
 * 数据库连接直接在 function 里面调用 getDbConnection() 拿。
 *
 * 表结构参考 aquarius-database-schema.sql：
 *   study_materials(id, title, description, file_name, file_path,
 *                    file_type, topic_id, uploaded_by,
 *                    regulation_status, uploaded_at)
 *   topics(id, course_id, title, description, order_index)
 * 注意：没有 topic 字符串字段，topic_id 是外键，要 JOIN topics 表
 * 才能拿到主题名字做关键词匹配。
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/db.php';

/**
 * 根据关键词查相关资料（匹配 title / description / 关联的 topic 标题）
 * 只返回 regulation_status = 'approved' 的资料，未审核通过的不推荐给学生。
 *
 * @param string $keyword
 * @param int $limit
 * @return array{success: bool, data?: array, error?: string}
 */
function findMaterialsByKeyword(string $keyword, int $limit = 3): array
{
    if (trim($keyword) === '') {
        return ['success' => true, 'data' => []];
    }

    $pdo = getDbConnection();

    $sql = "SELECT sm.id, sm.title, sm.file_path
            FROM study_materials sm
            LEFT JOIN topics t ON sm.topic_id = t.id
            WHERE sm.regulation_status = 'approved'
              AND (sm.title LIKE :keyword OR sm.description LIKE :keyword OR t.title LIKE :keyword)
            ORDER BY sm.uploaded_at DESC
            LIMIT :limit";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':keyword', '%' . $keyword . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['success' => true, 'data' => $stmt->fetchAll()];
    } catch (PDOException $e) {
        error_log('[StudyMaterialRepository] findMaterialsByKeyword failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'DB_QUERY_FAILED'];
    }
}

/**
 * 找不到匹配资料时的兜底：返回最新审核通过的几份资料
 *
 * @param int $limit
 * @return array{success: bool, data?: array, error?: string}
 */
function findRecentMaterials(int $limit = 3): array
{
    $pdo = getDbConnection();

    $sql = "SELECT sm.id, sm.title, sm.file_path
            FROM study_materials sm
            WHERE sm.regulation_status = 'approved'
            ORDER BY sm.uploaded_at DESC
            LIMIT :limit";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['success' => true, 'data' => $stmt->fetchAll()];
    } catch (PDOException $e) {
        error_log('[StudyMaterialRepository] findRecentMaterials failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'DB_QUERY_FAILED'];
    }
}