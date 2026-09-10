-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 10, 2026 at 01:16 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gdn_crm`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `legal_name` varchar(255) DEFAULT NULL,
  `industry` varchar(255) DEFAULT NULL,
  `size` varchar(255) DEFAULT NULL,
  `annual_revenue` decimal(15,2) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `address_line_1` varchar(255) DEFAULT NULL,
  `address_line_2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `owner_id` bigint(20) UNSIGNED NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `merged_into_id` bigint(20) UNSIGNED DEFAULT NULL,
  `merged_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'task',
  `subject` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `priority` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `due_at` datetime NOT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 0,
  `duration_minutes` smallint(5) UNSIGNED DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completion_notes` text DEFAULT NULL,
  `reminder_minutes_before` int(10) UNSIGNED DEFAULT NULL,
  `reminder_sent_at` timestamp NULL DEFAULT NULL,
  `recurrence_frequency` varchar(16) DEFAULT NULL,
  `recurrence_interval` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `recurrence_until` date DEFAULT NULL,
  `recurrence_count` smallint(5) UNSIGNED DEFAULT NULL,
  `recurrence_parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `related_type` varchar(255) DEFAULT NULL,
  `related_id` bigint(20) UNSIGNED DEFAULT NULL,
  `owner_id` bigint(20) UNSIGNED NOT NULL,
  `created_by_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `log_name` varchar(255) DEFAULT NULL,
  `description` text NOT NULL,
  `subject_type` varchar(255) DEFAULT NULL,
  `event` varchar(255) DEFAULT NULL,
  `subject_id` bigint(20) UNSIGNED DEFAULT NULL,
  `causer_type` varchar(255) DEFAULT NULL,
  `causer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`properties`)),
  `batch_uuid` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `log_name`, `description`, `subject_type`, `event`, `subject_id`, `causer_type`, `causer_id`, `properties`, `batch_uuid`, `created_at`, `updated_at`) VALUES
(2, 'audit', 'Company was updated', 'App\\Domain\\Company\\Models\\Company', 'updated', 1, 'App\\Models\\User', 1, '{\"attributes\":{\"name\":\"Golden Info Tech\",\"city\":\"Dhaka\"},\"old\":{\"name\":\"GDN CRM\",\"city\":null}}', NULL, '2026-09-02 05:36:27', '2026-09-02 05:36:27'),
(5, 'audit', 'Role was created', 'Spatie\\Permission\\Models\\Role', 'created', 3, 'App\\Models\\User', 1, '{\"attributes\":{\"name\":\"Support Agent\",\"data_access_level\":\"own\",\"permissions\":[\"users.view\"]}}', NULL, '2026-09-02 05:36:27', '2026-09-02 05:36:27'),
(6, 'audit', 'Role was updated', 'Spatie\\Permission\\Models\\Role', 'updated', 3, 'App\\Models\\User', 1, '{\"attributes\":{\"data_access_level\":\"team\",\"permissions\":[\"teams.view\",\"users.view\"]},\"old\":{\"data_access_level\":\"own\",\"permissions\":[\"users.view\"]}}', NULL, '2026-09-02 05:36:27', '2026-09-02 05:36:27'),
(7, 'audit', 'User was created', 'App\\Models\\User', 'created', 3, NULL, NULL, '{\"attributes\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T04:07:04.000000Z\"}}', NULL, '2026-09-02 22:07:04', '2026-09-02 22:07:04'),
(8, 'audit', 'Storage settings were updated', 'App\\Domain\\Settings\\Models\\Setting', 'updated', 2, 'App\\Models\\User', 3, '{\"attributes\":{\"group\":\"storage\",\"keys\":[\"s3_bucket\",\"s3_key\",\"s3_secret\"],\"values\":{\"s3_bucket\":\"verify-bucket\",\"s3_key\":\"[secret changed]\",\"s3_secret\":\"[secret changed]\"}}}', NULL, '2026-09-02 22:08:07', '2026-09-02 22:08:07'),
(9, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 3, NULL, NULL, '{\"old\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T04:07:04.000000Z\"}}', NULL, '2026-09-02 22:13:17', '2026-09-02 22:13:17'),
(10, 'audit', 'User was created', 'App\\Models\\User', 'created', 4, NULL, NULL, '{\"attributes\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T05:45:14.000000Z\"}}', NULL, '2026-09-02 23:45:14', '2026-09-02 23:45:14'),
(11, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 4, NULL, NULL, '{\"old\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T05:45:14.000000Z\"}}', NULL, '2026-09-02 23:48:55', '2026-09-02 23:48:55'),
(12, 'audit', 'User was created', 'App\\Models\\User', 'created', 5, NULL, NULL, '{\"attributes\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T06:49:53.000000Z\"}}', NULL, '2026-09-03 00:49:53', '2026-09-03 00:49:53'),
(13, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 5, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-03T06:49:54.000000Z\"},\"old\":{\"email_verified_at\":\"2026-09-03T06:49:53.000000Z\"}}', NULL, '2026-09-03 00:49:54', '2026-09-03 00:49:54'),
(18, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 5, NULL, NULL, '{\"old\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T06:49:54.000000Z\"}}', NULL, '2026-09-03 00:57:26', '2026-09-03 00:57:26'),
(19, 'audit', 'User was created', 'App\\Models\\User', 'created', 6, NULL, NULL, '{\"attributes\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T08:12:37.000000Z\"}}', NULL, '2026-09-03 02:12:38', '2026-09-03 02:12:38'),
(20, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 6, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-03T08:12:38.000000Z\"},\"old\":{\"email_verified_at\":\"2026-09-03T08:12:37.000000Z\"}}', NULL, '2026-09-03 02:12:38', '2026-09-03 02:12:38'),
(53, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 6, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-03T08:12:52.000000Z\"},\"old\":{\"email_verified_at\":\"2026-09-03T08:12:38.000000Z\"}}', NULL, '2026-09-03 02:12:52', '2026-09-03 02:12:52'),
(58, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 6, NULL, NULL, '{\"old\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T08:12:52.000000Z\"}}', NULL, '2026-09-03 02:23:35', '2026-09-03 02:23:35'),
(59, 'audit', 'User was created', 'App\\Models\\User', 'created', 7, NULL, NULL, '{\"attributes\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T09:01:10.000000Z\"}}', NULL, '2026-09-03 03:01:10', '2026-09-03 03:01:10'),
(92, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 7, NULL, NULL, '{\"old\":{\"name\":\"Verify Bot\",\"email\":\"uikit-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T09:01:10.000000Z\"}}', NULL, '2026-09-03 03:08:09', '2026-09-03 03:08:09'),
(103, 'audit', 'User was created', 'App\\Models\\User', 'created', 8, NULL, NULL, '{\"attributes\":{\"name\":\"Score Bot\",\"email\":\"score-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":null}}', NULL, '2026-09-03 03:43:23', '2026-09-03 03:43:23'),
(112, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 8, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-03T09:45:46.000000Z\"},\"old\":{\"email_verified_at\":null}}', NULL, '2026-09-03 03:45:46', '2026-09-03 03:45:46'),
(117, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 8, NULL, NULL, '{\"old\":{\"name\":\"Score Bot\",\"email\":\"score-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T09:45:46.000000Z\"}}', NULL, '2026-09-03 03:54:36', '2026-09-03 03:54:36'),
(118, 'audit', 'User was created', 'App\\Models\\User', 'created', 9, NULL, NULL, '{\"attributes\":{\"name\":\"Filter Bot\",\"email\":\"filter-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":null}}', NULL, '2026-09-03 04:26:00', '2026-09-03 04:26:00'),
(119, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 9, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-03T10:26:00.000000Z\"},\"old\":{\"email_verified_at\":null}}', NULL, '2026-09-03 04:26:00', '2026-09-03 04:26:00'),
(126, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 9, NULL, NULL, '{\"old\":{\"name\":\"Filter Bot\",\"email\":\"filter-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T10:26:00.000000Z\"}}', NULL, '2026-09-03 04:37:06', '2026-09-03 04:37:06'),
(127, 'audit', 'User was created', 'App\\Models\\User', 'created', 10, NULL, NULL, '{\"attributes\":{\"name\":\"Select Bot\",\"email\":\"sel-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":null}}', NULL, '2026-09-03 05:14:51', '2026-09-03 05:14:51'),
(128, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 10, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-03T11:14:51.000000Z\"},\"old\":{\"email_verified_at\":null}}', NULL, '2026-09-03 05:14:51', '2026-09-03 05:14:51'),
(134, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 10, NULL, NULL, '{\"old\":{\"name\":\"Select Bot\",\"email\":\"sel-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-03T11:14:51.000000Z\"}}', NULL, '2026-09-03 05:19:21', '2026-09-03 05:19:21'),
(141, 'audit', 'User was created', 'App\\Models\\User', 'created', 11, NULL, NULL, '{\"attributes\":{\"name\":\"Dup Bot\",\"email\":\"dup-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":null}}', NULL, '2026-09-05 21:52:05', '2026-09-05 21:52:05'),
(142, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 11, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-06T03:52:05.000000Z\"},\"old\":{\"email_verified_at\":null}}', NULL, '2026-09-05 21:52:05', '2026-09-05 21:52:05'),
(151, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 11, NULL, NULL, '{\"old\":{\"name\":\"Dup Bot\",\"email\":\"dup-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-06T03:52:05.000000Z\"}}', NULL, '2026-09-05 21:57:33', '2026-09-05 21:57:33'),
(153, 'audit', 'User was created', 'App\\Models\\User', 'created', 12, NULL, NULL, '{\"attributes\":{\"name\":\"Convert Bot\",\"email\":\"conv-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":null}}', NULL, '2026-09-05 22:29:56', '2026-09-05 22:29:56'),
(154, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 12, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-06T04:29:56.000000Z\"},\"old\":{\"email_verified_at\":null}}', NULL, '2026-09-05 22:29:56', '2026-09-05 22:29:56'),
(161, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 12, NULL, NULL, '{\"old\":{\"name\":\"Convert Bot\",\"email\":\"conv-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-06T04:29:56.000000Z\"}}', NULL, '2026-09-05 22:33:46', '2026-09-05 22:33:46'),
(162, 'audit', 'User was created', 'App\\Models\\User', 'created', 13, NULL, NULL, '{\"attributes\":{\"name\":\"Import Bot\",\"email\":\"imp-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":null}}', NULL, '2026-09-05 23:18:56', '2026-09-05 23:18:56'),
(163, 'audit', 'User was updated', 'App\\Models\\User', 'updated', 13, NULL, NULL, '{\"attributes\":{\"email_verified_at\":\"2026-09-06T05:18:56.000000Z\"},\"old\":{\"email_verified_at\":null}}', NULL, '2026-09-05 23:18:56', '2026-09-05 23:18:56'),
(166, 'audit', 'User was deleted', 'App\\Models\\User', 'deleted', 13, NULL, NULL, '{\"old\":{\"name\":\"Import Bot\",\"email\":\"imp-verify@example.test\",\"current_team_id\":null,\"email_verified_at\":\"2026-09-06T05:18:56.000000Z\"}}', NULL, '2026-09-05 23:23:26', '2026-09-05 23:23:26'),
(167, 'audit', 'Company was updated', 'App\\Domain\\Company\\Models\\Company', 'updated', 1, 'App\\Models\\User', 1, '{\"attributes\":{\"timezone\":\"Asia\\/Dhaka\"},\"old\":{\"timezone\":\"UTC\"}}', NULL, '2026-09-05 23:57:26', '2026-09-05 23:57:26'),
(170, 'audit', 'Note was created', 'App\\Domain\\Timeline\\Models\\Note', 'created', 1, NULL, NULL, '{\"attributes\":{\"notable_type\":\"App\\\\Domain\\\\Leads\\\\Models\\\\Lead\",\"notable_id\":61,\"author_id\":1,\"body\":\"Called them on Tuesday. Asked for a quote by the end of the month.\"}}', NULL, '2026-09-06 00:39:06', '2026-09-06 00:39:06'),
(171, 'audit', 'Note was created', 'App\\Domain\\Timeline\\Models\\Note', 'created', 2, 'App\\Models\\User', 1, '{\"attributes\":{\"notable_type\":\"App\\\\Domain\\\\Leads\\\\Models\\\\Lead\",\"notable_id\":61,\"author_id\":1,\"body\":\"Follow-up: they want the quote split by department.\"}}', NULL, '2026-09-06 00:41:58', '2026-09-06 00:41:58'),
(172, 'audit', 'Document was created', 'App\\Domain\\Timeline\\Models\\Document', 'created', 1, NULL, NULL, '{\"attributes\":{\"documentable_type\":\"App\\\\Domain\\\\Leads\\\\Models\\\\Lead\",\"documentable_id\":61,\"uploaded_by_id\":1,\"title\":\"Signed Contract 2026.pdf\",\"description\":\"Countersigned copy\"}}', NULL, '2026-09-06 00:42:25', '2026-09-06 00:42:25'),
(173, 'audit', 'Document was deleted', 'App\\Domain\\Timeline\\Models\\Document', 'deleted', 1, NULL, NULL, '{\"old\":{\"documentable_type\":\"App\\\\Domain\\\\Leads\\\\Models\\\\Lead\",\"documentable_id\":61,\"uploaded_by_id\":1,\"title\":\"Signed Contract 2026.pdf\",\"description\":\"Countersigned copy\"}}', NULL, '2026-09-06 00:43:12', '2026-09-06 00:43:12'),
(174, 'audit', 'Lead was deleted', 'App\\Domain\\Leads\\Models\\Lead', 'deleted', 61, NULL, NULL, '{\"old\":{\"first_name\":\"Verify\",\"last_name\":\"Timeline\",\"company_name\":\"Timeline Check Limited\",\"email\":\"lebsack.shyann@example.org\",\"status\":\"new\",\"source\":\"event\",\"estimated_value\":\"186088.63\",\"owner_id\":1}}', NULL, '2026-09-06 00:43:12', '2026-09-06 00:43:12'),
(196, 'audit', 'Deal was deleted', 'App\\Domain\\Deals\\Models\\Deal', 'deleted', 2, NULL, NULL, '{\"old\":{\"name\":\"Verify rollout\",\"account_id\":39,\"contact_id\":6,\"value\":\"40000.00\",\"expected_close_date\":\"2026-10-10T00:00:00.000000Z\",\"pipeline_id\":1,\"stage\":\"won\",\"owner_id\":1,\"closed_at\":\"2026-09-10T08:40:55.000000Z\",\"close_reason\":\"best_fit\"}}', NULL, '2026-09-10 02:41:14', '2026-09-10 02:41:14'),
(197, 'audit', 'Deal was deleted', 'App\\Domain\\Deals\\Models\\Deal', 'deleted', 3, NULL, NULL, '{\"old\":{\"name\":\"Verify renewal\",\"account_id\":39,\"contact_id\":6,\"value\":\"9000.00\",\"expected_close_date\":\"2026-10-10T00:00:00.000000Z\",\"pipeline_id\":1,\"stage\":\"new\",\"owner_id\":1,\"closed_at\":null,\"close_reason\":null}}', NULL, '2026-09-10 02:41:14', '2026-09-10 02:41:14'),
(198, 'audit', 'Contact was deleted', 'App\\Domain\\Contacts\\Models\\Contact', 'deleted', 6, NULL, NULL, '{\"old\":{\"first_name\":\"Dana\",\"last_name\":\"Scully\",\"job_title\":\"Soldering Machine Setter\",\"department\":\"marketing\",\"email\":\"herta12@example.org\",\"account_id\":39,\"is_primary\":false,\"owner_id\":1}}', NULL, '2026-09-10 02:41:14', '2026-09-10 02:41:14'),
(199, 'audit', 'Account was deleted', 'App\\Domain\\Accounts\\Models\\Account', 'deleted', 39, NULL, NULL, '{\"old\":{\"name\":\"Verify Industries\",\"industry\":\"healthcare\",\"size\":\"large\",\"annual_revenue\":\"10033351.04\",\"owner_id\":1,\"parent_id\":null,\"country\":\"New Zealand\"}}', NULL, '2026-09-10 02:41:14', '2026-09-10 02:41:14'),
(206, 'audit', 'Deal was deleted', 'App\\Domain\\Deals\\Models\\Deal', 'deleted', 4, NULL, NULL, '{\"old\":{\"name\":\"Board deal one\",\"account_id\":40,\"contact_id\":null,\"value\":\"12000.00\",\"expected_close_date\":null,\"pipeline_id\":1,\"stage\":\"qualification\",\"owner_id\":1,\"closed_at\":null,\"close_reason\":null}}', NULL, '2026-09-10 03:59:38', '2026-09-10 03:59:38'),
(207, 'audit', 'Deal was deleted', 'App\\Domain\\Deals\\Models\\Deal', 'deleted', 5, NULL, NULL, '{\"old\":{\"name\":\"Board deal two\",\"account_id\":40,\"contact_id\":null,\"value\":\"8000.00\",\"expected_close_date\":null,\"pipeline_id\":1,\"stage\":\"new\",\"owner_id\":1,\"closed_at\":null,\"close_reason\":null}}', NULL, '2026-09-10 03:59:38', '2026-09-10 03:59:38'),
(208, 'audit', 'Deal was deleted', 'App\\Domain\\Deals\\Models\\Deal', 'deleted', 6, NULL, NULL, '{\"old\":{\"name\":\"Board deal three\",\"account_id\":40,\"contact_id\":null,\"value\":\"3000.00\",\"expected_close_date\":null,\"pipeline_id\":1,\"stage\":\"new\",\"owner_id\":1,\"closed_at\":null,\"close_reason\":null}}', NULL, '2026-09-10 03:59:38', '2026-09-10 03:59:38'),
(209, 'audit', 'Account was deleted', 'App\\Domain\\Accounts\\Models\\Account', 'deleted', 40, NULL, NULL, '{\"old\":{\"name\":\"Board Check Ltd\",\"industry\":\"government\",\"size\":\"micro\",\"annual_revenue\":\"28293605.30\",\"owner_id\":1,\"parent_id\":null,\"country\":\"Zambia\"}}', NULL, '2026-09-10 03:59:38', '2026-09-10 03:59:38'),
(210, 'audit', 'User was created', 'App\\Models\\User', 'created', 14, NULL, NULL, '{\"attributes\":{\"name\":\"Dr. Holden Reilly I\",\"email\":\"karlie.rolfson@example.org\",\"current_team_id\":null,\"email_verified_at\":\"2026-05-01T09:00:00.000000Z\"}}', NULL, '2026-05-01 03:00:00', '2026-05-01 03:00:00'),
(212, 'audit', 'User was created', 'App\\Models\\User', 'created', 15, NULL, NULL, '{\"attributes\":{\"name\":\"Dax Macejkovic V\",\"email\":\"mills.donato@example.org\",\"current_team_id\":null,\"email_verified_at\":\"2026-05-01T09:00:00.000000Z\"}}', NULL, '2026-05-01 03:00:00', '2026-05-01 03:00:00'),
(221, 'audit', 'Deal was deleted', 'App\\Domain\\Deals\\Models\\Deal', 'deleted', 7, NULL, NULL, '{\"old\":{\"name\":\"Kshlerin, Strosin and Oberbrunner opportunity\",\"account_id\":41,\"contact_id\":null,\"value\":\"240815.07\",\"expected_close_date\":\"2026-09-12T00:00:00.000000Z\",\"pipeline_id\":1,\"stage\":\"won\",\"owner_id\":15,\"closed_at\":\"2026-05-09T09:00:00.000000Z\",\"close_reason\":null}}', NULL, '2026-09-10 04:22:56', '2026-09-10 04:22:56'),
(222, 'audit', 'Deal was deleted', 'App\\Domain\\Deals\\Models\\Deal', 'deleted', 8, NULL, NULL, '{\"old\":{\"name\":\"History check deal\",\"account_id\":42,\"contact_id\":null,\"value\":\"25000.00\",\"expected_close_date\":null,\"pipeline_id\":1,\"stage\":\"qualification\",\"owner_id\":1,\"closed_at\":null,\"close_reason\":null}}', NULL, '2026-09-10 04:22:56', '2026-09-10 04:22:56'),
(223, 'audit', 'Account was deleted', 'App\\Domain\\Accounts\\Models\\Account', 'deleted', 42, NULL, NULL, '{\"old\":{\"name\":\"History Check Ltd\",\"industry\":\"media\",\"size\":\"enterprise\",\"annual_revenue\":\"2113931.36\",\"owner_id\":1,\"parent_id\":null,\"country\":\"Equatorial Guinea\"}}', NULL, '2026-09-10 04:22:56', '2026-09-10 04:22:56'),
(224, 'audit', 'Account was deleted', 'App\\Domain\\Accounts\\Models\\Account', 'deleted', 41, NULL, NULL, '{\"old\":{\"name\":\"Hudson-Corkery\",\"industry\":\"education\",\"size\":\"micro\",\"annual_revenue\":\"1298110.95\",\"owner_id\":14,\"parent_id\":null,\"country\":\"Serbia\"}}', NULL, '2026-09-10 04:23:10', '2026-09-10 04:23:10');

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `cache`
--

INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('gdn-crm-cache-bd307a3ec329e10a2cff8fb87480823da114f8f4', 'i:1;', 1788672172),
('gdn-crm-cache-bd307a3ec329e10a2cff8fb87480823da114f8f4:timer', 'i:1788672172;', 1788672172),
('gdn-crm-cache-notifications:matrix', 'a:0:{}', 2104041920);
INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('gdn-crm-cache-roster:project:v3:ad58b2d097d0b51708bdbd741d021754', 'O:26:\"Laravel\\Roster\\ProjectScan\":8:{s:8:\"basePath\";s:27:\"E:\\xampp8.2\\htdocs\\gdn_crm\\\";s:3:\"php\";O:35:\"Laravel\\Roster\\Ecosystems\\Ecosystem\":2:{s:9:\"\0*\0byName\";a:175:{s:19:\"bacon/bacon-qr-code\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"bacon/bacon-qr-code\";s:10:\"\0*\0version\";s:5:\"3.1.1\";s:9:\"\0*\0source\";E:43:\"Laravel\\Roster\\Enums\\PackageSource:Composer\";s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\bacon\\bacon-qr-code\";}s:23:\"barryvdh/laravel-dompdf\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"barryvdh/laravel-dompdf\";s:10:\"\0*\0version\";s:5:\"3.1.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^3.1\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\barryvdh\\laravel-dompdf\";}s:24:\"blade-ui-kit/blade-icons\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:24:\"blade-ui-kit/blade-icons\";s:10:\"\0*\0version\";s:6:\"1.10.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\blade-ui-kit\\blade-icons\";}s:10:\"brick/math\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"brick/math\";s:10:\"\0*\0version\";s:6:\"0.14.8\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:44:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\brick\\math\";}s:31:\"carbonphp/carbon-doctrine-types\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:31:\"carbonphp/carbon-doctrine-types\";s:10:\"\0*\0version\";s:5:\"3.2.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:65:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\carbonphp\\carbon-doctrine-types\";}s:13:\"composer/pcre\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"composer/pcre\";s:10:\"\0*\0version\";s:5:\"3.4.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\composer\\pcre\";}s:15:\"composer/semver\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"composer/semver\";s:10:\"\0*\0version\";s:5:\"3.4.4\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\composer\\semver\";}s:12:\"dasprid/enum\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"dasprid/enum\";s:10:\"\0*\0version\";s:5:\"1.0.7\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\dasprid\\enum\";}s:23:\"dflydev/dot-access-data\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"dflydev/dot-access-data\";s:10:\"\0*\0version\";s:5:\"3.0.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\dflydev\\dot-access-data\";}s:21:\"doctrine/deprecations\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"doctrine/deprecations\";s:10:\"\0*\0version\";s:5:\"1.1.6\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\doctrine\\deprecations\";}s:18:\"doctrine/inflector\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"doctrine/inflector\";s:10:\"\0*\0version\";s:5:\"2.1.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\doctrine\\inflector\";}s:14:\"doctrine/lexer\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"doctrine/lexer\";s:10:\"\0*\0version\";s:5:\"3.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\doctrine\\lexer\";}s:13:\"dompdf/dompdf\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"dompdf/dompdf\";s:10:\"\0*\0version\";s:5:\"3.1.6\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\dompdf\\dompdf\";}s:19:\"dompdf/php-font-lib\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"dompdf/php-font-lib\";s:10:\"\0*\0version\";s:5:\"1.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\dompdf\\php-font-lib\";}s:18:\"dompdf/php-svg-lib\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"dompdf/php-svg-lib\";s:10:\"\0*\0version\";s:5:\"1.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\dompdf\\php-svg-lib\";}s:29:\"dragonmantank/cron-expression\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:29:\"dragonmantank/cron-expression\";s:10:\"\0*\0version\";s:5:\"3.6.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\dragonmantank\\cron-expression\";}s:23:\"egulias/email-validator\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"egulias/email-validator\";s:10:\"\0*\0version\";s:5:\"4.0.4\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\egulias\\email-validator\";}s:19:\"ezyang/htmlpurifier\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"ezyang/htmlpurifier\";s:10:\"\0*\0version\";s:6:\"4.19.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\ezyang\\htmlpurifier\";}s:18:\"fruitcake/php-cors\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"fruitcake/php-cors\";s:10:\"\0*\0version\";s:5:\"1.4.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\fruitcake\\php-cors\";}s:27:\"graham-campbell/result-type\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"graham-campbell/result-type\";s:10:\"\0*\0version\";s:5:\"1.1.4\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\graham-campbell\\result-type\";}s:17:\"guzzlehttp/guzzle\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"guzzlehttp/guzzle\";s:10:\"\0*\0version\";s:6:\"7.15.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\guzzlehttp\\guzzle\";}s:19:\"guzzlehttp/promises\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"guzzlehttp/promises\";s:10:\"\0*\0version\";s:5:\"2.5.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\guzzlehttp\\promises\";}s:15:\"guzzlehttp/psr7\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"guzzlehttp/psr7\";s:10:\"\0*\0version\";s:6:\"2.13.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\guzzlehttp\\psr7\";}s:23:\"guzzlehttp/uri-template\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"guzzlehttp/uri-template\";s:10:\"\0*\0version\";s:6:\"1.0.10\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\guzzlehttp\\uri-template\";}s:15:\"laravel/fortify\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"laravel/fortify\";s:10:\"\0*\0version\";s:6:\"1.38.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:5:\"^1.38\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\fortify\";}s:17:\"laravel/framework\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"laravel/framework\";s:10:\"\0*\0version\";s:7:\"12.67.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:5:\"^12.0\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\framework\";}s:15:\"laravel/horizon\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"laravel/horizon\";s:10:\"\0*\0version\";s:6:\"5.48.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:5:\"^5.48\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\horizon\";}s:16:\"laravel/passkeys\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"laravel/passkeys\";s:10:\"\0*\0version\";s:5:\"0.2.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\passkeys\";}s:15:\"laravel/prompts\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"laravel/prompts\";s:10:\"\0*\0version\";s:6:\"0.3.23\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\prompts\";}s:15:\"laravel/sanctum\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"laravel/sanctum\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^4.3\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\sanctum\";}s:16:\"laravel/sentinel\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"laravel/sentinel\";s:10:\"\0*\0version\";s:5:\"1.1.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\sentinel\";}s:28:\"laravel/serializable-closure\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:28:\"laravel/serializable-closure\";s:10:\"\0*\0version\";s:6:\"2.0.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:62:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\serializable-closure\";}s:14:\"laravel/tinker\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"laravel/tinker\";s:10:\"\0*\0version\";s:6:\"2.11.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:7:\"^2.10.1\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\tinker\";}s:17:\"league/commonmark\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"league/commonmark\";s:10:\"\0*\0version\";s:6:\"2.10.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\league\\commonmark\";}s:13:\"league/config\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"league/config\";s:10:\"\0*\0version\";s:5:\"1.2.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\league\\config\";}s:16:\"league/flysystem\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"league/flysystem\";s:10:\"\0*\0version\";s:6:\"3.35.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\league\\flysystem\";}s:22:\"league/flysystem-local\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"league/flysystem-local\";s:10:\"\0*\0version\";s:6:\"3.31.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\league\\flysystem-local\";}s:26:\"league/mime-type-detection\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"league/mime-type-detection\";s:10:\"\0*\0version\";s:6:\"1.17.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\league\\mime-type-detection\";}s:10:\"league/uri\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"league/uri\";s:10:\"\0*\0version\";s:5:\"7.8.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:44:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\league\\uri\";}s:21:\"league/uri-interfaces\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"league/uri-interfaces\";s:10:\"\0*\0version\";s:5:\"7.8.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\league\\uri-interfaces\";}s:17:\"livewire/livewire\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"livewire/livewire\";s:10:\"\0*\0version\";s:5:\"3.8.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^3.0\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\livewire\\livewire\";}s:17:\"maatwebsite/excel\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"maatwebsite/excel\";s:10:\"\0*\0version\";s:6:\"3.1.70\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^3.1\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\maatwebsite\\excel\";}s:23:\"maennchen/zipstream-php\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"maennchen/zipstream-php\";s:10:\"\0*\0version\";s:5:\"3.1.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\maennchen\\zipstream-php\";}s:30:\"mallardduck/blade-lucide-icons\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"mallardduck/blade-lucide-icons\";s:10:\"\0*\0version\";s:5:\"2.0.6\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^2.0\";s:7:\"\0*\0path\";s:64:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\mallardduck\\blade-lucide-icons\";}s:17:\"markbaker/complex\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"markbaker/complex\";s:10:\"\0*\0version\";s:5:\"3.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\markbaker\\complex\";}s:16:\"markbaker/matrix\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"markbaker/matrix\";s:10:\"\0*\0version\";s:5:\"3.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\markbaker\\matrix\";}s:17:\"masterminds/html5\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"masterminds/html5\";s:10:\"\0*\0version\";s:6:\"2.11.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\masterminds\\html5\";}s:15:\"monolog/monolog\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"monolog/monolog\";s:10:\"\0*\0version\";s:6:\"3.10.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\monolog\\monolog\";}s:13:\"nesbot/carbon\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"nesbot/carbon\";s:10:\"\0*\0version\";s:6:\"3.13.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\nesbot\\carbon\";}s:12:\"nette/schema\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"nette/schema\";s:10:\"\0*\0version\";s:5:\"1.3.6\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\nette\\schema\";}s:11:\"nette/utils\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"nette/utils\";s:10:\"\0*\0version\";s:5:\"4.1.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\nette\\utils\";}s:16:\"nikic/php-parser\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"nikic/php-parser\";s:10:\"\0*\0version\";s:5:\"5.8.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\nikic\\php-parser\";}s:19:\"nunomaduro/termwind\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"nunomaduro/termwind\";s:10:\"\0*\0version\";s:5:\"2.4.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\nunomaduro\\termwind\";}s:32:\"paragonie/constant_time_encoding\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:32:\"paragonie/constant_time_encoding\";s:10:\"\0*\0version\";s:5:\"3.1.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:66:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\paragonie\\constant_time_encoding\";}s:31:\"phpdocumentor/reflection-common\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:31:\"phpdocumentor/reflection-common\";s:10:\"\0*\0version\";s:5:\"2.2.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:65:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpdocumentor\\reflection-common\";}s:33:\"phpdocumentor/reflection-docblock\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:33:\"phpdocumentor/reflection-docblock\";s:10:\"\0*\0version\";s:5:\"6.0.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:67:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpdocumentor\\reflection-docblock\";}s:27:\"phpdocumentor/type-resolver\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"phpdocumentor/type-resolver\";s:10:\"\0*\0version\";s:5:\"2.0.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpdocumentor\\type-resolver\";}s:24:\"phpoffice/phpspreadsheet\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:24:\"phpoffice/phpspreadsheet\";s:10:\"\0*\0version\";s:6:\"1.30.6\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpoffice\\phpspreadsheet\";}s:19:\"phpoption/phpoption\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"phpoption/phpoption\";s:10:\"\0*\0version\";s:5:\"1.9.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpoption\\phpoption\";}s:21:\"phpstan/phpdoc-parser\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"phpstan/phpdoc-parser\";s:10:\"\0*\0version\";s:5:\"2.3.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpstan\\phpdoc-parser\";}s:18:\"pragmarx/google2fa\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"pragmarx/google2fa\";s:10:\"\0*\0version\";s:5:\"9.1.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\pragmarx\\google2fa\";}s:13:\"predis/predis\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"predis/predis\";s:10:\"\0*\0version\";s:5:\"3.6.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^3.6\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\predis\\predis\";}s:9:\"psr/clock\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:9:\"psr/clock\";s:10:\"\0*\0version\";s:5:\"1.0.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:43:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psr\\clock\";}s:13:\"psr/container\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"psr/container\";s:10:\"\0*\0version\";s:5:\"2.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psr\\container\";}s:20:\"psr/event-dispatcher\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"psr/event-dispatcher\";s:10:\"\0*\0version\";s:5:\"1.0.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psr\\event-dispatcher\";}s:15:\"psr/http-client\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"psr/http-client\";s:10:\"\0*\0version\";s:5:\"1.0.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psr\\http-client\";}s:16:\"psr/http-factory\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"psr/http-factory\";s:10:\"\0*\0version\";s:5:\"1.1.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psr\\http-factory\";}s:16:\"psr/http-message\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"psr/http-message\";s:10:\"\0*\0version\";s:3:\"2.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psr\\http-message\";}s:7:\"psr/log\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:7:\"psr/log\";s:10:\"\0*\0version\";s:5:\"3.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:41:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psr\\log\";}s:16:\"psr/simple-cache\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"psr/simple-cache\";s:10:\"\0*\0version\";s:5:\"3.0.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psr\\simple-cache\";}s:9:\"psy/psysh\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:9:\"psy/psysh\";s:10:\"\0*\0version\";s:7:\"0.12.24\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:43:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\psy\\psysh\";}s:23:\"ralouphie/getallheaders\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"ralouphie/getallheaders\";s:10:\"\0*\0version\";s:5:\"3.0.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\ralouphie\\getallheaders\";}s:17:\"ramsey/collection\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"ramsey/collection\";s:10:\"\0*\0version\";s:5:\"2.1.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\ramsey\\collection\";}s:11:\"ramsey/uuid\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"ramsey/uuid\";s:10:\"\0*\0version\";s:5:\"4.9.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\ramsey\\uuid\";}s:25:\"sabberworm/php-css-parser\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"sabberworm/php-css-parser\";s:10:\"\0*\0version\";s:5:\"9.4.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sabberworm\\php-css-parser\";}s:12:\"spatie/image\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"spatie/image\";s:10:\"\0*\0version\";s:5:\"3.9.6\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spatie\\image\";}s:22:\"spatie/image-optimizer\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"spatie/image-optimizer\";s:10:\"\0*\0version\";s:6:\"1.10.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spatie\\image-optimizer\";}s:26:\"spatie/laravel-activitylog\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"spatie/laravel-activitylog\";s:10:\"\0*\0version\";s:6:\"4.12.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^4.0\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spatie\\laravel-activitylog\";}s:27:\"spatie/laravel-medialibrary\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"spatie/laravel-medialibrary\";s:10:\"\0*\0version\";s:7:\"11.23.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:6:\"^11.23\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spatie\\laravel-medialibrary\";}s:28:\"spatie/laravel-package-tools\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:28:\"spatie/laravel-package-tools\";s:10:\"\0*\0version\";s:6:\"1.93.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:62:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spatie\\laravel-package-tools\";}s:25:\"spatie/laravel-permission\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"spatie/laravel-permission\";s:10:\"\0*\0version\";s:6:\"6.25.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^6.0\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spatie\\laravel-permission\";}s:26:\"spatie/temporary-directory\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"spatie/temporary-directory\";s:10:\"\0*\0version\";s:5:\"2.4.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spatie\\temporary-directory\";}s:20:\"spomky-labs/cbor-php\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"spomky-labs/cbor-php\";s:10:\"\0*\0version\";s:5:\"3.3.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spomky-labs\\cbor-php\";}s:25:\"spomky-labs/pki-framework\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"spomky-labs/pki-framework\";s:10:\"\0*\0version\";s:5:\"1.6.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\spomky-labs\\pki-framework\";}s:13:\"symfony/clock\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"symfony/clock\";s:10:\"\0*\0version\";s:5:\"7.4.8\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\clock\";}s:15:\"symfony/console\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"symfony/console\";s:10:\"\0*\0version\";s:6:\"7.4.16\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\console\";}s:20:\"symfony/css-selector\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"symfony/css-selector\";s:10:\"\0*\0version\";s:5:\"7.4.9\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\css-selector\";}s:29:\"symfony/deprecation-contracts\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:29:\"symfony/deprecation-contracts\";s:10:\"\0*\0version\";s:5:\"3.7.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\deprecation-contracts\";}s:21:\"symfony/error-handler\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"symfony/error-handler\";s:10:\"\0*\0version\";s:6:\"7.4.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\error-handler\";}s:24:\"symfony/event-dispatcher\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:24:\"symfony/event-dispatcher\";s:10:\"\0*\0version\";s:6:\"7.4.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\event-dispatcher\";}s:34:\"symfony/event-dispatcher-contracts\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:34:\"symfony/event-dispatcher-contracts\";s:10:\"\0*\0version\";s:5:\"3.7.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:68:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\event-dispatcher-contracts\";}s:14:\"symfony/finder\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"symfony/finder\";s:10:\"\0*\0version\";s:6:\"7.4.14\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\finder\";}s:23:\"symfony/http-foundation\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"symfony/http-foundation\";s:10:\"\0*\0version\";s:6:\"7.4.16\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\http-foundation\";}s:19:\"symfony/http-kernel\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"symfony/http-kernel\";s:10:\"\0*\0version\";s:6:\"7.4.16\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\http-kernel\";}s:14:\"symfony/mailer\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"symfony/mailer\";s:10:\"\0*\0version\";s:6:\"7.4.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\mailer\";}s:12:\"symfony/mime\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"symfony/mime\";s:10:\"\0*\0version\";s:6:\"7.4.16\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\mime\";}s:22:\"symfony/polyfill-ctype\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"symfony/polyfill-ctype\";s:10:\"\0*\0version\";s:6:\"1.37.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-ctype\";}s:30:\"symfony/polyfill-intl-grapheme\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"symfony/polyfill-intl-grapheme\";s:10:\"\0*\0version\";s:6:\"1.41.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:64:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-intl-grapheme\";}s:25:\"symfony/polyfill-intl-idn\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"symfony/polyfill-intl-idn\";s:10:\"\0*\0version\";s:6:\"1.38.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-intl-idn\";}s:32:\"symfony/polyfill-intl-normalizer\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:32:\"symfony/polyfill-intl-normalizer\";s:10:\"\0*\0version\";s:6:\"1.38.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:66:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-intl-normalizer\";}s:25:\"symfony/polyfill-mbstring\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"symfony/polyfill-mbstring\";s:10:\"\0*\0version\";s:6:\"1.38.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-mbstring\";}s:22:\"symfony/polyfill-php80\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"symfony/polyfill-php80\";s:10:\"\0*\0version\";s:6:\"1.37.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-php80\";}s:22:\"symfony/polyfill-php83\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"symfony/polyfill-php83\";s:10:\"\0*\0version\";s:6:\"1.41.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-php83\";}s:22:\"symfony/polyfill-php84\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"symfony/polyfill-php84\";s:10:\"\0*\0version\";s:6:\"1.38.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-php84\";}s:22:\"symfony/polyfill-php85\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"symfony/polyfill-php85\";s:10:\"\0*\0version\";s:6:\"1.41.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-php85\";}s:21:\"symfony/polyfill-uuid\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"symfony/polyfill-uuid\";s:10:\"\0*\0version\";s:6:\"1.37.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\polyfill-uuid\";}s:15:\"symfony/process\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"symfony/process\";s:10:\"\0*\0version\";s:6:\"7.4.13\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\process\";}s:23:\"symfony/property-access\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"symfony/property-access\";s:10:\"\0*\0version\";s:6:\"7.4.16\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\property-access\";}s:21:\"symfony/property-info\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"symfony/property-info\";s:10:\"\0*\0version\";s:6:\"7.4.16\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\property-info\";}s:15:\"symfony/routing\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"symfony/routing\";s:10:\"\0*\0version\";s:6:\"7.4.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\routing\";}s:18:\"symfony/serializer\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"symfony/serializer\";s:10:\"\0*\0version\";s:6:\"7.4.16\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\serializer\";}s:25:\"symfony/service-contracts\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"symfony/service-contracts\";s:10:\"\0*\0version\";s:5:\"3.7.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\service-contracts\";}s:14:\"symfony/string\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"symfony/string\";s:10:\"\0*\0version\";s:6:\"7.4.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\string\";}s:19:\"symfony/translation\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"symfony/translation\";s:10:\"\0*\0version\";s:6:\"7.4.16\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\translation\";}s:29:\"symfony/translation-contracts\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:29:\"symfony/translation-contracts\";s:10:\"\0*\0version\";s:5:\"3.7.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\translation-contracts\";}s:17:\"symfony/type-info\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"symfony/type-info\";s:10:\"\0*\0version\";s:5:\"7.4.9\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\type-info\";}s:11:\"symfony/uid\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"symfony/uid\";s:10:\"\0*\0version\";s:5:\"7.4.9\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\uid\";}s:18:\"symfony/var-dumper\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"symfony/var-dumper\";s:10:\"\0*\0version\";s:6:\"7.4.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\var-dumper\";}s:21:\"thecodingmachine/safe\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"thecodingmachine/safe\";s:10:\"\0*\0version\";s:5:\"3.4.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\thecodingmachine\\safe\";}s:33:\"tijsverkoyen/css-to-inline-styles\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:33:\"tijsverkoyen/css-to-inline-styles\";s:10:\"\0*\0version\";s:5:\"2.4.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:67:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\tijsverkoyen\\css-to-inline-styles\";}s:16:\"vlucas/phpdotenv\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"vlucas/phpdotenv\";s:10:\"\0*\0version\";s:5:\"5.6.4\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\vlucas\\phpdotenv\";}s:19:\"voku/portable-ascii\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"voku/portable-ascii\";s:10:\"\0*\0version\";s:5:\"2.1.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\voku\\portable-ascii\";}s:17:\"web-auth/cose-lib\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"web-auth/cose-lib\";s:10:\"\0*\0version\";s:5:\"4.6.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\web-auth\\cose-lib\";}s:21:\"web-auth/webauthn-lib\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"web-auth/webauthn-lib\";s:10:\"\0*\0version\";s:5:\"5.3.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\web-auth\\webauthn-lib\";}s:16:\"webmozart/assert\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"webmozart/assert\";s:10:\"\0*\0version\";s:5:\"2.4.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\webmozart\\assert\";}s:17:\"brianium/paratest\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"brianium/paratest\";s:10:\"\0*\0version\";s:5:\"7.8.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\brianium\\paratest\";}s:14:\"fakerphp/faker\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"fakerphp/faker\";s:10:\"\0*\0version\";s:6:\"1.24.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:5:\"^1.23\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\fakerphp\\faker\";}s:22:\"fidry/cpu-core-counter\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"fidry/cpu-core-counter\";s:10:\"\0*\0version\";s:5:\"1.3.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\fidry\\cpu-core-counter\";}s:11:\"filp/whoops\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"filp/whoops\";s:10:\"\0*\0version\";s:6:\"2.18.4\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\filp\\whoops\";}s:21:\"hamcrest/hamcrest-php\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"hamcrest/hamcrest-php\";s:10:\"\0*\0version\";s:5:\"3.0.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\hamcrest\\hamcrest-php\";}s:17:\"iamcal/sql-parser\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"iamcal/sql-parser\";s:10:\"\0*\0version\";s:3:\"0.7\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\iamcal\\sql-parser\";}s:30:\"jean85/pretty-package-versions\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"jean85/pretty-package-versions\";s:10:\"\0*\0version\";s:5:\"2.1.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:64:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\jean85\\pretty-package-versions\";}s:17:\"larastan/larastan\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"larastan/larastan\";s:10:\"\0*\0version\";s:6:\"3.10.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:5:\"^3.10\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\larastan\\larastan\";}s:13:\"laravel/boost\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"laravel/boost\";s:10:\"\0*\0version\";s:5:\"2.5.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^2.5\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\boost\";}s:11:\"laravel/mcp\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"laravel/mcp\";s:10:\"\0*\0version\";s:5:\"0.9.4\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\mcp\";}s:12:\"laravel/pail\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"laravel/pail\";s:10:\"\0*\0version\";s:5:\"1.2.7\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:6:\"^1.2.2\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\pail\";}s:12:\"laravel/pint\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"laravel/pint\";s:10:\"\0*\0version\";s:6:\"1.30.4\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:5:\"^1.24\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\pint\";}s:14:\"laravel/roster\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"laravel/roster\";s:10:\"\0*\0version\";s:5:\"1.0.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\roster\";}s:12:\"laravel/sail\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"laravel/sail\";s:10:\"\0*\0version\";s:6:\"1.67.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:5:\"^1.41\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\laravel\\sail\";}s:15:\"mockery/mockery\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"mockery/mockery\";s:10:\"\0*\0version\";s:6:\"1.6.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^1.6\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\mockery\\mockery\";}s:17:\"myclabs/deep-copy\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"myclabs/deep-copy\";s:10:\"\0*\0version\";s:6:\"1.14.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\myclabs\\deep-copy\";}s:20:\"nunomaduro/collision\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"nunomaduro/collision\";s:10:\"\0*\0version\";s:5:\"8.9.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^8.6\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\nunomaduro\\collision\";}s:12:\"pestphp/pest\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"pestphp/pest\";s:10:\"\0*\0version\";s:5:\"3.8.7\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^3.8\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\pestphp\\pest\";}s:19:\"pestphp/pest-plugin\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"pestphp/pest-plugin\";s:10:\"\0*\0version\";s:5:\"3.0.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\pestphp\\pest-plugin\";}s:24:\"pestphp/pest-plugin-arch\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:24:\"pestphp/pest-plugin-arch\";s:10:\"\0*\0version\";s:5:\"3.1.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\pestphp\\pest-plugin-arch\";}s:27:\"pestphp/pest-plugin-laravel\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"pestphp/pest-plugin-laravel\";s:10:\"\0*\0version\";s:5:\"3.2.0\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:4:\"^3.2\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\pestphp\\pest-plugin-laravel\";}s:26:\"pestphp/pest-plugin-mutate\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"pestphp/pest-plugin-mutate\";s:10:\"\0*\0version\";s:5:\"3.0.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\pestphp\\pest-plugin-mutate\";}s:16:\"phar-io/manifest\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"phar-io/manifest\";s:10:\"\0*\0version\";s:5:\"2.0.4\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phar-io\\manifest\";}s:15:\"phar-io/version\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"phar-io/version\";s:10:\"\0*\0version\";s:5:\"3.2.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phar-io\\version\";}s:15:\"phpstan/phpstan\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"phpstan/phpstan\";s:10:\"\0*\0version\";s:5:\"2.2.8\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpstan\\phpstan\";}s:25:\"phpunit/php-code-coverage\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"phpunit/php-code-coverage\";s:10:\"\0*\0version\";s:7:\"11.0.12\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpunit\\php-code-coverage\";}s:25:\"phpunit/php-file-iterator\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"phpunit/php-file-iterator\";s:10:\"\0*\0version\";s:5:\"5.1.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpunit\\php-file-iterator\";}s:19:\"phpunit/php-invoker\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"phpunit/php-invoker\";s:10:\"\0*\0version\";s:5:\"5.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpunit\\php-invoker\";}s:25:\"phpunit/php-text-template\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"phpunit/php-text-template\";s:10:\"\0*\0version\";s:5:\"4.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpunit\\php-text-template\";}s:17:\"phpunit/php-timer\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"phpunit/php-timer\";s:10:\"\0*\0version\";s:5:\"7.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpunit\\php-timer\";}s:15:\"phpunit/phpunit\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"phpunit/phpunit\";s:10:\"\0*\0version\";s:7:\"11.5.56\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:8:\"^11.5.50\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\phpunit\\phpunit\";}s:20:\"sebastian/cli-parser\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"sebastian/cli-parser\";s:10:\"\0*\0version\";s:5:\"3.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\cli-parser\";}s:19:\"sebastian/code-unit\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"sebastian/code-unit\";s:10:\"\0*\0version\";s:5:\"3.0.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\code-unit\";}s:34:\"sebastian/code-unit-reverse-lookup\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:34:\"sebastian/code-unit-reverse-lookup\";s:10:\"\0*\0version\";s:5:\"4.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:68:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\code-unit-reverse-lookup\";}s:20:\"sebastian/comparator\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"sebastian/comparator\";s:10:\"\0*\0version\";s:5:\"6.3.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\comparator\";}s:20:\"sebastian/complexity\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"sebastian/complexity\";s:10:\"\0*\0version\";s:5:\"4.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\complexity\";}s:14:\"sebastian/diff\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"sebastian/diff\";s:10:\"\0*\0version\";s:5:\"6.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\diff\";}s:21:\"sebastian/environment\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"sebastian/environment\";s:10:\"\0*\0version\";s:5:\"7.2.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\environment\";}s:18:\"sebastian/exporter\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"sebastian/exporter\";s:10:\"\0*\0version\";s:5:\"6.3.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\exporter\";}s:22:\"sebastian/global-state\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"sebastian/global-state\";s:10:\"\0*\0version\";s:5:\"7.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\global-state\";}s:23:\"sebastian/lines-of-code\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"sebastian/lines-of-code\";s:10:\"\0*\0version\";s:5:\"3.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\lines-of-code\";}s:27:\"sebastian/object-enumerator\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"sebastian/object-enumerator\";s:10:\"\0*\0version\";s:5:\"6.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\object-enumerator\";}s:26:\"sebastian/object-reflector\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"sebastian/object-reflector\";s:10:\"\0*\0version\";s:5:\"4.0.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\object-reflector\";}s:27:\"sebastian/recursion-context\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"sebastian/recursion-context\";s:10:\"\0*\0version\";s:5:\"6.0.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\recursion-context\";}s:14:\"sebastian/type\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"sebastian/type\";s:10:\"\0*\0version\";s:5:\"5.1.3\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\type\";}s:17:\"sebastian/version\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"sebastian/version\";s:10:\"\0*\0version\";s:5:\"5.0.2\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\sebastian\\version\";}s:28:\"staabm/side-effects-detector\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:28:\"staabm/side-effects-detector\";s:10:\"\0*\0version\";s:5:\"1.0.5\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:62:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\staabm\\side-effects-detector\";}s:12:\"symfony/yaml\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"symfony/yaml\";s:10:\"\0*\0version\";s:6:\"7.4.15\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\symfony\\yaml\";}s:35:\"ta-tikoma/phpunit-architecture-test\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:35:\"ta-tikoma/phpunit-architecture-test\";s:10:\"\0*\0version\";s:5:\"0.8.7\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:69:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\ta-tikoma\\phpunit-architecture-test\";}s:17:\"theseer/tokenizer\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"theseer/tokenizer\";s:10:\"\0*\0version\";s:5:\"1.3.1\";s:9:\"\0*\0source\";r:8;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\vendor\\theseer\\tokenizer\";}}s:11:\"\0*\0packages\";O:32:\"Laravel\\Roster\\PackageCollection\":2:{s:8:\"\0*\0items\";a:175:{i:0;r:5;i:1;r:13;i:2;r:21;i:3;r:29;i:4;r:37;i:5;r:45;i:6;r:53;i:7;r:61;i:8;r:69;i:9;r:77;i:10;r:85;i:11;r:93;i:12;r:101;i:13;r:109;i:14;r:117;i:15;r:125;i:16;r:133;i:17;r:141;i:18;r:149;i:19;r:157;i:20;r:165;i:21;r:173;i:22;r:181;i:23;r:189;i:24;r:197;i:25;r:205;i:26;r:213;i:27;r:221;i:28;r:229;i:29;r:237;i:30;r:245;i:31;r:253;i:32;r:261;i:33;r:269;i:34;r:277;i:35;r:285;i:36;r:293;i:37;r:301;i:38;r:309;i:39;r:317;i:40;r:325;i:41;r:333;i:42;r:341;i:43;r:349;i:44;r:357;i:45;r:365;i:46;r:373;i:47;r:381;i:48;r:389;i:49;r:397;i:50;r:405;i:51;r:413;i:52;r:421;i:53;r:429;i:54;r:437;i:55;r:445;i:56;r:453;i:57;r:461;i:58;r:469;i:59;r:477;i:60;r:485;i:61;r:493;i:62;r:501;i:63;r:509;i:64;r:517;i:65;r:525;i:66;r:533;i:67;r:541;i:68;r:549;i:69;r:557;i:70;r:565;i:71;r:573;i:72;r:581;i:73;r:589;i:74;r:597;i:75;r:605;i:76;r:613;i:77;r:621;i:78;r:629;i:79;r:637;i:80;r:645;i:81;r:653;i:82;r:661;i:83;r:669;i:84;r:677;i:85;r:685;i:86;r:693;i:87;r:701;i:88;r:709;i:89;r:717;i:90;r:725;i:91;r:733;i:92;r:741;i:93;r:749;i:94;r:757;i:95;r:765;i:96;r:773;i:97;r:781;i:98;r:789;i:99;r:797;i:100;r:805;i:101;r:813;i:102;r:821;i:103;r:829;i:104;r:837;i:105;r:845;i:106;r:853;i:107;r:861;i:108;r:869;i:109;r:877;i:110;r:885;i:111;r:893;i:112;r:901;i:113;r:909;i:114;r:917;i:115;r:925;i:116;r:933;i:117;r:941;i:118;r:949;i:119;r:957;i:120;r:965;i:121;r:973;i:122;r:981;i:123;r:989;i:124;r:997;i:125;r:1005;i:126;r:1013;i:127;r:1021;i:128;r:1029;i:129;r:1037;i:130;r:1045;i:131;r:1053;i:132;r:1061;i:133;r:1069;i:134;r:1077;i:135;r:1085;i:136;r:1093;i:137;r:1101;i:138;r:1109;i:139;r:1117;i:140;r:1125;i:141;r:1133;i:142;r:1141;i:143;r:1149;i:144;r:1157;i:145;r:1165;i:146;r:1173;i:147;r:1181;i:148;r:1189;i:149;r:1197;i:150;r:1205;i:151;r:1213;i:152;r:1221;i:153;r:1229;i:154;r:1237;i:155;r:1245;i:156;r:1253;i:157;r:1261;i:158;r:1269;i:159;r:1277;i:160;r:1285;i:161;r:1293;i:162;r:1301;i:163;r:1309;i:164;r:1317;i:165;r:1325;i:166;r:1333;i:167;r:1341;i:168;r:1349;i:169;r:1357;i:170;r:1365;i:171;r:1373;i:172;r:1381;i:173;r:1389;i:174;r:1397;}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}}s:2:\"js\";O:37:\"Laravel\\Roster\\Ecosystems\\JsEcosystem\":3:{s:9:\"\0*\0byName\";a:160:{s:16:\"@orchidjs/sifter\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"@orchidjs/sifter\";s:10:\"\0*\0version\";s:5:\"1.1.0\";s:9:\"\0*\0source\";E:38:\"Laravel\\Roster\\Enums\\PackageSource:Npm\";s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@orchidjs\\sifter\";}s:26:\"@orchidjs/unicode-variants\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"@orchidjs/unicode-variants\";s:10:\"\0*\0version\";s:5:\"1.1.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:66:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@orchidjs\\unicode-variants\";}s:10:\"sortablejs\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"sortablejs\";s:10:\"\0*\0version\";s:6:\"1.15.7\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:7:\"^1.15.7\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\sortablejs\";}s:10:\"tom-select\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"tom-select\";s:10:\"\0*\0version\";s:5:\"2.6.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:0;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:6:\"^2.6.2\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\tom-select\";}s:18:\"@esbuild/aix-ppc64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"@esbuild/aix-ppc64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\aix-ppc64\";}s:20:\"@esbuild/android-arm\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"@esbuild/android-arm\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\android-arm\";}s:22:\"@esbuild/android-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"@esbuild/android-arm64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:62:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\android-arm64\";}s:20:\"@esbuild/android-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"@esbuild/android-x64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\android-x64\";}s:21:\"@esbuild/darwin-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"@esbuild/darwin-arm64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\darwin-arm64\";}s:19:\"@esbuild/darwin-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"@esbuild/darwin-x64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\darwin-x64\";}s:22:\"@esbuild/freebsd-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"@esbuild/freebsd-arm64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:62:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\freebsd-arm64\";}s:20:\"@esbuild/freebsd-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"@esbuild/freebsd-x64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\freebsd-x64\";}s:18:\"@esbuild/linux-arm\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"@esbuild/linux-arm\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-arm\";}s:20:\"@esbuild/linux-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"@esbuild/linux-arm64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-arm64\";}s:19:\"@esbuild/linux-ia32\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"@esbuild/linux-ia32\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-ia32\";}s:22:\"@esbuild/linux-loong64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"@esbuild/linux-loong64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:62:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-loong64\";}s:23:\"@esbuild/linux-mips64el\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"@esbuild/linux-mips64el\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-mips64el\";}s:20:\"@esbuild/linux-ppc64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"@esbuild/linux-ppc64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-ppc64\";}s:22:\"@esbuild/linux-riscv64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"@esbuild/linux-riscv64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:62:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-riscv64\";}s:20:\"@esbuild/linux-s390x\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"@esbuild/linux-s390x\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-s390x\";}s:18:\"@esbuild/linux-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"@esbuild/linux-x64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\linux-x64\";}s:21:\"@esbuild/netbsd-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"@esbuild/netbsd-arm64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\netbsd-arm64\";}s:19:\"@esbuild/netbsd-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"@esbuild/netbsd-x64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\netbsd-x64\";}s:22:\"@esbuild/openbsd-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:22:\"@esbuild/openbsd-arm64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:62:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\openbsd-arm64\";}s:20:\"@esbuild/openbsd-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"@esbuild/openbsd-x64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\openbsd-x64\";}s:26:\"@esbuild/openharmony-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"@esbuild/openharmony-arm64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:66:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\openharmony-arm64\";}s:18:\"@esbuild/sunos-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"@esbuild/sunos-x64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\sunos-x64\";}s:20:\"@esbuild/win32-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:20:\"@esbuild/win32-arm64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:60:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\win32-arm64\";}s:19:\"@esbuild/win32-ia32\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"@esbuild/win32-ia32\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\win32-ia32\";}s:18:\"@esbuild/win32-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"@esbuild/win32-x64\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@esbuild\\win32-x64\";}s:23:\"@jridgewell/gen-mapping\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"@jridgewell/gen-mapping\";s:10:\"\0*\0version\";s:6:\"0.3.13\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@jridgewell\\gen-mapping\";}s:21:\"@jridgewell/remapping\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:21:\"@jridgewell/remapping\";s:10:\"\0*\0version\";s:5:\"2.3.5\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:61:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@jridgewell\\remapping\";}s:23:\"@jridgewell/resolve-uri\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"@jridgewell/resolve-uri\";s:10:\"\0*\0version\";s:5:\"3.1.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@jridgewell\\resolve-uri\";}s:27:\"@jridgewell/sourcemap-codec\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"@jridgewell/sourcemap-codec\";s:10:\"\0*\0version\";s:5:\"1.5.5\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:67:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@jridgewell\\sourcemap-codec\";}s:25:\"@jridgewell/trace-mapping\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"@jridgewell/trace-mapping\";s:10:\"\0*\0version\";s:6:\"0.3.31\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:65:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@jridgewell\\trace-mapping\";}s:27:\"@napi-rs/lzma-linux-x64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"@napi-rs/lzma-linux-x64-gnu\";s:10:\"\0*\0version\";s:5:\"1.5.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:67:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@napi-rs\\lzma-linux-x64-gnu\";}s:31:\"@rollup/rollup-android-arm-eabi\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:31:\"@rollup/rollup-android-arm-eabi\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:71:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-android-arm-eabi\";}s:28:\"@rollup/rollup-android-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:28:\"@rollup/rollup-android-arm64\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:68:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-android-arm64\";}s:27:\"@rollup/rollup-darwin-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"@rollup/rollup-darwin-arm64\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:67:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-darwin-arm64\";}s:25:\"@rollup/rollup-darwin-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"@rollup/rollup-darwin-x64\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:65:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-darwin-x64\";}s:28:\"@rollup/rollup-freebsd-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:28:\"@rollup/rollup-freebsd-arm64\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:68:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-freebsd-arm64\";}s:26:\"@rollup/rollup-freebsd-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"@rollup/rollup-freebsd-x64\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:66:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-freebsd-x64\";}s:34:\"@rollup/rollup-linux-arm-gnueabihf\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:34:\"@rollup/rollup-linux-arm-gnueabihf\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:74:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-arm-gnueabihf\";}s:35:\"@rollup/rollup-linux-arm-musleabihf\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:35:\"@rollup/rollup-linux-arm-musleabihf\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:75:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-arm-musleabihf\";}s:30:\"@rollup/rollup-linux-arm64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"@rollup/rollup-linux-arm64-gnu\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:70:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-arm64-gnu\";}s:31:\"@rollup/rollup-linux-arm64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:31:\"@rollup/rollup-linux-arm64-musl\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:71:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-arm64-musl\";}s:32:\"@rollup/rollup-linux-loong64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:32:\"@rollup/rollup-linux-loong64-gnu\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:72:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-loong64-gnu\";}s:33:\"@rollup/rollup-linux-loong64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:33:\"@rollup/rollup-linux-loong64-musl\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:73:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-loong64-musl\";}s:30:\"@rollup/rollup-linux-ppc64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"@rollup/rollup-linux-ppc64-gnu\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:70:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-ppc64-gnu\";}s:31:\"@rollup/rollup-linux-ppc64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:31:\"@rollup/rollup-linux-ppc64-musl\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:71:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-ppc64-musl\";}s:32:\"@rollup/rollup-linux-riscv64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:32:\"@rollup/rollup-linux-riscv64-gnu\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:72:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-riscv64-gnu\";}s:33:\"@rollup/rollup-linux-riscv64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:33:\"@rollup/rollup-linux-riscv64-musl\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:73:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-riscv64-musl\";}s:30:\"@rollup/rollup-linux-s390x-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"@rollup/rollup-linux-s390x-gnu\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:70:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-s390x-gnu\";}s:28:\"@rollup/rollup-linux-x64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:28:\"@rollup/rollup-linux-x64-gnu\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:68:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-x64-gnu\";}s:29:\"@rollup/rollup-linux-x64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:29:\"@rollup/rollup-linux-x64-musl\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:69:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-linux-x64-musl\";}s:26:\"@rollup/rollup-openbsd-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"@rollup/rollup-openbsd-x64\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:66:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-openbsd-x64\";}s:32:\"@rollup/rollup-openharmony-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:32:\"@rollup/rollup-openharmony-arm64\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:72:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-openharmony-arm64\";}s:31:\"@rollup/rollup-win32-arm64-msvc\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:31:\"@rollup/rollup-win32-arm64-msvc\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:71:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-win32-arm64-msvc\";}s:30:\"@rollup/rollup-win32-ia32-msvc\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"@rollup/rollup-win32-ia32-msvc\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:70:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-win32-ia32-msvc\";}s:28:\"@rollup/rollup-win32-x64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:28:\"@rollup/rollup-win32-x64-gnu\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:68:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-win32-x64-gnu\";}s:29:\"@rollup/rollup-win32-x64-msvc\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:29:\"@rollup/rollup-win32-x64-msvc\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:69:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@rollup\\rollup-win32-x64-msvc\";}s:17:\"@tailwindcss/node\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"@tailwindcss/node\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\node\";}s:18:\"@tailwindcss/oxide\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"@tailwindcss/oxide\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide\";}s:32:\"@tailwindcss/oxide-android-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:32:\"@tailwindcss/oxide-android-arm64\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:72:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-android-arm64\";}s:31:\"@tailwindcss/oxide-darwin-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:31:\"@tailwindcss/oxide-darwin-arm64\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:71:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-darwin-arm64\";}s:29:\"@tailwindcss/oxide-darwin-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:29:\"@tailwindcss/oxide-darwin-x64\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:69:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-darwin-x64\";}s:30:\"@tailwindcss/oxide-freebsd-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"@tailwindcss/oxide-freebsd-x64\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:70:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-freebsd-x64\";}s:38:\"@tailwindcss/oxide-linux-arm-gnueabihf\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:38:\"@tailwindcss/oxide-linux-arm-gnueabihf\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:78:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-linux-arm-gnueabihf\";}s:34:\"@tailwindcss/oxide-linux-arm64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:34:\"@tailwindcss/oxide-linux-arm64-gnu\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:74:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-linux-arm64-gnu\";}s:35:\"@tailwindcss/oxide-linux-arm64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:35:\"@tailwindcss/oxide-linux-arm64-musl\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:75:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-linux-arm64-musl\";}s:32:\"@tailwindcss/oxide-linux-x64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:32:\"@tailwindcss/oxide-linux-x64-gnu\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:72:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-linux-x64-gnu\";}s:33:\"@tailwindcss/oxide-linux-x64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:33:\"@tailwindcss/oxide-linux-x64-musl\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:73:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-linux-x64-musl\";}s:30:\"@tailwindcss/oxide-wasm32-wasi\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:30:\"@tailwindcss/oxide-wasm32-wasi\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:70:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-wasm32-wasi\";}s:35:\"@tailwindcss/oxide-win32-arm64-msvc\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:35:\"@tailwindcss/oxide-win32-arm64-msvc\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:75:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-win32-arm64-msvc\";}s:33:\"@tailwindcss/oxide-win32-x64-msvc\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:33:\"@tailwindcss/oxide-win32-x64-msvc\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:73:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\oxide-win32-x64-msvc\";}s:17:\"@tailwindcss/vite\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"@tailwindcss/vite\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:6:\"^4.0.0\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@tailwindcss\\vite\";}s:13:\"@types/estree\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"@types/estree\";s:10:\"\0*\0version\";s:5:\"1.0.9\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\@types\\estree\";}s:10:\"agent-base\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"agent-base\";s:10:\"\0*\0version\";s:5:\"6.0.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\agent-base\";}s:10:\"ansi-regex\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"ansi-regex\";s:10:\"\0*\0version\";s:5:\"5.0.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\ansi-regex\";}s:11:\"ansi-styles\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"ansi-styles\";s:10:\"\0*\0version\";s:5:\"4.3.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\ansi-styles\";}s:8:\"asynckit\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:8:\"asynckit\";s:10:\"\0*\0version\";s:5:\"0.4.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\asynckit\";}s:5:\"axios\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:5:\"axios\";s:10:\"\0*\0version\";s:6:\"1.19.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:7:\"^1.11.0\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\axios\";}s:23:\"call-bind-apply-helpers\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"call-bind-apply-helpers\";s:10:\"\0*\0version\";s:5:\"1.0.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\call-bind-apply-helpers\";}s:5:\"chalk\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:5:\"chalk\";s:10:\"\0*\0version\";s:5:\"4.1.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\chalk\";}s:5:\"cliui\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:5:\"cliui\";s:10:\"\0*\0version\";s:5:\"8.0.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\cliui\";}s:13:\"color-convert\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"color-convert\";s:10:\"\0*\0version\";s:5:\"2.0.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\color-convert\";}s:10:\"color-name\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"color-name\";s:10:\"\0*\0version\";s:5:\"1.1.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\color-name\";}s:15:\"combined-stream\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"combined-stream\";s:10:\"\0*\0version\";s:5:\"1.0.8\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\combined-stream\";}s:12:\"concurrently\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"concurrently\";s:10:\"\0*\0version\";s:5:\"9.2.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:6:\"^9.0.1\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\concurrently\";}s:5:\"debug\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:5:\"debug\";s:10:\"\0*\0version\";s:5:\"4.4.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\debug\";}s:14:\"delayed-stream\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"delayed-stream\";s:10:\"\0*\0version\";s:5:\"1.0.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\delayed-stream\";}s:11:\"detect-libc\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"detect-libc\";s:10:\"\0*\0version\";s:5:\"2.1.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\detect-libc\";}s:12:\"dunder-proto\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"dunder-proto\";s:10:\"\0*\0version\";s:5:\"1.0.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\dunder-proto\";}s:11:\"emoji-regex\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"emoji-regex\";s:10:\"\0*\0version\";s:5:\"8.0.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\emoji-regex\";}s:16:\"enhanced-resolve\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"enhanced-resolve\";s:10:\"\0*\0version\";s:6:\"5.24.5\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\enhanced-resolve\";}s:18:\"es-define-property\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"es-define-property\";s:10:\"\0*\0version\";s:5:\"1.0.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\es-define-property\";}s:9:\"es-errors\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:9:\"es-errors\";s:10:\"\0*\0version\";s:5:\"1.3.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\es-errors\";}s:15:\"es-object-atoms\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"es-object-atoms\";s:10:\"\0*\0version\";s:5:\"1.1.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\es-object-atoms\";}s:18:\"es-set-tostringtag\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:18:\"es-set-tostringtag\";s:10:\"\0*\0version\";s:5:\"2.1.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:58:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\es-set-tostringtag\";}s:7:\"esbuild\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:7:\"esbuild\";s:10:\"\0*\0version\";s:6:\"0.28.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\esbuild\";}s:8:\"escalade\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:8:\"escalade\";s:10:\"\0*\0version\";s:5:\"3.2.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\escalade\";}s:4:\"fdir\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:4:\"fdir\";s:10:\"\0*\0version\";s:5:\"6.5.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:44:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\fdir\";}s:16:\"follow-redirects\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:16:\"follow-redirects\";s:10:\"\0*\0version\";s:6:\"1.16.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:56:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\follow-redirects\";}s:9:\"form-data\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:9:\"form-data\";s:10:\"\0*\0version\";s:5:\"4.0.6\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\form-data\";}s:8:\"fsevents\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:8:\"fsevents\";s:10:\"\0*\0version\";s:5:\"2.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\fsevents\";}s:13:\"function-bind\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"function-bind\";s:10:\"\0*\0version\";s:5:\"1.1.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\function-bind\";}s:15:\"get-caller-file\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"get-caller-file\";s:10:\"\0*\0version\";s:5:\"2.0.5\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\get-caller-file\";}s:13:\"get-intrinsic\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"get-intrinsic\";s:10:\"\0*\0version\";s:5:\"1.3.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\get-intrinsic\";}s:9:\"get-proto\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:9:\"get-proto\";s:10:\"\0*\0version\";s:5:\"1.0.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\get-proto\";}s:4:\"gopd\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:4:\"gopd\";s:10:\"\0*\0version\";s:5:\"1.2.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:44:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\gopd\";}s:11:\"graceful-fs\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"graceful-fs\";s:10:\"\0*\0version\";s:6:\"4.2.11\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\graceful-fs\";}s:8:\"has-flag\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:8:\"has-flag\";s:10:\"\0*\0version\";s:5:\"4.0.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:48:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\has-flag\";}s:11:\"has-symbols\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"has-symbols\";s:10:\"\0*\0version\";s:5:\"1.1.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\has-symbols\";}s:15:\"has-tostringtag\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"has-tostringtag\";s:10:\"\0*\0version\";s:5:\"1.0.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\has-tostringtag\";}s:6:\"hasown\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:6:\"hasown\";s:10:\"\0*\0version\";s:5:\"2.0.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\hasown\";}s:17:\"https-proxy-agent\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"https-proxy-agent\";s:10:\"\0*\0version\";s:5:\"5.0.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\https-proxy-agent\";}s:23:\"is-fullwidth-code-point\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"is-fullwidth-code-point\";s:10:\"\0*\0version\";s:5:\"3.0.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\is-fullwidth-code-point\";}s:4:\"jiti\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:4:\"jiti\";s:10:\"\0*\0version\";s:5:\"2.7.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:44:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\jiti\";}s:19:\"laravel-vite-plugin\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:19:\"laravel-vite-plugin\";s:10:\"\0*\0version\";s:5:\"2.1.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:6:\"^2.0.0\";s:7:\"\0*\0path\";s:59:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\laravel-vite-plugin\";}s:12:\"lightningcss\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"lightningcss\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss\";}s:26:\"lightningcss-android-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"lightningcss-android-arm64\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:66:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-android-arm64\";}s:25:\"lightningcss-darwin-arm64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:25:\"lightningcss-darwin-arm64\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:65:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-darwin-arm64\";}s:23:\"lightningcss-darwin-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"lightningcss-darwin-x64\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-darwin-x64\";}s:24:\"lightningcss-freebsd-x64\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:24:\"lightningcss-freebsd-x64\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:64:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-freebsd-x64\";}s:32:\"lightningcss-linux-arm-gnueabihf\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:32:\"lightningcss-linux-arm-gnueabihf\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:72:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-linux-arm-gnueabihf\";}s:28:\"lightningcss-linux-arm64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:28:\"lightningcss-linux-arm64-gnu\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:68:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-linux-arm64-gnu\";}s:29:\"lightningcss-linux-arm64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:29:\"lightningcss-linux-arm64-musl\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:69:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-linux-arm64-musl\";}s:26:\"lightningcss-linux-x64-gnu\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:26:\"lightningcss-linux-x64-gnu\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:66:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-linux-x64-gnu\";}s:27:\"lightningcss-linux-x64-musl\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"lightningcss-linux-x64-musl\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:67:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-linux-x64-musl\";}s:29:\"lightningcss-win32-arm64-msvc\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:29:\"lightningcss-win32-arm64-msvc\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:69:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-win32-arm64-msvc\";}s:27:\"lightningcss-win32-x64-msvc\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:27:\"lightningcss-win32-x64-msvc\";s:10:\"\0*\0version\";s:6:\"1.32.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:67:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\lightningcss-win32-x64-msvc\";}s:12:\"magic-string\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"magic-string\";s:10:\"\0*\0version\";s:7:\"0.30.21\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\magic-string\";}s:15:\"math-intrinsics\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:15:\"math-intrinsics\";s:10:\"\0*\0version\";s:5:\"1.1.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:55:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\math-intrinsics\";}s:7:\"mime-db\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:7:\"mime-db\";s:10:\"\0*\0version\";s:6:\"1.52.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\mime-db\";}s:10:\"mime-types\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"mime-types\";s:10:\"\0*\0version\";s:6:\"2.1.35\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\mime-types\";}s:2:\"ms\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:2:\"ms\";s:10:\"\0*\0version\";s:5:\"2.1.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:42:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\ms\";}s:6:\"nanoid\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:6:\"nanoid\";s:10:\"\0*\0version\";s:6:\"3.3.18\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\nanoid\";}s:10:\"picocolors\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"picocolors\";s:10:\"\0*\0version\";s:5:\"1.1.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\picocolors\";}s:9:\"picomatch\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:9:\"picomatch\";s:10:\"\0*\0version\";s:5:\"4.0.5\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\picomatch\";}s:7:\"postcss\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:7:\"postcss\";s:10:\"\0*\0version\";s:6:\"8.5.26\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\postcss\";}s:14:\"proxy-from-env\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"proxy-from-env\";s:10:\"\0*\0version\";s:5:\"2.1.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\proxy-from-env\";}s:17:\"require-directory\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:17:\"require-directory\";s:10:\"\0*\0version\";s:5:\"2.1.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:57:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\require-directory\";}s:6:\"rollup\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:6:\"rollup\";s:10:\"\0*\0version\";s:6:\"4.62.4\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:46:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\rollup\";}s:4:\"rxjs\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:4:\"rxjs\";s:10:\"\0*\0version\";s:5:\"7.8.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:44:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\rxjs\";}s:11:\"shell-quote\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"shell-quote\";s:10:\"\0*\0version\";s:5:\"1.9.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\shell-quote\";}s:13:\"source-map-js\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:13:\"source-map-js\";s:10:\"\0*\0version\";s:5:\"1.2.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:53:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\source-map-js\";}s:12:\"string-width\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"string-width\";s:10:\"\0*\0version\";s:5:\"4.2.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\string-width\";}s:10:\"strip-ansi\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"strip-ansi\";s:10:\"\0*\0version\";s:5:\"6.0.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\strip-ansi\";}s:14:\"supports-color\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:14:\"supports-color\";s:10:\"\0*\0version\";s:5:\"8.1.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:54:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\supports-color\";}s:11:\"tailwindcss\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:11:\"tailwindcss\";s:10:\"\0*\0version\";s:5:\"4.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:6:\"^4.0.0\";s:7:\"\0*\0path\";s:51:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\tailwindcss\";}s:7:\"tapable\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:7:\"tapable\";s:10:\"\0*\0version\";s:5:\"2.3.3\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:47:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\tapable\";}s:10:\"tinyglobby\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:10:\"tinyglobby\";s:10:\"\0*\0version\";s:6:\"0.2.17\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:50:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\tinyglobby\";}s:9:\"tree-kill\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:9:\"tree-kill\";s:10:\"\0*\0version\";s:5:\"1.2.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\tree-kill\";}s:5:\"tslib\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:5:\"tslib\";s:10:\"\0*\0version\";s:5:\"2.8.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\tslib\";}s:4:\"vite\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:4:\"vite\";s:10:\"\0*\0version\";s:5:\"7.3.6\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:1;s:13:\"\0*\0constraint\";s:6:\"^7.0.7\";s:7:\"\0*\0path\";s:44:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\vite\";}s:23:\"vite-plugin-full-reload\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:23:\"vite-plugin-full-reload\";s:10:\"\0*\0version\";s:5:\"1.2.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:63:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\vite-plugin-full-reload\";}s:9:\"wrap-ansi\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:9:\"wrap-ansi\";s:10:\"\0*\0version\";s:5:\"7.0.0\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:49:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\wrap-ansi\";}s:4:\"y18n\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:4:\"y18n\";s:10:\"\0*\0version\";s:5:\"5.0.8\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:44:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\y18n\";}s:5:\"yargs\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:5:\"yargs\";s:10:\"\0*\0version\";s:6:\"17.7.2\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:45:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\yargs\";}s:12:\"yargs-parser\";O:22:\"Laravel\\Roster\\Package\":7:{s:7:\"\0*\0name\";s:12:\"yargs-parser\";s:10:\"\0*\0version\";s:6:\"21.1.1\";s:9:\"\0*\0source\";r:1588;s:6:\"\0*\0dev\";b:1;s:9:\"\0*\0direct\";b:0;s:13:\"\0*\0constraint\";s:0:\"\";s:7:\"\0*\0path\";s:52:\"E:\\xampp8.2\\htdocs\\gdn_crm\\node_modules\\yargs-parser\";}}s:11:\"\0*\0packages\";O:32:\"Laravel\\Roster\\PackageCollection\":2:{s:8:\"\0*\0items\";a:160:{i:0;r:1585;i:1;r:1593;i:2;r:1601;i:3;r:1609;i:4;r:1617;i:5;r:1625;i:6;r:1633;i:7;r:1641;i:8;r:1649;i:9;r:1657;i:10;r:1665;i:11;r:1673;i:12;r:1681;i:13;r:1689;i:14;r:1697;i:15;r:1705;i:16;r:1713;i:17;r:1721;i:18;r:1729;i:19;r:1737;i:20;r:1745;i:21;r:1753;i:22;r:1761;i:23;r:1769;i:24;r:1777;i:25;r:1785;i:26;r:1793;i:27;r:1801;i:28;r:1809;i:29;r:1817;i:30;r:1825;i:31;r:1833;i:32;r:1841;i:33;r:1849;i:34;r:1857;i:35;r:1865;i:36;r:1873;i:37;r:1881;i:38;r:1889;i:39;r:1897;i:40;r:1905;i:41;r:1913;i:42;r:1921;i:43;r:1929;i:44;r:1937;i:45;r:1945;i:46;r:1953;i:47;r:1961;i:48;r:1969;i:49;r:1977;i:50;r:1985;i:51;r:1993;i:52;r:2001;i:53;r:2009;i:54;r:2017;i:55;r:2025;i:56;r:2033;i:57;r:2041;i:58;r:2049;i:59;r:2057;i:60;r:2065;i:61;r:2073;i:62;r:2081;i:63;r:2089;i:64;r:2097;i:65;r:2105;i:66;r:2113;i:67;r:2121;i:68;r:2129;i:69;r:2137;i:70;r:2145;i:71;r:2153;i:72;r:2161;i:73;r:2169;i:74;r:2177;i:75;r:2185;i:76;r:2193;i:77;r:2201;i:78;r:2209;i:79;r:2217;i:80;r:2225;i:81;r:2233;i:82;r:2241;i:83;r:2249;i:84;r:2257;i:85;r:2265;i:86;r:2273;i:87;r:2281;i:88;r:2289;i:89;r:2297;i:90;r:2305;i:91;r:2313;i:92;r:2321;i:93;r:2329;i:94;r:2337;i:95;r:2345;i:96;r:2353;i:97;r:2361;i:98;r:2369;i:99;r:2377;i:100;r:2385;i:101;r:2393;i:102;r:2401;i:103;r:2409;i:104;r:2417;i:105;r:2425;i:106;r:2433;i:107;r:2441;i:108;r:2449;i:109;r:2457;i:110;r:2465;i:111;r:2473;i:112;r:2481;i:113;r:2489;i:114;r:2497;i:115;r:2505;i:116;r:2513;i:117;r:2521;i:118;r:2529;i:119;r:2537;i:120;r:2545;i:121;r:2553;i:122;r:2561;i:123;r:2569;i:124;r:2577;i:125;r:2585;i:126;r:2593;i:127;r:2601;i:128;r:2609;i:129;r:2617;i:130;r:2625;i:131;r:2633;i:132;r:2641;i:133;r:2649;i:134;r:2657;i:135;r:2665;i:136;r:2673;i:137;r:2681;i:138;r:2689;i:139;r:2697;i:140;r:2705;i:141;r:2713;i:142;r:2721;i:143;r:2729;i:144;r:2737;i:145;r:2745;i:146;r:2753;i:147;r:2761;i:148;r:2769;i:149;r:2777;i:150;r:2785;i:151;r:2793;i:152;r:2801;i:153;r:2809;i:154;r:2817;i:155;r:2825;i:156;r:2833;i:157;r:2841;i:158;r:2849;i:159;r:2857;}s:28:\"\0*\0escapeWhenCastingToString\";b:0;}s:17:\"\0*\0packageManager\";E:41:\"Laravel\\Roster\\Enums\\JsPackageManager:Npm\";}s:6:\"stacks\";O:30:\"Laravel\\Roster\\Support\\EnumSet\":1:{s:8:\"\0*\0cases\";a:1:{i:0;E:35:\"Laravel\\Roster\\Enums\\Stack:Livewire\";}}s:21:\"browserTestFrameworks\";O:30:\"Laravel\\Roster\\Support\\EnumSet\":1:{s:8:\"\0*\0cases\";a:0:{}}s:9:\"frontends\";O:30:\"Laravel\\Roster\\Support\\EnumSet\":1:{s:8:\"\0*\0cases\";a:0:{}}s:6:\"agents\";O:30:\"Laravel\\Roster\\Support\\EnumSet\":1:{s:8:\"\0*\0cases\";a:3:{i:0;E:37:\"Laravel\\Roster\\Enums\\Agent:ClaudeCode\";i:1;E:32:\"Laravel\\Roster\\Enums\\Agent:Codex\";i:2;E:32:\"Laravel\\Roster\\Enums\\Agent:Junie\";}}s:7:\"editors\";O:30:\"Laravel\\Roster\\Support\\EnumSet\":1:{s:8:\"\0*\0cases\";a:0:{}}}', 1789037101);
INSERT INTO `cache` (`key`, `value`, `expiration`) VALUES
('gdn-crm-cache-settings:group:localisation', 'a:0:{}', 2103789109),
('gdn-crm-cache-settings:group:notifications', 'a:0:{}', 2104397064),
('gdn-crm-cache-settings:group:storage', 'a:0:{}', 2104397065);

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `address_line_1` varchar(255) DEFAULT NULL,
  `address_line_2` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(255) DEFAULT NULL,
  `postal_code` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `timezone` varchar(255) NOT NULL DEFAULT 'UTC',
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `fiscal_year_start_month` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `name`, `address_line_1`, `address_line_2`, `city`, `state`, `postal_code`, `country`, `timezone`, `currency`, `fiscal_year_start_month`, `created_at`, `updated_at`) VALUES
(1, 'Golden Info Tech', NULL, NULL, 'Dhaka', NULL, NULL, NULL, 'Asia/Dhaka', 'USD', 1, '2026-09-02 02:57:28', '2026-09-05 23:57:26');

-- --------------------------------------------------------

--
-- Table structure for table `contacts`
--

CREATE TABLE `contacts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `address_line_1` varchar(255) DEFAULT NULL,
  `address_line_2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `owner_id` bigint(20) UNSIGNED NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `merged_into_id` bigint(20) UNSIGNED DEFAULT NULL,
  `merged_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deals`
--

CREATE TABLE `deals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `lead_id` bigint(20) UNSIGNED DEFAULT NULL,
  `pipeline_id` bigint(20) UNSIGNED DEFAULT NULL,
  `value` decimal(15,2) DEFAULT NULL,
  `expected_close_date` date DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `close_reason` varchar(32) DEFAULT NULL,
  `close_notes` text DEFAULT NULL,
  `stage` varchar(32) NOT NULL DEFAULT 'new',
  `description` text DEFAULT NULL,
  `owner_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deal_stage_entries`
