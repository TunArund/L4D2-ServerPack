-- download_tasks → tasks 统一任务表迁移（幂等，可重复执行）
-- 存量环境已执行过部分迁移的也能安全补全

-- Step 1: 如果 download_tasks 还存在则改名
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'download_tasks') > 0,
    'RENAME TABLE download_tasks TO tasks',
    'SELECT "download_tasks already migrated, skip rename" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 2: 补充 type 列
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'type') = 0,
    'ALTER TABLE tasks ADD COLUMN type ENUM(''download'',''upload'') NOT NULL DEFAULT ''download'' AFTER id',
    'SELECT "type column already exists, skip" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 3: 补充 src 列
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'src') = 0,
    'ALTER TABLE tasks ADD COLUMN src VARCHAR(512) NOT NULL DEFAULT '''' AFTER map_id',
    'SELECT "src column already exists, skip" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 4: 补充 dst 列
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'dst') = 0,
    'ALTER TABLE tasks ADD COLUMN dst VARCHAR(512) NOT NULL DEFAULT '''' AFTER src',
    'SELECT "dst column already exists, skip" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 5: 从旧 downlink 列迁移数据到 src（如果有 downlink 列且 src 为空）
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'downlink') > 0,
    'UPDATE tasks SET src = COALESCE(downlink, ''''), dst = CONCAT(disk_safe, ''.vpk'') WHERE src = '''' OR src IS NULL',
    'SELECT "downlink already migrated, skip" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 6: 修正 status 枚举（补充 uploading）
ALTER TABLE tasks MODIFY COLUMN status
    ENUM('waiting','downloading','uploading','success','fail') NOT NULL DEFAULT 'waiting';

-- Step 7: 重命名进度字段
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'downloaded_bytes') > 0,
    'ALTER TABLE tasks CHANGE COLUMN downloaded_bytes processed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'SELECT "processed_bytes already renamed, skip" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 8: 删除废弃列（如有）
SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'downlink') > 0,
    'ALTER TABLE tasks DROP COLUMN downlink',
    'SELECT "downlink already dropped, skip" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'cos_key') > 0,
    'ALTER TABLE tasks DROP COLUMN cos_key',
    'SELECT "cos_key already dropped, skip" AS msg'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 9: 清理旧表
DROP TABLE IF EXISTS cos_upload_tasks;

-- 完成
SELECT 'Migration completed' AS result;
SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'steam' AND TABLE_NAME = 'tasks'
ORDER BY ORDINAL_POSITION;
