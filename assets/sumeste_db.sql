-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 23, 2026 at 02:02 AM
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
-- Database: `sumeste_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `tbl_announcement`
--

CREATE TABLE `tbl_announcement` (
  `announcementID` int(11) NOT NULL,
  `announcementTitle` varchar(255) NOT NULL,
  `announcementDesc` text DEFAULT NULL,
  `announcementPost` date DEFAULT NULL,
  `announcementStart` date DEFAULT NULL,
  `announcementTag` varchar(100) DEFAULT NULL,
  `announcementImg` varchar(255) DEFAULT NULL,
  `announcementDetails` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_announcement`
--

INSERT INTO `tbl_announcement` (`announcementID`, `announcementTitle`, `announcementDesc`, `announcementPost`, `announcementStart`, `announcementTag`, `announcementImg`, `announcementDetails`) VALUES
(6, 'One-Stop Shop Services 2026', 'Inaanyayahan ang lahat ng residente na dumalo sa ating Barangay One-Stop Shop Services kung saan maaari ninyong asikasuhin ang iba’t ibang government services sa iisang lugar.\r\n\r\n🗓 Petsa: May 25, 2026\r\n⏰ Oras: 8:00 ng umaga – 4:00 ng hapon\r\n📍 Lugar: Barangay Covered Court, Brgy. Sumacab Este\r\n\r\nMga Requirements kada Serbisyo:\r\n🔹 National ID Registration\r\n• PSA Birth Certificate o anumang valid ID\r\n• Supporting documents (kung walang primary ID)\r\n\r\n🔹 SSS (Social Security System)\r\n• Valid ID\r\n• Birth Certificate\r\n• Marriage Certificate (kung married)\r\n\r\n🔹 Pag-IBIG Fund\r\n• Valid ID\r\n• Birth Certificate\r\n• Proof of Income (kung kinakailangan)\r\n\r\n🔹 PSA (Birth Certificate, etc.)\r\n• Valid ID\r\n• Kumpletong detalye ng hihinging dokumento (Pangalan, Petsa ng Kapanganakan, Pangalan ng Magulang)\r\n\r\nLayunin ng programang ito na mailapit ang mga serbisyong pampamahalaan sa ating komunidad para sa mas mabilis at maginhawang proseso. Hatid ito ng inyong Barangay Council katuwang ang iba’t ibang ahensya ng gobyerno. Para sa karagdagang impormasyon, makipag-ugnayan sa Barangay Hall.\r\n\r\nMaraming salamat!', '2026-04-09', '2026-05-25', 'Assistance', '[\"ann_69d7591ecefe18.93269293.jpg\",\"ann_69d7591ecf4f67.95331791.jpg\",\"ann_69d7591ecf9809.44109065.jpg\",\"ann_69d7591ecfcf49.45488833.jpg\"]', ''),
(7, 'Brigada Eskwela \'26 & School Supplies Giving Program', 'Inaanyayahan po ang lahat ng magulang at kabataan na makiisa sa ating Brigada Eskwela at School Supply Giving Program para sa mga mag-aaral sa elementarya.\r\n\r\n🗓 Petsa: June 1, 2026\r\n⏰ Oras: 8:00 ng umaga\r\n📍 Lugar: Barangay Covered Court, Brgy. Sumacab Este\r\n\r\nMga Benepisyaryo: Mga mag-aaral mula Kindergarten hanggang Grade 6\r\n\r\nDalhin:\r\n• Kopya ng School ID\r\n• Ballpen (para sa registration)\r\n\r\nLayunin ng programang ito na maihanda ang ating mga paaralan para sa darating na pasukan at mabigyan ng sapat na gamit ang ating mga kabataan. Maraming salamat at inaasahan ang inyong pakikiisa!', '2026-05-29', '2026-06-01', 'Education', '[\"ann_69d75a07e24547.39970587.jpg\",\"ann_69d75a07e274b1.76651513.jpg\",\"ann_69d75a07e2da52.15041752.jpg\"]', 'Ang aktibidad na ito ay hatid ng inyong Barangay Council katuwang ang mga boluntaryo at sponsors. Para sa karagdagang impormasyon, makipag-ugnayan lamang sa Barangay Hall.');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_beneficiary`
--

CREATE TABLE `tbl_beneficiary` (
  `id` int(10) UNSIGNED NOT NULL,
  `userId` int(11) NOT NULL,
  `housing_status` varchar(50) NOT NULL DEFAULT '',
  `house_material` varchar(50) NOT NULL DEFAULT '',
  `electricity` varchar(30) NOT NULL DEFAULT '',
  `water_source` varchar(30) NOT NULL DEFAULT '',
  `toilet_type` varchar(30) NOT NULL DEFAULT '',
  `pregnant_or_children` tinyint(1) NOT NULL DEFAULT 0,
  `is_pwd` tinyint(1) NOT NULL DEFAULT 0,
  `pwd_id_number` varchar(100) NOT NULL DEFAULT '',
  `is_solo_parent` tinyint(1) NOT NULL DEFAULT 0,
  `is_indigenous` tinyint(1) NOT NULL DEFAULT 0,
  `pension_status` varchar(30) NOT NULL DEFAULT '',
  `health_hypertension` tinyint(1) NOT NULL DEFAULT 0,
  `health_diabetes` tinyint(1) NOT NULL DEFAULT 0,
  `health_asthma` tinyint(1) NOT NULL DEFAULT 0,
  `health_other` tinyint(1) NOT NULL DEFAULT 0,
  `health_other_specify` varchar(255) NOT NULL DEFAULT '',
  `health_none` tinyint(1) NOT NULL DEFAULT 0,
  `requires_medicine` tinyint(1) NOT NULL DEFAULT 0,
  `medicine_name` varchar(255) NOT NULL DEFAULT '',
  `school_name` varchar(255) NOT NULL DEFAULT '',
  `course` varchar(255) NOT NULL DEFAULT '',
  `year_level` varchar(30) NOT NULL DEFAULT '',
  `gwa_gpa` varchar(20) NOT NULL DEFAULT '',
  `prio_score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `submitted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_beneficiary`
