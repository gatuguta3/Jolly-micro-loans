/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.13-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: jolly-micro-loans
-- ------------------------------------------------------
-- Server version	10.11.13-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_code` varchar(20) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) NOT NULL,
  `id_number` varchar(50) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `id_document_url` varchar(255) DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `uploads` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`uploads`)),
  `status` enum('active','inactive','blacklisted') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `join_date` date NOT NULL DEFAULT curdate(),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_client_code` (`client_code`),
  UNIQUE KEY `uq_clients_id_number` (`id_number`),
  UNIQUE KEY `uq_clients_phone` (`phone`),
  KEY `idx_clients_email` (`email`),
  KEY `idx_clients_status` (`status`),
  KEY `idx_clients_join_date` (`join_date`),
  KEY `fk_clients_created_by` (`created_by`),
  CONSTRAINT `fk_clients_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clients`
--

LOCK TABLES `clients` WRITE;
/*!40000 ALTER TABLE `clients` DISABLE KEYS */;
INSERT INTO `clients` VALUES
(1,'JML-0001','Joseph Gitoru','gatuguta3joseph@gmail.com','0703513093','40090900','2026-09-30',NULL,NULL,NULL,NULL,NULL,'active','fdsfsdfdsfdsf','2026-09-30',2,'2026-09-30 07:44:23','2026-09-30 07:44:23'),
(2,'JML-0002','Janice wambui','gatuguta3joseph@gmail.com','0703513094','40090901',NULL,NULL,NULL,NULL,NULL,NULL,'active',NULL,'2026-10-01',2,'2026-10-01 10:51:01','2026-10-01 10:51:01'),
(3,'JML-0003','Jane wangui','wangui34jane@gmail.com','0703513095','83939334',NULL,NULL,'Kitengela , Kajiado',NULL,NULL,NULL,'active',NULL,'2026-10-01',2,'2026-10-01 10:54:05','2026-10-01 10:54:05'),
(4,'JML-0004','jonathan warui','jonathan@gmail.com','0703513098','8393933',NULL,NULL,'jkj',NULL,NULL,NULL,'active',NULL,'2026-10-02',2,'2026-10-02 16:51:42','2026-10-02 16:51:42'),
(5,'JML-0005','fenel ochieng','fenelalulu@gmail.com','0703513097','6575775',NULL,NULL,'dsgdfgfdgfd',NULL,NULL,NULL,'active',NULL,'2026-10-02',2,'2026-10-02 16:53:50','2026-10-02 16:53:50'),
(6,'JML-0006','job Maoga','','0103513098','12345670',NULL,NULL,'githunguri',NULL,NULL,NULL,'active',NULL,'2026-10-03',2,'2026-10-03 07:27:57','2026-10-03 07:27:57'),
(7,'JML-0007','jacinta wangoo','','0721345980','24092240',NULL,NULL,'kimbo',NULL,NULL,NULL,'active',NULL,'2026-10-03',2,'2026-10-03 13:54:41','2026-10-03 13:54:41'),
(8,'JML-0008','Weston Kiprop ngetich','kiprop@gmail.com','0789045789','90056700',NULL,NULL,'Ruiru',NULL,NULL,NULL,'active',NULL,'2026-10-03',2,'2026-10-03 13:57:37','2026-10-03 15:26:14');
/*!40000 ALTER TABLE `clients` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb3 */ ;
/*!50003 SET character_set_results = utf8mb3 */ ;
/*!50003 SET collation_connection  = utf8mb3_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_clients_set_code`
BEFORE INSERT ON `clients`
FOR EACH ROW
BEGIN
  IF NEW.`client_code` IS NULL OR NEW.`client_code` = '' THEN
    SET NEW.`client_code` = CONCAT(
      'JML-',
      LPAD(
        (SELECT IFNULL(MAX(CAST(SUBSTRING(`client_code`, 5) AS UNSIGNED)), 0) + 1
         FROM `clients`
         WHERE `client_code` LIKE 'JML-%'),
        4, '0'
      )
    );
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `guarantors`
--

DROP TABLE IF EXISTS `guarantors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `guarantors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `type` enum('individual','chama') NOT NULL DEFAULT 'individual',
  `identification_type` enum('id_no','passport','other') NOT NULL DEFAULT 'id_no',
  `identification_number` varchar(50) DEFAULT NULL,
  `attachment_url` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `date_added` date NOT NULL DEFAULT curdate(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_guarantors_email` (`email`),
  KEY `idx_guarantors_type` (`type`),
  KEY `idx_guarantors_date_added` (`date_added`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `guarantors`
--

LOCK TABLES `guarantors` WRITE;
/*!40000 ALTER TABLE `guarantors` DISABLE KEYS */;
INSERT INTO `guarantors` VALUES
(1,'Jonathan','gatuguta3joseph@gmail.com','+254712122743','individual','passport','40090900','20261001_071827_dca6d0a4be8ec1f8.png',NULL,'active','2026-10-01','2026-10-01 07:18:28','2026-10-01 07:18:28'),
(2,'Joseph Gitoru gatuguta','gatuguta3joseph@gmail.com','0703513093','individual','passport','6756756',NULL,NULL,'active','2026-10-02','2026-10-02 16:52:58','2026-10-03 15:28:17');
/*!40000 ALTER TABLE `guarantors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_guarantors`
--

DROP TABLE IF EXISTS `loan_guarantors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_guarantors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `loan_id` bigint(20) unsigned NOT NULL,
  `guarantor_id` bigint(20) unsigned NOT NULL,
  `guaranteed_amount` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loan_guarantor` (`loan_id`,`guarantor_id`),
  KEY `idx_lg_guarantor_id` (`guarantor_id`),
  CONSTRAINT `fk_lg_guarantor` FOREIGN KEY (`guarantor_id`) REFERENCES `guarantors` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_lg_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_guarantors`
--

LOCK TABLES `loan_guarantors` WRITE;
/*!40000 ALTER TABLE `loan_guarantors` DISABLE KEYS */;
INSERT INTO `loan_guarantors` VALUES
(1,1,1,50000.00,'2026-10-01 07:19:52'),
(2,2,1,10000.00,'2026-10-01 07:20:35'),
(3,3,1,30000.00,'2026-10-01 07:21:50'),
(4,4,1,40000.00,'2026-10-01 07:28:17'),
(5,5,1,10000.00,'2026-10-01 07:30:06'),
(6,6,1,6000.00,'2026-10-01 07:31:21'),
(7,7,1,5000.00,'2026-10-01 07:32:05'),
(8,8,1,20000.00,'2026-10-01 07:55:21'),
(9,9,1,30000.00,'2026-10-01 07:59:12'),
(10,10,1,5000.00,'2026-10-01 08:02:21'),
(11,12,2,543634.00,'2026-10-02 16:54:20'),
(12,13,2,23500.00,'2026-10-02 16:57:38'),
(13,14,1,100000.00,'2026-10-02 17:58:39'),
(14,15,2,50000.00,'2026-10-02 18:04:44'),
(15,16,1,5000.00,'2026-10-02 18:16:33');
/*!40000 ALTER TABLE `loan_guarantors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loans`
--

DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `loan_code` varchar(20) NOT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `loan_category` enum('monthly','daily','boost') NOT NULL DEFAULT 'monthly',
  `date_issued` date NOT NULL DEFAULT curdate(),
  `principal_amount` decimal(12,2) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `duration` int(10) unsigned NOT NULL DEFAULT 1,
  `interest_accrued` decimal(12,2) NOT NULL DEFAULT 0.00,
  `interest_amount` decimal(12,2) GENERATED ALWAYS AS (round(`principal_amount` * `interest_rate` / 100,2)) STORED,
  `total_due` decimal(12,2) GENERATED ALWAYS AS (round(`principal_amount` + `principal_amount` * `interest_rate` / 100,2)) STORED,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) GENERATED ALWAYS AS (round(`principal_amount` + `principal_amount` * `interest_rate` / 100 - `amount_paid`,2)) STORED,
  `due_date` date NOT NULL,
  `grace_days` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','overdue','defaulted','cleared','written_off') NOT NULL DEFAULT 'active',
  `collateral_type` enum('none','vehicle','land','household_items','livestock','business_stock','salary','other') NOT NULL DEFAULT 'none',
  `collateral_description` varchar(255) DEFAULT NULL,
  `uploads` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`uploads`)),
  `purpose` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loans_loan_code` (`loan_code`),
  KEY `idx_loans_client_id` (`client_id`),
  KEY `idx_loans_status` (`status`),
  KEY `idx_loans_due_date` (`due_date`),
  CONSTRAINT `fk_loans_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loans`
--

LOCK TABLES `loans` WRITE;
/*!40000 ALTER TABLE `loans` DISABLE KEYS */;
INSERT INTO `loans` VALUES
(1,'LN-0001',1,'monthly','2026-10-01',50000.00,20.00,2,0.00,10000.00,60000.00,50000.00,10000.00,'2026-12-01',0,'active','none',NULL,'[\"20261001_071952_31480ac8217c868e.png\"]','Monthly Loan',NULL,'2026-10-01 07:19:52','2026-10-03 07:07:59'),
(2,'LN-0002',1,'monthly','2026-07-04',10000.00,20.00,1,0.00,2000.00,12000.00,12000.00,0.00,'2026-08-04',0,'cleared','none',NULL,'[\"20261001_072035_e5a88b0240a2e456.png\"]','Monthly Loan',NULL,'2026-10-01 07:20:35','2026-10-01 10:27:25'),
(3,'LN-0003',1,'monthly','2026-09-17',30000.00,20.00,2,0.00,6000.00,36000.00,0.00,36000.00,'2026-09-19',0,'overdue','none',NULL,'[\"20261001_072150_10420ffdd2b151f3.png\"]','Daily Loan',NULL,'2026-10-01 07:21:50','2026-10-02 17:57:20'),
(4,'LN-0004',1,'monthly','2025-07-01',40000.00,2.50,1,0.00,1000.00,41000.00,0.00,41000.00,'2025-07-02',0,'defaulted','none',NULL,'[\"20261001_072817_3d77830fabe7e847.png\"]','Daily Loan',NULL,'2026-10-01 07:28:17','2026-10-01 07:28:17'),
(5,'LN-0005',1,'monthly','2026-06-30',10000.00,2.50,1,0.00,250.00,10250.00,0.00,10250.00,'2026-07-01',0,'defaulted','none',NULL,'[\"20261001_073006_7680097eda3e339c.png\"]','Daily Loan',NULL,'2026-10-01 07:30:06','2026-10-01 07:30:06'),
(6,'LN-0006',1,'monthly','2026-07-31',6000.00,2.50,1,0.00,150.00,6150.00,0.00,6150.00,'2026-08-01',0,'defaulted','none',NULL,'[\"20261001_073121_d60d5827f26c1143.png\"]','Daily Loan',NULL,'2026-10-01 07:31:21','2026-10-01 07:31:21'),
(7,'LN-0007',1,'monthly','2026-08-01',5000.00,2.50,1,0.00,125.00,5125.00,0.00,5125.00,'2026-08-02',0,'defaulted','none',NULL,'[\"20261001_073205_53f75f1edd71cfac.png\"]','Daily Loan',NULL,'2026-10-01 07:32:05','2026-10-02 09:48:14'),
(8,'LN-0008',1,'monthly','2026-09-15',20000.00,0.00,5,0.00,0.00,20000.00,20000.00,0.00,'2026-09-20',0,'cleared','none',NULL,'[\"20261001_075521_4da9a441a2d52566.png\"]','Jolly Boost Loan',NULL,'2026-10-01 07:55:21','2026-10-01 10:24:12'),
(9,'LN-0009',1,'monthly','2026-09-27',30000.00,20.00,1,0.00,6000.00,36000.00,23000.00,13000.00,'2026-09-28',0,'overdue','none',NULL,'[\"20261001_075912_faa332ae6dfa09b3.png\"]','Daily Loan',NULL,'2026-10-01 07:59:12','2026-10-03 07:18:15'),
(10,'LN-0010',1,'daily','2026-09-27',5000.00,2.50,1,0.00,125.00,5125.00,0.00,5125.00,'2026-09-28',0,'defaulted','none',NULL,'[\"20261001_080221_c7ff26f29be318df.png\"]','Daily Loan',NULL,'2026-10-01 08:02:21','2026-10-01 08:02:21'),
(11,'LN-0011',2,'monthly','2026-10-02',60000.00,20.00,1,0.00,12000.00,72000.00,0.00,72000.00,'2026-11-02',0,'active','none',NULL,'[\"20261002_163617_2828e00fc781a605.png\"]','Monthly Loan',NULL,'2026-10-02 16:36:17','2026-10-02 16:36:17'),
(12,'LN-0012',5,'monthly','2026-10-02',543634.00,15.00,2,0.00,81545.10,625179.10,625179.10,0.00,'2026-12-02',0,'cleared','none',NULL,NULL,'Monthly Loan',NULL,'2026-10-02 16:54:20','2026-10-03 08:37:32'),
(13,'LN-0013',4,'daily','2026-09-27',23500.00,2.50,1,0.00,587.50,24087.50,24087.00,0.50,'2026-09-28',0,'defaulted','none',NULL,NULL,'Daily Loan',NULL,'2026-10-02 16:57:38','2026-10-03 08:28:44'),
(14,'LN-0014',3,'monthly','2026-08-01',100000.00,40.00,2,0.00,40000.00,140000.00,0.00,140000.00,'2026-10-01',0,'overdue','none',NULL,NULL,'Monthly Loan',NULL,'2026-10-02 17:58:39','2026-10-02 17:58:39'),
(15,'LN-0015',4,'monthly','2026-07-01',50000.00,40.00,2,0.00,20000.00,70000.00,0.00,70000.00,'2026-09-01',0,'overdue','none',NULL,NULL,'Monthly Loan',NULL,'2026-10-02 18:04:44','2026-10-02 18:04:44'),
(16,'LN-0016',5,'monthly','2026-06-03',5000.00,20.00,2,0.00,1000.00,6000.00,0.00,6000.00,'2026-08-03',0,'overdue','none',NULL,NULL,'Monthly Loan',NULL,'2026-10-02 18:16:33','2026-10-02 18:16:33');
/*!40000 ALTER TABLE `loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notification_reads`
--

DROP TABLE IF EXISTS `notification_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_reads` (
  `user_id` bigint(20) unsigned NOT NULL,
  `loan_id` bigint(20) unsigned NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`loan_id`),
  KEY `idx_nr_loan` (`loan_id`),
  CONSTRAINT `fk_nr_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_nr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notification_reads`
--

LOCK TABLES `notification_reads` WRITE;
/*!40000 ALTER TABLE `notification_reads` DISABLE KEYS */;
INSERT INTO `notification_reads` VALUES
(2,9,'2026-09-29 23:25:28'),
(2,17,'2026-09-30 07:00:41');
/*!40000 ALTER TABLE `notification_reads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_code` varchar(20) DEFAULT NULL,
  `client_id` bigint(20) unsigned NOT NULL,
  `loan_id` bigint(20) unsigned NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL DEFAULT curdate(),
  `mode_of_payment` enum('cash','mpesa','bank_transfer','cheque','card','other') NOT NULL DEFAULT 'cash',
  `reference_number` varchar(100) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `upload` varchar(255) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_payment_code` (`payment_code`),
  KEY `idx_payments_client_id` (`client_id`),
  KEY `idx_payments_loan_id` (`loan_id`),
  KEY `idx_payments_date` (`payment_date`),
  KEY `idx_payments_mode` (`mode_of_payment`),
  CONSTRAINT `fk_payments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES
(1,'PY-0001',1,8,20000.00,'2026-10-01','mpesa',NULL,NULL,NULL,'2026-10-01 10:24:12','2026-10-01 10:24:12'),
(2,'PY-0002',1,2,12000.00,'2026-10-01','bank_transfer',NULL,NULL,NULL,'2026-10-01 10:27:25','2026-10-01 10:27:25'),
(3,'PY-0003',1,1,50000.00,'2026-10-03','cash',NULL,NULL,NULL,'2026-10-03 07:07:59','2026-10-03 07:07:59'),
(4,'PY-0004',1,9,23000.00,'2026-10-03','mpesa',NULL,NULL,NULL,'2026-10-03 07:18:15','2026-10-03 07:18:15'),
(5,'PY-0005',4,13,20000.00,'2026-10-03','cash',NULL,NULL,NULL,'2026-10-03 07:41:28','2026-10-03 07:41:28'),
(6,'PY-0006',5,12,500000.00,'2026-10-03','mpesa',NULL,NULL,NULL,'2026-10-03 07:49:29','2026-10-03 07:49:29'),
(7,'PY-0007',4,13,4087.00,'2026-10-03','mpesa',NULL,NULL,NULL,'2026-10-03 08:28:44','2026-10-03 08:28:44'),
(8,'PY-0008',5,12,125179.10,'2026-10-03','mpesa',NULL,NULL,NULL,'2026-10-03 08:37:32','2026-10-03 08:37:32');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb3 */ ;
/*!50003 SET character_set_results = utf8mb3 */ ;
/*!50003 SET collation_connection  = utf8mb3_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_payments_set_code`
BEFORE INSERT ON `payments`
FOR EACH ROW
BEGIN
  IF NEW.`payment_code` IS NULL OR NEW.`payment_code` = '' THEN
    SET NEW.`payment_code` = CONCAT(
      'PY-',
      LPAD(
        (SELECT IFNULL(MAX(CAST(SUBSTRING(`payment_code`, 4) AS UNSIGNED)), 0) + 1
         FROM `payments`
         WHERE `payment_code` LIKE 'PY-%'),
        4, '0'
      )
    );
  END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('user','loan_officer','admin') NOT NULL DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(2,'Elizabeth wangui','jollykitchen@gmail.com','+254712345678','$2y$10$.uwgLiyZFnqHue/zkRKFoeK.7xmMVKazmDQ8nskMwKMCYj9AL9vry','admin','2026-09-27 17:59:27');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-03 18:48:10
