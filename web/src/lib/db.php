<?php
// ============================================================
// 数据库操作辅助
// core.php 已由 bootstrap.php 自动加载
// ============================================================

/**
 * 检查数据库是否可用
 */
function alive_db(PDO $pdo):bool{
  try{
    $stmt = $pdo->query("SELECT 1");
    return $stmt !== false;
  } catch (PDOException $e) {
    return false;
  }
}

/**
 * 安全执行 prepared statement
 * @return array ['success' => true, 'data' => $stmt] or ['success' => false, 'message' => ...]
 */
function exec_stmt($stmt,...$params){
  try{
    $stmt->execute($params);
  }catch(PDOException $e){
    return array_error($e->getMessage());
  }
  return array_success($stmt);
}

/**
 * 带重连的 PDO 执行（用于长时间运行的 daemon）
 */
function safe_execute(PDO &$pdo, string $query, array $params = [], int $retry = 3)
{
    for ($i = 0; $i < $retry; $i++) {
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("数据库连接丢失，尝试重连..." . $e->getMessage());
            $pdo = conn_db();
        }
    }
    return false;
}
