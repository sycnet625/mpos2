/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: palweb_central
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

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
-- Table structure for table `almacenes`
--

DROP TABLE IF EXISTS `almacenes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `almacenes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_sucursal` int(11) NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `id_sucursal` (`id_sucursal`),
  CONSTRAINT `1` FOREIGN KEY (`id_sucursal`) REFERENCES `sucursales` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `almacenes`
--

LOCK TABLES `almacenes` WRITE;
/*!40000 ALTER TABLE `almacenes` DISABLE KEYS */;
INSERT INTO `almacenes` VALUES
(1,1,'main marinero',1),
(2,2,'insumos roly',1),
(3,2,'terminados roly',1);
/*!40000 ALTER TABLE `almacenes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auditoria_pos`
--

DROP TABLE IF EXISTS `auditoria_pos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `auditoria_pos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `accion` varchar(50) DEFAULT NULL,
  `usuario` varchar(100) DEFAULT NULL,
  `datos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`datos`)),
  `fecha` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auditoria_pos`
--

LOCK TABLES `auditoria_pos` WRITE;
/*!40000 ALTER TABLE `auditoria_pos` DISABLE KEYS */;
INSERT INTO `auditoria_pos` VALUES
(1,'COMPRA_REGISTRADA','Admin','{\"id\":\"1\"}','2026-01-30 03:53:23'),
(2,'COMPRA_REGISTRADA','Admin','{\"id\":\"2\"}','2026-01-30 03:58:20'),
(3,'COMPRA_REGISTRADA','Admin','{\"id\":\"3\"}','2026-01-30 04:30:04'),
(4,'COMPRA_REGISTRADA','Admin','{\"id\":\"4\"}','2026-01-30 04:34:58'),
(5,'MERMA_REGISTRADA','Admin','{\"id\":\"1\"}','2026-01-30 04:42:59'),
(6,'COMPRA_REGISTRADA','Admin','{\"id\":\"5\"}','2026-01-30 04:48:01'),
(7,'MERMA_REGISTRADA','Admin','{\"id\":\"2\"}','2026-01-30 15:55:57'),
(8,'COMPRA_REGISTRADA','Admin','{\"id\":\"6\",\"fecha\":\"2026-01-02 11:36:03\"}','2026-01-30 16:36:03'),
(9,'COMPRA_CANCELADA','Admin','{\"id\":4}','2026-01-31 14:52:34'),
(10,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660037\",\"nuevo_stock\":16}','2026-01-31 15:06:57'),
(11,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660023\",\"nuevo_stock\":0}','2026-01-31 15:07:09'),
(12,'COMPRA_REGISTRADA','Admin','{\"id\":\"7\"}','2026-01-31 15:08:03'),
(13,'COMPRA_CANCELADA','Admin','{\"id\":7}','2026-01-31 15:08:32'),
(14,'COMPRA_CANCELADA','Admin','{\"id\":6}','2026-01-31 15:09:08'),
(15,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"756058841446\",\"nuevo_stock\":0}','2026-01-31 15:09:38'),
(16,'COMPRA_REGISTRADA','Admin','{\"id\":\"8\"}','2026-02-01 13:39:18'),
(17,'COMPRA_REGISTRADA','Admin','{\"id\":\"9\"}','2026-02-02 01:38:55'),
(18,'COMPRA_CANCELADA','Admin','{\"id\":8}','2026-02-02 01:39:04'),
(19,'COMPRA_CANCELADA','Admin','{\"id\":4}','2026-02-02 02:26:22'),
(20,'COMPRA_REGISTRADA','Admin','{\"id\":\"10\"}','2026-02-02 02:37:20'),
(21,'COMPRA_REGISTRADA','Admin','{\"id\":\"11\"}','2026-02-02 23:41:43'),
(22,'COMPRA_REGISTRADA','Admin','{\"id\":\"12\"}','2026-02-02 23:44:49'),
(23,'MERMA_REGISTRADA','Admin','{\"id\":\"3\"}','2026-02-02 23:45:38'),
(24,'COMPRA_REGISTRADA','Admin','{\"id\":\"13\"}','2026-02-03 00:09:47'),
(25,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660033\",\"nuevo_stock\":26}','2026-02-03 00:13:50'),
(26,'MERMA_REGISTRADA','Admin','{\"id\":\"4\"}','2026-02-03 00:16:32'),
(27,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660012\",\"nuevo_stock\":12}','2026-02-03 00:17:07'),
(28,'COMPRA_REGISTRADA','Admin','{\"id\":\"14\"}','2026-02-03 00:20:45'),
(29,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660012\",\"nuevo_stock\":7}','2026-02-03 00:39:49'),
(30,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660018\",\"nuevo_stock\":2}','2026-02-03 00:40:46'),
(31,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660002\",\"nuevo_stock\":28}','2026-02-03 00:42:02'),
(32,'MERMA_REGISTRADA','Admin','{\"id\":\"5\"}','2026-02-03 00:42:25'),
(33,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660034\",\"nuevo_stock\":127}','2026-02-03 00:43:22'),
(34,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660032\",\"nuevo_stock\":17}','2026-02-03 00:43:44'),
(35,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"660035\",\"nuevo_stock\":21}','2026-02-03 00:45:12'),
(36,'STOCK_AJUSTE_INLINE','Admin','{\"sku\":\"756058841478\",\"nuevo_stock\":2}','2026-02-03 00:46:32'),
(37,'COMPRA_REGISTRADA','Admin','{\"id\":\"15\"}','2026-02-04 14:55:48'),
(38,'MERMA_REGISTRADA','Admin','{\"id\":\"6\"}','2026-02-04 14:57:03'),
(39,'MERMA_REGISTRADA','Admin','{\"id\":\"7\"}','2026-02-04 14:58:42'),
(40,'MERMA_REGISTRADA','Admin','{\"id\":\"8\"}','2026-02-04 15:00:47'),
(41,'MERMA_REGISTRADA','Admin','{\"id\":\"9\"}','2026-02-04 15:01:18'),
(42,'MERMA_REGISTRADA','Admin','{\"id\":\"10\"}','2026-02-04 15:01:52'),
(43,'MERMA_REGISTRADA','Admin','{\"id\":\"11\"}','2026-02-04 15:02:42'),
(44,'MERMA_REGISTRADA','Admin','{\"id\":\"12\"}','2026-02-04 15:22:30'),
(45,'COMPRA_REGISTRADA','Admin','{\"id\":\"16\"}','2026-02-04 15:24:06'),
(46,'COMPRA_REGISTRADA','Admin','{\"id\":\"17\"}','2026-02-04 15:51:07'),
(47,'COMPRA_REGISTRADA','Admin','{\"id\":\"18\"}','2026-02-04 15:59:41'),
(48,'COMPRA_REGISTRADA','Admin','{\"id\":\"19\"}','2026-02-04 16:04:23'),
(49,'PRECIO_UPDATE','Admin','{\"sku\":\"660016\",\"nuevo_precio\":730}','2026-02-04 16:09:07'),
(50,'PRECIO_UPDATE','Admin','{\"sku\":\"660101\",\"nuevo_precio\":40}','2026-02-04 16:49:45'),
(51,'PRECIO_UPDATE','Admin','{\"sku\":\"660101\",\"nuevo_precio\":30}','2026-02-04 16:50:16'),
(52,'PRECIO_UPDATE','Admin','{\"sku\":\"660101\",\"nuevo_precio\":40}','2026-02-04 17:37:42'),
(53,'PRECIO_UPDATE','Admin','{\"sku\":\"660101\",\"nuevo_precio\":35}','2026-02-04 17:40:00'),
(54,'PRECIO_UPDATE','Admin','{\"sku\":\"660101\",\"nuevo_precio\":40}','2026-02-04 17:43:04'),
(55,'COMPRA_REGISTRADA','Admin','{\"id\":\"20\"}','2026-02-07 14:48:07'),
(56,'MERMA_REGISTRADA','Admin','{\"id\":\"14\"}','2026-02-07 14:57:23'),
(57,'COMPRA_REGISTRADA','Admin','{\"id\":\"21\"}','2026-02-07 16:06:14'),
(58,'COMPRA_REGISTRADA','Admin','{\"id\":\"22\"}','2026-02-08 14:07:39'),
(59,'COMPRA_REGISTRADA','Admin','{\"id\":\"23\"}','2026-02-08 19:33:49'),
(60,'COMPRA_REGISTRADA','Admin','{\"id\":\"24\"}','2026-02-09 00:25:31'),
(61,'PRECIO_UPDATE','Admin','{\"sku\":\"660014\",\"nuevo_precio\":370}','2026-02-10 16:55:36'),
(62,'COMPRA_REGISTRADA','Admin','{\"id\":\"25\"}','2026-02-10 17:21:55'),
(63,'COMPRA_REGISTRADA','Admin','{\"id\":\"26\"}','2026-02-10 17:22:41');
/*!40000 ALTER TABLE `auditoria_pos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_sesiones`
--

DROP TABLE IF EXISTS `caja_sesiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `caja_sesiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) DEFAULT NULL,
  `nombre_cajero` varchar(100) DEFAULT NULL,
  `fecha_apertura` datetime DEFAULT current_timestamp(),
  `fecha_cierre` datetime DEFAULT NULL,
  `monto_inicial` decimal(10,2) DEFAULT 0.00,
  `monto_final_sistema` decimal(10,2) DEFAULT 0.00,
  `monto_final_real` decimal(10,2) DEFAULT 0.00,
  `diferencia` decimal(10,2) DEFAULT 0.00,
  `estado` varchar(20) DEFAULT 'ABIERTA',
  `fecha_contable` date DEFAULT curdate(),
  `id_sucursal` int(11) DEFAULT 1,
  `nota` text DEFAULT 'no',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_sesiones`
--

LOCK TABLES `caja_sesiones` WRITE;
/*!40000 ALTER TABLE `caja_sesiones` DISABLE KEYS */;
INSERT INTO `caja_sesiones` VALUES
(1,NULL,'Eddy','2026-01-29 22:48:21','2026-01-29 23:00:49',0.00,7290.00,7290.00,0.00,'CERRADA','2026-01-23',4,'realmente 2360 en tranf'),
(2,NULL,'Eddy','2026-01-29 23:01:12','2026-01-29 23:12:12',0.00,8745.00,8745.00,0.00,'CERRADA','2026-01-24',4,'real fue 60 en tranfe'),
(3,NULL,'Admin','2026-01-29 23:13:03','2026-01-29 23:26:58',0.00,14235.00,13515.00,-720.00,'CERRADA','2026-01-25',4,'faltan 720 de 3 marranetas'),
(4,NULL,'Admin','2026-01-29 23:27:13','2026-01-29 23:31:59',0.00,5310.00,5310.00,0.00,'CERRADA','2026-01-26',4,'ok'),
(5,NULL,'Eddy','2026-01-29 23:38:30','2026-01-29 23:44:07',0.00,6115.00,6115.00,0.00,'CERRADA','2026-01-27',4,'por traf 1280'),
(6,NULL,'Eddy','2026-01-29 23:48:36','2026-01-29 23:54:16',0.00,6655.00,6655.00,0.00,'CERRADA','2026-01-28',4,'990 por tranf'),
(7,NULL,'Eddy','2026-01-30 08:40:43','2026-01-30 09:21:19',0.00,0.00,0.00,0.00,'CERRADA','2026-01-29',4,'test'),
(8,NULL,'Eddy','2026-01-30 09:22:13','2026-01-30 09:30:40',0.00,0.00,0.00,0.00,'CERRADA','2026-01-11',4,'test'),
(9,NULL,'Eddy','2026-01-30 10:23:27','2026-01-30 10:59:19',0.00,0.00,0.00,0.00,'CERRADA','2026-01-12',4,'prueba roly'),
(10,NULL,'Admin','2026-01-30 13:24:53','2026-01-31 08:23:01',0.00,0.00,0.00,0.00,'CERRADA','2026-01-02',4,'Test'),
(11,NULL,'Eddy','2026-01-31 08:23:10','2026-01-31 09:06:03',0.00,12940.00,12940.00,0.00,'CERRADA','2026-01-29',4,'Ok'),
(12,NULL,'Admin','2026-02-01 20:29:30','2026-02-01 21:42:46',0.00,17705.00,17705.00,0.00,'CERRADA','2026-01-30',4,''),
(13,NULL,'Eddy','2026-02-02 18:59:55','2026-02-02 19:35:39',0.00,15770.00,15770.00,0.00,'CERRADA','2026-01-31',4,'reportado 13130 faltaron 2640'),
(14,NULL,'Eddy','2026-02-04 08:26:03','2026-02-04 08:41:54',0.00,11180.00,11180.00,0.00,'CERRADA','2026-02-01',4,'ok'),
(15,NULL,'Eddy','2026-02-04 08:42:11','2026-02-04 09:40:48',0.00,5440.00,5440.00,0.00,'CERRADA','2026-02-02',4,'0'),
(16,NULL,'Eddy','2026-02-04 10:28:14','2026-02-04 11:21:50',0.00,3980.00,3990.00,10.00,'CERRADA','2026-02-03',4,'puse higado a 720 y es a 730 por eso la diferencia de 10'),
(17,NULL,'Eddy','2026-02-06 10:21:00','2026-02-07 10:01:08',0.00,18330.00,18330.00,0.00,'CERRADA','2026-02-04',4,'aqui se habian puesto en papel marranetas de mas y se rebajo 2880 en efectivo'),
(18,NULL,'Eddy','2026-02-07 10:18:21','2026-02-07 13:48:24',0.00,9080.00,9080.00,0.00,'CERRADA','2026-02-05',4,'-40 con el reaL'),
(19,NULL,'Eddy','2026-02-08 09:01:47','2026-02-08 14:46:37',0.00,15580.00,15590.00,10.00,'CERRADA','2026-02-06',4,'no se de que 10 pesos sobran en cash'),
(20,NULL,'Eddy','2026-02-08 14:47:56','2026-02-10 12:08:09',0.00,21120.00,21060.00,-60.00,'CERRADA','2026-02-07',4,'Le faltan 100 cup al marinero de shekels mal calculadas'),
(21,NULL,'Eddy','2026-02-10 12:09:07',NULL,0.00,0.00,0.00,0.00,'ABIERTA','2026-02-08',4,'no'),
(22,4,'Admin','2026-02-11 09:33:48','2026-02-11 09:34:37',0.00,300.00,300.00,0.00,'CERRADA','2026-02-11',4,'ok'),
(23,1,'Eddy','2026-02-11 14:25:40',NULL,0.00,0.00,0.00,0.00,'ABIERTA','2026-02-11',4,'no');
/*!40000 ALTER TABLE `caja_sesiones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_uuid` varchar(50) NOT NULL,
  `sender` enum('client','admin') NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_uuid`),
  KEY `idx_read` (`is_read`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chat_messages`
--

LOCK TABLES `chat_messages` WRITE;
/*!40000 ALTER TABLE `chat_messages` DISABLE KEYS */;
INSERT INTO `chat_messages` VALUES
(1,'cli_8j69zmenk','client','hola, les queda mas aceite',1,'2026-02-04 14:35:33'),
(2,'cli_8j69zmenk','client','hola',1,'2026-02-04 14:39:52'),
(3,'cli_8j69zmenk','admin','siii',0,'2026-02-04 14:45:27'),
(4,'cli_8j69zmenk','client','cuanto le queda',1,'2026-02-04 14:55:30'),
(5,'cli_8j69zmenk','admin','me quedan 22',0,'2026-02-04 14:55:45'),
(6,'cli_8j69zmenk','client','les quedan cake d ebombon',1,'2026-02-04 15:32:27'),
(7,'cli_8j69zmenk','admin','si de bpmbon tengo 2',0,'2026-02-04 15:36:08'),
(8,'cli_8j69zmenk','client','ok, gracias',1,'2026-02-04 15:36:33'),
(9,'cli_f6qf5h3p5','client','hola tiene cakes de bombon',1,'2026-02-05 11:45:51'),
(10,'cli_f6qf5h3p5','admin','si tengo cuantos quiere',0,'2026-02-05 11:46:10'),
(11,'cli_f6qf5h3p5','client','quiero 1',1,'2026-02-05 11:46:17'),
(12,'cli_f6qf5h3p5','admin','listo ya s elo envie',0,'2026-02-05 11:46:25'),
(13,'cli_8j69zmenk','client','tienen aceite hoy?',1,'2026-02-11 13:42:42'),
(14,'cli_w9xb36o39','client','Hola',1,'2026-02-11 13:43:11'),
(15,'cli_w9xb36o39','client','Tienen cake',1,'2026-02-11 13:43:15'),
(16,'cli_8j69zmenk','admin','Si',0,'2026-02-11 17:48:10'),
(17,'cli_w9xb36o39','admin','Si',0,'2026-02-11 17:48:17');
/*!40000 ALTER TABLE `chat_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clientes`
--

DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `direccion` text DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `uuid` char(36) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `categoria` enum('Regular','VIP','Corporativo','Moroso','Empleado') DEFAULT 'Regular',
  `origen` varchar(50) DEFAULT 'Local',
  `notas` text DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `nit_ci` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `es_mensajero` tinyint(1) DEFAULT 0,
  `vehiculo` varchar(50) DEFAULT NULL,
  `matricula` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clientes`
--

LOCK TABLES `clientes` WRITE;
/*!40000 ALTER TABLE `clientes` DISABLE KEYS */;
INSERT INTO `clientes` VALUES
(1,'sureima chales','7868082963','522 East 12th Street',1,NULL,'2026-02-10 10:27:22','VIP','Facebook','','2026-01-31','71010102929','sycnet.2013@gmail.com',0,NULL,NULL),
(2,'Oscar escobar','56078943','czda del cerro #1024',1,NULL,'2026-02-10 10:42:53','Empleado','Local','','2026-01-26','68020212312','',1,'Triciclo','P84739');
/*!40000 ALTER TABLE `clientes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comandas`
--

DROP TABLE IF EXISTS `comandas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comandas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta` int(11) DEFAULT NULL,
  `items_json` longtext DEFAULT NULL,
  `estado` varchar(20) DEFAULT 'pendiente',
  `tipo_servicio` varchar(30) DEFAULT NULL,
  `cliente_nombre` varchar(200) DEFAULT NULL,
  `id_mesa` int(10) unsigned DEFAULT NULL,
  `curso` int(11) DEFAULT 1,
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  `fecha_inicio` datetime DEFAULT NULL,
  `fecha_fin` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comandas`
--

LOCK TABLES `comandas` WRITE;
/*!40000 ALTER TABLE `comandas` DISABLE KEYS */;
INSERT INTO `comandas` VALUES
(1,26,'[{\"qty\":10,\"name\":\"Marquesita\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-01-31 09:02:12',NULL,NULL),
(2,29,'[{\"qty\":1,\"name\":\"Cake 2900\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-01 20:54:53',NULL,NULL),
(3,31,'[{\"qty\":3,\"name\":\"Marquesita\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-01 21:33:07',NULL,NULL),
(4,32,'[{\"qty\":3,\"name\":\"Cupcakes\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-01 21:35:17',NULL,NULL),
(5,33,'[{\"qty\":1,\"name\":\"Bolsa de 8 panes\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-01 21:38:39',NULL,NULL),
(6,35,'[{\"qty\":3,\"name\":\"Bolsa de 8 panes\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-02 19:12:03',NULL,NULL),
(7,36,'[{\"qty\":4,\"name\":\"Panque de chocolate\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-02 19:12:19',NULL,NULL),
(8,37,'[{\"qty\":2,\"name\":\"Marquesita\",\"note\":\"\"},{\"qty\":11,\"name\":\"Cerveza Presidente\",\"note\":\"\"},{\"qty\":2,\"name\":\"Cupcakes\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-02 19:25:32',NULL,NULL),
(9,39,'[{\"qty\":4,\"name\":\"Cerveza Presidente\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-04 08:26:07',NULL,NULL),
(10,40,'[{\"qty\":1,\"name\":\"Marquesita\",\"note\":\"\"},{\"qty\":2,\"name\":\"Cupcakes\",\"note\":\"\"},{\"qty\":2,\"name\":\"Gacenigas mini\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-04 08:40:54',NULL,NULL),
(11,41,'[{\"qty\":1,\"name\":\"Pqt de 4 panques\",\"note\":\"\"},{\"qty\":1,\"name\":\"pqt torticas 6u\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-04 09:40:34',NULL,NULL),
(12,73,'[{\"qty\":8,\"name\":\"Cerveza Presidente\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-06 16:02:51',NULL,NULL),
(13,89,'[{\"qty\":1,\"name\":\"Pqt de 4 panques\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 09:49:29',NULL,NULL),
(14,91,'[{\"qty\":1,\"name\":\"lomo en bandeja 1lb\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 09:50:14',NULL,NULL),
(15,92,'[{\"qty\":2,\"name\":\"Caldo de pollo sobrecito\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 09:50:38',NULL,NULL),
(16,93,'[{\"qty\":2,\"name\":\"pastillita pollo con tomate\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 09:50:49',NULL,NULL),
(17,94,'[{\"qty\":3,\"name\":\"Galletas Saltitacos\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 09:58:21',NULL,NULL),
(18,95,'[{\"qty\":3,\"name\":\"ojitos gummy gum\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 09:58:59',NULL,NULL),
(19,97,'[{\"qty\":1,\"name\":\"Jabon de carbon\",\"note\":\"\"},{\"qty\":1,\"name\":\"jabon de bano\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 09:59:26',NULL,NULL),
(20,101,'[{\"qty\":1,\"name\":\"Cerveza Presidente\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 10:20:45',NULL,NULL),
(21,106,'[{\"qty\":1,\"name\":\"Caldo de pollo sobrecito\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 10:29:32',NULL,NULL),
(22,107,'[{\"qty\":1,\"name\":\"Galletas Saltitacos\",\"note\":\"\"},{\"qty\":1,\"name\":\"jabon de bano\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 11:11:52',NULL,NULL),
(23,108,'[{\"qty\":8,\"name\":\"Donas Grandes\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-07 11:12:27',NULL,NULL),
(24,109,'[{\"qty\":3,\"name\":\"ojitos gummy gum\",\"note\":\"\"},{\"qty\":1,\"name\":\"lomo en bandeja 1lb\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-08 09:03:42',NULL,NULL),
(25,118,'[{\"qty\":3,\"name\":\"Galletas Saltitacos\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-08 10:13:53',NULL,NULL),
(26,119,'[{\"qty\":1,\"name\":\"Donas Donuts\",\"note\":\"\"},{\"qty\":4,\"name\":\"Donas Grandes\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-08 10:15:45',NULL,NULL),
(27,123,'[{\"qty\":1,\"name\":\"pqt torticas 6u\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-08 20:01:26',NULL,NULL),
(28,131,'[{\"qty\":1,\"name\":\"pqt torticas 6u\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-10 11:47:51',NULL,NULL),
(29,134,'[{\"qty\":1,\"name\":\"pqt torticas 6u\",\"note\":\"\"},{\"qty\":1,\"name\":\"jabon de bano\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-10 12:14:14',NULL,NULL),
(30,135,'[{\"qty\":1,\"name\":\"Hamburguesas pollo\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-10 12:15:38',NULL,NULL),
(31,137,'[{\"qty\":1,\"name\":\"Hamburguesas pollo\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-10 12:25:05',NULL,NULL),
(32,138,'[{\"qty\":2,\"name\":\"COCA COLA GRANDE\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-11 09:34:10',NULL,NULL),
(33,139,'[{\"qty\":1,\"name\":\"COCA COLA GRANDE\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-11 14:26:29',NULL,NULL),
(34,140,'[{\"qty\":1,\"name\":\"COCA COLA GRANDE\",\"note\":\"\"}]','pendiente',NULL,NULL,NULL,1,'2026-02-11 17:57:10',NULL,NULL);
/*!40000 ALTER TABLE `comandas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `compras_cabecera`
--

DROP TABLE IF EXISTS `compras_cabecera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `compras_cabecera` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT current_timestamp(),
  `proveedor` varchar(100) DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `usuario` varchar(50) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `estado` varchar(20) DEFAULT 'finalizado',
  `numero_factura` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `compras_cabecera`
--

LOCK TABLES `compras_cabecera` WRITE;
/*!40000 ALTER TABLE `compras_cabecera` DISABLE KEYS */;
INSERT INTO `compras_cabecera` VALUES
(1,'2026-01-29 22:53:23','adrian',8160.00,'Admin',' [Suc:4 Alm:4]','finalizado',NULL),
(2,'2026-01-29 22:58:19','',292.71,'Admin',' [Suc:4 Alm:4]','finalizado',NULL),
(3,'2026-01-29 23:30:04','roly ce',1594.00,'Admin','dia 26/ene [Suc:4 Alm:4]','finalizado',NULL),
(4,'2026-01-29 23:34:58','adrian',22517.00,'Admin','dia 27 [Suc:4 Alm:4]','CANCELADA','f43322'),
(5,'2026-01-29 23:48:01','roly ce',6140.00,'Admin','dia 28 [Suc:4 Alm:4]','finalizado',NULL),
(6,'2026-01-02 11:36:03','pepe',240.00,'Admin','no [Suc:4 Alm:4]','CANCELADA','f64435'),
(7,'2026-01-31 10:08:03','',240.00,'Admin',' [Suc:4 Alm:4]','CANCELADA',''),
(8,'2026-02-01 08:39:18','',230000.00,'Admin',' [Suc:4 Alm:4]','CANCELADA',''),
(9,'2026-02-02 20:38:55','',3568.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(10,'2026-01-30 21:37:20','por error',6050.00,'Admin','rectificando faltantes [Suc:4 Alm:4]','PROCESADA','f000'),
(11,'2026-02-02 18:41:43','',7494.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(12,'2026-02-02 18:44:49','roly',480.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(13,'2026-02-03 19:09:47','',13906.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(14,'2026-01-31 19:20:45','',640.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(15,'2026-02-04 09:55:48','',405.00,'Admin','por error [Suc:4 Alm:4]','PROCESADA',''),
(16,'2026-02-04 10:24:06','',50.00,'Admin','error [Suc:4 Alm:4]','PROCESADA',''),
(17,'2026-02-03 10:51:07','roly',10521.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(18,'2026-02-03 10:59:41','roly',4410.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(19,'2026-02-03 11:04:23','roly',1200.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(20,'2026-02-04 09:48:07','adrian',6384.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(21,'2026-02-05 11:06:14','roly',2010.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(22,'2026-02-06 09:07:39','roly',16248.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(23,'2026-02-06 14:33:49','',45.00,'Admin','por error [Suc:4 Alm:4]','PROCESADA',''),
(24,'2026-02-07 19:25:31','roly',1950.13,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(25,'2026-02-08 12:21:55','',10320.00,'Admin',' [Suc:4 Alm:4]','PROCESADA',''),
(26,'2026-02-08 12:22:41','',820.00,'Admin',' [Suc:4 Alm:4]','PROCESADA','');
/*!40000 ALTER TABLE `compras_cabecera` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `compras_detalle`
--

DROP TABLE IF EXISTS `compras_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `compras_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_compra` int(11) DEFAULT NULL,
  `id_producto` varchar(50) DEFAULT NULL,
  `cantidad` decimal(10,2) DEFAULT NULL,
  `costo_unitario` decimal(10,2) DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT NULL,
  `costo_anterior` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `compras_detalle`
--

LOCK TABLES `compras_detalle` WRITE;
/*!40000 ALTER TABLE `compras_detalle` DISABLE KEYS */;
INSERT INTO `compras_detalle` VALUES
(1,1,'660011',24.00,210.00,5040.00,NULL),
(2,1,'660005',24.00,130.00,3120.00,NULL),
(3,2,'881108',1.00,292.71,292.71,NULL),
(4,3,'660017',2.00,72.00,144.00,NULL),
(5,3,'660012',29.00,50.00,1450.00,NULL),
(6,4,'660026',2.00,430.00,860.00,NULL),
(7,4,'660012',22.00,50.00,1100.00,NULL),
(8,4,'660023',24.00,213.00,5112.00,NULL),
(9,4,'660037',24.00,210.00,5040.00,NULL),
(10,4,'660032',24.00,190.00,4560.00,NULL),
(11,4,'660036',27.00,135.00,3645.00,NULL),
(12,4,'660033',32.00,20.00,640.00,NULL),
(13,4,'660035',32.00,15.00,480.00,NULL),
(14,4,'660034',180.00,6.00,1080.00,NULL),
(15,5,'660012',26.00,50.00,1300.00,NULL),
(16,5,'660099',16.00,65.00,1040.00,NULL),
(17,5,'660015',9.00,300.00,2700.00,NULL),
(18,5,'660014',5.00,220.00,1100.00,NULL),
(19,6,'756058841446',2.00,120.00,240.00,NULL),
(20,7,'756058841446',2.00,120.00,240.00,NULL),
(21,8,'756058841480',2300.00,100.00,230000.00,NULL),
(22,9,'660010',4.00,130.00,520.00,NULL),
(23,9,'660009',6.00,120.00,720.00,NULL),
(24,9,'660017',9.00,72.00,648.00,NULL),
(25,9,'756058841478',7.00,40.00,280.00,NULL),
(26,9,'660098',1.00,0.00,0.00,NULL),
(27,9,'660043',2.00,700.00,1400.00,NULL),
(28,10,'660037',24.00,210.00,5040.00,NULL),
(29,10,'660097',4.00,160.00,640.00,NULL),
(30,10,'660004',1.00,370.00,370.00,NULL),
(31,11,'660043',1.00,700.00,700.00,NULL),
(32,11,'660012',20.00,50.00,1000.00,NULL),
(33,11,'660017',6.00,72.00,432.00,NULL),
(34,11,'660023',24.00,213.00,5112.00,NULL),
(35,11,'660002',10.00,25.00,250.00,NULL),
(36,12,'660096',2.00,120.00,240.00,NULL),
(37,12,'660031',3.00,80.00,240.00,NULL),
(38,13,'660094',2.00,200.00,400.00,NULL),
(39,13,'756058841478',4.00,40.00,160.00,NULL),
(40,13,'660031',2.00,80.00,160.00,NULL),
(41,13,'660012',12.00,50.00,600.00,NULL),
(42,13,'660095',4.00,70.00,280.00,NULL),
(43,13,'660093',2.00,180.00,360.00,NULL),
(44,13,'660026',3.00,430.00,1290.00,NULL),
(45,13,'660017',8.00,72.00,576.00,NULL),
(46,13,'660037',24.00,210.00,5040.00,NULL),
(47,13,'990091',24.00,210.00,5040.00,NULL),
(48,14,'660033',32.00,20.00,640.00,NULL),
(49,15,'660036',3.00,135.00,405.00,NULL),
(50,16,'660012',1.00,50.00,50.00,NULL),
(51,17,'660012',28.00,50.00,1400.00,NULL),
(52,17,'660090',4.00,1150.00,4600.00,NULL),
(53,17,'660016',3.00,620.00,1860.00,NULL),
(54,17,'660089',24.00,20.00,480.00,NULL),
(55,17,'660088',10.00,18.00,180.00,NULL),
(56,17,'660087',7.00,143.00,1001.00,NULL),
(57,17,'660087',25.00,40.00,1000.00,NULL),
(58,18,'660100',25.00,40.00,1000.00,NULL),
(59,18,'660007',70.00,20.00,1400.00,NULL),
(60,18,'660101',8.00,25.00,200.00,NULL),
(61,18,'660102',3.00,170.00,510.00,NULL),
(62,18,'660103',10.00,130.00,1300.00,NULL),
(63,19,'660104',10.00,120.00,1200.00,NULL),
(64,20,'660027',24.00,160.00,3840.00,NULL),
(65,20,'660006',12.00,212.00,2544.00,NULL),
(66,21,'440001',8.00,90.00,720.00,NULL),
(67,21,'660026',3.00,430.00,1290.00,NULL),
(68,22,'660013',16.00,35.00,560.00,NULL),
(69,22,'660020',5.00,570.00,2850.00,NULL),
(70,22,'660018',2.00,580.00,1160.00,NULL),
(71,22,'660096',4.00,120.00,480.00,NULL),
(72,22,'660003',8.00,360.00,2880.00,NULL),
(73,22,'660037',24.00,210.00,5040.00,NULL),
(74,22,'660012',17.00,50.00,850.00,NULL),
(75,22,'660017',11.00,88.00,968.00,NULL),
(76,22,'660014',5.00,220.00,1100.00,NULL),
(77,22,'440001',4.00,90.00,360.00,NULL),
(78,23,'660035',3.00,15.00,45.00,NULL),
(79,24,'660017',4.00,88.00,352.00,NULL),
(80,24,'660030',4.00,180.00,720.00,NULL),
(81,24,'881108',3.00,292.71,878.13,NULL),
(82,25,'660023',24.00,213.00,5112.00,NULL),
(83,25,'660021',24.00,217.00,5208.00,NULL),
(84,26,'660010',4.00,130.00,520.00,NULL),
(85,26,'660015',1.00,300.00,300.00,NULL);
/*!40000 ALTER TABLE `compras_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `config_facturacion`
--

DROP TABLE IF EXISTS `config_facturacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `config_facturacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ultimo_consecutivo` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `config_facturacion`
--

LOCK TABLES `config_facturacion` WRITE;
/*!40000 ALTER TABLE `config_facturacion` DISABLE KEYS */;
/*!40000 ALTER TABLE `config_facturacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contabilidad_config`
--

DROP TABLE IF EXISTS `contabilidad_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contabilidad_config` (
  `clave` varchar(50) NOT NULL,
  `valor` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contabilidad_config`
--

LOCK TABLES `contabilidad_config` WRITE;
/*!40000 ALTER TABLE `contabilidad_config` DISABLE KEYS */;
INSERT INTO `contabilidad_config` VALUES
('fecha_cierre','2025-12-31');
/*!40000 ALTER TABLE `contabilidad_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contabilidad_cuentas`
--

DROP TABLE IF EXISTS `contabilidad_cuentas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contabilidad_cuentas` (
  `codigo` varchar(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `nivel` int(11) DEFAULT 1,
  `padre_codigo` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contabilidad_cuentas`
--

LOCK TABLES `contabilidad_cuentas` WRITE;
/*!40000 ALTER TABLE `contabilidad_cuentas` DISABLE KEYS */;
INSERT INTO `contabilidad_cuentas` VALUES
('101','Efectivo en Caja','ACTIVO',1,NULL),
('101.001','Caja Magnolia','ACTIVO',2,'101'),
('101.002','Caja Marinero','ACTIVO',2,'101'),
('102','Efectivo en Banco','ACTIVO',1,NULL),
('102.001','Mayorista CUP Metro','ACTIVO',2,'102'),
('102.002','Corriente CUP Metro','ACTIVO',2,'102'),
('102.003','CUC Metro','ACTIVO',2,'102'),
('108','Cuentas por Cobrar (Clientes)','ACTIVO',1,NULL),
('120','Inventario de Mercancías','ACTIVO',1,NULL),
('121','Materias Primas y Materiales','ACTIVO',1,NULL),
('140','Activos Fijos Tangibles (Equipos)','ACTIVO',1,NULL),
('201','Cuentas por Pagar (Proveedores)','PASIVO',1,NULL),
('210','Salarios y Sueldos por Pagar','PASIVO',1,NULL),
('240','Obligaciones con el Fisco (Impuestos)','PASIVO',1,NULL),
('240.1','Impuesto s/Ventas (10%)','PASIVO',2,'240'),
('240.2','Contribución Local (1%)','PASIVO',2,'240'),
('240.3','Seguridad Social (14%)','PASIVO',2,'240'),
('240.4','Impuesto s/Utilidades','PASIVO',2,'240'),
('240.5','Seguridad Social por Pagar','PASIVO',2,'240'),
('401','Ingresos por Ventas (Bienes)','INGRESO',1,NULL),
('402','Ingresos por Servicios','INGRESO',1,NULL),
('409','Otros Ingresos','INGRESO',1,NULL),
('501','Costo de Ventas (Mercancía)','COSTO',1,NULL),
('502','Costo de Producción','COSTO',1,NULL),
('600','Patrimonio Neto','PATRIMONIO',1,NULL),
('601','Capital Contable','PATRIMONIO',1,NULL),
('630','Utilidades Retenidas','PATRIMONIO',1,NULL),
('640','Reserva para Contingencias','PATRIMONIO',2,'600'),
('701','Gastos de Personal (Salarios)','GASTO',1,NULL),
('702','Gastos de SERVICIOS','GASTO',1,NULL),
('703','Mantenimiento y Reparaciones','GASTO',1,NULL),
('704','Gastos de ONAT','GASTO',1,NULL),
('704.1','Gasto Impuesto Ventas','GASTO',2,'704'),
('704.2','Gasto Fuerza Trabajo','GASTO',2,'704'),
('704.3','Gasto Contribución Local','GASTO',2,'704'),
('704.4','Gasto Seguridad Social','GASTO',2,'704'),
('704.5','Gasto Impuesto Utilidades','GASTO',2,'704'),
('709','Gastos Personales (Retiro Socio)','GASTO',1,NULL);
/*!40000 ALTER TABLE `contabilidad_cuentas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contabilidad_diario`
--

DROP TABLE IF EXISTS `contabilidad_diario`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contabilidad_diario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asiento_id` varchar(30) NOT NULL DEFAULT '0',
  `fecha` date DEFAULT NULL,
  `asiento_tipo` varchar(50) DEFAULT NULL,
  `referencia_id` int(11) DEFAULT NULL,
  `cuenta` varchar(100) DEFAULT NULL,
  `debe` decimal(12,2) DEFAULT 0.00,
  `haber` decimal(12,2) DEFAULT 0.00,
  `creado_por` varchar(50) DEFAULT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fecha` (`fecha`),
  KEY `cuenta` (`cuenta`),
  KEY `asiento_id` (`asiento_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3790 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contabilidad_diario`
--

LOCK TABLES `contabilidad_diario` WRITE;
/*!40000 ALTER TABLE `contabilidad_diario` DISABLE KEYS */;
INSERT INTO `contabilidad_diario` VALUES
(399,'OLD-399','2026-02-26','GASTO',9,'702 - Gastos de Energía y Agua',3000.00,0.00,NULL,'pago de luz'),
(400,'OLD-400','2026-02-26','GASTO',9,'101 - Efectivo',0.00,3000.00,NULL,'Pago: SERVICIOS'),
(401,'OLD-401','2026-02-28','GASTO',10,'702 - Gastos de Energía y Agua',5600.00,0.00,NULL,'ETECSA MOVILES'),
(402,'OLD-402','2026-02-28','GASTO',10,'101 - Efectivo',0.00,5600.00,NULL,'Pago: SERVICIOS'),
(403,'OLD-403','2026-02-10','GASTO',11,'702 - Gastos de Energía y Agua',7800.00,0.00,NULL,'Impuestos ONAT'),
(404,'OLD-404','2026-02-10','GASTO',11,'101 - Efectivo',0.00,7800.00,NULL,'Pago: SERVICIOS'),
(405,'OLD-405','2026-02-15','GASTO',12,'702 - Gastos de Energía y Agua',8000.00,0.00,NULL,'Impuesto 10%'),
(406,'OLD-406','2026-02-15','GASTO',12,'101 - Efectivo',0.00,8000.00,NULL,'Pago: SERVICIOS'),
(1407,'OLD-1407','2026-01-01','AJUSTE',0,'120 - Inventario Mercancías',107385.00,0.00,NULL,'Ajuste Inventario Inicial/Físico'),
(1408,'OLD-1408','2026-01-01','AJUSTE',0,'601 - Capital Social',0.00,107385.00,NULL,'Regularización de Stock'),
(2793,'OLD-2793','2026-01-31','AJUSTE',0,'120 - Inventario Mercancías',179801.35,0.00,NULL,'Ajuste Inv. (global)'),
(2794,'OLD-2794','2026-01-31','AJUSTE',0,'601 - Capital Social',0.00,179801.35,NULL,'Regularización Stock'),
(3115,'OLD-3115','2026-01-28','AJUSTE',0,'601 - Capital Social',179801.35,0.00,NULL,'Ajuste Merma (local)'),
(3116,'OLD-3116','2026-01-28','AJUSTE',0,'120 - Inventario Mercancías',0.00,179801.35,NULL,'Corrección Inv.'),
(3277,'OLD-3277','2026-01-28','AJUSTE',0,'120 - Inventario Mercancías',179801.35,0.00,NULL,'Ajuste Inv. (global)'),
(3278,'OLD-3278','2026-01-28','AJUSTE',0,'601 - Capital Social',0.00,179801.35,NULL,'Regularización Stock'),
(3613,'OLD-3613','2026-01-23','VENTA',1,'102 - Banco',2280.00,0.00,NULL,'Venta #1'),
(3614,'OLD-3614','2026-01-23','VENTA',1,'401 - Ingresos',0.00,2280.00,NULL,'Venta #1'),
(3615,'OLD-3615','2026-01-23','COSTO',1,'501 - Costo',1409.00,0.00,NULL,'Costo Venta'),
(3616,'OLD-3616','2026-01-23','COSTO',1,'120 - Inventario',0.00,1409.00,NULL,'Baja Stock'),
(3617,'OLD-3617','2026-01-23','VENTA',2,'101.004 - Cuenta General',5010.00,0.00,NULL,'Venta #2'),
(3618,'OLD-3618','2026-01-23','VENTA',2,'401 - Ingresos',0.00,5010.00,NULL,'Venta #2'),
(3619,'OLD-3619','2026-01-23','COSTO',2,'501 - Costo',4304.00,0.00,NULL,'Costo Venta'),
(3620,'OLD-3620','2026-01-23','COSTO',2,'120 - Inventario',0.00,4304.00,NULL,'Baja Stock'),
(3621,'OLD-3621','2026-01-24','VENTA',3,'101.004 - Cuenta General',3160.00,0.00,NULL,'Venta #3'),
(3622,'OLD-3622','2026-01-24','VENTA',3,'401 - Ingresos',0.00,3160.00,NULL,'Venta #3'),
(3623,'OLD-3623','2026-01-24','COSTO',3,'501 - Costo',2440.00,0.00,NULL,'Costo Venta'),
(3624,'OLD-3624','2026-01-24','COSTO',3,'120 - Inventario',0.00,2440.00,NULL,'Baja Stock'),
(3625,'OLD-3625','2026-01-24','VENTA',4,'102 - Banco',70.00,0.00,NULL,'Venta #4'),
(3626,'OLD-3626','2026-01-24','VENTA',4,'401 - Ingresos',0.00,70.00,NULL,'Venta #4'),
(3627,'OLD-3627','2026-01-24','COSTO',4,'501 - Costo',35.00,0.00,NULL,'Costo Venta'),
(3628,'OLD-3628','2026-01-24','COSTO',4,'120 - Inventario',0.00,35.00,NULL,'Baja Stock'),
(3629,'OLD-3629','2026-01-24','VENTA',5,'101.004 - Cuenta General',4475.00,0.00,NULL,'Venta #5'),
(3630,'OLD-3630','2026-01-24','VENTA',5,'401 - Ingresos',0.00,4475.00,NULL,'Venta #5'),
(3631,'OLD-3631','2026-01-24','COSTO',5,'501 - Costo',3713.00,0.00,NULL,'Costo Venta'),
(3632,'OLD-3632','2026-01-24','COSTO',5,'120 - Inventario',0.00,3713.00,NULL,'Baja Stock'),
(3633,'OLD-3633','2026-01-29','VENTA',6,'101.004 - Cuenta General',-600.00,0.00,NULL,'Venta #6'),
(3634,'OLD-3634','2026-01-29','VENTA',6,'401 - Ingresos',0.00,-600.00,NULL,'Venta #6'),
(3635,'OLD-3635','2026-01-29','COSTO',6,'501 - Costo',-300.00,0.00,NULL,'Costo Venta'),
(3636,'OLD-3636','2026-01-29','COSTO',6,'120 - Inventario',0.00,-300.00,NULL,'Baja Stock'),
(3637,'OLD-3637','2026-01-24','VENTA',7,'101.004 - Cuenta General',840.00,0.00,NULL,'Venta #7'),
(3638,'OLD-3638','2026-01-24','VENTA',7,'401 - Ingresos',0.00,840.00,NULL,'Venta #7'),
(3639,'OLD-3639','2026-01-24','COSTO',7,'501 - Costo',540.00,0.00,NULL,'Costo Venta'),
(3640,'OLD-3640','2026-01-24','COSTO',7,'120 - Inventario',0.00,540.00,NULL,'Baja Stock'),
(3641,'OLD-3641','2026-01-24','VENTA',8,'101.004 - Cuenta General',800.00,0.00,NULL,'Venta #8'),
(3642,'OLD-3642','2026-01-24','VENTA',8,'401 - Ingresos',0.00,800.00,NULL,'Venta #8'),
(3643,'OLD-3643','2026-01-24','COSTO',8,'501 - Costo',400.00,0.00,NULL,'Costo Venta'),
(3644,'OLD-3644','2026-01-24','COSTO',8,'120 - Inventario',0.00,400.00,NULL,'Baja Stock'),
(3645,'OLD-3645','2026-01-25','VENTA',9,'102 - Banco',4090.00,0.00,NULL,'Venta #9'),
(3646,'OLD-3646','2026-01-25','VENTA',9,'401 - Ingresos',0.00,4090.00,NULL,'Venta #9'),
(3647,'OLD-3647','2026-01-25','COSTO',9,'501 - Costo',3364.00,0.00,NULL,'Costo Venta'),
(3648,'OLD-3648','2026-01-25','COSTO',9,'120 - Inventario',0.00,3364.00,NULL,'Baja Stock'),
(3649,'OLD-3649','2026-01-25','VENTA',10,'101.004 - Cuenta General',10225.00,0.00,NULL,'Venta #10'),
(3650,'OLD-3650','2026-01-25','VENTA',10,'401 - Ingresos',0.00,10225.00,NULL,'Venta #10'),
(3651,'OLD-3651','2026-01-25','COSTO',10,'501 - Costo',7997.00,0.00,NULL,'Costo Venta'),
(3652,'OLD-3652','2026-01-25','COSTO',10,'120 - Inventario',0.00,7997.00,NULL,'Baja Stock'),
(3653,'OLD-3653','2026-01-29','VENTA',11,'101.004 - Cuenta General',-80.00,0.00,NULL,'Venta #11'),
(3654,'OLD-3654','2026-01-29','VENTA',11,'401 - Ingresos',0.00,-80.00,NULL,'Venta #11'),
(3655,'OLD-3655','2026-01-29','COSTO',11,'501 - Costo',-35.00,0.00,NULL,'Costo Venta'),
(3656,'OLD-3656','2026-01-29','COSTO',11,'120 - Inventario',0.00,-35.00,NULL,'Baja Stock'),
(3657,'OLD-3657','2026-01-26','VENTA',12,'101.004 - Cuenta General',4210.00,0.00,NULL,'Venta #12'),
(3658,'OLD-3658','2026-01-26','VENTA',12,'401 - Ingresos',0.00,4210.00,NULL,'Venta #12'),
(3659,'OLD-3659','2026-01-26','COSTO',12,'501 - Costo',2960.00,0.00,NULL,'Costo Venta'),
(3660,'OLD-3660','2026-01-26','COSTO',12,'120 - Inventario',0.00,2960.00,NULL,'Baja Stock'),
(3661,'OLD-3661','2026-01-26','VENTA',13,'101.004 - Cuenta General',1100.00,0.00,NULL,'Venta #13'),
(3662,'OLD-3662','2026-01-26','VENTA',13,'401 - Ingresos',0.00,1100.00,NULL,'Venta #13'),
(3663,'OLD-3663','2026-01-26','COSTO',13,'501 - Costo',550.00,0.00,NULL,'Costo Venta'),
(3664,'OLD-3664','2026-01-26','COSTO',13,'120 - Inventario',0.00,550.00,NULL,'Baja Stock'),
(3665,'OLD-3665','2026-01-27','VENTA',14,'102 - Banco',1260.00,0.00,NULL,'Venta #14'),
(3666,'OLD-3666','2026-01-27','VENTA',14,'401 - Ingresos',0.00,1260.00,NULL,'Venta #14'),
(3667,'OLD-3667','2026-01-27','COSTO',14,'501 - Costo',1062.00,0.00,NULL,'Costo Venta'),
(3668,'OLD-3668','2026-01-27','COSTO',14,'120 - Inventario',0.00,1062.00,NULL,'Baja Stock'),
(3669,'OLD-3669','2026-01-27','VENTA',15,'101.004 - Cuenta General',4855.00,0.00,NULL,'Venta #15'),
(3670,'OLD-3670','2026-01-27','VENTA',15,'401 - Ingresos',0.00,4855.00,NULL,'Venta #15'),
(3671,'OLD-3671','2026-01-27','COSTO',15,'501 - Costo',3257.00,0.00,NULL,'Costo Venta'),
(3672,'OLD-3672','2026-01-27','COSTO',15,'120 - Inventario',0.00,3257.00,NULL,'Baja Stock'),
(3673,'OLD-3673','2026-01-28','VENTA',16,'102 - Banco',1000.00,0.00,NULL,'Venta #16'),
(3674,'OLD-3674','2026-01-28','VENTA',16,'401 - Ingresos',0.00,1000.00,NULL,'Venta #16'),
(3675,'OLD-3675','2026-01-28','COSTO',16,'501 - Costo',500.00,0.00,NULL,'Costo Venta'),
(3676,'OLD-3676','2026-01-28','COSTO',16,'120 - Inventario',0.00,500.00,NULL,'Baja Stock'),
(3677,'OLD-3677','2026-01-28','VENTA',17,'101.004 - Cuenta General',2020.00,0.00,NULL,'Venta #17'),
(3678,'OLD-3678','2026-01-28','VENTA',17,'401 - Ingresos',0.00,2020.00,NULL,'Venta #17'),
(3679,'OLD-3679','2026-01-28','COSTO',17,'501 - Costo',1561.00,0.00,NULL,'Costo Venta'),
(3680,'OLD-3680','2026-01-28','COSTO',17,'120 - Inventario',0.00,1561.00,NULL,'Baja Stock'),
(3681,'OLD-3681','2026-01-28','VENTA',18,'101.004 - Cuenta General',3235.00,0.00,NULL,'Venta #18'),
(3682,'OLD-3682','2026-01-28','VENTA',18,'401 - Ingresos',0.00,3235.00,NULL,'Venta #18'),
(3683,'OLD-3683','2026-01-28','COSTO',18,'501 - Costo',2776.00,0.00,NULL,'Costo Venta'),
(3684,'OLD-3684','2026-01-28','COSTO',18,'120 - Inventario',0.00,2776.00,NULL,'Baja Stock'),
(3685,'OLD-3685','2026-01-28','VENTA',19,'101.004 - Cuenta General',400.00,0.00,NULL,'Venta #19'),
(3686,'OLD-3686','2026-01-28','VENTA',19,'401 - Ingresos',0.00,400.00,NULL,'Venta #19'),
(3687,'OLD-3687','2026-01-28','COSTO',19,'501 - Costo',294.00,0.00,NULL,'Costo Venta'),
(3688,'OLD-3688','2026-01-28','COSTO',19,'120 - Inventario',0.00,294.00,NULL,'Baja Stock'),
(3689,'OLD-3689','2026-01-12','VENTA',20,'101.004 - Cuenta General',360.00,0.00,NULL,'Venta #20'),
(3690,'OLD-3690','2026-01-12','VENTA',20,'401 - Ingresos',0.00,360.00,NULL,'Venta #20'),
(3691,'OLD-3691','2026-01-12','COSTO',20,'501 - Costo',220.00,0.00,NULL,'Costo Venta'),
(3692,'OLD-3692','2026-01-12','COSTO',20,'120 - Inventario',0.00,220.00,NULL,'Baja Stock'),
(3693,'OLD-3693','2026-01-12','VENTA',21,'102 - Banco',650.00,0.00,NULL,'Venta #21'),
(3694,'OLD-3694','2026-01-12','VENTA',21,'401 - Ingresos',0.00,650.00,NULL,'Venta #21'),
(3695,'OLD-3695','2026-01-12','COSTO',21,'501 - Costo',560.00,0.00,NULL,'Costo Venta'),
(3696,'OLD-3696','2026-01-12','COSTO',21,'120 - Inventario',0.00,560.00,NULL,'Baja Stock'),
(3697,'OLD-3697','2026-01-30','VENTA',22,'101.004 - Cuenta General',-360.00,0.00,NULL,'Venta #22'),
(3698,'OLD-3698','2026-01-30','VENTA',22,'401 - Ingresos',0.00,-360.00,NULL,'Venta #22'),
(3699,'OLD-3699','2026-01-30','COSTO',22,'501 - Costo',-220.00,0.00,NULL,'Costo Venta'),
(3700,'OLD-3700','2026-01-30','COSTO',22,'120 - Inventario',0.00,-220.00,NULL,'Baja Stock'),
(3701,'OLD-3701','2026-01-30','VENTA',23,'101.004 - Cuenta General',-650.00,0.00,NULL,'Venta #23'),
(3702,'OLD-3702','2026-01-30','VENTA',23,'401 - Ingresos',0.00,-650.00,NULL,'Venta #23'),
(3703,'OLD-3703','2026-01-30','COSTO',23,'501 - Costo',-560.00,0.00,NULL,'Costo Venta'),
(3704,'OLD-3704','2026-01-30','COSTO',23,'120 - Inventario',0.00,-560.00,NULL,'Baja Stock'),
(3705,'OLD-3705','2026-01-29','VENTA',24,'102 - Banco',2030.00,0.00,NULL,'Venta #24'),
(3706,'OLD-3706','2026-01-29','VENTA',24,'401 - Ingresos',0.00,2030.00,NULL,'Venta #24'),
(3707,'OLD-3707','2026-01-29','COSTO',24,'501 - Costo',1535.00,0.00,NULL,'Costo Venta'),
(3708,'OLD-3708','2026-01-29','COSTO',24,'120 - Inventario',0.00,1535.00,NULL,'Baja Stock'),
(3709,'OLD-3709','2026-01-29','VENTA',25,'101.004 - Cuenta General',3850.00,0.00,NULL,'Venta #25'),
(3710,'OLD-3710','2026-01-29','VENTA',25,'401 - Ingresos',0.00,3850.00,NULL,'Venta #25'),
(3711,'OLD-3711','2026-01-29','COSTO',25,'501 - Costo',3076.00,0.00,NULL,'Costo Venta'),
(3712,'OLD-3712','2026-01-29','COSTO',25,'120 - Inventario',0.00,3076.00,NULL,'Baja Stock'),
(3713,'OLD-3713','2026-01-29','VENTA',26,'101.004 - Cuenta General',2980.00,0.00,NULL,'Venta #26'),
(3714,'OLD-3714','2026-01-29','VENTA',26,'401 - Ingresos',0.00,2980.00,NULL,'Venta #26'),
(3715,'OLD-3715','2026-01-29','COSTO',26,'501 - Costo',1686.00,0.00,NULL,'Costo Venta'),
(3716,'OLD-3716','2026-01-29','COSTO',26,'120 - Inventario',0.00,1686.00,NULL,'Baja Stock'),
(3717,'OLD-3717','2026-01-29','VENTA',27,'101.004 - Cuenta General',4080.00,0.00,NULL,'Venta #27'),
(3718,'OLD-3718','2026-01-29','VENTA',27,'401 - Ingresos',0.00,4080.00,NULL,'Venta #27'),
(3719,'OLD-3719','2026-01-29','COSTO',27,'501 - Costo',3597.00,0.00,NULL,'Costo Venta'),
(3720,'OLD-3720','2026-01-29','COSTO',27,'120 - Inventario',0.00,3597.00,NULL,'Baja Stock'),
(3721,'OLD-3721','2026-01-30','VENTA',28,'102 - Banco',1830.00,0.00,NULL,'Venta #28'),
(3722,'OLD-3722','2026-01-30','VENTA',28,'401 - Ingresos',0.00,1830.00,NULL,'Venta #28'),
(3723,'OLD-3723','2026-01-30','COSTO',28,'501 - Costo',1385.00,0.00,NULL,'Costo Venta'),
(3724,'OLD-3724','2026-01-30','COSTO',28,'120 - Inventario',0.00,1385.00,NULL,'Baja Stock'),
(3725,'OLD-3725','2026-01-30','VENTA',29,'102 - Banco',4400.00,0.00,NULL,'Venta #29'),
(3726,'OLD-3726','2026-01-30','VENTA',29,'401 - Ingresos',0.00,4400.00,NULL,'Venta #29'),
(3727,'OLD-3727','2026-01-30','COSTO',29,'501 - Costo',2700.00,0.00,NULL,'Costo Venta'),
(3728,'OLD-3728','2026-01-30','COSTO',29,'120 - Inventario',0.00,2700.00,NULL,'Baja Stock'),
(3729,'OLD-3729','2026-01-30','VENTA',30,'102 - Banco',240.00,0.00,NULL,'Venta #30'),
(3730,'OLD-3730','2026-01-30','VENTA',30,'401 - Ingresos',0.00,240.00,NULL,'Venta #30'),
(3731,'OLD-3731','2026-01-30','COSTO',30,'501 - Costo',166.00,0.00,NULL,'Costo Venta'),
(3732,'OLD-3732','2026-01-30','COSTO',30,'120 - Inventario',0.00,166.00,NULL,'Baja Stock'),
(3733,'OLD-3733','2026-01-30','VENTA',31,'101.004 - Cuenta General',3550.00,0.00,NULL,'Venta #31'),
(3734,'OLD-3734','2026-01-30','VENTA',31,'401 - Ingresos',0.00,3550.00,NULL,'Venta #31'),
(3735,'OLD-3735','2026-01-30','COSTO',31,'501 - Costo',2487.00,0.00,NULL,'Costo Venta'),
(3736,'OLD-3736','2026-01-30','COSTO',31,'120 - Inventario',0.00,2487.00,NULL,'Baja Stock'),
(3737,'OLD-3737','2026-01-30','VENTA',32,'101.004 - Cuenta General',3230.00,0.00,NULL,'Venta #32'),
(3738,'OLD-3738','2026-01-30','VENTA',32,'401 - Ingresos',0.00,3230.00,NULL,'Venta #32'),
(3739,'OLD-3739','2026-01-30','COSTO',32,'501 - Costo',2179.00,0.00,NULL,'Costo Venta'),
(3740,'OLD-3740','2026-01-30','COSTO',32,'120 - Inventario',0.00,2179.00,NULL,'Baja Stock'),
(3741,'OLD-3741','2026-01-30','VENTA',33,'101.004 - Cuenta General',4440.00,0.00,NULL,'Venta #33'),
(3742,'OLD-3742','2026-01-30','VENTA',33,'401 - Ingresos',0.00,4440.00,NULL,'Venta #33'),
(3743,'OLD-3743','2026-01-30','COSTO',33,'501 - Costo',3890.00,0.00,NULL,'Costo Venta'),
(3744,'OLD-3744','2026-01-30','COSTO',33,'120 - Inventario',0.00,3890.00,NULL,'Baja Stock'),
(3745,'OLD-3745','2026-01-30','VENTA',34,'101.004 - Cuenta General',15.00,0.00,NULL,'Venta #34'),
(3746,'OLD-3746','2026-01-30','VENTA',34,'401 - Ingresos',0.00,15.00,NULL,'Venta #34'),
(3747,'OLD-3747','2026-01-30','COSTO',34,'501 - Costo',8.00,0.00,NULL,'Costo Venta'),
(3748,'OLD-3748','2026-01-30','COSTO',34,'120 - Inventario',0.00,8.00,NULL,'Baja Stock'),
(3749,'OLD-3749','2026-01-26','GASTO',1,'702 - Gastos de SERVICIOS',3000.00,0.00,NULL,'pago de luz'),
(3750,'OLD-3750','2026-01-26','GASTO',1,'101 - Efectivo',0.00,3000.00,NULL,'Pago: SERVICIOS'),
(3751,'OLD-3751','2026-01-29','GASTO',2,'702 - Gastos de SERVICIOS',5600.00,0.00,NULL,'ETECSA MOVILES'),
(3752,'OLD-3752','2026-01-29','GASTO',2,'101 - Efectivo',0.00,5600.00,NULL,'Pago: SERVICIOS'),
(3753,'OLD-3753','2026-01-10','GASTO',3,'702 - Gastos de SERVICIOS',7800.00,0.00,NULL,'Impuestos ONAT'),
(3754,'OLD-3754','2026-01-10','GASTO',3,'101 - Efectivo',0.00,7800.00,NULL,'Pago: SERVICIOS'),
(3755,'OLD-3755','2026-01-15','GASTO',4,'702 - Gastos de SERVICIOS',8000.00,0.00,NULL,'Impuesto 10%'),
(3756,'OLD-3756','2026-01-15','GASTO',4,'101 - Efectivo',0.00,8000.00,NULL,'Pago: SERVICIOS'),
(3757,'OLD-3757','2026-01-29','GASTO',13,'701 - Gastos de Personal',1226.00,0.00,NULL,'Salario eddy 10%'),
(3758,'OLD-3758','2026-01-29','GASTO',13,'101 - Efectivo',0.00,1226.00,NULL,'Pago: NOMINA'),
(3759,'OLD-3759','2026-01-28','GASTO',14,'701 - Gastos de Personal',665.50,0.00,NULL,'Salario eddy 10%'),
(3760,'OLD-3760','2026-01-28','GASTO',14,'101 - Efectivo',0.00,665.50,NULL,'Pago: NOMINA'),
(3761,'OLD-3761','2026-01-27','GASTO',15,'701 - Gastos de Personal',611.50,0.00,NULL,'Salario eddy 10%'),
(3762,'OLD-3762','2026-01-27','GASTO',15,'101 - Efectivo',0.00,611.50,NULL,'Pago: NOMINA'),
(3763,'OLD-3763','2026-01-26','GASTO',16,'701 - Gastos de Personal',531.00,0.00,NULL,'Salario eddy 10%'),
(3764,'OLD-3764','2026-01-26','GASTO',16,'101 - Efectivo',0.00,531.00,NULL,'Pago: NOMINA'),
(3765,'OLD-3765','2026-01-25','GASTO',18,'701 - Gastos de Personal',1431.50,0.00,NULL,'Salario eddy 10%'),
(3766,'OLD-3766','2026-01-25','GASTO',18,'101 - Efectivo',0.00,1431.50,NULL,'Pago: NOMINA'),
(3767,'OLD-3767','2026-01-24','GASTO',19,'701 - Gastos de Personal',934.50,0.00,NULL,'Salario eddy 10%'),
(3768,'OLD-3768','2026-01-24','GASTO',19,'101 - Efectivo',0.00,934.50,NULL,'Pago: NOMINA'),
(3769,'OLD-3769','2026-01-23','GASTO',20,'701 - Gastos de Personal',729.00,0.00,NULL,'Salario eddy 10%'),
(3770,'OLD-3770','2026-01-23','GASTO',20,'101 - Efectivo',0.00,729.00,NULL,'Pago: NOMINA'),
(3771,'OLD-3771','2026-01-30','GASTO',21,'701 - Gastos de Personal',1770.50,0.00,NULL,'Salario eddy 10%'),
(3772,'OLD-3772','2026-01-30','GASTO',21,'101 - Efectivo',0.00,1770.50,NULL,'Pago: NOMINA'),
(3773,'OLD-3773','2026-01-29','COMPRA',1,'120 - Inventario Mercancías',8160.00,0.00,NULL,'Compra #1 - adrian'),
(3774,'OLD-3774','2026-01-29','COMPRA',1,'101 - Efectivo',0.00,8160.00,NULL,'Pago Compra #1'),
(3775,'OLD-3775','2026-01-29','COMPRA',2,'120 - Inventario Mercancías',292.71,0.00,NULL,'Compra #2 - '),
(3776,'OLD-3776','2026-01-29','COMPRA',2,'101 - Efectivo',0.00,292.71,NULL,'Pago Compra #2'),
(3777,'OLD-3777','2026-01-29','COMPRA',3,'120 - Inventario Mercancías',1594.00,0.00,NULL,'Compra #3 - roly ce'),
(3778,'OLD-3778','2026-01-29','COMPRA',3,'101 - Efectivo',0.00,1594.00,NULL,'Pago Compra #3'),
(3779,'OLD-3779','2026-01-29','COMPRA',5,'120 - Inventario Mercancías',6140.00,0.00,NULL,'Compra #5 - roly ce'),
(3780,'OLD-3780','2026-01-29','COMPRA',5,'101 - Efectivo',0.00,6140.00,NULL,'Pago Compra #5'),
(3781,'OLD-3781','2026-01-30','COMPRA',10,'120 - Inventario Mercancías',6050.00,0.00,NULL,'Compra #10 - por error'),
(3782,'OLD-3782','2026-01-30','COMPRA',10,'101 - Efectivo',0.00,6050.00,NULL,'Pago Compra #10'),
(3783,'OLD-3783','2026-01-29','MERMA',1,'709 - Gastos por Merma',3240.00,0.00,NULL,'Pérdida Merma #1'),
(3784,'OLD-3784','2026-01-29','MERMA',1,'120 - Inventario Mercancías',0.00,3240.00,NULL,'Baja por Merma'),
(3785,'OLD-3785','2026-01-30','MERMA',2,'709 - Gastos por Merma',292.71,0.00,NULL,'Pérdida Merma #2'),
(3786,'OLD-3786','2026-01-30','MERMA',2,'120 - Inventario Mercancías',0.00,292.71,NULL,'Baja por Merma'),
(3787,'20260209-221957-CC19','2026-02-08',NULL,NULL,'101.01',7590.00,0.00,NULL,'Ingreso Efectivo - Cierre de Caja #19'),
(3788,'20260209-221957-CC19','2026-02-08',NULL,NULL,'104.01',8740.00,0.00,NULL,'Ingreso Transferencia - Cierre de Caja #19'),
(3789,'20260209-221957-CC19','2026-02-08',NULL,NULL,'401.01',0.00,16330.00,NULL,'Ventas del Día - Eddy (Cierre de Caja #19)');
/*!40000 ALTER TABLE `contabilidad_diario` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contabilidad_gastos`
--

DROP TABLE IF EXISTS `contabilidad_gastos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contabilidad_gastos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date DEFAULT NULL,
  `descripcion` varchar(200) DEFAULT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `monto` decimal(12,2) DEFAULT NULL,
  `origen_pago` enum('CAJA','BANCO') DEFAULT 'CAJA',
  `usuario` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contabilidad_gastos`
--

LOCK TABLES `contabilidad_gastos` WRITE;
/*!40000 ALTER TABLE `contabilidad_gastos` DISABLE KEYS */;
/*!40000 ALTER TABLE `contabilidad_gastos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contabilidad_saldos`
--

DROP TABLE IF EXISTS `contabilidad_saldos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contabilidad_saldos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date DEFAULT NULL,
  `caja_fuerte` decimal(12,2) DEFAULT 0.00,
  `banco` decimal(12,2) DEFAULT 0.00,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contabilidad_saldos`
--

LOCK TABLES `contabilidad_saldos` WRITE;
/*!40000 ALTER TABLE `contabilidad_saldos` DISABLE KEYS */;
/*!40000 ALTER TABLE `contabilidad_saldos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `declaraciones_onat`
--

DROP TABLE IF EXISTS `declaraciones_onat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `declaraciones_onat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `anio` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `tipo_entidad` varchar(20) DEFAULT NULL,
  `ingresos_brutos` decimal(12,2) DEFAULT NULL,
  `costo_ventas` decimal(12,2) DEFAULT NULL,
  `gastos_nomina` decimal(12,2) DEFAULT NULL,
  `gastos_otros` decimal(12,2) DEFAULT NULL,
  `imp_ventas` decimal(12,2) DEFAULT NULL,
  `imp_fuerza` decimal(12,2) DEFAULT NULL,
  `contrib_local` decimal(12,2) DEFAULT NULL,
  `seg_social` decimal(12,2) DEFAULT NULL,
  `seg_social_especial` decimal(12,2) DEFAULT NULL,
  `imp_utilidades` decimal(12,2) DEFAULT NULL,
  `total_pagar` decimal(12,2) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_decl` (`id_empresa`,`anio`,`mes`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `declaraciones_onat`
--

LOCK TABLES `declaraciones_onat` WRITE;
/*!40000 ALTER TABLE `declaraciones_onat` DISABLE KEYS */;
INSERT INTO `declaraciones_onat` VALUES
(1,1,2026,1,'MIPYME',48350.00,36827.00,27000.00,200.00,4835.00,1350.00,483.50,1350.00,3240.00,0.00,11258.50,'2026-01-30 00:53:02');
/*!40000 ALTER TABLE `declaraciones_onat` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empresas`
--

DROP TABLE IF EXISTS `empresas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `empresas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empresas`
--

LOCK TABLES `empresas` WRITE;
/*!40000 ALTER TABLE `empresas` DISABLE KEYS */;
INSERT INTO `empresas` VALUES
(1,'palweb',1);
/*!40000 ALTER TABLE `empresas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facturas`
--

DROP TABLE IF EXISTS `facturas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero_factura` varchar(20) NOT NULL,
  `fecha_emision` datetime NOT NULL,
  `id_ticket_origen` int(11) DEFAULT NULL,
  `cliente_nombre` varchar(150) DEFAULT NULL,
  `cliente_direccion` varchar(255) DEFAULT NULL,
  `cliente_telefono` varchar(50) DEFAULT NULL,
  `mensajero_nombre` varchar(100) DEFAULT NULL,
  `vehiculo` varchar(100) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `creado_por` varchar(100) DEFAULT NULL,
  `estado` enum('ACTIVA','ANULADA') DEFAULT 'ACTIVA',
  `estado_pago` enum('PENDIENTE','PAGADA') DEFAULT 'PENDIENTE',
  `metodo_pago` varchar(50) DEFAULT NULL,
  `fecha_pago` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facturas`
--

LOCK TABLES `facturas` WRITE;
/*!40000 ALTER TABLE `facturas` DISABLE KEYS */;
INSERT INTO `facturas` VALUES
(1,'20260209001','2026-02-08 00:00:00',20,'la rosa de guadalupe','calle $534 entre pez y rayo','5834985904','pepe robert','Triciclo Eléctrico',360.00,360.00,'Administrador','ACTIVA','PAGADA','Efectivo','2026-02-09 21:41:50'),
(2,'20260209001','2026-02-08 00:00:00',NULL,'la rosa de guadalupe','calle $534 entre pez y rayo','5834985904','pepe robert','Triciclo Eléctrico',0.00,0.00,'Administrador','ANULADA','PENDIENTE','',NULL),
(3,'20260209001','2026-02-08 00:00:00',123,'la rosa de guadalupe','calle $534 entre pez y rayo','5834985904','pepe robert','Triciclo Eléctrico',4560.00,4560.00,'Administrador','ACTIVA','PENDIENTE',NULL,NULL),
(4,'20260209001','2026-02-08 00:00:00',NULL,'la rosa de guadalupe','calle $534 entre pez y rayo','5834985904','pepe robert','Triciclo Eléctrico',0.00,0.00,'Administrador','ANULADA','PENDIENTE','',NULL),
(5,'20260209001','2026-02-08 00:00:00',NULL,'la rosa de guadalupe','calle $534 entre pez y rayo','5834985904','pepe robert','Triciclo Eléctrico',0.00,0.00,'Administrador','ANULADA','PENDIENTE','',NULL),
(6,'20260209001','2026-02-08 00:00:00',120,'la rosa de guadalupe','calle $534 entre pez y rayo','5834985904','pepe robert','Triciclo Eléctrico',-750.00,-750.00,'Administrador','ACTIVA','PENDIENTE',NULL,NULL),
(7,'20260209007','2026-02-08 00:00:00',NULL,'la rosa de guadalupe','calle $534 entre pez y rayo','5834985904','pepe robert','Triciclo Eléctrico',0.00,0.00,'Administrador','ACTIVA','PENDIENTE',NULL,NULL),
(8,'20260209008','2026-02-08 00:00:00',100,'la rosa de guadalupe','calle $534 entre pez y rayo','5834985904','pepe robert','Triciclo Eléctrico',1000.00,1000.00,'Administrador','ACTIVA','PENDIENTE',NULL,NULL);
/*!40000 ALTER TABLE `facturas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facturas_detalle`
--

DROP TABLE IF EXISTS `facturas_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facturas_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_factura` int(11) NOT NULL,
  `descripcion` varchar(200) NOT NULL,
  `unidad_medida` varchar(20) DEFAULT 'UND',
  `cantidad` decimal(10,2) NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `importe` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_factura` (`id_factura`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facturas_detalle`
--

LOCK TABLES `facturas_detalle` WRITE;
/*!40000 ALTER TABLE `facturas_detalle` DISABLE KEYS */;
INSERT INTO `facturas_detalle` VALUES
(1,1,'Albondigas pqt','UND',1.00,360.00,360.00),
(2,3,'Cabezote','UND',10.00,80.00,800.00),
(3,3,'Albondigas pqt','UND',1.00,360.00,360.00),
(4,3,'Leche condensada','UND',1.00,520.00,520.00),
(5,3,'Fanguito','UND',3.00,580.00,1740.00),
(6,3,'Cerveza Esple','UND',4.00,230.00,920.00),
(7,3,'pqt torticas 6u','UND',1.00,220.00,220.00),
(8,6,'Croquetas pollo pqt','UND',-5.00,150.00,-750.00),
(9,8,'Energizante','UND',1.00,280.00,280.00),
(10,8,'Refresco cola lata 300ml','UND',2.00,240.00,480.00),
(11,8,'Cerveza La Fria','UND',1.00,240.00,240.00);
/*!40000 ALTER TABLE `facturas_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `flujo_caja_mensual`
--

DROP TABLE IF EXISTS `flujo_caja_mensual`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `flujo_caja_mensual` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `concepto_key` varchar(50) NOT NULL,
  `valor` decimal(15,2) DEFAULT 0.00,
  `id_sucursal` int(11) DEFAULT 1,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_celda` (`fecha`,`concepto_key`,`id_sucursal`)
) ENGINE=InnoDB AUTO_INCREMENT=638 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `flujo_caja_mensual`
--

LOCK TABLES `flujo_caja_mensual` WRITE;
/*!40000 ALTER TABLE `flujo_caja_mensual` DISABLE KEYS */;
INSERT INTO `flujo_caja_mensual` VALUES
(1,'2026-02-01','ventas_diarias',0.00,1,'2026-02-11 22:30:57'),
(2,'2026-02-01','inv_1',0.00,1,'2026-02-12 15:15:40'),
(3,'2026-02-01','inv_2',123198.85,1,'2026-02-12 15:15:40'),
(4,'2026-02-01','inv_4',46625.39,1,'2026-02-12 15:15:40'),
(5,'2026-02-01','inv_3',0.00,1,'2026-02-11 22:25:42'),
(6,'2026-02-01','inv_6',0.00,1,'2026-02-11 22:25:42'),
(7,'2026-02-01','inv_5',0.00,1,'2026-02-11 22:25:42'),
(8,'2026-02-02','inv_2',123198.85,1,'2026-02-12 15:15:44'),
(9,'2026-02-02','ventas_diarias',0.00,1,'2026-02-11 22:31:14'),
(10,'2026-02-02','inv_4',75424.82,1,'2026-02-12 15:15:44'),
(11,'2026-02-02','inv_1',0.00,1,'2026-02-12 15:15:44'),
(12,'2026-02-02','inv_3',0.00,1,'2026-02-11 22:25:47'),
(13,'2026-02-02','inv_5',0.00,1,'2026-02-11 22:25:47'),
(14,'2026-02-02','inv_6',0.00,1,'2026-02-11 22:25:47'),
(15,'2026-02-03','ventas_diarias',0.00,1,'2026-02-12 13:15:19'),
(16,'2026-02-03','inv_4',90794.82,1,'2026-02-12 15:15:48'),
(17,'2026-02-03','inv_3',0.00,1,'2026-02-11 22:26:03'),
(18,'2026-02-03','inv_2',123198.85,1,'2026-02-12 15:15:48'),
(19,'2026-02-03','inv_1',0.00,1,'2026-02-12 15:15:48'),
(20,'2026-02-03','inv_5',0.00,1,'2026-02-11 22:26:03'),
(21,'2026-02-03','inv_6',0.00,1,'2026-02-11 22:26:03'),
(22,'2026-02-04','inv_1',0.00,1,'2026-02-12 15:15:51'),
(23,'2026-02-04','inv_4',62007.00,1,'2026-02-12 15:15:51'),
(24,'2026-02-04','ventas_diarias',18890.00,1,'2026-02-12 12:40:50'),
(25,'2026-02-04','inv_5',0.00,1,'2026-02-11 22:26:27'),
(26,'2026-02-04','inv_3',0.00,1,'2026-02-11 22:26:27'),
(27,'2026-02-04','inv_2',123198.85,1,'2026-02-12 15:15:51'),
(28,'2026-02-04','inv_6',0.00,1,'2026-02-11 22:26:27'),
(29,'2026-02-05','inv_3',0.00,1,'2026-02-11 22:26:31'),
(30,'2026-02-05','inv_4',64017.00,1,'2026-02-12 15:15:55'),
(31,'2026-02-05','ventas_diarias',9080.00,1,'2026-02-12 12:40:38'),
(32,'2026-02-05','inv_2',123198.85,1,'2026-02-12 15:15:55'),
(33,'2026-02-05','inv_1',0.00,1,'2026-02-12 15:15:55'),
(34,'2026-02-05','inv_5',0.00,1,'2026-02-11 22:26:31'),
(35,'2026-02-05','inv_6',0.00,1,'2026-02-11 22:26:31'),
(36,'2026-02-06','inv_2',123198.85,1,'2026-02-12 15:15:58'),
(37,'2026-02-06','inv_1',0.00,1,'2026-02-12 15:15:58'),
(38,'2026-02-06','ventas_diarias',16330.00,1,'2026-02-12 12:40:34'),
(39,'2026-02-06','inv_3',0.00,1,'2026-02-11 22:26:35'),
(40,'2026-02-06','inv_5',0.00,1,'2026-02-11 22:26:35'),
(41,'2026-02-06','inv_4',67621.00,1,'2026-02-12 15:15:58'),
(42,'2026-02-06','inv_6',0.00,1,'2026-02-11 22:26:35'),
(43,'2026-02-07','inv_3',0.00,1,'2026-02-11 22:26:39'),
(44,'2026-02-07','inv_4',57174.72,1,'2026-02-12 15:16:01'),
(45,'2026-02-07','inv_5',0.00,1,'2026-02-11 22:26:39'),
(46,'2026-02-07','ventas_diarias',21120.00,1,'2026-02-12 00:36:35'),
(47,'2026-02-07','inv_1',0.00,1,'2026-02-12 15:16:01'),
(48,'2026-02-07','inv_2',123198.85,1,'2026-02-12 15:16:01'),
(49,'2026-02-07','inv_6',0.00,1,'2026-02-11 22:26:39'),
(64,'2026-02-08','inv_2',123198.85,1,'2026-02-12 15:16:03'),
(65,'2026-02-08','inv_4',56211.08,1,'2026-02-12 15:16:03'),
(66,'2026-02-08','inv_5',0.00,1,'2026-02-11 22:31:18'),
(67,'2026-02-08','ventas_diarias',11080.00,1,'2026-02-11 22:31:18'),
(68,'2026-02-08','inv_1',0.00,1,'2026-02-12 15:16:03'),
(69,'2026-02-08','inv_3',0.00,1,'2026-02-11 22:31:18'),
(70,'2026-02-08','inv_6',0.00,1,'2026-02-11 22:31:18'),
(71,'2026-02-09','inv_3',0.00,1,'2026-02-11 22:31:22'),
(72,'2026-02-09','ventas_diarias',0.00,1,'2026-02-11 22:31:22'),
(73,'2026-02-09','inv_2',123198.85,1,'2026-02-12 15:16:07'),
(74,'2026-02-09','inv_5',0.00,1,'2026-02-11 22:31:22'),
(75,'2026-02-09','inv_4',56211.08,1,'2026-02-12 15:16:07'),
(76,'2026-02-09','inv_1',0.00,1,'2026-02-12 15:16:07'),
(77,'2026-02-09','inv_6',0.00,1,'2026-02-11 22:31:22'),
(78,'2026-02-10','ventas_diarias',0.00,1,'2026-02-11 22:31:27'),
(79,'2026-02-10','inv_3',0.00,1,'2026-02-11 22:31:27'),
(80,'2026-02-10','inv_1',0.00,1,'2026-02-12 15:16:09'),
(81,'2026-02-10','inv_5',0.00,1,'2026-02-11 22:31:27'),
(82,'2026-02-10','inv_4',36648.24,1,'2026-02-12 15:16:09'),
(83,'2026-02-10','inv_2',123198.85,1,'2026-02-12 15:16:09'),
(84,'2026-02-10','inv_6',0.00,1,'2026-02-11 22:31:27'),
(85,'2026-02-11','inv_4',36168.24,1,'2026-02-12 15:16:36'),
(86,'2026-02-11','ventas_diarias',600.00,1,'2026-02-12 00:32:14'),
(87,'2026-02-11','inv_5',0.00,1,'2026-02-11 22:31:55'),
(88,'2026-02-11','inv_1',0.00,1,'2026-02-12 15:16:36'),
(89,'2026-02-11','inv_2',123198.85,1,'2026-02-12 15:16:36'),
(90,'2026-02-11','inv_3',0.00,1,'2026-02-11 22:31:55'),
(91,'2026-02-11','inv_6',0.00,1,'2026-02-11 22:31:55'),
(92,'2026-02-12','ventas_diarias',0.00,1,'2026-02-11 22:31:59'),
(93,'2026-02-12','inv_3',0.00,1,'2026-02-11 22:31:59'),
(94,'2026-02-12','inv_5',0.00,1,'2026-02-11 22:31:59'),
(95,'2026-02-12','inv_4',36168.24,1,'2026-02-12 15:16:38'),
(96,'2026-02-12','inv_2',123198.85,1,'2026-02-12 15:16:38'),
(97,'2026-02-12','inv_1',0.00,1,'2026-02-12 15:16:38'),
(98,'2026-02-12','inv_6',0.00,1,'2026-02-11 22:31:59'),
(561,'2026-02-13','inv_2',123198.85,1,'2026-02-12 15:16:16'),
(562,'2026-02-13','inv_5',0.00,1,'2026-02-12 15:16:16'),
(563,'2026-02-13','ventas_diarias',0.00,1,'2026-02-12 15:16:16'),
(564,'2026-02-13','inv_1',0.00,1,'2026-02-12 15:16:16'),
(565,'2026-02-13','inv_4',36168.24,1,'2026-02-12 15:16:16'),
(566,'2026-02-13','inv_3',0.00,1,'2026-02-12 15:16:16'),
(567,'2026-02-13','inv_6',0.00,1,'2026-02-12 15:16:16'),
(568,'2026-02-14','ventas_diarias',0.00,1,'2026-02-12 15:16:18'),
(569,'2026-02-14','inv_3',0.00,1,'2026-02-12 15:16:19'),
(570,'2026-02-14','inv_2',123198.85,1,'2026-02-12 15:16:19'),
(571,'2026-02-14','inv_4',36168.24,1,'2026-02-12 15:16:19'),
(572,'2026-02-14','inv_5',0.00,1,'2026-02-12 15:16:19'),
(573,'2026-02-14','inv_1',0.00,1,'2026-02-12 15:16:19'),
(574,'2026-02-14','inv_6',0.00,1,'2026-02-12 15:16:19');
/*!40000 ALTER TABLE `flujo_caja_mensual` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gastos_fijos`
--

DROP TABLE IF EXISTS `gastos_fijos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gastos_fijos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `concepto` varchar(150) NOT NULL,
  `monto_estimado` decimal(10,2) DEFAULT 0.00,
  `dia_pago_sugerido` int(11) DEFAULT 1,
  `categoria` varchar(50) DEFAULT 'General',
  `activo` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gastos_fijos`
--

LOCK TABLES `gastos_fijos` WRITE;
/*!40000 ALTER TABLE `gastos_fijos` DISABLE KEYS */;
/*!40000 ALTER TABLE `gastos_fijos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gastos_historial`
--

DROP TABLE IF EXISTS `gastos_historial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gastos_historial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `concepto` varchar(150) DEFAULT NULL,
  `monto` decimal(10,2) NOT NULL,
  `categoria` varchar(50) DEFAULT NULL,
  `id_usuario` varchar(50) DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `tipo` enum('VARIABLE','FIJO') DEFAULT 'VARIABLE',
  `id_sucursal` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_gastos_sucursal` (`id_sucursal`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gastos_historial`
--

LOCK TABLES `gastos_historial` WRITE;
/*!40000 ALTER TABLE `gastos_historial` DISABLE KEYS */;
INSERT INTO `gastos_historial` VALUES
(1,'2026-01-26','pago de luz',3000.00,'SERVICIOS','Admin',NULL,'FIJO',4),
(2,'2026-01-29','ETECSA MOVILES',5600.00,'SERVICIOS','Admin','','FIJO',4),
(3,'2026-01-10','Impuestos ONAT',7800.00,'SERVICIOS','Admin','','FIJO',4),
(4,'2026-01-15','Impuesto 10%',8000.00,'SERVICIOS','Admin','','FIJO',4),
(9,'2026-02-26','pago de luz',3000.00,'SERVICIOS','Admin',NULL,'FIJO',4),
(10,'2026-02-28','ETECSA MOVILES',5600.00,'SERVICIOS','Admin','','FIJO',4),
(11,'2026-02-10','Impuestos ONAT',7800.00,'SERVICIOS','Admin','','FIJO',4),
(12,'2026-02-15','Impuesto 10%',8000.00,'SERVICIOS','Admin','','FIJO',4),
(13,'2026-01-29','Salario eddy 10%',1226.00,'NOMINA','Admin','10% de $12260 = $1,226.00','VARIABLE',4),
(14,'2026-01-28','Salario eddy 10%',665.50,'NOMINA','Admin','10% de $6655 = $665.50','VARIABLE',4),
(15,'2026-01-27','Salario eddy 10%',611.50,'NOMINA','Admin','10% de $6115 = $611.50','VARIABLE',4),
(16,'2026-01-26','Salario eddy 10%',531.00,'NOMINA','Admin','10% de $5310 = $531.00','VARIABLE',4),
(18,'2026-01-25','Salario eddy 10%',1431.50,'NOMINA','Admin','10% de $14315 = $1,431.50','VARIABLE',4),
(19,'2026-01-24','Salario eddy 10%',934.50,'NOMINA','Admin','10% de $9345 = $934.50','VARIABLE',4),
(20,'2026-01-23','Salario eddy 10%',729.00,'NOMINA','Admin','10% de $7290 = $729.00','VARIABLE',4),
(21,'2026-01-30','Salario eddy 10%',1770.50,'NOMINA','Admin','10% de $17705 = $1,770.50','VARIABLE',4);
/*!40000 ALTER TABLE `gastos_historial` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gastos_plantillas_operativos`
--

DROP TABLE IF EXISTS `gastos_plantillas_operativos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gastos_plantillas_operativos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `categoria` varchar(50) NOT NULL DEFAULT 'OPERATIVO',
  `monto_default` decimal(12,2) DEFAULT 0.00,
  `descripcion` text DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `orden` int(11) DEFAULT 0,
  `es_salario` tinyint(1) DEFAULT 0,
  `tipo_calculo_salario` enum('FIJO','PORCENTAJE_VENTAS','FIJO_MAS_PORCENTAJE','FIJO_MAS_PORCENTAJE_SOBRE_META','PORCENTAJE_ESCALONADO','POR_HORA','PERSONALIZADO') DEFAULT 'FIJO',
  `salario_fijo` decimal(12,2) DEFAULT 0.00,
  `porcentaje_ventas` decimal(5,2) DEFAULT 0.00,
  `meta_ventas` decimal(12,2) DEFAULT 0.00,
  `porcentaje_sobre_meta` decimal(5,2) DEFAULT 0.00,
  `valor_hora` decimal(10,2) DEFAULT 0.00,
  `config_escalonado` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_escalonado`)),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_plantillas_activo` (`activo`),
  KEY `idx_plantillas_categoria` (`categoria`),
  KEY `idx_plantillas_es_salario` (`es_salario`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gastos_plantillas_operativos`
--

LOCK TABLES `gastos_plantillas_operativos` WRITE;
/*!40000 ALTER TABLE `gastos_plantillas_operativos` DISABLE KEYS */;
INSERT INTO `gastos_plantillas_operativos` VALUES
(1,'Limpieza diaria','LIMPIEZA',0.00,'Productos y servicios de limpieza del local',1,1,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(2,'Desechables','LIMPIEZA',0.00,'Bolsas, papel, servilletas, etc.',1,2,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(3,'Fumigación','LIMPIEZA',0.00,'Control de plagas',1,3,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(8,'Propinas entregadas','NOMINA',0.00,'Propinas pagadas al personal',1,14,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(10,'Personal eventual','NOMINA',0.00,'Trabajadores temporales',1,16,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(11,'Pago a proveedores','DEUDAS',0.00,'Abono a cuentas por pagar',1,20,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(12,'Intereses préstamos','DEUDAS',0.00,'Intereses de financiamiento',1,21,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(13,'Cuota de crédito','DEUDAS',0.00,'Pago de créditos bancarios',1,22,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(14,'Combustible/Gas','OPERATIVO',0.00,'Gas LP o combustible para cocina',1,30,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(15,'Hielo','OPERATIVO',0.00,'Compra de hielo para el día',1,31,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(16,'Agua embotellada','OPERATIVO',0.00,'Garrafones o botellas de agua',1,32,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(17,'Insumos menores','OPERATIVO',0.00,'Compras pequeñas del día',1,33,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(18,'Transporte/Delivery','OPERATIVO',0.00,'Gastos de entrega a domicilio',1,34,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(19,'Electricidad prepago','SERVICIOS',0.00,'Recarga de electricidad prepago',1,40,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(20,'Internet/WiFi','SERVICIOS',0.00,'Pago diario de internet',1,41,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(21,'Teléfono','SERVICIOS',0.00,'Recargas telefónicas',1,42,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(22,'Seguridad privada','SEGURIDAD',0.00,'Pago a vigilancia',1,50,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(23,'Custodia nocturna','SEGURIDAD',0.00,'Servicio nocturno de seguridad',1,51,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(24,'Reparaciones urgentes','MANTENIMIENTO',0.00,'Arreglos menores urgentes',1,60,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(25,'Gastos médicos','OTROS',0.00,'Atención médica de emergencia',1,61,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(26,'Multas/Permisos','OTROS',0.00,'Pagos a autoridades',1,62,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(27,'Otros gastos','OTROS',0.00,'Gastos varios no clasificados',1,99,0,'FIJO',0.00,0.00,0.00,0.00,0.00,NULL,'2026-02-02 02:11:31','2026-02-02 02:11:31'),
(28,'Salario eddy 10%','NOMINA',0.00,'eddy',1,0,1,'PORCENTAJE_VENTAS',0.00,10.00,0.00,0.00,0.00,NULL,'2026-02-02 02:13:35','2026-02-02 02:13:35');
/*!40000 ALTER TABLE `gastos_plantillas_operativos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `horarios_tienda`
--

DROP TABLE IF EXISTS `horarios_tienda`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `horarios_tienda` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dia_semana` tinyint(4) NOT NULL,
  `hora_apertura` time NOT NULL DEFAULT '09:00:00',
  `hora_cierre` time NOT NULL DEFAULT '22:00:00',
  `activo` tinyint(1) DEFAULT 1,
  `id_sucursal` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dia_suc` (`dia_semana`,`id_sucursal`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `horarios_tienda`
--

LOCK TABLES `horarios_tienda` WRITE;
/*!40000 ALTER TABLE `horarios_tienda` DISABLE KEYS */;
INSERT INTO `horarios_tienda` VALUES
(1,1,'09:00:00','22:00:00',1,NULL),
(2,2,'09:00:00','22:00:00',1,NULL),
(3,3,'09:00:00','22:00:00',1,NULL),
(4,4,'09:00:00','22:00:00',1,NULL),
(5,5,'09:00:00','23:00:00',1,NULL),
(6,6,'09:00:00','23:00:00',1,NULL),
(7,7,'09:00:00','22:00:00',1,NULL);
/*!40000 ALTER TABLE `horarios_tienda` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kardex`
--

DROP TABLE IF EXISTS `kardex`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `kardex` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `id_producto` varchar(50) NOT NULL,
  `id_almacen` int(11) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `tipo_movimiento` varchar(50) DEFAULT 'GENERAL',
  `cantidad` decimal(15,4) NOT NULL,
  `saldo_anterior` decimal(15,4) NOT NULL,
  `saldo_actual` decimal(15,4) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `costo_unitario` decimal(15,2) DEFAULT 0.00,
  `usuario` varchar(100) DEFAULT 'Sistema',
  `uuid` char(36) DEFAULT NULL,
  `id_sucursal` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uuid` (`uuid`),
  KEY `idx_prod_fecha` (`id_producto`,`fecha`),
  KEY `idx_almacen` (`id_almacen`)
) ENGINE=InnoDB AUTO_INCREMENT=556 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kardex`
--

LOCK TABLES `kardex` WRITE;
/*!40000 ALTER TABLE `kardex` DISABLE KEYS */;
INSERT INTO `kardex` VALUES
(1,'756058841477',2,'2026-01-29 18:50:04','AJUSTE',5.0000,0.0000,5.0000,'IMPORT_CSV_185004',20.00,'ADMIN_IMPORT','54f3de0e-01f4-11f1-b0a7-0affd1a0dee5',2),
(2,'881105',2,'2026-01-29 18:50:04','AJUSTE',29.0000,0.0000,29.0000,'IMPORT_CSV_185004',50.00,'ADMIN_IMPORT','54f3e2d6-01f4-11f1-b0a7-0affd1a0dee5',2),
(3,'991101',2,'2026-01-29 18:50:04','AJUSTE',6290.0000,0.0000,6290.0000,'IMPORT_CSV_185004',0.58,'ADMIN_IMPORT','54f3e3a8-01f4-11f1-b0a7-0affd1a0dee5',2),
(4,'991102',2,'2026-01-29 18:50:04','AJUSTE',320.0000,0.0000,320.0000,'IMPORT_CSV_185004',0.61,'ADMIN_IMPORT','54f3e442-01f4-11f1-b0a7-0affd1a0dee5',2),
(5,'991103',2,'2026-01-29 18:50:04','AJUSTE',5735.0000,0.0000,5735.0000,'IMPORT_CSV_185004',0.40,'ADMIN_IMPORT','54f3e4d1-01f4-11f1-b0a7-0affd1a0dee5',2),
(6,'991105',2,'2026-01-29 18:50:04','AJUSTE',2530.0000,0.0000,2530.0000,'IMPORT_CSV_185004',1.20,'ADMIN_IMPORT','54f3e561-01f4-11f1-b0a7-0affd1a0dee5',2),
(7,'991106',2,'2026-01-29 18:50:04','AJUSTE',700.0000,0.0000,700.0000,'IMPORT_CSV_185004',1.80,'ADMIN_IMPORT','54f3e5ee-01f4-11f1-b0a7-0affd1a0dee5',2),
(8,'991107',2,'2026-01-29 18:50:04','AJUSTE',695.0000,0.0000,695.0000,'IMPORT_CSV_185004',2.40,'ADMIN_IMPORT','54f3e679-01f4-11f1-b0a7-0affd1a0dee5',2),
(9,'991108',2,'2026-01-29 18:50:04','AJUSTE',500.0000,0.0000,500.0000,'IMPORT_CSV_185004',0.85,'ADMIN_IMPORT','54f3e8e6-01f4-11f1-b0a7-0affd1a0dee5',2),
(10,'991109',2,'2026-01-29 18:50:04','AJUSTE',200.0000,0.0000,200.0000,'IMPORT_CSV_185004',2.50,'ADMIN_IMPORT','54f3e977-01f4-11f1-b0a7-0affd1a0dee5',2),
(11,'991110',2,'2026-01-29 18:50:04','AJUSTE',415.0000,0.0000,415.0000,'IMPORT_CSV_185004',3.60,'ADMIN_IMPORT','54f3e9fe-01f4-11f1-b0a7-0affd1a0dee5',2),
(12,'991111',2,'2026-01-29 18:50:04','AJUSTE',1365.0000,0.0000,1365.0000,'IMPORT_CSV_185004',1.80,'ADMIN_IMPORT','54f3ea5d-01f4-11f1-b0a7-0affd1a0dee5',2),
(13,'991112',2,'2026-01-29 18:50:04','AJUSTE',315.0000,0.0000,315.0000,'IMPORT_CSV_185004',5.00,'ADMIN_IMPORT','54f3eab4-01f4-11f1-b0a7-0affd1a0dee5',2),
(14,'991113',2,'2026-01-29 18:50:04','AJUSTE',930.0000,0.0000,930.0000,'IMPORT_CSV_185004',4.15,'ADMIN_IMPORT','54f3eb0c-01f4-11f1-b0a7-0affd1a0dee5',2),
(15,'991114',2,'2026-01-29 18:50:04','AJUSTE',3.0000,0.0000,3.0000,'IMPORT_CSV_185004',45.00,'ADMIN_IMPORT','54f3eb64-01f4-11f1-b0a7-0affd1a0dee5',2),
(16,'991115',2,'2026-01-29 18:50:04','AJUSTE',29.0000,0.0000,29.0000,'IMPORT_CSV_185004',25.00,'ADMIN_IMPORT','54f3ebbc-01f4-11f1-b0a7-0affd1a0dee5',2),
(17,'991116',2,'2026-01-29 18:50:04','AJUSTE',14.0000,0.0000,14.0000,'IMPORT_CSV_185004',50.00,'ADMIN_IMPORT','54f3ec13-01f4-11f1-b0a7-0affd1a0dee5',2),
(18,'991117',2,'2026-01-29 18:50:04','AJUSTE',13.0000,0.0000,13.0000,'IMPORT_CSV_185004',50.00,'ADMIN_IMPORT','54f3ec69-01f4-11f1-b0a7-0affd1a0dee5',2),
(19,'991118',2,'2026-01-29 18:50:04','AJUSTE',110.0000,0.0000,110.0000,'IMPORT_CSV_185004',1.00,'ADMIN_IMPORT','54f3ecc0-01f4-11f1-b0a7-0affd1a0dee5',2),
(20,'991119',2,'2026-01-29 18:50:04','AJUSTE',1740.0000,0.0000,1740.0000,'IMPORT_CSV_185004',0.12,'ADMIN_IMPORT','54f3ed18-01f4-11f1-b0a7-0affd1a0dee5',2),
(21,'991120',2,'2026-01-29 18:50:04','AJUSTE',180.0000,0.0000,180.0000,'IMPORT_CSV_185004',2.00,'ADMIN_IMPORT','54f3ed6d-01f4-11f1-b0a7-0affd1a0dee5',2),
(22,'991121',2,'2026-01-29 18:50:04','AJUSTE',1145.0000,0.0000,1145.0000,'IMPORT_CSV_185004',0.43,'ADMIN_IMPORT','54f3edc5-01f4-11f1-b0a7-0affd1a0dee5',2),
(23,'991122',2,'2026-01-29 18:50:04','AJUSTE',1855.0000,0.0000,1855.0000,'IMPORT_CSV_185004',3.50,'ADMIN_IMPORT','54f3ee1e-01f4-11f1-b0a7-0affd1a0dee5',2),
(24,'991123',2,'2026-01-29 18:50:04','AJUSTE',910.0000,0.0000,910.0000,'IMPORT_CSV_185004',5.00,'ADMIN_IMPORT','54f3ee71-01f4-11f1-b0a7-0affd1a0dee5',2),
(25,'991124',2,'2026-01-29 18:50:04','AJUSTE',500.0000,0.0000,500.0000,'IMPORT_CSV_185004',0.40,'ADMIN_IMPORT','54f3eec9-01f4-11f1-b0a7-0affd1a0dee5',2),
(26,'991125',2,'2026-01-29 18:50:04','AJUSTE',240.0000,0.0000,240.0000,'IMPORT_CSV_185004',0.27,'ADMIN_IMPORT','54f3ef1c-01f4-11f1-b0a7-0affd1a0dee5',2),
(27,'991126',2,'2026-01-29 18:50:04','AJUSTE',300.0000,0.0000,300.0000,'IMPORT_CSV_185004',2.50,'ADMIN_IMPORT','54f3ef73-01f4-11f1-b0a7-0affd1a0dee5',2),
(28,'991127',2,'2026-01-29 18:50:04','AJUSTE',1775.0000,0.0000,1775.0000,'IMPORT_CSV_185004',2.50,'ADMIN_IMPORT','54f3efc8-01f4-11f1-b0a7-0affd1a0dee5',2),
(29,'991128',2,'2026-01-29 18:50:05','AJUSTE',830.0000,0.0000,830.0000,'IMPORT_CSV_185005',1.50,'ADMIN_IMPORT','54f3f01b-01f4-11f1-b0a7-0affd1a0dee5',2),
(30,'991129',2,'2026-01-29 18:50:05','AJUSTE',240.0000,0.0000,240.0000,'IMPORT_CSV_185005',1.50,'ADMIN_IMPORT','54f3f072-01f4-11f1-b0a7-0affd1a0dee5',2),
(31,'991130',2,'2026-01-29 18:50:05','AJUSTE',115.0000,0.0000,115.0000,'IMPORT_CSV_185005',1.50,'ADMIN_IMPORT','54f3f0cb-01f4-11f1-b0a7-0affd1a0dee5',2),
(32,'991131',2,'2026-01-29 18:50:05','AJUSTE',140.0000,0.0000,140.0000,'IMPORT_CSV_185005',1.50,'ADMIN_IMPORT','54f3f11e-01f4-11f1-b0a7-0affd1a0dee5',2),
(33,'991132',2,'2026-01-29 18:50:05','AJUSTE',630.0000,0.0000,630.0000,'IMPORT_CSV_185005',1.50,'ADMIN_IMPORT','54f3f174-01f4-11f1-b0a7-0affd1a0dee5',2),
(34,'991133',2,'2026-01-29 18:50:05','AJUSTE',625.0000,0.0000,625.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3f1cb-01f4-11f1-b0a7-0affd1a0dee5',2),
(35,'991134',2,'2026-01-29 18:50:05','AJUSTE',230.0000,0.0000,230.0000,'IMPORT_CSV_185005',4.60,'ADMIN_IMPORT','54f3f221-01f4-11f1-b0a7-0affd1a0dee5',2),
(36,'991135',2,'2026-01-29 18:50:05','AJUSTE',65.0000,0.0000,65.0000,'IMPORT_CSV_185005',1.00,'ADMIN_IMPORT','54f3f275-01f4-11f1-b0a7-0affd1a0dee5',2),
(37,'991136',2,'2026-01-29 18:50:05','AJUSTE',435.0000,0.0000,435.0000,'IMPORT_CSV_185005',2.90,'ADMIN_IMPORT','54f3f2d0-01f4-11f1-b0a7-0affd1a0dee5',2),
(38,'991137',2,'2026-01-29 18:50:05','AJUSTE',390.0000,0.0000,390.0000,'IMPORT_CSV_185005',2.90,'ADMIN_IMPORT','54f3f325-01f4-11f1-b0a7-0affd1a0dee5',2),
(39,'991139',2,'2026-01-29 18:50:05','AJUSTE',2775.0000,0.0000,2775.0000,'IMPORT_CSV_185005',2.60,'ADMIN_IMPORT','54f3f37c-01f4-11f1-b0a7-0affd1a0dee5',2),
(40,'991141',2,'2026-01-29 18:50:05','AJUSTE',180.0000,0.0000,180.0000,'IMPORT_CSV_185005',0.43,'ADMIN_IMPORT','54f3f3cf-01f4-11f1-b0a7-0affd1a0dee5',2),
(41,'991144',2,'2026-01-29 18:50:05','AJUSTE',135.0000,0.0000,135.0000,'IMPORT_CSV_185005',1.25,'ADMIN_IMPORT','54f3f426-01f4-11f1-b0a7-0affd1a0dee5',2),
(42,'991146',2,'2026-01-29 18:50:05','AJUSTE',75.0000,0.0000,75.0000,'IMPORT_CSV_185005',0.27,'ADMIN_IMPORT','54f3f47c-01f4-11f1-b0a7-0affd1a0dee5',2),
(43,'991147',2,'2026-01-29 18:50:05','AJUSTE',630.0000,0.0000,630.0000,'IMPORT_CSV_185005',0.50,'ADMIN_IMPORT','54f3f4d4-01f4-11f1-b0a7-0affd1a0dee5',2),
(44,'991148',2,'2026-01-29 18:50:05','AJUSTE',1.5000,0.0000,1.5000,'IMPORT_CSV_185005',253.00,'ADMIN_IMPORT','54f3f52a-01f4-11f1-b0a7-0affd1a0dee5',2),
(45,'991149',2,'2026-01-29 18:50:05','AJUSTE',0.2500,0.0000,0.2500,'IMPORT_CSV_185005',129.00,'ADMIN_IMPORT','54f3f57f-01f4-11f1-b0a7-0affd1a0dee5',2),
(46,'991151',2,'2026-01-29 18:50:05','AJUSTE',1020.0000,0.0000,1020.0000,'IMPORT_CSV_185005',0.23,'ADMIN_IMPORT','54f3f5d5-01f4-11f1-b0a7-0affd1a0dee5',2),
(47,'991152',2,'2026-01-29 18:50:05','AJUSTE',5500.0000,0.0000,5500.0000,'IMPORT_CSV_185005',0.63,'ADMIN_IMPORT','54f3f62c-01f4-11f1-b0a7-0affd1a0dee5',2),
(48,'991153',2,'2026-01-29 18:50:05','AJUSTE',915.0000,0.0000,915.0000,'IMPORT_CSV_185005',25.00,'ADMIN_IMPORT','54f3f681-01f4-11f1-b0a7-0affd1a0dee5',2),
(49,'991154',2,'2026-01-29 18:50:05','AJUSTE',50.0000,0.0000,50.0000,'IMPORT_CSV_185005',0.20,'ADMIN_IMPORT','54f3f6d6-01f4-11f1-b0a7-0affd1a0dee5',2),
(50,'991157',2,'2026-01-29 18:50:05','AJUSTE',285.0000,0.0000,285.0000,'IMPORT_CSV_185005',35.00,'ADMIN_IMPORT','54f3f72b-01f4-11f1-b0a7-0affd1a0dee5',2),
(51,'991158',2,'2026-01-29 18:50:05','AJUSTE',725.0000,0.0000,725.0000,'IMPORT_CSV_185005',0.41,'ADMIN_IMPORT','54f3f780-01f4-11f1-b0a7-0affd1a0dee5',2),
(52,'991159',2,'2026-01-29 18:50:05','AJUSTE',345.0000,0.0000,345.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3f7d6-01f4-11f1-b0a7-0affd1a0dee5',2),
(53,'991160',2,'2026-01-29 18:50:05','AJUSTE',60.0000,0.0000,60.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3f82b-01f4-11f1-b0a7-0affd1a0dee5',2),
(54,'991161',2,'2026-01-29 18:50:05','AJUSTE',60.0000,0.0000,60.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3f881-01f4-11f1-b0a7-0affd1a0dee5',2),
(55,'991162',2,'2026-01-29 18:50:05','AJUSTE',100.0000,0.0000,100.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3f8d5-01f4-11f1-b0a7-0affd1a0dee5',2),
(56,'991163',2,'2026-01-29 18:50:05','AJUSTE',50.0000,0.0000,50.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3f92b-01f4-11f1-b0a7-0affd1a0dee5',2),
(57,'991164',2,'2026-01-29 18:50:05','AJUSTE',40.0000,0.0000,40.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3f97f-01f4-11f1-b0a7-0affd1a0dee5',2),
(58,'991165',2,'2026-01-29 18:50:05','AJUSTE',50.0000,0.0000,50.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3f9d5-01f4-11f1-b0a7-0affd1a0dee5',2),
(59,'991166',2,'2026-01-29 18:50:05','AJUSTE',810.0000,0.0000,810.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fa2d-01f4-11f1-b0a7-0affd1a0dee5',2),
(60,'991167',2,'2026-01-29 18:50:05','AJUSTE',390.0000,0.0000,390.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fa81-01f4-11f1-b0a7-0affd1a0dee5',2),
(61,'991168',2,'2026-01-29 18:50:05','AJUSTE',80.0000,0.0000,80.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fad7-01f4-11f1-b0a7-0affd1a0dee5',2),
(62,'991169',2,'2026-01-29 18:50:05','AJUSTE',855.0000,0.0000,855.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fb2f-01f4-11f1-b0a7-0affd1a0dee5',2),
(63,'991170',2,'2026-01-29 18:50:05','AJUSTE',185.0000,0.0000,185.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fb87-01f4-11f1-b0a7-0affd1a0dee5',2),
(64,'991171',2,'2026-01-29 18:50:05','AJUSTE',995.0000,0.0000,995.0000,'IMPORT_CSV_185005',7.00,'ADMIN_IMPORT','54f3fbdd-01f4-11f1-b0a7-0affd1a0dee5',2),
(65,'991172',2,'2026-01-29 18:50:05','AJUSTE',140.0000,0.0000,140.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fd53-01f4-11f1-b0a7-0affd1a0dee5',2),
(66,'991173',2,'2026-01-29 18:50:05','AJUSTE',365.0000,0.0000,365.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fdad-01f4-11f1-b0a7-0affd1a0dee5',2),
(67,'991174',2,'2026-01-29 18:50:05','AJUSTE',305.0000,0.0000,305.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fe05-01f4-11f1-b0a7-0affd1a0dee5',2),
(68,'991175',2,'2026-01-29 18:50:05','AJUSTE',95.0000,0.0000,95.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3fe5a-01f4-11f1-b0a7-0affd1a0dee5',2),
(69,'991176',2,'2026-01-29 18:50:05','AJUSTE',345.0000,0.0000,345.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3feae-01f4-11f1-b0a7-0affd1a0dee5',2),
(70,'991177',2,'2026-01-29 18:50:05','AJUSTE',110.0000,0.0000,110.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3ff06-01f4-11f1-b0a7-0affd1a0dee5',2),
(71,'991178',2,'2026-01-29 18:50:05','AJUSTE',40.0000,0.0000,40.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3ff5e-01f4-11f1-b0a7-0affd1a0dee5',2),
(72,'991179',2,'2026-01-29 18:50:05','AJUSTE',75.0000,0.0000,75.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f3ffbf-01f4-11f1-b0a7-0affd1a0dee5',2),
(73,'991180',2,'2026-01-29 18:50:05','AJUSTE',55.0000,0.0000,55.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f4002c-01f4-11f1-b0a7-0affd1a0dee5',2),
(74,'991181',2,'2026-01-29 18:50:05','AJUSTE',105.0000,0.0000,105.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f40086-01f4-11f1-b0a7-0affd1a0dee5',2),
(75,'991182',2,'2026-01-29 18:50:05','AJUSTE',90.0000,0.0000,90.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f400e4-01f4-11f1-b0a7-0affd1a0dee5',2),
(76,'991183',2,'2026-01-29 18:50:05','AJUSTE',70.0000,0.0000,70.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f4013e-01f4-11f1-b0a7-0affd1a0dee5',2),
(77,'991184',2,'2026-01-29 18:50:05','AJUSTE',3625.0000,0.0000,3625.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f4019c-01f4-11f1-b0a7-0affd1a0dee5',2),
(78,'991185',2,'2026-01-29 18:50:05','AJUSTE',26.0000,0.0000,26.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f401fa-01f4-11f1-b0a7-0affd1a0dee5',2),
(79,'991186',2,'2026-01-29 18:50:05','AJUSTE',775.0000,0.0000,775.0000,'IMPORT_CSV_185005',2.20,'ADMIN_IMPORT','54f40255-01f4-11f1-b0a7-0affd1a0dee5',2),
(80,'991187',2,'2026-01-29 18:50:05','AJUSTE',6.0000,0.0000,6.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f4045a-01f4-11f1-b0a7-0affd1a0dee5',2),
(81,'991188',2,'2026-01-29 18:50:05','AJUSTE',2.0000,0.0000,2.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f404b4-01f4-11f1-b0a7-0affd1a0dee5',2),
(82,'991189',2,'2026-01-29 18:50:05','AJUSTE',160.0000,0.0000,160.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f4050b-01f4-11f1-b0a7-0affd1a0dee5',2),
(83,'991190',2,'2026-01-29 18:50:05','AJUSTE',325.0000,0.0000,325.0000,'IMPORT_CSV_185005',2.00,'ADMIN_IMPORT','54f40562-01f4-11f1-b0a7-0affd1a0dee5',2),
(84,'660034',4,'2026-01-29 18:55:40','AJUSTE',180.0000,0.0000,180.0000,'IMPORT_CSV_185540',5.00,'ADMIN_IMPORT','54f405be-01f4-11f1-b0a7-0affd1a0dee5',4),
(85,'660029',4,'2026-01-29 18:55:40','AJUSTE',315.0000,0.0000,315.0000,'IMPORT_CSV_185540',8.00,'ADMIN_IMPORT','54f407b8-01f4-11f1-b0a7-0affd1a0dee5',4),
(86,'660035',4,'2026-01-29 18:55:40','AJUSTE',32.0000,0.0000,32.0000,'IMPORT_CSV_185540',15.00,'ADMIN_IMPORT','54f41ca3-01f4-11f1-b0a7-0affd1a0dee5',4),
(87,'660007',4,'2026-01-29 18:55:40','AJUSTE',2.0000,0.0000,2.0000,'IMPORT_CSV_185540',20.00,'ADMIN_IMPORT','54f420cb-01f4-11f1-b0a7-0affd1a0dee5',4),
(88,'660033',4,'2026-01-29 18:55:40','AJUSTE',32.0000,0.0000,32.0000,'IMPORT_CSV_185540',20.00,'ADMIN_IMPORT','54f422f8-01f4-11f1-b0a7-0affd1a0dee5',4),
(89,'660002',4,'2026-01-29 18:55:40','AJUSTE',22.0000,0.0000,22.0000,'IMPORT_CSV_185540',25.00,'ADMIN_IMPORT','54f429ac-01f4-11f1-b0a7-0affd1a0dee5',4),
(90,'660019',4,'2026-01-29 18:55:40','AJUSTE',100.0000,0.0000,100.0000,'IMPORT_CSV_185540',33.00,'ADMIN_IMPORT','54f48a62-01f4-11f1-b0a7-0affd1a0dee5',4),
(91,'660001',4,'2026-01-29 18:55:40','AJUSTE',21.0000,0.0000,21.0000,'IMPORT_CSV_185540',35.00,'ADMIN_IMPORT','54f48b98-01f4-11f1-b0a7-0affd1a0dee5',4),
(92,'660028',4,'2026-01-29 18:55:40','AJUSTE',6.0000,0.0000,6.0000,'IMPORT_CSV_185540',90.00,'ADMIN_IMPORT','54f48e56-01f4-11f1-b0a7-0affd1a0dee5',4),
(93,'660009',4,'2026-01-29 18:55:40','AJUSTE',2.0000,0.0000,2.0000,'IMPORT_CSV_185540',120.00,'ADMIN_IMPORT','54f48ef6-01f4-11f1-b0a7-0affd1a0dee5',4),
(94,'660005',4,'2026-01-29 18:55:40','AJUSTE',1.0000,0.0000,1.0000,'IMPORT_CSV_185540',130.00,'ADMIN_IMPORT','54f49160-01f4-11f1-b0a7-0affd1a0dee5',4),
(95,'660036',4,'2026-01-29 18:55:40','AJUSTE',24.0000,0.0000,24.0000,'IMPORT_CSV_185540',135.00,'ADMIN_IMPORT','54f491f4-01f4-11f1-b0a7-0affd1a0dee5',4),
(96,'660008',4,'2026-01-29 18:55:40','AJUSTE',4.0000,0.0000,4.0000,'IMPORT_CSV_185540',150.00,'ADMIN_IMPORT','54f4945b-01f4-11f1-b0a7-0affd1a0dee5',4),
(97,'660027',4,'2026-01-29 18:55:40','AJUSTE',34.0000,0.0000,34.0000,'IMPORT_CSV_185540',166.00,'ADMIN_IMPORT','54f496c7-01f4-11f1-b0a7-0affd1a0dee5',4),
(98,'660032',4,'2026-01-29 18:55:40','AJUSTE',24.0000,0.0000,24.0000,'IMPORT_CSV_185540',190.00,'ADMIN_IMPORT','54f4993c-01f4-11f1-b0a7-0affd1a0dee5',4),
(99,'660024',4,'2026-01-29 18:55:40','AJUSTE',24.0000,0.0000,24.0000,'IMPORT_CSV_185540',205.00,'ADMIN_IMPORT','54f49baf-01f4-11f1-b0a7-0affd1a0dee5',4),
(100,'660011',4,'2026-01-29 18:55:40','AJUSTE',2.0000,0.0000,2.0000,'IMPORT_CSV_185540',210.00,'ADMIN_IMPORT','54f4a2d7-01f4-11f1-b0a7-0affd1a0dee5',4),
(101,'660021',4,'2026-01-29 18:55:40','AJUSTE',24.0000,0.0000,24.0000,'IMPORT_CSV_185540',210.00,'ADMIN_IMPORT','54f4a33e-01f4-11f1-b0a7-0affd1a0dee5',4),
(102,'660022',4,'2026-01-29 18:55:40','AJUSTE',24.0000,0.0000,24.0000,'IMPORT_CSV_185540',210.00,'ADMIN_IMPORT','54f4a398-01f4-11f1-b0a7-0affd1a0dee5',4),
(103,'660037',4,'2026-01-29 18:55:40','AJUSTE',24.0000,0.0000,24.0000,'IMPORT_CSV_185540',210.00,'ADMIN_IMPORT','54f4a3f3-01f4-11f1-b0a7-0affd1a0dee5',4),
(104,'660023',4,'2026-01-29 18:55:40','AJUSTE',48.0000,0.0000,48.0000,'IMPORT_CSV_185540',213.00,'ADMIN_IMPORT','54f4a520-01f4-11f1-b0a7-0affd1a0dee5',4),
(105,'660006',4,'2026-01-29 18:55:40','AJUSTE',1.0000,0.0000,1.0000,'IMPORT_CSV_185540',230.00,'ADMIN_IMPORT','54f4a58d-01f4-11f1-b0a7-0affd1a0dee5',4),
(106,'660003',4,'2026-01-29 18:55:40','AJUSTE',2.0000,0.0000,2.0000,'IMPORT_CSV_185540',340.00,'ADMIN_IMPORT','54f4a5e6-01f4-11f1-b0a7-0affd1a0dee5',4),
(107,'660004',4,'2026-01-29 18:55:40','AJUSTE',8.0000,0.0000,8.0000,'IMPORT_CSV_185540',370.00,'ADMIN_IMPORT','54f4a63e-01f4-11f1-b0a7-0affd1a0dee5',4),
(108,'660025',4,'2026-01-29 18:55:40','AJUSTE',12.0000,0.0000,12.0000,'IMPORT_CSV_185540',430.00,'ADMIN_IMPORT','54f4a697-01f4-11f1-b0a7-0affd1a0dee5',4),
(109,'660026',4,'2026-01-29 18:55:40','AJUSTE',3.0000,0.0000,3.0000,'IMPORT_CSV_185540',430.00,'ADMIN_IMPORT','54f4a6f0-01f4-11f1-b0a7-0affd1a0dee5',4),
(110,'660020',4,'2026-01-29 18:55:40','AJUSTE',10.0000,0.0000,10.0000,'IMPORT_CSV_185540',560.00,'ADMIN_IMPORT','54f4a746-01f4-11f1-b0a7-0affd1a0dee5',4),
(111,'660018',4,'2026-01-29 18:55:40','AJUSTE',8.0000,0.0000,8.0000,'IMPORT_CSV_185540',580.00,'ADMIN_IMPORT','54f4a79e-01f4-11f1-b0a7-0affd1a0dee5',4),
(112,'660016',4,'2026-01-29 18:55:40','AJUSTE',2.0000,0.0000,2.0000,'IMPORT_CSV_185540',620.00,'ADMIN_IMPORT','54f4a7f7-01f4-11f1-b0a7-0affd1a0dee5',4),
(113,'660012',4,'2026-01-29 18:55:40','AJUSTE',33.0000,0.0000,33.0000,'IMPORT_CSV_185540',50.00,'ADMIN_IMPORT','54f4a84c-01f4-11f1-b0a7-0affd1a0dee5',4),
(114,'660013',4,'2026-01-29 18:55:40','AJUSTE',17.0000,0.0000,17.0000,'IMPORT_CSV_185540',35.00,'ADMIN_IMPORT','54f4a8a3-01f4-11f1-b0a7-0affd1a0dee5',4),
(115,'660014',4,'2026-01-29 18:55:40','AJUSTE',2.0000,0.0000,2.0000,'IMPORT_CSV_185540',220.00,'ADMIN_IMPORT','54f4a8f9-01f4-11f1-b0a7-0affd1a0dee5',4),
(116,'660015',4,'2026-01-29 18:55:40','AJUSTE',1.0000,0.0000,1.0000,'IMPORT_CSV_185540',300.00,'ADMIN_IMPORT','54f4a94f-01f4-11f1-b0a7-0affd1a0dee5',4),
(117,'660017',4,'2026-01-29 18:55:40','AJUSTE',6.0000,0.0000,6.0000,'IMPORT_CSV_185540',72.00,'ADMIN_IMPORT','54f4a9a5-01f4-11f1-b0a7-0affd1a0dee5',4),
(118,'660030',4,'2026-01-29 18:55:40','AJUSTE',3.0000,0.0000,3.0000,'IMPORT_CSV_185540',180.00,'ADMIN_IMPORT','54f4a9fb-01f4-11f1-b0a7-0affd1a0dee5',4),
(119,'660031',4,'2026-01-29 18:55:40','AJUSTE',2.0000,0.0000,2.0000,'IMPORT_CSV_185540',80.00,'ADMIN_IMPORT','54f4aa52-01f4-11f1-b0a7-0affd1a0dee5',4),
(120,'660011',4,'2026-01-29 22:53:23','ENTRADA',24.0000,2.0000,26.0000,'COMPRA #1',210.00,'Admin','54f4aaa9-01f4-11f1-b0a7-0affd1a0dee5',4),
(121,'660005',4,'2026-01-29 22:53:23','ENTRADA',24.0000,1.0000,25.0000,'COMPRA #1',130.00,'Admin','54f4ab08-01f4-11f1-b0a7-0affd1a0dee5',4),
(122,'660001',4,'2026-01-29 22:57:36','VENTA',-2.0000,21.0000,19.0000,'VENTA #1',35.00,'Eddy','54f4ab62-01f4-11f1-b0a7-0affd1a0dee5',4),
(123,'660004',4,'2026-01-29 22:57:36','VENTA',-1.0000,8.0000,7.0000,'VENTA #1',370.00,'Eddy','54f4abb9-01f4-11f1-b0a7-0affd1a0dee5',4),
(124,'660007',4,'2026-01-29 22:57:36','VENTA',-1.0000,2.0000,1.0000,'VENTA #1',20.00,'Eddy','54f4ac12-01f4-11f1-b0a7-0affd1a0dee5',4),
(125,'660008',4,'2026-01-29 22:57:36','VENTA',-1.0000,4.0000,3.0000,'VENTA #1',150.00,'Eddy','54f4ac65-01f4-11f1-b0a7-0affd1a0dee5',4),
(126,'660011',4,'2026-01-29 22:57:36','VENTA',-1.0000,26.0000,25.0000,'VENTA #1',210.00,'Eddy','54f4acba-01f4-11f1-b0a7-0affd1a0dee5',4),
(127,'660012',4,'2026-01-29 22:57:36','VENTA',-4.0000,33.0000,29.0000,'VENTA #1',50.00,'Eddy','54f4ad12-01f4-11f1-b0a7-0affd1a0dee5',4),
(128,'660013',4,'2026-01-29 22:57:36','VENTA',-7.0000,17.0000,10.0000,'VENTA #1',35.00,'Eddy','54f4ad6a-01f4-11f1-b0a7-0affd1a0dee5',4),
(129,'660017',4,'2026-01-29 22:57:36','VENTA',-2.0000,6.0000,4.0000,'VENTA #1',88.00,'Eddy','54f4ae1d-01f4-11f1-b0a7-0affd1a0dee5',4),
(130,'881108',4,'2026-01-29 22:58:19','ENTRADA',1.0000,0.0000,1.0000,'COMPRA #2',292.71,'Admin','54f4ae7b-01f4-11f1-b0a7-0affd1a0dee5',4),
(131,'660015',4,'2026-01-29 22:59:43','VENTA',-1.0000,1.0000,0.0000,'VENTA #2',300.00,'Eddy','54f4aed7-01f4-11f1-b0a7-0affd1a0dee5',4),
(132,'660021',4,'2026-01-29 22:59:43','VENTA',-1.0000,24.0000,23.0000,'VENTA #2',217.00,'Eddy','54f4af2f-01f4-11f1-b0a7-0affd1a0dee5',4),
(133,'660023',4,'2026-01-29 22:59:43','VENTA',-13.0000,48.0000,35.0000,'VENTA #2',213.00,'Eddy','54f4af84-01f4-11f1-b0a7-0affd1a0dee5',4),
(134,'660024',4,'2026-01-29 22:59:43','VENTA',-5.0000,24.0000,19.0000,'VENTA #2',205.00,'Eddy','54f4afde-01f4-11f1-b0a7-0affd1a0dee5',4),
(135,'660003',4,'2026-01-29 23:04:03','VENTA',-2.0000,2.0000,0.0000,'VENTA #3',360.00,'Eddy','54f4b034-01f4-11f1-b0a7-0affd1a0dee5',4),
(136,'660007',4,'2026-01-29 23:04:03','VENTA',-1.0000,1.0000,0.0000,'VENTA #3',20.00,'Eddy','54f4b08e-01f4-11f1-b0a7-0affd1a0dee5',4),
(137,'660013',4,'2026-01-29 23:04:03','VENTA',-4.0000,10.0000,6.0000,'VENTA #3',35.00,'Eddy','54f4b0ec-01f4-11f1-b0a7-0affd1a0dee5',4),
(138,'660014',4,'2026-01-29 23:04:03','VENTA',-2.0000,2.0000,0.0000,'VENTA #3',220.00,'Eddy','54f4b141-01f4-11f1-b0a7-0affd1a0dee5',4),
(139,'660018',4,'2026-01-29 23:04:03','VENTA',-2.0000,8.0000,6.0000,'VENTA #3',580.00,'Eddy','54f4b199-01f4-11f1-b0a7-0affd1a0dee5',4),
(140,'660001',4,'2026-01-29 23:04:11','VENTA',-1.0000,19.0000,18.0000,'VENTA #4',35.00,'Eddy','54f4b1f0-01f4-11f1-b0a7-0affd1a0dee5',4),
(141,'660022',4,'2026-01-29 23:06:02','VENTA',-1.0000,24.0000,23.0000,'VENTA #5',210.00,'Eddy','54f4b249-01f4-11f1-b0a7-0affd1a0dee5',4),
(142,'660023',4,'2026-01-29 23:06:02','VENTA',-11.0000,35.0000,24.0000,'VENTA #5',213.00,'Eddy','54f4b2a1-01f4-11f1-b0a7-0affd1a0dee5',4),
(143,'660024',4,'2026-01-29 23:06:02','VENTA',-4.0000,19.0000,15.0000,'VENTA #5',205.00,'Eddy','54f4b2f9-01f4-11f1-b0a7-0affd1a0dee5',4),
(144,'660012',4,'2026-01-29 23:06:02','VENTA',-6.0000,29.0000,23.0000,'VENTA #5',50.00,'Eddy','54f4b341-01f4-11f1-b0a7-0affd1a0dee5',4),
(145,'660029',4,'2026-01-29 23:06:02','VENTA',-5.0000,315.0000,310.0000,'VENTA #5',8.00,'Eddy','54f4b37c-01f4-11f1-b0a7-0affd1a0dee5',4),
(146,'660012',4,'2026-01-29 23:07:42','DEVOLUCION',6.0000,23.0000,29.0000,'REFUND_ITEM_22',50.00,'CAJERO_REFUND','54f4b3b5-01f4-11f1-b0a7-0affd1a0dee5',4),
(147,'660028',4,'2026-01-29 23:07:59','VENTA',-6.0000,6.0000,0.0000,'VENTA #7',90.00,'Eddy','54f4b3ef-01f4-11f1-b0a7-0affd1a0dee5',4),
(148,'660012',4,'2026-01-29 23:08:54','VENTA',-8.0000,29.0000,21.0000,'VENTA #8',50.00,'Eddy','54f4b42a-01f4-11f1-b0a7-0affd1a0dee5',4),
(149,'660004',4,'2026-01-29 23:16:39','VENTA',-3.0000,7.0000,4.0000,'VENTA #9',370.00,'Admin','54f4b46e-01f4-11f1-b0a7-0affd1a0dee5',4),
(150,'660009',4,'2026-01-29 23:16:39','VENTA',-1.0000,2.0000,1.0000,'VENTA #9',120.00,'Admin','54f4b4ef-01f4-11f1-b0a7-0affd1a0dee5',4),
(151,'660011',4,'2026-01-29 23:16:39','VENTA',-4.0000,25.0000,21.0000,'VENTA #9',210.00,'Admin','54f4b586-01f4-11f1-b0a7-0affd1a0dee5',4),
(152,'660013',4,'2026-01-29 23:16:39','VENTA',-1.0000,6.0000,5.0000,'VENTA #9',35.00,'Admin','54f4b615-01f4-11f1-b0a7-0affd1a0dee5',4),
(153,'660018',4,'2026-01-29 23:16:39','VENTA',-2.0000,6.0000,4.0000,'VENTA #9',580.00,'Admin','54f4b6aa-01f4-11f1-b0a7-0affd1a0dee5',4),
(154,'660019',4,'2026-01-29 23:16:39','VENTA',-3.0000,100.0000,97.0000,'VENTA #9',33.00,'Admin','54f4b743-01f4-11f1-b0a7-0affd1a0dee5',4),
(155,'660013',4,'2026-01-29 23:22:47','VENTA',-2.0000,5.0000,3.0000,'VENTA #10',35.00,'Admin','54f4b7dc-01f4-11f1-b0a7-0affd1a0dee5',4),
(156,'660020',4,'2026-01-29 23:22:47','VENTA',-1.0000,10.0000,9.0000,'VENTA #10',570.00,'Admin','54f4b874-01f4-11f1-b0a7-0affd1a0dee5',4),
(157,'660021',4,'2026-01-29 23:22:47','VENTA',-4.0000,23.0000,19.0000,'VENTA #10',217.00,'Admin','54f4b903-01f4-11f1-b0a7-0affd1a0dee5',4),
(158,'660022',4,'2026-01-29 23:22:47','VENTA',-4.0000,23.0000,19.0000,'VENTA #10',210.00,'Admin','54f4b996-01f4-11f1-b0a7-0affd1a0dee5',4),
(159,'660024',4,'2026-01-29 23:22:47','VENTA',-15.0000,15.0000,0.0000,'VENTA #10',205.00,'Admin','54f4ba27-01f4-11f1-b0a7-0affd1a0dee5',4),
(160,'660025',4,'2026-01-29 23:22:47','VENTA',-1.0000,12.0000,11.0000,'VENTA #10',430.00,'Admin','54f4bab6-01f4-11f1-b0a7-0affd1a0dee5',4),
(161,'660026',4,'2026-01-29 23:22:47','VENTA',-2.0000,3.0000,1.0000,'VENTA #10',430.00,'Admin','54f4bb49-01f4-11f1-b0a7-0affd1a0dee5',4),
(162,'660027',4,'2026-01-29 23:22:47','VENTA',-3.0000,34.0000,31.0000,'VENTA #10',162.57,'Admin','54f4bbd9-01f4-11f1-b0a7-0affd1a0dee5',4),
(163,'660012',4,'2026-01-29 23:22:47','VENTA',-16.0000,21.0000,5.0000,'VENTA #10',50.00,'Admin','54f4bc6f-01f4-11f1-b0a7-0affd1a0dee5',4),
(164,'660029',4,'2026-01-29 23:22:47','VENTA',-3.0000,310.0000,307.0000,'VENTA #10',8.00,'Admin','54f4bd05-01f4-11f1-b0a7-0affd1a0dee5',4),
(165,'660013',4,'2026-01-29 23:25:25','DEVOLUCION',1.0000,3.0000,4.0000,'REFUND_ITEM_30',35.00,'CAJERO_REFUND','54f4bd96-01f4-11f1-b0a7-0affd1a0dee5',4),
(166,'660017',4,'2026-01-29 23:30:04','ENTRADA',2.0000,4.0000,6.0000,'COMPRA #3',72.00,'Admin','54f4be27-01f4-11f1-b0a7-0affd1a0dee5',4),
(167,'660012',4,'2026-01-29 23:30:04','ENTRADA',29.0000,5.0000,34.0000,'COMPRA #3',50.00,'Admin','54f4beb7-01f4-11f1-b0a7-0affd1a0dee5',4),
(168,'660008',4,'2026-01-29 23:30:43','VENTA',-2.0000,3.0000,1.0000,'VENTA #12',150.00,'Admin','54f4bf49-01f4-11f1-b0a7-0affd1a0dee5',4),
(169,'660011',4,'2026-01-29 23:30:43','VENTA',-1.0000,21.0000,20.0000,'VENTA #12',210.00,'Admin','54f4bfd7-01f4-11f1-b0a7-0affd1a0dee5',4),
(170,'660013',4,'2026-01-29 23:30:43','VENTA',-2.0000,4.0000,2.0000,'VENTA #12',35.00,'Admin','54f4c069-01f4-11f1-b0a7-0affd1a0dee5',4),
(171,'660016',4,'2026-01-29 23:30:43','VENTA',-2.0000,2.0000,0.0000,'VENTA #12',620.00,'Admin','54f4c0f9-01f4-11f1-b0a7-0affd1a0dee5',4),
(172,'660019',4,'2026-01-29 23:30:43','VENTA',-6.0000,97.0000,91.0000,'VENTA #12',33.00,'Admin','54f4c18c-01f4-11f1-b0a7-0affd1a0dee5',4),
(173,'660027',4,'2026-01-29 23:30:43','VENTA',-1.0000,31.0000,30.0000,'VENTA #12',162.57,'Admin','54f4c21a-01f4-11f1-b0a7-0affd1a0dee5',4),
(174,'660029',4,'2026-01-29 23:30:43','VENTA',-6.0000,307.0000,301.0000,'VENTA #12',8.00,'Admin','54f4c2aa-01f4-11f1-b0a7-0affd1a0dee5',4),
(175,'660017',4,'2026-01-29 23:30:43','VENTA',-4.0000,6.0000,2.0000,'VENTA #12',88.00,'Admin','54f4c305-01f4-11f1-b0a7-0affd1a0dee5',4),
(176,'660030',4,'2026-01-29 23:30:43','VENTA',-2.0000,3.0000,1.0000,'VENTA #12',180.00,'Admin','54f4c35f-01f4-11f1-b0a7-0affd1a0dee5',4),
(177,'660031',4,'2026-01-29 23:30:43','VENTA',-1.0000,2.0000,1.0000,'VENTA #12',82.00,'Admin','54f4c3b4-01f4-11f1-b0a7-0affd1a0dee5',4),
(178,'660012',4,'2026-01-29 23:31:15','VENTA',-11.0000,34.0000,23.0000,'VENTA #13',50.00,'Admin','54f4c409-01f4-11f1-b0a7-0affd1a0dee5',4),
(179,'660026',4,'2026-01-29 23:34:58','ENTRADA',2.0000,1.0000,3.0000,'COMPRA #4',430.00,'Admin','54f4c461-01f4-11f1-b0a7-0affd1a0dee5',4),
(180,'660012',4,'2026-01-29 23:34:58','ENTRADA',22.0000,23.0000,45.0000,'COMPRA #4',50.00,'Admin','54f4c4b7-01f4-11f1-b0a7-0affd1a0dee5',4),
(181,'660023',4,'2026-01-29 23:34:58','ENTRADA',24.0000,24.0000,48.0000,'COMPRA #4',213.00,'Admin','54f4c50e-01f4-11f1-b0a7-0affd1a0dee5',4),
(182,'660037',4,'2026-01-29 23:34:58','ENTRADA',24.0000,24.0000,48.0000,'COMPRA #4',210.00,'Admin','54f4c565-01f4-11f1-b0a7-0affd1a0dee5',4),
(183,'660032',4,'2026-01-29 23:34:58','ENTRADA',24.0000,24.0000,48.0000,'COMPRA #4',190.00,'Admin','54f4c5bd-01f4-11f1-b0a7-0affd1a0dee5',4),
(184,'660036',4,'2026-01-29 23:34:58','ENTRADA',27.0000,24.0000,51.0000,'COMPRA #4',135.00,'Admin','54f4c614-01f4-11f1-b0a7-0affd1a0dee5',4),
(185,'660033',4,'2026-01-29 23:34:58','ENTRADA',32.0000,32.0000,64.0000,'COMPRA #4',20.00,'Admin','54f4c66d-01f4-11f1-b0a7-0affd1a0dee5',4),
(186,'660035',4,'2026-01-29 23:34:58','ENTRADA',32.0000,32.0000,64.0000,'COMPRA #4',15.00,'Admin','54f4c6c4-01f4-11f1-b0a7-0affd1a0dee5',4),
(187,'660034',4,'2026-01-29 23:34:58','ENTRADA',180.0000,180.0000,360.0000,'COMPRA #4',6.00,'Admin','54f4c71b-01f4-11f1-b0a7-0affd1a0dee5',4),
(188,'660004',4,'2026-01-29 23:38:36','VENTA',-2.0000,4.0000,2.0000,'VENTA #14',370.00,'Eddy','54f4c772-01f4-11f1-b0a7-0affd1a0dee5',4),
(189,'660006',4,'2026-01-29 23:38:36','VENTA',-1.0000,1.0000,0.0000,'VENTA #14',212.00,'Eddy','54f4c7c8-01f4-11f1-b0a7-0affd1a0dee5',4),
(190,'660031',4,'2026-01-29 23:38:36','VENTA',-1.0000,1.0000,0.0000,'VENTA #14',82.00,'Eddy','54f4c820-01f4-11f1-b0a7-0affd1a0dee5',4),
(191,'660034',4,'2026-01-29 23:38:36','VENTA',-2.0000,360.0000,358.0000,'VENTA #14',6.00,'Eddy','54f4c877-01f4-11f1-b0a7-0affd1a0dee5',4),
(192,'660036',4,'2026-01-29 23:42:59','SALIDA',-24.0000,51.0000,27.0000,'MERMA #1',135.00,'Admin','54f4c8ce-01f4-11f1-b0a7-0affd1a0dee5',4),
(193,'660011',4,'2026-01-29 23:43:34','VENTA',-1.0000,20.0000,19.0000,'VENTA #15',210.00,'Eddy','54f4c924-01f4-11f1-b0a7-0affd1a0dee5',4),
(194,'660021',4,'2026-01-29 23:43:34','VENTA',-1.0000,19.0000,18.0000,'VENTA #15',217.00,'Eddy','54f4c979-01f4-11f1-b0a7-0affd1a0dee5',4),
(195,'660022',4,'2026-01-29 23:43:34','VENTA',-1.0000,19.0000,18.0000,'VENTA #15',210.00,'Eddy','54f4c9d3-01f4-11f1-b0a7-0affd1a0dee5',4),
(196,'660026',4,'2026-01-29 23:43:34','VENTA',-1.0000,3.0000,2.0000,'VENTA #15',430.00,'Eddy','54f4ca29-01f4-11f1-b0a7-0affd1a0dee5',4),
(197,'660027',4,'2026-01-29 23:43:34','VENTA',-1.0000,30.0000,29.0000,'VENTA #15',162.57,'Eddy','54f4ca7d-01f4-11f1-b0a7-0affd1a0dee5',4),
(198,'660029',4,'2026-01-29 23:43:34','VENTA',-41.0000,301.0000,260.0000,'VENTA #15',8.00,'Eddy','54f4cad4-01f4-11f1-b0a7-0affd1a0dee5',4),
(199,'660017',4,'2026-01-29 23:43:34','VENTA',-2.0000,2.0000,0.0000,'VENTA #15',88.00,'Eddy','54f4cb2c-01f4-11f1-b0a7-0affd1a0dee5',4),
(200,'660012',4,'2026-01-29 23:43:34','VENTA',-13.0000,45.0000,32.0000,'VENTA #15',50.00,'Eddy','54f4cd73-01f4-11f1-b0a7-0affd1a0dee5',4),
(201,'660023',4,'2026-01-29 23:43:34','VENTA',-3.0000,48.0000,45.0000,'VENTA #15',213.00,'Eddy','54f4cec0-01f4-11f1-b0a7-0affd1a0dee5',4),
(202,'660036',4,'2026-01-29 23:43:34','VENTA',-2.0000,27.0000,25.0000,'VENTA #15',135.00,'Eddy','54f4cfd8-01f4-11f1-b0a7-0affd1a0dee5',4),
(203,'660012',4,'2026-01-29 23:48:01','ENTRADA',26.0000,32.0000,58.0000,'COMPRA #5',50.00,'Admin','54f4d0f2-01f4-11f1-b0a7-0affd1a0dee5',4),
(204,'660099',4,'2026-01-29 23:48:01','ENTRADA',16.0000,0.0000,16.0000,'COMPRA #5',65.00,'Admin','54f4d23b-01f4-11f1-b0a7-0affd1a0dee5',4),
(205,'660015',4,'2026-01-29 23:48:01','ENTRADA',9.0000,0.0000,9.0000,'COMPRA #5',300.00,'Admin','54f4d353-01f4-11f1-b0a7-0affd1a0dee5',4),
(206,'660014',4,'2026-01-29 23:48:01','ENTRADA',5.0000,0.0000,5.0000,'COMPRA #5',220.00,'Admin','54f4d469-01f4-11f1-b0a7-0affd1a0dee5',4),
(207,'660012',4,'2026-01-29 23:50:41','VENTA',-10.0000,58.0000,48.0000,'VENTA #16',50.00,'Eddy','54f4d581-01f4-11f1-b0a7-0affd1a0dee5',4),
(208,'660001',4,'2026-01-29 23:51:49','VENTA',-3.0000,18.0000,15.0000,'VENTA #17',35.00,'Eddy','54f4d6c9-01f4-11f1-b0a7-0affd1a0dee5',4),
(209,'660009',4,'2026-01-29 23:51:49','VENTA',-1.0000,1.0000,0.0000,'VENTA #17',120.00,'Eddy','54f4d7e0-01f4-11f1-b0a7-0affd1a0dee5',4),
(210,'660019',4,'2026-01-29 23:51:49','VENTA',-2.0000,91.0000,89.0000,'VENTA #17',33.00,'Eddy','54f4d8f4-01f4-11f1-b0a7-0affd1a0dee5',4),
(211,'660021',4,'2026-01-29 23:51:49','VENTA',-2.0000,18.0000,16.0000,'VENTA #17',217.00,'Eddy','54f4da0a-01f4-11f1-b0a7-0affd1a0dee5',4),
(212,'660022',4,'2026-01-29 23:51:49','VENTA',-2.0000,18.0000,16.0000,'VENTA #17',210.00,'Eddy','54f4db52-01f4-11f1-b0a7-0affd1a0dee5',4),
(213,'660026',4,'2026-01-29 23:51:49','VENTA',-1.0000,2.0000,1.0000,'VENTA #17',430.00,'Eddy','54f4dc6b-01f4-11f1-b0a7-0affd1a0dee5',4),
(214,'660029',4,'2026-01-29 23:53:01','VENTA',-5.0000,260.0000,255.0000,'VENTA #18',8.00,'Eddy','54f4dd81-01f4-11f1-b0a7-0affd1a0dee5',4),
(215,'660030',4,'2026-01-29 23:53:01','VENTA',-1.0000,1.0000,0.0000,'VENTA #18',180.00,'Eddy','54f4de97-01f4-11f1-b0a7-0affd1a0dee5',4),
(216,'660023',4,'2026-01-29 23:53:01','VENTA',-12.0000,45.0000,33.0000,'VENTA #18',213.00,'Eddy','54f4dfe1-01f4-11f1-b0a7-0affd1a0dee5',4),
(217,'660036',4,'2026-01-29 23:53:25','VENTA',-2.0000,25.0000,23.0000,'VENTA #19',135.00,'Eddy','54f4e0f8-01f4-11f1-b0a7-0affd1a0dee5',4),
(218,'660034',4,'2026-01-29 23:53:25','VENTA',-4.0000,358.0000,354.0000,'VENTA #19',6.00,'Eddy','54f4e545-01f4-11f1-b0a7-0affd1a0dee5',4),
(219,'660014',4,'2026-01-30 10:23:36','VENTA',-1.0000,5.0000,4.0000,'VENTA #20',220.00,'Eddy','54f4e692-01f4-11f1-b0a7-0affd1a0dee5',4),
(220,'660020',4,'2026-01-30 10:24:26','VENTA',-1.0000,9.0000,8.0000,'VENTA #21',570.00,'Eddy','54f4e7a9-01f4-11f1-b0a7-0affd1a0dee5',4),
(221,'881108',4,'2026-01-30 10:55:57','SALIDA',-1.0000,1.0000,0.0000,'MERMA #2',292.71,'Admin','54f4e8c1-01f4-11f1-b0a7-0affd1a0dee5',4),
(222,'660014',4,'2026-01-30 10:58:32','DEVOLUCION',1.0000,4.0000,5.0000,'REFUND_ITEM_81',220.00,'CAJERO_REFUND','54f4ea09-01f4-11f1-b0a7-0affd1a0dee5',4),
(223,'660020',4,'2026-01-30 10:58:37','DEVOLUCION',1.0000,8.0000,9.0000,'REFUND_ITEM_82',570.00,'CAJERO_REFUND','54f4eb21-01f4-11f1-b0a7-0affd1a0dee5',4),
(224,'756058841446',4,'2026-01-02 11:36:03','ENTRADA',2.0000,0.0000,2.0000,'COMPRA #6 (Fact: f64435)',120.00,'Admin','54f4ec3a-01f4-11f1-b0a7-0affd1a0dee5',4),
(225,'660001',4,'2026-01-31 08:24:46','VENTA',-1.0000,15.0000,14.0000,'VENTA #24',35.00,'Eddy','54f4ed86-01f4-11f1-b0a7-0affd1a0dee5',4),
(226,'660033',4,'2026-01-31 08:24:46','VENTA',-4.0000,64.0000,60.0000,'VENTA #24',20.00,'Eddy','54f4eed7-01f4-11f1-b0a7-0affd1a0dee5',4),
(227,'660004',4,'2026-01-31 08:24:46','VENTA',-1.0000,2.0000,1.0000,'VENTA #24',370.00,'Eddy','54f4f027-01f4-11f1-b0a7-0affd1a0dee5',4),
(228,'660011',4,'2026-01-31 08:24:46','VENTA',-5.0000,19.0000,14.0000,'VENTA #24',210.00,'Eddy','54f4f141-01f4-11f1-b0a7-0affd1a0dee5',4),
(229,'660020',4,'2026-01-31 08:26:41','VENTA',-1.0000,9.0000,8.0000,'VENTA #25',570.00,'Eddy','54f4f2e2-01f4-11f1-b0a7-0affd1a0dee5',4),
(230,'660019',4,'2026-01-31 08:26:41','VENTA',-6.0000,89.0000,83.0000,'VENTA #25',33.00,'Eddy','54f4f409-01f4-11f1-b0a7-0affd1a0dee5',4),
(231,'660021',4,'2026-01-31 08:26:41','VENTA',-3.0000,16.0000,13.0000,'VENTA #25',217.00,'Eddy','54f4f55a-01f4-11f1-b0a7-0affd1a0dee5',4),
(232,'660022',4,'2026-01-31 08:26:41','VENTA',-3.0000,16.0000,13.0000,'VENTA #25',210.00,'Eddy','54f4f676-01f4-11f1-b0a7-0affd1a0dee5',4),
(233,'660025',4,'2026-01-31 08:26:41','VENTA',-1.0000,11.0000,10.0000,'VENTA #25',430.00,'Eddy','54f4f7c6-01f4-11f1-b0a7-0affd1a0dee5',4),
(234,'660026',4,'2026-01-31 08:26:41','VENTA',-1.0000,1.0000,0.0000,'VENTA #25',430.00,'Eddy','54f4f8e1-01f4-11f1-b0a7-0affd1a0dee5',4),
(235,'660027',4,'2026-01-31 08:26:41','VENTA',-1.0000,29.0000,28.0000,'VENTA #25',162.57,'Eddy','54f4fe88-01f4-11f1-b0a7-0affd1a0dee5',4),
(236,'660029',4,'2026-01-31 08:26:41','VENTA',-4.0000,255.0000,251.0000,'VENTA #25',8.00,'Eddy','54f4ffd5-01f4-11f1-b0a7-0affd1a0dee5',4),
(237,'660012',4,'2026-01-31 09:02:12','VENTA',-17.0000,48.0000,31.0000,'VENTA #26',50.00,'Eddy','54f500ef-01f4-11f1-b0a7-0affd1a0dee5',4),
(238,'660099',4,'2026-01-31 09:02:12','VENTA',-10.0000,16.0000,6.0000,'VENTA #26',65.00,'Eddy','54f5020b-01f4-11f1-b0a7-0affd1a0dee5',4),
(239,'660036',4,'2026-01-31 09:02:12','VENTA',-1.0000,23.0000,22.0000,'VENTA #26',135.00,'Eddy','54f50354-01f4-11f1-b0a7-0affd1a0dee5',4),
(240,'660034',4,'2026-01-31 09:02:12','VENTA',-1.0000,354.0000,353.0000,'VENTA #26',6.00,'Eddy','54f5046f-01f4-11f1-b0a7-0affd1a0dee5',4),
(241,'660035',4,'2026-01-31 09:02:12','VENTA',-3.0000,64.0000,61.0000,'VENTA #26',15.00,'Eddy','54f50a1a-01f4-11f1-b0a7-0affd1a0dee5',4),
(242,'660023',4,'2026-01-31 09:05:09','VENTA',-9.0000,33.0000,24.0000,'VENTA #27',213.00,'Eddy','54f50b66-01f4-11f1-b0a7-0affd1a0dee5',4),
(243,'660037',4,'2026-01-31 09:05:09','VENTA',-8.0000,48.0000,40.0000,'VENTA #27',210.00,'Eddy','54f50c7c-01f4-11f1-b0a7-0affd1a0dee5',4),
(244,'660026',4,'2026-01-31 09:52:34','SALIDA',2.0000,0.0000,2.0000,'CANCELACION COMPRA #4 (POR ERROR)',430.00,'Admin','54f55fa4-01f4-11f1-b0a7-0affd1a0dee5',4),
(245,'660012',4,'2026-01-31 09:52:34','SALIDA',22.0000,31.0000,53.0000,'CANCELACION COMPRA #4 (POR ERROR)',50.00,'Admin','54f562af-01f4-11f1-b0a7-0affd1a0dee5',4),
(246,'660023',4,'2026-01-31 09:52:34','SALIDA',24.0000,24.0000,48.0000,'CANCELACION COMPRA #4 (POR ERROR)',213.00,'Admin','54f56358-01f4-11f1-b0a7-0affd1a0dee5',4),
(247,'660037',4,'2026-01-31 09:52:34','SALIDA',24.0000,40.0000,64.0000,'CANCELACION COMPRA #4 (POR ERROR)',210.00,'Admin','54f563ed-01f4-11f1-b0a7-0affd1a0dee5',4),
(248,'660032',4,'2026-01-31 09:52:34','SALIDA',24.0000,48.0000,72.0000,'CANCELACION COMPRA #4 (POR ERROR)',190.00,'Admin','54f56658-01f4-11f1-b0a7-0affd1a0dee5',4),
(249,'660036',4,'2026-01-31 09:52:34','SALIDA',27.0000,22.0000,49.0000,'CANCELACION COMPRA #4 (POR ERROR)',135.00,'Admin','54f566f1-01f4-11f1-b0a7-0affd1a0dee5',4),
(250,'660033',4,'2026-01-31 09:52:34','SALIDA',-32.0000,60.0000,92.0000,'CANCELACION COMPRA #4 (POR ERROR)',20.00,'Admin','54f569de-01f4-11f1-b0a7-0affd1a0dee5',4),
(251,'660035',4,'2026-01-31 09:52:34','SALIDA',32.0000,61.0000,93.0000,'CANCELACION COMPRA #4 (POR ERROR)',15.00,'Admin','54f56c61-01f4-11f1-b0a7-0affd1a0dee5',4),
(252,'660034',4,'2026-01-31 09:52:34','SALIDA',180.0000,353.0000,533.0000,'CANCELACION COMPRA #4 (POR ERROR)',6.00,'Admin','54f56ee2-01f4-11f1-b0a7-0affd1a0dee5',4),
(253,'756058841446',4,'2026-01-31 10:08:03','ENTRADA',2.0000,2.0000,4.0000,'COMPRA #7',120.00,'Admin','54f57162-01f4-11f1-b0a7-0affd1a0dee5',4),
(254,'756058841446',4,'2026-01-31 10:08:32','SALIDA',2.0000,4.0000,6.0000,'CANCELACION COMPRA #7 (POR ERROR)',120.00,'Admin','54f5787e-01f4-11f1-b0a7-0affd1a0dee5',4),
(255,'756058841446',4,'2026-01-31 10:09:07','SALIDA',-2.0000,6.0000,4.0000,'CANCELACION COMPRA #6 (POR ERROR)',120.00,'Admin','54f578e9-01f4-11f1-b0a7-0affd1a0dee5',4),
(256,'756058841480',4,'2026-02-01 08:39:18','ENTRADA',2300.0000,0.0000,2300.0000,'COMPRA #8',100.00,'Admin','54f57948-01f4-11f1-b0a7-0affd1a0dee5',4),
(257,'660010',4,'2026-02-02 20:38:55','ENTRADA',4.0000,0.0000,4.0000,'COMPRA #9',130.00,'Admin','54f579a6-01f4-11f1-b0a7-0affd1a0dee5',4),
(258,'660009',4,'2026-02-02 20:38:55','ENTRADA',6.0000,0.0000,6.0000,'COMPRA #9',120.00,'Admin','54f57a00-01f4-11f1-b0a7-0affd1a0dee5',4),
(259,'660017',4,'2026-02-02 20:38:55','ENTRADA',9.0000,0.0000,9.0000,'COMPRA #9',72.00,'Admin','54f57a5b-01f4-11f1-b0a7-0affd1a0dee5',4),
(260,'756058841478',4,'2026-02-02 20:38:55','ENTRADA',7.0000,0.0000,7.0000,'COMPRA #9',40.00,'Admin','54f57ab8-01f4-11f1-b0a7-0affd1a0dee5',4),
(261,'660098',4,'2026-02-02 20:38:55','ENTRADA',1.0000,0.0000,1.0000,'COMPRA #9',2000.00,'Admin','54f57b13-01f4-11f1-b0a7-0affd1a0dee5',4),
(262,'660043',4,'2026-02-02 20:38:55','ENTRADA',2.0000,0.0000,2.0000,'COMPRA #9',700.00,'Admin','54f57b6c-01f4-11f1-b0a7-0affd1a0dee5',4),
(263,'756058841480',4,'2026-02-01 20:39:04','SALIDA',-2300.0000,2300.0000,0.0000,'CANCELACION COMPRA #8 (POR ERROR)',100.00,'Admin','54f57bc8-01f4-11f1-b0a7-0affd1a0dee5',4),
(264,'660001',4,'2026-02-01 20:41:34','VENTA',-1.0000,14.0000,13.0000,'VENTA #28',35.00,'Admin','54f57c26-01f4-11f1-b0a7-0affd1a0dee5',4),
(265,'660033',4,'2026-02-01 20:41:34','VENTA',-2.0000,92.0000,90.0000,'VENTA #28',20.00,'Admin','54f57c83-01f4-11f1-b0a7-0affd1a0dee5',4),
(266,'660002',4,'2026-02-01 20:41:34','VENTA',-4.0000,22.0000,18.0000,'VENTA #28',25.00,'Admin','54f57cde-01f4-11f1-b0a7-0affd1a0dee5',4),
(267,'660004',4,'2026-02-01 20:41:34','VENTA',-1.0000,1.0000,0.0000,'VENTA #28',370.00,'Admin','54f57d37-01f4-11f1-b0a7-0affd1a0dee5',4),
(268,'660011',4,'2026-02-01 20:41:34','VENTA',-4.0000,14.0000,10.0000,'VENTA #28',210.00,'Admin','54f57d91-01f4-11f1-b0a7-0affd1a0dee5',4),
(269,'660098',4,'2026-02-01 20:54:53','VENTA',-1.0000,1.0000,0.0000,'VENTA #29',2000.00,'Admin','54f57de8-01f4-11f1-b0a7-0affd1a0dee5',4),
(270,'660043',4,'2026-02-01 20:54:53','VENTA',-1.0000,2.0000,1.0000,'VENTA #29',700.00,'Admin','54f57e42-01f4-11f1-b0a7-0affd1a0dee5',4),
(271,'660027',4,'2026-02-01 20:56:11','VENTA',-1.0000,28.0000,27.0000,'VENTA #30',162.57,'Admin','54f57e9b-01f4-11f1-b0a7-0affd1a0dee5',4),
(272,'660026',4,'2026-02-01 21:26:22','SALIDA',-2.0000,2.0000,0.0000,'CANCELACION COMPRA #4 (POR ERROR)',430.00,'Admin','54f57ef3-01f4-11f1-b0a7-0affd1a0dee5',4),
(273,'660012',4,'2026-02-01 21:26:22','SALIDA',-22.0000,53.0000,31.0000,'CANCELACION COMPRA #4 (POR ERROR)',50.00,'Admin','54f57f4e-01f4-11f1-b0a7-0affd1a0dee5',4),
(274,'660023',4,'2026-02-01 21:26:22','SALIDA',-24.0000,0.0000,-24.0000,'CANCELACION COMPRA #4 (POR ERROR)',213.00,'Admin','54f57fa8-01f4-11f1-b0a7-0affd1a0dee5',4),
(275,'660037',4,'2026-02-01 21:26:22','SALIDA',-24.0000,16.0000,-8.0000,'CANCELACION COMPRA #4 (POR ERROR)',210.00,'Admin','54f58001-01f4-11f1-b0a7-0affd1a0dee5',4),
(276,'660032',4,'2026-02-01 21:26:22','SALIDA',-24.0000,72.0000,48.0000,'CANCELACION COMPRA #4 (POR ERROR)',190.00,'Admin','54f5805a-01f4-11f1-b0a7-0affd1a0dee5',4),
(277,'660036',4,'2026-02-01 21:26:22','SALIDA',-27.0000,49.0000,22.0000,'CANCELACION COMPRA #4 (POR ERROR)',135.00,'Admin','54f580b4-01f4-11f1-b0a7-0affd1a0dee5',4),
(278,'660033',4,'2026-02-01 21:26:22','SALIDA',-32.0000,90.0000,58.0000,'CANCELACION COMPRA #4 (POR ERROR)',20.00,'Admin','54f5810d-01f4-11f1-b0a7-0affd1a0dee5',4),
(279,'660035',4,'2026-02-01 21:26:22','SALIDA',-32.0000,93.0000,61.0000,'CANCELACION COMPRA #4 (POR ERROR)',15.00,'Admin','54f58167-01f4-11f1-b0a7-0affd1a0dee5',4),
(280,'660034',4,'2026-02-01 21:26:22','SALIDA',-180.0000,533.0000,353.0000,'CANCELACION COMPRA #4 (POR ERROR)',6.00,'Admin','54f581c1-01f4-11f1-b0a7-0affd1a0dee5',4),
(281,'660019',4,'2026-02-01 21:33:07','VENTA',-2.0000,83.0000,81.0000,'VENTA #31',33.00,'Admin','54f5821b-01f4-11f1-b0a7-0affd1a0dee5',4),
(282,'660021',4,'2026-02-01 21:33:07','VENTA',-2.0000,13.0000,11.0000,'VENTA #31',217.00,'Admin','54f58274-01f4-11f1-b0a7-0affd1a0dee5',4),
(283,'660022',4,'2026-02-01 21:33:07','VENTA',-3.0000,13.0000,10.0000,'VENTA #31',210.00,'Admin','54f582cd-01f4-11f1-b0a7-0affd1a0dee5',4),
(284,'660025',4,'2026-02-01 21:33:07','VENTA',-1.0000,10.0000,9.0000,'VENTA #31',430.00,'Admin','54f58329-01f4-11f1-b0a7-0affd1a0dee5',4),
(285,'660034',4,'2026-02-01 21:33:07','VENTA',-41.0000,353.0000,312.0000,'VENTA #31',6.00,'Admin','54f58383-01f4-11f1-b0a7-0affd1a0dee5',4),
(286,'660012',4,'2026-02-01 21:33:07','VENTA',-10.0000,31.0000,21.0000,'VENTA #31',50.00,'Admin','54f583da-01f4-11f1-b0a7-0affd1a0dee5',4),
(287,'660099',4,'2026-02-01 21:33:07','VENTA',-3.0000,6.0000,3.0000,'VENTA #31',65.00,'Admin','54f58433-01f4-11f1-b0a7-0affd1a0dee5',4),
(288,'660015',4,'2026-02-01 21:35:17','VENTA',-1.0000,9.0000,8.0000,'VENTA #32',300.00,'Admin','54f5848c-01f4-11f1-b0a7-0affd1a0dee5',4),
(289,'660032',4,'2026-02-01 21:35:17','VENTA',-7.0000,48.0000,41.0000,'VENTA #32',190.00,'Admin','54f584e4-01f4-11f1-b0a7-0affd1a0dee5',4),
(290,'660036',4,'2026-02-01 21:35:17','VENTA',-1.0000,22.0000,21.0000,'VENTA #32',135.00,'Admin','54f58540-01f4-11f1-b0a7-0affd1a0dee5',4),
(291,'660035',4,'2026-02-01 21:35:17','VENTA',-10.0000,61.0000,51.0000,'VENTA #32',15.00,'Admin','54f58598-01f4-11f1-b0a7-0affd1a0dee5',4),
(292,'660017',4,'2026-02-01 21:35:17','VENTA',-2.0000,9.0000,7.0000,'VENTA #32',88.00,'Admin','54f585f7-01f4-11f1-b0a7-0affd1a0dee5',4),
(293,'756058841478',4,'2026-02-01 21:35:17','VENTA',-3.0000,7.0000,4.0000,'VENTA #32',40.00,'Admin','54f58651-01f4-11f1-b0a7-0affd1a0dee5',4),
(294,'660037',4,'2026-01-30 21:37:20','ENTRADA',24.0000,-8.0000,16.0000,'COMPRA #10 (f000)',210.00,'Admin','54f586ab-01f4-11f1-b0a7-0affd1a0dee5',4),
(295,'660097',4,'2026-01-30 21:37:20','ENTRADA',4.0000,0.0000,4.0000,'COMPRA #10 (f000)',160.00,'Admin','54f58704-01f4-11f1-b0a7-0affd1a0dee5',4),
(296,'660004',4,'2026-01-30 21:37:20','ENTRADA',1.0000,0.0000,1.0000,'COMPRA #10 (f000)',370.00,'Admin','54f5875f-01f4-11f1-b0a7-0affd1a0dee5',4),
(297,'660004',4,'2026-02-01 21:38:39','VENTA',-1.0000,1.0000,0.0000,'VENTA #33',370.00,'Admin','54f587ba-01f4-11f1-b0a7-0affd1a0dee5',4),
(298,'660037',4,'2026-02-01 21:38:39','VENTA',-16.0000,16.0000,0.0000,'VENTA #33',210.00,'Admin','54f58815-01f4-11f1-b0a7-0affd1a0dee5',4),
(299,'660097',4,'2026-02-01 21:38:39','VENTA',-1.0000,4.0000,3.0000,'VENTA #33',160.00,'Admin','54f5886e-01f4-11f1-b0a7-0affd1a0dee5',4),
(300,'660029',4,'2026-02-01 21:41:00','VENTA',-1.0000,251.0000,250.0000,'VENTA #34',8.00,'Admin','54f588c9-01f4-11f1-b0a7-0affd1a0dee5',4),
(301,'660043',4,'2026-02-02 18:41:43','ENTRADA',1.0000,1.0000,2.0000,'COMPRA #11',700.00,'Admin','54f58924-01f4-11f1-b0a7-0affd1a0dee5',4),
(302,'660012',4,'2026-02-02 18:41:43','ENTRADA',20.0000,21.0000,41.0000,'COMPRA #11',50.00,'Admin','54f58980-01f4-11f1-b0a7-0affd1a0dee5',4),
(303,'660017',4,'2026-02-02 18:41:43','ENTRADA',6.0000,7.0000,13.0000,'COMPRA #11',72.00,'Admin','54f589dc-01f4-11f1-b0a7-0affd1a0dee5',4),
(304,'660023',4,'2026-02-02 18:41:43','ENTRADA',24.0000,24.0000,48.0000,'COMPRA #11',213.00,'Admin','54f58a42-01f4-11f1-b0a7-0affd1a0dee5',4),
(305,'660002',4,'2026-02-02 18:41:43','ENTRADA',10.0000,18.0000,28.0000,'COMPRA #11',25.00,'Admin','54f58c9e-01f4-11f1-b0a7-0affd1a0dee5',4),
(306,'660096',4,'2026-02-02 18:44:49','ENTRADA',2.0000,0.0000,2.0000,'COMPRA #12',120.00,'Admin','54f58d03-01f4-11f1-b0a7-0affd1a0dee5',4),
(307,'660031',4,'2026-02-02 18:44:49','ENTRADA',3.0000,0.0000,3.0000,'COMPRA #12',80.00,'Admin','54f58d60-01f4-11f1-b0a7-0affd1a0dee5',4),
(308,'660017',4,'2026-02-02 18:45:38','SALIDA',-3.0000,13.0000,10.0000,'MERMA #3',72.00,'Admin','54f58dbb-01f4-11f1-b0a7-0affd1a0dee5',4),
(309,'660094',4,'2026-02-03 19:09:47','ENTRADA',2.0000,0.0000,2.0000,'COMPRA #13',200.00,'Admin','54f58e18-01f4-11f1-b0a7-0affd1a0dee5',4),
(310,'756058841478',4,'2026-02-03 19:09:47','ENTRADA',4.0000,4.0000,8.0000,'COMPRA #13',40.00,'Admin','54f58e75-01f4-11f1-b0a7-0affd1a0dee5',4),
(311,'660031',4,'2026-02-03 19:09:47','ENTRADA',2.0000,3.0000,5.0000,'COMPRA #13',80.00,'Admin','54f58f29-01f4-11f1-b0a7-0affd1a0dee5',4),
(312,'660012',4,'2026-02-03 19:09:47','ENTRADA',12.0000,41.0000,53.0000,'COMPRA #13',50.00,'Admin','54f58f7d-01f4-11f1-b0a7-0affd1a0dee5',4),
(313,'660095',4,'2026-02-03 19:09:47','ENTRADA',4.0000,0.0000,4.0000,'COMPRA #13',70.00,'Admin','54f58fd9-01f4-11f1-b0a7-0affd1a0dee5',4),
(314,'660093',4,'2026-02-03 19:09:47','ENTRADA',2.0000,0.0000,2.0000,'COMPRA #13',180.00,'Admin','54f59034-01f4-11f1-b0a7-0affd1a0dee5',4),
(315,'660026',4,'2026-02-03 19:09:47','ENTRADA',3.0000,0.0000,3.0000,'COMPRA #13',430.00,'Admin','54f59091-01f4-11f1-b0a7-0affd1a0dee5',4),
(316,'660017',4,'2026-02-03 19:09:47','ENTRADA',8.0000,10.0000,18.0000,'COMPRA #13',72.00,'Admin','54f590ea-01f4-11f1-b0a7-0affd1a0dee5',4),
(317,'660037',4,'2026-02-03 19:09:47','ENTRADA',24.0000,48.0000,72.0000,'COMPRA #13',210.00,'Admin','54f59146-01f4-11f1-b0a7-0affd1a0dee5',4),
(318,'990091',4,'2026-02-03 19:09:47','ENTRADA',24.0000,0.0000,24.0000,'COMPRA #13',210.00,'Admin','54f591a3-01f4-11f1-b0a7-0affd1a0dee5',4),
(319,'660002',4,'2026-02-02 19:12:03','VENTA',-1.0000,28.0000,27.0000,'VENTA #35',25.00,'Eddy','54f591ff-01f4-11f1-b0a7-0affd1a0dee5',4),
(320,'660019',4,'2026-02-02 19:12:03','VENTA',-6.0000,81.0000,75.0000,'VENTA #35',33.00,'Eddy','54f59259-01f4-11f1-b0a7-0affd1a0dee5',4),
(321,'660015',4,'2026-02-02 19:12:03','VENTA',-2.0000,8.0000,6.0000,'VENTA #35',300.00,'Eddy','54f592b3-01f4-11f1-b0a7-0affd1a0dee5',4),
(322,'660097',4,'2026-02-02 19:12:03','VENTA',-3.0000,3.0000,0.0000,'VENTA #35',160.00,'Eddy','54f5930d-01f4-11f1-b0a7-0affd1a0dee5',4),
(323,'660009',4,'2026-02-02 19:12:03','VENTA',-2.0000,6.0000,4.0000,'VENTA #35',120.00,'Eddy','54f59367-01f4-11f1-b0a7-0affd1a0dee5',4),
(324,'660012',4,'2026-02-02 19:12:03','VENTA',-5.0000,53.0000,48.0000,'VENTA #35',50.00,'Eddy','54f593be-01f4-11f1-b0a7-0affd1a0dee5',4),
(325,'660095',4,'2026-02-02 19:12:19','VENTA',-4.0000,4.0000,0.0000,'VENTA #36',70.00,'Eddy','54f59418-01f4-11f1-b0a7-0affd1a0dee5',4),
(326,'660012',4,'2026-02-02 19:16:32','SALIDA',-22.0000,48.0000,26.0000,'MERMA #4',50.00,'Admin','54f59471-01f4-11f1-b0a7-0affd1a0dee5',4),
(327,'660033',4,'2026-01-31 19:20:45','ENTRADA',32.0000,-6.0000,26.0000,'COMPRA #14',20.00,'Admin','54f594cb-01f4-11f1-b0a7-0affd1a0dee5',4),
(328,'660033',4,'2026-02-02 19:25:31','VENTA',-2.0000,26.0000,24.0000,'VENTA #37',20.00,'Eddy','54f59524-01f4-11f1-b0a7-0affd1a0dee5',4),
(329,'660001',4,'2026-02-02 19:25:31','VENTA',-1.0000,13.0000,12.0000,'VENTA #37',35.00,'Eddy','54f5957d-01f4-11f1-b0a7-0affd1a0dee5',4),
(330,'660021',4,'2026-02-02 19:25:31','VENTA',-2.0000,11.0000,9.0000,'VENTA #37',217.00,'Eddy','54f595d6-01f4-11f1-b0a7-0affd1a0dee5',4),
(331,'660022',4,'2026-02-02 19:25:31','VENTA',-10.0000,10.0000,0.0000,'VENTA #37',210.00,'Eddy','54f59630-01f4-11f1-b0a7-0affd1a0dee5',4),
(332,'660026',4,'2026-02-02 19:25:31','VENTA',-1.0000,3.0000,2.0000,'VENTA #37',430.00,'Eddy','54f59687-01f4-11f1-b0a7-0affd1a0dee5',4),
(333,'660027',4,'2026-02-02 19:25:32','VENTA',-1.0000,27.0000,26.0000,'VENTA #37',162.57,'Eddy','54f596e0-01f4-11f1-b0a7-0affd1a0dee5',4),
(334,'660034',4,'2026-02-02 19:25:32','VENTA',-5.0000,312.0000,307.0000,'VENTA #37',6.00,'Eddy','54f59739-01f4-11f1-b0a7-0affd1a0dee5',4),
(335,'660099',4,'2026-02-02 19:25:32','VENTA',-2.0000,3.0000,1.0000,'VENTA #37',65.00,'Eddy','54f59791-01f4-11f1-b0a7-0affd1a0dee5',4),
(336,'660014',4,'2026-02-02 19:25:32','VENTA',-1.0000,5.0000,4.0000,'VENTA #37',220.00,'Eddy','54f597e8-01f4-11f1-b0a7-0affd1a0dee5',4),
(337,'660017',4,'2026-02-02 19:25:32','VENTA',-7.0000,18.0000,11.0000,'VENTA #37',88.00,'Eddy','54f59844-01f4-11f1-b0a7-0affd1a0dee5',4),
(338,'660037',4,'2026-02-02 19:25:32','VENTA',-11.0000,72.0000,61.0000,'VENTA #37',210.00,'Eddy','54f598a1-01f4-11f1-b0a7-0affd1a0dee5',4),
(339,'990091',4,'2026-02-02 19:25:32','VENTA',-11.0000,24.0000,13.0000,'VENTA #37',210.00,'Eddy','54f598fd-01f4-11f1-b0a7-0affd1a0dee5',4),
(340,'660036',4,'2026-02-02 19:25:32','VENTA',-7.0000,21.0000,14.0000,'VENTA #37',135.00,'Eddy','54f59954-01f4-11f1-b0a7-0affd1a0dee5',4),
(341,'660035',4,'2026-02-02 19:25:32','VENTA',-1.0000,51.0000,50.0000,'VENTA #37',15.00,'Eddy','54f599ac-01f4-11f1-b0a7-0affd1a0dee5',4),
(342,'756058841478',4,'2026-02-02 19:25:32','VENTA',-2.0000,8.0000,6.0000,'VENTA #37',40.00,'Eddy','54f59a07-01f4-11f1-b0a7-0affd1a0dee5',4),
(343,'660031',4,'2026-02-02 19:25:54','VENTA',-2.0000,5.0000,3.0000,'VENTA #38',82.00,'Eddy','54f59a60-01f4-11f1-b0a7-0affd1a0dee5',4),
(344,'660020',4,'2026-02-02 19:42:25','SALIDA',-1.0000,8.0000,7.0000,'MERMA #5',560.00,'Admin','54f59ab8-01f4-11f1-b0a7-0affd1a0dee5',4),
(345,'660011',4,'2026-02-04 08:26:07','VENTA',-2.0000,10.0000,8.0000,'VENTA #39',210.00,'Eddy','54f59b11-01f4-11f1-b0a7-0affd1a0dee5',4),
(346,'660021',4,'2026-02-04 08:26:07','VENTA',-1.0000,9.0000,8.0000,'VENTA #39',217.00,'Eddy','54f59b6a-01f4-11f1-b0a7-0affd1a0dee5',4),
(347,'990091',4,'2026-02-04 08:26:07','VENTA',-4.0000,13.0000,9.0000,'VENTA #39',210.00,'Eddy','54f59bc2-01f4-11f1-b0a7-0affd1a0dee5',4),
(348,'660036',4,'2026-02-04 08:26:07','VENTA',-3.0000,14.0000,11.0000,'VENTA #39',135.00,'Eddy','54f59c1c-01f4-11f1-b0a7-0affd1a0dee5',4),
(349,'660025',4,'2026-02-04 08:26:07','VENTA',-2.0000,9.0000,7.0000,'VENTA #39',430.00,'Eddy','54f59c76-01f4-11f1-b0a7-0affd1a0dee5',4),
(350,'660019',4,'2026-02-04 08:40:54','VENTA',-4.0000,75.0000,71.0000,'VENTA #40',33.00,'Eddy','54f59cce-01f4-11f1-b0a7-0affd1a0dee5',4),
(351,'660026',4,'2026-02-04 08:40:54','VENTA',-2.0000,2.0000,0.0000,'VENTA #40',430.00,'Eddy','54f59d29-01f4-11f1-b0a7-0affd1a0dee5',4),
(352,'660027',4,'2026-02-04 08:40:54','VENTA',-6.0000,26.0000,20.0000,'VENTA #40',162.57,'Eddy','54f59d80-01f4-11f1-b0a7-0affd1a0dee5',4),
(353,'660034',4,'2026-02-04 08:40:54','VENTA',-4.0000,127.0000,123.0000,'VENTA #40',6.00,'Eddy','54f59dd7-01f4-11f1-b0a7-0affd1a0dee5',4),
(354,'660099',4,'2026-02-04 08:40:54','VENTA',-1.0000,1.0000,0.0000,'VENTA #40',65.00,'Eddy','54f59f7b-01f4-11f1-b0a7-0affd1a0dee5',4),
(355,'660015',4,'2026-02-04 08:40:54','VENTA',-1.0000,6.0000,5.0000,'VENTA #40',300.00,'Eddy','54f59fd9-01f4-11f1-b0a7-0affd1a0dee5',4),
(356,'660014',4,'2026-02-04 08:40:54','VENTA',-1.0000,4.0000,3.0000,'VENTA #40',220.00,'Eddy','54f5a032-01f4-11f1-b0a7-0affd1a0dee5',4),
(357,'660017',4,'2026-02-04 08:40:54','VENTA',-6.0000,11.0000,5.0000,'VENTA #40',88.00,'Eddy','54f5a08a-01f4-11f1-b0a7-0affd1a0dee5',4),
(358,'756058841478',4,'2026-02-04 08:40:54','VENTA',-2.0000,2.0000,0.0000,'VENTA #40',40.00,'Eddy','54f5a0e3-01f4-11f1-b0a7-0affd1a0dee5',4),
(359,'660094',4,'2026-02-04 08:40:54','VENTA',-2.0000,2.0000,0.0000,'VENTA #40',200.00,'Eddy','54f5a13d-01f4-11f1-b0a7-0affd1a0dee5',4),
(360,'660012',4,'2026-02-04 08:40:54','VENTA',-7.0000,7.0000,0.0000,'VENTA #40',50.00,'Eddy','54f5a198-01f4-11f1-b0a7-0affd1a0dee5',4),
(361,'660043',4,'2026-02-04 08:40:54','VENTA',-1.0000,2.0000,1.0000,'VENTA #40',700.00,'Eddy','54f5a1ef-01f4-11f1-b0a7-0affd1a0dee5',4),
(362,'660010',4,'2026-02-04 08:40:54','VENTA',-1.0000,4.0000,3.0000,'VENTA #40',130.00,'Eddy','54f5a248-01f4-11f1-b0a7-0affd1a0dee5',4),
(363,'660033',4,'2026-02-04 09:40:34','VENTA',-3.0000,24.0000,21.0000,'VENTA #41',20.00,'Eddy','54f5a2a5-01f4-11f1-b0a7-0affd1a0dee5',4),
(364,'660011',4,'2026-02-04 09:40:34','VENTA',-1.0000,8.0000,7.0000,'VENTA #41',210.00,'Eddy','54f5a301-01f4-11f1-b0a7-0affd1a0dee5',4),
(365,'660037',4,'2026-02-04 09:40:34','VENTA',-2.0000,61.0000,59.0000,'VENTA #41',210.00,'Eddy','54f5a35a-01f4-11f1-b0a7-0affd1a0dee5',4),
(366,'660025',4,'2026-02-04 09:40:34','VENTA',-1.0000,7.0000,6.0000,'VENTA #41',430.00,'Eddy','54f5a3b3-01f4-11f1-b0a7-0affd1a0dee5',4),
(367,'660029',4,'2026-02-04 09:40:34','VENTA',-6.0000,250.0000,244.0000,'VENTA #41',8.00,'Eddy','54f5a40c-01f4-11f1-b0a7-0affd1a0dee5',4),
(368,'660034',4,'2026-02-04 09:40:34','VENTA',-29.0000,123.0000,94.0000,'VENTA #41',6.00,'Eddy','54f5a466-01f4-11f1-b0a7-0affd1a0dee5',4),
(369,'660017',4,'2026-02-04 09:40:34','VENTA',-3.0000,5.0000,2.0000,'VENTA #41',88.00,'Eddy','54f5a4bd-01f4-11f1-b0a7-0affd1a0dee5',4),
(370,'660093',4,'2026-02-04 09:40:34','VENTA',-1.0000,2.0000,1.0000,'VENTA #41',180.00,'Eddy','54f5a513-01f4-11f1-b0a7-0affd1a0dee5',4),
(371,'660012',4,'2026-02-04 09:40:34','VENTA',-10.0000,0.0000,-10.0000,'VENTA #41',50.00,'Eddy','54f5a56d-01f4-11f1-b0a7-0affd1a0dee5',4),
(372,'660096',4,'2026-02-04 09:40:34','VENTA',-1.0000,2.0000,1.0000,'VENTA #41',120.00,'Eddy','54f5a5c8-01f4-11f1-b0a7-0affd1a0dee5',4),
(373,'660043',4,'2026-02-04 09:40:34','VENTA',-1.0000,1.0000,0.0000,'VENTA #41',700.00,'Eddy','54f5a620-01f4-11f1-b0a7-0affd1a0dee5',4),
(374,'660021',4,'2026-02-04 09:40:34','VENTA',-1.0000,8.0000,7.0000,'VENTA #41',217.00,'Eddy','54f5a67a-01f4-11f1-b0a7-0affd1a0dee5',4),
(375,'660036',4,'2026-02-04 09:55:48','ENTRADA',3.0000,11.0000,14.0000,'COMPRA #15',135.00,'Admin','54f5a6d2-01f4-11f1-b0a7-0affd1a0dee5',4),
(376,'660018',4,'2026-02-04 09:57:03','SALIDA',-2.0000,4.0000,2.0000,'MERMA #6',580.00,'Admin','54f5a72b-01f4-11f1-b0a7-0affd1a0dee5',4),
(377,'660032',4,'2026-02-04 09:58:42','SALIDA',-24.0000,41.0000,17.0000,'MERMA #7',190.00,'Admin','54f5a785-01f4-11f1-b0a7-0affd1a0dee5',4),
(378,'660037',4,'2026-02-04 10:00:47','SALIDA',-48.0000,59.0000,11.0000,'MERMA #8',210.00,'Admin','54f5a7dc-01f4-11f1-b0a7-0affd1a0dee5',4),
(379,'660023',4,'2026-02-04 10:01:18','SALIDA',-24.0000,48.0000,24.0000,'MERMA #9',213.00,'Admin','54f5a837-01f4-11f1-b0a7-0affd1a0dee5',4),
(380,'660034',4,'2026-02-04 10:01:52','SALIDA',-180.0000,274.0000,94.0000,'MERMA #10',6.00,'Admin','54f5a892-01f4-11f1-b0a7-0affd1a0dee5',4),
(381,'660035',4,'2026-02-04 10:02:42','SALIDA',-29.0000,50.0000,21.0000,'MERMA #11',15.00,'Admin','54f5a8ea-01f4-11f1-b0a7-0affd1a0dee5',4),
(382,'756058841478',4,'2026-02-04 10:22:30','SALIDA',-4.0000,4.0000,0.0000,'MERMA #12',40.00,'Admin','54f5a940-01f4-11f1-b0a7-0affd1a0dee5',4),
(383,'660012',4,'2026-02-04 10:24:06','ENTRADA',1.0000,9.0000,10.0000,'COMPRA #16',50.00,'Admin','54f5a999-01f4-11f1-b0a7-0affd1a0dee5',4),
(384,'660033',4,'2026-02-04 10:40:12','VENTA',-1.0000,21.0000,20.0000,'VENTA #42',20.00,'Eddy','54f5a9f1-01f4-11f1-b0a7-0affd1a0dee5',4),
(385,'660021',4,'2026-02-04 10:40:12','VENTA',-3.0000,7.0000,4.0000,'VENTA #42',217.00,'Eddy','54f5aa4a-01f4-11f1-b0a7-0affd1a0dee5',4),
(386,'660025',4,'2026-02-04 10:40:12','VENTA',-1.0000,6.0000,5.0000,'VENTA #42',430.00,'Eddy','54f5aaa5-01f4-11f1-b0a7-0affd1a0dee5',4),
(387,'660027',4,'2026-02-04 10:40:12','VENTA',-1.0000,20.0000,19.0000,'VENTA #42',162.57,'Eddy','54f5ab01-01f4-11f1-b0a7-0affd1a0dee5',4),
(388,'660034',4,'2026-02-04 10:40:12','VENTA',-8.0000,94.0000,86.0000,'VENTA #42',6.00,'Eddy','54f5ab5c-01f4-11f1-b0a7-0affd1a0dee5',4),
(389,'660017',4,'2026-02-04 10:40:12','VENTA',-1.0000,2.0000,1.0000,'VENTA #42',88.00,'Eddy','54f5ad87-01f4-11f1-b0a7-0affd1a0dee5',4),
(390,'660031',4,'2026-02-04 10:40:12','VENTA',-2.0000,3.0000,1.0000,'VENTA #42',82.00,'Eddy','54f5ade2-01f4-11f1-b0a7-0affd1a0dee5',4),
(391,'660035',4,'2026-02-04 10:40:12','VENTA',-4.0000,21.0000,17.0000,'VENTA #42',15.00,'Eddy','54f5ae3b-01f4-11f1-b0a7-0affd1a0dee5',4),
(392,'660012',4,'2026-02-03 10:51:07','ENTRADA',28.0000,10.0000,38.0000,'COMPRA #17',50.00,'Admin','54f5ae94-01f4-11f1-b0a7-0affd1a0dee5',4),
(393,'660090',4,'2026-02-03 10:51:07','ENTRADA',4.0000,0.0000,4.0000,'COMPRA #17',1150.00,'Admin','54f5aeee-01f4-11f1-b0a7-0affd1a0dee5',4),
(394,'660016',4,'2026-02-03 10:51:07','ENTRADA',3.0000,0.0000,3.0000,'COMPRA #17',620.00,'Admin','54f5af4a-01f4-11f1-b0a7-0affd1a0dee5',4),
(395,'660089',4,'2026-02-03 10:51:07','ENTRADA',24.0000,0.0000,24.0000,'COMPRA #17',20.00,'Admin','54f5afa3-01f4-11f1-b0a7-0affd1a0dee5',4),
(396,'660088',4,'2026-02-03 10:51:07','ENTRADA',10.0000,0.0000,10.0000,'COMPRA #17',18.00,'Admin','54f5affe-01f4-11f1-b0a7-0affd1a0dee5',4),
(397,'660087',4,'2026-02-03 10:51:07','ENTRADA',7.0000,0.0000,7.0000,'COMPRA #17',143.00,'Admin','54f5b058-01f4-11f1-b0a7-0affd1a0dee5',4),
(398,'660087',4,'2026-02-03 10:51:07','ENTRADA',25.0000,7.0000,32.0000,'COMPRA #17',40.00,'Admin','54f5b0b0-01f4-11f1-b0a7-0affd1a0dee5',4),
(399,'660100',4,'2026-02-03 10:59:41','ENTRADA',25.0000,0.0000,25.0000,'COMPRA #18',40.00,'Admin','54f5b10c-01f4-11f1-b0a7-0affd1a0dee5',4),
(400,'660007',4,'2026-02-03 10:59:41','ENTRADA',70.0000,0.0000,70.0000,'COMPRA #18',20.00,'Admin','54f5b164-01f4-11f1-b0a7-0affd1a0dee5',4),
(401,'660101',4,'2026-02-03 10:59:41','ENTRADA',8.0000,0.0000,8.0000,'COMPRA #18',25.00,'Admin','54f5b1bd-01f4-11f1-b0a7-0affd1a0dee5',4),
(402,'660102',4,'2026-02-03 10:59:41','ENTRADA',3.0000,0.0000,3.0000,'COMPRA #18',170.00,'Admin','54f5b217-01f4-11f1-b0a7-0affd1a0dee5',4),
(403,'660103',4,'2026-02-03 10:59:41','ENTRADA',10.0000,0.0000,10.0000,'COMPRA #18',130.00,'Admin','54f5b26e-01f4-11f1-b0a7-0affd1a0dee5',4),
(404,'660104',4,'2026-02-03 11:04:23','ENTRADA',10.0000,0.0000,10.0000,'COMPRA #19',120.00,'Admin','54f5b2c8-01f4-11f1-b0a7-0affd1a0dee5',4),
(405,'660012',4,'2026-02-04 11:08:00','VENTA',-9.0000,38.0000,29.0000,'VENTA #43',50.00,'Eddy','54f5b320-01f4-11f1-b0a7-0affd1a0dee5',4),
(406,'660016',4,'2026-02-04 11:08:00','VENTA',-1.0000,3.0000,2.0000,'VENTA #43',620.00,'Eddy','54f5b379-01f4-11f1-b0a7-0affd1a0dee5',4),
(407,'660007',4,'2026-02-04 11:08:00','VENTA',-1.0000,70.0000,69.0000,'VENTA #43',20.00,'Eddy','54f5b3d2-01f4-11f1-b0a7-0affd1a0dee5',4),
(408,'660017',4,'2026-02-04 11:21:13','VENTA',-1.0000,1.0000,0.0000,'VENTA #44',88.00,'Eddy','54f5b42c-01f4-11f1-b0a7-0affd1a0dee5',4),
(409,'660033',4,'2026-02-06 11:00:24','VENTA',-2.0000,0.0000,0.0000,'VENTA #70',20.00,'Eddy','f28dccc0-0374-11f1-b0a7-0affd1a0dee5',4),
(410,'660011',4,'2026-02-06 12:10:31','VENTA',-2.0000,7.0000,5.0000,'VENTA #71',210.00,'Sistema','84d9f676-f7b6-48eb-b31e-48a43588d60b',4),
(411,'660033',4,'2026-02-06 16:02:51','VENTA',-2.0000,18.0000,16.0000,'VENTA #72',20.00,'Sistema','e912fa71-885b-432a-901d-969b92a37d2a',4),
(412,'660011',4,'2026-02-06 16:02:51','VENTA',-2.0000,5.0000,3.0000,'VENTA #72',210.00,'Sistema','5f37d981-bc9e-40e0-a077-5381bed96cb9',4),
(413,'660021',4,'2026-02-06 16:02:51','VENTA',-2.0000,4.0000,2.0000,'VENTA #72',217.00,'Sistema','0fc10a02-fc41-486e-b129-edf921d6af4e',4),
(414,'660037',4,'2026-02-06 16:02:51','VENTA',-10.0000,11.0000,1.0000,'VENTA #72',210.00,'Sistema','621a0de6-a82c-4196-96a4-93a97ac6af22',4),
(415,'990091',4,'2026-02-06 16:02:51','VENTA',-8.0000,9.0000,1.0000,'VENTA #73',210.00,'Sistema','c04a4f28-3b63-429c-9441-ef8db57e47d3',4),
(416,'660033',4,'2026-02-07 09:02:05','DEVOLUCION',2.0000,0.0000,0.0000,'REFUND_ITEM_219',20.00,'CAJERO_REFUND','959d117f-042d-11f1-b0a7-0affd1a0dee5',4),
(417,'660011',4,'2026-02-07 09:07:50','DEVOLUCION',2.0000,3.0000,5.0000,'REFUND_ITEM_220',210.00,'CAJERO_REFUND','kdx_6987473641b1e',4),
(418,'660023',4,'2026-02-07 09:45:26','VENTA',-9.0000,24.0000,15.0000,'VENTA #86',213.00,'Sistema','kdx_69875006ca84c',4),
(419,'660036',4,'2026-02-07 09:45:26','VENTA',-8.0000,14.0000,6.0000,'VENTA #86',135.00,'Sistema','kdx_69875006ccb6e',4),
(420,'660025',4,'2026-02-07 09:46:06','VENTA',-2.0000,5.0000,3.0000,'VENTA #87',430.00,'Sistema','kdx_6987502eec81f',4),
(421,'660020',4,'2026-02-07 09:47:06','VENTA',-1.0000,7.0000,6.0000,'VENTA #88',570.00,'Sistema','kdx_6987506adf187',4),
(422,'660027',4,'2026-02-07 09:47:06','VENTA',-1.0000,19.0000,18.0000,'VENTA #88',162.57,'Sistema','kdx_6987506adfb74',4),
(423,'660027',4,'2026-02-04 09:48:07','ENTRADA',24.0000,18.0000,42.0000,'COMPRA #20',160.00,'Admin','kdx_698750a756b08',4),
(424,'660006',4,'2026-02-04 09:48:07','ENTRADA',12.0000,0.0000,12.0000,'COMPRA #20',212.00,'Admin','kdx_698750a7587f9',4),
(425,'660034',4,'2026-02-07 09:49:29','VENTA',-20.0000,86.0000,66.0000,'VENTA #89',6.00,'Sistema','kdx_698750f958485',4),
(426,'660015',4,'2026-02-07 09:49:29','VENTA',-2.0000,5.0000,3.0000,'VENTA #89',300.00,'Sistema','kdx_698750f958f3b',4),
(427,'660014',4,'2026-02-07 09:49:29','VENTA',-2.0000,3.0000,1.0000,'VENTA #89',220.00,'Sistema','kdx_698750f95989c',4),
(428,'660009',4,'2026-02-07 09:49:29','VENTA',-1.0000,4.0000,3.0000,'VENTA #89',120.00,'Sistema','kdx_698750f95a21b',4),
(429,'660093',4,'2026-02-07 09:49:29','VENTA',-1.0000,1.0000,0.0000,'VENTA #89',180.00,'Sistema','kdx_698750f95ab3c',4),
(430,'660010',4,'2026-02-07 09:49:29','VENTA',-2.0000,3.0000,1.0000,'VENTA #89',130.00,'Sistema','kdx_698750f95b41d',4),
(431,'660012',4,'2026-02-07 09:50:01','VENTA',-17.0000,29.0000,12.0000,'VENTA #90',50.00,'Sistema','kdx_698751193d302',4),
(432,'660090',4,'2026-02-07 09:50:14','VENTA',-1.0000,4.0000,3.0000,'VENTA #91',1150.00,'Sistema','kdx_698751263c094',4),
(433,'660088',4,'2026-02-07 09:50:38','VENTA',-2.0000,10.0000,8.0000,'VENTA #92',18.00,'Sistema','kdx_6987513eefb80',4),
(434,'660089',4,'2026-02-07 09:50:49','VENTA',-2.0000,24.0000,22.0000,'VENTA #93',20.00,'Sistema','kdx_69875149b621c',4),
(435,'660087',4,'2026-02-07 09:57:23','SALIDA',-25.0000,32.0000,7.0000,'MERMA #14',62.53,'Admin','kdx_698752d3bd385',4),
(436,'660087',4,'2026-02-07 09:58:21','VENTA',-3.0000,7.0000,4.0000,'VENTA #94',62.53,'Sistema','kdx_6987530de8916',4),
(437,'660100',4,'2026-02-07 09:58:59','VENTA',-3.0000,25.0000,22.0000,'VENTA #95',40.00,'Sistema','kdx_69875333392d5',4),
(438,'660007',4,'2026-02-07 09:59:05','VENTA',-2.0000,69.0000,67.0000,'VENTA #96',20.00,'Sistema','kdx_698753392d796',4),
(439,'660102',4,'2026-02-07 09:59:26','VENTA',-1.0000,3.0000,2.0000,'VENTA #97',170.00,'Sistema','kdx_6987534e087f5',4),
(440,'660103',4,'2026-02-07 09:59:26','VENTA',-1.0000,10.0000,9.0000,'VENTA #97',130.00,'Sistema','kdx_6987534e092a2',4),
(441,'660006',4,'2026-02-07 09:59:36','VENTA',-1.0000,12.0000,11.0000,'VENTA #98',212.00,'Sistema','kdx_6987535864d3a',4),
(442,'660001',4,'2026-02-07 10:18:53','VENTA',-2.0000,12.0000,10.0000,'VENTA #99',35.00,'Sistema','kdx_698757dd90381',4),
(443,'660033',4,'2026-02-07 10:18:53','VENTA',-1.0000,18.0000,17.0000,'VENTA #99',20.00,'Sistema','kdx_698757dd90e2d',4),
(444,'660011',4,'2026-02-07 10:19:40','VENTA',-1.0000,5.0000,4.0000,'VENTA #100',210.00,'Sistema','kdx_6987580cc1923',4),
(445,'660021',4,'2026-02-07 10:19:40','VENTA',-2.0000,2.0000,0.0000,'VENTA #100',217.00,'Sistema','kdx_6987580cc2307',4),
(446,'660037',4,'2026-02-07 10:19:40','VENTA',-1.0000,1.0000,0.0000,'VENTA #100',210.00,'Sistema','kdx_6987580cc2bf3',4),
(447,'990091',4,'2026-02-07 10:20:45','VENTA',-1.0000,1.0000,0.0000,'VENTA #101',210.00,'Sistema','kdx_6987584daba07',4),
(448,'660023',4,'2026-02-07 10:27:03','VENTA',-1.0000,15.0000,14.0000,'VENTA #102',213.00,'Sistema','kdx_698759c757a4c',4),
(449,'660036',4,'2026-02-07 10:27:03','VENTA',-6.0000,6.0000,0.0000,'VENTA #102',135.00,'Sistema','kdx_698759c7583ac',4),
(450,'660025',4,'2026-02-07 10:27:03','VENTA',-2.0000,3.0000,1.0000,'VENTA #102',430.00,'Sistema','kdx_698759c758c35',4),
(451,'660019',4,'2026-02-07 10:27:03','VENTA',-4.0000,71.0000,67.0000,'VENTA #102',33.00,'Sistema','kdx_698759c7594c0',4),
(452,'660029',4,'2026-02-07 10:28:05','VENTA',-4.0000,244.0000,240.0000,'VENTA #103',8.00,'Sistema','kdx_69875a05462e0',4),
(453,'660034',4,'2026-02-07 10:28:05','VENTA',-5.0000,66.0000,61.0000,'VENTA #103',6.00,'Sistema','kdx_69875a0547b2e',4),
(454,'660015',4,'2026-02-07 10:28:05','VENTA',-3.0000,3.0000,0.0000,'VENTA #103',300.00,'Sistema','kdx_69875a05483c4',4),
(455,'660014',4,'2026-02-07 10:28:05','VENTA',-1.0000,1.0000,0.0000,'VENTA #103',220.00,'Sistema','kdx_69875a0548c8b',4),
(456,'660035',4,'2026-02-07 10:28:33','VENTA',-1.0000,17.0000,16.0000,'VENTA #104',15.00,'Sistema','kdx_69875a21cd642',4),
(457,'660012',4,'2026-02-07 10:29:06','VENTA',-12.0000,12.0000,0.0000,'VENTA #105',50.00,'Sistema','kdx_69875a4269b49',4),
(458,'660088',4,'2026-02-07 10:29:32','VENTA',-1.0000,8.0000,7.0000,'VENTA #106',18.00,'Sistema','kdx_69875a5c71a2a',4),
(459,'440001',4,'2026-02-05 11:06:14','ENTRADA',8.0000,0.0000,8.0000,'COMPRA #21',90.00,'Admin','kdx_698762f603f20',4),
(460,'660026',4,'2026-02-05 11:06:14','ENTRADA',3.0000,0.0000,3.0000,'COMPRA #21',430.00,'Admin','kdx_698762f605862',4),
(461,'660087',4,'2026-02-07 11:11:52','VENTA',-1.0000,4.0000,3.0000,'VENTA #107',62.53,'Sistema','kdx_698764490ac59',4),
(462,'660103',4,'2026-02-07 11:11:52','VENTA',-1.0000,9.0000,8.0000,'VENTA #107',130.00,'Sistema','kdx_698764490b542',4),
(463,'660007',4,'2026-02-07 11:11:52','VENTA',-11.0000,67.0000,56.0000,'VENTA #107',20.00,'Sistema','kdx_698764490bd9e',4),
(464,'440001',4,'2026-02-07 11:12:27','VENTA',-8.0000,8.0000,0.0000,'VENTA #108',90.00,'Sistema','kdx_6987646bbfeed',4),
(465,'660020',4,'2026-02-08 09:03:42','VENTA',-6.0000,6.0000,0.0000,'VENTA #109',570.00,'Sistema','kdx_698897be7e6f1',4),
(466,'660018',4,'2026-02-08 09:03:42','VENTA',-1.0000,2.0000,1.0000,'VENTA #109',580.00,'Sistema','kdx_698897be7ef93',4),
(467,'660019',4,'2026-02-08 09:03:42','VENTA',-5.0000,67.0000,62.0000,'VENTA #109',33.00,'Sistema','kdx_698897be7f76a',4),
(468,'660009',4,'2026-02-08 09:03:42','VENTA',-1.0000,3.0000,2.0000,'VENTA #109',120.00,'Sistema','kdx_698897be7ff1a',4),
(469,'660034',4,'2026-02-08 09:03:42','VENTA',-2.0000,61.0000,59.0000,'VENTA #109',6.00,'Sistema','kdx_698897be80772',4),
(470,'660100',4,'2026-02-08 09:03:42','VENTA',-3.0000,22.0000,19.0000,'VENTA #109',40.00,'Sistema','kdx_698897be80f3b',4),
(471,'660090',4,'2026-02-08 09:03:42','VENTA',-1.0000,3.0000,2.0000,'VENTA #109',1150.00,'Sistema','kdx_698897be816f1',4),
(472,'660013',4,'2026-02-06 09:07:39','ENTRADA',16.0000,2.0000,18.0000,'COMPRA #22',35.00,'Admin','kdx_698898ab1d392',4),
(473,'660020',4,'2026-02-06 09:07:39','ENTRADA',5.0000,0.0000,5.0000,'COMPRA #22',570.00,'Admin','kdx_698898ab1e093',4),
(474,'660018',4,'2026-02-06 09:07:39','ENTRADA',2.0000,1.0000,3.0000,'COMPRA #22',580.00,'Admin','kdx_698898ab1fd81',4),
(475,'660096',4,'2026-02-06 09:07:39','ENTRADA',4.0000,1.0000,5.0000,'COMPRA #22',120.00,'Admin','kdx_698898ab20b0a',4),
(476,'660003',4,'2026-02-06 09:07:39','ENTRADA',8.0000,0.0000,8.0000,'COMPRA #22',360.00,'Admin','kdx_698898ab225cf',4),
(477,'660037',4,'2026-02-06 09:07:39','ENTRADA',24.0000,0.0000,24.0000,'COMPRA #22',210.00,'Admin','kdx_698898ab2372e',4),
(478,'660012',4,'2026-02-06 09:07:39','ENTRADA',17.0000,0.0000,17.0000,'COMPRA #22',50.00,'Admin','kdx_698898ab243f9',4),
(479,'660017',4,'2026-02-06 09:07:39','ENTRADA',11.0000,0.0000,11.0000,'COMPRA #22',88.00,'Admin','kdx_698898ab25053',4),
(480,'660014',4,'2026-02-06 09:07:39','ENTRADA',5.0000,0.0000,5.0000,'COMPRA #22',220.00,'Admin','kdx_698898ab25d2f',4),
(481,'440001',4,'2026-02-06 09:07:39','ENTRADA',4.0000,0.0000,4.0000,'COMPRA #22',90.00,'Admin','kdx_698898ab26b01',4),
(482,'660013',4,'2026-02-08 09:08:19','VENTA',-4.0000,18.0000,14.0000,'VENTA #110',35.00,'Sistema','kdx_698898d390333',4),
(483,'660012',4,'2026-02-08 09:08:19','VENTA',-10.0000,17.0000,7.0000,'VENTA #110',50.00,'Sistema','kdx_698898d390cb1',4),
(484,'660017',4,'2026-02-08 09:08:19','VENTA',-1.0000,11.0000,10.0000,'VENTA #110',88.00,'Sistema','kdx_698898d391527',4),
(485,'660029',4,'2026-02-08 09:17:55','VENTA',-16.0000,240.0000,224.0000,'VENTA #111',8.00,'Sistema','kdx_69889b139d1ef',4),
(486,'660003',4,'2026-02-08 09:17:55','VENTA',-1.0000,8.0000,7.0000,'VENTA #111',360.00,'Sistema','kdx_69889b139e11a',4),
(487,'660001',4,'2026-02-08 10:10:46','VENTA',-1.0000,10.0000,9.0000,'VENTA #112',35.00,'Sistema','kdx_6988a77647586',4),
(488,'660033',4,'2026-02-08 10:10:46','VENTA',-5.0000,17.0000,12.0000,'VENTA #112',20.00,'Sistema','kdx_6988a77647ef1',4),
(489,'660008',4,'2026-02-08 10:11:16','VENTA',-1.0000,1.0000,0.0000,'VENTA #113',150.00,'Sistema','kdx_6988a794742c1',4),
(490,'660011',4,'2026-02-08 10:11:16','VENTA',-2.0000,4.0000,2.0000,'VENTA #113',210.00,'Sistema','kdx_6988a79474b77',4),
(491,'660023',4,'2026-02-08 10:11:53','VENTA',-4.0000,14.0000,10.0000,'VENTA #114',213.00,'Sistema','kdx_6988a7b98d5bb',4),
(492,'660019',4,'2026-02-08 10:12:44','VENTA',-1.0000,62.0000,61.0000,'VENTA #115',33.00,'Sistema','kdx_6988a7ec06b9b',4),
(493,'660027',4,'2026-02-08 10:12:55','VENTA',-7.0000,42.0000,35.0000,'VENTA #116',162.57,'Sistema','kdx_6988a7f7e9e40',4),
(494,'660034',4,'2026-02-08 10:13:22','VENTA',-7.0000,59.0000,52.0000,'VENTA #117',6.00,'Sistema','kdx_6988a8125aeb0',4),
(495,'660087',4,'2026-02-08 10:13:53','VENTA',-3.0000,3.0000,0.0000,'VENTA #118',62.53,'Sistema','kdx_6988a831a42f3',4),
(496,'660104',4,'2026-02-08 10:15:45','VENTA',-1.0000,10.0000,9.0000,'VENTA #119',120.00,'Sistema','kdx_6988a8a13952e',4),
(497,'660007',4,'2026-02-08 10:15:45','VENTA',-3.0000,56.0000,53.0000,'VENTA #119',20.00,'Sistema','kdx_6988a8a139e19',4),
(498,'440001',4,'2026-02-08 10:15:45','VENTA',-4.0000,4.0000,0.0000,'VENTA #119',90.00,'Sistema','kdx_6988a8a13a5ed',4),
(499,'660014',4,'2026-02-08 10:15:45','VENTA',-1.0000,5.0000,4.0000,'VENTA #119',220.00,'Sistema','kdx_6988a8a13adbf',4),
(500,'660017',4,'2026-02-08 10:15:45','VENTA',-5.0000,10.0000,5.0000,'VENTA #119',88.00,'Sistema','kdx_6988a8a13c5d5',4),
(501,'660012',4,'2026-02-08 10:15:45','VENTA',-7.0000,7.0000,0.0000,'VENTA #119',50.00,'Sistema','kdx_6988a8a13ceb5',4),
(502,'660035',4,'2026-02-06 14:33:49','ENTRADA',3.0000,16.0000,19.0000,'COMPRA #23',15.00,'Admin','kdx_6988e51d55efe',4),
(503,'660017',4,'2026-02-08 14:44:34','DEVOLUCION',5.0000,5.0000,10.0000,'REFUND_ITEM_305',88.00,'CAJERO_REFUND','kdx_6988e7a2dd628',4),
(504,'660017',4,'2026-02-08 14:45:06','VENTA',-4.0000,10.0000,6.0000,'VENTA #121',88.00,'Sistema','kdx_6988e7c2f314d',4),
(505,'660001',4,'2026-02-08 14:50:34','VENTA',-2.0000,9.0000,7.0000,'VENTA #122',35.00,'Sistema','kdx_6988e90a10839',4),
(506,'660033',4,'2026-02-08 14:50:34','VENTA',-4.0000,12.0000,8.0000,'VENTA #122',20.00,'Sistema','kdx_6988e90a1111f',4),
(507,'660017',4,'2026-02-07 19:25:31','ENTRADA',4.0000,6.0000,10.0000,'COMPRA #24',88.00,'Admin','kdx_6989297b287df',4),
(508,'660030',4,'2026-02-07 19:25:31','ENTRADA',4.0000,0.0000,4.0000,'COMPRA #24',180.00,'Admin','kdx_6989297b2aa48',4),
(509,'881108',4,'2026-02-07 19:25:31','ENTRADA',3.0000,0.0000,3.0000,'COMPRA #24',292.71,'Admin','kdx_6989297b2b906',4),
(510,'660026',4,'2026-02-08 20:01:26','VENTA',-3.0000,3.0000,0.0000,'VENTA #123',430.00,'Sistema','kdx_698931e662e06',4),
(511,'660032',4,'2026-02-08 20:01:26','VENTA',-4.0000,17.0000,13.0000,'VENTA #123',190.00,'Sistema','kdx_698931e66379d',4),
(512,'660025',4,'2026-02-08 20:01:26','VENTA',-1.0000,1.0000,0.0000,'VENTA #123',430.00,'Sistema','kdx_698931e66407e',4),
(513,'660013',4,'2026-02-08 20:01:26','VENTA',-10.0000,14.0000,4.0000,'VENTA #123',35.00,'Sistema','kdx_698931e664867',4),
(514,'660096',4,'2026-02-08 20:01:26','VENTA',-1.0000,5.0000,4.0000,'VENTA #123',120.00,'Sistema','kdx_698931e6662af',4),
(515,'660014',4,'2026-02-08 20:01:26','VENTA',-1.0000,4.0000,3.0000,'VENTA #123',220.00,'Sistema','kdx_698931e666bb7',4),
(516,'660031',4,'2026-02-08 20:02:33','VENTA',-1.0000,1.0000,0.0000,'VENTA #124',82.00,'Sistema','kdx_69893229a0162',4),
(517,'660006',4,'2026-02-10 10:58:42','VENTA',-1.0000,11.0000,10.0000,'VENTA #125',212.00,'Sistema','kdx_698b55b2c15b9',4),
(518,'660005',4,'2026-02-10 11:00:46','VENTA',-2.0000,25.0000,23.0000,'VENTA #126',130.00,'Sistema','kdx_698b562e48c3e',4),
(519,'660011',4,'2026-02-10 11:01:05','VENTA',-2.0000,2.0000,0.0000,'VENTA #127',210.00,'Sistema','kdx_698b5641c1c46',4),
(520,'660023',4,'2026-02-10 11:02:38','VENTA',-9.0000,10.0000,1.0000,'VENTA #128',213.00,'Sistema','kdx_698b569ea782d',4),
(521,'660032',4,'2026-02-10 11:02:38','VENTA',-5.0000,13.0000,8.0000,'VENTA #128',190.00,'Sistema','kdx_698b569ea88f3',4),
(522,'660037',4,'2026-02-10 11:02:38','VENTA',-24.0000,24.0000,0.0000,'VENTA #128',210.00,'Sistema','kdx_698b569ea91cb',4),
(523,'660020',4,'2026-02-10 11:03:12','VENTA',-1.0000,5.0000,4.0000,'VENTA #129',570.00,'Sistema','kdx_698b56c084e18',4),
(524,'660018',4,'2026-02-10 11:43:15','VENTA',-1.0000,3.0000,2.0000,'VENTA #130',580.00,'Sistema','kdx_698b6023af80c',4),
(525,'660019',4,'2026-02-10 11:43:15','VENTA',-1.0000,61.0000,60.0000,'VENTA #130',33.00,'Sistema','kdx_698b6023b02e0',4),
(526,'660027',4,'2026-02-10 11:43:15','VENTA',-6.0000,35.0000,29.0000,'VENTA #130',162.57,'Sistema','kdx_698b6023b15e6',4),
(527,'660034',4,'2026-02-10 11:43:15','VENTA',-29.0000,52.0000,23.0000,'VENTA #130',6.00,'Sistema','kdx_698b6023b1f5d',4),
(528,'660096',4,'2026-02-10 11:47:51','VENTA',-1.0000,4.0000,3.0000,'VENTA #131',120.00,'Sistema','kdx_698b6137c40d6',4),
(529,'660007',4,'2026-02-10 11:47:51','VENTA',-14.0000,53.0000,39.0000,'VENTA #131',20.00,'Sistema','kdx_698b6137c54bf',4),
(530,'660014',4,'2026-02-10 11:47:51','VENTA',-2.0000,3.0000,1.0000,'VENTA #131',220.00,'Sistema','kdx_698b6137c5db7',4),
(531,'660017',4,'2026-02-10 11:47:51','VENTA',-7.0000,10.0000,3.0000,'VENTA #131',88.00,'Sistema','kdx_698b6137c65ce',4),
(532,'660010',4,'2026-02-10 11:53:18','VENTA',-1.0000,1.0000,0.0000,'VENTA #132',130.00,'Sistema','kdx_698b627ee5d73',4),
(533,'660001',4,'2026-02-10 12:12:38','VENTA',-4.0000,7.0000,3.0000,'VENTA #133',35.00,'Sistema','kdx_698b6706caff8',4),
(534,'660033',4,'2026-02-10 12:12:38','VENTA',-4.0000,8.0000,4.0000,'VENTA #133',20.00,'Sistema','kdx_698b6706cba23',4),
(535,'660005',4,'2026-02-10 12:12:38','VENTA',-1.0000,23.0000,22.0000,'VENTA #133',130.00,'Sistema','kdx_698b6706cc380',4),
(536,'660013',4,'2026-02-10 12:12:38','VENTA',-2.0000,4.0000,2.0000,'VENTA #133',35.00,'Sistema','kdx_698b6706ccc6f',4),
(537,'660034',4,'2026-02-10 12:12:38','VENTA',-23.0000,23.0000,0.0000,'VENTA #133',6.00,'Sistema','kdx_698b6706cd520',4),
(538,'660029',4,'2026-02-10 12:12:38','VENTA',-14.0000,224.0000,210.0000,'VENTA #133',8.00,'Sistema','kdx_698b6706cdd82',4),
(539,'660035',4,'2026-02-10 12:14:14','VENTA',-2.0000,19.0000,17.0000,'VENTA #134',15.00,'Sistema','kdx_698b6766ae318',4),
(540,'660096',4,'2026-02-10 12:14:14','VENTA',-1.0000,3.0000,2.0000,'VENTA #134',120.00,'Sistema','kdx_698b6766aec22',4),
(541,'660016',4,'2026-02-10 12:14:14','VENTA',-2.0000,2.0000,0.0000,'VENTA #134',620.00,'Sistema','kdx_698b6766af49a',4),
(542,'660103',4,'2026-02-10 12:14:14','VENTA',-1.0000,8.0000,7.0000,'VENTA #134',130.00,'Sistema','kdx_698b6766b0c14',4),
(543,'660014',4,'2026-02-10 12:15:38','VENTA',-1.0000,1.0000,0.0000,'VENTA #135',220.00,'Sistema','kdx_698b67ba9412b',4),
(544,'881108',4,'2026-02-10 12:15:38','VENTA',-1.0000,3.0000,2.0000,'VENTA #135',292.71,'Sistema','kdx_698b67ba94a14',4),
(545,'660023',4,'2026-02-08 12:21:55','ENTRADA',24.0000,1.0000,25.0000,'COMPRA #25',213.00,'Admin','kdx_698b6933a699c',4),
(546,'660021',4,'2026-02-08 12:21:55','ENTRADA',24.0000,0.0000,24.0000,'COMPRA #25',217.00,'Admin','kdx_698b6933a7eba',4),
(547,'660010',4,'2026-02-08 12:22:41','ENTRADA',4.0000,0.0000,4.0000,'COMPRA #26',130.00,'Admin','kdx_698b6961418cc',4),
(548,'660015',4,'2026-02-08 12:22:41','ENTRADA',1.0000,0.0000,1.0000,'COMPRA #26',300.00,'Admin','kdx_698b6961434bf',4),
(549,'660010',4,'2026-02-10 12:24:30','VENTA',-1.0000,4.0000,3.0000,'VENTA #136',130.00,'Sistema','kdx_698b69ce8e75a',4),
(550,'660023',4,'2026-02-10 12:24:30','VENTA',-23.0000,25.0000,2.0000,'VENTA #136',213.00,'Sistema','kdx_698b69ce8f059',4),
(551,'660021',4,'2026-02-10 12:25:05','VENTA',-4.0000,24.0000,20.0000,'VENTA #137',217.00,'Sistema','kdx_698b69f158500',4),
(552,'881108',4,'2026-02-10 12:25:05','VENTA',-1.0000,2.0000,1.0000,'VENTA #137',292.71,'Sistema','kdx_698b69f158dd5',4),
(553,'756058841446',4,'2026-02-11 09:34:10','VENTA',-2.0000,4.0000,2.0000,'VENTA #138',120.00,'Admin','kdx_0ZKlBBzYbN3Mc',4),
(554,'756058841446',4,'2026-02-11 14:26:29','VENTA',-1.0000,2.0000,1.0000,'VENTA #139',120.00,'Eddy','kdx_vGQaVqGkonwql',4),
(555,'756058841446',4,'2026-02-11 17:57:10','VENTA',-1.0000,1.0000,0.0000,'VENTA #140',120.00,'Eddy','kdx_wpfjZo1KvZMyR',4);
/*!40000 ALTER TABLE `kardex` ENABLE KEYS */;
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
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_kardex_ai BEFORE INSERT ON kardex
FOR EACH ROW
BEGIN
    IF NEW.uuid IS NULL THEN SET NEW.uuid = UUID(); END IF;
    
    INSERT INTO sync_journal (tabla, accion, registro_uuid, datos_json, origen_sucursal_id)
    VALUES ('kardex', 'INSERT', NEW.uuid, JSON_OBJECT(
        'id_producto', NEW.id_producto, 'id_almacen', NEW.id_almacen,
        'id_sucursal', NEW.id_sucursal, 'tipo_movimiento', NEW.tipo_movimiento,
        'cantidad', NEW.cantidad, 'referencia', NEW.referencia, 
        'costo_unitario', NEW.costo_unitario, 'fecha', NEW.fecha, 
        'uuid', NEW.uuid
    ), NEW.id_sucursal);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `mermas_cabecera`
--

DROP TABLE IF EXISTS `mermas_cabecera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mermas_cabecera` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT current_timestamp(),
  `usuario` varchar(50) DEFAULT NULL,
  `motivo_general` varchar(100) DEFAULT NULL,
  `total_costo_perdida` decimal(12,2) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `estado` varchar(20) DEFAULT 'PROCESADA',
  `uuid` char(36) DEFAULT NULL,
  `id_sucursal` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mermas_cabecera`
--

LOCK TABLES `mermas_cabecera` WRITE;
/*!40000 ALTER TABLE `mermas_cabecera` DISABLE KEYS */;
INSERT INTO `mermas_cabecera` VALUES
(1,'2026-01-29 23:42:59','Admin','General',3240.00,'2026-02-04 11:33:39','PROCESADA','54fe3a4d-01f4-11f1-b0a7-0affd1a0dee5',1),
(2,'2026-01-30 10:55:57','Admin','General',292.71,'2026-02-04 11:33:39','PROCESADA','54fe3e25-01f4-11f1-b0a7-0affd1a0dee5',1),
(3,'2026-02-02 18:45:38','Admin','General',216.00,'2026-02-04 11:33:39','PROCESADA','54fe3ea9-01f4-11f1-b0a7-0affd1a0dee5',1),
(4,'2026-02-02 19:16:32','Admin','General',1100.00,'2026-02-04 11:33:39','PROCESADA','54fe3f12-01f4-11f1-b0a7-0affd1a0dee5',1),
(5,'2026-02-02 19:42:25','Admin','General',560.00,'2026-02-04 11:33:39','PROCESADA','54fe3f78-01f4-11f1-b0a7-0affd1a0dee5',1),
(6,'2026-02-04 09:57:03','Admin','General',1160.00,'2026-02-04 11:33:39','PROCESADA','54fe3fe0-01f4-11f1-b0a7-0affd1a0dee5',1),
(7,'2026-02-04 09:58:42','Admin','General',4560.00,'2026-02-04 11:33:39','PROCESADA','54fe4047-01f4-11f1-b0a7-0affd1a0dee5',1),
(8,'2026-02-04 10:00:47','Admin','General',10080.00,'2026-02-04 11:33:39','PROCESADA','54fe40a5-01f4-11f1-b0a7-0affd1a0dee5',1),
(9,'2026-02-04 10:01:18','Admin','General',5112.00,'2026-02-04 11:33:39','PROCESADA','54fe410b-01f4-11f1-b0a7-0affd1a0dee5',1),
(10,'2026-02-04 10:01:52','Admin','General',1080.00,'2026-02-04 11:33:39','PROCESADA','54fe420a-01f4-11f1-b0a7-0affd1a0dee5',1),
(11,'2026-02-04 10:02:42','Admin','General',435.00,'2026-02-04 11:33:39','PROCESADA','54fe4272-01f4-11f1-b0a7-0affd1a0dee5',1),
(12,'2026-02-04 10:22:30','Admin','General',160.00,'2026-02-04 11:33:39','PROCESADA','54fe42d1-01f4-11f1-b0a7-0affd1a0dee5',1),
(14,'2026-02-07 09:57:23','Admin','General',1563.25,'2026-02-07 09:57:23','PROCESADA','4f6f8d21-0435-11f1-b0a7-0affd1a0dee5',4);
/*!40000 ALTER TABLE `mermas_cabecera` ENABLE KEYS */;
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
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_mermas_ai BEFORE INSERT ON mermas_cabecera
FOR EACH ROW
BEGIN
    IF NEW.uuid IS NULL THEN SET NEW.uuid = UUID(); END IF;
    
    INSERT INTO sync_journal (tabla, accion, registro_uuid, datos_json, origen_sucursal_id)
    VALUES ('mermas_cabecera', 'INSERT', NEW.uuid, JSON_OBJECT(
        'usuario', NEW.usuario, 'motivo_general', NEW.motivo_general,
        'total_costo_perdida', NEW.total_costo_perdida, 'fecha_registro', NEW.fecha_registro,
        'id_sucursal', NEW.id_sucursal, 'uuid', NEW.uuid
    ), NEW.id_sucursal);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `mermas_detalle`
--

DROP TABLE IF EXISTS `mermas_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mermas_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_merma` int(11) DEFAULT NULL,
  `id_producto` varchar(50) DEFAULT NULL,
  `cantidad` decimal(10,2) DEFAULT NULL,
  `costo_al_momento` decimal(10,2) DEFAULT NULL,
  `motivo_especifico` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mermas_detalle`
--

LOCK TABLES `mermas_detalle` WRITE;
/*!40000 ALTER TABLE `mermas_detalle` DISABLE KEYS */;
INSERT INTO `mermas_detalle` VALUES
(1,1,'660036',24.00,135.00,'Vencimiento'),
(2,2,'881108',1.00,292.71,'Vencimiento'),
(3,3,'660017',3.00,72.00,'Error de Entrada'),
(4,4,'660012',22.00,50.00,'Error de Entrada'),
(5,5,'660020',1.00,560.00,'Consumo'),
(6,6,'660018',2.00,580.00,'Error de Entrada'),
(7,7,'660032',24.00,190.00,'Error de Entrada'),
(8,8,'660037',48.00,210.00,'Error de Entrada'),
(9,9,'660023',24.00,213.00,'Error de Entrada'),
(10,10,'660034',180.00,6.00,'Error de Entrada'),
(11,11,'660035',29.00,15.00,'Error de Entrada'),
(12,12,'756058841478',4.00,40.00,'Error de Entrada'),
(14,14,'660087',25.00,62.53,'Error de Entrada');
/*!40000 ALTER TABLE `mermas_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mesas`
--

DROP TABLE IF EXISTS `mesas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mesas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `id_piso` int(10) unsigned DEFAULT NULL,
  `capacidad` int(11) DEFAULT 4,
  `pos_x` decimal(6,2) DEFAULT 50.00,
  `pos_y` decimal(6,2) DEFAULT 50.00,
  `estado` enum('libre','ocupada','reservada') DEFAULT 'libre',
  `id_orden_actual` int(10) unsigned DEFAULT NULL,
  `forma` varchar(20) DEFAULT 'circulo',
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mesas`
--

LOCK TABLES `mesas` WRITE;
/*!40000 ALTER TABLE `mesas` DISABLE KEYS */;
/*!40000 ALTER TABLE `mesas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
(1,'0001_01_01_000000_create_users_table',1),
(2,'2026_02_10_190715_create_personal_access_tokens_table',2);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ordenes_paralelas`
--

DROP TABLE IF EXISTS `ordenes_paralelas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ordenes_paralelas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_sesion_caja` int(11) DEFAULT NULL,
  `id_mesa` int(10) unsigned DEFAULT NULL,
  `items_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`items_json`)),
  `estado` enum('activa','cerrada','cancelada') DEFAULT 'activa',
  `tipo_servicio` varchar(30) DEFAULT 'Mesa',
  `cliente_nombre` varchar(200) DEFAULT 'Mostrador',
  `precio_tipo` varchar(20) DEFAULT 'normal',
  `descuento_orden` decimal(5,2) DEFAULT 0.00,
  `notas` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ordenes_paralelas`
--

LOCK TABLES `ordenes_paralelas` WRITE;
/*!40000 ALTER TABLE `ordenes_paralelas` DISABLE KEYS */;
INSERT INTO `ordenes_paralelas` VALUES
(1,NULL,NULL,'[{\"codigo\":\"660021\",\"nombre\":\"Refresco cola lata 300ml\",\"precio\":240,\"cantidad\":2,\"descuento\":10,\"nota\":\"Extra picante\",\"curso\":1}]','cancelada','Mesa','Mostrador','normal',5.00,NULL,'2026-02-12 08:06:33','2026-02-12 08:06:40'),
(2,NULL,NULL,'[{\"codigo\":\"660021\",\"nombre\":\"Refresco cola lata 300ml\",\"precio\":240,\"cantidad\":2,\"descuento\":10,\"nota\":\"Extra picante\",\"curso\":1}]','activa','Mesa','Mostrador','normal',5.00,NULL,'2026-02-12 08:06:43','2026-02-12 08:06:43');
/*!40000 ALTER TABLE `ordenes_paralelas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pedidos_cabecera`
--

DROP TABLE IF EXISTS `pedidos_cabecera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pedidos_cabecera` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_nombre` varchar(100) DEFAULT NULL,
  `cliente_direccion` varchar(255) DEFAULT NULL,
  `cliente_telefono` varchar(20) DEFAULT NULL,
  `cliente_email` varchar(100) DEFAULT NULL,
  `cliente_contacto_pref` varchar(50) DEFAULT NULL,
  `total` decimal(10,2) DEFAULT NULL,
  `estado` varchar(20) DEFAULT 'pendiente',
  `fecha` datetime DEFAULT current_timestamp(),
  `fecha_programada` datetime DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `notas_admin` text DEFAULT NULL,
  `id_empresa` int(11) DEFAULT 1,
  `id_sucursal` int(11) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pedidos_cabecera`
--

LOCK TABLES `pedidos_cabecera` WRITE;
/*!40000 ALTER TABLE `pedidos_cabecera` DISABLE KEYS */;
INSERT INTO `pedidos_cabecera` VALUES
(1,'JUAN PEREZ','[DELIVERY: Cerro - Palatino] CAL ARBOL SECO #32','5352783030',NULL,NULL,700.00,'pendiente','2026-02-11 18:32:56','2026-02-14 00:00:00','CON ROSAS',NULL,1,4);
/*!40000 ALTER TABLE `pedidos_cabecera` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pedidos_detalle`
--

DROP TABLE IF EXISTS `pedidos_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pedidos_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_pedido` int(11) DEFAULT NULL,
  `cantidad` decimal(10,2) DEFAULT NULL,
  `precio_unitario` decimal(10,2) DEFAULT NULL,
  `id_producto` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_pedido` (`id_pedido`),
  CONSTRAINT `pedidos_detalle_ibfk_1` FOREIGN KEY (`id_pedido`) REFERENCES `pedidos_cabecera` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pedidos_detalle`
--

LOCK TABLES `pedidos_detalle` WRITE;
/*!40000 ALTER TABLE `pedidos_detalle` DISABLE KEYS */;
INSERT INTO `pedidos_detalle` VALUES
(1,1,1.00,150.00,'756058841446');
/*!40000 ALTER TABLE `pedidos_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pedidos_espera`
--

DROP TABLE IF EXISTS `pedidos_espera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pedidos_espera` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_referencia` varchar(100) DEFAULT NULL,
  `datos_json` longtext DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `cajero` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pedidos_espera`
--

LOCK TABLES `pedidos_espera` WRITE;
/*!40000 ALTER TABLE `pedidos_espera` DISABLE KEYS */;
/*!40000 ALTER TABLE `pedidos_espera` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES
(1,'App\\Models\\User',4,'pos-session','d0546141bdc1b8f7f1e98a2335857a6cf0d9be448ba3c2da27a4556aa5776022','[\"*\"]','2026-02-10 19:14:31','2026-02-11 07:14:27','2026-02-10 19:14:27','2026-02-10 19:14:31'),
(2,'App\\Models\\User',1,'test','ac6656f15705c7b684232aa19430161ddd9c83d54364ca2710fa2b192600e81c','[\"*\"]',NULL,NULL,'2026-02-10 19:55:20','2026-02-10 19:55:20'),
(3,'App\\Models\\User',4,'pos-session','a0e7916491eca0402e478ff892281dff858e7243acf2a56fa5212a3a864dc00d','[\"*\"]',NULL,'2026-02-11 07:58:47','2026-02-10 19:58:47','2026-02-10 19:58:47'),
(5,'App\\Models\\User',4,'pos-session','b73cd0ea169e76f5cc171fe2221dfe814996f0e11a2ecedc9f7d53b24db9d277','[\"*\"]','2026-02-11 16:38:28','2026-02-12 00:43:05','2026-02-11 12:43:05','2026-02-11 16:38:28'),
(6,'App\\Models\\User',1,'pos-session','d2bd499796c14d49cec9d23d4f74992c1f1517ed455927562cd3225d8441d70a','[\"*\"]','2026-02-12 00:48:36','2026-02-12 00:49:05','2026-02-11 12:49:05','2026-02-12 00:48:36'),
(7,'App\\Models\\User',4,'pos-session','7b678c32e0d4b0b5ed1a7f313f80179b909ef3ba0d538cbf79936c9a52655df7','[\"*\"]','2026-02-11 13:43:04','2026-02-12 00:53:23','2026-02-11 12:53:23','2026-02-11 13:43:04'),
(9,'App\\Models\\User',1,'pos-session','8fb5d1e744293be3b97845c8965a67fd4b7b0c3bd9501925ffff392dc5a34c22','[\"*\"]','2026-02-11 15:24:50','2026-02-12 02:39:05','2026-02-11 14:39:05','2026-02-11 15:24:50'),
(10,'App\\Models\\User',4,'pos-session','0d7f50b7cec11f7fcabbd6db422398a21516ef183bed3c1b7402068813f0f248','[\"*\"]','2026-02-12 10:42:36','2026-02-12 19:38:58','2026-02-12 07:38:58','2026-02-12 10:42:36');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pisos`
--

DROP TABLE IF EXISTS `pisos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pisos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `orden` int(11) DEFAULT 0,
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pisos`
--

LOCK TABLES `pisos` WRITE;
/*!40000 ALTER TABLE `pisos` DISABLE KEYS */;
INSERT INTO `pisos` VALUES
(1,'Planta Baja',1,1);
/*!40000 ALTER TABLE `pisos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `producciones_historial`
--

DROP TABLE IF EXISTS `producciones_historial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `producciones_historial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_receta` int(11) DEFAULT NULL,
  `nombre_receta` varchar(150) DEFAULT NULL,
  `id_producto_final` varchar(50) DEFAULT NULL,
  `cantidad_lotes` decimal(10,2) DEFAULT NULL,
  `unidades_creadas` decimal(12,2) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `usuario` varchar(50) DEFAULT NULL,
  `costo_total` decimal(12,2) DEFAULT NULL,
  `revertido` tinyint(4) DEFAULT 0,
  `json_snapshot` longtext DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `producciones_historial`
--

LOCK TABLES `producciones_historial` WRITE;
/*!40000 ALTER TABLE `producciones_historial` DISABLE KEYS */;
INSERT INTO `producciones_historial` VALUES
(1,2,'picadillo en jaba ','881110',2.00,2.00,'2026-01-27 21:10:13','Admin',585.20,1,'[{\"id\":\"991152\",\"cant\":920,\"costo\":\"0.63\"},{\"id\":\"991145\",\"cant\":20,\"costo\":\"0.28\"}]'),
(2,2,'picadillo en jaba ','881110',5.00,5.00,'2026-01-27 21:10:34','Admin',1463.00,0,'[{\"id\":\"991152\",\"cant\":2300,\"costo\":\"0.63\"},{\"id\":\"991145\",\"cant\":50,\"costo\":\"0.28\"}]');
/*!40000 ALTER TABLE `producciones_historial` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `productos`
--

DROP TABLE IF EXISTS `productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `productos` (
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(200) DEFAULT NULL,
  `precio` decimal(15,2) DEFAULT NULL,
  `costo` decimal(15,2) DEFAULT NULL,
  `precio_mayorista` decimal(15,2) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `version_row` bigint(20) DEFAULT 0,
  `id_empresa` int(11) NOT NULL DEFAULT 1,
  `categoria` varchar(100) DEFAULT 'General',
  `stock_minimo` decimal(10,2) DEFAULT 0.00,
  `fecha_vencimiento` date DEFAULT NULL,
  `es_elaborado` tinyint(1) DEFAULT 1,
  `es_materia_prima` tinyint(1) DEFAULT 0,
  `es_servicio` tinyint(1) DEFAULT 0,
  `es_cocina` tinyint(4) DEFAULT 0,
  `unidad_medida` varchar(20) DEFAULT 'UNIDAD',
  `descripcion` text DEFAULT NULL,
  `impuesto` decimal(5,2) DEFAULT 0.00,
  `peso` decimal(10,2) DEFAULT 0.00,
  `color` varchar(50) DEFAULT NULL,
  `es_web` tinyint(1) DEFAULT 1,
  `es_pos` tinyint(1) DEFAULT 1,
  `tiene_variantes` tinyint(1) DEFAULT 0,
  `variantes_json` text DEFAULT NULL,
  `es_suc1` tinyint(1) DEFAULT 1,
  `es_suc2` tinyint(1) DEFAULT 1,
  `es_suc3` tinyint(1) DEFAULT 1,
  `es_suc4` tinyint(1) DEFAULT 1,
  `es_suc5` tinyint(1) DEFAULT 1,
  `es_suc6` tinyint(1) DEFAULT 1,
  `sucursales_web` varchar(255) DEFAULT '1',
  `uuid` char(36) DEFAULT NULL,
  `id_sucursal_origen` int(11) DEFAULT 1,
  PRIMARY KEY (`codigo`),
  UNIQUE KEY `idx_uuid` (`uuid`),
  KEY `idx_version` (`version_row`),
  KEY `idx_prod_empresa` (`id_empresa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `productos`
--

LOCK TABLES `productos` WRITE;
/*!40000 ALTER TABLE `productos` DISABLE KEYS */;
INSERT INTO `productos` VALUES
('440001','Donas Grandes',130.00,90.00,94.50,1,0,1,'DULCES',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1','ed40cff0-043e-11f1-b0a7-0affd1a0dee5',1),
('660001','Chupa chus grandes',70.00,35.00,36.75,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1ecd1-01f4-11f1-b0a7-0affd1a0dee5',1),
('660002','Carritos chocolate',40.00,25.00,26.25,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1efce-01f4-11f1-b0a7-0affd1a0dee5',1),
('660003','Pure de tomate 400g lata',440.00,360.00,378.00,1,1770559589,1,'BODEGON',0.00,NULL,0,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f07e-01f4-11f1-b0a7-0affd1a0dee5',1),
('660004','Mini Whiskey 200ml',400.00,370.00,388.50,1,1769730940,1,'BEBIDAS_A',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f10c-01f4-11f1-b0a7-0affd1a0dee5',1),
('660005','Galletas Porleo',180.00,130.00,136.50,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f190-01f4-11f1-b0a7-0affd1a0dee5',1),
('660006','Espagetis 500g',280.00,212.00,222.60,1,1769730940,1,'BODEGON',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f211-01f4-11f1-b0a7-0affd1a0dee5',1),
('660007','Refresco en polvo',40.00,20.00,21.00,1,1769730940,1,'BODEGON',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f294-01f4-11f1-b0a7-0affd1a0dee5',1),
('660008','Galleta Biskiato minion',180.00,150.00,157.50,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f310-01f4-11f1-b0a7-0affd1a0dee5',1),
('660009','Galletas Rellenitas',150.00,120.00,126.00,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f390-01f4-11f1-b0a7-0affd1a0dee5',1),
('660010','Galletas Blackout azul',160.00,130.00,136.50,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f412-01f4-11f1-b0a7-0affd1a0dee5',1),
('660011','Energizante',280.00,210.00,220.50,1,1769730940,1,'BEBIDAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f490-01f4-11f1-b0a7-0affd1a0dee5',1),
('660012','Tartaleta',100.00,50.00,52.50,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f50f-01f4-11f1-b0a7-0affd1a0dee5',1),
('660013','Cabezote',80.00,35.00,36.75,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD','Cabezote cubano , almibarados',0.00,0.56,'amarillo',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f589-01f4-11f1-b0a7-0affd1a0dee5',1),
('660014','Albondigas pqt',370.00,220.00,231.00,1,1769730940,1,'CARNICOS',0.00,NULL,0,0,0,0,'UNIDAD','albondigas de pollo',0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f60d-01f4-11f1-b0a7-0affd1a0dee5',1),
('660015','Hamgurgesa 5u pqt',500.00,300.00,315.00,1,1769730940,1,'CARNICOS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f697-01f4-11f1-b0a7-0affd1a0dee5',1),
('660016','Higado de pollo pqt',730.00,620.00,651.00,1,1769730940,1,'CARNICOS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f717-01f4-11f1-b0a7-0affd1a0dee5',1),
('660017','Croquetas pollo pqt',150.00,88.00,92.40,1,1770558559,1,'CARNICOS',0.00,NULL,0,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f796-01f4-11f1-b0a7-0affd1a0dee5',1),
('660018','Azucar 1kg pqt',680.00,580.00,609.00,1,1769730940,1,'BODEGON',0.00,NULL,0,0,0,0,'UNIDAD','Azúcar blanca cristalina',0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f812-01f4-11f1-b0a7-0affd1a0dee5',1),
('660019','Pinguinos gelatina',60.00,33.00,34.65,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f893-01f4-11f1-b0a7-0affd1a0dee5',1),
('660020','Arroz 1kg pqt',650.00,570.00,598.50,1,1769730940,1,'BODEGON',0.00,NULL,0,0,0,0,'UNIDAD','Arroz de importación, calidad premium, 1kg tipo 1 pulido.limpio.listo para cocinar.',0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f914-01f4-11f1-b0a7-0affd1a0dee5',1),
('660021','Refresco cola lata 300ml',240.00,217.00,227.85,1,1769730940,1,'BEBIDAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1f98e-01f4-11f1-b0a7-0affd1a0dee5',1),
('660022','Refresco limon lata 300ml',240.00,210.00,220.50,1,1769730940,1,'BEBIDAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fa0e-01f4-11f1-b0a7-0affd1a0dee5',1),
('660023','Cerveza Shekels',240.00,213.00,223.65,1,1769730940,1,'CERVEZAS',0.00,NULL,0,0,0,0,'UNIDAD','Cerveza shekels,',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5',1),
('660024','Cerveza Sta Isabel',230.00,205.00,215.25,1,1769730940,1,'CERVEZAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fb03-01f4-11f1-b0a7-0affd1a0dee5',1),
('660025','Leche condensada',520.00,430.00,451.50,1,1769730940,1,'BODEGON',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fb7d-01f4-11f1-b0a7-0affd1a0dee5',1),
('660026','Fanguito',580.00,430.00,451.50,1,1769730940,1,'BODEGON',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fbf7-01f4-11f1-b0a7-0affd1a0dee5',1),
('660027','Marranetas',240.00,162.57,170.70,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fc6f-01f4-11f1-b0a7-0affd1a0dee5',1),
('660028','Tartaletas especiales',140.00,90.00,94.50,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fceb-01f4-11f1-b0a7-0affd1a0dee5',1),
('660029','Caramelos de 15',15.00,8.00,8.40,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fd65-01f4-11f1-b0a7-0affd1a0dee5',1),
('660030','San jacobo',280.00,180.00,189.00,1,1769730940,1,'CARNICOS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fddd-01f4-11f1-b0a7-0affd1a0dee5',1),
('660031','Croquetas Buffet',160.00,82.00,86.10,1,1770558582,1,'CARNICOS',0.00,NULL,0,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fe58-01f4-11f1-b0a7-0affd1a0dee5',1),
('660032','Cerveza Esple',230.00,190.00,199.50,1,1769730940,1,'CERVEZAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1fed4-01f4-11f1-b0a7-0affd1a0dee5',1),
('660033','Chupa chus 40',40.00,20.00,21.00,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1ff4f-01f4-11f1-b0a7-0affd1a0dee5',1),
('660034','Caramelos 10',10.00,6.00,6.30,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e1ffcb-01f4-11f1-b0a7-0affd1a0dee5',1),
('660035','chicle 30',30.00,15.00,15.75,1,1769730940,1,'CONFITURAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20047-01f4-11f1-b0a7-0affd1a0dee5',1),
('660036','jugo cajita 200ml',180.00,135.00,141.75,1,1769730940,1,'BEBIDAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e200c3-01f4-11f1-b0a7-0affd1a0dee5',1),
('660037','Cerveza La Fria',240.00,210.00,220.50,1,1769730940,1,'CERVEZAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e2013f-01f4-11f1-b0a7-0affd1a0dee5',1),
('660038','Cerveza Beer azul',240.00,210.00,220.50,1,1769730940,1,'CERVEZAS',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e201b9-01f4-11f1-b0a7-0affd1a0dee5',1),
('660039','Helado Mousse 7oz',150.00,90.00,94.50,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20235-01f4-11f1-b0a7-0affd1a0dee5',1),
('660040','Dulce 3 leches vaso 7oz',250.00,150.00,157.50,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e202b1-01f4-11f1-b0a7-0affd1a0dee5',1),
('660041','Torticas',70.00,35.00,36.75,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1','54e2032c-01f4-11f1-b0a7-0affd1a0dee5',1),
('660042','Pan de Gloria',80.00,35.00,36.75,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e205ab-01f4-11f1-b0a7-0affd1a0dee5',1),
('660043','Minicake 18cm',1500.00,700.00,735.00,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20629-01f4-11f1-b0a7-0affd1a0dee5',1),
('660044','Minicake Fanguito',1700.00,800.00,840.00,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20721-01f4-11f1-b0a7-0affd1a0dee5',1),
('660045','Minicake Bombom',2000.00,1105.00,1160.25,1,1769730940,1,'DULCES',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e207ad-01f4-11f1-b0a7-0affd1a0dee5',1),
('660046','Harina 1kg',600.00,500.00,525.00,1,1769730940,1,'BODEGON',0.00,NULL,0,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20827-01f4-11f1-b0a7-0affd1a0dee5',1),
('660087','Galletas Saltitacos',200.00,62.53,65.66,1,0,1,'CONFITURAS',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e208a4-01f4-11f1-b0a7-0affd1a0dee5',1),
('660088','Caldo de pollo sobrecito',30.00,18.00,18.90,1,0,1,'BODEGON',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20921-01f4-11f1-b0a7-0affd1a0dee5',1),
('660089','pastillita pollo con tomate',40.00,20.00,21.00,1,0,1,'BODEGON',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e2099d-01f4-11f1-b0a7-0affd1a0dee5',1),
('660090','lomo en bandeja 1lb',1300.00,1150.00,1207.50,1,0,1,'CARNICOS',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20a18-01f4-11f1-b0a7-0affd1a0dee5',1),
('660093','Pqt de 4 panques',280.00,180.00,189.00,1,0,1,'DULCES',5.00,NULL,1,0,0,0,'U','',0.00,0.00,'#cccccc',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20a99-01f4-11f1-b0a7-0affd1a0dee5',1),
('660094','Gacenigas mini',280.00,200.00,210.00,1,0,1,'DULCES',5.00,NULL,1,0,0,0,'U','',0.00,0.00,'#cccccc',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20b21-01f4-11f1-b0a7-0affd1a0dee5',1),
('660095','Panque de chocolate',100.00,70.00,73.50,1,0,1,'DULCES',5.00,NULL,1,0,0,0,'U','',0.00,0.00,'#cccccc',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20ba2-01f4-11f1-b0a7-0affd1a0dee5',1),
('660096','pqt torticas 6u',220.00,120.00,126.00,1,0,1,'DULCES',5.00,NULL,1,0,0,0,'U','',0.00,0.00,'#cccccc',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20c23-01f4-11f1-b0a7-0affd1a0dee5',1),
('660097','Bolsa de 8 panes',200.00,160.00,168.00,1,0,1,'BODEGON',0.00,NULL,1,0,0,0,'UNIDAD','Pan suave, 8 unidades de 50g. acabados de hornear y envasados al vacio',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20ca1-01f4-11f1-b0a7-0affd1a0dee5',1),
('660098','Cake 2900',2900.00,2000.00,2100.00,1,1769996399,1,'DULCES',0.00,NULL,1,0,0,0,'UNIDAD','Cake mediano de 22 cm, 1 piso, pude escoger gratis, el color, el sabor del relleno y algun mensaje corto escrito.',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20d22-01f4-11f1-b0a7-0affd1a0dee5',1),
('660099','Marquesita',100.00,65.00,68.25,1,1769749678,1,'DULCES',0.00,NULL,1,0,0,0,'UNIDAD','Con merengue',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20da7-01f4-11f1-b0a7-0affd1a0dee5',1),
('660100','ojitos gummy gum',80.00,40.00,42.00,1,0,1,'CONFITURAS',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20eb0-01f4-11f1-b0a7-0affd1a0dee5',1),
('660101','Cafe en sobre',40.00,25.00,26.25,1,1770223525,1,'BODEGON',0.00,NULL,1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20f35-01f4-11f1-b0a7-0affd1a0dee5',1),
('660102','Jabon de carbon',200.00,170.00,178.50,1,0,1,'BODEGON',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e20fb5-01f4-11f1-b0a7-0affd1a0dee5',1),
('660103','jabon de bano',150.00,130.00,136.50,1,0,1,'BODEGON',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e21032-01f4-11f1-b0a7-0affd1a0dee5',1),
('660104','Donas Donuts',160.00,120.00,126.00,1,0,1,'CONFITURAS',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e210ac-01f4-11f1-b0a7-0affd1a0dee5',1),
('756058841446','COCA COLA GRANDE',150.00,120.00,126.00,1,0,1,'Bebidas',5.00,NULL,1,0,0,0,'U','Deliciosa cocacola, toma coca',5.00,0.00,'#4d2f79',1,1,0,NULL,1,1,1,1,1,1,'1,2,4','54e2112e-01f4-11f1-b0a7-0affd1a0dee5',1),
('756058841453','Refresco de naranja',240.00,210.00,220.50,1,0,1,'Bebidas',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e211be-01f4-11f1-b0a7-0affd1a0dee5',1),
('756058841477','melcocha',40.00,20.00,21.00,1,1769730604,1,'Pruebas',0.00,NULL,1,0,0,1,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'2,4','54e21241-01f4-11f1-b0a7-0affd1a0dee5',1),
('756058841478','Cupcakes',120.00,40.00,42.00,1,1769886848,1,'DULCES',5.00,NULL,1,0,0,0,'UNIDAD','Deliciosos cup cakes',1.00,0.00,'#cccccc',1,1,0,NULL,1,1,1,1,1,1,'1,2,4','54e212c4-01f4-11f1-b0a7-0affd1a0dee5',1),
('756058841479','Capacillo de papel',2.00,1.00,1.05,1,0,1,'INSUMOS',5.00,NULL,1,1,0,0,'U','',1.00,0.00,'#712d2d',0,1,0,NULL,1,1,1,1,1,1,'1','54e21347-01f4-11f1-b0a7-0affd1a0dee5',1),
('756058841480','Yuca en Pure',120.00,100.00,105.00,1,0,1,'INSUMOS',5.00,NULL,1,1,0,0,'U','',0.00,0.00,'#2c7c81',0,1,0,NULL,1,1,1,1,1,1,'1','54e213cb-01f4-11f1-b0a7-0affd1a0dee5',1),
('88011','Producto de Prueba',50.00,20.00,21.00,1,1769730604,1,'Pruebas',0.00,NULL,0,0,1,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e21447-01f4-11f1-b0a7-0affd1a0dee5',1),
('88012','Producto de Prueba2',50.00,20.00,21.00,1,1769730604,1,'Pruebas',0.00,NULL,0,0,1,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e214cb-01f4-11f1-b0a7-0affd1a0dee5',1),
('881101','Minicake 1500',1500.00,1041.35,1093.42,1,1769730604,1,'DULCES',0.00,'2026-01-15',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,2,3,4,5,6','54e2154d-01f4-11f1-b0a7-0affd1a0dee5',1),
('881102','Minicake fanguito',1700.00,565.04,593.29,1,1769730604,1,'DULCES',0.00,'2026-01-15',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,2,3,5,6','54e215d5-01f4-11f1-b0a7-0affd1a0dee5',1),
('881103','Tortica grande',70.00,45.00,47.25,1,1769730604,1,'DULCES',0.00,'2026-01-15',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1','54e21884-01f4-11f1-b0a7-0affd1a0dee5',1),
('881104','Torticas chiquita',80.00,35.00,36.75,1,1769730604,1,'DULCES',0.00,'2026-01-24',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1','54e2190d-01f4-11f1-b0a7-0affd1a0dee5',1),
('881105','Tartaletas',100.00,50.00,52.50,1,1769730604,1,'DULCES',0.00,'2026-01-24',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1','54e2198e-01f4-11f1-b0a7-0affd1a0dee5',1),
('881106','Minicake 1500 choco',1500.00,1041.35,1093.42,1,1769730604,1,'DULCES',0.00,'2026-01-24',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,2,3,4,5,6','54e21a14-01f4-11f1-b0a7-0affd1a0dee5',1),
('881107','Mini Cake',1500.00,567.90,596.30,1,1769730604,1,'DULCES',0.00,'2026-01-20',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1','54e21a94-01f4-11f1-b0a7-0affd1a0dee5',1),
('881108','Hamburguesas pollo',480.00,292.71,307.35,1,1769730604,1,'CONGELADOS',1.00,'2026-01-24',1,0,0,0,'UNIDAD','Deliciosas Hamburgesas de 100g de picadillo de pollo mdm, saborizadas con ingredientes naturales',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,2,3','54e21b15-01f4-11f1-b0a7-0affd1a0dee5',1),
('881109','Cabezotes',80.00,33.55,35.23,1,1769730604,1,'DULCES',1.00,'2026-01-24',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1','54e21ba0-01f4-11f1-b0a7-0affd1a0dee5',1),
('881110','PQT Picadillo 1lb',330.00,289.80,304.29,1,1769730604,1,'CONGELADOS',0.00,'2026-01-24',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'2','54e21c24-01f4-11f1-b0a7-0affd1a0dee5',1),
('990091','Cerveza Presidente',240.00,210.00,220.50,1,0,1,'CERVEZAS',0.00,NULL,1,0,0,0,'UNIDAD',NULL,0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'1,4','54e21ca6-01f4-11f1-b0a7-0affd1a0dee5',1),
('991101','Azucar Blanca',0.65,0.58,0.61,1,1769730604,1,'B. Alcoholicas',5.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e21d30-01f4-11f1-b0a7-0affd1a0dee5',1),
('991102','azucar prieta',0.65,0.61,0.64,1,1769730604,1,'B. Alcoholicas',5.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e21dbe-01f4-11f1-b0a7-0affd1a0dee5',1),
('991103','Harina trigo',0.60,0.50,0.53,1,1770504191,1,'B. Alcoholicas',5.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e21e50-01f4-11f1-b0a7-0affd1a0dee5',1),
('991104','leche en polvo',1.65,1.50,1.58,1,1769730604,1,'B. Alcoholicas',5.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e21ed8-01f4-11f1-b0a7-0affd1a0dee5',1),
('991105','maicena',1.20,1.20,1.26,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e21f5d-01f4-11f1-b0a7-0affd1a0dee5',1),
('991106','polvo hornear',2.00,1.80,1.89,1,1769885570,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e21fe2-01f4-11f1-b0a7-0affd1a0dee5',1),
('991107','levadura',2.80,2.40,2.52,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22064-01f4-11f1-b0a7-0affd1a0dee5',1),
('991108','guayaba en barra',1.00,0.85,0.89,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e220e5-01f4-11f1-b0a7-0affd1a0dee5',1),
('991109','canela en polvo',3.00,2.50,2.63,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22169-01f4-11f1-b0a7-0affd1a0dee5',1),
('991110','Bicarbonato',4.00,3.60,3.78,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e221eb-01f4-11f1-b0a7-0affd1a0dee5',1),
('991111','mejorador de pan',2.00,1.80,1.89,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2226d-01f4-11f1-b0a7-0affd1a0dee5',1),
('991112','CMC',8.00,5.00,5.25,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e222ec-01f4-11f1-b0a7-0affd1a0dee5',1),
('991113','Emulsionante redolmy',4.50,4.15,4.36,1,1769730604,1,'B. Alcoholicas',0.00,'2026-01-24',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2236e-01f4-11f1-b0a7-0affd1a0dee5',1),
('991114','sazon en sobre',70.00,45.00,47.25,1,1769730604,1,'B. Alcoholicas',2.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e223ef-01f4-11f1-b0a7-0affd1a0dee5',1),
('991115','pastillita de sabor',50.00,25.00,26.25,1,1769730604,1,'B. Alcoholicas',2.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2246c-01f4-11f1-b0a7-0affd1a0dee5',1),
('991116','yema huevo',55.00,50.00,52.50,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e224ec-01f4-11f1-b0a7-0affd1a0dee5',1),
('991117','clara huevo',55.00,50.00,52.50,1,1769730604,1,'B. Alcoholicas',2.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2256c-01f4-11f1-b0a7-0affd1a0dee5',1),
('991118','Aceite',2.00,1.20,1.26,1,1770504174,1,'BODEGON',2.00,'2026-01-15',0,1,0,0,'UNIDAD','aceite',0.00,0.00,NULL,1,1,0,NULL,1,1,1,1,1,1,'2,3,4','54e225f1-01f4-11f1-b0a7-0affd1a0dee5',1),
('991119','Sal comun',0.15,0.12,0.13,1,1769730604,1,'B. Alcoholicas',2.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22672-01f4-11f1-b0a7-0affd1a0dee5',1),
('991120','Vainilla liquida',2.20,2.00,2.10,1,1769730604,1,'B. Alcoholicas',2.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e226ef-01f4-11f1-b0a7-0affd1a0dee5',1),
('991121','Pan Rayado',0.60,0.43,0.45,1,1769730604,1,'B. Alcoholicas',2.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22770-01f4-11f1-b0a7-0affd1a0dee5',1),
('991122','chapilla chocolate oscuro',3.80,3.50,3.68,1,1769730604,1,'B. Alcoholicas',6.00,'2026-01-15',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e227ec-01f4-11f1-b0a7-0affd1a0dee5',1),
('991123','Gelatina fresa',6.00,5.00,5.25,1,1769730604,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2286c-01f4-11f1-b0a7-0affd1a0dee5',1),
('991124','pasta de ajo',0.50,0.40,0.42,1,1769730604,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e228eb-01f4-11f1-b0a7-0affd1a0dee5',1),
('991125','Pasta verde cebollino',0.29,0.27,0.28,1,1769730604,1,'B. Alcoholicas',2.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22968-01f4-11f1-b0a7-0affd1a0dee5',1),
('991126','mostaza',2.80,2.50,2.63,1,1769730604,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e229e5-01f4-11f1-b0a7-0affd1a0dee5',1),
('991127','mostaza agranel',2.80,2.50,2.63,1,1769730604,1,'B. Alcoholicas',2.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22a63-01f4-11f1-b0a7-0affd1a0dee5',1),
('991128','semifrio de kiwi',1.80,1.50,1.58,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','delicioso wiki',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22ae3-01f4-11f1-b0a7-0affd1a0dee5',1),
('991129','Mousse limon',1.80,1.50,1.58,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22b67-01f4-11f1-b0a7-0affd1a0dee5',1),
('991130','Mouse Chocolate',1.80,1.50,1.58,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e22d84-01f4-11f1-b0a7-0affd1a0dee5',1),
('991131','semifrio choco',1.80,1.50,1.58,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2481e-01f4-11f1-b0a7-0affd1a0dee5',1),
('991132','Mouse de fresa',1.80,1.50,1.58,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e24ac1-01f4-11f1-b0a7-0affd1a0dee5',1),
('991133','Crema Pastelera',2.20,2.00,2.10,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e24c94-01f4-11f1-b0a7-0affd1a0dee5',1),
('991134','Grenatina',5.00,4.60,4.83,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e24e33-01f4-11f1-b0a7-0affd1a0dee5',1),
('991135','cocoa pura',1.20,1.00,1.05,1,1769730605,1,'B. Alcoholicas',1.00,'2026-08-03',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e24fcc-01f4-11f1-b0a7-0affd1a0dee5',1),
('991136','gragea de colores',3.00,2.90,3.05,1,1769730605,1,'B. Alcoholicas',1.00,'2026-08-03',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e25137-01f4-11f1-b0a7-0affd1a0dee5',1),
('991137','grageas de chocolate',3.00,2.90,3.05,1,1769730605,1,'B. Alcoholicas',1.00,'2026-08-03',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e252d4-01f4-11f1-b0a7-0affd1a0dee5',1),
('991138','Natilla de coco',1.00,0.50,0.53,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e25472-01f4-11f1-b0a7-0affd1a0dee5',1),
('991139','margarina',3.00,2.60,2.73,1,1769730605,1,'B. Alcoholicas',0.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2573d-01f4-11f1-b0a7-0affd1a0dee5',1),
('991140','Natilla de Chocolate',0.80,0.64,0.67,1,1769730605,1,'B. Alcoholicas',0.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2590f-01f4-11f1-b0a7-0affd1a0dee5',1),
('991141','Natilla de Fresa',0.50,0.43,0.45,1,1769730605,1,'B. Alcoholicas',0.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e25aad-01f4-11f1-b0a7-0affd1a0dee5',1),
('991142','Natilla de Limon',0.50,0.43,0.45,1,1769730605,1,'B. Alcoholicas',0.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e25c4d-01f4-11f1-b0a7-0affd1a0dee5',1),
('991143','Natilla de Fanguito',0.60,0.50,0.53,1,1769730605,1,'B. Alcoholicas',0.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e25dea-01f4-11f1-b0a7-0affd1a0dee5',1),
('991144','Fanguito',1.20,1.25,1.31,1,1769730605,1,'B. Alcoholicas',0.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2601e-01f4-11f1-b0a7-0affd1a0dee5',1),
('991145','Almibar (1:1,5)',0.46,0.36,0.38,1,1769734349,1,'DULCES',0.00,'2026-01-24',1,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e261ba-01f4-11f1-b0a7-0affd1a0dee5',1),
('991146','Pesto',0.30,0.27,0.28,1,1769730605,1,'DULCES',0.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e26357-01f4-11f1-b0a7-0affd1a0dee5',1),
('991147','Queso',0.55,0.50,0.53,1,1769730605,1,'DULCES',0.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e26529-01f4-11f1-b0a7-0affd1a0dee5',1),
('991148','Panetelas Redolmy minicake',253.00,253.00,265.65,1,1769730605,1,'DULCES',0.00,'2026-01-24',0,0,0,1,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'2','54e266c7-01f4-11f1-b0a7-0affd1a0dee5',1),
('991149','merengue emul',150.00,129.00,135.45,1,1769730605,1,'DULCES',0.00,'2026-01-20',1,0,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e26863-01f4-11f1-b0a7-0affd1a0dee5',1),
('991150','merengue italiano',200.00,159.19,167.15,1,1769730605,1,'DULCES',0.00,'2026-01-20',1,0,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e26a00-01f4-11f1-b0a7-0affd1a0dee5',1),
('991151','Azucar de Vainilla',0.90,0.80,0.84,1,1770581047,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1,2,3','54e26b9a-01f4-11f1-b0a7-0affd1a0dee5',1),
('991152','Picadillo pollo mdm',0.68,0.63,0.66,1,1769730605,1,'CONGELADOS',1.00,'2026-08-08',1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'2','54e26d35-01f4-11f1-b0a7-0affd1a0dee5',1),
('991153','jugo de limon',0.30,0.25,0.26,1,1770602766,1,'B. Alcoholicas',0.00,'2026-08-08',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e26ed8-01f4-11f1-b0a7-0affd1a0dee5',1),
('991154','Ron o alcohol',0.25,0.20,0.21,1,1769730605,1,'B. Alcoholicas',0.00,'2026-08-08',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e27073-01f4-11f1-b0a7-0affd1a0dee5',1),
('991155','vinagre',0.50,0.35,0.37,1,1769730605,1,'B. Alcoholicas',0.00,'2026-08-08',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e27209-01f4-11f1-b0a7-0affd1a0dee5',1),
('991156','natilla guayaba',0.40,0.37,0.39,1,1769730605,1,'B. Alcoholicas',1.00,'2026-01-20',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e273a0-01f4-11f1-b0a7-0affd1a0dee5',1),
('991157','Colorante amarillo polvo',38.00,35.00,36.75,1,1769730605,1,'B. Alcoholicas',1.00,'2026-08-08',0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e27b5f-01f4-11f1-b0a7-0affd1a0dee5',1),
('991158','Almibar (1:1)',0.50,0.41,0.43,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e27d5f-01f4-11f1-b0a7-0affd1a0dee5',1),
('991159','Colorante ENCO 40g',40.00,37.00,38.85,1,1769886124,1,'',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e27f03-01f4-11f1-b0a7-0affd1a0dee5',1),
('991160','Colorante ENCO 20g',60.00,55.00,57.75,1,1769885983,1,'',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e280a1-01f4-11f1-b0a7-0affd1a0dee5',1),
('991161','Colorante Cheffmaster 20g',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2823c-01f4-11f1-b0a7-0affd1a0dee5',1),
('991162','Colorante Tinta negra',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2840a-01f4-11f1-b0a7-0affd1a0dee5',1),
('991163','Colorante Tinta rosada',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2856f-01f4-11f1-b0a7-0affd1a0dee5',1),
('991164','Colorante Tinta namarilla',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e28706-01f4-11f1-b0a7-0affd1a0dee5',1),
('991165','Colorante Soft Gel (MIX) 25g',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2889d-01f4-11f1-b0a7-0affd1a0dee5',1),
('991166','Colorante OTELSA azul',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e28a03-01f4-11f1-b0a7-0affd1a0dee5',1),
('991167','Colorante OTELSA rojo',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e28b99-01f4-11f1-b0a7-0affd1a0dee5',1),
('991168','Colorante Star Chemical',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e28d2e-01f4-11f1-b0a7-0affd1a0dee5',1),
('991169','Colorante agranel',35.00,30.00,31.50,1,1769886213,1,'',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e28ecb-01f4-11f1-b0a7-0affd1a0dee5',1),
('991170','Chips horneables de chocolate',5.00,4.50,4.73,1,1769889295,1,'INSUMOS',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e29067-01f4-11f1-b0a7-0affd1a0dee5',1),
('991171','Emulsionante supernortemul',8.00,7.00,7.35,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e291ff-01f4-11f1-b0a7-0affd1a0dee5',1),
('991172','Saborizante Carolesen 60 ml',40.00,37.00,38.85,1,1769886142,1,'',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e29367-01f4-11f1-b0a7-0affd1a0dee5',1),
('991173','Saborizante Carolesen Tutifruti 510 ml',38.00,35.00,36.75,1,1769886173,1,'',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e29502-01f4-11f1-b0a7-0affd1a0dee5',1),
('991174','Saborizante Loran 118 ml',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2969d-01f4-11f1-b0a7-0affd1a0dee5',1),
('991175','Saborizante La Anita 120 ml',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e29833-01f4-11f1-b0a7-0affd1a0dee5',1),
('991176','Saborizante ENCO 120 ml',22.00,21.00,22.05,1,1770604046,1,'',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e299c5-01f4-11f1-b0a7-0affd1a0dee5',1),
('991177','Saborizante ENCO 60 ml',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e29b28-01f4-11f1-b0a7-0affd1a0dee5',1),
('991178','Saborizante Star Chemical',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2a346-01f4-11f1-b0a7-0affd1a0dee5',1),
('991179','Saborizante Flor d Arancio',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2a521-01f4-11f1-b0a7-0affd1a0dee5',1),
('991180','Saborizante Lorsnn Tutifruti',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2a6b4-01f4-11f1-b0a7-0affd1a0dee5',1),
('991181','Saborizante DUCHE mangp 120 ml',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2a816-01f4-11f1-b0a7-0affd1a0dee5',1),
('991182','Saborizante DEIMPIN guanabana 120 ml',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2a9b0-01f4-11f1-b0a7-0affd1a0dee5',1),
('991183','Saborizante Esencia 3,7ml',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2ab49-01f4-11f1-b0a7-0affd1a0dee5',1),
('991184','Saborizantes agranel',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2acdc-01f4-11f1-b0a7-0affd1a0dee5',1),
('991185','Tartaletas (base)',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD','',0.00,0.00,'',0,1,0,NULL,1,1,1,1,1,1,'1','54e2ae75-01f4-11f1-b0a7-0affd1a0dee5',1),
('991186','Nata',2.60,2.20,2.31,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2afde-01f4-11f1-b0a7-0affd1a0dee5',1),
('991187','Leche condensada',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2b175-01f4-11f1-b0a7-0affd1a0dee5',1),
('991188','Refresco polvo Mayar',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2b30b-01f4-11f1-b0a7-0affd1a0dee5',1),
('991189','Mantequilla de mani',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2b49f-01f4-11f1-b0a7-0affd1a0dee5',1),
('991190','Coco deshidratado',1.00,2.00,2.10,1,1769730605,1,'',0.00,NULL,0,1,0,0,'UNIDAD',NULL,0.00,0.00,NULL,0,1,0,NULL,1,1,1,1,1,1,'1','54e2b604-01f4-11f1-b0a7-0affd1a0dee5',1),
('TEST-999','Producto de Prueba',50.00,20.00,21.00,1,1769730605,1,'Pruebas',0.00,NULL,1,0,0,0,'UNIDAD','',0.00,0.00,'',1,1,0,NULL,1,1,1,1,1,1,'1','54e2b7d7-01f4-11f1-b0a7-0affd1a0dee5',1);
/*!40000 ALTER TABLE `productos` ENABLE KEYS */;
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
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_productos_ai BEFORE INSERT ON productos
FOR EACH ROW
BEGIN
    IF NEW.uuid IS NULL THEN SET NEW.uuid = UUID(); END IF;
    
    INSERT INTO sync_journal (tabla, accion, registro_uuid, datos_json, origen_sucursal_id)
    VALUES ('productos', 'INSERT', NEW.uuid, JSON_OBJECT(
        'codigo', NEW.codigo, 'nombre', NEW.nombre, 'costo', NEW.costo, 
        'precio', NEW.precio, 'categoria', NEW.categoria, 'activo', NEW.activo,
        'es_elaborado', NEW.es_elaborado, 'es_servicio', NEW.es_servicio, 
        'id_empresa', NEW.id_empresa, 'uuid', NEW.uuid
    ), COALESCE(NEW.id_sucursal_origen, 1));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb3 */ ;
/*!50003 SET character_set_results = utf8mb3 */ ;
/*!50003 SET collation_connection  = utf8mb3_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_productos_au AFTER UPDATE ON productos
FOR EACH ROW
BEGIN
    INSERT INTO sync_journal (tabla, accion, registro_uuid, datos_json, origen_sucursal_id)
    VALUES ('productos', 'UPDATE', NEW.uuid, JSON_OBJECT(
        'codigo', NEW.codigo, 'nombre', NEW.nombre, 'costo', NEW.costo, 
        'precio', NEW.precio, 'categoria', NEW.categoria, 'activo', NEW.activo,
        'uuid', NEW.uuid
    ), COALESCE(NEW.id_sucursal_origen, 1));
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `recetas_cabecera`
--

DROP TABLE IF EXISTS `recetas_cabecera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recetas_cabecera` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_producto_final` varchar(50) DEFAULT NULL,
  `nombre_receta` varchar(150) DEFAULT NULL,
  `unidades_resultantes` decimal(10,2) DEFAULT 1.00,
  `costo_total_lote` decimal(12,2) DEFAULT 0.00,
  `costo_unitario` decimal(12,2) DEFAULT 0.00,
  `descripcion` text DEFAULT NULL,
  `fecha_actualizacion` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `activo` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `id_producto_final` (`id_producto_final`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recetas_cabecera`
--

LOCK TABLES `recetas_cabecera` WRITE;
/*!40000 ALTER TABLE `recetas_cabecera` DISABLE KEYS */;
INSERT INTO `recetas_cabecera` VALUES
(1,'881107','cake 1500',3.00,1698.00,566.00,'cake pequeno','2026-01-28 01:40:06',1),
(2,'881110','picadillo en jaba ',1.00,292.60,292.60,'','2026-01-28 01:40:56',1),
(3,'881107','cake 1500 (Copia)',3.00,1698.00,566.00,'cake pequeno','2026-01-28 02:12:38',1),
(4,'756058841478','Cupcakes cubanos',26.00,1208.10,46.47,'hacer el batido pesado y hornear los moldes 20 mins a 180grados','2026-01-31 19:48:15',1),
(5,'756058841478','Cupcakes cubanos (Copia)',30.00,1042.10,34.74,'hacer el batido pesado y hornear los moldes 20 mins a 180grados','2026-01-31 21:32:59',1),
(6,'756058841446','croquetas de yuca',19.00,1527.80,80.41,'','2026-02-05 16:49:25',1),
(7,'881105','tartaletas santo',21.00,1108.60,52.79,'mezclare y hornear\n','2026-02-07 22:48:09',1),
(8,'881103','torticas',16.00,606.86,37.93,'a mano','2026-02-07 22:55:41',1),
(9,'881109','cabezotee',28.00,834.36,29.80,'','2026-02-07 23:05:33',1),
(10,'881109','cabezotee',28.00,834.36,29.80,'','2026-02-07 23:05:33',1);
/*!40000 ALTER TABLE `recetas_cabecera` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recetas_detalle`
--

DROP TABLE IF EXISTS `recetas_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `recetas_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_receta` int(11) DEFAULT NULL,
  `id_ingrediente` varchar(50) DEFAULT NULL,
  `cantidad` decimal(12,4) DEFAULT NULL,
  `costo_calculado` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_receta` (`id_receta`),
  CONSTRAINT `recetas_detalle_ibfk_1` FOREIGN KEY (`id_receta`) REFERENCES `recetas_cabecera` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recetas_detalle`
--

LOCK TABLES `recetas_detalle` WRITE;
/*!40000 ALTER TABLE `recetas_detalle` DISABLE KEYS */;
INSERT INTO `recetas_detalle` VALUES
(17,1,'991148',3.0000,759.00),
(18,1,'991140',600.0000,384.00),
(19,1,'991149',3.0000,387.00),
(20,1,'991145',600.0000,168.00),
(23,3,'991148',3.0000,759.00),
(24,3,'991140',600.0000,384.00),
(25,3,'991149',3.0000,387.00),
(26,3,'991145',600.0000,168.00),
(30,2,'991152',460.0000,289.80),
(31,2,'991145',12.0000,3.36),
(53,4,'991103',375.0000,150.00),
(54,4,'991110',15.0000,54.00),
(55,4,'991120',10.0000,20.00),
(56,4,'991119',5.0000,0.60),
(57,4,'991104',25.0000,37.50),
(58,4,'991101',300.0000,174.00),
(59,4,'991149',1.0000,129.00),
(60,4,'991159',1.0000,37.00),
(61,4,'756058841479',26.0000,26.00),
(62,4,'991116',4.0000,200.00),
(63,4,'991117',4.0000,200.00),
(64,4,'991118',180.0000,180.00),
(80,5,'991103',375.0000,150.00),
(81,5,'991110',15.0000,54.00),
(82,5,'991120',10.0000,20.00),
(83,5,'991119',5.0000,0.60),
(84,5,'991104',25.0000,37.50),
(85,5,'991101',300.0000,174.00),
(86,5,'756058841479',26.0000,26.00),
(87,5,'991116',4.0000,200.00),
(88,5,'991117',4.0000,200.00),
(89,5,'991118',180.0000,180.00),
(90,6,'756058841480',2.0000,200.00),
(91,6,'991103',2000.0000,800.00),
(92,6,'991152',460.0000,289.80),
(93,6,'991119',100.0000,12.00),
(94,6,'991124',100.0000,40.00),
(95,6,'991125',100.0000,27.00),
(96,6,'991169',1.0000,30.00),
(97,6,'991121',300.0000,129.00),
(108,7,'991117',1.0000,50.00),
(109,7,'991116',1.0000,50.00),
(110,7,'991101',110.0000,63.80),
(111,7,'991139',20.0000,52.00),
(112,7,'991103',350.0000,175.00),
(113,7,'991118',120.0000,144.00),
(114,7,'991119',5.0000,0.60),
(115,7,'991120',2.0000,4.00),
(116,7,'991140',630.0000,403.20),
(117,7,'991149',1.0000,129.00),
(118,7,'991159',1.0000,37.00),
(119,8,'991101',150.0000,87.00),
(120,8,'991103',450.0000,225.00),
(121,8,'991139',20.0000,52.00),
(122,8,'991118',195.0000,234.00),
(123,8,'991119',3.0000,0.36),
(124,8,'991108',10.0000,8.50),
(125,9,'991101',20.0000,11.60),
(126,9,'991103',20.0000,10.00),
(127,9,'991116',12.0000,600.00),
(128,9,'991117',1.0000,50.00),
(129,9,'991105',100.0000,120.00),
(130,9,'991119',3.0000,0.36),
(131,9,'991106',3.0000,5.40),
(132,9,'991159',1.0000,37.00),
(133,10,'991101',20.0000,11.60),
(134,10,'991103',20.0000,10.00),
(135,10,'991116',12.0000,600.00),
(136,10,'991117',1.0000,50.00),
(137,10,'991105',100.0000,120.00),
(138,10,'991119',3.0000,0.36),
(139,10,'991106',3.0000,5.40),
(140,10,'991159',1.0000,37.00);
/*!40000 ALTER TABLE `recetas_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_almacen`
--

DROP TABLE IF EXISTS `stock_almacen`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_almacen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_almacen` int(11) NOT NULL,
  `id_producto` varchar(50) NOT NULL,
  `cantidad` decimal(15,4) DEFAULT NULL,
  `ultima_actualizacion` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `id_sucursal` int(11) DEFAULT 1,
  `codigo_temp` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_almacen_prod` (`id_almacen`,`id_producto`),
  KEY `idx_stock_prod` (`id_producto`),
  KEY `idx_stk_prod` (`id_producto`)
) ENGINE=InnoDB AUTO_INCREMENT=1300 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_almacen`
--

LOCK TABLES `stock_almacen` WRITE;
/*!40000 ALTER TABLE `stock_almacen` DISABLE KEYS */;
INSERT INTO `stock_almacen` VALUES
(1,1,'991101',0.0000,'2026-01-29 20:05:18',1,NULL),
(2,1,'991102',0.0000,'2026-01-29 20:05:18',1,NULL),
(3,1,'991103',0.0000,'2026-01-29 20:05:18',1,NULL),
(4,1,'991104',0.0000,'2026-01-29 20:05:18',1,NULL),
(5,1,'991105',0.0000,'2026-01-29 20:05:18',1,NULL),
(6,1,'991106',0.0000,'2026-01-29 20:05:18',1,NULL),
(7,1,'991107',0.0000,'2026-01-29 20:05:18',1,NULL),
(8,1,'991108',0.0000,'2026-01-29 20:05:18',1,NULL),
(9,1,'991109',0.0000,'2026-01-29 20:05:18',1,NULL),
(10,1,'991110',0.0000,'2026-01-29 20:05:18',1,NULL),
(11,1,'991111',0.0000,'2026-01-29 20:05:18',1,NULL),
(12,1,'991112',0.0000,'2026-01-29 20:05:18',1,NULL),
(13,1,'991113',0.0000,'2026-01-29 20:05:18',1,NULL),
(14,1,'991114',0.0000,'2026-01-29 20:05:18',1,NULL),
(15,1,'991115',0.0000,'2026-01-29 20:05:18',1,NULL),
(16,1,'991116',0.0000,'2026-01-29 20:05:18',1,NULL),
(17,1,'991117',0.0000,'2026-01-29 20:05:18',1,NULL),
(18,1,'991118',0.0000,'2026-01-29 20:05:18',1,NULL),
(19,1,'991119',0.0000,'2026-01-29 20:05:18',1,NULL),
(20,1,'991120',0.0000,'2026-01-29 20:05:18',1,NULL),
(21,1,'991121',0.0000,'2026-01-29 20:05:18',1,NULL),
(22,1,'991122',0.0000,'2026-01-29 20:05:18',1,NULL),
(23,1,'991123',0.0000,'2026-01-29 20:05:18',1,NULL),
(24,1,'991124',0.0000,'2026-01-29 20:05:18',1,NULL),
(25,1,'991125',0.0000,'2026-01-29 20:05:18',1,NULL),
(26,1,'991126',0.0000,'2026-01-29 20:05:18',1,NULL),
(27,1,'991127',0.0000,'2026-01-29 20:05:18',1,NULL),
(28,1,'991128',0.0000,'2026-01-29 20:05:18',1,NULL),
(29,1,'991129',0.0000,'2026-01-29 20:05:18',1,NULL),
(30,1,'991130',0.0000,'2026-01-29 20:05:18',1,NULL),
(31,1,'991131',0.0000,'2026-01-29 20:05:18',1,NULL),
(32,1,'991132',0.0000,'2026-01-29 20:05:18',1,NULL),
(33,1,'991133',0.0000,'2026-01-29 20:05:18',1,NULL),
(34,1,'991134',0.0000,'2026-01-29 20:05:18',1,NULL),
(35,1,'991135',0.0000,'2026-01-29 20:05:18',1,NULL),
(36,1,'991136',0.0000,'2026-01-29 20:05:18',1,NULL),
(37,1,'991137',0.0000,'2026-01-29 20:05:18',1,NULL),
(38,1,'991138',0.0000,'2026-01-29 20:05:18',1,NULL),
(39,1,'991139',0.0000,'2026-01-29 20:05:18',1,NULL),
(40,1,'991140',0.0000,'2026-01-29 20:05:18',1,NULL),
(41,1,'881101',0.0000,'2026-01-29 20:05:18',1,NULL),
(42,1,'881102',0.0000,'2026-01-29 20:05:18',1,NULL),
(43,1,'881103',0.0000,'2026-01-29 20:05:18',1,NULL),
(44,1,'881104',0.0000,'2026-01-29 20:05:18',1,NULL),
(45,1,'881105',0.0000,'2026-01-28 14:20:26',1,NULL),
(46,1,'991141',0.0000,'2026-01-29 20:05:18',1,NULL),
(47,1,'991142',0.0000,'2026-01-29 20:05:18',1,NULL),
(48,1,'991143',0.0000,'2026-01-29 20:05:18',1,NULL),
(49,1,'991144',0.0000,'2026-01-29 20:05:18',1,NULL),
(50,1,'991145',0.0000,'2026-01-29 20:05:18',1,NULL),
(51,1,'991146',0.0000,'2026-01-29 20:05:18',1,NULL),
(52,1,'991147',0.0000,'2026-01-29 20:05:18',1,NULL),
(53,1,'991148',0.0000,'2026-01-28 05:40:54',1,NULL),
(54,1,'991149',0.0000,'2026-01-26 07:53:27',1,NULL),
(55,1,'991150',0.0000,'2026-01-29 20:05:18',1,NULL),
(56,1,'881106',0.0000,'2026-01-29 20:05:18',1,NULL),
(57,1,'881107',0.0000,'2026-01-29 20:05:18',1,NULL),
(58,1,'991151',0.0000,'2026-01-29 20:05:18',1,NULL),
(59,1,'991152',0.0000,'2026-01-29 20:05:18',1,NULL),
(60,1,'991153',0.0000,'2026-01-29 20:05:18',1,NULL),
(61,1,'991154',0.0000,'2026-01-29 20:05:18',1,NULL),
(62,1,'991155',0.0000,'2026-01-29 20:05:18',1,NULL),
(63,1,'881108',0.0000,'2026-01-29 20:05:18',1,NULL),
(64,1,'991156',0.0000,'2026-01-29 20:05:18',1,NULL),
(65,1,'991157',0.0000,'2026-01-29 20:05:18',1,NULL),
(66,1,'881109',0.0000,'2026-01-29 20:05:18',1,NULL),
(67,1,'881110',0.0000,'2026-01-29 20:05:18',1,NULL),
(122,1,'TEST-999',0.0000,'2026-01-26 16:38:32',1,NULL),
(541,1,'88011',0.0000,'2026-01-29 20:05:18',1,NULL),
(542,1,'756058841477',0.0000,'2026-01-26 19:21:06',1,NULL),
(543,1,'88012',0.0000,'2026-01-26 17:20:42',1,NULL),
(567,2,'756058841477',5.0000,'2026-02-12 14:57:42',2,NULL),
(568,2,'881105',29.0000,'2026-02-12 14:57:42',2,NULL),
(569,2,'991101',6290.0000,'2026-02-12 14:57:42',2,NULL),
(570,2,'991102',320.0000,'2026-02-12 14:57:42',2,NULL),
(571,2,'991103',5735.0000,'2026-02-12 14:57:42',2,NULL),
(572,2,'991105',2530.0000,'2026-02-12 14:57:42',2,NULL),
(573,2,'991106',700.0000,'2026-02-12 14:57:42',2,NULL),
(574,2,'991107',695.0000,'2026-02-12 14:57:42',2,NULL),
(575,2,'991108',500.0000,'2026-02-12 14:57:42',2,NULL),
(576,2,'991109',200.0000,'2026-02-12 14:57:42',2,NULL),
(577,2,'991110',415.0000,'2026-02-12 14:57:42',2,NULL),
(578,2,'991111',1365.0000,'2026-02-12 14:57:42',2,NULL),
(579,2,'991112',315.0000,'2026-02-12 14:57:42',2,NULL),
(580,2,'991113',930.0000,'2026-02-12 14:57:42',2,NULL),
(581,2,'991114',3.0000,'2026-02-12 14:57:42',2,NULL),
(582,2,'991115',29.0000,'2026-02-12 14:57:42',2,NULL),
(583,2,'991116',14.0000,'2026-02-12 14:57:42',2,NULL),
(584,2,'991117',13.0000,'2026-02-12 14:57:42',2,NULL),
(585,2,'991118',110.0000,'2026-02-12 14:57:42',2,NULL),
(586,2,'991119',1740.0000,'2026-02-12 14:57:42',2,NULL),
(587,2,'991120',180.0000,'2026-02-12 14:57:42',2,NULL),
(588,2,'991121',1145.0000,'2026-02-12 14:57:42',2,NULL),
(589,2,'991122',1855.0000,'2026-02-12 14:57:42',2,NULL),
(590,2,'991123',910.0000,'2026-02-12 14:57:42',2,NULL),
(591,2,'991124',500.0000,'2026-02-12 14:57:42',2,NULL),
(592,2,'991125',240.0000,'2026-02-12 14:57:42',2,NULL),
(593,2,'991126',300.0000,'2026-02-12 14:57:42',2,NULL),
(594,2,'991127',1775.0000,'2026-02-12 14:57:42',2,NULL),
(595,2,'991128',830.0000,'2026-02-12 14:57:42',2,NULL),
(596,2,'991129',240.0000,'2026-02-12 14:57:42',2,NULL),
(597,2,'991130',115.0000,'2026-02-12 14:57:42',2,NULL),
(598,2,'991131',140.0000,'2026-02-12 14:57:42',2,NULL),
(599,2,'991132',630.0000,'2026-02-12 14:57:42',2,NULL),
(600,2,'991133',625.0000,'2026-02-12 14:57:42',2,NULL),
(601,2,'991134',230.0000,'2026-02-12 14:57:42',2,NULL),
(602,2,'991135',65.0000,'2026-02-12 14:57:42',2,NULL),
(603,2,'991136',435.0000,'2026-02-12 14:57:42',2,NULL),
(604,2,'991137',390.0000,'2026-02-12 14:57:42',2,NULL),
(605,2,'991139',2775.0000,'2026-02-12 14:57:42',2,NULL),
(606,2,'991141',180.0000,'2026-02-12 14:57:42',2,NULL),
(607,2,'991144',135.0000,'2026-02-12 14:57:42',2,NULL),
(608,2,'991146',75.0000,'2026-02-12 14:57:42',2,NULL),
(609,2,'991147',630.0000,'2026-02-12 14:57:42',2,NULL),
(610,2,'991148',1.5000,'2026-02-12 14:57:42',2,NULL),
(611,2,'991149',0.2500,'2026-02-12 14:57:42',2,NULL),
(612,2,'991151',1020.0000,'2026-02-12 14:57:42',2,NULL),
(613,2,'991152',5500.0000,'2026-02-12 14:57:42',2,NULL),
(614,2,'991153',915.0000,'2026-02-12 14:57:42',2,NULL),
(615,2,'991154',50.0000,'2026-02-12 14:57:42',2,NULL),
(616,2,'991157',285.0000,'2026-02-12 14:57:42',2,NULL),
(617,2,'991158',725.0000,'2026-02-12 14:57:42',2,NULL),
(618,2,'991159',345.0000,'2026-02-12 14:57:42',2,NULL),
(619,2,'991160',60.0000,'2026-02-12 14:57:42',2,NULL),
(620,2,'991161',60.0000,'2026-02-12 14:57:42',2,NULL),
(621,2,'991162',100.0000,'2026-02-12 14:57:42',2,NULL),
(622,2,'991163',50.0000,'2026-02-12 14:57:42',2,NULL),
(623,2,'991164',40.0000,'2026-02-12 14:57:42',2,NULL),
(624,2,'991165',50.0000,'2026-02-12 14:57:42',2,NULL),
(625,2,'991166',810.0000,'2026-02-12 14:57:42',2,NULL),
(626,2,'991167',390.0000,'2026-02-12 14:57:42',2,NULL),
(627,2,'991168',80.0000,'2026-02-12 14:57:42',2,NULL),
(628,2,'991169',855.0000,'2026-02-12 14:57:42',2,NULL),
(629,2,'991170',185.0000,'2026-02-12 14:57:42',2,NULL),
(630,2,'991171',995.0000,'2026-02-12 14:57:42',2,NULL),
(631,2,'991172',140.0000,'2026-02-12 14:57:42',2,NULL),
(632,2,'991173',365.0000,'2026-02-12 14:57:42',2,NULL),
(633,2,'991174',305.0000,'2026-02-12 14:57:42',2,NULL),
(634,2,'991175',95.0000,'2026-02-12 14:57:42',2,NULL),
(635,2,'991176',345.0000,'2026-02-12 14:57:42',2,NULL),
(636,2,'991177',110.0000,'2026-02-12 14:57:42',2,NULL),
(637,2,'991178',40.0000,'2026-02-12 14:57:42',2,NULL),
(638,2,'991179',75.0000,'2026-02-12 14:57:42',2,NULL),
(639,2,'991180',55.0000,'2026-02-12 14:57:42',2,NULL),
(640,2,'991181',105.0000,'2026-02-12 14:57:42',2,NULL),
(641,2,'991182',90.0000,'2026-02-12 14:57:42',2,NULL),
(642,2,'991183',70.0000,'2026-02-12 14:57:42',2,NULL),
(643,2,'991184',3625.0000,'2026-02-12 14:57:42',2,NULL),
(644,2,'991185',26.0000,'2026-02-12 14:57:42',2,NULL),
(645,2,'991186',775.0000,'2026-02-12 14:57:42',2,NULL),
(646,2,'991187',6.0000,'2026-02-12 14:57:42',2,NULL),
(647,2,'991188',2.0000,'2026-02-12 14:57:42',2,NULL),
(648,2,'991189',160.0000,'2026-02-12 14:57:42',2,NULL),
(649,2,'991190',325.0000,'2026-02-12 14:57:42',2,NULL),
(657,2,'881103',0.0000,'2026-01-29 20:05:18',2,NULL),
(670,6,'660034',0.0000,'2026-01-29 20:05:18',6,NULL),
(671,6,'660029',0.0000,'2026-01-29 20:05:18',6,NULL),
(672,6,'660035',0.0000,'2026-01-29 20:05:18',6,NULL),
(673,6,'660007',0.0000,'2026-01-29 20:05:18',6,NULL),
(674,6,'660033',0.0000,'2026-01-29 20:05:18',6,NULL),
(675,6,'660002',0.0000,'2026-01-29 20:05:18',6,NULL),
(676,6,'660019',0.0000,'2026-01-29 20:05:18',6,NULL),
(677,6,'660001',0.0000,'2026-01-29 20:05:18',6,NULL),
(678,6,'660028',0.0000,'2026-01-29 20:05:18',6,NULL),
(679,6,'660009',0.0000,'2026-01-29 20:05:18',6,NULL),
(680,6,'660005',0.0000,'2026-01-29 20:05:18',6,NULL),
(681,6,'660036',0.0000,'2026-01-29 20:05:18',6,NULL),
(682,6,'660008',0.0000,'2026-01-29 20:05:18',6,NULL),
(683,6,'660027',0.0000,'2026-01-29 20:05:18',6,NULL),
(684,6,'660032',0.0000,'2026-01-29 20:05:18',6,NULL),
(685,6,'660024',0.0000,'2026-01-29 20:05:18',6,NULL),
(686,6,'660011',0.0000,'2026-01-29 20:05:18',6,NULL),
(687,6,'660021',0.0000,'2026-01-29 20:05:18',6,NULL),
(688,6,'660022',0.0000,'2026-01-29 20:05:18',6,NULL),
(689,6,'660037',0.0000,'2026-01-29 20:05:18',6,NULL),
(690,6,'660023',0.0000,'2026-01-29 20:05:18',6,NULL),
(691,6,'660006',0.0000,'2026-01-29 20:05:18',6,NULL),
(692,6,'660003',0.0000,'2026-01-29 20:05:18',6,NULL),
(693,6,'660004',0.0000,'2026-01-29 20:05:18',6,NULL),
(694,6,'660025',0.0000,'2026-01-29 20:05:18',6,NULL),
(695,6,'660026',0.0000,'2026-01-29 20:05:18',6,NULL),
(696,6,'660020',0.0000,'2026-01-29 20:05:18',6,NULL),
(697,6,'660018',0.0000,'2026-01-29 20:05:18',6,NULL),
(698,6,'660016',0.0000,'2026-01-29 20:05:18',6,NULL),
(699,6,'660012',0.0000,'2026-01-29 20:05:18',6,NULL),
(700,6,'660013',0.0000,'2026-01-29 20:05:18',6,NULL),
(701,6,'660014',0.0000,'2026-01-29 20:05:18',6,NULL),
(702,6,'660015',0.0000,'2026-01-29 20:05:18',6,NULL),
(703,6,'660017',0.0000,'2026-01-29 20:05:18',6,NULL),
(704,6,'660030',0.0000,'2026-01-29 20:05:18',6,NULL),
(705,6,'660031',0.0000,'2026-01-29 20:05:18',6,NULL),
(708,2,'660001',0.0000,'2026-01-29 23:33:47',2,NULL),
(710,2,'756058841453',0.0000,'2026-01-29 23:33:47',2,NULL),
(722,2,'660014',0.0000,'2026-01-29 23:33:47',2,NULL),
(724,2,'756058841446',0.0000,'2026-01-29 23:33:47',2,NULL),
(820,4,'660034',0.0000,'2026-02-10 17:12:38',4,NULL),
(821,4,'660029',210.0000,'2026-02-12 14:57:42',4,NULL),
(822,4,'660035',17.0000,'2026-02-12 14:57:42',4,NULL),
(823,4,'660007',39.0000,'2026-02-12 14:57:42',4,NULL),
(824,4,'660033',4.0000,'2026-02-12 14:57:42',4,NULL),
(825,4,'660002',27.0000,'2026-02-12 14:57:42',4,NULL),
(826,4,'660019',60.0000,'2026-02-12 14:57:42',4,NULL),
(827,4,'660001',3.0000,'2026-02-12 14:57:42',4,NULL),
(828,4,'660028',0.0000,'2026-01-30 04:07:59',4,NULL),
(829,4,'660009',2.0000,'2026-02-12 14:57:42',4,NULL),
(830,4,'660005',22.0000,'2026-02-12 14:57:42',4,NULL),
(831,4,'660036',0.0000,'2026-02-07 15:27:03',4,NULL),
(832,4,'660008',0.0000,'2026-02-08 15:11:16',4,NULL),
(833,4,'660027',29.0000,'2026-02-12 14:57:42',4,NULL),
(834,4,'660032',8.0000,'2026-02-12 14:57:42',4,NULL),
(835,4,'660024',0.0000,'2026-01-30 04:22:47',4,NULL),
(836,4,'660011',0.0000,'2026-02-10 16:01:05',4,NULL),
(837,4,'660021',20.0000,'2026-02-12 14:57:42',4,NULL),
(838,4,'660022',0.0000,'2026-02-03 00:25:31',4,NULL),
(839,4,'660037',0.0000,'2026-02-10 16:02:38',4,NULL),
(840,4,'660023',2.0000,'2026-02-12 14:57:42',4,NULL),
(841,4,'660006',10.0000,'2026-02-12 14:57:42',4,NULL),
(842,4,'660003',7.0000,'2026-02-12 14:57:42',4,NULL),
(843,4,'660004',0.0000,'2026-02-02 02:38:39',4,NULL),
(844,4,'660025',0.0000,'2026-02-09 01:01:26',4,NULL),
(845,4,'660026',0.0000,'2026-02-09 01:01:26',4,NULL),
(846,4,'660020',4.0000,'2026-02-12 14:57:42',4,NULL),
(847,4,'660018',2.0000,'2026-02-12 14:57:42',4,NULL),
(848,4,'660016',0.0000,'2026-02-10 17:14:14',4,NULL),
(849,4,'660012',0.0000,'2026-02-08 15:15:45',4,NULL),
(850,4,'660013',2.0000,'2026-02-12 14:57:42',4,NULL),
(851,4,'660014',0.0000,'2026-02-10 17:15:38',4,NULL),
(852,4,'660015',1.0000,'2026-02-12 14:57:42',4,NULL),
(853,4,'660017',3.0000,'2026-02-12 14:57:42',4,NULL),
(854,4,'660030',4.0000,'2026-02-12 14:57:42',4,NULL),
(855,4,'660031',0.0000,'2026-02-09 01:02:33',4,NULL),
(867,4,'881108',1.0000,'2026-02-12 14:57:42',4,NULL),
(941,4,'660099',0.0000,'2026-02-04 13:40:54',4,NULL),
(961,4,'756058841446',0.0000,'2026-02-11 22:57:10',4,NULL),
(994,4,'756058841480',0.0000,'2026-02-02 01:39:04',4,NULL),
(995,4,'660010',3.0000,'2026-02-12 14:57:42',4,NULL),
(998,4,'756058841478',0.0000,'2026-02-04 15:22:30',4,NULL),
(999,4,'660098',0.0000,'2026-02-02 01:54:53',4,NULL),
(1000,4,'660043',0.0000,'2026-02-04 14:40:34',4,NULL),
(1033,4,'660097',0.0000,'2026-02-03 00:12:03',4,NULL),
(1046,4,'660096',2.0000,'2026-02-12 14:57:42',4,NULL),
(1049,4,'660094',0.0000,'2026-02-04 13:40:54',4,NULL),
(1053,4,'660095',0.0000,'2026-02-03 00:12:19',4,NULL),
(1054,4,'660093',0.0000,'2026-02-07 14:49:29',4,NULL),
(1058,4,'990091',0.0000,'2026-02-07 15:20:45',4,NULL),
(1139,4,'660090',2.0000,'2026-02-12 14:57:42',4,NULL),
(1141,4,'660089',22.0000,'2026-02-12 14:57:42',4,NULL),
(1142,4,'660088',7.0000,'2026-02-12 14:57:42',4,NULL),
(1143,4,'660087',0.0000,'2026-02-08 15:13:53',4,NULL),
(1145,4,'660100',19.0000,'2026-02-12 14:57:42',4,NULL),
(1147,4,'660101',8.0000,'2026-02-12 14:57:42',4,NULL),
(1148,4,'660102',2.0000,'2026-02-12 14:57:42',4,NULL),
(1149,4,'660103',7.0000,'2026-02-12 14:57:42',4,NULL),
(1150,4,'660104',9.0000,'2026-02-12 14:57:42',4,NULL),
(1205,4,'440001',0.0000,'2026-02-08 15:15:45',4,NULL);
/*!40000 ALTER TABLE `stock_almacen` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sucursales`
--

DROP TABLE IF EXISTS `sucursales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sucursales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_empresa` int(11) NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `codigo_externo` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_empresa` (`id_empresa`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sucursales`
--

LOCK TABLES `sucursales` WRITE;
/*!40000 ALTER TABLE `sucursales` DISABLE KEYS */;
INSERT INTO `sucursales` VALUES
(1,1,'marinero','1'),
(2,1,'roly','2');
/*!40000 ALTER TABLE `sucursales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sync_journal`
--

DROP TABLE IF EXISTS `sync_journal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sync_journal` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fecha_evento` datetime DEFAULT current_timestamp(),
  `tabla` varchar(50) NOT NULL,
  `accion` varchar(10) NOT NULL,
  `registro_uuid` char(36) NOT NULL,
  `datos_json` longtext NOT NULL,
  `origen_sucursal_id` int(11) NOT NULL,
  `sincronizado` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sync` (`sincronizado`),
  KEY `idx_fecha` (`fecha_evento`)
) ENGINE=InnoDB AUTO_INCREMENT=543 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sync_journal`
--

LOCK TABLES `sync_journal` WRITE;
/*!40000 ALTER TABLE `sync_journal` DISABLE KEYS */;
INSERT INTO `sync_journal` VALUES
(1,'2026-02-04 15:28:18','productos','UPDATE','54e1f60d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660014\", \"nombre\": \"Albondigas pqt\", \"costo\": 220.00, \"precio\": 360.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f60d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(2,'2026-02-04 15:28:19','productos','UPDATE','54e261ba-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991145\", \"nombre\": \"Almibar (1:1,5)\", \"costo\": 0.36, \"precio\": 0.46, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e261ba-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(3,'2026-02-04 15:28:20','productos','UPDATE','54e261ba-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991145\", \"nombre\": \"Almibar (1:1,5)\", \"costo\": 0.36, \"precio\": 0.46, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e261ba-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(4,'2026-02-04 15:28:26','productos','UPDATE','54e1f914-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660020\", \"nombre\": \"Arroz 1kg pqt\", \"costo\": 560.00, \"precio\": 650.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f914-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(5,'2026-02-04 15:28:27','productos','UPDATE','54e1f812-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660018\", \"nombre\": \"Azucar 1kg pqt\", \"costo\": 580.00, \"precio\": 680.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f812-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(6,'2026-02-04 15:28:36','productos','UPDATE','54e20ca1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660097\", \"nombre\": \"Bolsa de 8 panes\", \"costo\": 160.00, \"precio\": 200.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20ca1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(7,'2026-02-04 15:28:40','productos','UPDATE','54e1f589-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660013\", \"nombre\": \"Cabezote\", \"costo\": 35.00, \"precio\": 80.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1f589-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(8,'2026-02-04 15:28:43','productos','UPDATE','54e20f35-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660101\", \"nombre\": \"Cafe en sobre\", \"costo\": 25.00, \"precio\": 40.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20f35-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(9,'2026-02-04 15:28:45','productos','UPDATE','54e20921-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660088\", \"nombre\": \"Caldo de pollo sobrecito\", \"costo\": 18.00, \"precio\": 30.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20921-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(10,'2026-02-04 15:28:52','productos','UPDATE','54e1ffcb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660034\", \"nombre\": \"Caramelos 10\", \"costo\": 6.00, \"precio\": 10.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1ffcb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(11,'2026-02-04 15:28:53','productos','UPDATE','54e1fd65-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660029\", \"nombre\": \"Caramelos de 15\", \"costo\": 8.00, \"precio\": 15.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1fd65-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(12,'2026-02-04 15:28:54','productos','UPDATE','54e1efce-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660002\", \"nombre\": \"Carritos chocolate\", \"costo\": 25.00, \"precio\": 40.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1efce-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(13,'2026-02-04 15:28:56','productos','UPDATE','54e201b9-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660038\", \"nombre\": \"Cerveza Beer azul\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e201b9-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(14,'2026-02-04 15:28:57','productos','UPDATE','54e1fed4-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660032\", \"nombre\": \"Cerveza Esple\", \"costo\": 190.00, \"precio\": 230.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e1fed4-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(15,'2026-02-04 15:28:58','productos','UPDATE','54e2013f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660037\", \"nombre\": \"Cerveza La Fria\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e2013f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(16,'2026-02-04 15:28:59','productos','UPDATE','54e21ca6-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"990091\", \"nombre\": \"Cerveza Presidente\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e21ca6-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(17,'2026-02-04 15:29:01','productos','UPDATE','54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660023\", \"nombre\": \"Cerveza Shekels\", \"costo\": 213.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(18,'2026-02-04 15:29:05','productos','UPDATE','54e20047-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660035\", \"nombre\": \"chicle 30\", \"costo\": 15.00, \"precio\": 30.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e20047-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(19,'2026-02-04 15:29:08','productos','UPDATE','54e1ff4f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660033\", \"nombre\": \"Chupa chus 40\", \"costo\": 20.00, \"precio\": 40.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1ff4f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(20,'2026-02-04 15:29:09','productos','UPDATE','54e1ecd1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660001\", \"nombre\": \"Chupa chus grandes\", \"costo\": 35.00, \"precio\": 70.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1ecd1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(21,'2026-02-04 15:29:12','productos','UPDATE','54e2112e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841446\", \"nombre\": \"COCA COLA GRANDE\", \"costo\": 120.00, \"precio\": 150.00, \"categoria\": \"Bebidas\", \"activo\": 1, \"uuid\": \"54e2112e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(22,'2026-02-04 15:29:14','productos','UPDATE','54e2112e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841446\", \"nombre\": \"COCA COLA GRANDE\", \"costo\": 120.00, \"precio\": 150.00, \"categoria\": \"Bebidas\", \"activo\": 1, \"uuid\": \"54e2112e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(23,'2026-02-04 15:29:29','productos','UPDATE','54e1f796-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660017\", \"nombre\": \"Croquetas pollo pqt\", \"costo\": 72.00, \"precio\": 140.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f796-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(24,'2026-02-04 15:29:30','productos','UPDATE','54e1fe58-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660031\", \"nombre\": \"Croquetas Buffet\", \"costo\": 80.00, \"precio\": 160.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1fe58-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(25,'2026-02-04 15:29:32','productos','UPDATE','54e210ac-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660104\", \"nombre\": \"Donas Donuts\", \"costo\": 120.00, \"precio\": 160.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e210ac-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(26,'2026-02-04 15:29:35','productos','UPDATE','54e202b1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660040\", \"nombre\": \"Dulce 3 leches vaso 7oz\", \"costo\": 150.00, \"precio\": 250.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e202b1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(27,'2026-02-04 15:29:39','productos','UPDATE','54e1f490-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660011\", \"nombre\": \"Energizante\", \"costo\": 210.00, \"precio\": 280.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e1f490-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(28,'2026-02-04 15:29:40','productos','UPDATE','54e1f211-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660006\", \"nombre\": \"Espagetis 500g\", \"costo\": 230.00, \"precio\": 280.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f211-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(29,'2026-02-04 15:29:41','productos','UPDATE','54e1fbf7-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660026\", \"nombre\": \"Fanguito\", \"costo\": 430.00, \"precio\": 580.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1fbf7-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(30,'2026-02-04 15:29:44','productos','UPDATE','54e20b21-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660094\", \"nombre\": \"Gacenigas mini\", \"costo\": 200.00, \"precio\": 280.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20b21-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(31,'2026-02-04 15:29:46','productos','UPDATE','54e1f310-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660008\", \"nombre\": \"Galleta Biskiato minion\", \"costo\": 150.00, \"precio\": 180.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f310-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(32,'2026-02-04 15:29:46','productos','UPDATE','54e1f412-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660010\", \"nombre\": \"Galletas Blackout azul\", \"costo\": 130.00, \"precio\": 160.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f412-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(33,'2026-02-04 15:29:47','productos','UPDATE','54e1f190-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660005\", \"nombre\": \"Galletas Porleo\", \"costo\": 130.00, \"precio\": 180.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f190-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(34,'2026-02-04 15:29:48','productos','UPDATE','54e1f390-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660009\", \"nombre\": \"Galletas Rellenitas\", \"costo\": 120.00, \"precio\": 150.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f390-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(35,'2026-02-04 15:29:49','productos','UPDATE','54e208a4-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660087\", \"nombre\": \"Galletas Saltitacos\", \"costo\": 62.53, \"precio\": 200.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e208a4-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(36,'2026-02-04 15:29:58','productos','UPDATE','54e220e5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991108\", \"nombre\": \"guayaba en barra\", \"costo\": 0.85, \"precio\": 1.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e220e5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(37,'2026-02-04 15:29:58','productos','UPDATE','54e220e5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991108\", \"nombre\": \"guayaba en barra\", \"costo\": 0.85, \"precio\": 1.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e220e5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(38,'2026-02-04 15:30:03','productos','UPDATE','54e1f697-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660015\", \"nombre\": \"Hamgurgesa 5u pqt\", \"costo\": 300.00, \"precio\": 500.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f697-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(39,'2026-02-04 15:30:03','productos','UPDATE','54e21b15-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881108\", \"nombre\": \"Hamburguesas pollo\", \"costo\": 292.71, \"precio\": 480.00, \"categoria\": \"CONGELADOS\", \"activo\": 1, \"uuid\": \"54e21b15-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(40,'2026-02-04 15:30:04','productos','UPDATE','54e21b15-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881108\", \"nombre\": \"Hamburguesas pollo\", \"costo\": 292.71, \"precio\": 480.00, \"categoria\": \"CONGELADOS\", \"activo\": 1, \"uuid\": \"54e21b15-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(41,'2026-02-04 15:30:05','productos','UPDATE','54e21b15-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881108\", \"nombre\": \"Hamburguesas pollo\", \"costo\": 292.71, \"precio\": 480.00, \"categoria\": \"CONGELADOS\", \"activo\": 1, \"uuid\": \"54e21b15-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(42,'2026-02-04 15:30:11','productos','UPDATE','54e20827-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660046\", \"nombre\": \"Harina 1kg\", \"costo\": 500.00, \"precio\": 600.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20827-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(43,'2026-02-04 15:30:16','productos','UPDATE','54e20235-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660039\", \"nombre\": \"Helado Mousse 7oz\", \"costo\": 90.00, \"precio\": 150.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20235-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(44,'2026-02-04 15:30:17','productos','UPDATE','54e1f717-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660016\", \"nombre\": \"Higado de pollo pqt\", \"costo\": 620.00, \"precio\": 730.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f717-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(45,'2026-02-04 15:30:19','productos','UPDATE','54e21032-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660103\", \"nombre\": \"jabon de bano\", \"costo\": 130.00, \"precio\": 150.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e21032-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(46,'2026-02-04 15:30:20','productos','UPDATE','54e20fb5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660102\", \"nombre\": \"Jabon de carbon\", \"costo\": 170.00, \"precio\": 200.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20fb5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(47,'2026-02-04 15:30:21','productos','UPDATE','54e200c3-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660036\", \"nombre\": \"jugo cajita 200ml\", \"costo\": 135.00, \"precio\": 180.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e200c3-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(48,'2026-02-04 15:30:23','productos','UPDATE','54e1fb7d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660025\", \"nombre\": \"Leche condensada\", \"costo\": 430.00, \"precio\": 520.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1fb7d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(49,'2026-02-04 15:30:26','productos','UPDATE','54e20a18-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660090\", \"nombre\": \"lomo en bandeja 1lb\", \"costo\": 1150.00, \"precio\": 1300.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e20a18-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(50,'2026-02-04 15:30:29','productos','UPDATE','54e20da7-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660099\", \"nombre\": \"Marquesita\", \"costo\": 65.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20da7-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(51,'2026-02-04 15:30:30','productos','UPDATE','54e1fc6f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660027\", \"nombre\": \"Marranetas\", \"costo\": 166.00, \"precio\": 240.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1fc6f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(52,'2026-02-04 15:30:34','productos','UPDATE','54e21241-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841477\", \"nombre\": \"melcocha\", \"costo\": 20.00, \"precio\": 40.00, \"categoria\": \"Pruebas\", \"activo\": 1, \"uuid\": \"54e21241-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(53,'2026-02-04 15:30:38','productos','UPDATE','54e21a94-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881107\", \"nombre\": \"Mini Cake\", \"costo\": 567.90, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21a94-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(54,'2026-02-04 15:30:38','productos','UPDATE','54e21a94-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881107\", \"nombre\": \"Mini Cake\", \"costo\": 567.90, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21a94-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(55,'2026-02-04 15:30:39','productos','UPDATE','54e21a94-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881107\", \"nombre\": \"Mini Cake\", \"costo\": 567.90, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21a94-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(56,'2026-02-04 15:30:40','productos','UPDATE','54e21a94-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881107\", \"nombre\": \"Mini Cake\", \"costo\": 567.90, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21a94-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(57,'2026-02-04 15:30:40','productos','UPDATE','54e21a94-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881107\", \"nombre\": \"Mini Cake\", \"costo\": 567.90, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21a94-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(58,'2026-02-04 15:30:52','productos','UPDATE','54e20721-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660044\", \"nombre\": \"Minicake Fanguito\", \"costo\": 800.00, \"precio\": 1700.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20721-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(59,'2026-02-04 15:30:53','productos','UPDATE','54e207ad-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660045\", \"nombre\": \"Minicake Bombom\", \"costo\": 1105.00, \"precio\": 2000.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e207ad-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(60,'2026-02-04 15:30:54','productos','UPDATE','54e20629-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660043\", \"nombre\": \"Minicake 18cm\", \"costo\": 700.00, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20629-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(61,'2026-02-04 15:30:55','productos','UPDATE','54e215d5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881102\", \"nombre\": \"Minicake fanguito\", \"costo\": 565.04, \"precio\": 1700.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e215d5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(62,'2026-02-04 15:31:05','productos','UPDATE','54e20eb0-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660100\", \"nombre\": \"ojitos gummy gum\", \"costo\": 40.00, \"precio\": 80.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e20eb0-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(63,'2026-02-04 15:31:07','productos','UPDATE','54e205ab-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660042\", \"nombre\": \"Pan de Gloria\", \"costo\": 35.00, \"precio\": 80.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e205ab-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(64,'2026-02-04 15:31:10','productos','UPDATE','54e20ba2-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660095\", \"nombre\": \"Panque de chocolate\", \"costo\": 70.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20ba2-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(65,'2026-02-04 15:31:13','productos','UPDATE','54e2099d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660089\", \"nombre\": \"pastillita pollo con tomate\", \"costo\": 20.00, \"precio\": 40.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e2099d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(66,'2026-02-04 15:31:17','productos','UPDATE','54e1f893-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660019\", \"nombre\": \"Pinguinos gelatina\", \"costo\": 33.00, \"precio\": 60.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f893-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(67,'2026-02-04 15:31:19','productos','UPDATE','54e20a99-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660093\", \"nombre\": \"Pqt de 4 panques\", \"costo\": 180.00, \"precio\": 280.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20a99-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(68,'2026-02-04 15:31:22','productos','UPDATE','54e20c23-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660096\", \"nombre\": \"pqt torticas 6u\", \"costo\": 120.00, \"precio\": 220.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20c23-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(69,'2026-02-04 15:31:27','productos','UPDATE','54e1f07e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660003\", \"nombre\": \"Pure de tomate 400g lata\", \"costo\": 340.00, \"precio\": 360.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f07e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(70,'2026-02-04 15:31:31','productos','UPDATE','54e1f98e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660021\", \"nombre\": \"Refresco cola lata 300ml\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e1f98e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(71,'2026-02-04 15:31:32','productos','UPDATE','54e211be-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841453\", \"nombre\": \"Refresco de naranja\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"Bebidas\", \"activo\": 1, \"uuid\": \"54e211be-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(72,'2026-02-04 15:31:36','productos','UPDATE','54e1f294-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660007\", \"nombre\": \"Refresco en polvo\", \"costo\": 20.00, \"precio\": 40.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f294-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(73,'2026-02-04 15:31:41','productos','UPDATE','54e1fa0e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660022\", \"nombre\": \"Refresco limon lata 300ml\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e1fa0e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(74,'2026-02-04 15:31:43','productos','UPDATE','54e2b30b-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991188\", \"nombre\": \"Refresco polvo Mayar\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2b30b-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(75,'2026-02-04 15:31:44','productos','UPDATE','54e2b30b-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991188\", \"nombre\": \"Refresco polvo Mayar\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2b30b-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(76,'2026-02-04 15:31:53','productos','UPDATE','54e1fddd-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660030\", \"nombre\": \"San jacobo\", \"costo\": 180.00, \"precio\": 280.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1fddd-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(77,'2026-02-04 15:31:56','productos','UPDATE','54e1f50f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660012\", \"nombre\": \"Tartaleta\", \"costo\": 50.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1f50f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(78,'2026-02-04 15:32:00','productos','UPDATE','54e1fceb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660028\", \"nombre\": \"Tartaletas especiales\", \"costo\": 90.00, \"precio\": 140.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1fceb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(79,'2026-02-05 08:10:45','productos','UPDATE','54e1fb03-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660024\", \"nombre\": \"Cerveza Sta Isabel\", \"costo\": 205.00, \"precio\": 230.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e1fb03-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(80,'2026-02-05 08:10:50','productos','UPDATE','54e20b21-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660094\", \"nombre\": \"Gacenigas mini\", \"costo\": 200.00, \"precio\": 280.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20b21-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(81,'2026-02-05 08:10:55','productos','UPDATE','54e1f10c-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660004\", \"nombre\": \"Mini Whiskey 200ml\", \"costo\": 370.00, \"precio\": 400.00, \"categoria\": \"BEBIDAS_A\", \"activo\": 1, \"uuid\": \"54e1f10c-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(82,'2026-02-05 08:10:58','productos','UPDATE','54e20ba2-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660095\", \"nombre\": \"Panque de chocolate\", \"costo\": 70.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20ba2-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(83,'2026-02-05 08:11:02','productos','UPDATE','54e20a99-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660093\", \"nombre\": \"Pqt de 4 panques\", \"costo\": 180.00, \"precio\": 280.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20a99-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(84,'2026-02-05 08:11:06','productos','UPDATE','54e20c23-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660096\", \"nombre\": \"pqt torticas 6u\", \"costo\": 120.00, \"precio\": 220.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20c23-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(85,'2026-02-05 08:11:48','productos','UPDATE','54e1f914-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660020\", \"nombre\": \"Arroz 1kg pqt\", \"costo\": 560.00, \"precio\": 650.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f914-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(86,'2026-02-05 08:12:03','productos','UPDATE','54e1f812-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660018\", \"nombre\": \"Azucar 1kg pqt\", \"costo\": 580.00, \"precio\": 680.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f812-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(87,'2026-02-05 08:31:43','productos','UPDATE','54e20ca1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660097\", \"nombre\": \"Bolsa de 8 panes\", \"costo\": 160.00, \"precio\": 200.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20ca1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(88,'2026-02-05 08:32:28','productos','UPDATE','54e1f589-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660013\", \"nombre\": \"Cabezote\", \"costo\": 35.00, \"precio\": 80.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1f589-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(89,'2026-02-05 08:33:20','productos','UPDATE','54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660023\", \"nombre\": \"Cerveza Shekels\", \"costo\": 213.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(90,'2026-02-05 08:38:07','productos','UPDATE','54e20d22-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660098\", \"nombre\": \"Cake 2900\", \"costo\": 2000.00, \"precio\": 2900.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20d22-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(116,'2026-02-06 11:00:24','ventas_cabecera','INSERT','f28d7f06-0374-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-06 11:00:24\", \"total\": 80.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"f28d7f06-0374-11f1-b0a7-0affd1a0dee5\"}',4,0),
(117,'2026-02-06 11:00:24','kardex','INSERT','f28dccc0-0374-11f1-b0a7-0affd1a0dee5','{\"id_producto\": \"660033\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #70\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-06 11:00:24\", \"uuid\": \"f28dccc0-0374-11f1-b0a7-0affd1a0dee5\"}',4,0),
(118,'2026-02-06 12:10:31','ventas_cabecera','INSERT','be0ea5b4-037e-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-06 12:10:31\", \"total\": 560.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"be0ea5b4-037e-11f1-b0a7-0affd1a0dee5\"}',4,0),
(119,'2026-02-06 12:10:31','kardex','INSERT','84d9f676-f7b6-48eb-b31e-48a43588d60b','{\"id_producto\": \"660011\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #71\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-06 12:10:31\", \"uuid\": \"84d9f676-f7b6-48eb-b31e-48a43588d60b\"}',4,0),
(120,'2026-02-06 16:02:51','ventas_cabecera','INSERT','32f56a19-039f-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-06 16:02:51\", \"total\": 3520.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"32f56a19-039f-11f1-b0a7-0affd1a0dee5\"}',4,0),
(121,'2026-02-06 16:02:51','kardex','INSERT','e912fa71-885b-432a-901d-969b92a37d2a','{\"id_producto\": \"660033\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #72\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-06 16:02:51\", \"uuid\": \"e912fa71-885b-432a-901d-969b92a37d2a\"}',4,0),
(122,'2026-02-06 16:02:51','kardex','INSERT','5f37d981-bc9e-40e0-a077-5381bed96cb9','{\"id_producto\": \"660011\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #72\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-06 16:02:51\", \"uuid\": \"5f37d981-bc9e-40e0-a077-5381bed96cb9\"}',4,0),
(123,'2026-02-06 16:02:51','kardex','INSERT','0fc10a02-fc41-486e-b129-edf921d6af4e','{\"id_producto\": \"660021\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #72\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-06 16:02:51\", \"uuid\": \"0fc10a02-fc41-486e-b129-edf921d6af4e\"}',4,0),
(124,'2026-02-06 16:02:51','kardex','INSERT','621a0de6-a82c-4196-96a4-93a97ac6af22','{\"id_producto\": \"660037\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -10.0000, \"referencia\": \"VENTA #72\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-06 16:02:51\", \"uuid\": \"621a0de6-a82c-4196-96a4-93a97ac6af22\"}',4,0),
(125,'2026-02-06 16:02:51','ventas_cabecera','INSERT','3309cfa9-039f-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-06 16:02:51\", \"total\": 1920.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"3309cfa9-039f-11f1-b0a7-0affd1a0dee5\"}',4,0),
(126,'2026-02-06 16:02:51','kardex','INSERT','c04a4f28-3b63-429c-9441-ef8db57e47d3','{\"id_producto\": \"990091\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -8.0000, \"referencia\": \"VENTA #73\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-06 16:02:51\", \"uuid\": \"c04a4f28-3b63-429c-9441-ef8db57e47d3\"}',4,0),
(131,'2026-02-07 09:02:05','ventas_cabecera','INSERT','959c2084-042d-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:02:05\", \"total\": -80.00, \"metodo_pago\": \"Devolución\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"DEVOLUCIÓN\", \"uuid\": \"959c2084-042d-11f1-b0a7-0affd1a0dee5\"}',4,0),
(132,'2026-02-07 09:02:05','kardex','INSERT','959d117f-042d-11f1-b0a7-0affd1a0dee5','{\"id_producto\": \"660033\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"DEVOLUCION\", \"cantidad\": 2.0000, \"referencia\": \"REFUND_ITEM_219\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:02:05\", \"uuid\": \"959d117f-042d-11f1-b0a7-0affd1a0dee5\"}',4,0),
(133,'2026-02-07 09:07:50','ventas_cabecera','INSERT','63174dd3-042e-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:07:50\", \"total\": -560.00, \"metodo_pago\": \"Devolución\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"DEVOLUCIÓN\", \"uuid\": \"63174dd3-042e-11f1-b0a7-0affd1a0dee5\"}',4,0),
(134,'2026-02-07 09:07:50','kardex','INSERT','kdx_6987473641b1e','{\"id_producto\": \"660011\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"DEVOLUCION\", \"cantidad\": 2.0000, \"referencia\": \"REFUND_ITEM_220\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:07:50\", \"uuid\": \"kdx_6987473641b1e\"}',4,0),
(141,'2026-02-07 09:45:26','ventas_cabecera','INSERT','a41b0f7f-0433-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:45:26\", \"total\": 3600.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"a41b0f7f-0433-11f1-b0a7-0affd1a0dee5\"}',4,0),
(142,'2026-02-07 09:45:26','kardex','INSERT','kdx_69875006ca84c','{\"id_producto\": \"660023\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -9.0000, \"referencia\": \"VENTA #86\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:45:26\", \"uuid\": \"kdx_69875006ca84c\"}',4,0),
(143,'2026-02-07 09:45:26','kardex','INSERT','kdx_69875006ccb6e','{\"id_producto\": \"660036\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -8.0000, \"referencia\": \"VENTA #86\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:45:26\", \"uuid\": \"kdx_69875006ccb6e\"}',4,0),
(144,'2026-02-07 09:46:06','ventas_cabecera','INSERT','bc079dfa-0433-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:46:06\", \"total\": 1040.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"bc079dfa-0433-11f1-b0a7-0affd1a0dee5\"}',4,0),
(145,'2026-02-07 09:46:06','kardex','INSERT','kdx_6987502eec81f','{\"id_producto\": \"660025\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #87\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:46:06\", \"uuid\": \"kdx_6987502eec81f\"}',4,0),
(146,'2026-02-07 09:47:06','ventas_cabecera','INSERT','dfc2b907-0433-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:47:06\", \"total\": 890.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"dfc2b907-0433-11f1-b0a7-0affd1a0dee5\"}',4,0),
(147,'2026-02-07 09:47:06','kardex','INSERT','kdx_6987506adf187','{\"id_producto\": \"660020\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #88\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:47:06\", \"uuid\": \"kdx_6987506adf187\"}',4,0),
(148,'2026-02-07 09:47:06','kardex','INSERT','kdx_6987506adfb74','{\"id_producto\": \"660027\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #88\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:47:06\", \"uuid\": \"kdx_6987506adfb74\"}',4,0),
(149,'2026-02-07 09:48:07','productos','UPDATE','54e1fc6f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660027\", \"nombre\": \"Marranetas\", \"costo\": 162.57, \"precio\": 240.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1fc6f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(150,'2026-02-07 09:48:07','kardex','INSERT','kdx_698750a756b08','{\"id_producto\": \"660027\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 24.0000, \"referencia\": \"COMPRA #20\", \"costo_unitario\": 160.00, \"fecha\": \"2026-02-04 09:48:07\", \"uuid\": \"kdx_698750a756b08\"}',4,0),
(151,'2026-02-07 09:48:07','productos','UPDATE','54e1f211-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660006\", \"nombre\": \"Espagetis 500g\", \"costo\": 212.00, \"precio\": 280.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f211-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(152,'2026-02-07 09:48:07','kardex','INSERT','kdx_698750a7587f9','{\"id_producto\": \"660006\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 12.0000, \"referencia\": \"COMPRA #20\", \"costo_unitario\": 212.00, \"fecha\": \"2026-02-04 09:48:07\", \"uuid\": \"kdx_698750a7587f9\"}',4,0),
(153,'2026-02-07 09:49:29','ventas_cabecera','INSERT','34aa7e0a-0434-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:49:29\", \"total\": 2670.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"34aa7e0a-0434-11f1-b0a7-0affd1a0dee5\"}',4,0),
(154,'2026-02-07 09:49:29','kardex','INSERT','kdx_698750f958485','{\"id_producto\": \"660034\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -20.0000, \"referencia\": \"VENTA #89\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:49:29\", \"uuid\": \"kdx_698750f958485\"}',4,0),
(155,'2026-02-07 09:49:29','kardex','INSERT','kdx_698750f958f3b','{\"id_producto\": \"660015\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #89\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:49:29\", \"uuid\": \"kdx_698750f958f3b\"}',4,0),
(156,'2026-02-07 09:49:29','kardex','INSERT','kdx_698750f95989c','{\"id_producto\": \"660014\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #89\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:49:29\", \"uuid\": \"kdx_698750f95989c\"}',4,0),
(157,'2026-02-07 09:49:29','kardex','INSERT','kdx_698750f95a21b','{\"id_producto\": \"660009\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #89\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:49:29\", \"uuid\": \"kdx_698750f95a21b\"}',4,0),
(158,'2026-02-07 09:49:29','kardex','INSERT','kdx_698750f95ab3c','{\"id_producto\": \"660093\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #89\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:49:29\", \"uuid\": \"kdx_698750f95ab3c\"}',4,0),
(159,'2026-02-07 09:49:29','kardex','INSERT','kdx_698750f95b41d','{\"id_producto\": \"660010\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #89\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:49:29\", \"uuid\": \"kdx_698750f95b41d\"}',4,0),
(160,'2026-02-07 09:50:01','ventas_cabecera','INSERT','47abbfbb-0434-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:50:01\", \"total\": 1700.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"47abbfbb-0434-11f1-b0a7-0affd1a0dee5\"}',4,0),
(161,'2026-02-07 09:50:01','kardex','INSERT','kdx_698751193d302','{\"id_producto\": \"660012\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -17.0000, \"referencia\": \"VENTA #90\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:50:01\", \"uuid\": \"kdx_698751193d302\"}',4,0),
(162,'2026-02-07 09:50:14','ventas_cabecera','INSERT','4f6b4d44-0434-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:50:14\", \"total\": 1300.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"4f6b4d44-0434-11f1-b0a7-0affd1a0dee5\"}',4,0),
(163,'2026-02-07 09:50:14','kardex','INSERT','kdx_698751263c094','{\"id_producto\": \"660090\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #91\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:50:14\", \"uuid\": \"kdx_698751263c094\"}',4,0),
(164,'2026-02-07 09:50:38','ventas_cabecera','INSERT','5e28fe26-0434-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:50:38\", \"total\": 60.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"5e28fe26-0434-11f1-b0a7-0affd1a0dee5\"}',4,0),
(165,'2026-02-07 09:50:38','kardex','INSERT','kdx_6987513eefb80','{\"id_producto\": \"660088\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #92\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:50:38\", \"uuid\": \"kdx_6987513eefb80\"}',4,0),
(166,'2026-02-07 09:50:49','ventas_cabecera','INSERT','64942fa3-0434-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:50:49\", \"total\": 80.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"64942fa3-0434-11f1-b0a7-0affd1a0dee5\"}',4,0),
(167,'2026-02-07 09:50:49','kardex','INSERT','kdx_69875149b621c','{\"id_producto\": \"660089\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #93\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:50:49\", \"uuid\": \"kdx_69875149b621c\"}',4,0),
(169,'2026-02-07 09:57:23','mermas_cabecera','INSERT','4f6f8d21-0435-11f1-b0a7-0affd1a0dee5','{\"usuario\": \"Admin\", \"motivo_general\": \"General\", \"total_costo_perdida\": 1563.25, \"fecha_registro\": \"2026-02-07 09:57:23\", \"id_sucursal\": 4, \"uuid\": \"4f6f8d21-0435-11f1-b0a7-0affd1a0dee5\"}',4,0),
(170,'2026-02-07 09:57:23','kardex','INSERT','kdx_698752d3bd385','{\"id_producto\": \"660087\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"SALIDA\", \"cantidad\": -25.0000, \"referencia\": \"MERMA #14\", \"costo_unitario\": 62.53, \"fecha\": \"2026-02-07 09:57:23\", \"uuid\": \"kdx_698752d3bd385\"}',4,0),
(171,'2026-02-07 09:58:21','ventas_cabecera','INSERT','721d6f66-0435-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:58:21\", \"total\": 600.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"721d6f66-0435-11f1-b0a7-0affd1a0dee5\"}',4,0),
(172,'2026-02-07 09:58:21','kardex','INSERT','kdx_6987530de8916','{\"id_producto\": \"660087\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -3.0000, \"referencia\": \"VENTA #94\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:58:21\", \"uuid\": \"kdx_6987530de8916\"}',4,0),
(173,'2026-02-07 09:58:59','ventas_cabecera','INSERT','885624d6-0435-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:58:59\", \"total\": 240.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"885624d6-0435-11f1-b0a7-0affd1a0dee5\"}',4,0),
(174,'2026-02-07 09:58:59','kardex','INSERT','kdx_69875333392d5','{\"id_producto\": \"660100\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -3.0000, \"referencia\": \"VENTA #95\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:58:59\", \"uuid\": \"kdx_69875333392d5\"}',4,0),
(175,'2026-02-07 09:59:05','ventas_cabecera','INSERT','8be25d2f-0435-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:59:05\", \"total\": 80.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"8be25d2f-0435-11f1-b0a7-0affd1a0dee5\"}',4,0),
(176,'2026-02-07 09:59:05','kardex','INSERT','kdx_698753392d796','{\"id_producto\": \"660007\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #96\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:59:05\", \"uuid\": \"kdx_698753392d796\"}',4,0),
(177,'2026-02-07 09:59:26','ventas_cabecera','INSERT','984f9989-0435-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:59:26\", \"total\": 350.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"984f9989-0435-11f1-b0a7-0affd1a0dee5\"}',4,0),
(178,'2026-02-07 09:59:26','kardex','INSERT','kdx_6987534e087f5','{\"id_producto\": \"660102\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #97\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:59:26\", \"uuid\": \"kdx_6987534e087f5\"}',4,0),
(179,'2026-02-07 09:59:26','kardex','INSERT','kdx_6987534e092a2','{\"id_producto\": \"660103\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #97\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:59:26\", \"uuid\": \"kdx_6987534e092a2\"}',4,0),
(180,'2026-02-07 09:59:36','ventas_cabecera','INSERT','9e7e9738-0435-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 09:59:36\", \"total\": 280.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"9e7e9738-0435-11f1-b0a7-0affd1a0dee5\"}',4,0),
(181,'2026-02-07 09:59:36','kardex','INSERT','kdx_6987535864d3a','{\"id_producto\": \"660006\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #98\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 09:59:36\", \"uuid\": \"kdx_6987535864d3a\"}',4,0),
(182,'2026-02-07 10:18:53','ventas_cabecera','INSERT','503a854a-0438-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 10:18:53\", \"total\": 180.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"503a854a-0438-11f1-b0a7-0affd1a0dee5\"}',4,0),
(183,'2026-02-07 10:18:53','kardex','INSERT','kdx_698757dd90381','{\"id_producto\": \"660001\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #99\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:18:53\", \"uuid\": \"kdx_698757dd90381\"}',4,0),
(184,'2026-02-07 10:18:53','kardex','INSERT','kdx_698757dd90e2d','{\"id_producto\": \"660033\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #99\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:18:53\", \"uuid\": \"kdx_698757dd90e2d\"}',4,0),
(185,'2026-02-07 10:19:40','ventas_cabecera','INSERT','6c5cfdca-0438-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 10:19:40\", \"total\": 1000.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"6c5cfdca-0438-11f1-b0a7-0affd1a0dee5\"}',4,0),
(186,'2026-02-07 10:19:40','kardex','INSERT','kdx_6987580cc1923','{\"id_producto\": \"660011\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #100\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:19:40\", \"uuid\": \"kdx_6987580cc1923\"}',4,0),
(187,'2026-02-07 10:19:40','kardex','INSERT','kdx_6987580cc2307','{\"id_producto\": \"660021\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #100\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:19:40\", \"uuid\": \"kdx_6987580cc2307\"}',4,0),
(188,'2026-02-07 10:19:40','kardex','INSERT','kdx_6987580cc2bf3','{\"id_producto\": \"660037\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #100\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:19:40\", \"uuid\": \"kdx_6987580cc2bf3\"}',4,0),
(189,'2026-02-07 10:20:45','ventas_cabecera','INSERT','930d47d6-0438-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 10:20:45\", \"total\": 240.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"930d47d6-0438-11f1-b0a7-0affd1a0dee5\"}',4,0),
(190,'2026-02-07 10:20:45','kardex','INSERT','kdx_6987584daba07','{\"id_producto\": \"990091\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #101\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:20:45\", \"uuid\": \"kdx_6987584daba07\"}',4,0),
(191,'2026-02-07 10:27:03','ventas_cabecera','INSERT','742738fc-0439-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 10:27:03\", \"total\": 2600.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"742738fc-0439-11f1-b0a7-0affd1a0dee5\"}',4,0),
(192,'2026-02-07 10:27:03','kardex','INSERT','kdx_698759c757a4c','{\"id_producto\": \"660023\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #102\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:27:03\", \"uuid\": \"kdx_698759c757a4c\"}',4,0),
(193,'2026-02-07 10:27:03','kardex','INSERT','kdx_698759c7583ac','{\"id_producto\": \"660036\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -6.0000, \"referencia\": \"VENTA #102\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:27:03\", \"uuid\": \"kdx_698759c7583ac\"}',4,0),
(194,'2026-02-07 10:27:03','kardex','INSERT','kdx_698759c758c35','{\"id_producto\": \"660025\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #102\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:27:03\", \"uuid\": \"kdx_698759c758c35\"}',4,0),
(195,'2026-02-07 10:27:03','kardex','INSERT','kdx_698759c7594c0','{\"id_producto\": \"660019\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #102\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:27:03\", \"uuid\": \"kdx_698759c7594c0\"}',4,0),
(196,'2026-02-07 10:28:05','ventas_cabecera','INSERT','9910c1d3-0439-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 10:28:05\", \"total\": 1970.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"9910c1d3-0439-11f1-b0a7-0affd1a0dee5\"}',4,0),
(197,'2026-02-07 10:28:05','kardex','INSERT','kdx_69875a05462e0','{\"id_producto\": \"660029\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #103\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:28:05\", \"uuid\": \"kdx_69875a05462e0\"}',4,0),
(198,'2026-02-07 10:28:05','kardex','INSERT','kdx_69875a0547b2e','{\"id_producto\": \"660034\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -5.0000, \"referencia\": \"VENTA #103\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:28:05\", \"uuid\": \"kdx_69875a0547b2e\"}',4,0),
(199,'2026-02-07 10:28:05','kardex','INSERT','kdx_69875a05483c4','{\"id_producto\": \"660015\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -3.0000, \"referencia\": \"VENTA #103\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:28:05\", \"uuid\": \"kdx_69875a05483c4\"}',4,0),
(200,'2026-02-07 10:28:05','kardex','INSERT','kdx_69875a0548c8b','{\"id_producto\": \"660014\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #103\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:28:05\", \"uuid\": \"kdx_69875a0548c8b\"}',4,0),
(201,'2026-02-07 10:28:33','ventas_cabecera','INSERT','aa15ba40-0439-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 10:28:33\", \"total\": 30.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"aa15ba40-0439-11f1-b0a7-0affd1a0dee5\"}',4,0),
(202,'2026-02-07 10:28:33','kardex','INSERT','kdx_69875a21cd642','{\"id_producto\": \"660035\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #104\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:28:33\", \"uuid\": \"kdx_69875a21cd642\"}',4,0),
(203,'2026-02-07 10:29:06','ventas_cabecera','INSERT','bd82d37b-0439-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 10:29:06\", \"total\": 1200.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"bd82d37b-0439-11f1-b0a7-0affd1a0dee5\"}',4,0),
(204,'2026-02-07 10:29:06','kardex','INSERT','kdx_69875a4269b49','{\"id_producto\": \"660012\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -12.0000, \"referencia\": \"VENTA #105\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:29:06\", \"uuid\": \"kdx_69875a4269b49\"}',4,0),
(205,'2026-02-07 10:29:32','ventas_cabecera','INSERT','cd070eae-0439-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 10:29:32\", \"total\": 30.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"cd070eae-0439-11f1-b0a7-0affd1a0dee5\"}',4,0),
(206,'2026-02-07 10:29:32','kardex','INSERT','kdx_69875a5c71a2a','{\"id_producto\": \"660088\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #106\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 10:29:32\", \"uuid\": \"kdx_69875a5c71a2a\"}',4,0),
(207,'2026-02-07 11:06:14','productos','INSERT','ed40cff0-043e-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"440001\", \"nombre\": \"Donas Grandes\", \"costo\": 90.00, \"precio\": 130.00, \"categoria\": \"DULCES\", \"activo\": 1, \"es_elaborado\": 1, \"es_servicio\": 0, \"id_empresa\": 1, \"uuid\": \"ed40cff0-043e-11f1-b0a7-0affd1a0dee5\"}',1,0),
(208,'2026-02-07 11:06:14','kardex','INSERT','kdx_698762f603f20','{\"id_producto\": \"440001\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 8.0000, \"referencia\": \"COMPRA #21\", \"costo_unitario\": 90.00, \"fecha\": \"2026-02-05 11:06:14\", \"uuid\": \"kdx_698762f603f20\"}',4,0),
(209,'2026-02-07 11:06:14','productos','UPDATE','54e1fbf7-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660026\", \"nombre\": \"Fanguito\", \"costo\": 430.00, \"precio\": 580.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1fbf7-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(210,'2026-02-07 11:06:14','kardex','INSERT','kdx_698762f605862','{\"id_producto\": \"660026\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 3.0000, \"referencia\": \"COMPRA #21\", \"costo_unitario\": 430.00, \"fecha\": \"2026-02-05 11:06:14\", \"uuid\": \"kdx_698762f605862\"}',4,0),
(211,'2026-02-07 11:11:53','ventas_cabecera','INSERT','b7541584-043f-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 11:11:52\", \"total\": 790.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"b7541584-043f-11f1-b0a7-0affd1a0dee5\"}',4,0),
(212,'2026-02-07 11:11:53','kardex','INSERT','kdx_698764490ac59','{\"id_producto\": \"660087\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #107\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 11:11:52\", \"uuid\": \"kdx_698764490ac59\"}',4,0),
(213,'2026-02-07 11:11:53','kardex','INSERT','kdx_698764490b542','{\"id_producto\": \"660103\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #107\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 11:11:52\", \"uuid\": \"kdx_698764490b542\"}',4,0),
(214,'2026-02-07 11:11:53','kardex','INSERT','kdx_698764490bd9e','{\"id_producto\": \"660007\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -11.0000, \"referencia\": \"VENTA #107\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 11:11:52\", \"uuid\": \"kdx_698764490bd9e\"}',4,0),
(215,'2026-02-07 11:12:27','ventas_cabecera','INSERT','cc096d3b-043f-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-07 11:12:27\", \"total\": 1040.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"cc096d3b-043f-11f1-b0a7-0affd1a0dee5\"}',4,0),
(216,'2026-02-07 11:12:27','kardex','INSERT','kdx_6987646bbfeed','{\"id_producto\": \"440001\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -8.0000, \"referencia\": \"VENTA #108\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-07 11:12:27\", \"uuid\": \"kdx_6987646bbfeed\"}',4,0),
(217,'2026-02-07 17:42:54','productos','UPDATE','54e225f1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991118\", \"nombre\": \"Aceite\", \"costo\": 1.20, \"precio\": 2.00, \"categoria\": \"Bodegon\", \"activo\": 1, \"uuid\": \"54e225f1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(218,'2026-02-07 17:43:11','productos','UPDATE','54e21e50-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991103\", \"nombre\": \"Harina trigo\", \"costo\": 0.50, \"precio\": 0.60, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e21e50-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(219,'2026-02-08 08:49:19','productos','UPDATE','54e1f796-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660017\", \"nombre\": \"Croquetas pollo pqt\", \"costo\": 82.00, \"precio\": 150.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f796-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(220,'2026-02-08 08:49:32','productos','UPDATE','54e1fe58-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660031\", \"nombre\": \"Croquetas Buffet\", \"costo\": 82.00, \"precio\": 170.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1fe58-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(221,'2026-02-08 08:49:42','productos','UPDATE','54e1fe58-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660031\", \"nombre\": \"Croquetas Buffet\", \"costo\": 82.00, \"precio\": 160.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1fe58-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(222,'2026-02-08 09:03:42','ventas_cabecera','INSERT','f9d4d5b0-04f6-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 09:03:42\", \"total\": 6590.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"f9d4d5b0-04f6-11f1-b0a7-0affd1a0dee5\"}',4,0),
(223,'2026-02-08 09:03:42','kardex','INSERT','kdx_698897be7e6f1','{\"id_producto\": \"660020\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -6.0000, \"referencia\": \"VENTA #109\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:03:42\", \"uuid\": \"kdx_698897be7e6f1\"}',4,0),
(224,'2026-02-08 09:03:42','kardex','INSERT','kdx_698897be7ef93','{\"id_producto\": \"660018\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #109\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:03:42\", \"uuid\": \"kdx_698897be7ef93\"}',4,0),
(225,'2026-02-08 09:03:42','kardex','INSERT','kdx_698897be7f76a','{\"id_producto\": \"660019\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -5.0000, \"referencia\": \"VENTA #109\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:03:42\", \"uuid\": \"kdx_698897be7f76a\"}',4,0),
(226,'2026-02-08 09:03:42','kardex','INSERT','kdx_698897be7ff1a','{\"id_producto\": \"660009\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #109\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:03:42\", \"uuid\": \"kdx_698897be7ff1a\"}',4,0),
(227,'2026-02-08 09:03:42','kardex','INSERT','kdx_698897be80772','{\"id_producto\": \"660034\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #109\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:03:42\", \"uuid\": \"kdx_698897be80772\"}',4,0),
(228,'2026-02-08 09:03:42','kardex','INSERT','kdx_698897be80f3b','{\"id_producto\": \"660100\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -3.0000, \"referencia\": \"VENTA #109\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:03:42\", \"uuid\": \"kdx_698897be80f3b\"}',4,0),
(229,'2026-02-08 09:03:42','kardex','INSERT','kdx_698897be816f1','{\"id_producto\": \"660090\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #109\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:03:42\", \"uuid\": \"kdx_698897be816f1\"}',4,0),
(230,'2026-02-08 09:06:29','productos','UPDATE','54e1f07e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660003\", \"nombre\": \"Pure de tomate 400g lata\", \"costo\": 360.00, \"precio\": 440.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f07e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(231,'2026-02-08 09:07:39','productos','UPDATE','54e1f589-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660013\", \"nombre\": \"Cabezote\", \"costo\": 35.00, \"precio\": 80.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1f589-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(232,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab1d392','{\"id_producto\": \"660013\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 16.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 35.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab1d392\"}',4,0),
(233,'2026-02-08 09:07:39','productos','UPDATE','54e1f914-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660020\", \"nombre\": \"Arroz 1kg pqt\", \"costo\": 570.00, \"precio\": 650.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f914-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(234,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab1e093','{\"id_producto\": \"660020\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 5.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 570.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab1e093\"}',4,0),
(235,'2026-02-08 09:07:39','productos','UPDATE','54e1f812-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660018\", \"nombre\": \"Azucar 1kg pqt\", \"costo\": 580.00, \"precio\": 680.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f812-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(236,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab1fd81','{\"id_producto\": \"660018\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 2.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 580.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab1fd81\"}',4,0),
(237,'2026-02-08 09:07:39','productos','UPDATE','54e20c23-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660096\", \"nombre\": \"pqt torticas 6u\", \"costo\": 120.00, \"precio\": 220.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20c23-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(238,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab20b0a','{\"id_producto\": \"660096\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 4.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 120.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab20b0a\"}',4,0),
(239,'2026-02-08 09:07:39','productos','UPDATE','54e1f07e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660003\", \"nombre\": \"Pure de tomate 400g lata\", \"costo\": 360.00, \"precio\": 440.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f07e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(240,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab225cf','{\"id_producto\": \"660003\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 8.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 360.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab225cf\"}',4,0),
(241,'2026-02-08 09:07:39','productos','UPDATE','54e2013f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660037\", \"nombre\": \"Cerveza La Fria\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e2013f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(242,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab2372e','{\"id_producto\": \"660037\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 24.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 210.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab2372e\"}',4,0),
(243,'2026-02-08 09:07:39','productos','UPDATE','54e1f50f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660012\", \"nombre\": \"Tartaleta\", \"costo\": 50.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1f50f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(244,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab243f9','{\"id_producto\": \"660012\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 17.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 50.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab243f9\"}',4,0),
(245,'2026-02-08 09:07:39','productos','UPDATE','54e1f796-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660017\", \"nombre\": \"Croquetas pollo pqt\", \"costo\": 88.00, \"precio\": 150.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f796-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(246,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab25053','{\"id_producto\": \"660017\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 11.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 88.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab25053\"}',4,0),
(247,'2026-02-08 09:07:39','productos','UPDATE','54e1f60d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660014\", \"nombre\": \"Albondigas pqt\", \"costo\": 220.00, \"precio\": 360.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f60d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(248,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab25d2f','{\"id_producto\": \"660014\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 5.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 220.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab25d2f\"}',4,0),
(249,'2026-02-08 09:07:39','productos','UPDATE','ed40cff0-043e-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"440001\", \"nombre\": \"Donas Grandes\", \"costo\": 90.00, \"precio\": 130.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"ed40cff0-043e-11f1-b0a7-0affd1a0dee5\"}',1,0),
(250,'2026-02-08 09:07:39','kardex','INSERT','kdx_698898ab26b01','{\"id_producto\": \"440001\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 4.0000, \"referencia\": \"COMPRA #22\", \"costo_unitario\": 90.00, \"fecha\": \"2026-02-06 09:07:39\", \"uuid\": \"kdx_698898ab26b01\"}',4,0),
(251,'2026-02-08 09:08:19','ventas_cabecera','INSERT','9efb1281-04f7-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 09:08:19\", \"total\": 1470.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"9efb1281-04f7-11f1-b0a7-0affd1a0dee5\"}',4,0),
(252,'2026-02-08 09:08:19','kardex','INSERT','kdx_698898d390333','{\"id_producto\": \"660013\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #110\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:08:19\", \"uuid\": \"kdx_698898d390333\"}',4,0),
(253,'2026-02-08 09:08:19','kardex','INSERT','kdx_698898d390cb1','{\"id_producto\": \"660012\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -10.0000, \"referencia\": \"VENTA #110\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:08:19\", \"uuid\": \"kdx_698898d390cb1\"}',4,0),
(254,'2026-02-08 09:08:19','kardex','INSERT','kdx_698898d391527','{\"id_producto\": \"660017\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #110\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:08:19\", \"uuid\": \"kdx_698898d391527\"}',4,0),
(255,'2026-02-08 09:17:55','ventas_cabecera','INSERT','f655d864-04f8-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 09:17:55\", \"total\": 680.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"f655d864-04f8-11f1-b0a7-0affd1a0dee5\"}',4,0),
(256,'2026-02-08 09:17:55','kardex','INSERT','kdx_69889b139d1ef','{\"id_producto\": \"660029\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -16.0000, \"referencia\": \"VENTA #111\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:17:55\", \"uuid\": \"kdx_69889b139d1ef\"}',4,0),
(257,'2026-02-08 09:17:55','kardex','INSERT','kdx_69889b139e11a','{\"id_producto\": \"660003\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #111\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 09:17:55\", \"uuid\": \"kdx_69889b139e11a\"}',4,0),
(258,'2026-02-08 10:10:46','ventas_cabecera','INSERT','58301406-0500-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 10:10:46\", \"total\": 270.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"58301406-0500-11f1-b0a7-0affd1a0dee5\"}',4,0),
(259,'2026-02-08 10:10:46','kardex','INSERT','kdx_6988a77647586','{\"id_producto\": \"660001\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #112\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:10:46\", \"uuid\": \"kdx_6988a77647586\"}',4,0),
(260,'2026-02-08 10:10:46','kardex','INSERT','kdx_6988a77647ef1','{\"id_producto\": \"660033\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -5.0000, \"referencia\": \"VENTA #112\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:10:46\", \"uuid\": \"kdx_6988a77647ef1\"}',4,0),
(261,'2026-02-08 10:11:16','ventas_cabecera','INSERT','6a2e171c-0500-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 10:11:16\", \"total\": 740.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"6a2e171c-0500-11f1-b0a7-0affd1a0dee5\"}',4,0),
(262,'2026-02-08 10:11:16','kardex','INSERT','kdx_6988a794742c1','{\"id_producto\": \"660008\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #113\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:11:16\", \"uuid\": \"kdx_6988a794742c1\"}',4,0),
(263,'2026-02-08 10:11:16','kardex','INSERT','kdx_6988a79474b77','{\"id_producto\": \"660011\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #113\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:11:16\", \"uuid\": \"kdx_6988a79474b77\"}',4,0),
(264,'2026-02-08 10:11:53','ventas_cabecera','INSERT','804b921e-0500-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 10:11:53\", \"total\": 960.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"804b921e-0500-11f1-b0a7-0affd1a0dee5\"}',4,0),
(265,'2026-02-08 10:11:53','kardex','INSERT','kdx_6988a7b98d5bb','{\"id_producto\": \"660023\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #114\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:11:53\", \"uuid\": \"kdx_6988a7b98d5bb\"}',4,0),
(266,'2026-02-08 10:12:44','ventas_cabecera','INSERT','9e5d55ac-0500-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 10:12:44\", \"total\": 60.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"9e5d55ac-0500-11f1-b0a7-0affd1a0dee5\"}',4,0),
(267,'2026-02-08 10:12:44','kardex','INSERT','kdx_6988a7ec06b9b','{\"id_producto\": \"660019\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #115\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:12:44\", \"uuid\": \"kdx_6988a7ec06b9b\"}',4,0),
(268,'2026-02-08 10:12:55','ventas_cabecera','INSERT','a5793897-0500-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 10:12:55\", \"total\": 1680.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"a5793897-0500-11f1-b0a7-0affd1a0dee5\"}',4,0),
(269,'2026-02-08 10:12:55','kardex','INSERT','kdx_6988a7f7e9e40','{\"id_producto\": \"660027\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -7.0000, \"referencia\": \"VENTA #116\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:12:55\", \"uuid\": \"kdx_6988a7f7e9e40\"}',4,0),
(270,'2026-02-08 10:13:22','ventas_cabecera','INSERT','b5386000-0500-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 10:13:22\", \"total\": 70.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"b5386000-0500-11f1-b0a7-0affd1a0dee5\"}',4,0),
(271,'2026-02-08 10:13:22','kardex','INSERT','kdx_6988a8125aeb0','{\"id_producto\": \"660034\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -7.0000, \"referencia\": \"VENTA #117\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:13:22\", \"uuid\": \"kdx_6988a8125aeb0\"}',4,0),
(272,'2026-02-08 10:13:53','ventas_cabecera','INSERT','c7e00b4b-0500-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 10:13:53\", \"total\": 600.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"c7e00b4b-0500-11f1-b0a7-0affd1a0dee5\"}',4,0),
(273,'2026-02-08 10:13:53','kardex','INSERT','kdx_6988a831a42f3','{\"id_producto\": \"660087\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -3.0000, \"referencia\": \"VENTA #118\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:13:53\", \"uuid\": \"kdx_6988a831a42f3\"}',4,0),
(274,'2026-02-08 10:15:45','ventas_cabecera','INSERT','0a5f6757-0501-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 10:15:45\", \"total\": 2610.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"0a5f6757-0501-11f1-b0a7-0affd1a0dee5\"}',4,0),
(275,'2026-02-08 10:15:45','kardex','INSERT','kdx_6988a8a13952e','{\"id_producto\": \"660104\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #119\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:15:45\", \"uuid\": \"kdx_6988a8a13952e\"}',4,0),
(276,'2026-02-08 10:15:45','kardex','INSERT','kdx_6988a8a139e19','{\"id_producto\": \"660007\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -3.0000, \"referencia\": \"VENTA #119\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:15:45\", \"uuid\": \"kdx_6988a8a139e19\"}',4,0),
(277,'2026-02-08 10:15:45','kardex','INSERT','kdx_6988a8a13a5ed','{\"id_producto\": \"440001\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #119\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:15:45\", \"uuid\": \"kdx_6988a8a13a5ed\"}',4,0),
(278,'2026-02-08 10:15:45','kardex','INSERT','kdx_6988a8a13adbf','{\"id_producto\": \"660014\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #119\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:15:45\", \"uuid\": \"kdx_6988a8a13adbf\"}',4,0),
(279,'2026-02-08 10:15:45','kardex','INSERT','kdx_6988a8a13c5d5','{\"id_producto\": \"660017\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -5.0000, \"referencia\": \"VENTA #119\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:15:45\", \"uuid\": \"kdx_6988a8a13c5d5\"}',4,0),
(280,'2026-02-08 10:15:45','kardex','INSERT','kdx_6988a8a13ceb5','{\"id_producto\": \"660012\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -7.0000, \"referencia\": \"VENTA #119\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 10:15:45\", \"uuid\": \"kdx_6988a8a13ceb5\"}',4,0),
(281,'2026-02-08 14:33:49','productos','UPDATE','54e20047-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660035\", \"nombre\": \"chicle 30\", \"costo\": 15.00, \"precio\": 30.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e20047-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(282,'2026-02-08 14:33:49','kardex','INSERT','kdx_6988e51d55efe','{\"id_producto\": \"660035\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 3.0000, \"referencia\": \"COMPRA #23\", \"costo_unitario\": 15.00, \"fecha\": \"2026-02-06 14:33:49\", \"uuid\": \"kdx_6988e51d55efe\"}',4,0),
(283,'2026-02-08 14:44:34','ventas_cabecera','INSERT','98681c67-0526-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 14:44:34\", \"total\": -750.00, \"metodo_pago\": \"Devolución\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"DEVOLUCIÓN\", \"uuid\": \"98681c67-0526-11f1-b0a7-0affd1a0dee5\"}',4,0),
(284,'2026-02-08 14:44:34','kardex','INSERT','kdx_6988e7a2dd628','{\"id_producto\": \"660017\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"DEVOLUCION\", \"cantidad\": 5.0000, \"referencia\": \"REFUND_ITEM_305\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 14:44:34\", \"uuid\": \"kdx_6988e7a2dd628\"}',4,0),
(285,'2026-02-08 14:45:06','ventas_cabecera','INSERT','ab8871cc-0526-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 14:45:06\", \"total\": 600.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"ab8871cc-0526-11f1-b0a7-0affd1a0dee5\"}',4,0),
(286,'2026-02-08 14:45:06','kardex','INSERT','kdx_6988e7c2f314d','{\"id_producto\": \"660017\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #121\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 14:45:06\", \"uuid\": \"kdx_6988e7c2f314d\"}',4,0),
(287,'2026-02-08 14:50:34','ventas_cabecera','INSERT','6e7ba845-0527-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 14:50:34\", \"total\": 300.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"6e7ba845-0527-11f1-b0a7-0affd1a0dee5\"}',4,0),
(288,'2026-02-08 14:50:34','kardex','INSERT','kdx_6988e90a10839','{\"id_producto\": \"660001\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #122\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 14:50:34\", \"uuid\": \"kdx_6988e90a10839\"}',4,0),
(289,'2026-02-08 14:50:34','kardex','INSERT','kdx_6988e90a1111f','{\"id_producto\": \"660033\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #122\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 14:50:34\", \"uuid\": \"kdx_6988e90a1111f\"}',4,0),
(290,'2026-02-08 15:04:07','productos','UPDATE','54e26b9a-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991151\", \"nombre\": \"Azucar de Vainilla\", \"costo\": 0.80, \"precio\": 0.90, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e26b9a-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(291,'2026-02-08 19:25:31','productos','UPDATE','54e1f796-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660017\", \"nombre\": \"Croquetas pollo pqt\", \"costo\": 88.00, \"precio\": 150.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f796-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(292,'2026-02-08 19:25:31','kardex','INSERT','kdx_6989297b287df','{\"id_producto\": \"660017\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 4.0000, \"referencia\": \"COMPRA #24\", \"costo_unitario\": 88.00, \"fecha\": \"2026-02-07 19:25:31\", \"uuid\": \"kdx_6989297b287df\"}',4,0),
(293,'2026-02-08 19:25:31','productos','UPDATE','54e1fddd-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660030\", \"nombre\": \"San jacobo\", \"costo\": 180.00, \"precio\": 280.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1fddd-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(294,'2026-02-08 19:25:31','kardex','INSERT','kdx_6989297b2aa48','{\"id_producto\": \"660030\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 4.0000, \"referencia\": \"COMPRA #24\", \"costo_unitario\": 180.00, \"fecha\": \"2026-02-07 19:25:31\", \"uuid\": \"kdx_6989297b2aa48\"}',4,0),
(295,'2026-02-08 19:25:31','productos','UPDATE','54e21b15-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881108\", \"nombre\": \"Hamburguesas pollo\", \"costo\": 292.71, \"precio\": 480.00, \"categoria\": \"CONGELADOS\", \"activo\": 1, \"uuid\": \"54e21b15-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(296,'2026-02-08 19:25:31','kardex','INSERT','kdx_6989297b2b906','{\"id_producto\": \"881108\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 3.0000, \"referencia\": \"COMPRA #24\", \"costo_unitario\": 292.71, \"fecha\": \"2026-02-07 19:25:31\", \"uuid\": \"kdx_6989297b2b906\"}',4,0),
(297,'2026-02-08 20:01:26','ventas_cabecera','INSERT','dc247b42-0552-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 20:01:26\", \"total\": 4560.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"dc247b42-0552-11f1-b0a7-0affd1a0dee5\"}',4,0),
(298,'2026-02-08 20:01:26','kardex','INSERT','kdx_698931e662e06','{\"id_producto\": \"660026\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -3.0000, \"referencia\": \"VENTA #123\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 20:01:26\", \"uuid\": \"kdx_698931e662e06\"}',4,0),
(299,'2026-02-08 20:01:26','kardex','INSERT','kdx_698931e66379d','{\"id_producto\": \"660032\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #123\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 20:01:26\", \"uuid\": \"kdx_698931e66379d\"}',4,0),
(300,'2026-02-08 20:01:26','kardex','INSERT','kdx_698931e66407e','{\"id_producto\": \"660025\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #123\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 20:01:26\", \"uuid\": \"kdx_698931e66407e\"}',4,0),
(301,'2026-02-08 20:01:26','kardex','INSERT','kdx_698931e664867','{\"id_producto\": \"660013\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -10.0000, \"referencia\": \"VENTA #123\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 20:01:26\", \"uuid\": \"kdx_698931e664867\"}',4,0),
(302,'2026-02-08 20:01:26','kardex','INSERT','kdx_698931e6662af','{\"id_producto\": \"660096\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #123\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 20:01:26\", \"uuid\": \"kdx_698931e6662af\"}',4,0),
(303,'2026-02-08 20:01:26','kardex','INSERT','kdx_698931e666bb7','{\"id_producto\": \"660014\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #123\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 20:01:26\", \"uuid\": \"kdx_698931e666bb7\"}',4,0),
(304,'2026-02-08 20:02:33','ventas_cabecera','INSERT','043a1c1a-0553-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-08 20:02:33\", \"total\": 160.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"043a1c1a-0553-11f1-b0a7-0affd1a0dee5\"}',4,0),
(305,'2026-02-08 20:02:33','kardex','INSERT','kdx_69893229a0162','{\"id_producto\": \"660031\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #124\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-08 20:02:33\", \"uuid\": \"kdx_69893229a0162\"}',4,0),
(306,'2026-02-08 21:06:06','productos','UPDATE','54e26ed8-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991153\", \"nombre\": \"jugo de limon\", \"costo\": 0.25, \"precio\": 0.30, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e26ed8-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(307,'2026-02-08 21:27:26','productos','UPDATE','54e299c5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991176\", \"nombre\": \"Saborizante ENCO 120 ml\", \"costo\": 21.00, \"precio\": 22.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e299c5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(308,'2026-02-10 10:58:42','ventas_cabecera','INSERT','5f8aeed1-0699-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 10:58:42\", \"total\": 280.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"5f8aeed1-0699-11f1-b0a7-0affd1a0dee5\"}',4,0),
(309,'2026-02-10 10:58:42','kardex','INSERT','kdx_698b55b2c15b9','{\"id_producto\": \"660006\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #125\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 10:58:42\", \"uuid\": \"kdx_698b55b2c15b9\"}',4,0),
(310,'2026-02-10 11:00:46','ventas_cabecera','INSERT','a9287be4-0699-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 11:00:46\", \"total\": 360.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"a9287be4-0699-11f1-b0a7-0affd1a0dee5\"}',4,0),
(311,'2026-02-10 11:00:46','kardex','INSERT','kdx_698b562e48c3e','{\"id_producto\": \"660005\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #126\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:00:46\", \"uuid\": \"kdx_698b562e48c3e\"}',4,0),
(312,'2026-02-10 11:01:05','ventas_cabecera','INSERT','b4c69bc8-0699-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 11:01:05\", \"total\": 560.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"b4c69bc8-0699-11f1-b0a7-0affd1a0dee5\"}',4,0),
(313,'2026-02-10 11:01:05','kardex','INSERT','kdx_698b5641c1c46','{\"id_producto\": \"660011\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #127\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:01:05\", \"uuid\": \"kdx_698b5641c1c46\"}',4,0),
(314,'2026-02-10 11:02:38','ventas_cabecera','INSERT','ec2549ae-0699-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 11:02:38\", \"total\": 9070.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"ec2549ae-0699-11f1-b0a7-0affd1a0dee5\"}',4,0),
(315,'2026-02-10 11:02:38','kardex','INSERT','kdx_698b569ea782d','{\"id_producto\": \"660023\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -9.0000, \"referencia\": \"VENTA #128\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:02:38\", \"uuid\": \"kdx_698b569ea782d\"}',4,0),
(316,'2026-02-10 11:02:38','kardex','INSERT','kdx_698b569ea88f3','{\"id_producto\": \"660032\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -5.0000, \"referencia\": \"VENTA #128\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:02:38\", \"uuid\": \"kdx_698b569ea88f3\"}',4,0),
(317,'2026-02-10 11:02:38','kardex','INSERT','kdx_698b569ea91cb','{\"id_producto\": \"660037\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -24.0000, \"referencia\": \"VENTA #128\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:02:38\", \"uuid\": \"kdx_698b569ea91cb\"}',4,0),
(318,'2026-02-10 11:03:12','ventas_cabecera','INSERT','0053e1c9-069a-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 11:03:12\", \"total\": 650.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"0053e1c9-069a-11f1-b0a7-0affd1a0dee5\"}',4,0),
(319,'2026-02-10 11:03:12','kardex','INSERT','kdx_698b56c084e18','{\"id_producto\": \"660020\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #129\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:03:12\", \"uuid\": \"kdx_698b56c084e18\"}',4,0),
(320,'2026-02-10 11:43:15','ventas_cabecera','INSERT','98ba87f2-069f-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 11:43:15\", \"total\": 2470.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"98ba87f2-069f-11f1-b0a7-0affd1a0dee5\"}',4,0),
(321,'2026-02-10 11:43:15','kardex','INSERT','kdx_698b6023af80c','{\"id_producto\": \"660018\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #130\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:43:15\", \"uuid\": \"kdx_698b6023af80c\"}',4,0),
(322,'2026-02-10 11:43:15','kardex','INSERT','kdx_698b6023b02e0','{\"id_producto\": \"660019\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #130\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:43:15\", \"uuid\": \"kdx_698b6023b02e0\"}',4,0),
(323,'2026-02-10 11:43:15','kardex','INSERT','kdx_698b6023b15e6','{\"id_producto\": \"660027\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -6.0000, \"referencia\": \"VENTA #130\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:43:15\", \"uuid\": \"kdx_698b6023b15e6\"}',4,0),
(324,'2026-02-10 11:43:15','kardex','INSERT','kdx_698b6023b1f5d','{\"id_producto\": \"660034\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -29.0000, \"referencia\": \"VENTA #130\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:43:15\", \"uuid\": \"kdx_698b6023b1f5d\"}',4,0),
(325,'2026-02-10 11:47:51','ventas_cabecera','INSERT','3d4a53de-06a0-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 11:47:51\", \"total\": 2550.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"3d4a53de-06a0-11f1-b0a7-0affd1a0dee5\"}',4,0),
(326,'2026-02-10 11:47:51','kardex','INSERT','kdx_698b6137c40d6','{\"id_producto\": \"660096\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #131\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:47:51\", \"uuid\": \"kdx_698b6137c40d6\"}',4,0),
(327,'2026-02-10 11:47:51','kardex','INSERT','kdx_698b6137c54bf','{\"id_producto\": \"660007\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -14.0000, \"referencia\": \"VENTA #131\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:47:51\", \"uuid\": \"kdx_698b6137c54bf\"}',4,0),
(328,'2026-02-10 11:47:51','kardex','INSERT','kdx_698b6137c5db7','{\"id_producto\": \"660014\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #131\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:47:51\", \"uuid\": \"kdx_698b6137c5db7\"}',4,0),
(329,'2026-02-10 11:47:51','kardex','INSERT','kdx_698b6137c65ce','{\"id_producto\": \"660017\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -7.0000, \"referencia\": \"VENTA #131\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:47:51\", \"uuid\": \"kdx_698b6137c65ce\"}',4,0),
(330,'2026-02-10 11:53:18','ventas_cabecera','INSERT','0047a973-06a1-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 11:53:18\", \"total\": 160.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"0047a973-06a1-11f1-b0a7-0affd1a0dee5\"}',4,0),
(331,'2026-02-10 11:53:18','kardex','INSERT','kdx_698b627ee5d73','{\"id_producto\": \"660010\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #132\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 11:53:18\", \"uuid\": \"kdx_698b627ee5d73\"}',4,0),
(332,'2026-02-10 11:55:36','productos','UPDATE','54e1f60d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660014\", \"nombre\": \"Albondigas pqt\", \"costo\": 220.00, \"precio\": 370.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f60d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(333,'2026-02-10 12:12:38','ventas_cabecera','INSERT','b3a0dac9-06a3-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 12:12:38\", \"total\": 1220.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"b3a0dac9-06a3-11f1-b0a7-0affd1a0dee5\"}',4,0),
(334,'2026-02-10 12:12:38','kardex','INSERT','kdx_698b6706caff8','{\"id_producto\": \"660001\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #133\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:12:38\", \"uuid\": \"kdx_698b6706caff8\"}',4,0),
(335,'2026-02-10 12:12:38','kardex','INSERT','kdx_698b6706cba23','{\"id_producto\": \"660033\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #133\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:12:38\", \"uuid\": \"kdx_698b6706cba23\"}',4,0),
(336,'2026-02-10 12:12:38','kardex','INSERT','kdx_698b6706cc380','{\"id_producto\": \"660005\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #133\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:12:38\", \"uuid\": \"kdx_698b6706cc380\"}',4,0),
(337,'2026-02-10 12:12:38','kardex','INSERT','kdx_698b6706ccc6f','{\"id_producto\": \"660013\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #133\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:12:38\", \"uuid\": \"kdx_698b6706ccc6f\"}',4,0),
(338,'2026-02-10 12:12:38','kardex','INSERT','kdx_698b6706cd520','{\"id_producto\": \"660034\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -23.0000, \"referencia\": \"VENTA #133\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:12:38\", \"uuid\": \"kdx_698b6706cd520\"}',4,0),
(339,'2026-02-10 12:12:38','kardex','INSERT','kdx_698b6706cdd82','{\"id_producto\": \"660029\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -14.0000, \"referencia\": \"VENTA #133\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:12:38\", \"uuid\": \"kdx_698b6706cdd82\"}',4,0),
(340,'2026-02-10 12:14:14','ventas_cabecera','INSERT','ecc74ac6-06a3-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 12:14:14\", \"total\": 1890.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"ecc74ac6-06a3-11f1-b0a7-0affd1a0dee5\"}',4,0),
(341,'2026-02-10 12:14:14','kardex','INSERT','kdx_698b6766ae318','{\"id_producto\": \"660035\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #134\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:14:14\", \"uuid\": \"kdx_698b6766ae318\"}',4,0),
(342,'2026-02-10 12:14:14','kardex','INSERT','kdx_698b6766aec22','{\"id_producto\": \"660096\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #134\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:14:14\", \"uuid\": \"kdx_698b6766aec22\"}',4,0),
(343,'2026-02-10 12:14:14','kardex','INSERT','kdx_698b6766af49a','{\"id_producto\": \"660016\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #134\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:14:14\", \"uuid\": \"kdx_698b6766af49a\"}',4,0),
(344,'2026-02-10 12:14:14','kardex','INSERT','kdx_698b6766b0c14','{\"id_producto\": \"660103\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #134\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:14:14\", \"uuid\": \"kdx_698b6766b0c14\"}',4,0),
(345,'2026-02-10 12:15:38','ventas_cabecera','INSERT','1ec85b39-06a4-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 12:15:38\", \"total\": 850.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"1ec85b39-06a4-11f1-b0a7-0affd1a0dee5\"}',4,0),
(346,'2026-02-10 12:15:38','kardex','INSERT','kdx_698b67ba9412b','{\"id_producto\": \"660014\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #135\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:15:38\", \"uuid\": \"kdx_698b67ba9412b\"}',4,0),
(347,'2026-02-10 12:15:38','kardex','INSERT','kdx_698b67ba94a14','{\"id_producto\": \"881108\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #135\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:15:38\", \"uuid\": \"kdx_698b67ba94a14\"}',4,0),
(348,'2026-02-10 12:21:55','productos','UPDATE','54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660023\", \"nombre\": \"Cerveza Shekels\", \"costo\": 213.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(349,'2026-02-10 12:21:55','kardex','INSERT','kdx_698b6933a699c','{\"id_producto\": \"660023\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 24.0000, \"referencia\": \"COMPRA #25\", \"costo_unitario\": 213.00, \"fecha\": \"2026-02-08 12:21:55\", \"uuid\": \"kdx_698b6933a699c\"}',4,0),
(350,'2026-02-10 12:21:55','productos','UPDATE','54e1f98e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660021\", \"nombre\": \"Refresco cola lata 300ml\", \"costo\": 217.00, \"precio\": 240.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e1f98e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(351,'2026-02-10 12:21:55','kardex','INSERT','kdx_698b6933a7eba','{\"id_producto\": \"660021\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 24.0000, \"referencia\": \"COMPRA #25\", \"costo_unitario\": 217.00, \"fecha\": \"2026-02-08 12:21:55\", \"uuid\": \"kdx_698b6933a7eba\"}',4,0),
(352,'2026-02-10 12:22:41','productos','UPDATE','54e1f412-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660010\", \"nombre\": \"Galletas Blackout azul\", \"costo\": 130.00, \"precio\": 160.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f412-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(353,'2026-02-10 12:22:41','kardex','INSERT','kdx_698b6961418cc','{\"id_producto\": \"660010\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 4.0000, \"referencia\": \"COMPRA #26\", \"costo_unitario\": 130.00, \"fecha\": \"2026-02-08 12:22:41\", \"uuid\": \"kdx_698b6961418cc\"}',4,0),
(354,'2026-02-10 12:22:41','productos','UPDATE','54e1f697-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660015\", \"nombre\": \"Hamgurgesa 5u pqt\", \"costo\": 300.00, \"precio\": 500.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f697-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(355,'2026-02-10 12:22:41','kardex','INSERT','kdx_698b6961434bf','{\"id_producto\": \"660015\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"ENTRADA\", \"cantidad\": 1.0000, \"referencia\": \"COMPRA #26\", \"costo_unitario\": 300.00, \"fecha\": \"2026-02-08 12:22:41\", \"uuid\": \"kdx_698b6961434bf\"}',4,0),
(356,'2026-02-10 12:24:30','ventas_cabecera','INSERT','5bdd9c36-06a5-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 12:24:30\", \"total\": 5680.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"5bdd9c36-06a5-11f1-b0a7-0affd1a0dee5\"}',4,0),
(357,'2026-02-10 12:24:30','kardex','INSERT','kdx_698b69ce8e75a','{\"id_producto\": \"660010\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #136\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:24:30\", \"uuid\": \"kdx_698b69ce8e75a\"}',4,0),
(358,'2026-02-10 12:24:30','kardex','INSERT','kdx_698b69ce8f059','{\"id_producto\": \"660023\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -23.0000, \"referencia\": \"VENTA #136\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:24:30\", \"uuid\": \"kdx_698b69ce8f059\"}',4,0),
(359,'2026-02-10 12:25:05','ventas_cabecera','INSERT','7098527c-06a5-11f1-b0a7-0affd1a0dee5','{\"fecha\": \"2026-02-10 12:25:05\", \"total\": 1440.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Consumidor Final\", \"uuid\": \"7098527c-06a5-11f1-b0a7-0affd1a0dee5\"}',4,0),
(360,'2026-02-10 12:25:05','kardex','INSERT','kdx_698b69f158500','{\"id_producto\": \"660021\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -4.0000, \"referencia\": \"VENTA #137\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:25:05\", \"uuid\": \"kdx_698b69f158500\"}',4,0),
(361,'2026-02-10 12:25:05','kardex','INSERT','kdx_698b69f158dd5','{\"id_producto\": \"881108\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #137\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-10 12:25:05\", \"uuid\": \"kdx_698b69f158dd5\"}',4,0),
(362,'2026-02-11 14:34:10','ventas_cabecera','INSERT','96d99b6f-67a3-433c-8c29-fedd0e50cddc','{\"fecha\": \"2026-02-11 09:34:10\", \"total\": 300.00, \"metodo_pago\": \"Efectivo\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Mostrador\", \"uuid\": \"96d99b6f-67a3-433c-8c29-fedd0e50cddc\"}',4,0),
(363,'2026-02-11 14:34:10','kardex','INSERT','kdx_0ZKlBBzYbN3Mc','{\"id_producto\": \"756058841446\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -2.0000, \"referencia\": \"VENTA #138\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-11 09:34:10\", \"uuid\": \"kdx_0ZKlBBzYbN3Mc\"}',4,0),
(364,'2026-02-11 17:50:57','productos','UPDATE','54e225f1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991118\", \"nombre\": \"Aceite\", \"costo\": 1.20, \"precio\": 2.00, \"categoria\": \"Bodegon\", \"activo\": 1, \"uuid\": \"54e225f1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(365,'2026-02-11 17:51:11','productos','UPDATE','54e225f1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991118\", \"nombre\": \"Aceite\", \"costo\": 1.20, \"precio\": 2.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e225f1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(366,'2026-02-11 19:26:29','ventas_cabecera','INSERT','d4441ca4-ac7c-4838-8edd-44686746e38a','{\"fecha\": \"2026-02-11 14:26:29\", \"total\": 150.00, \"metodo_pago\": \"Transferencia\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"Mostrador\", \"uuid\": \"d4441ca4-ac7c-4838-8edd-44686746e38a\"}',4,0),
(367,'2026-02-11 19:26:29','kardex','INSERT','kdx_vGQaVqGkonwql','{\"id_producto\": \"756058841446\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #139\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-11 14:26:29\", \"uuid\": \"kdx_vGQaVqGkonwql\"}',4,0),
(368,'2026-02-11 22:57:10','ventas_cabecera','INSERT','4b61b3b3-f5ce-41cd-8db8-e886bd708d7a','{\"fecha\": \"2026-02-11 17:57:10\", \"total\": 150.00, \"metodo_pago\": \"Mixto\", \"id_cliente\": 0, \"id_sucursal\": 4, \"cliente_nombre\": \"sureima chales\", \"uuid\": \"4b61b3b3-f5ce-41cd-8db8-e886bd708d7a\"}',4,0),
(369,'2026-02-11 22:57:10','kardex','INSERT','kdx_wpfjZo1KvZMyR','{\"id_producto\": \"756058841446\", \"id_almacen\": 4, \"id_sucursal\": 4, \"tipo_movimiento\": \"VENTA\", \"cantidad\": -1.0000, \"referencia\": \"VENTA #140\", \"costo_unitario\": 0.00, \"fecha\": \"2026-02-11 17:57:10\", \"uuid\": \"kdx_wpfjZo1KvZMyR\"}',4,0),
(370,'2026-02-12 00:20:42','productos','UPDATE','ed40cff0-043e-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"440001\", \"nombre\": \"Donas Grandes\", \"costo\": 90.00, \"precio\": 130.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"ed40cff0-043e-11f1-b0a7-0affd1a0dee5\"}',1,0),
(371,'2026-02-12 00:20:42','productos','UPDATE','54e1ecd1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660001\", \"nombre\": \"Chupa chus grandes\", \"costo\": 35.00, \"precio\": 70.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1ecd1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(372,'2026-02-12 00:20:42','productos','UPDATE','54e1efce-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660002\", \"nombre\": \"Carritos chocolate\", \"costo\": 25.00, \"precio\": 40.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1efce-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(373,'2026-02-12 00:20:42','productos','UPDATE','54e1f07e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660003\", \"nombre\": \"Pure de tomate 400g lata\", \"costo\": 360.00, \"precio\": 440.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f07e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(374,'2026-02-12 00:20:42','productos','UPDATE','54e1f10c-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660004\", \"nombre\": \"Mini Whiskey 200ml\", \"costo\": 370.00, \"precio\": 400.00, \"categoria\": \"BEBIDAS_A\", \"activo\": 1, \"uuid\": \"54e1f10c-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(375,'2026-02-12 00:20:42','productos','UPDATE','54e1f190-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660005\", \"nombre\": \"Galletas Porleo\", \"costo\": 130.00, \"precio\": 180.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f190-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(376,'2026-02-12 00:20:42','productos','UPDATE','54e1f211-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660006\", \"nombre\": \"Espagetis 500g\", \"costo\": 212.00, \"precio\": 280.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f211-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(377,'2026-02-12 00:20:42','productos','UPDATE','54e1f294-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660007\", \"nombre\": \"Refresco en polvo\", \"costo\": 20.00, \"precio\": 40.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f294-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(378,'2026-02-12 00:20:42','productos','UPDATE','54e1f310-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660008\", \"nombre\": \"Galleta Biskiato minion\", \"costo\": 150.00, \"precio\": 180.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f310-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(379,'2026-02-12 00:20:42','productos','UPDATE','54e1f390-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660009\", \"nombre\": \"Galletas Rellenitas\", \"costo\": 120.00, \"precio\": 150.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f390-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(380,'2026-02-12 00:20:42','productos','UPDATE','54e1f412-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660010\", \"nombre\": \"Galletas Blackout azul\", \"costo\": 130.00, \"precio\": 160.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f412-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(381,'2026-02-12 00:20:42','productos','UPDATE','54e1f490-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660011\", \"nombre\": \"Energizante\", \"costo\": 210.00, \"precio\": 280.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e1f490-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(382,'2026-02-12 00:20:42','productos','UPDATE','54e1f50f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660012\", \"nombre\": \"Tartaleta\", \"costo\": 50.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1f50f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(383,'2026-02-12 00:20:42','productos','UPDATE','54e1f589-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660013\", \"nombre\": \"Cabezote\", \"costo\": 35.00, \"precio\": 80.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1f589-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(384,'2026-02-12 00:20:42','productos','UPDATE','54e1f60d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660014\", \"nombre\": \"Albondigas pqt\", \"costo\": 220.00, \"precio\": 370.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f60d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(385,'2026-02-12 00:20:42','productos','UPDATE','54e1f697-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660015\", \"nombre\": \"Hamgurgesa 5u pqt\", \"costo\": 300.00, \"precio\": 500.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f697-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(386,'2026-02-12 00:20:42','productos','UPDATE','54e1f717-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660016\", \"nombre\": \"Higado de pollo pqt\", \"costo\": 620.00, \"precio\": 730.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f717-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(387,'2026-02-12 00:20:42','productos','UPDATE','54e1f796-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660017\", \"nombre\": \"Croquetas pollo pqt\", \"costo\": 88.00, \"precio\": 150.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1f796-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(388,'2026-02-12 00:20:42','productos','UPDATE','54e1f812-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660018\", \"nombre\": \"Azucar 1kg pqt\", \"costo\": 580.00, \"precio\": 680.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f812-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(389,'2026-02-12 00:20:42','productos','UPDATE','54e1f893-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660019\", \"nombre\": \"Pinguinos gelatina\", \"costo\": 33.00, \"precio\": 60.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1f893-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(390,'2026-02-12 00:20:42','productos','UPDATE','54e1f914-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660020\", \"nombre\": \"Arroz 1kg pqt\", \"costo\": 570.00, \"precio\": 650.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1f914-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(391,'2026-02-12 00:20:42','productos','UPDATE','54e1f98e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660021\", \"nombre\": \"Refresco cola lata 300ml\", \"costo\": 217.00, \"precio\": 240.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e1f98e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(392,'2026-02-12 00:20:42','productos','UPDATE','54e1fa0e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660022\", \"nombre\": \"Refresco limon lata 300ml\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e1fa0e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(393,'2026-02-12 00:20:42','productos','UPDATE','54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660023\", \"nombre\": \"Cerveza Shekels\", \"costo\": 213.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e1fa8a-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(394,'2026-02-12 00:20:42','productos','UPDATE','54e1fb03-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660024\", \"nombre\": \"Cerveza Sta Isabel\", \"costo\": 205.00, \"precio\": 230.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e1fb03-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(395,'2026-02-12 00:20:42','productos','UPDATE','54e1fb7d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660025\", \"nombre\": \"Leche condensada\", \"costo\": 430.00, \"precio\": 520.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1fb7d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(396,'2026-02-12 00:20:42','productos','UPDATE','54e1fbf7-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660026\", \"nombre\": \"Fanguito\", \"costo\": 430.00, \"precio\": 580.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e1fbf7-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(397,'2026-02-12 00:20:42','productos','UPDATE','54e1fc6f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660027\", \"nombre\": \"Marranetas\", \"costo\": 162.57, \"precio\": 240.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1fc6f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(398,'2026-02-12 00:20:42','productos','UPDATE','54e1fceb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660028\", \"nombre\": \"Tartaletas especiales\", \"costo\": 90.00, \"precio\": 140.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e1fceb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(399,'2026-02-12 00:20:42','productos','UPDATE','54e1fd65-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660029\", \"nombre\": \"Caramelos de 15\", \"costo\": 8.00, \"precio\": 15.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1fd65-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(400,'2026-02-12 00:20:42','productos','UPDATE','54e1fddd-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660030\", \"nombre\": \"San jacobo\", \"costo\": 180.00, \"precio\": 280.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1fddd-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(401,'2026-02-12 00:20:42','productos','UPDATE','54e1fe58-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660031\", \"nombre\": \"Croquetas Buffet\", \"costo\": 82.00, \"precio\": 160.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e1fe58-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(402,'2026-02-12 00:20:42','productos','UPDATE','54e1fed4-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660032\", \"nombre\": \"Cerveza Esple\", \"costo\": 190.00, \"precio\": 230.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e1fed4-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(403,'2026-02-12 00:20:42','productos','UPDATE','54e1ff4f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660033\", \"nombre\": \"Chupa chus 40\", \"costo\": 20.00, \"precio\": 40.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1ff4f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(404,'2026-02-12 00:20:42','productos','UPDATE','54e1ffcb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660034\", \"nombre\": \"Caramelos 10\", \"costo\": 6.00, \"precio\": 10.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e1ffcb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(405,'2026-02-12 00:20:42','productos','UPDATE','54e20047-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660035\", \"nombre\": \"chicle 30\", \"costo\": 15.00, \"precio\": 30.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e20047-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(406,'2026-02-12 00:20:42','productos','UPDATE','54e200c3-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660036\", \"nombre\": \"jugo cajita 200ml\", \"costo\": 135.00, \"precio\": 180.00, \"categoria\": \"BEBIDAS\", \"activo\": 1, \"uuid\": \"54e200c3-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(407,'2026-02-12 00:20:42','productos','UPDATE','54e2013f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660037\", \"nombre\": \"Cerveza La Fria\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e2013f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(408,'2026-02-12 00:20:42','productos','UPDATE','54e201b9-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660038\", \"nombre\": \"Cerveza Beer azul\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e201b9-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(409,'2026-02-12 00:20:42','productos','UPDATE','54e20235-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660039\", \"nombre\": \"Helado Mousse 7oz\", \"costo\": 90.00, \"precio\": 150.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20235-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(410,'2026-02-12 00:20:42','productos','UPDATE','54e202b1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660040\", \"nombre\": \"Dulce 3 leches vaso 7oz\", \"costo\": 150.00, \"precio\": 250.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e202b1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(411,'2026-02-12 00:20:42','productos','UPDATE','54e2032c-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660041\", \"nombre\": \"Torticas\", \"costo\": 35.00, \"precio\": 70.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e2032c-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(412,'2026-02-12 00:20:42','productos','UPDATE','54e205ab-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660042\", \"nombre\": \"Pan de Gloria\", \"costo\": 35.00, \"precio\": 80.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e205ab-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(413,'2026-02-12 00:20:42','productos','UPDATE','54e20629-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660043\", \"nombre\": \"Minicake 18cm\", \"costo\": 700.00, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20629-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(414,'2026-02-12 00:20:42','productos','UPDATE','54e20721-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660044\", \"nombre\": \"Minicake Fanguito\", \"costo\": 800.00, \"precio\": 1700.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20721-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(415,'2026-02-12 00:20:42','productos','UPDATE','54e207ad-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660045\", \"nombre\": \"Minicake Bombom\", \"costo\": 1105.00, \"precio\": 2000.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e207ad-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(416,'2026-02-12 00:20:42','productos','UPDATE','54e20827-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660046\", \"nombre\": \"Harina 1kg\", \"costo\": 500.00, \"precio\": 600.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20827-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(417,'2026-02-12 00:20:42','productos','UPDATE','54e208a4-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660087\", \"nombre\": \"Galletas Saltitacos\", \"costo\": 62.53, \"precio\": 200.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e208a4-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(418,'2026-02-12 00:20:42','productos','UPDATE','54e20921-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660088\", \"nombre\": \"Caldo de pollo sobrecito\", \"costo\": 18.00, \"precio\": 30.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20921-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(419,'2026-02-12 00:20:42','productos','UPDATE','54e2099d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660089\", \"nombre\": \"pastillita pollo con tomate\", \"costo\": 20.00, \"precio\": 40.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e2099d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(420,'2026-02-12 00:20:42','productos','UPDATE','54e20a18-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660090\", \"nombre\": \"lomo en bandeja 1lb\", \"costo\": 1150.00, \"precio\": 1300.00, \"categoria\": \"CARNICOS\", \"activo\": 1, \"uuid\": \"54e20a18-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(421,'2026-02-12 00:20:42','productos','UPDATE','54e20a99-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660093\", \"nombre\": \"Pqt de 4 panques\", \"costo\": 180.00, \"precio\": 280.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20a99-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(422,'2026-02-12 00:20:42','productos','UPDATE','54e20b21-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660094\", \"nombre\": \"Gacenigas mini\", \"costo\": 200.00, \"precio\": 280.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20b21-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(423,'2026-02-12 00:20:42','productos','UPDATE','54e20ba2-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660095\", \"nombre\": \"Panque de chocolate\", \"costo\": 70.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20ba2-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(424,'2026-02-12 00:20:42','productos','UPDATE','54e20c23-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660096\", \"nombre\": \"pqt torticas 6u\", \"costo\": 120.00, \"precio\": 220.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20c23-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(425,'2026-02-12 00:20:42','productos','UPDATE','54e20ca1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660097\", \"nombre\": \"Bolsa de 8 panes\", \"costo\": 160.00, \"precio\": 200.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20ca1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(426,'2026-02-12 00:20:42','productos','UPDATE','54e20d22-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660098\", \"nombre\": \"Cake 2900\", \"costo\": 2000.00, \"precio\": 2900.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20d22-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(427,'2026-02-12 00:20:42','productos','UPDATE','54e20da7-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660099\", \"nombre\": \"Marquesita\", \"costo\": 65.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e20da7-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(428,'2026-02-12 00:20:42','productos','UPDATE','54e20eb0-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660100\", \"nombre\": \"ojitos gummy gum\", \"costo\": 40.00, \"precio\": 80.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e20eb0-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(429,'2026-02-12 00:20:42','productos','UPDATE','54e20f35-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660101\", \"nombre\": \"Cafe en sobre\", \"costo\": 25.00, \"precio\": 40.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20f35-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(430,'2026-02-12 00:20:42','productos','UPDATE','54e20fb5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660102\", \"nombre\": \"Jabon de carbon\", \"costo\": 170.00, \"precio\": 200.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e20fb5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(431,'2026-02-12 00:20:42','productos','UPDATE','54e21032-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660103\", \"nombre\": \"jabon de bano\", \"costo\": 130.00, \"precio\": 150.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e21032-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(432,'2026-02-12 00:20:42','productos','UPDATE','54e210ac-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"660104\", \"nombre\": \"Donas Donuts\", \"costo\": 120.00, \"precio\": 160.00, \"categoria\": \"CONFITURAS\", \"activo\": 1, \"uuid\": \"54e210ac-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(433,'2026-02-12 00:20:42','productos','UPDATE','54e2112e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841446\", \"nombre\": \"COCA COLA GRANDE\", \"costo\": 120.00, \"precio\": 150.00, \"categoria\": \"Bebidas\", \"activo\": 1, \"uuid\": \"54e2112e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(434,'2026-02-12 00:20:42','productos','UPDATE','54e211be-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841453\", \"nombre\": \"Refresco de naranja\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"Bebidas\", \"activo\": 1, \"uuid\": \"54e211be-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(435,'2026-02-12 00:20:42','productos','UPDATE','54e21241-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841477\", \"nombre\": \"melcocha\", \"costo\": 20.00, \"precio\": 40.00, \"categoria\": \"Pruebas\", \"activo\": 1, \"uuid\": \"54e21241-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(436,'2026-02-12 00:20:42','productos','UPDATE','54e212c4-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841478\", \"nombre\": \"Cupcakes\", \"costo\": 40.00, \"precio\": 120.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e212c4-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(437,'2026-02-12 00:20:42','productos','UPDATE','54e21347-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841479\", \"nombre\": \"Capacillo de papel\", \"costo\": 1.00, \"precio\": 2.00, \"categoria\": \"INSUMOS\", \"activo\": 1, \"uuid\": \"54e21347-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(438,'2026-02-12 00:20:42','productos','UPDATE','54e213cb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"756058841480\", \"nombre\": \"Yuca en Pure\", \"costo\": 100.00, \"precio\": 120.00, \"categoria\": \"INSUMOS\", \"activo\": 1, \"uuid\": \"54e213cb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(439,'2026-02-12 00:20:42','productos','UPDATE','54e21447-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"88011\", \"nombre\": \"Producto de Prueba\", \"costo\": 20.00, \"precio\": 50.00, \"categoria\": \"Pruebas\", \"activo\": 1, \"uuid\": \"54e21447-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(440,'2026-02-12 00:20:42','productos','UPDATE','54e214cb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"88012\", \"nombre\": \"Producto de Prueba2\", \"costo\": 20.00, \"precio\": 50.00, \"categoria\": \"Pruebas\", \"activo\": 1, \"uuid\": \"54e214cb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(441,'2026-02-12 00:20:42','productos','UPDATE','54e2154d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881101\", \"nombre\": \"Minicake 1500\", \"costo\": 1041.35, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e2154d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(442,'2026-02-12 00:20:42','productos','UPDATE','54e215d5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881102\", \"nombre\": \"Minicake fanguito\", \"costo\": 565.04, \"precio\": 1700.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e215d5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(443,'2026-02-12 00:20:42','productos','UPDATE','54e21884-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881103\", \"nombre\": \"Tortica grande\", \"costo\": 45.00, \"precio\": 70.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21884-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(444,'2026-02-12 00:20:42','productos','UPDATE','54e2190d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881104\", \"nombre\": \"Torticas chiquita\", \"costo\": 35.00, \"precio\": 80.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e2190d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(445,'2026-02-12 00:20:42','productos','UPDATE','54e2198e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881105\", \"nombre\": \"Tartaletas\", \"costo\": 50.00, \"precio\": 100.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e2198e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(446,'2026-02-12 00:20:42','productos','UPDATE','54e21a14-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881106\", \"nombre\": \"Minicake 1500 choco\", \"costo\": 1041.35, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21a14-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(447,'2026-02-12 00:20:42','productos','UPDATE','54e21a94-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881107\", \"nombre\": \"Mini Cake\", \"costo\": 567.90, \"precio\": 1500.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21a94-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(448,'2026-02-12 00:20:42','productos','UPDATE','54e21b15-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881108\", \"nombre\": \"Hamburguesas pollo\", \"costo\": 292.71, \"precio\": 480.00, \"categoria\": \"CONGELADOS\", \"activo\": 1, \"uuid\": \"54e21b15-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(449,'2026-02-12 00:20:42','productos','UPDATE','54e21ba0-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881109\", \"nombre\": \"Cabezotes\", \"costo\": 33.55, \"precio\": 80.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e21ba0-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(450,'2026-02-12 00:20:42','productos','UPDATE','54e21c24-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"881110\", \"nombre\": \"PQT Picadillo 1lb\", \"costo\": 289.80, \"precio\": 330.00, \"categoria\": \"CONGELADOS\", \"activo\": 1, \"uuid\": \"54e21c24-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(451,'2026-02-12 00:20:42','productos','UPDATE','54e21ca6-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"990091\", \"nombre\": \"Cerveza Presidente\", \"costo\": 210.00, \"precio\": 240.00, \"categoria\": \"CERVEZAS\", \"activo\": 1, \"uuid\": \"54e21ca6-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(452,'2026-02-12 00:20:42','productos','UPDATE','54e21d30-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991101\", \"nombre\": \"Azucar Blanca\", \"costo\": 0.58, \"precio\": 0.65, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e21d30-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(453,'2026-02-12 00:20:42','productos','UPDATE','54e21dbe-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991102\", \"nombre\": \"azucar prieta\", \"costo\": 0.61, \"precio\": 0.65, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e21dbe-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(454,'2026-02-12 00:20:42','productos','UPDATE','54e21e50-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991103\", \"nombre\": \"Harina trigo\", \"costo\": 0.50, \"precio\": 0.60, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e21e50-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(455,'2026-02-12 00:20:42','productos','UPDATE','54e21ed8-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991104\", \"nombre\": \"leche en polvo\", \"costo\": 1.50, \"precio\": 1.65, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e21ed8-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(456,'2026-02-12 00:20:42','productos','UPDATE','54e21f5d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991105\", \"nombre\": \"maicena\", \"costo\": 1.20, \"precio\": 1.20, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e21f5d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(457,'2026-02-12 00:20:42','productos','UPDATE','54e21fe2-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991106\", \"nombre\": \"polvo hornear\", \"costo\": 1.80, \"precio\": 2.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e21fe2-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(458,'2026-02-12 00:20:42','productos','UPDATE','54e22064-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991107\", \"nombre\": \"levadura\", \"costo\": 2.40, \"precio\": 2.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22064-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(459,'2026-02-12 00:20:42','productos','UPDATE','54e220e5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991108\", \"nombre\": \"guayaba en barra\", \"costo\": 0.85, \"precio\": 1.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e220e5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(460,'2026-02-12 00:20:42','productos','UPDATE','54e22169-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991109\", \"nombre\": \"canela en polvo\", \"costo\": 2.50, \"precio\": 3.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22169-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(461,'2026-02-12 00:20:42','productos','UPDATE','54e221eb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991110\", \"nombre\": \"Bicarbonato\", \"costo\": 3.60, \"precio\": 4.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e221eb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(462,'2026-02-12 00:20:42','productos','UPDATE','54e2226d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991111\", \"nombre\": \"mejorador de pan\", \"costo\": 1.80, \"precio\": 2.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2226d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(463,'2026-02-12 00:20:42','productos','UPDATE','54e222ec-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991112\", \"nombre\": \"CMC\", \"costo\": 5.00, \"precio\": 8.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e222ec-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(464,'2026-02-12 00:20:42','productos','UPDATE','54e2236e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991113\", \"nombre\": \"Emulsionante redolmy\", \"costo\": 4.15, \"precio\": 4.50, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2236e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(465,'2026-02-12 00:20:42','productos','UPDATE','54e223ef-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991114\", \"nombre\": \"sazon en sobre\", \"costo\": 45.00, \"precio\": 70.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e223ef-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(466,'2026-02-12 00:20:42','productos','UPDATE','54e2246c-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991115\", \"nombre\": \"pastillita de sabor\", \"costo\": 25.00, \"precio\": 50.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2246c-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(467,'2026-02-12 00:20:42','productos','UPDATE','54e224ec-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991116\", \"nombre\": \"yema huevo\", \"costo\": 50.00, \"precio\": 55.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e224ec-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(468,'2026-02-12 00:20:42','productos','UPDATE','54e2256c-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991117\", \"nombre\": \"clara huevo\", \"costo\": 50.00, \"precio\": 55.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2256c-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(469,'2026-02-12 00:20:42','productos','UPDATE','54e225f1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991118\", \"nombre\": \"Aceite\", \"costo\": 1.20, \"precio\": 2.00, \"categoria\": \"BODEGON\", \"activo\": 1, \"uuid\": \"54e225f1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(470,'2026-02-12 00:20:42','productos','UPDATE','54e22672-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991119\", \"nombre\": \"Sal comun\", \"costo\": 0.12, \"precio\": 0.15, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22672-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(471,'2026-02-12 00:20:42','productos','UPDATE','54e226ef-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991120\", \"nombre\": \"Vainilla liquida\", \"costo\": 2.00, \"precio\": 2.20, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e226ef-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(472,'2026-02-12 00:20:42','productos','UPDATE','54e22770-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991121\", \"nombre\": \"Pan Rayado\", \"costo\": 0.43, \"precio\": 0.60, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22770-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(473,'2026-02-12 00:20:42','productos','UPDATE','54e227ec-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991122\", \"nombre\": \"chapilla chocolate oscuro\", \"costo\": 3.50, \"precio\": 3.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e227ec-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(474,'2026-02-12 00:20:42','productos','UPDATE','54e2286c-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991123\", \"nombre\": \"Gelatina fresa\", \"costo\": 5.00, \"precio\": 6.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2286c-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(475,'2026-02-12 00:20:42','productos','UPDATE','54e228eb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991124\", \"nombre\": \"pasta de ajo\", \"costo\": 0.40, \"precio\": 0.50, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e228eb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(476,'2026-02-12 00:20:42','productos','UPDATE','54e22968-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991125\", \"nombre\": \"Pasta verde cebollino\", \"costo\": 0.27, \"precio\": 0.29, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22968-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(477,'2026-02-12 00:20:42','productos','UPDATE','54e229e5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991126\", \"nombre\": \"mostaza\", \"costo\": 2.50, \"precio\": 2.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e229e5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(478,'2026-02-12 00:20:42','productos','UPDATE','54e22a63-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991127\", \"nombre\": \"mostaza agranel\", \"costo\": 2.50, \"precio\": 2.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22a63-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(479,'2026-02-12 00:20:42','productos','UPDATE','54e22ae3-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991128\", \"nombre\": \"semifrio de kiwi\", \"costo\": 1.50, \"precio\": 1.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22ae3-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(480,'2026-02-12 00:20:42','productos','UPDATE','54e22b67-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991129\", \"nombre\": \"Mousse limon\", \"costo\": 1.50, \"precio\": 1.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22b67-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(481,'2026-02-12 00:20:42','productos','UPDATE','54e22d84-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991130\", \"nombre\": \"Mouse Chocolate\", \"costo\": 1.50, \"precio\": 1.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e22d84-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(482,'2026-02-12 00:20:42','productos','UPDATE','54e2481e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991131\", \"nombre\": \"semifrio choco\", \"costo\": 1.50, \"precio\": 1.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2481e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(483,'2026-02-12 00:20:42','productos','UPDATE','54e24ac1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991132\", \"nombre\": \"Mouse de fresa\", \"costo\": 1.50, \"precio\": 1.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e24ac1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(484,'2026-02-12 00:20:42','productos','UPDATE','54e24c94-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991133\", \"nombre\": \"Crema Pastelera\", \"costo\": 2.00, \"precio\": 2.20, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e24c94-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(485,'2026-02-12 00:20:42','productos','UPDATE','54e24e33-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991134\", \"nombre\": \"Grenatina\", \"costo\": 4.60, \"precio\": 5.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e24e33-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(486,'2026-02-12 00:20:42','productos','UPDATE','54e24fcc-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991135\", \"nombre\": \"cocoa pura\", \"costo\": 1.00, \"precio\": 1.20, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e24fcc-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(487,'2026-02-12 00:20:42','productos','UPDATE','54e25137-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991136\", \"nombre\": \"gragea de colores\", \"costo\": 2.90, \"precio\": 3.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e25137-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(488,'2026-02-12 00:20:42','productos','UPDATE','54e252d4-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991137\", \"nombre\": \"grageas de chocolate\", \"costo\": 2.90, \"precio\": 3.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e252d4-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(489,'2026-02-12 00:20:42','productos','UPDATE','54e25472-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991138\", \"nombre\": \"Natilla de coco\", \"costo\": 0.50, \"precio\": 1.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e25472-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(490,'2026-02-12 00:20:42','productos','UPDATE','54e2573d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991139\", \"nombre\": \"margarina\", \"costo\": 2.60, \"precio\": 3.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2573d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(491,'2026-02-12 00:20:42','productos','UPDATE','54e2590f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991140\", \"nombre\": \"Natilla de Chocolate\", \"costo\": 0.64, \"precio\": 0.80, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2590f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(492,'2026-02-12 00:20:42','productos','UPDATE','54e25aad-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991141\", \"nombre\": \"Natilla de Fresa\", \"costo\": 0.43, \"precio\": 0.50, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e25aad-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(493,'2026-02-12 00:20:42','productos','UPDATE','54e25c4d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991142\", \"nombre\": \"Natilla de Limon\", \"costo\": 0.43, \"precio\": 0.50, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e25c4d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(494,'2026-02-12 00:20:42','productos','UPDATE','54e25dea-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991143\", \"nombre\": \"Natilla de Fanguito\", \"costo\": 0.50, \"precio\": 0.60, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e25dea-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(495,'2026-02-12 00:20:42','productos','UPDATE','54e2601e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991144\", \"nombre\": \"Fanguito\", \"costo\": 1.25, \"precio\": 1.20, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e2601e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(496,'2026-02-12 00:20:42','productos','UPDATE','54e261ba-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991145\", \"nombre\": \"Almibar (1:1,5)\", \"costo\": 0.36, \"precio\": 0.46, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e261ba-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(497,'2026-02-12 00:20:42','productos','UPDATE','54e26357-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991146\", \"nombre\": \"Pesto\", \"costo\": 0.27, \"precio\": 0.30, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e26357-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(498,'2026-02-12 00:20:42','productos','UPDATE','54e26529-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991147\", \"nombre\": \"Queso\", \"costo\": 0.50, \"precio\": 0.55, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e26529-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(499,'2026-02-12 00:20:42','productos','UPDATE','54e266c7-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991148\", \"nombre\": \"Panetelas Redolmy minicake\", \"costo\": 253.00, \"precio\": 253.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e266c7-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(500,'2026-02-12 00:20:42','productos','UPDATE','54e26863-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991149\", \"nombre\": \"merengue emul\", \"costo\": 129.00, \"precio\": 150.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e26863-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(501,'2026-02-12 00:20:42','productos','UPDATE','54e26a00-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991150\", \"nombre\": \"merengue italiano\", \"costo\": 159.19, \"precio\": 200.00, \"categoria\": \"DULCES\", \"activo\": 1, \"uuid\": \"54e26a00-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(502,'2026-02-12 00:20:42','productos','UPDATE','54e26b9a-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991151\", \"nombre\": \"Azucar de Vainilla\", \"costo\": 0.80, \"precio\": 0.90, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e26b9a-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(503,'2026-02-12 00:20:42','productos','UPDATE','54e26d35-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991152\", \"nombre\": \"Picadillo pollo mdm\", \"costo\": 0.63, \"precio\": 0.68, \"categoria\": \"CONGELADOS\", \"activo\": 1, \"uuid\": \"54e26d35-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(504,'2026-02-12 00:20:42','productos','UPDATE','54e26ed8-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991153\", \"nombre\": \"jugo de limon\", \"costo\": 0.25, \"precio\": 0.30, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e26ed8-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(505,'2026-02-12 00:20:42','productos','UPDATE','54e27073-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991154\", \"nombre\": \"Ron o alcohol\", \"costo\": 0.20, \"precio\": 0.25, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e27073-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(506,'2026-02-12 00:20:42','productos','UPDATE','54e27209-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991155\", \"nombre\": \"vinagre\", \"costo\": 0.35, \"precio\": 0.50, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e27209-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(507,'2026-02-12 00:20:42','productos','UPDATE','54e273a0-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991156\", \"nombre\": \"natilla guayaba\", \"costo\": 0.37, \"precio\": 0.40, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e273a0-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(508,'2026-02-12 00:20:42','productos','UPDATE','54e27b5f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991157\", \"nombre\": \"Colorante amarillo polvo\", \"costo\": 35.00, \"precio\": 38.00, \"categoria\": \"B. Alcoholicas\", \"activo\": 1, \"uuid\": \"54e27b5f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(509,'2026-02-12 00:20:42','productos','UPDATE','54e27d5f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991158\", \"nombre\": \"Almibar (1:1)\", \"costo\": 0.41, \"precio\": 0.50, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e27d5f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(510,'2026-02-12 00:20:42','productos','UPDATE','54e27f03-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991159\", \"nombre\": \"Colorante ENCO 40g\", \"costo\": 37.00, \"precio\": 40.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e27f03-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(511,'2026-02-12 00:20:42','productos','UPDATE','54e280a1-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991160\", \"nombre\": \"Colorante ENCO 20g\", \"costo\": 55.00, \"precio\": 60.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e280a1-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(512,'2026-02-12 00:20:42','productos','UPDATE','54e2823c-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991161\", \"nombre\": \"Colorante Cheffmaster 20g\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2823c-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(513,'2026-02-12 00:20:42','productos','UPDATE','54e2840a-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991162\", \"nombre\": \"Colorante Tinta negra\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2840a-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(514,'2026-02-12 00:20:42','productos','UPDATE','54e2856f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991163\", \"nombre\": \"Colorante Tinta rosada\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2856f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(515,'2026-02-12 00:20:42','productos','UPDATE','54e28706-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991164\", \"nombre\": \"Colorante Tinta namarilla\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e28706-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(516,'2026-02-12 00:20:42','productos','UPDATE','54e2889d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991165\", \"nombre\": \"Colorante Soft Gel (MIX) 25g\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2889d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(517,'2026-02-12 00:20:42','productos','UPDATE','54e28a03-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991166\", \"nombre\": \"Colorante OTELSA azul\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e28a03-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(518,'2026-02-12 00:20:42','productos','UPDATE','54e28b99-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991167\", \"nombre\": \"Colorante OTELSA rojo\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e28b99-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(519,'2026-02-12 00:20:42','productos','UPDATE','54e28d2e-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991168\", \"nombre\": \"Colorante Star Chemical\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e28d2e-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(520,'2026-02-12 00:20:42','productos','UPDATE','54e28ecb-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991169\", \"nombre\": \"Colorante agranel\", \"costo\": 30.00, \"precio\": 35.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e28ecb-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(521,'2026-02-12 00:20:42','productos','UPDATE','54e29067-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991170\", \"nombre\": \"Chips horneables de chocolate\", \"costo\": 4.50, \"precio\": 5.00, \"categoria\": \"INSUMOS\", \"activo\": 1, \"uuid\": \"54e29067-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(522,'2026-02-12 00:20:42','productos','UPDATE','54e291ff-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991171\", \"nombre\": \"Emulsionante supernortemul\", \"costo\": 7.00, \"precio\": 8.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e291ff-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(523,'2026-02-12 00:20:42','productos','UPDATE','54e29367-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991172\", \"nombre\": \"Saborizante Carolesen 60 ml\", \"costo\": 37.00, \"precio\": 40.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e29367-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(524,'2026-02-12 00:20:42','productos','UPDATE','54e29502-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991173\", \"nombre\": \"Saborizante Carolesen Tutifruti 510 ml\", \"costo\": 35.00, \"precio\": 38.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e29502-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(525,'2026-02-12 00:20:42','productos','UPDATE','54e2969d-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991174\", \"nombre\": \"Saborizante Loran 118 ml\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2969d-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(526,'2026-02-12 00:20:42','productos','UPDATE','54e29833-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991175\", \"nombre\": \"Saborizante La Anita 120 ml\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e29833-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(527,'2026-02-12 00:20:42','productos','UPDATE','54e299c5-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991176\", \"nombre\": \"Saborizante ENCO 120 ml\", \"costo\": 21.00, \"precio\": 22.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e299c5-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(528,'2026-02-12 00:20:42','productos','UPDATE','54e29b28-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991177\", \"nombre\": \"Saborizante ENCO 60 ml\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e29b28-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(529,'2026-02-12 00:20:42','productos','UPDATE','54e2a346-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991178\", \"nombre\": \"Saborizante Star Chemical\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2a346-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(530,'2026-02-12 00:20:42','productos','UPDATE','54e2a521-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991179\", \"nombre\": \"Saborizante Flor d Arancio\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2a521-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(531,'2026-02-12 00:20:42','productos','UPDATE','54e2a6b4-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991180\", \"nombre\": \"Saborizante Lorsnn Tutifruti\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2a6b4-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(532,'2026-02-12 00:20:42','productos','UPDATE','54e2a816-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991181\", \"nombre\": \"Saborizante DUCHE mangp 120 ml\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2a816-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(533,'2026-02-12 00:20:42','productos','UPDATE','54e2a9b0-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991182\", \"nombre\": \"Saborizante DEIMPIN guanabana 120 ml\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2a9b0-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(534,'2026-02-12 00:20:42','productos','UPDATE','54e2ab49-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991183\", \"nombre\": \"Saborizante Esencia 3,7ml\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2ab49-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(535,'2026-02-12 00:20:42','productos','UPDATE','54e2acdc-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991184\", \"nombre\": \"Saborizantes agranel\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2acdc-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(536,'2026-02-12 00:20:42','productos','UPDATE','54e2ae75-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991185\", \"nombre\": \"Tartaletas (base)\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2ae75-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(537,'2026-02-12 00:20:42','productos','UPDATE','54e2afde-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991186\", \"nombre\": \"Nata\", \"costo\": 2.20, \"precio\": 2.60, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2afde-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(538,'2026-02-12 00:20:42','productos','UPDATE','54e2b175-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991187\", \"nombre\": \"Leche condensada\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2b175-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(539,'2026-02-12 00:20:42','productos','UPDATE','54e2b30b-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991188\", \"nombre\": \"Refresco polvo Mayar\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2b30b-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(540,'2026-02-12 00:20:42','productos','UPDATE','54e2b49f-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991189\", \"nombre\": \"Mantequilla de mani\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2b49f-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(541,'2026-02-12 00:20:42','productos','UPDATE','54e2b604-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"991190\", \"nombre\": \"Coco deshidratado\", \"costo\": 2.00, \"precio\": 1.00, \"categoria\": \"\", \"activo\": 1, \"uuid\": \"54e2b604-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0),
(542,'2026-02-12 00:20:42','productos','UPDATE','54e2b7d7-01f4-11f1-b0a7-0affd1a0dee5','{\"codigo\": \"TEST-999\", \"nombre\": \"Producto de Prueba\", \"costo\": 20.00, \"precio\": 50.00, \"categoria\": \"Pruebas\", \"activo\": 1, \"uuid\": \"54e2b7d7-01f4-11f1-b0a7-0affd1a0dee5\"}',1,0);
/*!40000 ALTER TABLE `sync_journal` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transferencias_cabecera`
--

DROP TABLE IF EXISTS `transferencias_cabecera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transferencias_cabecera` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid_transf` varchar(64) NOT NULL,
  `id_empresa` int(11) NOT NULL,
  `id_almacen_origen` int(11) NOT NULL,
  `id_almacen_destino` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `estado` varchar(20) DEFAULT 'COMPLETADO',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid_transf` (`uuid_transf`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transferencias_cabecera`
--

LOCK TABLES `transferencias_cabecera` WRITE;
/*!40000 ALTER TABLE `transferencias_cabecera` DISABLE KEYS */;
/*!40000 ALTER TABLE `transferencias_cabecera` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transferencias_detalle`
--

DROP TABLE IF EXISTS `transferencias_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transferencias_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_transf_cabecera` int(11) NOT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `id_producto` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_transf_cabecera` (`id_transf_cabecera`),
  CONSTRAINT `fk_transf_cabecera` FOREIGN KEY (`id_transf_cabecera`) REFERENCES `transferencias_cabecera` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transferencias_detalle`
--

LOCK TABLES `transferencias_detalle` WRITE;
/*!40000 ALTER TABLE `transferencias_detalle` DISABLE KEYS */;
/*!40000 ALTER TABLE `transferencias_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `pin` varchar(10) NOT NULL,
  `rol` varchar(20) NOT NULL DEFAULT 'cajero',
  `id_sucursal` int(10) unsigned NOT NULL DEFAULT 1,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'Eddy',NULL,NULL,'4321','cajero',4,1,NULL,'2026-02-10 19:13:19','2026-02-10 19:13:19'),
(2,'pablito',NULL,NULL,'6556','cajero',4,1,NULL,'2026-02-10 19:13:19','2026-02-10 19:13:19'),
(3,'Miriam',NULL,NULL,'2233','cajero',4,1,NULL,'2026-02-10 19:13:19','2026-02-10 19:13:19'),
(4,'Admin',NULL,NULL,'0000','admin',4,1,NULL,'2026-02-10 19:13:19','2026-02-10 19:13:19');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ventas_cabecera`
--

DROP TABLE IF EXISTS `ventas_cabecera`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ventas_cabecera` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid_venta` varchar(64) NOT NULL,
  `fecha` datetime NOT NULL,
  `total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `metodo_pago` varchar(50) DEFAULT 'Efectivo',
  `precio_tipo` varchar(20) DEFAULT 'normal',
  `descuento_orden` decimal(5,2) DEFAULT 0.00,
  `id_sucursal` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `id_empresa` int(11) NOT NULL DEFAULT 1,
  `id_almacen` int(11) DEFAULT NULL,
  `id_caja` int(11) DEFAULT 1,
  `tipo_servicio` varchar(50) DEFAULT 'consumir_aqui',
  `fecha_reserva` datetime DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `cliente_nombre` varchar(100) DEFAULT 'Mostrador',
  `cliente_direccion` varchar(255) DEFAULT NULL,
  `cliente_telefono` varchar(50) DEFAULT NULL,
  `mensajero_nombre` varchar(100) DEFAULT NULL,
  `abono_reserva` decimal(10,2) DEFAULT 0.00,
  `id_sesion_caja` int(11) DEFAULT 0,
  `abono` decimal(10,2) DEFAULT 0.00,
  `estado_reserva` varchar(20) DEFAULT 'PENDIENTE',
  `uuid` char(36) DEFAULT NULL,
  `id_cliente` int(11) DEFAULT 0,
  `sincronizado` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid_venta` (`uuid_venta`),
  UNIQUE KEY `idx_uuid` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ventas_cabecera`
--

LOCK TABLES `ventas_cabecera` WRITE;
/*!40000 ALTER TABLE `ventas_cabecera` DISABLE KEYS */;
INSERT INTO `ventas_cabecera` VALUES
(1,'d15204da-77f2-4b3c-bb77-ec8a3548ca4b','2026-01-23 22:57:36',2280.00,'Transferencia','normal',0.00,4,'2026-01-30 03:57:36',1,4,1,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea96ad-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(2,'8713ae35-461f-48dc-b4b0-f759cd0affc2','2026-01-23 22:59:43',5010.00,'Efectivo','normal',0.00,4,'2026-01-30 03:59:43',1,4,1,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9a3b-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(3,'1e42bbc3-780b-42f4-be8c-7b0868f8fdd9','2026-01-24 23:04:03',3160.00,'Efectivo','normal',0.00,4,'2026-01-30 04:04:03',1,4,2,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9af2-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(4,'33bffd61-2650-4981-8afe-62738333af5d','2026-01-24 23:04:11',70.00,'Transferencia','normal',0.00,4,'2026-01-30 04:04:11',1,4,2,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9b81-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(5,'0f2ecc02-3038-4a1b-a046-e116d113d415','2026-01-24 23:06:02',4475.00,'Efectivo','normal',0.00,4,'2026-01-30 04:06:02',1,4,2,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9c0d-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(6,'ref_697c2e8df35fb','2026-01-29 23:07:41',-600.00,'Devolución','normal',0.00,4,'2026-01-30 04:07:41',1,4,2,'mostrador',NULL,NULL,'DEVOLUCIÓN',NULL,NULL,NULL,0.00,0,0.00,'PENDIENTE','54ea9c96-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(7,'88c54cd5-8a86-4622-adf2-4dd9233c9d99','2026-01-24 23:07:59',840.00,'Efectivo','normal',0.00,4,'2026-01-30 04:07:59',1,4,2,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9d33-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(8,'c10dd738-8ed7-4626-b582-daaff5b71070','2026-01-24 23:08:54',800.00,'Efectivo','normal',0.00,4,'2026-01-30 04:08:54',1,4,2,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9dbd-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(9,'a3d0b2ba-84cc-48ea-a393-c50db170ec9b','2026-01-25 23:16:39',4090.00,'Transferencia','normal',0.00,4,'2026-01-30 04:16:39',1,4,3,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9e41-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(10,'559adfe7-00f9-469e-a25d-2a28f9e95e17','2026-01-25 23:22:47',10225.00,'Efectivo','normal',0.00,4,'2026-01-30 04:22:47',1,4,3,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9ec8-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(11,'ref_697c32b5d6868','2026-01-29 23:25:25',-80.00,'Devolución','normal',0.00,4,'2026-01-30 04:25:25',1,4,3,'mostrador',NULL,NULL,'DEVOLUCIÓN',NULL,NULL,NULL,0.00,0,0.00,'PENDIENTE','54ea9f4a-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(12,'848e21fd-4e14-4549-a85b-f4b811a59eb0','2026-01-26 23:30:43',4210.00,'Efectivo','normal',0.00,4,'2026-01-30 04:30:43',1,4,4,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54ea9fd8-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(13,'ba690b11-a9d3-49ef-9253-2d2ecdb34c6a','2026-01-26 23:31:15',1100.00,'Efectivo','normal',0.00,4,'2026-01-30 04:31:15',1,4,4,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa060-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(14,'36342fe8-dc7d-4a95-94d7-75e457bf8a19','2026-01-27 23:38:36',1260.00,'Transferencia','normal',0.00,4,'2026-01-30 04:38:36',1,4,5,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa0e2-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(15,'3b5ed7c9-4299-47c9-ac9d-4f335245ace1','2026-01-27 23:43:34',4855.00,'Efectivo','normal',0.00,4,'2026-01-30 04:43:34',1,4,5,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa166-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(16,'8857aeb4-6d23-402c-a694-f805a6375f8a','2026-01-28 23:50:41',1000.00,'Transferencia','normal',0.00,4,'2026-01-30 04:50:41',1,4,6,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa1ec-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(17,'d9847b64-713e-4b29-9822-2233650e1603','2026-01-28 23:51:49',2020.00,'Efectivo','normal',0.00,4,'2026-01-30 04:51:49',1,4,6,'llevar',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa26c-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(18,'cc834b53-292c-4923-9099-775e6d38b79e','2026-01-28 23:53:01',3235.00,'Efectivo','normal',0.00,4,'2026-01-30 04:53:01',1,4,6,'llevar',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa2f0-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(19,'c790b30a-aad6-415b-8e48-9dd64d886853','2026-01-28 23:53:25',400.00,'Efectivo','normal',0.00,4,'2026-01-30 04:53:25',1,4,6,'llevar',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa376-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(20,'3350a593-3a66-420d-a204-f81943e4bead','2026-01-12 10:23:36',360.00,'Efectivo','normal',0.00,4,'2026-01-30 15:23:36',1,4,9,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa3fd-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(21,'c43d9e87-34f6-474d-a415-96c75781585f','2026-01-12 10:24:26',650.00,'Transferencia','normal',0.00,4,'2026-01-30 15:24:26',1,4,9,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa47e-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(22,'ref_697cd52827ea6','2026-01-30 10:58:32',-360.00,'Devolución','normal',0.00,4,'2026-01-30 15:58:32',1,4,9,'mostrador',NULL,NULL,'DEVOLUCIÓN',NULL,NULL,NULL,0.00,0,0.00,'PENDIENTE','54eaa4ff-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(23,'ref_697cd52dc681a','2026-01-30 10:58:37',-650.00,'Devolución','normal',0.00,4,'2026-01-30 15:58:37',1,4,9,'mostrador',NULL,NULL,'DEVOLUCIÓN',NULL,NULL,NULL,0.00,0,0.00,'PENDIENTE','54eaa581-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(24,'aa47e786-1ecc-4a49-b874-b1d0b992f10d','2026-01-29 08:24:46',2030.00,'Transferencia','normal',0.00,4,'2026-01-31 13:24:46',1,4,11,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa604-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(25,'21eb7668-3a07-4ebe-a80d-466608d9c4de','2026-01-29 08:26:41',3850.00,'Efectivo','normal',0.00,4,'2026-01-31 13:26:41',1,4,11,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa685-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(26,'96a4d1ec-5fe9-4ea4-8060-ef1303ade089','2026-01-29 09:02:12',2980.00,'Efectivo','normal',0.00,4,'2026-01-31 14:02:12',1,4,11,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa708-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(27,'81bb0a8f-7371-47c1-a1ac-e7a9b5de7325','2026-01-29 09:05:09',4080.00,'Efectivo','normal',0.00,4,'2026-01-31 14:05:09',1,4,11,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa78b-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(28,'3415c097-3477-455d-9060-cca6ea944420','2026-01-30 20:41:34',1830.00,'Transferencia','normal',0.00,4,'2026-02-02 01:41:34',1,4,12,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa810-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(29,'77acc414-0fea-4b8a-8cb7-2a1c646eae54','2026-01-30 20:54:53',4400.00,'Transferencia','normal',0.00,4,'2026-02-02 01:54:53',1,4,12,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa891-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(30,'14336593-fa54-416a-86cb-01385639480b','2026-01-30 20:56:11',240.00,'Transferencia','normal',0.00,4,'2026-02-02 01:56:11',1,4,12,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa911-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(31,'1ca9cbfb-2668-42f2-b3f2-8150b6a49f97','2026-01-30 21:33:07',3550.00,'Efectivo','normal',0.00,4,'2026-02-02 02:33:07',1,4,12,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaa995-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(32,'b73a02c7-e8bd-43bf-bbc8-f31d463d91f0','2026-01-30 21:35:17',3230.00,'Efectivo','normal',0.00,4,'2026-02-02 02:35:17',1,4,12,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaaa18-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(33,'a9d484a1-6685-4d3c-8931-5f774567d861','2026-01-30 21:38:39',4440.00,'Efectivo','normal',0.00,4,'2026-02-02 02:38:39',1,4,12,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaaa82-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(34,'e785d5bf-9c36-44b6-8d90-a732114bfc92','2026-01-30 21:41:00',15.00,'Efectivo','normal',0.00,4,'2026-02-02 02:41:00',1,4,12,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaaae7-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(35,'0cc7080b-202a-4662-a10b-70a05198de28','2026-01-31 19:12:03',2800.00,'Transferencia','normal',0.00,4,'2026-02-03 00:12:03',1,4,13,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaab4b-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(36,'8913d46b-9762-4eb1-a95f-948b6929614d','2026-01-31 19:12:19',400.00,'Transferencia','normal',0.00,4,'2026-02-03 00:12:19',1,4,13,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaabb2-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(37,'90abcc35-da66-4404-8395-b226b3b15131','2026-01-31 19:25:31',12250.00,'Efectivo','normal',0.00,4,'2026-02-03 00:25:31',1,4,13,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaac17-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(38,'17512f1c-6367-4db3-9483-e1398a2cf6ea','2026-01-31 19:25:54',320.00,'Efectivo','normal',0.00,4,'2026-02-03 00:25:54',1,4,13,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaac7a-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(39,'f52f8800-8b33-49d1-bffc-77f8d6900b30','2026-02-01 08:26:07',3340.00,'Efectivo','normal',0.00,4,'2026-02-04 13:26:07',1,4,14,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaacdd-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(40,'15a08e2c-2ffc-4556-b45a-f8a9304a1612','2026-02-01 08:40:54',7840.00,'Efectivo','normal',0.00,4,'2026-02-04 13:40:54',1,4,14,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaad43-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(41,'891ff1cb-d641-4565-bdbc-35094ad958e0','2026-02-02 09:40:34',5440.00,'Efectivo','normal',0.00,4,'2026-02-04 14:40:34',1,4,15,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaada6-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(42,'e0588d1a-d9d4-4d64-9feb-87093f7d6cab','2026-02-03 10:40:12',2180.00,'Efectivo','normal',0.00,4,'2026-02-04 15:40:12',1,4,16,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaae08-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(43,'f33624f3-ecbb-42eb-bf12-0145f77d2c30','2026-02-03 11:08:00',1660.00,'Efectivo','normal',0.00,4,'2026-02-04 16:08:00',1,4,16,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaae6b-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(44,'6151bc0b-bf79-4bd5-9e34-70ff455e32a3','2026-02-03 11:21:13',140.00,'Efectivo','normal',0.00,4,'2026-02-04 16:21:13',1,4,16,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','54eaaed4-01f4-11f1-b0a7-0affd1a0dee5',0,0),
(70,'8bcb0cd7-814f-4e0e-8fa0-05c6843e3f8e','2026-02-06 11:00:24',80.00,'Efectivo','normal',0.00,4,'2026-02-06 16:00:24',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,0,0.00,'PENDIENTE','f28d7f06-0374-11f1-b0a7-0affd1a0dee5',0,1),
(71,'28a46d23-02bb-4760-be7c-57edb8525b4e','2026-02-06 12:10:31',560.00,'Efectivo','normal',0.00,4,'2026-02-06 17:10:31',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','be0ea5b4-037e-11f1-b0a7-0affd1a0dee5',0,1),
(72,'3c53d996-439d-47f1-8945-cf4acc8012c2','2026-02-06 16:02:51',3520.00,'Efectivo','normal',0.00,4,'2026-02-06 21:02:51',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','32f56a19-039f-11f1-b0a7-0affd1a0dee5',0,1),
(73,'f69bd680-8538-49d5-90d2-bbd03cd5fae5','2026-02-06 16:02:51',1920.00,'Efectivo','normal',0.00,4,'2026-02-06 21:02:51',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','3309cfa9-039f-11f1-b0a7-0affd1a0dee5',0,1),
(78,'ref_698745dd80993','2026-02-07 09:02:05',-80.00,'Devolución','normal',0.00,4,'2026-02-07 14:02:05',1,4,17,'mostrador',NULL,NULL,'DEVOLUCIÓN',NULL,NULL,NULL,0.00,0,0.00,'PENDIENTE','959c2084-042d-11f1-b0a7-0affd1a0dee5',0,0),
(79,'ref_6987473641257','2026-02-07 09:07:50',-560.00,'Devolución','normal',0.00,4,'2026-02-07 14:07:50',1,4,17,'mostrador',NULL,NULL,'DEVOLUCIÓN',NULL,NULL,NULL,0.00,0,0.00,'PENDIENTE','63174dd3-042e-11f1-b0a7-0affd1a0dee5',0,0),
(86,'15e90eba-ce45-42e4-83cf-6b0b6c191464','2026-02-07 09:45:26',3600.00,'Efectivo','normal',0.00,4,'2026-02-07 14:45:26',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','a41b0f7f-0433-11f1-b0a7-0affd1a0dee5',0,1),
(87,'c6d7cb6d-5236-4dbd-80bd-e9462dd19b78','2026-02-07 09:46:06',1040.00,'Efectivo','normal',0.00,4,'2026-02-07 14:46:06',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','bc079dfa-0433-11f1-b0a7-0affd1a0dee5',0,1),
(88,'c0987f91-af27-4c54-9917-9fb1e1ea94c2','2026-02-07 09:47:06',890.00,'Efectivo','normal',0.00,4,'2026-02-07 14:47:06',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','dfc2b907-0433-11f1-b0a7-0affd1a0dee5',0,1),
(89,'c698c1ad-44d2-421c-9eb1-03df4ff8ac84','2026-02-07 09:49:29',2670.00,'Efectivo','normal',0.00,4,'2026-02-07 14:49:29',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','34aa7e0a-0434-11f1-b0a7-0affd1a0dee5',0,1),
(90,'d819ca41-2dd6-4714-add5-2008b180b1c5','2026-02-07 09:50:01',1700.00,'Efectivo','normal',0.00,4,'2026-02-07 14:50:01',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','47abbfbb-0434-11f1-b0a7-0affd1a0dee5',0,1),
(91,'7626f5d4-fcba-4f48-a080-b508d4c0e5a2','2026-02-07 09:50:14',1300.00,'Efectivo','normal',0.00,4,'2026-02-07 14:50:14',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','4f6b4d44-0434-11f1-b0a7-0affd1a0dee5',0,1),
(92,'805cd0e7-db2d-4219-a39e-e75135a4d157','2026-02-07 09:50:38',60.00,'Efectivo','normal',0.00,4,'2026-02-07 14:50:38',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','5e28fe26-0434-11f1-b0a7-0affd1a0dee5',0,1),
(93,'18f644b8-b5ab-4fad-91d8-210ff84a7adc','2026-02-07 09:50:49',80.00,'Transferencia','normal',0.00,4,'2026-02-07 14:50:49',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','64942fa3-0434-11f1-b0a7-0affd1a0dee5',0,1),
(94,'99365e78-f149-4491-b51f-a13c078b253b','2026-02-07 09:58:21',600.00,'Efectivo','normal',0.00,4,'2026-02-07 14:58:21',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','721d6f66-0435-11f1-b0a7-0affd1a0dee5',0,1),
(95,'2706a30a-c686-4065-b6c8-22ce4b5e02cf','2026-02-07 09:58:59',240.00,'Efectivo','normal',0.00,4,'2026-02-07 14:58:59',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','885624d6-0435-11f1-b0a7-0affd1a0dee5',0,1),
(96,'5ac081a6-9bc3-4a3f-9018-8b6e7e8dc327','2026-02-07 09:59:05',80.00,'Transferencia','normal',0.00,4,'2026-02-07 14:59:05',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','8be25d2f-0435-11f1-b0a7-0affd1a0dee5',0,1),
(97,'c2f04d08-bd70-4e1c-9f4d-e047d3bd4d0a','2026-02-07 09:59:26',350.00,'Efectivo','normal',0.00,4,'2026-02-07 14:59:26',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','984f9989-0435-11f1-b0a7-0affd1a0dee5',0,1),
(98,'c33124a9-60b1-46e0-8670-264b250add96','2026-02-07 09:59:36',280.00,'Efectivo','normal',0.00,4,'2026-02-07 14:59:36',1,4,17,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,17,0.00,'PENDIENTE','9e7e9738-0435-11f1-b0a7-0affd1a0dee5',0,1),
(99,'11c0bf2e-f5e7-41ef-adf0-c8cd28e514f5','2026-02-07 10:18:53',180.00,'Efectivo','normal',0.00,4,'2026-02-07 15:18:53',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','503a854a-0438-11f1-b0a7-0affd1a0dee5',0,1),
(100,'c2494d1d-2efc-4379-98dd-2506fb26528e','2026-02-07 10:19:40',1000.00,'Transferencia','normal',0.00,4,'2026-02-07 15:19:40',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','6c5cfdca-0438-11f1-b0a7-0affd1a0dee5',0,1),
(101,'6abec1b5-cf30-4f8b-9903-364afbefa4ee','2026-02-07 10:20:45',240.00,'Efectivo','normal',0.00,4,'2026-02-07 15:20:45',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','930d47d6-0438-11f1-b0a7-0affd1a0dee5',0,1),
(102,'a9c57b44-d9c2-49f8-a862-ff62ffd85b5e','2026-02-07 10:27:03',2600.00,'Efectivo','normal',0.00,4,'2026-02-07 15:27:03',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','742738fc-0439-11f1-b0a7-0affd1a0dee5',0,1),
(103,'ebbcb79c-007a-4520-a256-2175715a1bb9','2026-02-07 10:28:05',1970.00,'Efectivo','normal',0.00,4,'2026-02-07 15:28:05',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','9910c1d3-0439-11f1-b0a7-0affd1a0dee5',0,1),
(104,'d259a362-5e94-4379-b2cf-9cee8c58d466','2026-02-07 10:28:33',30.00,'Efectivo','normal',0.00,4,'2026-02-07 15:28:33',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','aa15ba40-0439-11f1-b0a7-0affd1a0dee5',0,1),
(105,'63baa656-ee0e-446a-a405-c9fdbbaced83','2026-02-07 10:29:06',1200.00,'Efectivo','normal',0.00,4,'2026-02-07 15:29:06',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','bd82d37b-0439-11f1-b0a7-0affd1a0dee5',0,1),
(106,'fd7ce5be-1efb-4893-a2a2-d75b7cae9be6','2026-02-07 10:29:32',30.00,'Transferencia','normal',0.00,4,'2026-02-07 15:29:32',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','cd070eae-0439-11f1-b0a7-0affd1a0dee5',0,1),
(107,'103ec75b-9f0e-4cca-ab06-3b9491371aaa','2026-02-07 11:11:52',790.00,'Efectivo','normal',0.00,4,'2026-02-07 16:11:53',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','b7541584-043f-11f1-b0a7-0affd1a0dee5',0,1),
(108,'dc72d799-44dc-4f50-9162-7e84009f20bc','2026-02-07 11:12:27',1040.00,'Efectivo','normal',0.00,4,'2026-02-07 16:12:27',1,4,18,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,18,0.00,'PENDIENTE','cc096d3b-043f-11f1-b0a7-0affd1a0dee5',0,1),
(109,'ec1df56a-1d1c-47ee-a578-a4f5699ae35b','2026-02-08 09:03:42',6590.00,'Transferencia','normal',0.00,4,'2026-02-08 14:03:42',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','f9d4d5b0-04f6-11f1-b0a7-0affd1a0dee5',0,1),
(110,'52b195d7-ae82-44f8-8da6-cfc395cbfeee','2026-02-08 09:08:19',1470.00,'Transferencia','normal',0.00,4,'2026-02-08 14:08:19',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','9efb1281-04f7-11f1-b0a7-0affd1a0dee5',0,1),
(111,'c85ccbd1-2f1e-49e1-82d1-6bb551dc35cd','2026-02-08 09:17:55',680.00,'Transferencia','normal',0.00,4,'2026-02-08 14:17:55',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','f655d864-04f8-11f1-b0a7-0affd1a0dee5',0,1),
(112,'0ccc5afc-f83a-4209-89f8-3cffcb45af6a','2026-02-08 10:10:46',270.00,'Efectivo','normal',0.00,4,'2026-02-08 15:10:46',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','58301406-0500-11f1-b0a7-0affd1a0dee5',0,1),
(113,'502b085c-face-47af-8a30-b8bbc221485d','2026-02-08 10:11:16',740.00,'Efectivo','normal',0.00,4,'2026-02-08 15:11:16',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','6a2e171c-0500-11f1-b0a7-0affd1a0dee5',0,1),
(114,'d2bf011d-b768-4303-993d-80e00a5e9127','2026-02-08 10:11:53',960.00,'Efectivo','normal',0.00,4,'2026-02-08 15:11:53',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','804b921e-0500-11f1-b0a7-0affd1a0dee5',0,1),
(115,'2a8e2760-4468-4678-bb29-22cbfea7d5a7','2026-02-08 10:12:44',60.00,'Efectivo','normal',0.00,4,'2026-02-08 15:12:44',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','9e5d55ac-0500-11f1-b0a7-0affd1a0dee5',0,1),
(116,'2c5281a8-1401-46bd-8ac7-a07a38e85246','2026-02-08 10:12:55',1680.00,'Efectivo','normal',0.00,4,'2026-02-08 15:12:55',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','a5793897-0500-11f1-b0a7-0affd1a0dee5',0,1),
(117,'86e9a541-eb52-4620-bf9d-e5597c90d41a','2026-02-08 10:13:22',70.00,'Efectivo','normal',0.00,4,'2026-02-08 15:13:22',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','b5386000-0500-11f1-b0a7-0affd1a0dee5',0,1),
(118,'85ef2575-9a37-4637-8103-db649dfc23c2','2026-02-08 10:13:53',600.00,'Efectivo','normal',0.00,4,'2026-02-08 15:13:53',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','c7e00b4b-0500-11f1-b0a7-0affd1a0dee5',0,1),
(119,'eee7548b-c659-44b8-88c6-fef5c1e7691e','2026-02-08 10:15:45',2610.00,'Efectivo','normal',0.00,4,'2026-02-08 15:15:45',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','0a5f6757-0501-11f1-b0a7-0affd1a0dee5',0,1),
(120,'ref_6988e7a2dcdbb','2026-02-08 14:44:34',-750.00,'Devolución','normal',0.00,4,'2026-02-08 19:44:34',1,4,19,'mostrador',NULL,NULL,'DEVOLUCIÓN',NULL,NULL,NULL,0.00,0,0.00,'PENDIENTE','98681c67-0526-11f1-b0a7-0affd1a0dee5',0,0),
(121,'845fa625-9bb8-4d5b-8dfc-a8a5da3b3ee8','2026-02-08 14:45:06',600.00,'Efectivo','normal',0.00,4,'2026-02-08 19:45:06',1,4,19,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,19,0.00,'PENDIENTE','ab8871cc-0526-11f1-b0a7-0affd1a0dee5',0,1),
(122,'ee085fb1-e7d0-4d3f-a13c-061dd9693ac2','2026-02-08 14:50:34',300.00,'Efectivo','normal',0.00,4,'2026-02-08 19:50:34',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','6e7ba845-0527-11f1-b0a7-0affd1a0dee5',0,1),
(123,'7a32f0a0-32ab-4946-b06a-fd03a5328dc4','2026-02-08 20:01:26',4560.00,'Transferencia','normal',0.00,4,'2026-02-09 01:01:26',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','dc247b42-0552-11f1-b0a7-0affd1a0dee5',0,1),
(124,'48acbb12-4c25-4301-9cef-c2f129b62f5b','2026-02-08 20:02:33',160.00,'Transferencia','normal',0.00,4,'2026-02-09 01:02:33',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','043a1c1a-0553-11f1-b0a7-0affd1a0dee5',0,1),
(125,'5790bdc8-7379-49dc-a613-afa6c92346e8','2026-02-10 10:58:42',280.00,'Transferencia','normal',0.00,4,'2026-02-10 15:58:42',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','5f8aeed1-0699-11f1-b0a7-0affd1a0dee5',0,1),
(126,'a6b34837-ebba-45e9-b687-80cea2a1d1d7','2026-02-10 11:00:46',360.00,'Efectivo','normal',0.00,4,'2026-02-10 16:00:46',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','a9287be4-0699-11f1-b0a7-0affd1a0dee5',0,1),
(127,'8557b1ab-b883-43f9-867b-bc053910ab2c','2026-02-10 11:01:05',560.00,'Efectivo','normal',0.00,4,'2026-02-10 16:01:05',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','b4c69bc8-0699-11f1-b0a7-0affd1a0dee5',0,1),
(128,'70849d47-dea6-48ac-b791-4804d9c52999','2026-02-10 11:02:38',9070.00,'Efectivo','normal',0.00,4,'2026-02-10 16:02:38',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','ec2549ae-0699-11f1-b0a7-0affd1a0dee5',0,1),
(129,'23a1532f-e410-4255-91d6-0385d78c9190','2026-02-10 11:03:12',650.00,'Efectivo','normal',0.00,4,'2026-02-10 16:03:12',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','0053e1c9-069a-11f1-b0a7-0affd1a0dee5',0,1),
(130,'ab583eae-b468-4164-b54b-c84c9f98f967','2026-02-10 11:43:15',2470.00,'Efectivo','normal',0.00,4,'2026-02-10 16:43:15',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','98ba87f2-069f-11f1-b0a7-0affd1a0dee5',0,1),
(131,'a24869d5-252e-407c-8319-2cd6759745b9','2026-02-10 11:47:51',2550.00,'Efectivo','normal',0.00,4,'2026-02-10 16:47:51',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','3d4a53de-06a0-11f1-b0a7-0affd1a0dee5',0,1),
(132,'6c183c47-5444-4ac6-86d7-9d2ff6879cb2','2026-02-10 11:53:18',160.00,'Efectivo','normal',0.00,4,'2026-02-10 16:53:18',1,4,20,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,20,0.00,'PENDIENTE','0047a973-06a1-11f1-b0a7-0affd1a0dee5',0,1),
(133,'6320458c-2a74-467f-a3e4-208ddef03f71','2026-02-10 12:12:38',1220.00,'Efectivo','normal',0.00,4,'2026-02-10 17:12:38',1,4,21,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,21,0.00,'PENDIENTE','b3a0dac9-06a3-11f1-b0a7-0affd1a0dee5',0,1),
(134,'f45f66f0-9e77-46b2-96cb-bf00c1478b95','2026-02-10 12:14:14',1890.00,'Efectivo','normal',0.00,4,'2026-02-10 17:14:14',1,4,21,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,21,0.00,'PENDIENTE','ecc74ac6-06a3-11f1-b0a7-0affd1a0dee5',0,1),
(135,'653d5d6c-521a-489c-b3cd-f5fb30db139f','2026-02-10 12:15:38',850.00,'Efectivo','normal',0.00,4,'2026-02-10 17:15:38',1,4,21,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,21,0.00,'PENDIENTE','1ec85b39-06a4-11f1-b0a7-0affd1a0dee5',0,1),
(136,'cc1fa1ab-4ddc-4679-9d82-cea8500dcc8f','2026-02-10 12:24:30',5680.00,'Efectivo','normal',0.00,4,'2026-02-10 17:24:30',1,4,21,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,21,0.00,'PENDIENTE','5bdd9c36-06a5-11f1-b0a7-0affd1a0dee5',0,1),
(137,'41fbd60f-2b8b-40d5-b26f-3e243686045c','2026-02-10 12:25:05',1440.00,'Efectivo','normal',0.00,4,'2026-02-10 17:25:05',1,4,21,'consumir_aqui',NULL,NULL,'Consumidor Final','','','',0.00,21,0.00,'PENDIENTE','7098527c-06a5-11f1-b0a7-0affd1a0dee5',0,1),
(138,'V-698c936254e846.69134372','2026-02-11 09:34:10',300.00,'Efectivo','normal',0.00,4,'2026-02-11 09:34:10',1,4,1,'Mesa',NULL,NULL,'Mostrador',NULL,NULL,NULL,0.00,22,0.00,'PENDIENTE','96d99b6f-67a3-433c-8c29-fedd0e50cddc',0,0),
(139,'V-698cd7e5efd535.86715175','2026-02-11 14:26:29',150.00,'Transferencia','normal',0.00,4,'2026-02-11 14:26:29',1,4,1,'Delivery',NULL,NULL,'Mostrador',NULL,NULL,NULL,0.00,23,0.00,'PENDIENTE','d4441ca4-ac7c-4838-8edd-44686746e38a',1,0),
(140,'V-698d094611ffc5.48684973','2026-02-11 17:57:10',150.00,'Mixto','normal',0.00,4,'2026-02-11 17:57:10',1,4,1,'Reserva','2026-02-21 17:57:00',NULL,'sureima chales','522 East 12th Street','7868082963',NULL,0.00,23,25.00,'PENDIENTE','4b61b3b3-f5ce-41cd-8db8-e886bd708d7a',1,0);
/*!40000 ALTER TABLE `ventas_cabecera` ENABLE KEYS */;
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
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_ventas_ai BEFORE INSERT ON ventas_cabecera
FOR EACH ROW
BEGIN
    IF NEW.uuid IS NULL THEN SET NEW.uuid = UUID(); END IF;
    
    INSERT INTO sync_journal (tabla, accion, registro_uuid, datos_json, origen_sucursal_id)
    VALUES ('ventas_cabecera', 'INSERT', NEW.uuid, JSON_OBJECT(
        'fecha', NEW.fecha, 'total', NEW.total, 'metodo_pago', NEW.metodo_pago,
        'id_cliente', 0, 
        'id_sucursal', NEW.id_sucursal, 'cliente_nombre', NEW.cliente_nombre,
        'uuid', NEW.uuid
    ), NEW.id_sucursal);
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `ventas_detalle`
--

DROP TABLE IF EXISTS `ventas_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ventas_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta_cabecera` int(11) NOT NULL,
  `cantidad` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `precio` decimal(15,2) NOT NULL DEFAULT 0.00,
  `nombre_producto` text DEFAULT NULL,
  `codigo_producto` text DEFAULT NULL,
  `categoria_producto` text DEFAULT NULL,
  `descuento_pct` decimal(5,2) DEFAULT 0.00,
  `descuento_monto` decimal(10,2) DEFAULT 0.00,
  `reembolsado` tinyint(4) DEFAULT 0,
  `id_producto` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_detalle_cabecera` (`id_venta_cabecera`),
  KEY `idx_vta_prod` (`id_producto`),
  CONSTRAINT `fk_detalle_cabecera` FOREIGN KEY (`id_venta_cabecera`) REFERENCES `ventas_cabecera` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=353 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ventas_detalle`
--

LOCK TABLES `ventas_detalle` WRITE;
/*!40000 ALTER TABLE `ventas_detalle` DISABLE KEYS */;
INSERT INTO `ventas_detalle` VALUES
(1,1,2.0000,70.00,NULL,NULL,NULL,0.00,0.00,0,'660001'),
(2,1,1.0000,400.00,NULL,NULL,NULL,0.00,0.00,0,'660004'),
(3,1,1.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660007'),
(4,1,1.0000,180.00,NULL,NULL,NULL,0.00,0.00,0,'660008'),
(5,1,1.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660011'),
(6,1,4.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(7,1,7.0000,80.00,NULL,NULL,NULL,0.00,0.00,0,'660013'),
(8,1,2.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(9,2,1.0000,500.00,NULL,NULL,NULL,0.00,0.00,0,'660015'),
(10,2,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(11,2,13.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660023'),
(12,2,5.0000,230.00,NULL,NULL,NULL,0.00,0.00,0,'660024'),
(13,3,2.0000,360.00,NULL,NULL,NULL,0.00,0.00,0,'660003'),
(14,3,1.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660007'),
(15,3,4.0000,80.00,NULL,NULL,NULL,0.00,0.00,0,'660013'),
(16,3,2.0000,360.00,NULL,NULL,NULL,0.00,0.00,0,'660014'),
(17,3,2.0000,680.00,NULL,NULL,NULL,0.00,0.00,0,'660018'),
(18,4,1.0000,70.00,NULL,NULL,NULL,0.00,0.00,0,'660001'),
(19,5,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660022'),
(20,5,11.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660023'),
(21,5,4.0000,230.00,NULL,NULL,NULL,0.00,0.00,0,'660024'),
(22,5,6.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(23,5,5.0000,15.00,NULL,NULL,NULL,0.00,0.00,0,'660029'),
(24,6,-6.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(25,7,6.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660028'),
(26,8,8.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(27,9,3.0000,400.00,NULL,NULL,NULL,0.00,0.00,0,'660004'),
(28,9,1.0000,150.00,NULL,NULL,NULL,0.00,0.00,0,'660009'),
(29,9,4.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660011'),
(30,9,1.0000,80.00,NULL,NULL,NULL,0.00,0.00,0,'660013'),
(31,9,2.0000,680.00,NULL,NULL,NULL,0.00,0.00,0,'660018'),
(32,9,3.0000,60.00,NULL,NULL,NULL,0.00,0.00,0,'660019'),
(33,10,2.0000,80.00,NULL,NULL,NULL,0.00,0.00,0,'660013'),
(34,10,1.0000,650.00,NULL,NULL,NULL,0.00,0.00,0,'660020'),
(35,10,4.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(36,10,4.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660022'),
(37,10,15.0000,230.00,NULL,NULL,NULL,0.00,0.00,0,'660024'),
(38,10,1.0000,520.00,NULL,NULL,NULL,0.00,0.00,0,'660025'),
(39,10,2.0000,580.00,NULL,NULL,NULL,0.00,0.00,0,'660026'),
(40,10,3.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660027'),
(41,10,16.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(42,10,3.0000,15.00,NULL,NULL,NULL,0.00,0.00,0,'660029'),
(43,11,-1.0000,80.00,NULL,NULL,NULL,0.00,0.00,0,'660013'),
(44,12,2.0000,180.00,NULL,NULL,NULL,0.00,0.00,0,'660008'),
(45,12,1.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660011'),
(46,12,2.0000,80.00,NULL,NULL,NULL,0.00,0.00,0,'660013'),
(47,12,2.0000,720.00,NULL,NULL,NULL,0.00,0.00,0,'660016'),
(48,12,6.0000,60.00,NULL,NULL,NULL,0.00,0.00,0,'660019'),
(49,12,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660027'),
(50,12,6.0000,15.00,NULL,NULL,NULL,0.00,0.00,0,'660029'),
(51,12,4.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(52,12,2.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660030'),
(53,12,1.0000,160.00,NULL,NULL,NULL,0.00,0.00,0,'660031'),
(54,13,11.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(55,14,2.0000,400.00,NULL,NULL,NULL,0.00,0.00,0,'660004'),
(56,14,1.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660006'),
(57,14,1.0000,160.00,NULL,NULL,NULL,0.00,0.00,0,'660031'),
(58,14,2.0000,10.00,NULL,NULL,NULL,0.00,0.00,0,'660034'),
(59,15,1.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660011'),
(60,15,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(61,15,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660022'),
(62,15,1.0000,580.00,NULL,NULL,NULL,0.00,0.00,0,'660026'),
(63,15,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660027'),
(64,15,41.0000,15.00,NULL,NULL,NULL,0.00,0.00,0,'660029'),
(65,15,2.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(66,15,13.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(67,15,3.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660023'),
(68,15,2.0000,180.00,NULL,NULL,NULL,0.00,0.00,0,'660036'),
(69,16,10.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(70,17,3.0000,70.00,NULL,NULL,NULL,0.00,0.00,0,'660001'),
(71,17,1.0000,150.00,NULL,NULL,NULL,0.00,0.00,0,'660009'),
(72,17,2.0000,60.00,NULL,NULL,NULL,0.00,0.00,0,'660019'),
(73,17,2.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(74,17,2.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660022'),
(75,17,1.0000,580.00,NULL,NULL,NULL,0.00,0.00,0,'660026'),
(76,18,5.0000,15.00,NULL,NULL,NULL,0.00,0.00,0,'660029'),
(77,18,1.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660030'),
(78,18,12.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660023'),
(79,19,2.0000,180.00,NULL,NULL,NULL,0.00,0.00,0,'660036'),
(80,19,4.0000,10.00,NULL,NULL,NULL,0.00,0.00,0,'660034'),
(81,20,1.0000,360.00,NULL,NULL,NULL,0.00,0.00,0,'660014'),
(82,21,1.0000,650.00,NULL,NULL,NULL,0.00,0.00,0,'660020'),
(83,22,-1.0000,360.00,NULL,NULL,NULL,0.00,0.00,0,'660014'),
(84,23,-1.0000,650.00,NULL,NULL,NULL,0.00,0.00,0,'660020'),
(85,24,1.0000,70.00,NULL,NULL,NULL,0.00,0.00,0,'660001'),
(86,24,4.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660033'),
(87,24,1.0000,400.00,NULL,NULL,NULL,0.00,0.00,0,'660004'),
(88,24,5.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660011'),
(89,25,1.0000,650.00,NULL,NULL,NULL,0.00,0.00,0,'660020'),
(90,25,6.0000,60.00,NULL,NULL,NULL,0.00,0.00,0,'660019'),
(91,25,3.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(92,25,3.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660022'),
(93,25,1.0000,520.00,NULL,NULL,NULL,0.00,0.00,0,'660025'),
(94,25,1.0000,580.00,NULL,NULL,NULL,0.00,0.00,0,'660026'),
(95,25,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660027'),
(96,25,4.0000,15.00,NULL,NULL,NULL,0.00,0.00,0,'660029'),
(97,26,17.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(98,26,10.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660099'),
(99,26,1.0000,180.00,NULL,NULL,NULL,0.00,0.00,0,'660036'),
(100,26,1.0000,10.00,NULL,NULL,NULL,0.00,0.00,0,'660034'),
(101,26,3.0000,30.00,NULL,NULL,NULL,0.00,0.00,0,'660035'),
(102,27,9.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660023'),
(103,27,8.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660037'),
(104,28,1.0000,70.00,NULL,NULL,NULL,0.00,0.00,0,'660001'),
(105,28,2.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660033'),
(106,28,4.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660002'),
(107,28,1.0000,400.00,NULL,NULL,NULL,0.00,0.00,0,'660004'),
(108,28,4.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660011'),
(109,29,1.0000,2900.00,NULL,NULL,NULL,0.00,0.00,0,'660098'),
(110,29,1.0000,1500.00,NULL,NULL,NULL,0.00,0.00,0,'660043'),
(111,30,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660027'),
(112,31,2.0000,60.00,NULL,NULL,NULL,0.00,0.00,0,'660019'),
(113,31,2.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(114,31,3.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660022'),
(115,31,1.0000,520.00,NULL,NULL,NULL,0.00,0.00,0,'660025'),
(116,31,41.0000,10.00,NULL,NULL,NULL,0.00,0.00,0,'660034'),
(117,31,10.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(118,31,3.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660099'),
(119,32,1.0000,500.00,NULL,NULL,NULL,0.00,0.00,0,'660015'),
(120,32,7.0000,230.00,NULL,NULL,NULL,0.00,0.00,0,'660032'),
(121,32,1.0000,180.00,NULL,NULL,NULL,0.00,0.00,0,'660036'),
(122,32,10.0000,30.00,NULL,NULL,NULL,0.00,0.00,0,'660035'),
(123,32,2.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(124,32,3.0000,120.00,NULL,NULL,NULL,0.00,0.00,0,'756058841478'),
(125,33,1.0000,400.00,NULL,NULL,NULL,0.00,0.00,0,'660004'),
(126,33,16.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660037'),
(127,33,1.0000,200.00,NULL,NULL,NULL,0.00,0.00,0,'660097'),
(128,34,1.0000,15.00,NULL,NULL,NULL,0.00,0.00,0,'660029'),
(129,35,1.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660002'),
(130,35,6.0000,60.00,NULL,NULL,NULL,0.00,0.00,0,'660019'),
(131,35,2.0000,500.00,NULL,NULL,NULL,0.00,0.00,0,'660015'),
(132,35,3.0000,200.00,NULL,NULL,NULL,0.00,0.00,0,'660097'),
(133,35,2.0000,150.00,NULL,NULL,NULL,0.00,0.00,0,'660009'),
(134,35,5.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(135,36,4.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660095'),
(136,37,2.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660033'),
(137,37,1.0000,70.00,NULL,NULL,NULL,0.00,0.00,0,'660001'),
(138,37,2.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(139,37,10.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660022'),
(140,37,1.0000,580.00,NULL,NULL,NULL,0.00,0.00,0,'660026'),
(141,37,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660027'),
(142,37,5.0000,10.00,NULL,NULL,NULL,0.00,0.00,0,'660034'),
(143,37,2.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660099'),
(144,37,1.0000,360.00,NULL,NULL,NULL,0.00,0.00,0,'660014'),
(145,37,7.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(146,37,11.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660037'),
(147,37,11.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'990091'),
(148,37,7.0000,180.00,NULL,NULL,NULL,0.00,0.00,0,'660036'),
(149,37,1.0000,30.00,NULL,NULL,NULL,0.00,0.00,0,'660035'),
(150,37,2.0000,120.00,NULL,NULL,NULL,0.00,0.00,0,'756058841478'),
(151,38,2.0000,160.00,NULL,NULL,NULL,0.00,0.00,0,'660031'),
(152,39,2.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660011'),
(153,39,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(154,39,4.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'990091'),
(155,39,3.0000,180.00,NULL,NULL,NULL,0.00,0.00,0,'660036'),
(156,39,2.0000,520.00,NULL,NULL,NULL,0.00,0.00,0,'660025'),
(157,40,4.0000,60.00,NULL,NULL,NULL,0.00,0.00,0,'660019'),
(158,40,2.0000,580.00,NULL,NULL,NULL,0.00,0.00,0,'660026'),
(159,40,6.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660027'),
(160,40,4.0000,10.00,NULL,NULL,NULL,0.00,0.00,0,'660034'),
(161,40,1.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660099'),
(162,40,1.0000,500.00,NULL,NULL,NULL,0.00,0.00,0,'660015'),
(163,40,1.0000,360.00,NULL,NULL,NULL,0.00,0.00,0,'660014'),
(164,40,6.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(165,40,2.0000,120.00,NULL,NULL,NULL,0.00,0.00,0,'756058841478'),
(166,40,2.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660094'),
(167,40,7.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(168,40,1.0000,1500.00,NULL,NULL,NULL,0.00,0.00,0,'660043'),
(169,40,1.0000,160.00,NULL,NULL,NULL,0.00,0.00,0,'660010'),
(170,41,3.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660033'),
(171,41,1.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660011'),
(172,41,2.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660037'),
(173,41,1.0000,520.00,NULL,NULL,NULL,0.00,0.00,0,'660025'),
(174,41,6.0000,15.00,NULL,NULL,NULL,0.00,0.00,0,'660029'),
(175,41,29.0000,10.00,NULL,NULL,NULL,0.00,0.00,0,'660034'),
(176,41,3.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(177,41,1.0000,280.00,NULL,NULL,NULL,0.00,0.00,0,'660093'),
(178,41,10.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(179,41,1.0000,220.00,NULL,NULL,NULL,0.00,0.00,0,'660096'),
(180,41,1.0000,1500.00,NULL,NULL,NULL,0.00,0.00,0,'660043'),
(181,41,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(182,42,1.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660033'),
(183,42,3.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660021'),
(184,42,1.0000,520.00,NULL,NULL,NULL,0.00,0.00,0,'660025'),
(185,42,1.0000,240.00,NULL,NULL,NULL,0.00,0.00,0,'660027'),
(186,42,8.0000,10.00,NULL,NULL,NULL,0.00,0.00,0,'660034'),
(187,42,1.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(188,42,2.0000,160.00,NULL,NULL,NULL,0.00,0.00,0,'660031'),
(189,42,4.0000,30.00,NULL,NULL,NULL,0.00,0.00,0,'660035'),
(190,43,9.0000,100.00,NULL,NULL,NULL,0.00,0.00,0,'660012'),
(191,43,1.0000,720.00,NULL,NULL,NULL,0.00,0.00,0,'660016'),
(192,43,1.0000,40.00,NULL,NULL,NULL,0.00,0.00,0,'660007'),
(193,44,1.0000,140.00,NULL,NULL,NULL,0.00,0.00,0,'660017'),
(219,70,2.0000,40.00,'Chupa chus 40','660033',NULL,0.00,0.00,0,'660033'),
(220,71,2.0000,280.00,'Energizante','660011',NULL,0.00,0.00,0,'660011'),
(221,72,2.0000,40.00,'Chupa chus 40','660033',NULL,0.00,0.00,0,'660033'),
(222,72,2.0000,280.00,'Energizante','660011',NULL,0.00,0.00,0,'660011'),
(223,72,2.0000,240.00,'Refresco cola lata 300ml','660021',NULL,0.00,0.00,0,'660021'),
(224,72,10.0000,240.00,'Cerveza La Fria','660037',NULL,0.00,0.00,0,'660037'),
(225,73,8.0000,240.00,'Cerveza Presidente','990091',NULL,0.00,0.00,0,'990091'),
(230,78,-2.0000,40.00,'DEVOLUCIÓN: Chupa chus 40',NULL,NULL,0.00,0.00,0,'660033'),
(231,79,-2.0000,280.00,'DEVOLUCIÓN: Energizante',NULL,NULL,0.00,0.00,0,'660011'),
(238,86,9.0000,240.00,'Cerveza Shekels','660023',NULL,0.00,0.00,0,'660023'),
(239,86,8.0000,180.00,'jugo cajita 200ml','660036',NULL,0.00,0.00,0,'660036'),
(240,87,2.0000,520.00,'Leche condensada','660025',NULL,0.00,0.00,0,'660025'),
(241,88,1.0000,650.00,'Arroz 1kg pqt','660020',NULL,0.00,0.00,0,'660020'),
(242,88,1.0000,240.00,'Marranetas','660027',NULL,0.00,0.00,0,'660027'),
(243,89,20.0000,10.00,'Caramelos 10','660034',NULL,0.00,0.00,0,'660034'),
(244,89,2.0000,500.00,'Hamgurgesa 5u pqt','660015',NULL,0.00,0.00,0,'660015'),
(245,89,2.0000,360.00,'Albondigas pqt','660014',NULL,0.00,0.00,0,'660014'),
(246,89,1.0000,150.00,'Galletas Rellenitas','660009',NULL,0.00,0.00,0,'660009'),
(247,89,1.0000,280.00,'Pqt de 4 panques','660093',NULL,0.00,0.00,0,'660093'),
(248,89,2.0000,160.00,'Galletas Blackout azul','660010',NULL,0.00,0.00,0,'660010'),
(249,90,17.0000,100.00,'Tartaleta','660012',NULL,0.00,0.00,0,'660012'),
(250,91,1.0000,1300.00,'lomo en bandeja 1lb','660090',NULL,0.00,0.00,0,'660090'),
(251,92,2.0000,30.00,'Caldo de pollo sobrecito','660088',NULL,0.00,0.00,0,'660088'),
(252,93,2.0000,40.00,'pastillita pollo con tomate','660089',NULL,0.00,0.00,0,'660089'),
(253,94,3.0000,200.00,'Galletas Saltitacos','660087',NULL,0.00,0.00,0,'660087'),
(254,95,3.0000,80.00,'ojitos gummy gum','660100',NULL,0.00,0.00,0,'660100'),
(255,96,2.0000,40.00,'Refresco en polvo','660007',NULL,0.00,0.00,0,'660007'),
(256,97,1.0000,200.00,'Jabon de carbon','660102',NULL,0.00,0.00,0,'660102'),
(257,97,1.0000,150.00,'jabon de bano','660103',NULL,0.00,0.00,0,'660103'),
(258,98,1.0000,280.00,'Espagetis 500g','660006',NULL,0.00,0.00,0,'660006'),
(259,99,2.0000,70.00,'Chupa chus grandes','660001',NULL,0.00,0.00,0,'660001'),
(260,99,1.0000,40.00,'Chupa chus 40','660033',NULL,0.00,0.00,0,'660033'),
(261,100,1.0000,280.00,'Energizante','660011',NULL,0.00,0.00,0,'660011'),
(262,100,2.0000,240.00,'Refresco cola lata 300ml','660021',NULL,0.00,0.00,0,'660021'),
(263,100,1.0000,240.00,'Cerveza La Fria','660037',NULL,0.00,0.00,0,'660037'),
(264,101,1.0000,240.00,'Cerveza Presidente','990091',NULL,0.00,0.00,0,'990091'),
(265,102,1.0000,240.00,'Cerveza Shekels','660023',NULL,0.00,0.00,0,'660023'),
(266,102,6.0000,180.00,'jugo cajita 200ml','660036',NULL,0.00,0.00,0,'660036'),
(267,102,2.0000,520.00,'Leche condensada','660025',NULL,0.00,0.00,0,'660025'),
(268,102,4.0000,60.00,'Pinguinos gelatina','660019',NULL,0.00,0.00,0,'660019'),
(269,103,4.0000,15.00,'Caramelos de 15','660029',NULL,0.00,0.00,0,'660029'),
(270,103,5.0000,10.00,'Caramelos 10','660034',NULL,0.00,0.00,0,'660034'),
(271,103,3.0000,500.00,'Hamgurgesa 5u pqt','660015',NULL,0.00,0.00,0,'660015'),
(272,103,1.0000,360.00,'Albondigas pqt','660014',NULL,0.00,0.00,0,'660014'),
(273,104,1.0000,30.00,'chicle 30','660035',NULL,0.00,0.00,0,'660035'),
(274,105,12.0000,100.00,'Tartaleta','660012',NULL,0.00,0.00,0,'660012'),
(275,106,1.0000,30.00,'Caldo de pollo sobrecito','660088',NULL,0.00,0.00,0,'660088'),
(276,107,1.0000,200.00,'Galletas Saltitacos','660087',NULL,0.00,0.00,0,'660087'),
(277,107,1.0000,150.00,'jabon de bano','660103',NULL,0.00,0.00,0,'660103'),
(278,107,11.0000,40.00,'Refresco en polvo','660007',NULL,0.00,0.00,0,'660007'),
(279,108,8.0000,130.00,'Donas Grandes','440001',NULL,0.00,0.00,0,'440001'),
(280,109,6.0000,650.00,'Arroz 1kg pqt','660020',NULL,0.00,0.00,0,'660020'),
(281,109,1.0000,680.00,'Azucar 1kg pqt','660018',NULL,0.00,0.00,0,'660018'),
(282,109,5.0000,60.00,'Pinguinos gelatina','660019',NULL,0.00,0.00,0,'660019'),
(283,109,1.0000,150.00,'Galletas Rellenitas','660009',NULL,0.00,0.00,0,'660009'),
(284,109,2.0000,10.00,'Caramelos 10','660034',NULL,0.00,0.00,0,'660034'),
(285,109,3.0000,80.00,'ojitos gummy gum','660100',NULL,0.00,0.00,0,'660100'),
(286,109,1.0000,1300.00,'lomo en bandeja 1lb','660090',NULL,0.00,0.00,0,'660090'),
(287,110,4.0000,80.00,'Cabezote','660013',NULL,0.00,0.00,0,'660013'),
(288,110,10.0000,100.00,'Tartaleta','660012',NULL,0.00,0.00,0,'660012'),
(289,110,1.0000,150.00,'Croquetas pollo pqt','660017',NULL,0.00,0.00,0,'660017'),
(290,111,16.0000,15.00,'Caramelos de 15','660029',NULL,0.00,0.00,0,'660029'),
(291,111,1.0000,440.00,'Pure de tomate 400g lata','660003',NULL,0.00,0.00,0,'660003'),
(292,112,1.0000,70.00,'Chupa chus grandes','660001',NULL,0.00,0.00,0,'660001'),
(293,112,5.0000,40.00,'Chupa chus 40','660033',NULL,0.00,0.00,0,'660033'),
(294,113,1.0000,180.00,'Galleta Biskiato minion','660008',NULL,0.00,0.00,0,'660008'),
(295,113,2.0000,280.00,'Energizante','660011',NULL,0.00,0.00,0,'660011'),
(296,114,4.0000,240.00,'Cerveza Shekels','660023',NULL,0.00,0.00,0,'660023'),
(297,115,1.0000,60.00,'Pinguinos gelatina','660019',NULL,0.00,0.00,0,'660019'),
(298,116,7.0000,240.00,'Marranetas','660027',NULL,0.00,0.00,0,'660027'),
(299,117,7.0000,10.00,'Caramelos 10','660034',NULL,0.00,0.00,0,'660034'),
(300,118,3.0000,200.00,'Galletas Saltitacos','660087',NULL,0.00,0.00,0,'660087'),
(301,119,1.0000,160.00,'Donas Donuts','660104',NULL,0.00,0.00,0,'660104'),
(302,119,3.0000,40.00,'Refresco en polvo','660007',NULL,0.00,0.00,0,'660007'),
(303,119,4.0000,130.00,'Donas Grandes','440001',NULL,0.00,0.00,0,'440001'),
(304,119,1.0000,360.00,'Albondigas pqt','660014',NULL,0.00,0.00,0,'660014'),
(305,119,5.0000,150.00,'Croquetas pollo pqt','660017',NULL,0.00,0.00,0,'660017'),
(306,119,7.0000,100.00,'Tartaleta','660012',NULL,0.00,0.00,0,'660012'),
(307,120,-5.0000,150.00,'DEVOLUCIÓN: Croquetas pollo pqt',NULL,NULL,0.00,0.00,0,'660017'),
(308,121,4.0000,150.00,'Croquetas pollo pqt','660017',NULL,0.00,0.00,0,'660017'),
(309,122,2.0000,70.00,'Chupa chus grandes','660001',NULL,0.00,0.00,0,'660001'),
(310,122,4.0000,40.00,'Chupa chus 40','660033',NULL,0.00,0.00,0,'660033'),
(311,123,3.0000,580.00,'Fanguito','660026',NULL,0.00,0.00,0,'660026'),
(312,123,4.0000,230.00,'Cerveza Esple','660032',NULL,0.00,0.00,0,'660032'),
(313,123,1.0000,520.00,'Leche condensada','660025',NULL,0.00,0.00,0,'660025'),
(314,123,10.0000,80.00,'Cabezote','660013',NULL,0.00,0.00,0,'660013'),
(315,123,1.0000,220.00,'pqt torticas 6u','660096',NULL,0.00,0.00,0,'660096'),
(316,123,1.0000,360.00,'Albondigas pqt','660014',NULL,0.00,0.00,0,'660014'),
(317,124,1.0000,160.00,'Croquetas Buffet','660031',NULL,0.00,0.00,0,'660031'),
(318,125,1.0000,280.00,'Espagetis 500g','660006',NULL,0.00,0.00,0,'660006'),
(319,126,2.0000,180.00,'Galletas Porleo','660005',NULL,0.00,0.00,0,'660005'),
(320,127,2.0000,280.00,'Energizante','660011',NULL,0.00,0.00,0,'660011'),
(321,128,9.0000,240.00,'Cerveza Shekels','660023',NULL,0.00,0.00,0,'660023'),
(322,128,5.0000,230.00,'Cerveza Esple','660032',NULL,0.00,0.00,0,'660032'),
(323,128,24.0000,240.00,'Cerveza La Fria','660037',NULL,0.00,0.00,0,'660037'),
(324,129,1.0000,650.00,'Arroz 1kg pqt','660020',NULL,0.00,0.00,0,'660020'),
(325,130,1.0000,680.00,'Azucar 1kg pqt','660018',NULL,0.00,0.00,0,'660018'),
(326,130,1.0000,60.00,'Pinguinos gelatina','660019',NULL,0.00,0.00,0,'660019'),
(327,130,6.0000,240.00,'Marranetas','660027',NULL,0.00,0.00,0,'660027'),
(328,130,29.0000,10.00,'Caramelos 10','660034',NULL,0.00,0.00,0,'660034'),
(329,131,1.0000,220.00,'pqt torticas 6u','660096',NULL,0.00,0.00,0,'660096'),
(330,131,14.0000,40.00,'Refresco en polvo','660007',NULL,0.00,0.00,0,'660007'),
(331,131,2.0000,360.00,'Albondigas pqt','660014',NULL,0.00,0.00,0,'660014'),
(332,131,7.0000,150.00,'Croquetas pollo pqt','660017',NULL,0.00,0.00,0,'660017'),
(333,132,1.0000,160.00,'Galletas Blackout azul','660010',NULL,0.00,0.00,0,'660010'),
(334,133,4.0000,70.00,'Chupa chus grandes','660001',NULL,0.00,0.00,0,'660001'),
(335,133,4.0000,40.00,'Chupa chus 40','660033',NULL,0.00,0.00,0,'660033'),
(336,133,1.0000,180.00,'Galletas Porleo','660005',NULL,0.00,0.00,0,'660005'),
(337,133,2.0000,80.00,'Cabezote','660013',NULL,0.00,0.00,0,'660013'),
(338,133,23.0000,10.00,'Caramelos 10','660034',NULL,0.00,0.00,0,'660034'),
(339,133,14.0000,15.00,'Caramelos de 15','660029',NULL,0.00,0.00,0,'660029'),
(340,134,2.0000,30.00,'chicle 30','660035',NULL,0.00,0.00,0,'660035'),
(341,134,1.0000,220.00,'pqt torticas 6u','660096',NULL,0.00,0.00,0,'660096'),
(342,134,2.0000,730.00,'Higado de pollo pqt','660016',NULL,0.00,0.00,0,'660016'),
(343,134,1.0000,150.00,'jabon de bano','660103',NULL,0.00,0.00,0,'660103'),
(344,135,1.0000,370.00,'Albondigas pqt','660014',NULL,0.00,0.00,0,'660014'),
(345,135,1.0000,480.00,'Hamburguesas pollo','881108',NULL,0.00,0.00,0,'881108'),
(346,136,1.0000,160.00,'Galletas Blackout azul','660010',NULL,0.00,0.00,0,'660010'),
(347,136,23.0000,240.00,'Cerveza Shekels','660023',NULL,0.00,0.00,0,'660023'),
(348,137,4.0000,240.00,'Refresco cola lata 300ml','660021',NULL,0.00,0.00,0,'660021'),
(349,137,1.0000,480.00,'Hamburguesas pollo','881108',NULL,0.00,0.00,0,'881108'),
(350,138,2.0000,150.00,'','756058841446','',0.00,0.00,0,'756058841446'),
(351,139,1.0000,150.00,'COCA COLA GRANDE','756058841446','',0.00,0.00,0,'756058841446'),
(352,140,1.0000,150.00,'COCA COLA GRANDE','756058841446','',0.00,0.00,0,'756058841446');
/*!40000 ALTER TABLE `ventas_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ventas_pagos`
--

DROP TABLE IF EXISTS `ventas_pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ventas_pagos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `id_venta_cabecera` int(10) unsigned NOT NULL,
  `metodo_pago` varchar(50) NOT NULL,
  `monto` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_venta` (`id_venta_cabecera`)
) ENGINE=InnoDB AUTO_INCREMENT=130 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ventas_pagos`
--

LOCK TABLES `ventas_pagos` WRITE;
/*!40000 ALTER TABLE `ventas_pagos` DISABLE KEYS */;
INSERT INTO `ventas_pagos` VALUES
(1,1,'Transferencia',2280.00),
(2,2,'Efectivo',5010.00),
(3,3,'Efectivo',3160.00),
(4,4,'Transferencia',70.00),
(5,5,'Efectivo',4475.00),
(6,6,'Devolución',-600.00),
(7,7,'Efectivo',840.00),
(8,8,'Efectivo',800.00),
(9,9,'Transferencia',4090.00),
(10,10,'Efectivo',10225.00),
(11,11,'Devolución',-80.00),
(12,12,'Efectivo',4210.00),
(13,13,'Efectivo',1100.00),
(14,14,'Transferencia',1260.00),
(15,15,'Efectivo',4855.00),
(16,16,'Transferencia',1000.00),
(17,17,'Efectivo',2020.00),
(18,18,'Efectivo',3235.00),
(19,19,'Efectivo',400.00),
(20,20,'Efectivo',360.00),
(21,21,'Transferencia',650.00),
(22,22,'Devolución',-360.00),
(23,23,'Devolución',-650.00),
(24,24,'Transferencia',2030.00),
(25,25,'Efectivo',3850.00),
(26,26,'Efectivo',2980.00),
(27,27,'Efectivo',4080.00),
(28,28,'Transferencia',1830.00),
(29,29,'Transferencia',4400.00),
(30,30,'Transferencia',240.00),
(31,31,'Efectivo',3550.00),
(32,32,'Efectivo',3230.00),
(33,33,'Efectivo',4440.00),
(34,34,'Efectivo',15.00),
(35,35,'Transferencia',2800.00),
(36,36,'Transferencia',400.00),
(37,37,'Efectivo',12250.00),
(38,38,'Efectivo',320.00),
(39,39,'Efectivo',3340.00),
(40,40,'Efectivo',7840.00),
(41,41,'Efectivo',5440.00),
(42,42,'Efectivo',2180.00),
(43,43,'Efectivo',1660.00),
(44,44,'Efectivo',140.00),
(45,70,'Efectivo',80.00),
(46,71,'Efectivo',560.00),
(47,72,'Efectivo',3520.00),
(48,73,'Efectivo',1920.00),
(49,78,'Devolución',-80.00),
(50,79,'Devolución',-560.00),
(51,86,'Efectivo',3600.00),
(52,87,'Efectivo',1040.00),
(53,88,'Efectivo',890.00),
(54,89,'Efectivo',2670.00),
(55,90,'Efectivo',1700.00),
(56,91,'Efectivo',1300.00),
(57,92,'Efectivo',60.00),
(58,93,'Transferencia',80.00),
(59,94,'Efectivo',600.00),
(60,95,'Efectivo',240.00),
(61,96,'Transferencia',80.00),
(62,97,'Efectivo',350.00),
(63,98,'Efectivo',280.00),
(64,99,'Efectivo',180.00),
(65,100,'Transferencia',1000.00),
(66,101,'Efectivo',240.00),
(67,102,'Efectivo',2600.00),
(68,103,'Efectivo',1970.00),
(69,104,'Efectivo',30.00),
(70,105,'Efectivo',1200.00),
(71,106,'Transferencia',30.00),
(72,107,'Efectivo',790.00),
(73,108,'Efectivo',1040.00),
(74,109,'Transferencia',6590.00),
(75,110,'Transferencia',1470.00),
(76,111,'Transferencia',680.00),
(77,112,'Efectivo',270.00),
(78,113,'Efectivo',740.00),
(79,114,'Efectivo',960.00),
(80,115,'Efectivo',60.00),
(81,116,'Efectivo',1680.00),
(82,117,'Efectivo',70.00),
(83,118,'Efectivo',600.00),
(84,119,'Efectivo',2610.00),
(85,120,'Devolución',-750.00),
(86,121,'Efectivo',600.00),
(87,122,'Efectivo',300.00),
(88,123,'Transferencia',4560.00),
(89,124,'Transferencia',160.00),
(90,125,'Transferencia',280.00),
(91,126,'Efectivo',360.00),
(92,127,'Efectivo',560.00),
(93,128,'Efectivo',9070.00),
(94,129,'Efectivo',650.00),
(95,130,'Efectivo',2470.00),
(96,131,'Efectivo',2550.00),
(97,132,'Efectivo',160.00),
(98,133,'Efectivo',1220.00),
(99,134,'Efectivo',1890.00),
(100,135,'Efectivo',850.00),
(101,136,'Efectivo',5680.00),
(102,137,'Efectivo',1440.00),
(103,138,'Efectivo',300.00),
(104,139,'Transferencia',150.00),
(128,140,'Efectivo',100.00),
(129,140,'Transferencia',50.00);
/*!40000 ALTER TABLE `ventas_pagos` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-12 15:42:46
