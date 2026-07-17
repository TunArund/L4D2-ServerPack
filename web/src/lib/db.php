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

// ============================================================
// 通用查询辅助函数（替代 BaseModel 的继承层次）
// ============================================================

/**
 * 查询多行
 * @return array ['success' => true, 'data' => array[]]
 */
function db_fetch_all(string $sql, array $params = []): array
{
    try {
        $stmt = conn_db()->prepare($sql);
        $result = exec_stmt($stmt, ...$params);
        if (!$result['success']) return $result;
        return array_success($result['data']->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        return array_error($e->getMessage());
    }
}

/**
 * 查询单行
 * @return array ['success' => true, 'data' => array|null]
 */
function db_fetch_one(string $sql, array $params = []): array
{
    try {
        $stmt = conn_db()->prepare($sql);
        $result = exec_stmt($stmt, ...$params);
        if (!$result['success']) return $result;
        $row = $result['data']->fetch(PDO::FETCH_ASSOC);
        return array_success($row ?: null);
    } catch (PDOException $e) {
        return array_error($e->getMessage());
    }
}

/**
 * 查询单个标量值
 * @return array ['success' => true, 'data' => mixed]
 */
function db_fetch_column(string $sql, array $params = [], int $column = 0): array
{
    try {
        $stmt = conn_db()->prepare($sql);
        $result = exec_stmt($stmt, ...$params);
        if (!$result['success']) return $result;
        $row = $result['data']->fetch(PDO::FETCH_NUM);
        return array_success($row[$column] ?? null);
    } catch (PDOException $e) {
        return array_error($e->getMessage());
    }
}

/**
 * 执行 INSERT 并返回 lastInsertId
 * @return array ['success' => true, 'data' => int|string]
 */
function db_insert(string $sql, array $params = []): array
{
    try {
        $stmt = conn_db()->prepare($sql);
        $result = exec_stmt($stmt, ...$params);
        if (!$result['success']) return $result;
        return array_success(conn_db()->lastInsertId());
    } catch (PDOException $e) {
        return array_error($e->getMessage());
    }
}

/**
 * 执行 UPDATE/DELETE 并返回受影响行数
 * @return array ['success' => true, 'data' => int]
 */
function db_execute_write(string $sql, array $params = []): array
{
    try {
        $stmt = conn_db()->prepare($sql);
        $result = exec_stmt($stmt, ...$params);
        if (!$result['success']) return $result;
        return array_success($result['data']->rowCount());
    } catch (PDOException $e) {
        return array_error($e->getMessage());
    }
}

/**
 * 动态 ORDER BY 白名单校验
 */
function db_validate_order_by(string $column, array $allowed, string $default = 'id'): string
{
    return in_array($column, $allowed, true) ? $column : $default;
}

/**
 * 动态排序方向白名单校验
 */
function db_validate_order(string $order): string
{
    $order = strtoupper($order);
    return in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
}