--

CREATE TABLE `deal_stage_entries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `deal_id` bigint(20) UNSIGNED NOT NULL,
  `pipeline_id` bigint(20) UNSIGNED DEFAULT NULL,
  `stage_key` varchar(64) NOT NULL,
  `stage_name` varchar(255) NOT NULL,
  `outcome` varchar(10) NOT NULL DEFAULT 'open',
  `entered_at` datetime NOT NULL,
  `left_at` datetime DEFAULT NULL,
  `duration_seconds` bigint(20) UNSIGNED DEFAULT NULL,
  `moved_by_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `documentable_type` varchar(255) NOT NULL,
  `documentable_id` bigint(20) UNSIGNED NOT NULL,
  `uploaded_by_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `duplicate_keys`
--

CREATE TABLE `duplicate_keys` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `keyable_type` varchar(255) NOT NULL,
  `keyable_id` bigint(20) UNSIGNED NOT NULL,
  `kind` varchar(20) NOT NULL,
  `value` varchar(191) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `import_runs`
--

CREATE TABLE `import_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `module` varchar(32) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL,
  `mapping` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`mapping`)),
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `total_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `imported_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `failed_rows` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `errors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`errors`)),
  `failure_reason` text DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `mobile` varchar(50) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address_line_1` varchar(255) DEFAULT NULL,
  `address_line_2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'new',
  `source` varchar(255) DEFAULT NULL,
  `estimated_value` decimal(15,2) DEFAULT NULL,
  `score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `scored_at` timestamp NULL DEFAULT NULL,
  `converted_at` timestamp NULL DEFAULT NULL,
  `converted_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `converted_contact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `converted_deal_id` bigint(20) UNSIGNED DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status_changed_at` datetime DEFAULT NULL,
  `owner_id` bigint(20) UNSIGNED NOT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `merged_into_id` bigint(20) UNSIGNED DEFAULT NULL,
  `merged_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_scoring_rules`
--

CREATE TABLE `lead_scoring_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `kind` varchar(20) NOT NULL DEFAULT 'score',
  `label` varchar(255) NOT NULL,
  `field` varchar(64) NOT NULL,
  `operator` varchar(32) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  `second_value` varchar(255) DEFAULT NULL,
  `selected` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`selected`)),
  `points` smallint(6) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `position` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_histories`
--

CREATE TABLE `login_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `event` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_histories`
--

INSERT INTO `login_histories` (`id`, `user_id`, `email`, `event`, `ip_address`, `user_agent`, `created_at`, `updated_at`) VALUES
(1, 1, 'nayem@goldeninfotech.com.bd', 'failed', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 02:56:19', '2026-09-02 02:56:19'),
(2, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 02:56:54', '2026-09-02 02:56:54'),
(3, 1, 'nayem@goldeninfotech.com.bd', 'logout', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 03:04:08', '2026-09-02 03:04:08'),
(4, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:154.0) Gecko/20100101 Firefox/154.0', '2026-09-02 03:18:21', '2026-09-02 03:18:21'),
(5, NULL, 'admin@gazipump.com', 'failed', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:154.0) Gecko/20100101 Firefox/154.0', '2026-09-02 03:32:26', '2026-09-02 03:32:26'),
(6, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:24:50', '2026-09-02 04:24:50'),
(7, 1, 'nayem@goldeninfotech.com.bd', 'logout', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:25:14', '2026-09-02 04:25:14'),
(8, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:26:18', '2026-09-02 04:26:18'),
(9, 1, 'nayem@goldeninfotech.com.bd', 'logout', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:26:27', '2026-09-02 04:26:27'),
(10, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:27:10', '2026-09-02 04:27:10'),
(11, 1, 'nayem@goldeninfotech.com.bd', 'logout', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:27:12', '2026-09-02 04:27:12'),
(12, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:27:41', '2026-09-02 04:27:41'),
(13, 1, 'nayem@goldeninfotech.com.bd', 'logout', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:28:51', '2026-09-02 04:28:51'),
(14, 2, 'colleague@example.com', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:29:05', '2026-09-02 04:29:05'),
(15, 2, 'colleague@example.com', 'logout', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:29:28', '2026-09-02 04:29:28'),
(16, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 04:29:42', '2026-09-02 04:29:42'),
(17, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Symfony', '2026-09-02 05:36:27', '2026-09-02 05:36:27'),
(18, 2, 'colleague@example.com', 'failed', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 21:31:31', '2026-09-02 21:31:31'),
(19, NULL, 'uikit-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.40609.1 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 22:07:36', '2026-09-02 22:07:36'),
(20, NULL, 'uikit-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.2 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-02 23:45:33', '2026-09-02 23:45:33'),
(21, NULL, 'uikit-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.2 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 00:50:13', '2026-09-03 00:50:13'),
(22, NULL, 'uikit-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.2 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 00:50:57', '2026-09-03 00:50:57'),
(23, NULL, 'uikit-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 02:14:55', '2026-09-03 02:14:55'),
(24, NULL, 'uikit-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 03:01:52', '2026-09-03 03:01:52'),
(25, NULL, 'uikit-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 03:02:17', '2026-09-03 03:02:17'),
(26, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:154.0) Gecko/20100101 Firefox/154.0', '2026-09-03 03:14:32', '2026-09-03 03:14:32'),
(27, NULL, 'score-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 03:44:14', '2026-09-03 03:44:14'),
(28, NULL, 'score-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 03:47:06', '2026-09-03 03:47:06'),
(29, NULL, 'filter-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 04:26:24', '2026-09-03 04:26:24'),
(30, NULL, 'filter-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 04:27:07', '2026-09-03 04:27:07'),
(31, NULL, 'sel-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 05:15:15', '2026-09-03 05:15:15'),
(32, NULL, 'sel-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-03 05:15:42', '2026-09-03 05:15:42'),
(33, NULL, 'dup-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-05 21:52:34', '2026-09-05 21:52:34'),
(34, 1, 'nayem@goldeninfotech.com.bd', 'login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 OPR/135.0.0.0', '2026-09-05 22:00:26', '2026-09-05 22:00:26'),
(35, NULL, 'conv-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-05 22:30:21', '2026-09-05 22:30:21'),
(36, NULL, 'conv-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-05 22:30:57', '2026-09-05 22:30:57'),
(37, 2, 'colleague@example.com', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:155.0) Gecko/20100101 Firefox/155.0', '2026-09-05 22:37:35', '2026-09-05 22:37:35'),
(38, NULL, 'imp-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-05 23:19:29', '2026-09-05 23:19:29'),
(39, NULL, 'imp-verify@example.test', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-05 23:19:55', '2026-09-05 23:19:55'),
(40, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:155.0) Gecko/20100101 Firefox/155.0', '2026-09-05 23:26:23', '2026-09-05 23:26:23'),
(41, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36 OPR/135.0.0.0', '2026-09-05 23:54:04', '2026-09-05 23:54:04'),
(42, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-05 23:58:06', '2026-09-05 23:58:06'),
(43, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.44121.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-05 23:58:34', '2026-09-05 23:58:34'),
(44, 1, 'nayem@goldeninfotech.com.bd', 'login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.46388.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-06 03:03:11', '2026-09-06 03:03:11'),
(45, 1, 'nayem@goldeninfotech.com.bd', 'login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.46388.4 Chrome/148.0.7778.280 Safari/537.36 MSIX', '2026-09-06 03:18:12', '2026-09-06 03:18:12'),
(46, 1, 'nayem@goldeninfotech.com.bd', 'login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.49585.0 Chrome/152.0.7977.76 Safari/537.36 MSIX', '2026-09-10 02:34:28', '2026-09-10 02:34:28'),
(47, 1, 'nayem@goldeninfotech.com.bd', 'login', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:155.0) Gecko/20100101 Firefox/155.0', '2026-09-10 02:53:59', '2026-09-10 02:53:59'),
(48, 1, 'nayem@goldeninfotech.com.bd', 'login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.49585.0 Chrome/152.0.7977.76 Safari/537.36 MSIX', '2026-09-10 03:57:29', '2026-09-10 03:57:29'),
(49, 1, 'nayem@goldeninfotech.com.bd', 'login', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.49585.0 Chrome/152.0.7977.76 Safari/537.36 MSIX', '2026-09-10 04:22:22', '2026-09-10 04:22:22');

-- --------------------------------------------------------

--
-- Table structure for table `media`
--

CREATE TABLE `media` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) DEFAULT NULL,
  `collection_name` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `disk` varchar(255) NOT NULL,
  `conversions_disk` varchar(255) DEFAULT NULL,
  `size` bigint(20) UNSIGNED NOT NULL,
  `manipulations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`manipulations`)),
  `custom_properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`custom_properties`)),
  `generated_conversions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`generated_conversions`)),
  `responsive_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`responsive_images`)),
  `order_column` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_08_20_092708_create_permission_tables', 1),
(5, '2026_08_20_092708_create_personal_access_tokens_table', 1),
(6, '2026_08_20_092709_create_activity_log_table', 1),
(7, '2026_08_20_092710_add_event_column_to_activity_log_table', 1),
(8, '2026_08_20_092711_add_batch_uuid_column_to_activity_log_table', 1),
(9, '2026_08_20_092711_create_media_table', 1),
(10, '2026_08_20_105233_create_teams_table', 1),
(11, '2026_08_20_105234_add_current_team_id_to_users_table', 1),
(12, '2026_08_20_105234_create_team_user_table', 1),
(13, '2026_08_20_105235_add_data_access_level_to_roles_table', 1),
(14, '2026_08_20_105235_create_settings_table', 1),
(15, '2026_08_20_112531_create_companies_table', 2),
(16, '2026_09_02_084237_add_two_factor_columns_to_users_table', 3),
(17, '2026_09_02_084337_create_login_histories_table', 3),
(18, '2026_09_02_100617_create_user_invitations_table', 4),
(19, '2026_09_02_100618_add_soft_deletes_to_users_table', 4),
(21, '2026_09_02_114015_create_user_view_preferences_table', 5),
(22, '2026_09_02_115541_create_notifications_table', 6),
(23, '2026_09_03_051246_create_notification_tables', 7),
(24, '2026_09_03_062601_create_accounts_table', 8),
(25, '2026_09_03_075150_create_contacts_table', 9),
(26, '2026_09_03_083749_create_leads_table', 10),
(27, '2026_09_03_092119_add_score_to_leads_table', 11),
(28, '2026_09_03_092119_create_lead_scoring_rules_table', 11),
(29, '2026_09_03_113810_create_duplicate_keys_table', 12),
(30, '2026_09_03_113811_add_merge_columns_to_phase_two_tables', 12),
(31, '2026_09_06_040805_create_deals_table', 13),
(32, '2026_09_06_040806_add_conversion_columns_to_leads_table', 13),
(33, '2026_09_06_045425_create_import_runs_table', 14),
(34, '2026_09_06_062223_create_documents_table', 15),
(35, '2026_09_06_062223_create_notes_table', 15),
(36, '2026_09_06_090624_create_pipelines_table', 16),
(37, '2026_09_06_090625_add_pipeline_id_to_deals_table', 16),
(38, '2026_09_06_090625_create_pipeline_stages_table', 16),
(39, '2026_09_10_081311_add_closing_to_deals_table', 17),
(41, '2026_09_10_101348_create_deal_stage_entries_table', 18),
(42, '2026_09_10_140000_create_activities_table', 19);

-- --------------------------------------------------------

--
-- Table structure for table `model_has_permissions`
--

CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `model_has_permissions`
--

INSERT INTO `model_has_permissions` (`permission_id`, `model_type`, `model_id`) VALUES
(1, 'App\\Models\\User', 1),
(2, 'App\\Models\\User', 1),
(3, 'App\\Models\\User', 1),
(4, 'App\\Models\\User', 1),
(5, 'App\\Models\\User', 1),
(6, 'App\\Models\\User', 1),
(7, 'App\\Models\\User', 1),
(8, 'App\\Models\\User', 1),
(9, 'App\\Models\\User', 1),
(10, 'App\\Models\\User', 1),
(11, 'App\\Models\\User', 1),
(12, 'App\\Models\\User', 1),
(13, 'App\\Models\\User', 1),
(14, 'App\\Models\\User', 1),
(15, 'App\\Models\\User', 1),
(16, 'App\\Models\\User', 1);

-- --------------------------------------------------------

--
-- Table structure for table `model_has_roles`
--

CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `model_has_roles`
--

INSERT INTO `model_has_roles` (`role_id`, `model_type`, `model_id`) VALUES
(1, 'App\\Models\\User', 1);

-- --------------------------------------------------------

--
-- Table structure for table `notes`
--

CREATE TABLE `notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `notable_type` varchar(255) NOT NULL,
  `notable_id` bigint(20) UNSIGNED NOT NULL,
  `author_id` bigint(20) UNSIGNED DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) UNSIGNED NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_logs`
--

CREATE TABLE `notification_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event` varchar(255) NOT NULL,
  `channel` varchar(255) NOT NULL,
  `recipient_type` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recipient` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'queued',
  `subject` varchar(255) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_preferences`
--

CREATE TABLE `notification_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `event` varchar(255) DEFAULT NULL,
  `channel` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_settings`
--

CREATE TABLE `notification_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event` varchar(255) NOT NULL,
  `recipient_type` varchar(255) NOT NULL,
  `channel` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_templates`
