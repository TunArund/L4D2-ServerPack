-- MySQL dump 10.13  Distrib 8.0.45, for Linux (x86_64)
--
-- Host: localhost    Database: steam
-- ------------------------------------------------------
-- Server version	8.0.45-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `comments`
--

DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `map_id` int unsigned NOT NULL,
  `grade` float DEFAULT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `map_id` (`map_id`),
  CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`map_id`) REFERENCES `maps` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--

--
-- Table structure for table `download_tasks`
--

DROP TABLE IF EXISTS `download_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `download_tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `map_id` int unsigned NOT NULL,
  `downlink` varchar(256) NOT NULL,
  `disk_safe` varchar(256) NOT NULL,
  `status` enum('waiting','downloading','fail','success') NOT NULL DEFAULT 'waiting',
  `downloaded_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `total_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `download_tasks_ibfk_1` (`map_id`),
  CONSTRAINT `download_tasks_ibfk_1` FOREIGN KEY (`map_id`) REFERENCES `maps` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=442 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--

--
-- Table structure for table `emails`
--

DROP TABLE IF EXISTS `emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `emails` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `vericode` char(5) NOT NULL,
  `expire` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--

--
-- Table structure for table `map_request_users`
--

DROP TABLE IF EXISTS `map_request_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `map_request_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `request_id` int unsigned NOT NULL COMMENT '地图申请ID（map_requests.id）',
  `user_id` int unsigned NOT NULL COMMENT '用户ID（users.id）',
  `associated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立关联时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_request_user` (`request_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `map_request_users_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `map_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `map_request_users_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=344 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='用户与地图申请关联表';
/*!40101 SET character_set_client = @saved_cs_client */;

--

--
-- Table structure for table `map_requests`
--

DROP TABLE IF EXISTS `map_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `map_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `steam_id` bigint unsigned NOT NULL,
  `title` varchar(256) DEFAULT NULL,
  `downlink` varchar(256) DEFAULT NULL,
  `status` enum('rejected','pending','approved') NOT NULL DEFAULT 'rejected' COMMENT '状态',
  `explaination` text COMMENT '说明备注（如审核时间过长等）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '首次提交时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后更新时间',
  `description` text COMMENT 'HTML 说明',
  `link` varchar(256) DEFAULT NULL,
  `preview_url` varchar(256) DEFAULT NULL COMMENT '一个缩略图链接',
  `disk_safe` varchar(256) DEFAULT NULL COMMENT '可安全存储的文件名',
  `size` int unsigned DEFAULT NULL COMMENT '以字节为单位，最大2047MB',
  `is_map` tinyint(1) DEFAULT '1',
  `in_maps` tinyint(1) DEFAULT '0' COMMENT '对应地图在maps表中是否已经存在',
  `version` int unsigned DEFAULT NULL COMMENT '对应地图更新时间戳(自1970以来的秒数)',
  `subscriptions` int DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=340 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='地图申请主表';
/*!40101 SET character_set_client = @saved_cs_client */;

--

--
-- Table structure for table `maps`
--

DROP TABLE IF EXISTS `maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maps` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(256) DEFAULT NULL,
  `link` varchar(256) DEFAULT NULL,
  `description` text,
  `img_urls` text,
  `subscriptions` int DEFAULT '0',
  `records` json DEFAULT NULL,
  `status` enum('abandon','updating','active') NOT NULL DEFAULT 'abandon',
  `steam_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '首次提交时间',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后更新时间',
  `disk_safe` varchar(256) DEFAULT NULL COMMENT '可安全存储的文件名',
  `downlink` varchar(256) DEFAULT NULL,
  `size` int unsigned DEFAULT NULL COMMENT '以字节为单位，最大2047MB',
  `is_map` tinyint(1) DEFAULT '1',
  `version` int unsigned DEFAULT NULL COMMENT '地图实际更新时间戳',
  `preview_url` varchar(256) DEFAULT NULL COMMENT '一个缩略图的url',
  `cos_url` varchar(512) DEFAULT NULL COMMENT 'COS 对象公网访问 URL',
  `cos_version` int unsigned DEFAULT NULL COMMENT '已上传至 COS 的地图版本（与 version 比较判断是否需要重新上传）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `steam_id` (`steam_id`),
  UNIQUE KEY `steam_id_2` (`steam_id`)
) ENGINE=InnoDB AUTO_INCREMENT=470 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=228 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `hashpass` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `email` varchar(100) NOT NULL,
  `role` enum('guest','admin') DEFAULT 'guest',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--

--
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-11 20:51:45