--

INSERT INTO `tbl_beneficiary` (`id`, `userId`, `housing_status`, `house_material`, `electricity`, `water_source`, `toilet_type`, `pregnant_or_children`, `is_pwd`, `pwd_id_number`, `is_solo_parent`, `is_indigenous`, `pension_status`, `health_hypertension`, `health_diabetes`, `health_asthma`, `health_other`, `health_other_specify`, `health_none`, `requires_medicine`, `medicine_name`, `school_name`, `course`, `year_level`, `gwa_gpa`, `prio_score`, `status`, `submitted_at`, `created_at`, `updated_at`) VALUES
(2, 1, 'owned', 'concrete', 'own_meter', 'piped_faucet', 'private_flush', 0, 0, '', 0, 0, 'none', 0, 0, 0, 0, '', 1, 0, '', 'Neuva Ecija Science and Technology', 'BSIT', '3rd_year', '1.54', 40, 'pending', '2026-04-17 08:22:43', '2026-04-17 14:22:43', '2026-04-17 14:22:43'),
(3, 3, 'owned', 'mixed', 'own_meter', 'piped_faucet', 'private_flush', 0, 0, '', 0, 0, 'sss', 0, 0, 0, 0, '', 1, 1, 'Insulin', '', '', '', '', 40, 'approved', '2026-04-17 08:24:24', '2026-04-17 14:24:24', '2026-04-17 14:31:22'),
(4, 4, 'owned', 'mixed', 'shared', 'piped_faucet', 'private_flush', 0, 0, '', 1, 0, 'none', 0, 0, 0, 0, '', 1, 0, '', '', '', '', '', 52, 'approved', '2026-04-17 08:25:20', '2026-04-17 14:25:20', '2026-04-17 14:31:18'),
(5, 5, 'shared', 'light_materials', 'shared', 'shared_well', 'shared_public', 1, 0, '', 0, 0, 'none', 0, 1, 0, 0, '', 0, 1, 'Insulin', 'Neuva Ecija Science and Technology', 'BSIT', '2nd_year', '1.25', 79, 'approved', '2026-04-17 08:26:37', '2026-04-17 14:26:37', '2026-04-17 14:31:14'),
(6, 6, 'owned', 'concrete', 'own_meter', 'piped_faucet', 'private_flush', 0, 1, 'PWD234-567-8894', 0, 0, 'social_pension', 0, 0, 0, 0, '', 1, 0, '', '', '', '', '', 40, 'approved', '2026-04-17 08:27:38', '2026-04-17 14:27:38', '2026-04-17 14:31:11'),
(7, 7, 'informal_settler', 'light_materials', 'no_electricity', 'shared_well', 'shared_public', 1, 1, 'PWD454-890-11243', 0, 0, 'none', 0, 0, 1, 0, '', 1, 1, 'Montelukast', '', '', '', '', 90, 'approved', '2026-04-17 08:29:41', '2026-04-17 14:29:41', '2026-04-17 14:31:07'),
(8, 8, 'shared', 'mixed', 'own_meter', 'piped_faucet', 'private_flush', 0, 0, '', 0, 0, 'none', 0, 0, 0, 0, '', 1, 0, '', 'Neuva Ecija Science and Technology', 'BSBA', '4th_year', '1.00', 45, 'approved', '2026-04-17 08:30:39', '2026-04-17 14:30:39', '2026-04-17 14:31:27'),
(9, 9, 'owned', 'concrete', 'own_meter', 'piped_faucet', 'private_flush', 0, 0, '', 1, 0, 'none', 0, 0, 1, 0, '', 0, 0, '', 'Nueva Ecija University of Science and Technology', 'Bachelor of Science in Information Technology', '3rd_year', '1.80', 60, 'pending', '2026-04-18 09:35:11', '2026-04-18 15:35:11', '2026-04-18 15:35:11');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_busaptlisting`
--

CREATE TABLE `tbl_busaptlisting` (
  `id` int(11) NOT NULL,
  `userId` varchar(255) NOT NULL,
  `listingType` enum('apartment','business') NOT NULL,
  `slotsAvailable` int(11) DEFAULT 0,
  `aptType` varchar(50) DEFAULT NULL,
  `aptTitle` varchar(255) DEFAULT NULL,
  `aptStatus` enum('available','occupied','inquire') DEFAULT NULL,
  `aptPrice` decimal(10,2) DEFAULT NULL,
  `aptFloor` varchar(100) DEFAULT NULL,
  `aptRooms` int(11) DEFAULT NULL,
  `aptOccupants` int(11) DEFAULT NULL,
  `aptBath` varchar(50) DEFAULT NULL,
  `aptIncluded` text DEFAULT NULL,
  `aptAmenities` text DEFAULT NULL,
  `aptRules` text DEFAULT NULL,
  `aptDesc` text DEFAULT NULL,
  `aptAddress` text DEFAULT NULL,
  `aptMapsLink` text DEFAULT NULL,
  `bussCat` varchar(100) DEFAULT NULL,
  `bussName` varchar(255) DEFAULT NULL,
  `bussStatus` enum('open','new','temp-closed','for-rent') DEFAULT NULL,
  `bussPrice` varchar(100) DEFAULT NULL,
  `bussYears` varchar(50) DEFAULT NULL,
  `bussOpen` time DEFAULT NULL,
  `bussClose` time DEFAULT NULL,
  `bussDays` text DEFAULT NULL,
  `bussFeatures` text DEFAULT NULL,
  `bussDesc` text DEFAULT NULL,
  `bussAddress` text DEFAULT NULL,
  `bussMapsLink` text DEFAULT NULL,
  `contact` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `houseNum` varchar(50) DEFAULT NULL,
  `street` varchar(255) DEFAULT NULL,
  `barangay` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `photos` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_busaptlisting`