--

CREATE TABLE `notification_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event` varchar(255) NOT NULL,
  `channel` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
(1, 'company.view', 'web', '2026-09-02 02:55:47', '2026-09-02 02:55:47'),
(2, 'company.update', 'web', '2026-09-02 02:55:47', '2026-09-02 02:55:47'),
(3, 'users.view', 'web', '2026-09-02 04:24:32', '2026-09-02 04:24:32'),
(4, 'users.create', 'web', '2026-09-02 04:24:32', '2026-09-02 04:24:32'),
(5, 'users.update', 'web', '2026-09-02 04:24:32', '2026-09-02 04:24:32'),
(6, 'users.delete', 'web', '2026-09-02 04:24:32', '2026-09-02 04:24:32'),
(7, 'users.invite', 'web', '2026-09-02 04:24:32', '2026-09-02 04:24:32'),
(8, 'teams.view', 'web', '2026-09-02 04:40:14', '2026-09-02 04:40:14'),
(9, 'teams.create', 'web', '2026-09-02 04:40:15', '2026-09-02 04:40:15'),
(10, 'teams.update', 'web', '2026-09-02 04:40:15', '2026-09-02 04:40:15'),
(11, 'teams.delete', 'web', '2026-09-02 04:40:15', '2026-09-02 04:40:15'),
(12, 'roles.view', 'web', '2026-09-02 05:00:44', '2026-09-02 05:00:44'),
(13, 'roles.create', 'web', '2026-09-02 05:00:44', '2026-09-02 05:00:44'),
(14, 'roles.update', 'web', '2026-09-02 05:00:44', '2026-09-02 05:00:44'),
(15, 'roles.delete', 'web', '2026-09-02 05:00:44', '2026-09-02 05:00:44'),
(16, 'audit.view', 'web', '2026-09-02 05:34:44', '2026-09-02 05:34:44'),
(17, 'settings.view', 'web', '2026-09-02 21:50:54', '2026-09-02 21:50:54'),
(18, 'settings.update', 'web', '2026-09-02 21:50:54', '2026-09-02 21:50:54'),
(19, 'settings.secrets', 'web', '2026-09-02 21:50:54', '2026-09-02 21:50:54'),
(20, 'notifications.view', 'web', '2026-09-02 23:18:46', '2026-09-02 23:18:46'),
(21, 'notifications.update', 'web', '2026-09-02 23:18:46', '2026-09-02 23:18:46'),
(22, 'accounts.view', 'web', '2026-09-03 00:32:32', '2026-09-03 00:32:32'),
(23, 'accounts.create', 'web', '2026-09-03 00:32:32', '2026-09-03 00:32:32'),
(24, 'accounts.update', 'web', '2026-09-03 00:32:32', '2026-09-03 00:32:32'),
(25, 'accounts.delete', 'web', '2026-09-03 00:32:32', '2026-09-03 00:32:32'),
(26, 'accounts.export', 'web', '2026-09-03 00:32:32', '2026-09-03 00:32:32'),
(27, 'contacts.view', 'web', '2026-09-03 01:53:48', '2026-09-03 01:53:48'),
(28, 'contacts.create', 'web', '2026-09-03 01:53:48', '2026-09-03 01:53:48'),
(29, 'contacts.update', 'web', '2026-09-03 01:53:48', '2026-09-03 01:53:48'),
(30, 'contacts.delete', 'web', '2026-09-03 01:53:48', '2026-09-03 01:53:48'),
(31, 'contacts.export', 'web', '2026-09-03 01:53:48', '2026-09-03 01:53:48'),
(32, 'leads.view', 'web', '2026-09-03 02:39:46', '2026-09-03 02:39:46'),
(33, 'leads.create', 'web', '2026-09-03 02:39:46', '2026-09-03 02:39:46'),
(34, 'leads.update', 'web', '2026-09-03 02:39:46', '2026-09-03 02:39:46'),
(35, 'leads.assign', 'web', '2026-09-03 02:39:46', '2026-09-03 02:39:46'),
(36, 'leads.delete', 'web', '2026-09-03 02:39:46', '2026-09-03 02:39:46'),
(37, 'leads.export', 'web', '2026-09-03 02:39:46', '2026-09-03 02:39:46'),
(38, 'leads.scoring', 'web', '2026-09-03 03:43:15', '2026-09-03 03:43:15'),
(39, 'accounts.merge', 'web', '2026-09-05 21:52:04', '2026-09-05 21:52:04'),
(40, 'leads.merge', 'web', '2026-09-05 21:52:04', '2026-09-05 21:52:04'),
(41, 'contacts.merge', 'web', '2026-09-05 21:52:04', '2026-09-05 21:52:04'),
(42, 'leads.convert', 'web', '2026-09-05 22:29:55', '2026-09-05 22:29:55'),
(43, 'deals.view', 'web', '2026-09-05 22:29:55', '2026-09-05 22:29:55'),
(44, 'accounts.import', 'web', '2026-09-05 23:18:55', '2026-09-05 23:18:55'),
(45, 'leads.import', 'web', '2026-09-05 23:18:55', '2026-09-05 23:18:55'),
(46, 'contacts.import', 'web', '2026-09-05 23:18:55', '2026-09-05 23:18:55'),
(47, 'timeline.view', 'web', '2026-09-06 00:31:09', '2026-09-06 00:31:09'),
(48, 'timeline.create', 'web', '2026-09-06 00:31:09', '2026-09-06 00:31:09'),
(49, 'timeline.update', 'web', '2026-09-06 00:31:09', '2026-09-06 00:31:09'),
(50, 'timeline.delete', 'web', '2026-09-06 00:31:09', '2026-09-06 00:31:09'),
(51, 'deals.pipelines', 'web', '2026-09-06 03:13:01', '2026-09-06 03:13:01'),
(52, 'deals.create', 'web', '2026-09-10 02:17:06', '2026-09-10 02:17:06'),
(53, 'deals.update', 'web', '2026-09-10 02:17:07', '2026-09-10 02:17:07'),
(54, 'deals.assign', 'web', '2026-09-10 02:17:07', '2026-09-10 02:17:07'),
(55, 'deals.close', 'web', '2026-09-10 02:17:07', '2026-09-10 02:17:07'),
(56, 'deals.delete', 'web', '2026-09-10 02:17:07', '2026-09-10 02:17:07'),
(57, 'deals.export', 'web', '2026-09-10 02:17:07', '2026-09-10 02:17:07'),
(58, 'activities.view', 'web', '2026-09-10 05:04:57', '2026-09-10 05:04:57'),
(59, 'activities.create', 'web', '2026-09-10 05:04:57', '2026-09-10 05:04:57'),
(60, 'activities.update', 'web', '2026-09-10 05:04:57', '2026-09-10 05:04:57'),
(61, 'activities.assign', 'web', '2026-09-10 05:04:57', '2026-09-10 05:04:57'),
(62, 'activities.delete', 'web', '2026-09-10 05:04:57', '2026-09-10 05:04:57'),
(63, 'activities.export', 'web', '2026-09-10 05:04:57', '2026-09-10 05:04:57');

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pipelines`
--

CREATE TABLE `pipelines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pipelines`
--

INSERT INTO `pipelines` (`id`, `name`, `description`, `is_default`, `position`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'Standard sales', 'The route every deal follows unless another pipeline is chosen.', 1, 0, '2026-09-06 03:13:02', '2026-09-06 03:22:55', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `pipeline_stages`
--

CREATE TABLE `pipeline_stages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `pipeline_id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'slate',
  `probability` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `outcome` varchar(10) NOT NULL DEFAULT 'open',
  `position` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pipeline_stages`
