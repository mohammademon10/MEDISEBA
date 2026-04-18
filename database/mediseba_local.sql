-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: mediseba_local
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

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
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activity_user_id` (`user_id`),
  KEY `idx_activity_entity` (`entity_type`,`entity_id`),
  KEY `idx_activity_created_at` (`created_at`),
  CONSTRAINT `activity_logs_ibfk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
INSERT INTO `activity_logs` VALUES (1,NULL,'user_modification_trigger','user',5,'{\"status\": \"active\", \"role\": \"doctor\"}','{\"status\": \"active\", \"role\": \"admin\"}',NULL,NULL,'2026-04-16 13:59:00');
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_chat_messages`
--

DROP TABLE IF EXISTS `appointment_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointment_chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `sender_user_id` bigint(20) unsigned NOT NULL,
  `sender_role` enum('patient','doctor','admin') NOT NULL,
  `message_text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_chat_appointment_id` (`appointment_id`),
  KEY `idx_chat_sender_user_id` (`sender_user_id`),
  KEY `idx_chat_appointment_message_id` (`appointment_id`,`id`),
  CONSTRAINT `appointment_chat_messages_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_chat_messages_ibfk_2` FOREIGN KEY (`sender_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_chat_messages`
--

LOCK TABLES `appointment_chat_messages` WRITE;
/*!40000 ALTER TABLE `appointment_chat_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_chat_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_status_history`
--

DROP TABLE IF EXISTS `appointment_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointment_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` bigint(20) unsigned DEFAULT NULL,
  `changed_by_type` enum('patient','doctor','admin','system') NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_appointment` (`appointment_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_changed_by` (`changed_by`),
  CONSTRAINT `appointment_status_history_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_status_history_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_status_history`
--

LOCK TABLES `appointment_status_history` WRITE;
/*!40000 ALTER TABLE `appointment_status_history` DISABLE KEYS */;
INSERT INTO `appointment_status_history` VALUES (1,3,'pending','confirmed',NULL,'system','Trigger generated status modification log','2026-04-16 11:34:15');
/*!40000 ALTER TABLE `appointment_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_number` varchar(50) NOT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `doctor_id` bigint(20) unsigned NOT NULL,
  `schedule_id` bigint(20) unsigned NOT NULL,
  `appointment_date` date NOT NULL,
  `token_number` int(10) unsigned NOT NULL,
  `estimated_time` time DEFAULT NULL,
  `status` enum('pending','confirmed','in_progress','completed','cancelled','no_show') DEFAULT 'pending',
  `cancellation_reason` text DEFAULT NULL,
  `cancelled_by` enum('patient','doctor','system') DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `symptoms` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `appointment_number` (`appointment_number`),
  UNIQUE KEY `unique_doctor_date_token` (`doctor_id`,`appointment_date`,`token_number`),
  KEY `schedule_id` (`schedule_id`),
  KEY `idx_patient` (`patient_id`),
  KEY `idx_doctor_date` (`doctor_id`,`appointment_date`),
  KEY `idx_status` (`status`),
  KEY `idx_appointment_date` (`appointment_date`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patient_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctor_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointments_ibfk_3` FOREIGN KEY (`schedule_id`) REFERENCES `doctor_schedules` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (1,'APT-20260403-A54A7F',1,1,5,'2026-04-03',1,'17:00:00','completed',NULL,NULL,NULL,'Joy bangla','I have aids','2026-04-02 20:23:06','2026-04-02 21:30:48'),(3,'APT-20260416-820048',3,1,4,'2026-04-16',1,NULL,'confirmed',NULL,NULL,NULL,NULL,'','2026-04-16 11:34:11','2026-04-16 11:34:15');
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_appointment_status_audit
AFTER UPDATE ON appointments
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status THEN
        INSERT INTO appointment_status_history (
            appointment_id, 
            old_status, 
            new_status, 
            changed_by_type, 
            notes
        ) VALUES (
            NEW.id, 
            OLD.status, 
            NEW.status, 
            'system', 
            'Trigger generated status modification log'
        );
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `doctor_profiles`
--

DROP TABLE IF EXISTS `doctor_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doctor_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `specialty` varchar(100) NOT NULL,
  `qualification` text NOT NULL,
  `experience_years` int(10) unsigned DEFAULT 0,
  `consultation_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `clinic_name` varchar(255) DEFAULT NULL,
  `clinic_address` text DEFAULT NULL,
  `clinic_latitude` decimal(10,8) DEFAULT NULL,
  `clinic_longitude` decimal(11,8) DEFAULT NULL,
  `profile_photo` varchar(500) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `languages` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `registration_number` varchar(100) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `average_rating` decimal(3,2) NOT NULL DEFAULT 5.00,
  `total_reviews` int(10) unsigned NOT NULL DEFAULT 0,
  `total_appointments` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doctor_user_id` (`user_id`),
  UNIQUE KEY `uniq_doctor_slug` (`slug`),
  KEY `idx_doctor_specialty` (`specialty`),
  KEY `idx_doctor_verified` (`is_verified`),
  KEY `idx_doctor_featured` (`is_featured`),
  KEY `idx_doctor_created_at` (`created_at`),
  CONSTRAINT `doctor_profiles_ibfk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctor_profiles`
--

LOCK TABLES `doctor_profiles` WRITE;
/*!40000 ALTER TABLE `doctor_profiles` DISABLE KEYS */;
INSERT INTO `doctor_profiles` VALUES (id ,user_id ,full_name ,slug ,specialty ,qualification ,experience_years ,consultation_fee ,clinic_name ,clinic_address ,clinic_latitude ,clinic_longitude ,profile_photo ,bio ,languages ,registration_number ,is_verified ,is_featured ,average_rating ,total_reviews ,total_appointments ,created_at ,updated_at )
(1,1,'Md. Emon Hossain','Md.-emon- Hossain','Cardiologist','MBBS',5,500.00,'Noakhali Medical College Hospital','Noakhali, Bangladesh',NULL,NULL,'uploads/profile-photos/doctors/doctors-9-62f2bc8f1afd773f.jpg','Laziness is the mother of invention','[\"English\"]','12345',1,0,5.00,0,0,'2026-04-02 20:05:14','2026-04-02 21:45:15'),
(3,6,'Dr. Antu','Antu-doctor','General Medicine','MBBS, FCPS',6,1000.00,'Pabna Medecal Center','pabna','pabna, Bangladesh','uploads\profile-photos\doctors\doctors-1-c7d5a70699bffabd.jpg',NULL,NULL,NULL,NULL,1,0,5.00,0,0,'2026-04-16 11:58:53','2026-04-16 12:05:06');
( 4,7,'Dr.Tanizina','Tanizina-doctor','General Medicine','MBBS, FCPS',6,1000.00,'Dhaka Medical College Hospital','Dhaka','Dhaka, Bangladesh','uploads\profile-photos\doctors\doctors-5-63a19f7779e01988.jpeg',NULL,NULL,NULL,NULL,1,0,5.00,0,0,'2026-04-16 12:00:00','2026-04-16 12:10:00');
/*!40000 ALTER TABLE `doctor_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doctor_reviews`
--

DROP TABLE IF EXISTS `doctor_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doctor_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doctor_id` bigint(20) unsigned NOT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `review_text` text DEFAULT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_review_appointment` (`appointment_id`),
  KEY `idx_review_doctor` (`doctor_id`),
  KEY `idx_review_patient` (`patient_id`),
  KEY `idx_review_visible` (`is_visible`),
  KEY `idx_review_created_at` (`created_at`),
  CONSTRAINT `doctor_reviews_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctor_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `doctor_reviews_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patient_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `doctor_reviews_ibfk_3` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctor_reviews`
--

LOCK TABLES `doctor_reviews` WRITE;
/*!40000 ALTER TABLE `doctor_reviews` DISABLE KEYS */;
/*!40000 ALTER TABLE `doctor_reviews` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doctor_schedules`
--

DROP TABLE IF EXISTS `doctor_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doctor_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doctor_id` bigint(20) unsigned NOT NULL,
  `weekday` tinyint(3) unsigned NOT NULL COMMENT '0=Sunday, 6=Saturday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `slot_duration` int(10) unsigned DEFAULT 15 COMMENT 'Duration in minutes',
  `max_patients` int(10) unsigned NOT NULL DEFAULT 10,
  `is_available` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_doctor_weekday` (`doctor_id`,`weekday`),
  KEY `idx_weekday` (`weekday`),
  KEY `idx_available` (`is_available`),
  CONSTRAINT `doctor_schedules_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctor_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctor_schedules`
--

LOCK TABLES `doctor_schedules` WRITE;
/*!40000 ALTER TABLE `doctor_schedules` DISABLE KEYS */;
INSERT INTO `doctor_schedules` VALUES (1,1,1,'17:00:00','21:00:00',15,10,1,'2026-04-02 20:14:46','2026-04-02 20:14:46'),(2,1,2,'17:00:00','21:00:00',15,10,1,'2026-04-02 20:14:46','2026-04-02 20:14:46'),(3,1,3,'17:00:00','21:00:00',15,10,1,'2026-04-02 20:14:46','2026-04-02 20:14:46'),(4,1,4,'17:00:00','21:00:00',15,10,1,'2026-04-02 20:14:46','2026-04-02 20:14:46'),(5,1,5,'17:00:00','21:00:00',15,10,1,'2026-04-02 20:14:46','2026-04-02 20:14:46');
/*!40000 ALTER TABLE `doctor_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doctor_timeoffs`
--

DROP TABLE IF EXISTS `doctor_timeoffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doctor_timeoffs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doctor_id` bigint(20) unsigned NOT NULL,
  `off_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `is_full_day` tinyint(1) DEFAULT 1,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `doctor_id` (`doctor_id`),
  KEY `idx_off_date` (`off_date`),
  CONSTRAINT `doctor_timeoffs_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `doctor_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctor_timeoffs`
--

LOCK TABLES `doctor_timeoffs` WRITE;
/*!40000 ALTER TABLE `doctor_timeoffs` DISABLE KEYS */;
/*!40000 ALTER TABLE `doctor_timeoffs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` enum('appointment','payment','prescription','system','reminder') NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notification_user_id` (`user_id`),
  KEY `idx_notification_type` (`type`),
  KEY `idx_notification_is_read` (`is_read`),
  KEY `idx_notification_created_at` (`created_at`),
  CONSTRAINT `notifications_ibfk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_requests`
--

DROP TABLE IF EXISTS `otp_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `otp_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `attempts` tinyint(3) unsigned DEFAULT 0,
  `max_attempts` tinyint(3) unsigned DEFAULT 3,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `verified_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_email_created` (`email`,`created_at`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_otp_hash` (`otp_hash`),
  CONSTRAINT `otp_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_requests`
--

LOCK TABLES `otp_requests` WRITE;
/*!40000 ALTER TABLE `otp_requests` DISABLE KEYS */;
INSERT INTO `otp_requests` VALUES (1,NULL,'mostafizurrahmanantu@gmail.com','$2y$10$LE3wGMeVYpRcxiw5cLZEbeHyjNQpHtPdLOwa7bGPxF.UiIU3zYu2e',1,3,'2026-04-03 08:58:49','2026-04-03 08:54:20','103.139.144.204','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-02 19:53:48'),(2,1,'mostafizurrahmanantu@gmail.com','$2y$10$fMpudhs56cFdxWObNG7Hl.L2JQ8w8HbqPXEthEfG/CRWQdHt.Re1G',1,3,'2026-04-03 09:09:34','2026-04-03 09:04:51','103.139.144.204','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-02 20:04:34'),(3,NULL,'juniormostafiz@gmail.com','$2y$10$v6tkaEv29AAwHc/PayxObeHeDJ9pSYG0XRI1A7dQZT9nMlhTeLiGu',1,3,'2026-04-03 09:13:30','2026-04-03 09:08:44','103.139.144.204','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36','2026-04-02 20:08:31'),(4,NULL,'qa_smoke_live_1775162512@example.com','$2y$10$5KgQWAWGV3CyW01V3quvjOhZesvD8fdz8.wfYmb97hESLiIoYKHiC',1,3,'2026-04-03 09:46:57','2026-04-03 09:42:34','103.139.144.204','Mozilla/5.0 (Windows NT 10.0; Microsoft Windows 10.0.26200; en-US) PowerShell/7.5.5','2026-04-02 20:41:58'),(5,1,'mostafizurrahmanantu@gmail.com','$2y$10$o6uWfcBCagg064OCIkAoSuKsSjCCPao18NJJS8ZxwsrjmDhd1nDl2',1,3,'2026-04-03 09:47:34','2026-04-03 09:43:03','103.139.144.204','Mozilla/5.0 (Windows NT 10.0; Microsoft Windows 10.0.26200; en-US) PowerShell/7.5.5','2026-04-02 20:42:34');
/*!40000 ALTER TABLE `otp_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patient_profiles`
--

DROP TABLE IF EXISTS `patient_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patient_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` enum('male','female','other','prefer_not_to_say') DEFAULT NULL,
  `blood_group` enum('A+','A-','B+','B-','AB+','AB-','O+','O-') DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `medical_history_summary` text DEFAULT NULL,
  `allergies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `chronic_conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `profile_photo` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_patient_user_id` (`user_id`),
  KEY `idx_patient_created_at` (`created_at`),
  CONSTRAINT `patient_profiles_ibfk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patient_profiles`
--

LOCK TABLES `patient_profiles` WRITE;
/*!40000 ALTER TABLE `patient_profiles` DISABLE KEYS */;
INSERT INTO `patient_profiles` VALUES (1,2,'MD. MOSTAFIZUR RAHMAN ANTU','2002-11-17','male','O+','Natore',NULL,NULL,NULL,NULL,NULL,'uploads/profile-photos/patients/patients-2-90e378e23f170598.jpg','2026-04-02 20:09:14','2026-04-02 21:26:38'),(3,4,'EMON HOSSAIN Emu',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'uploads/profile-photos/patients/patients-4-7df743e10f1e4a73.jpeg','2026-04-16 11:33:47','2026-04-16 11:39:34');
/*!40000 ALTER TABLE `patient_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(50) NOT NULL,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `doctor_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(3) DEFAULT 'BDT',
  `status` enum('pending','success','failed','refunded','partially_refunded') DEFAULT 'pending',
  `payment_method` enum('cash','card','mobile_banking','online') DEFAULT NULL,
  `transaction_id` varchar(255) DEFAULT NULL,
  `gateway_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `refund_amount` decimal(10,2) DEFAULT NULL,
  `refund_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_number` (`payment_number`),
  KEY `idx_payment_appointment` (`appointment_id`),
  KEY `idx_payment_patient` (`patient_id`),
  KEY `idx_payment_doctor` (`doctor_id`),
  KEY `idx_payment_status` (`status`),
  KEY `idx_payment_paid_at` (`paid_at`),
  KEY `idx_payment_created_at` (`created_at`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patient_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`doctor_id`) REFERENCES `doctor_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` VALUES (1,'PAY-20260403-A5DE82',1,1,1,500.00,'BDT','success','online','MSB-ONLINE-20260403023435-C54C69','{\"recorded_in_app\":true,\"payment_method\":\"online\",\"completed_at\":\"2026-04-03T02:34:35+06:00\"}','2026-04-03 09:34:35',NULL,NULL,NULL,'2026-04-02 20:23:06','2026-04-02 20:34:35'),(3,'PAY-20260416-5BCE7D',3,3,1,500.00,'BDT','success','online','MSB-ONLINE-20260416173415-E8D861','{\"recorded_in_app\":true,\"payment_method\":\"online\",\"completed_at\":\"2026-04-16T17:34:15+06:00\"}','2026-04-16 11:34:15',NULL,NULL,NULL,'2026-04-16 11:34:11','2026-04-16 11:34:15');
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prescription_attachments`
--

DROP TABLE IF EXISTS `prescription_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prescription_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prescription_id` bigint(20) unsigned NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(50) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_prescription` (`prescription_id`),
  CONSTRAINT `prescription_attachments_ibfk_1` FOREIGN KEY (`prescription_id`) REFERENCES `prescriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prescription_attachments`
--

LOCK TABLES `prescription_attachments` WRITE;
/*!40000 ALTER TABLE `prescription_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `prescription_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prescriptions`
--

DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prescriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prescription_number` varchar(50) NOT NULL,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `patient_id` bigint(20) unsigned NOT NULL,
  `doctor_id` bigint(20) unsigned NOT NULL,
  `symptoms` text NOT NULL,
  `diagnosis` text NOT NULL,
  `diagnosis_codes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'ICD-10 codes',
  `medicine_list` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `dosage_instructions` text DEFAULT NULL,
  `advice` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `follow_up_notes` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `prescription_number` (`prescription_number`),
  UNIQUE KEY `uniq_prescription_appointment` (`appointment_id`),
  KEY `idx_prescription_patient` (`patient_id`),
  KEY `idx_prescription_doctor` (`doctor_id`),
  KEY `idx_prescription_follow_up_date` (`follow_up_date`),
  KEY `idx_prescription_is_deleted` (`is_deleted`),
  KEY `idx_prescription_created_at` (`created_at`),
  KEY `idx_prescription_deleted_by` (`deleted_by`),
  CONSTRAINT `prescriptions_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prescriptions_ibfk_2` FOREIGN KEY (`patient_id`) REFERENCES `patient_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prescriptions_ibfk_3` FOREIGN KEY (`doctor_id`) REFERENCES `doctor_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prescriptions_ibfk_4` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prescriptions`
--

LOCK TABLES `prescriptions` WRITE;
/*!40000 ALTER TABLE `prescriptions` DISABLE KEYS */;
INSERT INTO `prescriptions` VALUES (1,'RX-20260403-2F274E',1,1,1,'Sympotms','Diagnosis',NULL,'[{\"name\":\"Medicines\"}]','Dosage Instructions','Advice','2026-04-15','Hello',0,NULL,NULL,'2026-04-02 20:24:51','2026-04-02 20:24:51');
/*!40000 ALTER TABLE `prescriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rate_limits`
--

DROP TABLE IF EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(255) NOT NULL,
  `type` enum('otp_request','login_attempt','api_call') NOT NULL,
  `count` int(10) unsigned DEFAULT 1,
  `reset_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_identifier_type` (`identifier`,`type`),
  KEY `idx_reset_at` (`reset_at`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rate_limits`
--

LOCK TABLES `rate_limits` WRITE;
/*!40000 ALTER TABLE `rate_limits` DISABLE KEYS */;
INSERT INTO `rate_limits` VALUES (1,'otp:mostafizurrahmanantu@gmail.com','otp_request',3,'2026-04-03 09:53:49','2026-04-02 19:53:48'),(3,'otp:qa_smoke_live_1775162512@example.com','otp_request',1,'2026-04-03 10:41:57','2026-04-02 20:41:58'),(21,'login:emonemran6888@gmail.com','login_attempt',2,'2026-04-16 13:59:44','2026-04-16 13:59:29');
/*!40000 ALTER TABLE `rate_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `setting_group` varchar(50) DEFAULT 'general',
  `is_encrypted` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `idx_group` (`setting_group`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_settings`
--

LOCK TABLES `system_settings` WRITE;
/*!40000 ALTER TABLE `system_settings` DISABLE KEYS */;
INSERT INTO `system_settings` VALUES (1,'otp_expiry_minutes','5','auth',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(2,'otp_max_attempts','3','auth',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(3,'otp_rate_limit_per_hour','5','auth',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(4,'session_lifetime_hours','24','auth',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(5,'max_appointments_per_day','3','appointments',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(6,'cancellation_window_hours','2','appointments',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(7,'currency_code','BDT','payment',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(8,'tax_percentage','0','payment',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(9,'platform_fee_percentage','0','payment',0,'2026-04-02 19:45:58','2026-04-02 19:45:58'),(10,'maintenance_mode','false','system',0,'2026-04-02 19:45:58','2026-04-02 19:45:58');
/*!40000 ALTER TABLE `system_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` enum('patient','doctor','admin') NOT NULL DEFAULT 'patient',
  `status` enum('active','inactive','suspended','deleted') NOT NULL DEFAULT 'active',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'mostafizurrahmanantu@gmail.com',NULL,NULL,'doctor','active','2026-04-03 08:54:20','2026-04-03 09:43:03','103.139.144.204','2026-04-02 19:54:19','2026-04-02 20:43:04'),(2,'juniormostafiz@gmail.com',NULL,NULL,'patient','active','2026-04-03 09:08:44','2026-04-03 09:08:44','103.139.144.204','2026-04-02 20:08:45','2026-04-02 20:08:45'),(4,'emonemran@gmail.com',NULL,'$2y$10$zB8.WmJ06P/YZC9GJsIA2eEUrzuocEMByauFZbQGCeodPKjtaSnGe','patient','active','2026-04-16 11:33:47','2026-04-16 12:16:26','::1','2026-04-16 11:33:47','2026-04-16 12:16:26'),(6,'doctor_test100@example.com',NULL,'$2y$10$T5IYVrs1sleZp4KeEZFucOlk1fdPamAxGrr6TmP770WyNToi8nlRy','doctor','active','2026-04-16 11:58:53','2026-04-16 12:17:45','::1','2026-04-16 11:58:53','2026-04-16 12:17:45');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_user_status_audit
AFTER UPDATE ON users
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status OR OLD.role <> NEW.role THEN
        INSERT INTO activity_logs (
            user_id, 
            action, 
            entity_type, 
            entity_id, 
            old_values,
            new_values
        ) VALUES (
            NEW.id, 
            'user_modification_trigger', 
            'user', 
            NEW.id,
            JSON_OBJECT('status', OLD.status, 'role', OLD.role),
            JSON_OBJECT('status', NEW.status, 'role', NEW.role)
        );
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Temporary table structure for view `vw_appointment_details`
--

DROP TABLE IF EXISTS `vw_appointment_details`;
/*!50001 DROP VIEW IF EXISTS `vw_appointment_details`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_appointment_details` AS SELECT
 1 AS `appointment_id`,
  1 AS `appointment_number`,
  1 AS `appointment_date`,
  1 AS `token_number`,
  1 AS `estimated_time`,
  1 AS `appointment_status`,
  1 AS `booked_at`,
  1 AS `patient_id`,
  1 AS `patient_email`,
  1 AS `patient_name`,
  1 AS `patient_photo`,
  1 AS `patient_gender`,
  1 AS `doctor_id`,
  1 AS `doctor_email`,
  1 AS `doctor_name`,
  1 AS `doctor_specialty`,
  1 AS `doctor_photo`,
  1 AS `clinic`,
  1 AS `clinic_address`,
  1 AS `schedule_id`,
  1 AS `schedule_start`,
  1 AS `schedule_end` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_financial_reports`
--

DROP TABLE IF EXISTS `vw_financial_reports`;
/*!50001 DROP VIEW IF EXISTS `vw_financial_reports`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_financial_reports` AS SELECT
 1 AS `id`,
  1 AS `payment_number`,
  1 AS `amount`,
  1 AS `currency`,
  1 AS `status`,
  1 AS `payment_method`,
  1 AS `transaction_id`,
  1 AS `paid_at`,
  1 AS `refund_amount`,
  1 AS `refund_reason`,
  1 AS `refunded_at`,
  1 AS `created_at`,
  1 AS `appointment_number`,
  1 AS `appointment_date`,
  1 AS `patient_name`,
  1 AS `doctor_name`,
  1 AS `specialty` */;
SET character_set_client = @saved_cs_client;

--
-- Temporary table structure for view `vw_user_master_profile`
--

DROP TABLE IF EXISTS `vw_user_master_profile`;
/*!50001 DROP VIEW IF EXISTS `vw_user_master_profile`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `vw_user_master_profile` AS SELECT
 1 AS `user_id`,
  1 AS `email`,
  1 AS `role`,
  1 AS `status`,
  1 AS `last_login_at`,
  1 AS `created_at`,
  1 AS `full_name`,
  1 AS `profile_photo`,
  1 AS `patient_gender`,
  1 AS `doctor_specialty`,
  1 AS `is_doctor_verified` */;
SET character_set_client = @saved_cs_client;

--
-- Dumping events for database 'mediseba_local'
--

--
-- Dumping routines for database 'mediseba_local'
--
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `sp_book_appointment` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_book_appointment`(
    IN p_patient_id BIGINT,
    IN p_doctor_id BIGINT,
    IN p_schedule_id BIGINT,
    IN p_date DATE,
    IN p_symptoms TEXT,
    OUT p_appointment_id BIGINT,
    OUT p_token_num INT
)
BEGIN
    DECLARE current_bookings INT;
    DECLARE schedule_limit INT;
    DECLARE start_time TIME;
    DECLARE slot_duration INT;
    
    DECLARE exit handler for sqlexception
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- Extract constraints
    SELECT max_patients, start_time, slot_duration 
    INTO schedule_limit, start_time, slot_duration
    FROM doctor_schedules 
    WHERE id = p_schedule_id AND is_available = 1 FOR UPDATE;
    
    -- Check capacity
    SELECT COUNT(*) INTO current_bookings 
    FROM appointments 
    WHERE schedule_id = p_schedule_id AND appointment_date = p_date AND status NOT IN ('cancelled', 'no_show');
    
    IF current_bookings >= schedule_limit THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Schedule capacity limit reached';
    END IF;

    SET p_token_num = current_bookings + 1;
    
    -- Insert appointment ensuring robust atomic timestamping logic
    INSERT INTO appointments (
        appointment_number, patient_id, doctor_id, schedule_id, 
        appointment_date, token_number, estimated_time, status, symptoms
    )
    VALUES (
        CONCAT('APT-', DATE_FORMAT(p_date, '%Y%m%d'), '-', LPAD(FLOOR(RAND() * 999999), 6, '0')),
        p_patient_id, p_doctor_id, p_schedule_id, 
        p_date, p_token_num, ADDTIME(start_time, SEC_TO_TIME((p_token_num-1) * slot_duration * 60)),
        'pending', p_symptoms
    );

    SET p_appointment_id = LAST_INSERT_ID();

    COMMIT;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `sp_register_patient` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_register_patient`(
    IN p_email VARCHAR(255),
    IN p_hash VARCHAR(255),
    IN p_full_name VARCHAR(255),
    IN p_dob DATE,
    IN p_gender ENUM('male','female','other','prefer_not_to_say'),
    OUT p_user_id BIGINT
)
BEGIN
    DECLARE exit handler for sqlexception
    BEGIN
        -- Generic Exception Fallback
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;
    
    INSERT INTO users (email, password_hash, role, status)
    VALUES (p_email, p_hash, 'patient', 'active');
    
    SET p_user_id = LAST_INSERT_ID();
    
    INSERT INTO patient_profiles (user_id, full_name, date_of_birth, gender)
    VALUES (p_user_id, p_full_name, p_dob, p_gender);
    
    COMMIT;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Final view structure for view `vw_appointment_details`
--

/*!50001 DROP VIEW IF EXISTS `vw_appointment_details`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_appointment_details` AS select `a`.`id` AS `appointment_id`,`a`.`appointment_number` AS `appointment_number`,`a`.`appointment_date` AS `appointment_date`,`a`.`token_number` AS `token_number`,`a`.`estimated_time` AS `estimated_time`,`a`.`status` AS `appointment_status`,`a`.`created_at` AS `booked_at`,`p`.`id` AS `patient_id`,`pu`.`email` AS `patient_email`,`pp`.`full_name` AS `patient_name`,`pp`.`profile_photo` AS `patient_photo`,`pp`.`gender` AS `patient_gender`,`d`.`id` AS `doctor_id`,`du`.`email` AS `doctor_email`,`dp`.`full_name` AS `doctor_name`,`dp`.`specialty` AS `doctor_specialty`,`dp`.`profile_photo` AS `doctor_photo`,`dp`.`clinic_name` AS `clinic`,`dp`.`clinic_address` AS `clinic_address`,`s`.`id` AS `schedule_id`,`s`.`start_time` AS `schedule_start`,`s`.`end_time` AS `schedule_end` from (((((((`appointments` `a` join `patient_profiles` `pp` on(`a`.`patient_id` = `pp`.`id`)) join `users` `pu` on(`pp`.`user_id` = `pu`.`id`)) join `doctor_profiles` `dp` on(`a`.`doctor_id` = `dp`.`id`)) join `users` `du` on(`dp`.`user_id` = `du`.`id`)) join `doctor_schedules` `s` on(`a`.`schedule_id` = `s`.`id`)) join `patient_profiles` `p` on(`a`.`patient_id` = `p`.`id`)) join `doctor_profiles` `d` on(`a`.`doctor_id` = `d`.`id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_financial_reports`
--

/*!50001 DROP VIEW IF EXISTS `vw_financial_reports`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_financial_reports` AS select `p`.`id` AS `id`,`p`.`payment_number` AS `payment_number`,`p`.`amount` AS `amount`,`p`.`currency` AS `currency`,`p`.`status` AS `status`,`p`.`payment_method` AS `payment_method`,`p`.`transaction_id` AS `transaction_id`,`p`.`paid_at` AS `paid_at`,`p`.`refund_amount` AS `refund_amount`,`p`.`refund_reason` AS `refund_reason`,`p`.`refunded_at` AS `refunded_at`,`p`.`created_at` AS `created_at`,`a`.`appointment_number` AS `appointment_number`,`a`.`appointment_date` AS `appointment_date`,`pp`.`full_name` AS `patient_name`,`dp`.`full_name` AS `doctor_name`,`dp`.`specialty` AS `specialty` from (((`payments` `p` join `appointments` `a` on(`p`.`appointment_id` = `a`.`id`)) join `doctor_profiles` `dp` on(`p`.`doctor_id` = `dp`.`id`)) join `patient_profiles` `pp` on(`p`.`patient_id` = `pp`.`id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `vw_user_master_profile`
--

/*!50001 DROP VIEW IF EXISTS `vw_user_master_profile`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `vw_user_master_profile` AS select `u`.`id` AS `user_id`,`u`.`email` AS `email`,`u`.`role` AS `role`,`u`.`status` AS `status`,`u`.`last_login_at` AS `last_login_at`,`u`.`created_at` AS `created_at`,coalesce(`pp`.`full_name`,`dp`.`full_name`) AS `full_name`,coalesce(`pp`.`profile_photo`,`dp`.`profile_photo`) AS `profile_photo`,`pp`.`gender` AS `patient_gender`,`dp`.`specialty` AS `doctor_specialty`,`dp`.`is_verified` AS `is_doctor_verified` from ((`users` `u` left join `patient_profiles` `pp` on(`u`.`id` = `pp`.`user_id` and `u`.`role` = 'patient')) left join `doctor_profiles` `dp` on(`u`.`id` = `dp`.`user_id` and `u`.`role` = 'doctor')) */;
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

-- Dump completed on 2026-04-16 20:08:38

-- ============================================================
-- MediSeba Default Demo Users  (added by setup script)
-- ============================================================

-- Admin user  (email: admin@mediseba.com  |  password: Admin@1234)
INSERT IGNORE INTO `users`
  (`id`, `email`, `email_verified_at`, `password_hash`, `role`, `status`, `created_at`, `updated_at`)
VALUES
  (100, 'admin@mediseba.com', NOW(), '$2y$10$QNZRYnZB59p9U8eoHUgECuN9z9Q1H/ZPVXB9G8KySJbs5Atp6UNDe', 'admin', 'active', NOW(), NOW());

-- Demo doctor  (email: doctor@mediseba.com  |  password: Doctor@1234)
INSERT IGNORE INTO `users`
  (`id`, `email`, `email_verified_at`, `password_hash`, `role`, `status`, `created_at`, `updated_at`)
VALUES
  (101, 'doctor@mediseba.com', NOW(), '$2y$10$eSWsQ3nEWRCgxbRWxM03JO3PWGL5z13dEcTqrrQ2qGHU0WXumgMGG', 'doctor', 'active', NOW(), NOW());

-- Demo doctor profile (linked to user 101)
INSERT IGNORE INTO `doctor_profiles`
  (`user_id`, `full_name`, `slug`, `specialty`, `qualification`,
   `experience_years`, `consultation_fee`, `is_verified`, `is_featured`,
   `bio`, `created_at`, `updated_at`)
VALUES
  (101, 'Dr. Demo Doctor', 'dr-demo-doctor', 'General Physician', 'MBBS',
   5, 500.00, 1, 0,
   'Demo doctor account for testing MediSeba features.',
   NOW(), NOW());

-- Demo patient  (email: patient@mediseba.com  |  password: Patient@1234)
INSERT IGNORE INTO `users`
  (`id`, `email`, `email_verified_at`, `password_hash`, `role`, `status`, `created_at`, `updated_at`)
VALUES
  (102, 'patient@mediseba.com', NOW(), '$2y$10$Hxi11jaYfYo2ElK0EwdWDeslW/B1/Y9GwBA54m.YTSUJU83EPRSlC', 'patient', 'active', NOW(), NOW());

-- Demo patient profile (linked to user 102)
INSERT IGNORE INTO `patient_profiles`
  (`user_id`, `full_name`, `gender`, `date_of_birth`, `blood_group`, `created_at`, `updated_at`)
VALUES
  (102, 'Demo Patient', 'male', '1990-01-01', 'O+', NOW(), NOW());

-- ============================================================