--

INSERT INTO `tbl_busaptlisting` (`id`, `userId`, `listingType`, `slotsAvailable`, `aptType`, `aptTitle`, `aptStatus`, `aptPrice`, `aptFloor`, `aptRooms`, `aptOccupants`, `aptBath`, `aptIncluded`, `aptAmenities`, `aptRules`, `aptDesc`, `aptAddress`, `aptMapsLink`, `bussCat`, `bussName`, `bussStatus`, `bussPrice`, `bussYears`, `bussOpen`, `bussClose`, `bussDays`, `bussFeatures`, `bussDesc`, `bussAddress`, `bussMapsLink`, `contact`, `email`, `houseNum`, `street`, `barangay`, `city`, `photos`, `createdAt`) VALUES
(1, 'Acc4', 'apartment', 5, '1br', 'Valdez Building', 'available', 3000.00, '1st Floor', 1, 1, 'private', '[\"electric\",\"water\",\"wifi\"]', '[\"fan\",\"security\",\"kitchen\"]', '[\"no-smoking\",\"curfew\",\"no-cooking\"]', '', '256 Purok 2, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/3XXnbpqADJKirYhW9', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '09982617865', 'alyssa0125@gmail.com', '256', 'Purok 2', 'Sumacab Este', 'Cabanatuan City', '[\"1776405262_69e1cb0e06a09.png\",\"1776405262_69e1cb0e073c5.png\",\"1776405262_69e1cb0e0789b.png\",\"1776405262_69e1cb0e07cf9.png\"]', '2026-04-17 05:54:22'),
(2, 'Acc4', 'apartment', 5, '2br', 'Valdez Building', '', 6000.00, '1st Floor', 2, 2, 'private', '[\"electric\",\"water\",\"wifi\"]', '[\"aircon\",\"laundry\",\"cctv\"]', '[\"no-smoking\",\"curfew\"]', '', '256 Purok 2, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/3XXnbpqADJKirYhW9', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '09982617865', 'alyssa0125@gmail.com', '256', 'Purok 2', 'Sumacab Este', 'Cabanatuan City', '[\"1776405323_69e1cb4b04682.png\",\"1776405323_69e1cb4b04e22.png\",\"1776405323_69e1cb4b05482.png\",\"1776405323_69e1cb4b05aa8.png\"]', '2026-04-17 05:55:23'),
(3, 'Acc5', 'apartment', 3, 'studio', 'Castillo Studio Apartment', 'inquire', 5000.00, '1st Floor', 1, 2, 'private', '[\"electric\",\"water\"]', '[\"fan\"]', '[\"no-smoking\",\"no-pets\",\"no-visitors\"]', '', '889 Purok 7, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/ksLLTRLhDjxZRA5g9', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '09256785411', 'beacastillo0122@gmail.com', '889', 'Purok 7', 'Sumacab Este', 'Cabanatuan City', '[\"1776405566_69e1cc3ebb59a.png\",\"1776405566_69e1cc3ebbd7d.png\",\"1776405566_69e1cc3ebc282.png\",\"1776405566_69e1cc3ebc7b2.png\"]', '2026-04-17 05:59:26'),
(4, 'Acc5', 'business', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'bakery', 'ALING NENA\'S BAKESHOP', 'open', '5', '1', '06:00:00', '14:00:00', '[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\"]', '[\"delivery\",\"pickup\",\"dine-in\",\"gcash\",\"maya\"]', '', '889 Purok 7, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/ksLLTRLhDjxZRA5g9', '09256785411', 'beacastillo0122@gmail.com', '889', 'Purok 7', 'Sumacab Este', 'Cabanatuan City', '[\"1776405729_69e1cce12201f.png\",\"1776405729_69e1cce122ea7.png\",\"1776405729_69e1cce1239de.png\",\"1776405729_69e1cce124276.png\"]', '2026-04-17 06:02:09'),
(5, 'Acc5', 'business', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'food', 'ALING ROSAS KAINAN', 'new', '50', 'new', '08:00:00', '21:00:00', '[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\",\"sat\",\"sun\"]', '[\"pickup\",\"dine-in\",\"gcash\",\"maya\"]', '', '889 Purok 7, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/ksLLTRLhDjxZRA5g9', '09256785411', 'beacastillo0122@gmail.com', '889', 'Purok 7', 'Sumacab Este', 'Cabanatuan City', '[\"1776405805_69e1cd2d14b5b.png\",\"1776405805_69e1cd2d15073.png\",\"1776405805_69e1cd2d1545a.png\",\"1776405805_69e1cd2d159c0.png\"]', '2026-04-17 06:03:25'),
(6, 'Acc7', 'business', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'hardware', 'Pupong\'s General Hardware', 'open', '100', '5-10', '08:00:00', '17:00:00', '[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\"]', '[\"delivery\",\"pickup\",\"parking\",\"gcash\",\"maya\"]', '', '632 Purok 6, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/sJPCCmHcUVi9CYLw7', '09267715731', 'jerome012235@gmail.com', '632', 'Purok 6', 'Sumacab Este', 'Cabanatuan City', '[\"1776406049_69e1ce219818f.png\",\"1776406049_69e1ce219874e.png\",\"1776406049_69e1ce2198eec.png\",\"1776406049_69e1ce2199616.png\"]', '2026-04-17 06:07:29'),
(7, 'Acc7', 'business', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pharmacy', 'Generics Botika', 'open', '50', '2-5', '08:00:00', '17:00:00', '[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\",\"sat\",\"sun\"]', '[\"pickup\",\"parking\",\"gcash\",\"maya\"]', '', '258 Purok 6, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/9J2ARAQCSyRSEXse6', '09267715731', 'jerome012235@gmail.com', '258', 'Purok 6', 'Sumacab Este', 'Cabanatuan City', '[\"1776406180_69e1cea4e8a04.png\",\"1776406180_69e1cea4e966d.png\",\"1776406180_69e1cea4e9b27.png\",\"1776406180_69e1cea4e9fe6.png\"]', '2026-04-17 06:09:40'),
(8, 'Acc8', 'business', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'printing', 'Kuya Pablo\'s Net & Print', 'open', '10', '5-10', '08:00:00', '18:00:00', '[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\",\"sat\",\"sun\",\"holiday\"]', '[\"pickup\",\"parking\",\"gcash\",\"maya\"]', '', '346 Purok 1, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/rBdZSUpbzMPfBgWH7', '09758911656', 'kenneth5622@gmail.com', '346', 'Purok 1', 'Sumacab Este', 'Cabanatuan City', '[\"1776406340_69e1cf441d922.png\",\"1776406340_69e1cf441dee9.png\",\"1776406340_69e1cf441e3b6.png\",\"1776406340_69e1cf441e9c8.png\"]', '2026-04-17 06:12:20'),
(9, 'Acc8', 'business', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'sari-sari', 'Aling Rosa\'s Sari Sari Store', 'open', '10', '10+', '05:00:00', '23:59:00', '[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\",\"sat\",\"sun\",\"holiday\"]', '[\"pickup\",\"gcash\",\"maya\"]', '', '346 Purok 1, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/rBdZSUpbzMPfBgWH7', '09758911656', 'kenneth5622@gmail.com', '346', 'Purok 1', 'Sumacab Este', 'Cabanatuan City', '[\"1776406422_69e1cf96c5be9.png\",\"1776406422_69e1cf96c636a.png\",\"1776406422_69e1cf96c6932.png\",\"1776406422_69e1cf96c6dd0.png\"]', '2026-04-17 06:13:42'),
(10, 'Acc9', 'apartment', 15, 'whole-unit', 'Mercado Apartment', 'available', 7000.00, '1st, 2nd, 3rd', 2, 5, 'private', '[\"electric\",\"water\",\"wifi\"]', '[\"aircon\",\"fan\",\"parking\",\"laundry\",\"cctv\",\"gate\"]', '[\"no-smoking\",\"no-pets\",\"no-visitors\",\"curfew\"]', '', '587 Purok 4, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/Se8VwL3ZyB4FzJHM9', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '09999852567', 'mercadomaria01@gmail.com', '587', 'Purok 4', 'Sumacab Este', 'Cabanatuan City', '[\"1776406596_69e1d04408a9f.png\",\"1776406596_69e1d044091b8.png\",\"1776406596_69e1d044097ec.png\",\"1776406596_69e1d04409c82.png\"]', '2026-04-17 06:16:36'),
(11, 'Acc9', 'business', 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'salon', 'Mercado\'s Salon', 'open', '70', '2-5', '08:00:00', '17:00:00', '[\"mon\",\"tue\",\"wed\",\"thu\",\"fri\",\"sat\",\"sun\"]', '[\"parking\",\"gcash\",\"maya\"]', '', '346 Purok 4, Sumacab Este, Cabanatuan City', 'https://maps.app.goo.gl/Se8VwL3ZyB4FzJHM9', '09999852567', 'mercadomaria01@gmail.com', '346', 'Purok 4', 'Sumacab Este', 'Cabanatuan City', '[\"1776406717_69e1d0bd7b5c6.png\",\"1776406717_69e1d0bd7bbb5.png\",\"1776406717_69e1d0bd7bff1.png\",\"1776406717_69e1d0bd7c3e7.png\"]', '2026-04-17 06:18:37');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_count`
--

CREATE TABLE `tbl_count` (
  `count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_count`
--

INSERT INTO `tbl_count` (`count`) VALUES
(13);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_equipmentlist`
--

CREATE TABLE `tbl_equipmentlist` (
  `equipmentId` int(11) NOT NULL,
  `equipmentName` varchar(255) NOT NULL,
  `equipmentStock` int(11) NOT NULL DEFAULT 0,
  `equipmentImage` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_equipmentlist`
--

INSERT INTO `tbl_equipmentlist` (`equipmentId`, `equipmentName`, `equipmentStock`, `equipmentImage`, `description`, `createdAt`, `updatedAt`) VALUES
(3, 'Barangay Tent', 5, 'eq_69d75f9166dfa9.03240481.jpg', '', '2026-04-09 08:13:05', '2026-04-28 11:51:30'),
(5, 'Printer', 2, 'eq_69d7606195b270.56418141.jpg', '', '2026-04-09 08:16:33', '2026-04-09 08:16:33'),
(6, 'Speaker', 4, 'eq_69d7608a4e9093.98845461.jpg', '', '2026-04-09 08:17:14', '2026-04-09 08:17:14'),
(7, 'Gardening Tools', 3, 'eq_69d760b00ca964.46161988.jpg', '', '2026-04-09 08:17:52', '2026-04-09 08:17:52'),
(8, 'Hand Tools', 3, 'eq_69d760cc73e513.94371233.jpg', '', '2026-04-09 08:18:20', '2026-04-09 08:18:20'),
(9, 'Basketball - Ball', 10, 'eq_69d760f9ec0cb4.17803386.jpg', '', '2026-04-09 08:19:05', '2026-04-09 08:19:05'),
(10, 'Volleyball - Ball & Net', 5, 'eq_69d7612179e7f2.04782543.jpg', '', '2026-04-09 08:19:45', '2026-04-18 06:54:10'),
(11, 'Barangay Barrier', 10, 'eq_69d76176deea26.74114632.png', '', '2026-04-09 08:21:10', '2026-04-09 08:21:10');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_equipmentrequest`
--

CREATE TABLE `tbl_equipmentrequest` (
  `id` int(11) NOT NULL,
  `userId` int(11) NOT NULL,
  `equipmentId` int(11) NOT NULL,
  `quantityRequested` int(11) NOT NULL DEFAULT 1,
  `status` varchar(50) DEFAULT 'pending',
  `requestDate` timestamp NOT NULL DEFAULT current_timestamp(),
  `approvedDate` timestamp NULL DEFAULT NULL,
  `returnDate` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `updatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_equipmentrequest`
--

INSERT INTO `tbl_equipmentrequest` (`id`, `userId`, `equipmentId`, `quantityRequested`, `status`, `requestDate`, `approvedDate`, `returnDate`, `notes`, `createdAt`, `updatedAt`) VALUES
(2, 6, 3, 2, 'Returned', '2026-04-17 06:39:21', NULL, '2026-04-17 16:00:00', '', '2026-04-17 06:39:21', '2026-04-28 11:51:30'),
(3, 3, 10, 1, 'Returned', '2026-04-17 06:40:01', NULL, '2026-04-17 16:00:00', '', '2026-04-17 06:40:01', '2026-04-18 06:54:10'),
(4, 4, 11, 1, 'pending', '2026-04-17 06:40:27', NULL, '2026-04-17 16:00:00', '', '2026-04-17 06:40:27', '2026-04-17 06:40:27'),
(5, 4, 8, 1, 'pending', '2026-04-17 06:40:27', NULL, '2026-04-17 16:00:00', '', '2026-04-17 06:40:27', '2026-04-17 06:40:27'),
(6, 4, 7, 1, 'pending', '2026-04-17 06:40:27', NULL, '2026-04-17 16:00:00', '', '2026-04-17 06:40:27', '2026-04-17 06:40:27'),
(7, 7, 9, 2, 'pending', '2026-04-17 06:40:54', NULL, '2026-04-17 16:00:00', '', '2026-04-17 06:40:54', '2026-04-17 06:40:54'),
(8, 9, 11, 1, 'pending', '2026-04-18 07:08:29', NULL, '2026-04-20 16:00:00', '', '2026-04-18 07:08:29', '2026-04-18 07:08:29');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_password_resets`
--

CREATE TABLE `tbl_password_resets` (
  `id` int(11) NOT NULL,
  `accID` varchar(255) NOT NULL,
  `selector` char(16) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_password_resets`
--

INSERT INTO `tbl_password_resets` (`id`, `accID`, `selector`, `token_hash`, `expires_at`, `used_at`, `created_at`) VALUES
(54, 'Acc2', 'f3daccc3a24f3582', '4cbca70127eb5572a49772915222e5945e9541a450948a24ec6f0db8ed0cf5e3', '2026-04-18 10:39:53', NULL, '2026-04-18 15:39:53');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_requestdocs`
--

CREATE TABLE `tbl_requestdocs` (
  `id` int(10) UNSIGNED NOT NULL,
  `userId` int(11) NOT NULL,
  `document_type` varchar(50) NOT NULL,
  `num_copies` int(11) NOT NULL DEFAULT 1,
  `purpose` varchar(100) NOT NULL,
  `notes` text NOT NULL DEFAULT '',
  `uploaded_files` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`uploaded_files`)),
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `submitted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_requestdocs`
--

INSERT INTO `tbl_requestdocs` (`id`, `userId`, `document_type`, `num_copies`, `purpose`, `notes`, `uploaded_files`, `status`, `submitted_at`, `created_at`, `updated_at`) VALUES
(1, 8, 'barangay_clearance', 1, 'scholarship', '', '[\"doc_69e1d479a29a86.30018420_Macapagal-John-Patrick-A.-1.pdf\"]', 'pending', '2026-04-17 08:34:33', '2026-04-17 14:34:33', '2026-04-17 14:34:33'),
(2, 7, 'business_permit', 1, 'loan_application', '', '[\"doc_69e1d4d1777d95.40147696_KennethIDFront.jpg\"]', 'pending', '2026-04-17 08:36:01', '2026-04-17 14:36:01', '2026-04-17 14:36:01'),
(3, 1, 'certificate_indigency', 1, 'scholarship', '', '[\"doc_69e1d5292af2c4.53709063_Macapagal-John-Patrick-A.-1.pdf\"]', 'pending', '2026-04-17 08:37:29', '2026-04-17 14:37:29', '2026-04-17 14:37:29'),
(4, 6, 'certificate_residency', 1, 'loan_application', '', '[\"doc_69e1d56566b3c2.72539675_JeromeIDFront.jpg\"]', 'approved', '2026-04-17 08:38:29', '2026-04-17 14:38:29', '2026-04-18 14:52:46'),
(5, 9, 'barangay_clearance', 2, 'scholarship', 'Need before Sunday', '[\"doc_69e3347b308ed8.02522920_662282217_4365953280341612_2647520712448762530_n.jpg\"]', 'pending', '2026-04-18 09:36:27', '2026-04-18 15:36:27', '2026-04-18 15:36:27'),
(6, 5, 'barangay_clearance', 1, 'employment', '', NULL, 'pending', '2026-07-21 15:59:50', '2026-07-21 21:59:51', '2026-07-21 21:59:51');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_useracc`
--

CREATE TABLE `tbl_useracc` (
  `accID` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `account_role` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_useracc`
--

INSERT INTO `tbl_useracc` (`accID`, `email`, `password`, `account_role`) VALUES
('Acc1', 'sumesteadmin@gmail.com', '$2y$10$mLRpU1WAI0RImzTtRCSOoO9Tynfera0PC7U5PkAxazES5NK6FSMam', 'admin'),
('Acc10', 'kurtarguelles@gmail.com', '$2y$10$rI4wzMth1P5P6p9jVzWFR.PeklPOkEjQSauEc621ngElPRrmZeJ66', 'resident'),
('Acc12', 'lush@gmail.com', '$2y$10$vomu2uQxUKlv4NilJy6lMesuB9DQC/7QTYdhYbUbPwErvZn6UWiWW', 'resident'),
('Acc13', 'liza@gmail.com', '$2y$10$4uGMrU561iJFkn7JLsK6kuRE8NUortIiF3qyWEc9yu0lj2lidgcjG', 'non-resident,business/apartment owner'),
('Acc2', 'macapagalpatrickjohn@gmail.com', '$2y$10$SLWBuPd47/84GS/LBI2OB.IInNX04eU/S9/1gfrktdInZOP7OWjWS', 'resident,business/apartment owner'),
('Acc3', 'joshuamadulid00@gmail.com', '$2y$10$38tNPO9ZXOI/k496jiO5qO4aOFulv2K7t4gxg.1gCegOvgbC.KLiO', 'non-resident'),
('Acc4', 'alyssa0125@gmail.com', '$2y$10$I.e.Df5M6cAVCcrV6zV.x.aL5laNJj7wCbCciZF66ik/qdsNoZedC', 'resident,business/apartment owner'),
('Acc5', 'beacastillo0122@gmail.com', '$2y$10$QOWuBhAYDPIjQB7F6BWVzOiujjci2gkYhHW.2YZ74z47uLXQY.7LO', 'resident,business/apartment owner'),
('Acc6', 'gabriel1255@gmail.com', '$2y$10$/NOgTOYIUT4hAFySzXXyZeNuBD1ZVqkPjkzsm2/lhy8IdwAFPC6Xu', 'resident'),
('Acc7', 'jerome012235@gmail.com', '$2y$10$LNdDfj3tTrgVfeCBKGaTYu9K2DhOenzc2bAABp4VDyBmTV/dbFDNe', 'resident,business/apartment owner'),
('Acc8', 'kenneth5622@gmail.com', '$2y$10$.x7sJEf4UJXs0YRMS349sOsDiqvEpUrf/vx92zGMYrDXbW2LD6BUO', 'resident,business/apartment owner'),
('Acc9', 'mercadomaria01@gmail.com', '$2y$10$NCH3ZXOppLUMZvFvrLD63OFhd6vHuXrEJpm/rUnDVte8lQAz14UTO', 'resident,business/apartment owner');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_userinfo`
--

CREATE TABLE `tbl_userinfo` (
  `userID` int(11) NOT NULL,
  `accID` varchar(255) DEFAULT NULL,
  `account_role_csv` varchar(255) DEFAULT NULL,
  `pending_role` varchar(100) DEFAULT '',
  `firstname` varchar(100) DEFAULT NULL,
  `lastname` varchar(100) DEFAULT NULL,
  `middlename` varchar(100) DEFAULT NULL,
  `suffix` varchar(50) DEFAULT NULL,
  `family_role` varchar(50) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `birthplace` varchar(100) DEFAULT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `citizenship` varchar(50) DEFAULT NULL,
  `religion` varchar(50) DEFAULT NULL,
  `ethnicity` varchar(50) DEFAULT NULL,
  `street` varchar(255) DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `zip` varchar(20) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `emergency_contact_relationship` varchar(255) DEFAULT '',
  `emergency_phone` varchar(20) DEFAULT NULL,
  `health_conditions` text DEFAULT NULL,
  `employment_status` varchar(50) DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `monthly_income` decimal(12,2) DEFAULT NULL,
  `years_resident` int(11) DEFAULT NULL,
  `resident_birth` tinyint(1) DEFAULT 0,
  `id_front` varchar(500) DEFAULT '',
  `id_back` varchar(500) DEFAULT '',
  `voter_id` varchar(50) DEFAULT NULL,
  `precinct` varchar(50) DEFAULT NULL,
  `userStatus` varchar(255) NOT NULL,
  `frontID` varchar(255) DEFAULT NULL,
  `backID` varchar(255) DEFAULT NULL,
  `dateRegistered` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_userinfo`
--

INSERT INTO `tbl_userinfo` (`userID`, `accID`, `account_role_csv`, `pending_role`, `firstname`, `lastname`, `middlename`, `suffix`, `family_role`, `gender`, `birthday`, `birthplace`, `civil_status`, `citizenship`, `religion`, `ethnicity`, `street`, `barangay`, `city`, `province`, `zip`, `phone`, `email`, `emergency_contact`, `emergency_contact_relationship`, `emergency_phone`, `health_conditions`, `employment_status`, `job_title`, `monthly_income`, `years_resident`, `resident_birth`, `id_front`, `id_back`, `voter_id`, `precinct`, `userStatus`, `frontID`, `backID`, `dateRegistered`) VALUES
(1, 'Acc2', 'resident,business/apartment owner', '', 'John Patrick', 'Macapagal', 'Andres', '', 'child', 'male', '2005-05-25', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Purok 5', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09068919380', 'macapagalpatrickjohn@gmail.com', 'Roderick Macapagal', '', '09068445449', 'B+', 'student', 'N/A', 0.00, 20, 1, '', '', '4879-8491-3869-0280', '044A', 'approved', '../uploads/id_verification/PatrickIDFront.jpg', '../uploads/id_verification/PatrickIDBack.jpg', '2026-04-17 05:40:16'),
(2, 'Acc3', 'non-resident', '', 'Joshua', 'Madulid', 'Mabagos', '', 'child', 'male', '2004-10-04', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Tomana Street', 'Sinasajan', 'Peñaranda', 'Nueva Ecija', '3103', '09613485486', 'joshuamadulid00@gmail.com', 'Aisa Madulid', '', '09615961086', 'A+', '', '', 0.00, 0, 0, '', '', '', '', 'approved', '../uploads/id_verification/denri15l237gpeavdvj9oabnkm_front.jpg', '../uploads/id_verification/denri15l237gpeavdvj9oabnkm_back.jpg', '2026-04-17 05:51:34'),
(3, 'Acc4', 'resident,business/apartment owner', '', 'Alyssa', 'Valdez', 'Andres', '', 'head', 'female', '1990-01-01', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Purok 2', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09982617865', 'alyssa0125@gmail.com', 'Maria Valdez', '', '09672516782', 'B-', 'self-employed', 'Business Owner', 10000.00, 36, 1, '', '', '2345-6789-0123-4567', '0577B', 'approved', '../uploads/id_verification/AlyssaIDFront.jpg', '../uploads/id_verification/AlyssaIDBack.jpg', '2026-04-17 06:04:41'),
(4, 'Acc5', 'resident,business/apartment owner', '', 'Bea', 'Castillo', 'Santos', '', 'head', 'female', '1990-01-01', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Purok 7', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09256785411', 'beacastillo0122@gmail.com', 'Josepina Castillo', '', '09678265378', 'O', 'self-employed', 'Business Owner', 4999.00, 36, 1, '', '', '3456-7890-1234-5678', '0271A', 'approved', '../uploads/id_verification/BeaIDFront.jpg', '../uploads/id_verification/BeaIDBack.jpg', '2026-04-17 06:11:08'),
(5, 'Acc6', 'resident', '', 'Gabriel', 'Aguinaldo', 'Romolo', '', 'head', 'male', '1990-01-01', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Purok 5', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09567223889', 'gabriel1255@gmail.com', 'Annabel Aguinaldo', '', '09676562199', 'A+', 'unemployed', 'N/A', 0.00, 36, 1, '', '', '2345-6789-0123-4567', '0155B', 'approved', '../uploads/id_verification/GabrielIDFront.jpg', '../uploads/id_verification/GabrielIDBack.jpg', '2026-04-17 06:18:22'),
(6, 'Acc7', 'resident,business/apartment owner', '', 'Jerome', 'Santos', 'Valdez', '', 'head', 'male', '1990-01-01', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Purok 6', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09267715731', 'jerome012235@gmail.com', 'Rosabel Valdez', '', '09982567118', 'O-', 'self-employed', 'Business Owner', 7500.00, 36, 1, '', '', '3456-7890-1234-5678', '0524A', 'approved', '../uploads/id_verification/JeromeIDFront.jpg', '../uploads/id_verification/JeromeIDBack.jpg', '2026-04-17 07:11:20'),
(7, 'Acc8', 'resident,business/apartment owner', '', 'Kenneth', 'Dela Cruz', 'Vero', '', 'head', 'male', '1990-01-01', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Purok 1', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09758911656', 'kenneth5622@gmail.com', 'Olivia Dela Cruz', '', '09751167254', 'B-', 'self-employed', 'Business Owner', 4500.00, 36, 1, '', '', '2345-6789-0123-4567', '0323B', 'approved', '../uploads/id_verification/KennethIDFront.jpg', '../uploads/id_verification/KennethIDBack.jpg', '2026-04-17 07:16:55'),
(8, 'Acc9', 'resident,business/apartment owner', '', 'Maria', 'Mercado', 'Pineda', '', 'head', 'female', '1990-01-01', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Purok 4', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09999852567', 'mercadomaria01@gmail.com', 'Luzviminda Mercado', '', '09872674135', 'B-', 'self-employed', 'Business Owner', 3000.00, 36, 1, '', '', '2345-6789-0123-4567', '0455A', 'approved', '../uploads/id_verification/MariaIDFront.jpg', '../uploads/id_verification/MariaIDBack.jpg', '2026-04-17 07:23:01'),
(9, 'Acc10', 'resident', '', 'Kurt', 'Arguelles', 'Millares', '', 'child', 'male', '2004-11-05', 'Manila City, NCR', 'single', 'Filipino', 'Catholic', '', 'Purok 7', 'Sumacab Este', 'Cabanatuan City', 'Nueva Ecija', '3100', '09615961087', 'kurtarguelles@gmail.com', 'Catalina Arguelles', '', '09289419630', 'O+', 'student', '', 0.00, 21, 0, '', '', '4790-3197-5204-8735', '0076B', 'approved', '../uploads/id_verification/lvvmvgv261kc83g70ni5adfq39_front.jpg', '../uploads/id_verification/lvvmvgv261kc83g70ni5adfq39_back.jpg', '2026-04-18 08:01:50'),
(11, 'Acc12', 'resident', '', 'kurt', 'arguelles', '', '', 'spouse', 'male', '2026-04-27', 'Cabanatuan City', 'married', 'Filipino', 'Catholic', 'Tagalog', 'Purok 4', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09893874823', 'lush@gmail.com', 'hello\'),(\'hacked', '', '', '', 'employed', '', 0.00, 0, 1, '', '', '2345-6789-0123-4567', '', 'pending', '../uploads/id_verification/slc7rl89hbonq1bplbat9tkclo_front.png', '../uploads/id_verification/slc7rl89hbonq1bplbat9tkclo_back.png', '2026-05-03 07:26:11'),
(12, 'Acc13', 'non-resident,business/apartment owner', '', 'liza', 'serquina', 'Santos', '', 'child', 'female', '2001-09-15', 'Cabanatuan City', 'single', 'Filipino', 'Catholic', 'Tagalog', 'Purok 7', 'Sumacab Este', 'Cabanatuan city', 'Nueva Ecija', '3100', '09256785411', 'liza@gmail.com', 'Josepina Castillo', '', '', 'O', '', '', 0.00, 0, 0, '', '', '', '', 'approved', '../uploads/id_verification/42sfqac9ca6dsf8vam3i93dclj_front.jpg', '../uploads/id_verification/42sfqac9ca6dsf8vam3i93dclj_back.jpg', '2026-06-20 08:54:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_announcement`
--
ALTER TABLE `tbl_announcement`
  ADD PRIMARY KEY (`announcementID`);

--
-- Indexes for table `tbl_beneficiary`
--
ALTER TABLE `tbl_beneficiary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_userId` (`userId`),
  ADD KEY `idx_prio_score` (`prio_score`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `tbl_busaptlisting`
--
ALTER TABLE `tbl_busaptlisting`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userId` (`userId`);

--
-- Indexes for table `tbl_equipmentlist`
--
ALTER TABLE `tbl_equipmentlist`
  ADD PRIMARY KEY (`equipmentId`);

--
-- Indexes for table `tbl_equipmentrequest`
--
ALTER TABLE `tbl_equipmentrequest`
  ADD PRIMARY KEY (`id`),
  ADD KEY `userId` (`userId`),
  ADD KEY `equipmentId` (`equipmentId`);

--
-- Indexes for table `tbl_password_resets`
--
ALTER TABLE `tbl_password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_selector` (`selector`),
  ADD KEY `idx_accid` (`accID`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `tbl_requestdocs`
--
ALTER TABLE `tbl_requestdocs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_userId` (`userId`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `tbl_useracc`
--
ALTER TABLE `tbl_useracc`
  ADD PRIMARY KEY (`accID`);

--
-- Indexes for table `tbl_userinfo`
--
ALTER TABLE `tbl_userinfo`
  ADD PRIMARY KEY (`userID`),
  ADD KEY `fk_accID` (`accID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_announcement`
--
ALTER TABLE `tbl_announcement`
  MODIFY `announcementID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `tbl_beneficiary`
--
ALTER TABLE `tbl_beneficiary`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `tbl_busaptlisting`
--
ALTER TABLE `tbl_busaptlisting`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `tbl_equipmentlist`
--
ALTER TABLE `tbl_equipmentlist`
  MODIFY `equipmentId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `tbl_equipmentrequest`
--
ALTER TABLE `tbl_equipmentrequest`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `tbl_password_resets`
--
ALTER TABLE `tbl_password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `tbl_requestdocs`
--
ALTER TABLE `tbl_requestdocs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tbl_userinfo`
--
ALTER TABLE `tbl_userinfo`
  MODIFY `userID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_beneficiary`
--
ALTER TABLE `tbl_beneficiary`
  ADD CONSTRAINT `tbl_beneficiary_ibfk_1` FOREIGN KEY (`userId`) REFERENCES `tbl_userinfo` (`userID`);

--
-- Constraints for table `tbl_busaptlisting`
--
ALTER TABLE `tbl_busaptlisting`
  ADD CONSTRAINT `tbl_busaptlisting_ibfk_1` FOREIGN KEY (`userId`) REFERENCES `tbl_userinfo` (`accID`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_equipmentrequest`
--
ALTER TABLE `tbl_equipmentrequest`
  ADD CONSTRAINT `tbl_equipmentrequest_ibfk_1` FOREIGN KEY (`userId`) REFERENCES `tbl_userinfo` (`userID`) ON DELETE CASCADE,
  ADD CONSTRAINT `tbl_equipmentrequest_ibfk_2` FOREIGN KEY (`equipmentId`) REFERENCES `tbl_equipmentlist` (`equipmentId`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_password_resets`
--
ALTER TABLE `tbl_password_resets`
  ADD CONSTRAINT `fk_password_resets_accid` FOREIGN KEY (`accID`) REFERENCES `tbl_useracc` (`accID`) ON DELETE CASCADE;

--
-- Constraints for table `tbl_requestdocs`
--
ALTER TABLE `tbl_requestdocs`
  ADD CONSTRAINT `tbl_requestdocs_ibfk_1` FOREIGN KEY (`userId`) REFERENCES `tbl_userinfo` (`userID`);

--
-- Constraints for table `tbl_userinfo`
--
ALTER TABLE `tbl_userinfo`
  ADD CONSTRAINT `fk_accID` FOREIGN KEY (`accID`) REFERENCES `tbl_useracc` (`accID`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