--

INSERT INTO `pipeline_stages` (`id`, `pipeline_id`, `key`, `name`, `color`, `probability`, `outcome`, `position`, `created_at`, `updated_at`) VALUES
(1, 1, 'new', 'New', 'slate', 10, 'open', 0, '2026-09-06 03:13:02', '2026-09-06 03:13:02'),
(2, 1, 'qualification', 'Qualification', 'blue', 25, 'open', 1, '2026-09-06 03:13:02', '2026-09-06 03:13:02'),
(3, 1, 'proposal', 'Proposal', 'violet', 50, 'open', 2, '2026-09-06 03:13:02', '2026-09-06 03:13:02'),
(4, 1, 'negotiation', 'Negotiation', 'amber', 75, 'open', 3, '2026-09-06 03:13:02', '2026-09-06 03:13:02'),
(5, 1, 'won', 'Won', 'emerald', 100, 'won', 4, '2026-09-06 03:13:02', '2026-09-06 03:13:02'),
(6, 1, 'lost', 'Lost', 'rose', 0, 'lost', 5, '2026-09-06 03:13:02', '2026-09-06 03:13:02');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `data_access_level` varchar(255) NOT NULL DEFAULT 'own',
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `data_access_level`, `guard_name`, `created_at`, `updated_at`) VALUES
(1, 'Super Admin', 'all', 'web', '2026-09-02 05:00:44', '2026-09-02 05:00:44'),
(2, 'Sales Rep', 'team', 'web', '2026-09-02 05:02:47', '2026-09-02 05:02:47'),
(3, 'Support Agent', 'team', 'web', '2026-09-02 05:36:27', '2026-09-02 05:36:27');

