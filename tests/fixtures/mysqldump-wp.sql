-- MySQL dump 10.13  Distrib 8.0.46, for Linux (aarch64)
--
-- Host: localhost    Database: fixture
-- ------------------------------------------------------
-- Server version	8.0.46

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
-- Current Database: `fixture`
--



--
-- Table structure for table `wpa_child`
--

DROP TABLE IF EXISTS `wpa_child`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wpa_child` (
  `id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `wpa_child_fk` FOREIGN KEY (`parent_id`) REFERENCES `wpa_parent` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wpa_child`
--

LOCK TABLES `wpa_child` WRITE;
/*!40000 ALTER TABLE `wpa_child` DISABLE KEYS */;
INSERT INTO `wpa_child` VALUES (10,1),(20,2);
/*!40000 ALTER TABLE `wpa_child` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wpa_options`
--

DROP TABLE IF EXISTS `wpa_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wpa_options` (
  `option_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `option_value` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `autoload` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wpa_options`
--

LOCK TABLES `wpa_options` WRITE;
/*!40000 ALTER TABLE `wpa_options` DISABLE KEYS */;
INSERT INTO `wpa_options` VALUES (1,'siteurl','http://old.test','yes'),(2,'home','http://old.test','yes'),(3,'blog_public','1','yes'),(4,'widget_text','a:1:{i:2;a:1:{s:4:\"text\";s:28:\"<a href=\"http://old.test/x\">\";}}','yes'),(5,'wpa_user_roles','a:1:{s:13:\"administrator\";a:1:{s:4:\"name\";s:13:\"Administrator\";}}','yes'),(6,'active_plugins','a:0:{}','yes'),(7,'siteferry_job','A-job','no');
/*!40000 ALTER TABLE `wpa_options` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wpa_parent`
--

DROP TABLE IF EXISTS `wpa_parent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wpa_parent` (
  `id` int NOT NULL,
  `name` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wpa_parent`
--

LOCK TABLES `wpa_parent` WRITE;
/*!40000 ALTER TABLE `wpa_parent` DISABLE KEYS */;
INSERT INTO `wpa_parent` VALUES (1,'uno'),(2,'dos');
/*!40000 ALTER TABLE `wpa_parent` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `wpa_posts`
--

DROP TABLE IF EXISTS `wpa_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wpa_posts` (
  `ID` bigint unsigned NOT NULL AUTO_INCREMENT,
  `post_content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `guid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wpa_posts`
--

LOCK TABLES `wpa_posts` WRITE;
/*!40000 ALTER TABLE `wpa_posts` DISABLE KEYS */;
INSERT INTO `wpa_posts` VALUES (1,'<!-- wp:image {\"url\":\"http:\\/\\/old.test\\/a.jpg\"} --><img src=\"http://old.test/a.jpg\">','0000-00-00 00:00:00','http://old.test/?p=1'),(2,'Hola mundo 🚀','2024-01-01 10:00:00','http://old.test/?p=2');
/*!40000 ALTER TABLE `wpa_posts` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_0900_ai_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `wpa_posts_bi` BEFORE INSERT ON `wpa_posts` FOR EACH ROW BEGIN SET NEW.guid = NEW.guid; END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `wpa_usermeta`
--

DROP TABLE IF EXISTS `wpa_usermeta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wpa_usermeta` (
  `umeta_id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_value` longtext COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`umeta_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `wpa_usermeta`
--

LOCK TABLES `wpa_usermeta` WRITE;
/*!40000 ALTER TABLE `wpa_usermeta` DISABLE KEYS */;
INSERT INTO `wpa_usermeta` VALUES (1,1,'wpa_capabilities','a:1:{s:13:\"administrator\";b:1;}'),(2,1,'nickname','admin');
/*!40000 ALTER TABLE `wpa_usermeta` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `wpa_view`
--

DROP TABLE IF EXISTS `wpa_view`;
/*!50001 DROP VIEW IF EXISTS `wpa_view`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `wpa_view` AS SELECT 
 1 AS `ID`*/;
SET character_set_client = @saved_cs_client;

--
-- Current Database: `fixture`
--


--
-- Final view structure for view `wpa_view`
--

/*!50001 DROP VIEW IF EXISTS `wpa_view`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `wpa_view` AS select `wpa_posts`.`ID` AS `ID` from `wpa_posts` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-26 17:48:40
