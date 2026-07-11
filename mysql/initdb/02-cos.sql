-- ============================================================
-- Tencent COS 集成 — 为 maps 表添加 COS 相关字段
-- 每日凌晨 3 点批量上传 active 地图到 COS，版本号比较避免重复上传
-- ============================================================

ALTER TABLE `maps`
  ADD COLUMN `cos_url` varchar(512) DEFAULT NULL COMMENT 'COS 对象公网访问 URL'
  AFTER `preview_url`;

ALTER TABLE `maps`
  ADD COLUMN `cos_version` int unsigned DEFAULT NULL COMMENT '已上传至 COS 的地图版本（与 version 比较判断是否需要重新上传）'
  AFTER `cos_url`;