-- --------------------------------------------------------

--
-- Table structure for table `role_has_permissions`
--

CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_has_permissions`
--

INSERT INTO `role_has_permissions` (`permission_id`, `role_id`) VALUES
(1, 1),
(2, 1),
(3, 1),
(3, 2),
(3, 3),
(4, 1),
(4, 2),
(5, 1),
(5, 2),
(6, 1),
(6, 2),
(7, 1),
(7, 2),
(8, 1),
(8, 3),
(9, 1),
(10, 1),
(11, 1),
(12, 1),
(13, 1),
(14, 1),
(15, 1),
(16, 1),
(17, 1),
(18, 1),
(19, 1),
(20, 1),
(21, 1),
(22, 1),
(23, 1),
(24, 1),
(25, 1),
(26, 1),
(27, 1),
(28, 1),
(29, 1),
(30, 1),
(31, 1),
(32, 1),
(33, 1),
(34, 1),
(35, 1),
(36, 1),
(37, 1),
(38, 1),
(39, 1),
(40, 1),
(41, 1),
(42, 1),
(43, 1),
(44, 1),
(45, 1),
(46, 1),
(47, 1),
(48, 1),
(49, 1),
(50, 1),
(51, 1),
(52, 1),
(53, 1),
(54, 1),
(55, 1),
(56, 1),
(57, 1),
(58, 1),
(59, 1),
(60, 1),
(61, 1),
(62, 1),
(63, 1);

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `sessions`
--

INSERT INTO `sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`) VALUES
('TxefnjmQSZ34RVK2cAjx0FmxAUH4lCWYuMORrGV8', 1, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:155.0) Gecko/20100101 Firefox/155.0', 'ZXlKcGRpSTZJbGhPWnpSblN6QTNZWGh5YzA4eEwxUTBXaTlUZW5jOVBTSXNJblpoYkhWbElqb2lhWGhrVUU1TGJIZExLMjVaTWs1eVNERmhVRXRSTkRoRFlrNURjVWxoWVVSemF6ZG5WV3BEWTFsT0szWjNhbk5wYlVWemNHZEVXVFJMTW1aSFoyaERUR1YxUkRadWRHbGhlbnBvU2xncmRuVmlabWhwVTA5UVkyZzFTMUJNYzBaTFRqQm1iV1ZHYkZOM1FsaFdabU5rUTA1dVlqaGliV2c1UjBVclpXcHRjRFJ2WVVkV2JVbEhla28xZERsNFlXeEVhSEJvTHpsNVUybGhXbmRJZGpOUlExZHNNamxXZUVoemRtVTFVMFZaU2xkV2VHaGxlRzVYYVRGb1NtMU5WMmRMTkhVNVRXSnFiVmQyZFZKelEydDBUSGxzVGtKYWJrbFNaVk5FTWxFdldsRnRlblZtUnpOV1NsZEpWRzgyV25aTVJrRk5XSHByY2xOSVJFbG9iR0p2SzB4NVVTdHNZMFpNY21WMVMxaFFSR3BDYVhWSU1VdFBlRkJpVGpFNWRVSnBNMFJXTVRkSmRrcHRSVmswVEVKcFRIRjNiRWR4ZDBocGJEUTJXWGRpZUdaYVYzaHFSVWxWWlhodE1sQjJVWEZGU1VSWVJsTnJVWHAxVGtKek5HNVRhVEowWVRSUFJtcENXVWxEV25WWmFWZHpNa1J0Tms5V2FHdEVkamRRY1hobGFqWXhlVTlsVDFWM2QzQm9VVzl2UkVJeE5rNVJaMEV3YTNwdk4zUk9iMkl4WlhGWU1sbHJXV3RLVDBrcmFVa3ZUWGxPY1ZNdlJuazFlR2hPZUZGaFdqUlpRM1kzVDBZMlJYQlBWR0pIY21sUVUyUnhTblEzV0V4dGJIQnVkMnhKVHprNVprSjNaM0EzUzFscGVXUnNXazkzTURsdFFXWXlNRFJqVVhsdVlYUTVlbU5WTldKYVJHZHpTMWRwTldWc1lsWlZSUzlGTm5wT1N6TkxXSHBvYnpKWmMyazBkbFZoUzJSTlBTSXNJbTFoWXlJNkltUXlZVEUyTXpWbFpUbGpNV1E1TmpsbVl6Y3laVFJpWXpnME56UmhaRGt4WkRJMU1ERmtPR1ZsWkdVNFpEZGlNR1V5Wm1SaU9HWTJORE16T1daaE16SWlMQ0owWVdjaU9pSWlmUT09', 1789037543),
('YRyZVPVZENXUKDmTRP7Pap1wVkFEeuupt0JgQ9Bf', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.49585.0 Chrome/152.0.7977.76 Safari/537.36 MSIX', 'ZXlKcGRpSTZJbTlCYzFoQ1QxTkxhVGxQVm01TlEySnFhVkZaWW1jOVBTSXNJblpoYkhWbElqb2lPRUk1YzJ0Nk1uY3paMmRJWjNsaE9HbDNXa3gxWm1wU0syUnljMFkyWkVWU2NITjJaRE13VTFOdVNGSlpRM05CTlZaYVlUWjFRVGROTldWV1NrbEJTWGhrVlcxbVdEUnRkbmRRTVVwNFV5dEJaVkIyUVZZeGRtcGtaMk5QZGpsRVdFMTROVmxxYWxCSk16aEdhVU0yZGtKSWNISTRVbEJoWjBjNE1USTJiM28wVVhBMGR5dFZTVVpVVFhOT1JFVkRaSGhCZUVjMFEyTlFVRmhTTW1jelRTOTJOMHRSWTFWemFHMXJVbVEzVkdoVE5XY3lWalJETTFaTllrSktZbFJ4Y25wcFRGRndjakl5ZG1jd1UzbDFZVW96UkhsVlNVNUhXSG80SzJkamEyTkRWMmwyVUdNdmNEQmxWbHB5Ymt0S09YSlRXamRPWldjMFdVRk1jaXQyWTFJeUwzZEdaMXB5VlRCUFMyMUJSWGt6V0dWMWJIbDFTRWhvTTFaSVptb3hkVEJ5U2t4cU1tMUthekF4Wm1kV2NrMHpSMFEzWm01RE0wcGFXVEpPY1daaWNXaEVlVE5VWkVwRE5reHdTbVpIU2twM2NrUTRiVzFKWmpkS2IwMTBaVE1yUm05bVV5OHJObkp0ZFRNdlV6WmtibEpwYjBWbE1tbGFVV2xpTDBWVVMzRjNjRFU0VW1sdFZWRkpkeXM0U1dwdFIydHpRMlJHYlZobWQwZEJZelZsY2pSMFdqWkllVmRyUlV0TVJrSk5abXRXVUVKVmIxUmlkbkJrVVdWVlYwUlJkbkZuU1ZaRmMwUnVPVlJOYUd0a1p6aElSbmxaVm5abWRXaHpLMU5oSzJjeWJHWkdXbFV4TjBkaWNWSjRlVlZNUW13elZXbGpWRlkzTDNJMFRFMDJWVW8xYmtRMVFtZDNlRzR5VDFSRVFVczFTRXBCUFQwaUxDSnRZV01pT2lJNU1tUXdOekkxTTJVd05UaGhObVEwTURNd05tRXdabVUzWW1RMFpHVm1ZekkwT0RjNE56VmpPR1ZqTmprNFpUQmlNREV5TmpaaU5EVmlZVGM1WmpFNUlpd2lkR0ZuSWpvaUluMD0=', 1789035745);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `group` varchar(255) NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'string',
  `is_secret` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teams`
