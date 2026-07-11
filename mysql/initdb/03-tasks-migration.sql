-- download_tasks → tasks 统一任务表迁移

RENAME TABLE download_tasks TO tasks;

-- 新增列
ALTER TABLE tasks
  ADD COLUMN type ENUM('download','upload') NOT NULL DEFAULT 'download' AFTER id,
  ADD COLUMN src VARCHAR(512) NOT NULL DEFAULT '' AFTER map_id,
  ADD COLUMN dst VARCHAR(512) NOT NULL DEFAULT '' AFTER src;

-- 迁移旧数据：downlink → src, disk_safe → dst（下载场景）
UPDATE tasks SET src = downlink, dst = CONCAT(disk_safe, '.vpk');

-- 修改状态枚举（新增 uploading）
ALTER TABLE tasks
  MODIFY COLUMN status ENUM('waiting','downloading','uploading','success','fail') NOT NULL DEFAULT 'waiting';

-- 重命名进度字段
ALTER TABLE tasks
  CHANGE COLUMN downloaded_bytes processed_bytes BIGINT UNSIGNED NOT NULL DEFAULT '0';

-- 删除旧列
ALTER TABLE tasks
  DROP COLUMN downlink,
  DROP COLUMN cos_key;

DROP TABLE IF EXISTS cos_upload_tasks;