--

CREATE TABLE `teams` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `team_user`
--

CREATE TABLE `team_user` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `team_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `current_team_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `current_team_id`, `name`, `email`, `email_verified_at`, `password`, `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `remember_token`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, NULL, 'Nayem Ahmed', 'nayem@goldeninfotech.com.bd', NULL, '$2y$12$CeOU1/4E9mFWPwb5/rEk1uTWLpQOSM8NnUK8Bisqml3N3wMorIhU6', NULL, NULL, NULL, NULL, '2026-09-02 02:55:47', '2026-09-02 04:41:06', NULL),
(2, NULL, 'New Colleague', 'colleague@example.com', '2026-09-02 04:29:05', '$2y$12$MLZUvCLWbhLcGXpe2ocjUu1rAzDBr6SSdPzizODMgz2jYtKSX.e7G', NULL, NULL, NULL, NULL, '2026-09-02 04:29:05', '2026-09-02 04:43:13', NULL),
(14, NULL, 'Dr. Holden Reilly I', 'karlie.rolfson@example.org', '2026-05-01 03:00:00', '$2y$12$AjEe1g9pJ3J4AxPUeoU2FeBORfEjDSc5P.VC3D2ZfSwM9XwKZZH3O', NULL, NULL, NULL, 'FE6jYUCVox', '2026-05-01 03:00:00', '2026-05-01 03:00:00', NULL),
(15, NULL, 'Dax Macejkovic V', 'mills.donato@example.org', '2026-05-01 03:00:00', '$2y$12$AjEe1g9pJ3J4AxPUeoU2FeBORfEjDSc5P.VC3D2ZfSwM9XwKZZH3O', NULL, NULL, NULL, 'pYf3BTgejb', '2026-05-01 03:00:00', '2026-05-01 03:00:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_invitations`
--

CREATE TABLE `user_invitations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `role_id` bigint(20) UNSIGNED DEFAULT NULL,
  `team_id` bigint(20) UNSIGNED DEFAULT NULL,
  `invited_by` bigint(20) UNSIGNED DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `accepted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_invitations`
--

INSERT INTO `user_invitations` (`id`, `email`, `name`, `token`, `role_id`, `team_id`, `invited_by`, `expires_at`, `accepted_at`, `created_at`, `updated_at`) VALUES
(1, 'colleague@example.com', 'New Colleague', '92355f13c0ee47162d65e1accf31c8e94124ac376e9eda2c4d90587b6d6f2a2e', NULL, NULL, 1, '2026-09-02 10:29:05', '2026-09-02 04:29:05', '2026-09-02 04:27:56', '2026-09-02 04:29:05');

-- --------------------------------------------------------

--
-- Table structure for table `user_view_preferences`
--

CREATE TABLE `user_view_preferences` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `module` varchar(255) NOT NULL,
  `view_mode` varchar(255) NOT NULL DEFAULT 'table',
  `columns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`columns`)),
  `pinned_columns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pinned_columns`)),
  `per_page` smallint(5) UNSIGNED NOT NULL DEFAULT 25,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_view_preferences`
--

INSERT INTO `user_view_preferences` (`id`, `user_id`, `module`, `view_mode`, `columns`, `pinned_columns`, `per_page`, `created_at`, `updated_at`) VALUES
(4, 1, 'leads', 'table', NULL, NULL, 25, '2026-09-06 03:24:49', '2026-09-06 03:25:10'),
(5, 1, 'deals', 'kanban', NULL, NULL, 25, '2026-09-10 02:35:07', '2026-09-10 02:35:07');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `accounts_parent_id_foreign` (`parent_id`),
  ADD KEY `accounts_name_index` (`name`),
  ADD KEY `accounts_industry_index` (`industry`),
  ADD KEY `accounts_size_index` (`size`),
  ADD KEY `accounts_country_index` (`country`),
  ADD KEY `accounts_annual_revenue_index` (`annual_revenue`),
  ADD KEY `accounts_owner_id_created_at_index` (`owner_id`,`created_at`),
  ADD KEY `accounts_owner_id_industry_index` (`owner_id`,`industry`),
  ADD KEY `accounts_merged_into_id_foreign` (`merged_into_id`);

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activities_recurrence_parent_id_foreign` (`recurrence_parent_id`),
  ADD KEY `activities_related_type_related_id_index` (`related_type`,`related_id`),
  ADD KEY `activities_created_by_id_foreign` (`created_by_id`),
  ADD KEY `activities_owner_id_status_due_at_index` (`owner_id`,`status`,`due_at`),
  ADD KEY `activities_status_due_at_index` (`status`,`due_at`),
  ADD KEY `activities_type_status_index` (`type`,`status`),
  ADD KEY `activities_priority_index` (`priority`),
  ADD KEY `activities_completed_at_index` (`completed_at`),
  ADD KEY `activities_status_reminder_sent_at_due_at_index` (`status`,`reminder_sent_at`,`due_at`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `subject` (`subject_type`,`subject_id`),
  ADD KEY `causer` (`causer_type`,`causer_id`),
  ADD KEY `activity_log_log_name_index` (`log_name`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `contacts`
--
ALTER TABLE `contacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `contacts_last_name_index` (`last_name`),
  ADD KEY `contacts_email_index` (`email`),
  ADD KEY `contacts_department_index` (`department`),
  ADD KEY `contacts_country_index` (`country`),
  ADD KEY `contacts_account_id_is_primary_index` (`account_id`,`is_primary`),
  ADD KEY `contacts_owner_id_created_at_index` (`owner_id`,`created_at`),
  ADD KEY `contacts_merged_into_id_foreign` (`merged_into_id`);

--
-- Indexes for table `deals`
--
ALTER TABLE `deals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `deals_account_id_foreign` (`account_id`),
  ADD KEY `deals_contact_id_foreign` (`contact_id`),
  ADD KEY `deals_lead_id_foreign` (`lead_id`),
  ADD KEY `deals_stage_created_at_index` (`stage`,`created_at`),
  ADD KEY `deals_owner_id_stage_index` (`owner_id`,`stage`),
  ADD KEY `deals_expected_close_date_index` (`expected_close_date`),
  ADD KEY `deals_pipeline_id_stage_index` (`pipeline_id`,`stage`),
  ADD KEY `deals_close_reason_closed_at_index` (`close_reason`,`closed_at`),
  ADD KEY `deals_closed_at_index` (`closed_at`);

--
-- Indexes for table `deal_stage_entries`
--
ALTER TABLE `deal_stage_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `deal_stage_entries_pipeline_id_foreign` (`pipeline_id`),
  ADD KEY `deal_stage_entries_moved_by_id_foreign` (`moved_by_id`),
  ADD KEY `deal_stage_entries_deal_id_entered_at_id_index` (`deal_id`,`entered_at`,`id`),
  ADD KEY `deal_stage_entries_deal_id_left_at_index` (`deal_id`,`left_at`),
  ADD KEY `deal_stage_entries_stage_key_entered_at_index` (`stage_key`,`entered_at`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `documents_documentable_type_documentable_id_index` (`documentable_type`,`documentable_id`),
  ADD KEY `documents_uploaded_by_id_foreign` (`uploaded_by_id`),
  ADD KEY `documents_timeline_index` (`documentable_type`,`documentable_id`,`created_at`,`id`);

--
-- Indexes for table `duplicate_keys`
--
ALTER TABLE `duplicate_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `duplicate_keys_record_value_unique` (`keyable_type`,`keyable_id`,`kind`,`value`),
  ADD KEY `duplicate_keys_keyable_type_keyable_id_index` (`keyable_type`,`keyable_id`),
  ADD KEY `duplicate_keys_kind_value_index` (`kind`,`value`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `import_runs`
--
ALTER TABLE `import_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `import_runs_user_id_created_at_index` (`user_id`,`created_at`),
  ADD KEY `import_runs_module_status_index` (`module`,`status`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `leads_last_name_index` (`last_name`),
  ADD KEY `leads_company_name_index` (`company_name`),
  ADD KEY `leads_email_index` (`email`),
  ADD KEY `leads_source_index` (`source`),
  ADD KEY `leads_estimated_value_index` (`estimated_value`),
  ADD KEY `leads_status_created_at_index` (`status`,`created_at`),
  ADD KEY `leads_owner_id_status_index` (`owner_id`,`status`),
  ADD KEY `leads_score_index` (`score`),
  ADD KEY `leads_merged_into_id_foreign` (`merged_into_id`),
  ADD KEY `leads_converted_account_id_foreign` (`converted_account_id`),
  ADD KEY `leads_converted_contact_id_foreign` (`converted_contact_id`),
  ADD KEY `leads_converted_deal_id_foreign` (`converted_deal_id`),
  ADD KEY `leads_converted_at_index` (`converted_at`);

--
-- Indexes for table `lead_scoring_rules`
--
ALTER TABLE `lead_scoring_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `lead_scoring_rules_kind_is_active_position_index` (`kind`,`is_active`,`position`);

--
-- Indexes for table `login_histories`
--
ALTER TABLE `login_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `login_histories_user_id_created_at_index` (`user_id`,`created_at`),
  ADD KEY `login_histories_event_created_at_index` (`event`,`created_at`),
  ADD KEY `login_histories_email_index` (`email`),
  ADD KEY `login_histories_event_index` (`event`),
  ADD KEY `login_histories_ip_address_index` (`ip_address`);

--
-- Indexes for table `media`
--
ALTER TABLE `media`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `media_uuid_unique` (`uuid`),
  ADD KEY `media_model_type_model_id_index` (`model_type`,`model_id`),
  ADD KEY `media_order_column_index` (`order_column`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  ADD KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  ADD KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notes_notable_type_notable_id_index` (`notable_type`,`notable_id`),
  ADD KEY `notes_author_id_foreign` (`author_id`),
  ADD KEY `notes_timeline_index` (`notable_type`,`notable_id`,`created_at`,`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`);

--
-- Indexes for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notification_logs_user_id_foreign` (`user_id`),
  ADD KEY `notification_logs_status_created_at_index` (`status`,`created_at`),
  ADD KEY `notification_logs_channel_created_at_index` (`channel`,`created_at`),
  ADD KEY `notification_logs_event_index` (`event`);

--
-- Indexes for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `notification_preferences_unique` (`user_id`,`event`,`channel`);

--
-- Indexes for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `notification_settings_unique` (`event`,`recipient_type`,`channel`),
  ADD KEY `notification_settings_event_recipient_type_index` (`event`,`recipient_type`);

--
-- Indexes for table `notification_templates`
--
ALTER TABLE `notification_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `notification_templates_event_channel_unique` (`event`,`channel`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  ADD KEY `personal_access_tokens_expires_at_index` (`expires_at`);

--
-- Indexes for table `pipelines`
--
ALTER TABLE `pipelines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pipelines_name_unique` (`name`),
  ADD KEY `pipelines_position_id_index` (`position`,`id`);

--
-- Indexes for table `pipeline_stages`
--
ALTER TABLE `pipeline_stages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `pipeline_stages_pipeline_id_key_unique` (`pipeline_id`,`key`),
  ADD KEY `pipeline_stages_pipeline_id_position_id_index` (`pipeline_id`,`position`,`id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`role_id`),
  ADD KEY `role_has_permissions_role_id_foreign` (`role_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `settings_group_key_unique` (`group`,`key`),
  ADD KEY `settings_group_index` (`group`);

--
-- Indexes for table `teams`
--
ALTER TABLE `teams`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teams_parent_id_foreign` (`parent_id`);

--
-- Indexes for table `team_user`
--
ALTER TABLE `team_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `team_user_team_id_user_id_unique` (`team_id`,`user_id`),
  ADD KEY `team_user_user_id_foreign` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD KEY `users_current_team_id_foreign` (`current_team_id`);

--
-- Indexes for table `user_invitations`
--
ALTER TABLE `user_invitations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_invitations_email_unique` (`email`),
  ADD UNIQUE KEY `user_invitations_token_unique` (`token`),
  ADD KEY `user_invitations_role_id_foreign` (`role_id`),
  ADD KEY `user_invitations_team_id_foreign` (`team_id`),
  ADD KEY `user_invitations_invited_by_foreign` (`invited_by`),
  ADD KEY `user_invitations_accepted_at_expires_at_index` (`accepted_at`,`expires_at`);

--
-- Indexes for table `user_view_preferences`
--
ALTER TABLE `user_view_preferences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_view_preferences_user_id_module_unique` (`user_id`,`module`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=225;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `contacts`
--
ALTER TABLE `contacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `deals`
--
ALTER TABLE `deals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `deal_stage_entries`
--
ALTER TABLE `deal_stage_entries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `duplicate_keys`
--
ALTER TABLE `duplicate_keys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=144;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `import_runs`
--
ALTER TABLE `import_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `lead_scoring_rules`
--
ALTER TABLE `lead_scoring_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `login_histories`
--
ALTER TABLE `login_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `media`
--
ALTER TABLE `media`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `notification_logs`
--
ALTER TABLE `notification_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_settings`
--
ALTER TABLE `notification_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_templates`
--
ALTER TABLE `notification_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=64;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pipelines`
--
ALTER TABLE `pipelines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `pipeline_stages`
--
ALTER TABLE `pipeline_stages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `teams`
--
ALTER TABLE `teams`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `team_user`
--
ALTER TABLE `team_user`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `user_invitations`
--
ALTER TABLE `user_invitations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_view_preferences`
--
ALTER TABLE `user_view_preferences`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `accounts_merged_into_id_foreign` FOREIGN KEY (`merged_into_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `accounts_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `accounts_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `activities`
--
ALTER TABLE `activities`
  ADD CONSTRAINT `activities_created_by_id_foreign` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `activities_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `activities_recurrence_parent_id_foreign` FOREIGN KEY (`recurrence_parent_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `contacts`
--
ALTER TABLE `contacts`
  ADD CONSTRAINT `contacts_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `contacts_merged_into_id_foreign` FOREIGN KEY (`merged_into_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `contacts_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `deals`
--
ALTER TABLE `deals`
  ADD CONSTRAINT `deals_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `deals_contact_id_foreign` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `deals_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `deals_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `deals_pipeline_id_foreign` FOREIGN KEY (`pipeline_id`) REFERENCES `pipelines` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `deal_stage_entries`
--
ALTER TABLE `deal_stage_entries`
  ADD CONSTRAINT `deal_stage_entries_deal_id_foreign` FOREIGN KEY (`deal_id`) REFERENCES `deals` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `deal_stage_entries_moved_by_id_foreign` FOREIGN KEY (`moved_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `deal_stage_entries_pipeline_id_foreign` FOREIGN KEY (`pipeline_id`) REFERENCES `pipelines` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_uploaded_by_id_foreign` FOREIGN KEY (`uploaded_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `import_runs`
--
ALTER TABLE `import_runs`
  ADD CONSTRAINT `import_runs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `leads`
--
ALTER TABLE `leads`
  ADD CONSTRAINT `leads_converted_account_id_foreign` FOREIGN KEY (`converted_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_converted_contact_id_foreign` FOREIGN KEY (`converted_contact_id`) REFERENCES `contacts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_converted_deal_id_foreign` FOREIGN KEY (`converted_deal_id`) REFERENCES `deals` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_merged_into_id_foreign` FOREIGN KEY (`merged_into_id`) REFERENCES `leads` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_owner_id_foreign` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `login_histories`
--
ALTER TABLE `login_histories`
  ADD CONSTRAINT `login_histories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notes`
--
ALTER TABLE `notes`
  ADD CONSTRAINT `notes_author_id_foreign` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notification_logs`
--
ALTER TABLE `notification_logs`
  ADD CONSTRAINT `notification_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notification_preferences`
--
ALTER TABLE `notification_preferences`
  ADD CONSTRAINT `notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pipeline_stages`
--
ALTER TABLE `pipeline_stages`
  ADD CONSTRAINT `pipeline_stages_pipeline_id_foreign` FOREIGN KEY (`pipeline_id`) REFERENCES `pipelines` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `teams`
--
ALTER TABLE `teams`
  ADD CONSTRAINT `teams_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `team_user`
--
ALTER TABLE `team_user`
  ADD CONSTRAINT `team_user_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `team_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_current_team_id_foreign` FOREIGN KEY (`current_team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_invitations`
--
ALTER TABLE `user_invitations`
  ADD CONSTRAINT `user_invitations_invited_by_foreign` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `user_invitations_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `user_invitations_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_view_preferences`
--
ALTER TABLE `user_view_preferences`
  ADD CONSTRAINT `user_view_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
