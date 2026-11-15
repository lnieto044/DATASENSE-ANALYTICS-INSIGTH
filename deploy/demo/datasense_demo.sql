-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: datasense_demo
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
-- Table structure for table `asientos_contables`
--

DROP TABLE IF EXISTS `asientos_contables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asientos_contables` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `cuenta_id` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `factura_id` int(11) DEFAULT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `debito` decimal(15,2) DEFAULT 0.00,
  `credito` decimal(15,2) DEFAULT 0.00,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_asiento_cuenta` (`cuenta_id`),
  KEY `fk_asiento_factura` (`factura_id`),
  CONSTRAINT `fk_asiento_cuenta` FOREIGN KEY (`cuenta_id`) REFERENCES `puc_cuentas` (`id`),
  CONSTRAINT `fk_asiento_factura` FOREIGN KEY (`factura_id`) REFERENCES `facturas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asientos_contables`
--

LOCK TABLES `asientos_contables` WRITE;
/*!40000 ALTER TABLE `asientos_contables` DISABLE KEYS */;
INSERT INTO `asientos_contables` VALUES (1,'2026-04-02',4,16,1,'Venta de servicios FAC-FAC-2026-001',0.00,5355000.00,'2026-04-02 00:54:46'),(2,'2026-04-02',5,16,1,'Comisión MP FAC-FAC-2026-001',176979.50,0.00,'2026-04-02 00:54:46'),(3,'2026-04-02',2,16,1,'Recaudo neto FAC-FAC-2026-001',5178020.50,0.00,'2026-04-02 00:54:46'),(4,'2026-04-02',4,16,1,'Venta de servicios FAC-FAC-2026-001',0.00,5355000.00,'2026-04-02 01:29:18'),(5,'2026-04-02',5,16,1,'Comisión MP FAC-FAC-2026-001',176979.50,0.00,'2026-04-02 01:29:18'),(6,'2026-04-02',2,16,1,'Recaudo neto FAC-FAC-2026-001',5178020.50,0.00,'2026-04-02 01:29:18'),(7,'2026-04-02',4,16,1,'Venta de servicios FAC-FAC-2026-001',0.00,5355000.00,'2026-04-02 02:10:59'),(8,'2026-04-02',5,16,1,'Comisión MP FAC-FAC-2026-001',176979.50,0.00,'2026-04-02 02:10:59'),(9,'2026-04-02',2,16,1,'Recaudo neto FAC-FAC-2026-001',5178020.50,0.00,'2026-04-02 02:10:59'),(10,'2026-04-02',4,16,1,'Venta de servicios FAC-FAC-2026-001',0.00,5355000.00,'2026-04-02 05:36:19'),(11,'2026-04-02',5,16,1,'Comisión MP FAC-FAC-2026-001',176979.50,0.00,'2026-04-02 05:36:19'),(12,'2026-04-02',2,16,1,'Recaudo neto FAC-FAC-2026-001',5178020.50,0.00,'2026-04-02 05:36:19'),(13,'2026-04-02',4,16,1,'Venta de servicios FAC-FAC-2026-001',0.00,5355000.00,'2026-04-02 05:58:37'),(14,'2026-04-02',5,16,1,'Comisión MP FAC-FAC-2026-001',176979.50,0.00,'2026-04-02 05:58:37'),(15,'2026-04-02',2,16,1,'Recaudo neto FAC-FAC-2026-001',5178020.50,0.00,'2026-04-02 05:58:37'),(16,'2026-04-13',4,12,2,'Venta de servicios FAC-FAC-2026-002',0.00,952000.00,'2026-04-13 02:01:45'),(17,'2026-04-13',5,12,2,'Comisión MP FAC-FAC-2026-002',32120.80,0.00,'2026-04-13 02:01:45'),(18,'2026-04-13',2,12,2,'Recaudo neto FAC-FAC-2026-002',919879.20,0.00,'2026-04-13 02:01:45'),(19,'2026-04-13',4,12,2,'Venta de servicios FAC-FAC-2026-002',0.00,952000.00,'2026-04-13 21:41:13'),(20,'2026-04-13',5,12,2,'Comisión MP FAC-FAC-2026-002',32120.80,0.00,'2026-04-13 21:41:13'),(21,'2026-04-13',2,12,2,'Recaudo neto FAC-FAC-2026-002',919879.20,0.00,'2026-04-13 21:41:13'),(22,'2026-09-20',4,16,1,'Venta de servicios FAC-FAC-2026-001',0.00,5355000.00,'2026-09-20 16:05:02'),(23,'2026-09-20',5,16,1,'Comisión MP FAC-FAC-2026-001',176979.50,0.00,'2026-09-20 16:05:02'),(24,'2026-09-20',2,16,1,'Recaudo neto FAC-FAC-2026-001',5178020.50,0.00,'2026-09-20 16:05:02');
/*!40000 ALTER TABLE `asientos_contables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `catalogo_servicios`
--

DROP TABLE IF EXISTS `catalogo_servicios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `catalogo_servicios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo_solicitud` varchar(10) NOT NULL COMMENT 'INC o RF',
  `servicio` varchar(100) NOT NULL COMMENT 'Categoría Principal',
  `subcategoria` varchar(150) NOT NULL COMMENT 'Lo que lee el cliente',
  `grupo_resolutor` varchar(100) NOT NULL COMMENT 'Quién lo atiende',
  `estado` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `catalogo_servicios`
--

LOCK TABLES `catalogo_servicios` WRITE;
/*!40000 ALTER TABLE `catalogo_servicios` DISABLE KEYS */;
INSERT INTO `catalogo_servicios` VALUES (1,'INC','Acceso y Seguridad','No puedo acceder a la plataforma web (Error de Login/Clave)','Soporte BI',1),(2,'INC','Disponibilidad Web','La plataforma está totalmente caída o arroja error 500/404','Infraestructura Cloud',1),(3,'INC','Rendimiento','Un tablero de control o reporte está extremadamente lento','Ingeniería de Datos',1),(4,'INC','Visualización (Dashboards)','Un gráfico está roto, en blanco o arroja error visual','Soporte BI',1),(5,'INC','Calidad de Datos','Los números del reporte no cuadran con mi fuente original','Científico de Datos',1),(6,'INC','Actualización (ETL)','Los datos están desactualizados (El proceso automático falló)','Ingeniería de Datos',1),(7,'INC','Modelos Predictivos','El modelo de Machine Learning arroja resultados anómalos o falló','Científico de Datos',1),(8,'INC','Alertas y Notificaciones','No me llegó el correo automático de alertas o KPIs','Soporte BI',1),(9,'INC','Exportación e Informes','Falla al intentar descargar un reporte en PDF o Excel','Soporte BI',1),(10,'INC','Integración (APIs)','Error de conexión con una fuente externa (CRM, ERP, Google, etc.)','Ingeniería de Datos',1),(11,'RF','Acceso y Seguridad','Solicitar creación de un nuevo usuario en DataSense','Administración',1),(12,'RF','Acceso y Seguridad','Solicitar permisos para ver un tablero o módulo específico','Administración',1),(13,'RF','Acceso y Seguridad','Bloquear acceso de un usuario por retiro de la empresa','Administración',1),(14,'RF','Visualización (Dashboards)','Desarrollo de un nuevo Dashboard o Tablero de Control','Soporte BI',1),(15,'RF','Visualización (Dashboards)','Modificar un tablero actual (Añadir KPIs, cambiar filtros)','Soporte BI',1),(16,'RF','Extracción de Datos','Solicitud de base de datos cruda (Query/Exportación a medida)','Científico de Datos',1),(17,'RF','Modelos Predictivos','Solicitud de nuevo modelo analítico (Forecasting, Churn, etc.)','Científico de Datos',1),(18,'RF','Integración de Datos','Conectar una nueva fuente de datos a la plataforma','Ingeniería de Datos',1),(19,'RF','Auditoría y Consultoría','Solicitar análisis profundo o limpieza de una base de datos','Científico de Datos',1),(20,'RF','Consultoría y Capacitación','Sesión de entrenamiento para interpretar métricas o usar el portal','Soporte BI',1),(21,'INC','Calidad de Datos','Registros duplicados, nulos o inconsistentes en la base de datos maestra.','Gobierno y calidad de datos',1),(22,'INC','Cumplimiento y Seguridad de Datos','Datos sensibles (PII) expuestos sin enmascarar en un tablero o reporte.','Gobierno y calidad de datos',1),(23,'INC','Estandarización de Datos','Valores erróneos o fuera de formato estándar (ej. fechas, monedas).','Gobierno y calidad de datos',1),(24,'RF','Diccionario de Datos','Solicitar creación/actualización de la definición oficial de un KPI.','Gobierno y calidad de datos',1),(25,'RF','Gobierno de Datos','Solicitar nueva política de enmascaramiento o borrado seguro de datos.','Gobierno y calidad de datos',1),(26,'RF','Calidad de Datos','Implementar nueva regla de validación automática para limpiar datos.','Gobierno y calidad de datos',1),(27,'RF','Estandarización de Datos','Solicitar la creación de un nuevo catálogo maestro (Master Data).','Gobierno y calidad de datos',1),(28,'RF','Desarrollo de Software','Nuevo desarrollo backend (APIs, bases de datos, integraciones)','Desarrollo Backend',1),(29,'RF','Desarrollo de Software','Nuevo desarrollo frontend (interfaces web y móviles)','Desarrollo Frontend',1),(30,'RF','Desarrollo de Software','Mejora o ajuste a una aplicación existente','Desarrollo Backend',1),(31,'INC','Desarrollo de Software','Error en una API o servicio backend','Desarrollo Backend',1),(32,'INC','Desarrollo de Software','La aplicación web o móvil falla o se ve mal','Desarrollo Frontend',1);
/*!40000 ALTER TABLE `catalogo_servicios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comprobantes`
--

DROP TABLE IF EXISTS `comprobantes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comprobantes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(3) NOT NULL,
  `numero` varchar(20) NOT NULL,
  `fecha` date NOT NULL,
  `fecha_vencimiento` date DEFAULT NULL,
  `tercero_id` int(11) DEFAULT NULL,
  `tercero_nombre` varchar(150) NOT NULL,
  `tercero_documento` varchar(20) DEFAULT NULL,
  `concepto` varchar(255) NOT NULL,
  `metodo_pago` varchar(40) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `valor_total` decimal(14,2) NOT NULL,
  `factura_id` int(11) DEFAULT NULL,
  `pago_id` int(11) DEFAULT NULL,
  `origen_id` int(11) DEFAULT NULL,
  `estado` varchar(12) NOT NULL DEFAULT 'Pendiente',
  `origen` varchar(20) NOT NULL DEFAULT 'Manual',
  `clase_nota` varchar(40) DEFAULT NULL,
  `preparado_por` int(11) DEFAULT NULL,
  `revisado_por` int(11) DEFAULT NULL,
  `fecha_revision` datetime DEFAULT NULL,
  `aprobado_por` int(11) DEFAULT NULL,
  `fecha_aprobacion` datetime DEFAULT NULL,
  `anulado_por` int(11) DEFAULT NULL,
  `fecha_anulacion` datetime DEFAULT NULL,
  `motivo_anulacion` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero` (`numero`),
  KEY `idx_comprobantes_tipo_fecha` (`tipo`,`fecha`),
  KEY `idx_comprobantes_factura` (`factura_id`),
  KEY `idx_comprobantes_origen` (`origen_id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comprobantes`
--

LOCK TABLES `comprobantes` WRITE;
/*!40000 ALTER TABLE `comprobantes` DISABLE KEYS */;
INSERT INTO `comprobantes` VALUES (1,'CI','CI-2026-0001','2026-03-31',NULL,NULL,'Soluciones Tecnológicas Andinas S.A.S.',NULL,'Recaudo de la factura FAC-2026-005 - Modelo Predictivo Inventarios','Mercado Pago (PSE)','152700049180',12000.00,5,1,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(2,'CI','CI-2026-0002','2026-03-31',NULL,NULL,'Soluciones Tecnológicas Andinas S.A.S.',NULL,'Recaudo de la factura FAC-2026-004 - Auditoría de Datos Municipales','Mercado Pago Checkout','152735067568',10000.00,4,2,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(3,'CI','CI-2026-0003','2026-03-31',NULL,NULL,'Banco Cordillera',NULL,'Recaudo de la factura FAC-2026-023 - Integración de APIs Corporativas','Transferencia_Bancaria','FAC-2026-023',5712000.00,27,3,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(4,'CI','CI-2026-0004','2026-03-31',NULL,NULL,'Alimentos La Pradera S.A.S.',NULL,'Recaudo de la factura FAC-2026-022 - Business Intelligence','Transferencia_Bancaria','FAC-2026-022',238000.00,25,4,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(5,'CI','CI-2026-0005','2026-03-31',NULL,NULL,'Soluciones Tecnológicas Andinas S.A.S.',NULL,'Recaudo de la factura FAC-2026-015 - Análisis de Rotación PowerBI','Transferencia_Bancaria','FAC-2026-015',4165000.00,15,5,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(6,'CI','CI-2026-0006','2026-03-31',NULL,NULL,'Alimentos La Pradera S.A.S.',NULL,'Recaudo de la factura FAC-2026-018 - Creación de Dashboard','Transferencia_Bancaria','FAC-2026-018',3332000.00,18,6,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(7,'CI','CI-2026-0007','2026-03-31',NULL,NULL,'Soluciones Tecnológicas Andinas S.A.S.',NULL,'Recaudo de la factura FAC-2026-003 - Piloto Analítica Datos Públicos','Transferencia_Bancaria','FAC-2026-003',14875000.00,3,7,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(8,'CI','CI-2026-0008','2026-03-31',NULL,NULL,'Métricas y Datos S.A.S.',NULL,'Recaudo de la factura FAC-2026-001 - Dashboard Comercial','Transferencia_Bancaria','p-2026-001',5355000.00,1,8,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(9,'CI','CI-2026-0009','2026-03-31',NULL,NULL,'Soluciones Tecnológicas Andinas S.A.S.',NULL,'Recaudo de la factura FAC-2026-008 - Consultoría de Seguridad','Transferencia_Bancaria','2026-008',5950000.00,8,9,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(10,'CI','CI-2026-0010','2026-04-01',NULL,NULL,'Aseguradora Brisas del Valle',NULL,'Recaudo de la factura FAC-2026-012 - Licenciamiento Software Q1','Transferencia_Bancaria','FAC-2026-012',7735000.00,12,10,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(11,'CI','CI-2026-0011','2026-04-01',NULL,NULL,'Banco Cordillera',NULL,'Recaudo de la factura FAC-2026-010 - Implementación IA en Cajas','Transferencia_Bancaria','FAC-2026-010',14280000.00,10,11,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(12,'CI','CI-2026-0012','2026-04-01',NULL,NULL,'Petroandina Energía S.A.',NULL,'Recaudo de la factura FAC-2026-014 - Tableros de Control Directivo','Transferencia_Bancaria','FAC-2026-014',8568000.00,14,12,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(13,'CI','CI-2026-0013','2026-04-01',NULL,NULL,'Petroandina Energía S.A.',NULL,'Recaudo de la factura FAC-2026-019 - Extracción Datos Clínicos','Transferencia_Bancaria','FAC-2026-019',4998000.00,19,13,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(14,'CI','CI-2026-0014','2026-04-01',NULL,NULL,'Soluciones Tecnológicas Andinas S.A.S.',NULL,'Recaudo de la factura FAC-2026-002 - Soporte Mensual BI - Febrero','Transferencia_Bancaria','FAC-2026-002',952000.00,2,14,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(15,'CI','CI-2026-0015','2026-04-01',NULL,NULL,'Soluciones Tecnológicas Andinas S.A.S.',NULL,'Recaudo de la factura FAC-2026-007 - Auditoría Estratégica de Datos','Transferencia_Bancaria','FAC-2026-007',17850000.00,7,15,NULL,'Aprobado','Migración',NULL,NULL,NULL,NULL,NULL,'2026-09-20 11:05:02',NULL,NULL,NULL,'2026-09-20 16:05:02'),(16,'CE','CE-2026-0001','2026-04-01',NULL,1,'DataSense','1084664393','pago de hosting y dominio','Transferencia','Migración CE-000001',139320.00,NULL,NULL,NULL,'Aprobado','Migración',NULL,5,6,NULL,NULL,'2026-04-01 22:54:43',NULL,NULL,NULL,'2026-09-20 16:05:03');
/*!40000 ALTER TABLE `comprobantes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comprobantes_detalle`
--

DROP TABLE IF EXISTS `comprobantes_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comprobantes_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comprobante_id` int(11) NOT NULL,
  `codigo_puc` varchar(10) NOT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `debito` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credito` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_detalle_comprobante` (`comprobante_id`),
  KEY `idx_detalle_cuenta` (`codigo_puc`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comprobantes_detalle`
--

LOCK TABLES `comprobantes_detalle` WRITE;
/*!40000 ALTER TABLE `comprobantes_detalle` DISABLE KEYS */;
INSERT INTO `comprobantes_detalle` VALUES (1,1,'111005','Recaudo factura FAC-2026-005',12000.00,0.00),(2,1,'130505','Abono a factura FAC-2026-005',0.00,12000.00),(3,2,'111005','Recaudo factura FAC-2026-004',10000.00,0.00),(4,2,'130505','Abono a factura FAC-2026-004',0.00,10000.00),(5,3,'111005','Recaudo factura FAC-2026-023',5712000.00,0.00),(6,3,'130505','Abono a factura FAC-2026-023',0.00,5712000.00),(7,4,'111005','Recaudo factura FAC-2026-022',238000.00,0.00),(8,4,'130505','Abono a factura FAC-2026-022',0.00,238000.00),(9,5,'111005','Recaudo factura FAC-2026-015',4165000.00,0.00),(10,5,'130505','Abono a factura FAC-2026-015',0.00,4165000.00),(11,6,'111005','Recaudo factura FAC-2026-018',3332000.00,0.00),(12,6,'130505','Abono a factura FAC-2026-018',0.00,3332000.00),(13,7,'111005','Recaudo factura FAC-2026-003',14875000.00,0.00),(14,7,'130505','Abono a factura FAC-2026-003',0.00,14875000.00),(15,8,'111005','Recaudo factura FAC-2026-001',5355000.00,0.00),(16,8,'130505','Abono a factura FAC-2026-001',0.00,5355000.00),(17,9,'111005','Recaudo factura FAC-2026-008',5950000.00,0.00),(18,9,'130505','Abono a factura FAC-2026-008',0.00,5950000.00),(19,10,'111005','Recaudo factura FAC-2026-012',7735000.00,0.00),(20,10,'130505','Abono a factura FAC-2026-012',0.00,7735000.00),(21,11,'111005','Recaudo factura FAC-2026-010',14280000.00,0.00),(22,11,'130505','Abono a factura FAC-2026-010',0.00,14280000.00),(23,12,'111005','Recaudo factura FAC-2026-014',8568000.00,0.00),(24,12,'130505','Abono a factura FAC-2026-014',0.00,8568000.00),(25,13,'111005','Recaudo factura FAC-2026-019',4998000.00,0.00),(26,13,'130505','Abono a factura FAC-2026-019',0.00,4998000.00),(27,14,'111005','Recaudo factura FAC-2026-002',952000.00,0.00),(28,14,'130505','Abono a factura FAC-2026-002',0.00,952000.00),(29,15,'111005','Recaudo factura FAC-2026-007',17850000.00,0.00),(30,15,'130505','Abono a factura FAC-2026-007',0.00,17850000.00),(31,16,'514525','Mantenimiento de Equipo de Computación (Hosting)',139320.00,0.00),(32,16,'111005','Bancos',0.00,139320.00);
/*!40000 ALTER TABLE `comprobantes_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `conciliaciones`
--

DROP TABLE IF EXISTS `conciliaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conciliaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cuenta` varchar(10) NOT NULL,
  `periodo` char(7) NOT NULL,
  `saldo_extracto` decimal(14,2) DEFAULT NULL,
  `saldo_libro` decimal(14,2) DEFAULT NULL,
  `pendiente_libro` decimal(14,2) DEFAULT NULL,
  `pendiente_extracto` decimal(14,2) DEFAULT NULL,
  `estado` varchar(10) NOT NULL DEFAULT 'Abierta',
  `creado_por` int(11) DEFAULT NULL,
  `cerrado_por` int(11) DEFAULT NULL,
  `fecha_cierre` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_conciliacion` (`cuenta`,`periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `conciliaciones`
--

LOCK TABLES `conciliaciones` WRITE;
/*!40000 ALTER TABLE `conciliaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `conciliaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `consecutivos`
--

DROP TABLE IF EXISTS `consecutivos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `consecutivos` (
  `tipo` varchar(10) NOT NULL,
  `anio` smallint(6) NOT NULL,
  `ultimo` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`tipo`,`anio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `consecutivos`
--

LOCK TABLES `consecutivos` WRITE;
/*!40000 ALTER TABLE `consecutivos` DISABLE KEYS */;
INSERT INTO `consecutivos` VALUES ('CE',2026,1),('CI',2026,15),('FAC',2026,23),('PQR',2026,8);
/*!40000 ALTER TABLE `consecutivos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contactos`
--

DROP TABLE IF EXISTS `contactos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contactos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario_final` int(11) DEFAULT NULL,
  `nombre` varchar(150) NOT NULL,
  `correo` varchar(100) NOT NULL,
  `mensaje` text NOT NULL,
  `estado` enum('Nuevo','En Calificación','Contactado','En Propuesta','Negociación','Convertido','Descartado') DEFAULT 'Nuevo',
  `tipo_cliente` enum('Privado','Público','mixto') NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `servicio` varchar(100) NOT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `leido` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contactos`
--

LOCK TABLES `contactos` WRITE;
/*!40000 ALTER TABLE `contactos` DISABLE KEYS */;
INSERT INTO `contactos` VALUES (1,NULL,'Fundación Semillas de Futuro','fundacion.semillas.de.futuro@example.com','Interés en dashboard de analítica.','En Calificación','Privado','127.0.0.1','Business Intelligence','2026-03-01 14:15:00',1),(2,NULL,'Alcaldía de Villa Esperanza','alcaldia.de.villa.esperanza@example.com','Requerimiento para analítica de datos públicos.','Convertido','Público','127.0.0.1','Analítica Sector Público','2026-03-03 19:20:00',1),(3,NULL,'Ferretería La Llave S.A.S.','ferreteria.la.llave.s.a.s@example.com','Cotización para modelos predictivos.','En Calificación','Privado','127.0.0.1','Ciencia de Datos','2026-03-05 15:00:00',1),(4,NULL,'Ministerio de Planeación (demo)','ministerio.de.planeacion.demo@example.com','Solicitud de auditoría de datos estratégicos.','En Propuesta','Público','127.0.0.1','Analítica Sector Público','2026-03-07 16:30:00',1),(5,NULL,'Almacenes La Economía','almacenes.la.economia@example.com','Optimización de inventarios con IA.','Negociación','Privado','127.0.0.1','Ciencia de Datos','2026-03-08 21:45:00',1),(6,7,'Petroandina Energía S.A.','petroandina.energia.s.a@example.com','Análisis de sensores en tiempo real.','Contactado','mixto','127.0.0.1','Ciencia de Datos','2026-03-09 13:00:00',1),(7,NULL,'Banco Cordillera','banco.cordillera@example.com','Modernización de reportes BI.','En Propuesta','Privado','127.0.0.1','Business Intelligence','2026-03-09 20:10:00',1),(8,NULL,'Agroindustrial La Esperanza S.A.S.','agroindustrial.la.esperanza.s.a.s@example.com','Realizar un analisis en power Bi','En Calificación','Privado','127.0.0.1','Ciencia de Datos','2026-03-10 04:14:49',1),(9,NULL,'Agroindustrial La Esperanza S.A.S.','agroindustrial.la.esperanza.s.a.s@example.com','Realizar un analisis en power Bi','Descartado','Privado','127.0.0.1','Ciencia de Datos','2026-03-10 04:14:53',1),(10,NULL,'simón naranjo','simon.naranjo@example.com','bulling','Descartado','Público','127.0.0.1','Business Intelligence','2026-03-10 04:26:25',1),(11,NULL,'SaludGo','admin@example.com','datos','Contactado','mixto','127.0.0.1','Business Intelligence','2026-03-10 04:58:23',1),(12,NULL,'Redes y Cómputo del Centro S.A.S.','redes.y.computo.del.centro.s.a.s@example.com','crear dashboard','En Calificación','Privado','127.0.0.1','Business Intelligence','2026-03-12 20:58:21',1),(13,NULL,'Redes y Cómputo del Centro S.A.S.','redes.y.computo.del.centro.s.a.s2@example.com','Necesito crear un dashboard de kpis','Nuevo','Privado','127.0.0.1','Ciencia de Datos','2026-03-15 22:05:19',1),(14,NULL,'Métricas y Datos S.A.S.','cliente@example.com','hola','Descartado','Privado','127.0.0.1','Ciencia de Datos','2026-03-15 22:08:10',1),(15,NULL,'datasense','datasense@example.com','pruba de correo','Descartado','mixto','127.0.0.1','Ciencia de Datos','2026-03-16 01:56:58',1),(16,NULL,'bibiana cifuentes','bibiana.cifuentes@example.com','addaad','Descartado','Público','127.0.0.1','Business Intelligence','2026-03-16 04:30:55',1),(17,NULL,'alejandro bermúdez','alejandro.bermudez@example.com','Los datos son un desastre y necesitan orden primero.','En Calificación','Privado','127.0.0.1','CGD','2026-03-16 15:52:31',1),(18,NULL,'Julián Trujillo','alejandro.bermudez@example.com','furo','Descartado','Privado','127.0.0.1','ASP','2026-03-16 16:01:59',1),(19,NULL,'patricio suárez','cliente@example.com','trato','Descartado','Público','127.0.0.1','CGD','2026-03-16 21:52:26',1),(20,NULL,'diego giraldo','diego.giraldo@example.com','prurba','Descartado','mixto','127.0.0.1','CGD','2026-04-03 16:50:43',1),(21,NULL,'Julián Mejía','julian.mejia@example.com','agua','Descartado','mixto','127.0.0.1','BI','2026-04-03 17:40:33',1),(22,NULL,'alejandro bermúdez','cliente@example.com','pruba ciencia','Descartado','Privado','127.0.0.1','DS','2026-04-03 17:46:27',1),(23,NULL,'Métricas y Datos S.A.S.','metricas.y.datos.s.a.s@example.com','pruebas del sector','Descartado','Público','127.0.0.1','ASP','2026-04-03 17:50:48',1),(24,NULL,'Julián Echeverri','julian.echeverri@example.com','ciendioa','Descartado','Privado','127.0.0.1','DS','2026-04-03 18:06:28',1),(25,NULL,'Ministerio de Planeación (demo)','ministerio.de.planeacion.demo2@example.com','SOPORTE','Descartado','Público','127.0.0.1','BI','2026-04-03 18:16:35',1),(26,NULL,'Redes y Cómputo del Centro S.A.S.','redes.y.computo.del.centro.s.a.s3@example.com','prueba compu','Descartado','Privado','127.0.0.1','DS','2026-04-03 19:03:18',1),(27,NULL,'Julián Zuluaga','julian.zuluaga@example.com','prueba candado','Descartado','Privado','127.0.0.1','ASP','2026-04-03 19:17:33',1),(28,NULL,'Joaquín Valencia','joaquin.valencia@example.com','Hi,\r\n\r\nI came across saludgo.org and wanted to run something by you.\r\n\r\nMy team recently finished an 81-page YouTube guide specifically for website owners—whether you’re launching from scratch or trying to scale an existing channel in 2026.\r\n\r\nWe’ve just moved the guide to a \"Pay What You Want\" model. It’s valued at $28, but I wanted to make sure it’s accessible to fellow site owners regardless of their budget. You can grab it for the full price, the price of a coffee, or even $0 if things are tight right now.\r\n\r\nIf you’d like to see if the system fits your plans for this year, you can check it out here: https://furtherinfo.info/youtube\r\n\r\nJoaquín','Descartado','','127.0.0.1','ASP','2026-04-05 19:19:29',1),(29,NULL,'Édgar Quiroga','edgar.quiroga@example.com','Excited to announce our latest collection at Iftishop !  Discover exclusive offers up to 70%  only selection products and a wide range of products—shop now at https://iftishop.com . Don’t miss out on the best deals! \r\nProduct Categories \r\nFashion & Accessories: Includes items like genuine leather wallets, jewelry (e.g., adjustable chain anklets), and branded footwear (Clarks, New Balance, Guess). \r\nHome Decor: Various items to enhance living spaces. \r\nCar Accessories: Products like back-seat organizers and interior utility items. \r\nElectronics & Gadgets: Small tech items such as multifunctional hair styler brushes. \r\nKids & Education: Educational toys, 3D puzzles, and developmental tools for preschool learning. \r\nDigital Products: eBooks and guides covering topics like digital literacy and tech skills. \r\nKey Features & Services \r\nGlobal Reach: Offers fast worldwide shipping with expedited services and 30-day money-back guarantee \r\nhttps://iftishop.com/product/','Descartado','mixto','127.0.0.1','BI','2026-04-09 10:34:28',1),(30,NULL,'Métricas y Datos S.A.S.','cliente@example.com','prueba','Descartado','mixto','127.0.0.1','ASP','2026-04-12 23:43:32',1),(31,NULL,'Métricas y Datos S.A.S.','cliente@example.com','pruan','Descartado','Privado','127.0.0.1','ASP','2026-04-13 01:57:41',1),(32,NULL,'datasense','datasense2@example.com','assa','Descartado','Privado','127.0.0.1','DS','2026-04-13 03:14:58',1),(33,NULL,'wilson arbeláez','wilson.arbelaez2@example.com','pruebas','Descartado','Público','127.0.0.1','DS','2026-04-13 03:19:09',1),(34,NULL,'Julián Escobar','julian.escobar@example.com','equipo','Descartado','Privado','127.0.0.1','ASP','2026-04-13 03:39:35',1),(35,NULL,'Julián Isaza','julian.isaza@example.com','validad','Descartado','Público','127.0.0.1','CGD','2026-04-13 14:36:58',1),(36,NULL,'mateo muñoz','mateo.munoz@example.com','h','Descartado','Público','127.0.0.1','CGD','2026-04-13 20:23:04',1),(37,NULL,'nicolás wilson','nicolas.wilson@example.com','mario prueba','Descartado','mixto','127.0.0.1','CGD','2026-04-13 22:08:04',1),(38,NULL,'Julián Serna','julian.serna@example.com','analisits','Descartado','Público','127.0.0.1','ASP','2026-04-13 22:35:27',1),(39,NULL,'álvaro betancur','alvaro.betancur@example.com','demente','Descartado','Privado','127.0.0.1','BI','2026-04-13 22:49:58',1),(40,NULL,'raúl espinosa','raul.espinosa@example.com','dad','Descartado','Privado','127.0.0.1','DS','2026-04-14 04:27:59',1),(41,NULL,'Jimena Holguín','jimena.holguin@example.com','Hello\r\n\r\nEnlist saludgo.org in GoogleSearchIndex so it can be displayed in google search results!\r\n\r\nRegister saludgo.org now: https://searchregister.info','Descartado','mixto','127.0.0.1','DS','2026-04-15 20:39:10',1),(42,NULL,'Óscar Vallejo','oscar.vallejo@example.com','Salutations! saludgo.org, \r\nYour page appeared while I was exploring the internet. \r\nOur tool helps companies connect with websites in different niches. \r\nThis approach helps companies share their offers with relevant websites. \r\n  \r\n  \r\nIf this looks relevant, you can contact us. \r\n \r\nThanks for taking a moment to read this. \r\nContact us. \r\nTelegram - https://t.me/FeedbackFormEU \r\nWhatsApp - +375259112693 \r\nWhatsApp  https://wa.me/+375259112693','Descartado','Privado','127.0.0.1','BI','2026-04-23 16:51:08',1),(43,NULL,'Fabián Ibarra','jimena.holguin@example.com','Hello\r\n\r\nList saludgo.org in GoogleSearchIndex and have it show up in web search results!\r\n\r\nAdd saludgo.org now: https://searchregister.org','Descartado','','127.0.0.1','CGD','2026-04-28 17:26:23',0),(44,NULL,'Ángela Uribe','angela.uribe@example.com','Hi,\r\n\r\nI’m reaching out because we help brands connected to saludgo.org build authority on Instagram.\r\n\r\nWe use our customized AI system, mixed with natural manual interaction to drive niche-relevant followers to your page safely.\r\n\r\nOpen to finding out more about this?\r\n\r\nÁngela','Descartado','mixto','127.0.0.1','DS','2026-05-05 20:02:30',0),(45,NULL,'Ángela Uribe','angela.uribe2@example.com','Hi,\r\n\r\nI’m reaching out because we help brands connected to saludgo.org build authority on Instagram.\r\n\r\nWe use our customized AI system, mixed with natural manual interaction to drive niche-relevant followers to your page safely.\r\n\r\nOpen to finding out more about this?\r\n\r\nÁngela','Descartado','','127.0.0.1','DS','2026-05-07 07:11:41',1),(46,NULL,'Humberto Jiménez','humberto.jimenez@example.com','Hello http://saludgo.org,\r\n \r\nIf you’re looking to boost your website’s visibility, I can help you achieve top Google rankings.\r\n \r\nI’ll prepare a complete SEO plan with actionable steps and potential growth insights for your products or services.\r\n \r\nOnce you share your target keywords and target market, I’ll send a full proposal.\r\n \r\nBest Regards,\r\nHumberto','Descartado','Público','127.0.0.1','ASP','2026-05-19 16:44:11',0),(47,NULL,'Sebastián Molina','sebastian.molina@example.com','Hello http://saludgo.org,\r\n \r\nIf you’re looking to boost your website’s visibility, I can help you achieve top Google rankings.\r\n \r\nI’ll prepare a complete SEO plan with actionable steps and potential growth insights for your products or services.\r\n \r\nOnce you share your target keywords and target market, I’ll send a full proposal.\r\n \r\nBest Regards,\r\nSebastián','Descartado','mixto','127.0.0.1','ASP','2026-05-21 05:10:16',0),(48,NULL,'Gonzalo Hoyos','gonzalo.hoyos@example.com','Hello http://saludgo.org,\r\n \r\nI hope you’re doing well. I came across your business online and thought you might be interested in improving your visibility and traffic on search engines.\r\n \r\nWe specialize in helping businesses strengthen their online presence through effective SEO strategies.\r\n \r\nOnce you share your target keywords and target market, I’ll send a full proposal.\r\n \r\nWarm regards,\r\nGonzalo','Descartado','','127.0.0.1','DS','2026-05-22 21:34:54',0),(49,NULL,'Gustavo Lara','gustavo.lara@example.com','YyErjcwdkdjwjjwjjdwjddjwsjf ndsaKAqwdweihduncbbwebidaa iudwnishqwuvdwqihbfvweuiojsqjqioqdefiw dwqsqwijbfiewdncbhvdifqhioqsjnqw saludgo.org','Descartado','Privado','127.0.0.1','BI','2026-05-26 15:40:04',0),(50,NULL,'Bernardo Tamayo','bernardo.tamayo@example.com','Greetings, \r\n \r\nI hope this mail finds you well. \r\n \r\nUnder corporate mandate, I’m pleased to propose a potential collaboration to manage a significant cash for investment in your company or project financing at a flexible and competitive rate. As you may well be aware, this is necessitated due to the growing global sanctions and the need for our clientele of  High Net Worth Individuals in Russia, China and Middle East to Diversify, As a funds manager i am open to further discussion and disclosure. \r\nPlease feel free to contact me directly, confidentiality rule applies. \r\n \r\nBest regards, \r\nBernardo Tamayo A. \r\nFunds Manager \r\n \r\nEmail: contacto27@example.com','Descartado','mixto','127.0.0.1','BI','2026-05-27 04:39:24',0),(51,NULL,'Hoyos Daza','gonzalo.hoyos@example.com','Hello http://saludgo.org,\r\n \r\nIf you’re looking to boost your website’s visibility, I can help you achieve top Google rankings.\r\n \r\nI’ll prepare a complete SEO plan with actionable steps and potential growth insights for your products or services.\r\n \r\nOnce you share your target keywords and target market, I’ll send a full proposal.\r\n \r\nBest Regards,\r\nGonzalo','Descartado','mixto','127.0.0.1','CGD','2026-05-28 02:36:22',0),(52,NULL,'Emilio Cárdenas','emilio.cardenas@example.com','Greetings! \r\n \r\nI am working directly with a private INVESTOR portfolio that can provide funding for credible clients with feasible projects. Currently, we have investment funds for viable projects. \r\n \r\nWe are currently seeking means of expanding and relocating our business interest in the following sectors: Banking, Real Estate, Stock Speculation and Mining, Transportation, Health sector and Tobacco or any other sector you may suggest. The interest rate and tenure are fantastic. \r\n \r\nSend me an email on : contacto28@example.com \r\n \r\nKindly respond accordingly if you have interest for possible corporation. \r\n \r\nRegards, \r\nEmilio Cárdenas','Descartado','Privado','127.0.0.1','BI','2026-06-03 02:19:11',1),(53,NULL,'Esteban Vélez','esteban.velez@example.com','IMPORTANT MESSAGE! 1.3426 BTC IS GUARANTEED WITHDRAW WHILE POSSIBLE https://bittyshort.com/RXbdM','Descartado','Privado','127.0.0.1','BI','2026-06-11 12:04:10',0),(54,NULL,'Esteban Vélez','esteban.velez2@example.com','Why the $27,000,000 Jackpot Is the Prize Worth the Wait https://s.kode4u.tech/Q4ehA','Descartado','mixto','127.0.0.1','BI','2026-06-11 22:55:01',0),(55,NULL,'Óscar Vallejo','oscar.vallejo2@example.com','Good morning! saludgo.org, \r\nWhile browsing different sites I came across yours. \r\nWe provide a service that helps businesses connect with website owners through contact forms. \r\nThis method allows companies to introduce their services online. \r\nThe platform provides flexible pricing depending on usage. \r\nYou can take a moment to explore how the system works. \r\nIf you are interested, feel free to reach out. \r\n \r\nThank you for your time. \r\nContact us. \r\nTelegram - https://t.me/FeedbackFormEU \r\nWhatsApp - +375259112693 \r\nWhatsApp  https://wa.me/+375259112693','Descartado','mixto','127.0.0.1','BI','2026-06-13 02:12:52',1),(56,NULL,'Esteban Vélez','esteban.velez3@example.com','THE $27,000,000 JACKPOT IS A DIAL FOR DIVIDENDS https://alstr.in/ssFpSl','Descartado','Privado','127.0.0.1','BI','2026-06-15 01:24:54',0),(57,NULL,'Esteban Vélez','esteban.velez4@example.com','The $27,000,000 Jackpot Is a Galaxy of Gain https://myip.kr/MmOtI','Descartado','mixto','127.0.0.1','BI','2026-06-17 13:45:38',0),(58,NULL,'Esteban Vélez','esteban.velez5@example.com','MAKE A PLAY FOR GLORY WITH THE $27,000,000 JACKPOT http://poderdiario.com/LGxIb','Descartado','Privado','127.0.0.1','BI','2026-06-19 17:25:24',0),(59,NULL,'Esteban Vélez','esteban.velez6@example.com','THE $27,000,000 JACKPOT IS A CROWN FOR CASH http://freeurlredirect.com/d77a4','Descartado','Privado','127.0.0.1','BI','2026-06-22 03:58:05',0),(60,NULL,'Esteban Vélez','esteban.velez7@example.com','THE $27,000,000 JACKPOT IS A MEDAL FOR MONEY MOVES https://shortmylink.co/sNKwm','Descartado','mixto','127.0.0.1','BI','2026-06-24 23:38:36',0),(61,NULL,'Esteban Vélez','esteban.velez8@example.com','URGENT! Take the Product Your 1.3426 BTC Toil https://s.ubyt.es/9LPypA','Descartado','mixto','127.0.0.1','BI','2026-06-28 21:08:05',0),(62,NULL,'Esteban Vélez','esteban.velez9@example.com','A Moment of Courage Could Land You the $27,000,000 Jackpot https://jbiv.com/sNcOU','Descartado','Privado','127.0.0.1','BI','2026-07-02 16:03:19',1),(63,NULL,'Felipe Rendón','felipe.rendon@example.com','My name is Felipe Rendón, I am a research head with Hamilton Laboratory UK known for vast manufacturing. \r\n \r\nI am reaching out to discuss a promising business opportunity that could be highly advantageous for both of us. I need a dependable foreign business partner to assist me in procuring a rare Premium Herbal Extract known as Kolmogorovian HG57. \r\n \r\nAlthough this may not fall within your usual area of expertise, it presents an opportunity for an additional revenue stream for you or your organization. The limited availability of this raw material has impeded product development at my company. Our previous supplier in Ukraine has ceased operations due to the ongoing conflict in the region. \r\n \r\nPROPOSAL: I am requesting your agreement to act as a new contractor between the manufacturer and Hamilton Laboratory to facilitate this project/contract. \r\n \r\nWe would share the profits from this venture, with 80% allocated to you and 20% to me. I am unable to bid for the supply contract myself, as I prefer to avoid direct contact between my company and the manufacturer, which also falls outside the scope of my employment contract. \r\n \r\nPlease respond to this email contacto29@example.com so that I can provide you with further details regarding the process. \r\n \r\nI look forward to establishing a mutually beneficial partnership. \r\n \r\nKind regards, \r\nFelipe Rendón. \r\nResearch & Development Department \r\nDurham Pharmaceuticals Limited \r\ncontacto29@example.com \r\nhttps://hamiltonpharmaceuticals.com','Descartado','Privado','127.0.0.1','BI','2026-07-06 01:44:33',1),(64,NULL,'Esteban Vélez','esteban.velez10@example.com','ONE CLICK AWAY FROM THE $27,000,000 JACKPOT https://linkypay.com/RmsnZ','Descartado','Privado','127.0.0.1','BI','2026-07-07 11:35:18',1),(65,NULL,'Esteban Vélez','esteban.velez11@example.com','THE $27,000,000 JACKPOT IS A BLAST TO BLISS http://dbolc.com/SJe2OJmN','Descartado','Privado','127.0.0.1','BI','2026-07-11 18:25:36',1),(66,NULL,'Rafael Agudelo','rafael.agudelo@example.com','THE $27,000,000 JACKPOT IS A BELIEF WITH BALANCE https://link.1hut.ru/pbSUQt','Descartado','Privado','127.0.0.1','BI','2026-07-16 12:32:15',0),(67,NULL,'Óscar Vallejo','oscar.vallejo3@example.com','Greetings! saludgo.org, \r\nI came across saludgo.org while browsing online. \r\nOur service is built to simplify online outreach to websites. \r\nOur tool helps companies manage website outreach more efficiently. \r\nOur pricing is designed to be accessible for businesses of different sizes. \r\n  \r\nLet us know if this could be useful. \r\n \r\nAppreciate your time. \r\nContact us. \r\nTelegram - https://t.me/FeedbackFormEU \r\nWhatsApp - +375259112693 \r\nWhatsApp  https://wa.me/+375259112693 \r\nWe only use chat for communication.','Descartado','Privado','127.0.0.1','BI','2026-07-18 18:43:34',1),(68,NULL,'Rafael Agudelo','rafael.agudelo2@example.com','Give Yourself Permission to Win the $27,000,000 Jackpot http://freeurlredirect.com/df2px','Descartado','mixto','127.0.0.1','BI','2026-07-20 12:58:55',1),(69,NULL,'Rafael Agudelo','rafael.agudelo3@example.com','Collect $1,500 per day or more from tokenized forestry assets https://telegra.ph/Collect-cryptocurrency-automatically-every-day-over-1500-Message-ID-601997-07-23','Descartado','Privado','127.0.0.1','BI','2026-07-23 19:53:12',0),(70,NULL,'Rafael Agudelo','rafael.agudelo4@example.com','LEARN TECHNICAL ANALYSIS TO TARGET $1,500 PER DAY OR MORE https://telegra.ph/Collect-cryptocurrency-automatically-every-day-over-1500-Message-ID-394976-07-23','Descartado','Privado','127.0.0.1','BI','2026-07-28 14:35:18',1),(71,NULL,'Ángela Uribe','angela.uribe@example.com','Hi,\r\n\r\nI was just looking at saludgo.org and wanted to ask: are you looking to scale your Instagram presence right now?\r\n\r\nWe help brands like yours add 300+ targeted Instagram followers every month using manual outreach and ads. We can grow your existing page or even build a brand-new profile from scratch for you if you\'d prefer a fresh start.\r\n\r\nWould you like me to send over some more info on how it works?\r\n\r\nThanks for your time,\r\nÁngela','Descartado','mixto','127.0.0.1','ASP','2026-08-03 16:29:49',1),(72,NULL,'Camila Galeano','camila.galeano@example.com','Boost your website backlinks with best quality seo backlinks! \r\nBonusBacklinks.com - we deliver daily backlinks and drive organic clicks to your website EVERY DAY:\r\n\r\nUse 85% SALE\r\nQuality daily backlinks\r\nOrganic website traffic\r\nPrices as low as $1\r\nBonus coupon codes:\r\n\r\nhttps://bonusbacklinks.com/85offer\r\n\r\nBonusBacklinks.com - daily backlinks and organic clicks to increase your website everyday','Descartado','Privado','127.0.0.1','CGD','2026-08-25 11:55:16',1);
/*!40000 ALTER TABLE `contactos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ds_chat_messages`
--

DROP TABLE IF EXISTS `ds_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ds_chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) DEFAULT NULL,
  `remitente` enum('user','bot') DEFAULT NULL,
  `mensaje` text DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `session_id` (`session_id`),
  CONSTRAINT `ds_chat_messages_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `ds_chat_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=512 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ds_chat_messages`
--

LOCK TABLES `ds_chat_messages` WRITE;
/*!40000 ALTER TABLE `ds_chat_messages` DISABLE KEYS */;
INSERT INTO `ds_chat_messages` VALUES (1,1,'bot','👋 ¡Hola! Soy SenseBot, el asistente de IA de DataSense. ¿Cómo te llamas?','2026-03-16 16:22:17'),(2,1,'user','wilson arbeláez','2026-03-16 16:22:22'),(3,1,'bot','Mucho gusto, wilson arbeláez. ¿De qué empresa o entidad nos escribes?','2026-03-16 16:22:23'),(4,1,'user','redes y cómputo del centro s.a.s.','2026-03-16 16:22:29'),(5,1,'bot','Perfecto. En DataSense estamos listos para llevar a redes y cómputo del centro s.a.s. al siguiente nivel. ¿En qué te puedo ayudar hoy?','2026-03-16 16:22:30'),(6,1,'user','📊 Agendar diagnóstico','2026-03-16 16:22:33'),(7,1,'bot','¡Excelente decisión! 🚀 Para coordinar tu diagnóstico gratuito, por favor escribe tu correo electrónico aquí en el chat:','2026-03-16 16:22:34'),(8,1,'user','cliente@example.com','2026-03-16 16:22:52'),(9,1,'bot','¡Anotado! 📩 Hemos registrado el correo: cliente@example.com. Un experto te contactará en breve.','2026-03-16 16:22:53'),(10,1,'bot','¿Te puedo ayudar con alguna otra consulta?','2026-03-16 16:22:54'),(11,1,'user','Sí','2026-03-16 16:23:02'),(12,1,'user','⚙️ Consultar servicios','2026-03-16 16:23:07'),(13,1,'bot','Ofrecemos Business Intelligence y Ciencia de Datos avanzada. Ayudamos a transformar datos crudos en decisiones ejecutivas.','2026-03-16 16:23:08'),(14,1,'bot','¿Te puedo ayudar con alguna otra consulta?','2026-03-16 16:23:09'),(15,1,'user','Sí','2026-03-16 16:23:18'),(16,1,'user','🏛️ Analítica Sector Público','2026-03-16 16:23:19'),(17,1,'bot','Somos especialistas en transparencia y gestión pública. Implementamos modelos predictivos para gobernaciones y alcaldías.','2026-03-16 16:23:20'),(18,1,'bot','¿Te puedo ayudar con alguna otra consulta?','2026-03-16 16:23:21'),(19,1,'user','Sí','2026-03-16 16:23:29'),(20,1,'user','🏛️ Analítica Sector Público','2026-03-16 16:23:44'),(21,1,'bot','Somos especialistas en transparencia y gestión pública. Implementamos modelos predictivos para gobernaciones y alcaldías.','2026-03-16 16:23:45'),(22,1,'bot','¿Te puedo ayudar con alguna otra consulta?','2026-03-16 16:23:46'),(23,1,'user','No, gracias','2026-03-16 16:23:47'),(24,1,'bot','¡Perfecto! Que tengas un excelente día. Quedamos a tu disposición en DataSense. 👋','2026-03-16 16:23:48'),(25,2,'bot','👋 ¡Hola! Soy SenseBot, el asistente de IA de DataSense. ¿Cómo te llamas?','2026-03-16 16:25:51'),(26,2,'user','simón naranjo','2026-03-16 16:25:54'),(27,2,'bot','Mucho gusto, simón naranjo. ¿De qué empresa o entidad nos escribes?','2026-03-16 16:25:55'),(28,2,'user','soluciones tecnológicas andinas s.a.s.','2026-03-16 16:25:59'),(29,2,'bot','Perfecto. En DataSense estamos listos para llevar a soluciones tecnológicas andinas s.a.s. al siguiente nivel. ¿En qué te puedo ayudar hoy?','2026-03-16 16:26:00'),(30,2,'user','🛡️ Gobierno y Calidad de Datos','2026-03-16 16:26:02'),(31,2,'bot','Aseguramos que tu información sea precisa, centralizada y segura. Implementamos diccionarios de datos, auditorías y limpieza estructural para que confíes al 100% en tus métricas.','2026-03-16 16:26:03'),(32,2,'bot','¿Te puedo ayudar con alguna otra consulta?','2026-03-16 16:26:04'),(33,3,'bot','👋 ¡Hola! Soy SenseBot, el asistente de IA de DataSense. ¿Cómo te llamas?','2026-03-16 16:31:17'),(34,3,'user','samuel mesa','2026-03-16 16:31:26'),(35,3,'bot','Mucho gusto, samuel mesa. ¿De qué empresa o entidad nos escribes?','2026-03-16 16:31:27'),(36,3,'user','instituto técnico del valle alto','2026-03-16 16:31:37'),(37,3,'bot','Perfecto. En DataSense estamos listos para llevar a instituto técnico del valle alto al siguiente nivel. ¿En qué te puedo ayudar hoy?','2026-03-16 16:31:38'),(38,3,'user','⚙️ BI y Ciencia de Datos','2026-03-16 16:31:44'),(39,3,'bot','El Business Intelligence transforma empresas. ¿Cuál dirías que es su mayor desafío actual: los reportes demoran mucho en hacerse, o les cuesta proyectar ventas a futuro?','2026-03-16 16:31:45'),(40,3,'user','les cuesta proyectar ventas a futuro?','2026-03-16 16:32:10'),(41,3,'bot','Es un dolor muy común en las empresas, pero automatizarlo cambia las reglas del juego por completo. Para enviarte un caso de éxito sobre esto y ponernos en contacto, ¿cuál es tu correo electrónico?','2026-03-16 16:32:11'),(42,3,'user','contacto@example.com','2026-03-16 16:32:26'),(43,3,'bot','¡Anotado! 📩 Hemos registrado el correo: contacto@example.com. Nuestro equipo analizará lo que me comentaste y te escribiremos muy pronto.','2026-03-16 16:32:27'),(44,3,'bot','¿Te puedo ayudar con alguna otra consulta?','2026-03-16 16:32:29'),(45,3,'user','Sí','2026-03-16 16:32:32'),(46,3,'user','📊 Agendar diagnóstico','2026-03-16 16:32:35'),(47,3,'bot','¡Excelente decisión! Antes de agendar, cuéntame brevemente: ¿Qué herramienta usan más hoy en día para ver sus datos? (Ej: Excel, ERP propio, ninguna...)','2026-03-16 16:32:36'),(48,3,'user','excel','2026-03-16 16:32:49'),(49,3,'bot','Comprendo. Evaluar esa situación actual es el primer paso para modernizar su analítica. Para enviarte los detalles de cómo haremos el diagnóstico, ¿me regalas tu correo electrónico?','2026-03-16 16:32:50'),(50,3,'user','contacto@example.com','2026-03-16 16:33:07'),(51,3,'bot','¡Anotado! 📩 Hemos registrado el correo: contacto@example.com. Nuestro equipo analizará lo que me comentaste y te escribiremos muy pronto.','2026-03-16 16:33:09'),(52,3,'bot','¿Te puedo ayudar con alguna otra consulta?','2026-03-16 16:33:10'),(53,3,'user','Sí','2026-03-16 16:33:14'),(54,3,'user','🏛️ Analítica Sector Público','2026-03-16 16:33:17'),(55,3,'bot','El sector público requiere alta precisión. ¿Están buscando implementar tableros de transparencia para la ciudadanía, o analítica interna para optimizar presupuesto?','2026-03-16 16:33:18'),(56,3,'user','¿Están buscando implementar tableros de transparencia para la ciudadanía','2026-03-16 16:33:37'),(57,3,'bot','Entiendo la necesidad. La gestión de datos públicos es delicada y exige herramientas robustas. Déjame tu correo institucional o personal para que un consultor experto te contacte.','2026-03-16 16:33:38'),(58,3,'user','contacto2@example.com','2026-03-16 16:33:54'),(59,3,'bot','¡Anotado! 📩 Hemos registrado el correo: contacto2@example.com. Nuestro equipo analizará lo que me comentaste y te escribiremos muy pronto.','2026-03-16 16:33:55'),(60,3,'bot','¿Te puedo ayudar con alguna otra consulta?','2026-03-16 16:33:57'),(61,4,'bot','👋 ¡Hola! Soy SenseBot, tu consultor analítico en DataSense. Para hacer esto más personal, ¿cómo te llamas?','2026-03-16 16:39:16'),(62,4,'user','Olga Guzmán','2026-03-16 16:39:28'),(63,4,'bot','Mucho gusto, Olga Guzmán. ¿De qué empresa o entidad nos contactas?','2026-03-16 16:39:29'),(64,4,'user','Ministerio de Cultura','2026-03-16 16:39:51'),(65,4,'bot','¡Genial! En DataSense estamos listos para potenciar a Ministerio de Cultura.','2026-03-16 16:39:52'),(66,4,'bot','Aquí tienes nuestras líneas de especialidad. Elige la que más resuene con tu necesidad actual:','2026-03-16 16:39:53'),(67,4,'user','📊 Agendar diagnóstico','2026-03-16 16:39:55'),(68,4,'bot','Para enfocar bien el diagnóstico, ¿qué área de la empresa genera el mayor volumen de datos?','2026-03-16 16:39:57'),(69,4,'user','💰 Ventas y Comercial','2026-03-16 16:40:10'),(70,4,'bot','Entendido. Y actualmente, ¿sienten que esos datos son 100% confiables para tomar decisiones clave?','2026-03-16 16:40:11'),(71,4,'user','❌ No, hay inconsistencias o errores','2026-03-16 16:40:20'),(72,4,'bot','Excelente, ya tengo una radiografía clara de lo que necesitan. Tenemos casos de éxito resolviendo exactamente este escenario. 🚀\n\nPara enviarte una propuesta preliminar o agendar una llamada de 15 minutos, por favor escribe tu correo electrónico corporativo:','2026-03-16 16:40:21'),(73,4,'user','contacto3@example.com','2026-03-16 16:40:41'),(74,4,'bot','¡Confirmado! 📩 Hemos registrado el correo: contacto3@example.com. Nuestro equipo evaluará tus respuestas y te contactaremos a la brevedad.','2026-03-16 16:40:43'),(75,4,'bot','¿Hay algún otro tema que quisieras explorar hoy?','2026-03-16 16:40:44'),(76,4,'user','✅ Sí, volver al menú','2026-03-16 16:40:54'),(77,4,'bot','Aquí tienes nuestras líneas de especialidad. Elige la que más resuene con tu necesidad actual:','2026-03-16 16:40:56'),(78,4,'user','⚙️ BI y Ciencia de Datos','2026-03-16 16:40:57'),(79,4,'bot','El Business Intelligence transforma organizaciones. ¿En qué etapa se encuentran hoy?','2026-03-16 16:40:58'),(80,4,'user','🤖 Buscamos modelos predictivos (IA)','2026-03-16 16:41:10'),(81,4,'bot','Perfecto. Si tuvieras una varita mágica, ¿cuál sería el mayor logro a resolver este mes?','2026-03-16 16:41:11'),(82,4,'user','⏱️ Automatizar reportes (ahorrar tiempo)','2026-03-16 16:41:22'),(83,4,'bot','Excelente, ya tengo una radiografía clara de lo que necesitan. Tenemos casos de éxito resolviendo exactamente este escenario. 🚀\n\nPara enviarte una propuesta preliminar o agendar una llamada de 15 minutos, por favor escribe tu correo electrónico corporativo:','2026-03-16 16:41:23'),(84,4,'user','contacto2@example.com','2026-03-16 16:41:38'),(85,4,'bot','¡Confirmado! 📩 Hemos registrado el correo: contacto2@example.com. Nuestro equipo evaluará tus respuestas y te contactaremos a la brevedad.','2026-03-16 16:41:39'),(86,4,'bot','¿Hay algún otro tema que quisieras explorar hoy?','2026-03-16 16:41:41'),(87,4,'user','✅ Sí, volver al menú','2026-03-16 16:41:42'),(88,4,'bot','Aquí tienes nuestras líneas de especialidad. Elige la que más resuene con tu necesidad actual:','2026-03-16 16:41:44'),(89,4,'user','🏛️ Analítica Sector Público','2026-03-16 16:41:45'),(90,4,'bot','La analítica en el sector público requiere rigor. ¿Para qué nivel de entidad buscas apoyo?','2026-03-16 16:41:46'),(91,4,'user','🏛️ Alcaldía / Municipio','2026-03-16 16:41:57'),(92,4,'bot','Comprendido. ¿El objetivo principal del proyecto está orientado hacia adentro o hacia los ciudadanos?','2026-03-16 16:41:58'),(93,4,'user','👥 Tableros de Transparencia (Ciudadanía)','2026-03-16 16:42:07'),(94,4,'bot','Excelente, ya tengo una radiografía clara de lo que necesitan. Tenemos casos de éxito resolviendo exactamente este escenario. 🚀\n\nPara enviarte una propuesta preliminar o agendar una llamada de 15 minutos, por favor escribe tu correo electrónico corporativo:','2026-03-16 16:42:08'),(95,4,'user','contacto4@example.com','2026-03-16 16:42:17'),(96,4,'bot','¡Confirmado! 📩 Hemos registrado el correo: contacto4@example.com. Nuestro equipo evaluará tus respuestas y te contactaremos a la brevedad.','2026-03-16 16:42:19'),(97,4,'bot','¿Hay algún otro tema que quisieras explorar hoy?','2026-03-16 16:42:20'),(98,4,'user','✅ Sí, volver al menú','2026-03-16 16:42:21'),(99,4,'bot','Aquí tienes nuestras líneas de especialidad. Elige la que más resuene con tu necesidad actual:','2026-03-16 16:42:22'),(100,4,'user','🛡️ Gobierno y Calidad de Datos','2026-03-16 16:42:24'),(101,4,'bot','El Gobierno de Datos es la zapata del edificio analítico. ¿Cuál es el síntoma que más les preocupa hoy?','2026-03-16 16:42:25'),(102,4,'user','🔄 Datos duplicados o desactualizados','2026-03-16 16:42:40'),(103,4,'bot','Es un dolor clásico que cuesta mucho dinero mensual. ¿Cuentan actualmente con un equipo de TI dedicado a esto?','2026-03-16 16:42:42'),(104,4,'user','🤷‍♂️ No, necesitamos apoyo externo','2026-03-16 16:42:55'),(105,4,'bot','Excelente, ya tengo una radiografía clara de lo que necesitan. Tenemos casos de éxito resolviendo exactamente este escenario. 🚀\n\nPara enviarte una propuesta preliminar o agendar una llamada de 15 minutos, por favor escribe tu correo electrónico corporativo:','2026-03-16 16:42:56'),(106,4,'user','contacto5@example.com','2026-03-16 16:43:03'),(107,4,'bot','¡Confirmado! 📩 Hemos registrado el correo: contacto5@example.com. Nuestro equipo evaluará tus respuestas y te contactaremos a la brevedad.','2026-03-16 16:43:04'),(108,4,'bot','¿Hay algún otro tema que quisieras explorar hoy?','2026-03-16 16:43:06'),(109,4,'user','❌ No, es todo por hoy','2026-03-16 16:43:30'),(110,4,'bot','¡Ha sido un verdadero placer! Que tengas un excelente día. Quedamos a tu entera disposición en DataSense. 👋','2026-03-16 16:43:31'),(111,5,'bot','👋 ¡Hola! Soy SenseBot, tu consultor analítico en DataSense. Para hacer esto más personal, ¿cómo te llamas?','2026-03-16 17:41:43'),(112,5,'user','bibiana cifuentes','2026-03-16 17:41:51'),(113,5,'bot','Mucho gusto, bibiana cifuentes. ¿De qué empresa o entidad nos contactas?','2026-03-16 17:41:52'),(114,5,'user','Sector Publico','2026-03-16 17:42:06'),(115,5,'bot','¡Genial! En DataSense estamos listos para potenciar a Sector Publico.','2026-03-16 17:42:07'),(116,5,'bot','Aquí tienes nuestras líneas de especialidad. Elige la que más resuene con tu necesidad actual:','2026-03-16 17:42:07'),(117,6,'bot','👋 ¡Hola! Soy SenseBot, tu consultor analítico. Para hacer esto más personal, ¿cómo te llamas?','2026-04-03 04:39:24'),(118,6,'user','hola','2026-04-03 04:39:29'),(119,6,'bot','Mucho gusto, hola. ¿De qué empresa nos contactas?','2026-04-03 04:39:30'),(120,6,'user','Redes y Cómputo del Centro S.A.S.','2026-04-03 04:39:44'),(121,6,'bot','¡Genial! En DataSense estamos listos para potenciar a Redes y Cómputo del Centro S.A.S..','2026-04-03 04:39:45'),(122,6,'bot','Aquí tienes nuestras líneas de especialidad. Elige una:','2026-04-03 04:39:45'),(123,6,'user','📊 Agendar diagnóstico','2026-04-03 04:39:55'),(124,6,'bot','¿Qué área genera más datos?','2026-04-03 04:39:56'),(125,6,'user','Operaciones','2026-04-03 04:40:01'),(126,6,'bot','Excelente 🚀. Para enviarte una propuesta o agendar una llamada, por favor escribe tu correo corporativo:','2026-04-03 04:40:02'),(127,6,'user','contacto6@example.com','2026-04-03 04:40:24'),(128,6,'bot','¡Confirmado! 📩 Te contactaremos a contacto6@example.com a la brevedad.','2026-04-03 04:40:25'),(129,6,'bot','¡Un placer! Quedamos a tu disposición en DataSense. 👋','2026-04-03 04:40:26'),(134,11,'bot','👋 ¡Hola! Soy SenseBot, tu asistente analítico en DataSense. ¿Con quién tengo el gusto de hablar?','2026-04-03 14:18:19'),(135,11,'user','candado','2026-04-03 14:18:27'),(136,11,'bot','Mucho gusto, candado. ¿A qué empresa representas?','2026-04-03 14:18:28'),(137,11,'user','cliente','2026-04-03 14:18:33'),(138,11,'bot','¡Perfecto! En DataSense estamos listos para potenciar a cliente.','2026-04-03 14:18:34'),(139,11,'bot','¿En cuál de nuestras líneas de servicio tiene interés tu organización?','2026-04-03 14:18:34'),(140,11,'user','🛡️ Gobierno y Calidad de Datos','2026-04-03 14:18:35'),(141,11,'bot','Excelente elección. Cuéntame brevemente, ¿cuál es el principal desafío que enfrentan en cliente?','2026-04-03 14:18:36'),(142,11,'user','dupliciada','2026-04-03 14:18:43'),(143,11,'bot','Comprendo. Nuestro equipo analizará este requerimiento. Para enviarte una propuesta, por favor escribe tu correo corporativo:','2026-04-03 14:18:44'),(144,11,'user','cliente@example.com','2026-04-03 14:19:25'),(145,11,'bot','¡Solicitud registrada! 📩 Nos comunicaremos contigo pronto. ¡Que tengas un excelente día! 👋','2026-04-03 14:19:27'),(146,11,'bot','--- Sesión completada con éxito ---','2026-04-03 14:19:27'),(147,12,'bot','👋 ¡Hola! Soy SenseBot, tu asistente analítico en DataSense. ¿Con quién tengo el gusto de hablar?','2026-04-03 14:37:17'),(148,12,'user','samuel mesa','2026-04-03 14:37:19'),(149,12,'bot','Mucho gusto, samuel mesa. ¿A qué empresa representas?','2026-04-03 14:37:20'),(150,12,'user','redes y cómputo del centro s.a.s.','2026-04-03 14:38:07'),(151,12,'bot','¡Perfecto! En DataSense estamos listos para potenciar a redes y cómputo del centro s.a.s..','2026-04-03 14:38:08'),(152,12,'bot','¿En cuál de nuestras líneas de servicio tiene interés tu organización?','2026-04-03 14:38:08'),(153,12,'user','🛡️ Gobierno y Calidad de Datos','2026-04-03 14:38:10'),(154,12,'bot','Excelente elección. Cuéntame brevemente, ¿cuál es el principal desafío que enfrentan en redes y cómputo del centro s.a.s.?','2026-04-03 14:38:11'),(155,12,'user','tecnologia','2026-04-03 14:38:27'),(156,12,'bot','Comprendo. Nuestro equipo analizará este requerimiento. Para enviarte una propuesta, por favor escribe tu correo corporativo:','2026-04-03 14:38:28'),(157,12,'user','cliente@example.com','2026-04-03 14:38:46'),(158,12,'bot','¡Solicitud registrada! 📩 Nos comunicaremos contigo pronto. ¡Que tengas un excelente día! 👋','2026-04-03 14:38:48'),(159,12,'bot','--- Sesión completada con éxito ---','2026-04-03 14:38:48'),(160,13,'bot','👋 ¡Hola! Soy SenseBot, tu asistente analítico en DataSense. ¿Con quién tengo el gusto de hablar?','2026-04-03 14:39:32'),(161,13,'user','wilson ocampo','2026-04-03 14:43:51'),(162,13,'bot','Mucho gusto, wilson ocampo. ¿A qué empresa representas?','2026-04-03 14:43:52'),(163,13,'user','soluciones tecnológicas andinas s.a.s.','2026-04-03 14:46:43'),(164,13,'bot','¡Perfecto! En DataSense estamos listos para potenciar a soluciones tecnológicas andinas s.a.s..','2026-04-03 14:46:44'),(165,13,'bot','¿En cuál de nuestras líneas de servicio tiene interés tu organización?','2026-04-03 14:46:44'),(166,13,'user','📊 Business Intelligence','2026-04-03 14:47:01'),(167,13,'bot','Excelente elección. Cuéntame brevemente, ¿cuál es el principal desafío que enfrentan en soluciones tecnológicas andinas s.a.s.?','2026-04-03 14:47:02'),(168,13,'user','mover lapiz','2026-04-03 14:47:22'),(169,13,'bot','Comprendo. Nuestro equipo analizará este requerimiento. Para enviarte una propuesta, por favor escribe tu correo corporativo:','2026-04-03 14:47:23'),(170,13,'user','guillermonieto.200391@hot','2026-04-03 14:47:47'),(171,13,'bot','¡Solicitud registrada! 📩 Nos comunicaremos contigo pronto. ¡Que tengas un excelente día! 👋','2026-04-03 14:47:49'),(172,13,'bot','--- Sesión completada con éxito ---','2026-04-03 14:47:49'),(173,14,'bot','Gusto en saludarte, wilson arbeláez. ¿De qué organización nos contactas?','2026-04-03 15:37:26'),(174,14,'user','instituto técnico del valle alto','2026-04-03 15:37:33'),(175,14,'bot','¿En qué línea de consultoría podemos apoyarte?','2026-04-03 15:37:34'),(176,14,'user','🛡️ Gobernanza','2026-04-03 15:38:07'),(177,14,'bot','Entendido. ¿Qué desafío principal enfrentan hoy en instituto técnico del valle alto?','2026-04-03 15:38:09'),(178,14,'user','salud','2026-04-03 15:38:15'),(179,14,'bot','Para enviarte la propuesta, por favor escribe tu correo corporativo:','2026-04-03 15:38:17'),(180,14,'user','cliente@example.com','2026-04-03 15:38:32'),(181,14,'bot','¡Gracias! Un consultor te contactará pronto. 👋','2026-04-03 15:38:33'),(182,15,'bot','Mucho gusto, Wilson Ocampo Flórez. ¿De qué empresa u organización nos contactas?','2026-04-03 15:48:23'),(183,15,'user','EDITORIAL PÁRAMO','2026-04-03 15:48:43'),(184,15,'bot','¿En cuál de nuestras líneas de consultoría tiene interés tu organización?','2026-04-03 15:48:44'),(185,15,'bot','¡Perfecto! En DataSense estamos listos para potenciar a EDITORIAL PÁRAMO.','2026-04-03 15:48:45'),(186,15,'user','📊 Business Intelligence','2026-04-03 15:49:03'),(187,15,'bot','Excelente elección. ¿Qué desafío principal de datos enfrentan actualmente en EDITORIAL PÁRAMO?','2026-04-03 15:49:04'),(188,15,'user','MOLESTAR','2026-04-03 15:49:10'),(189,15,'bot','Entiendo la situación. Para enviarte la propuesta técnica o agendar una cita, por favor escribe tu correo corporativo:','2026-04-03 15:49:11'),(190,15,'user','contacto6@example.com','2026-04-03 15:49:30'),(191,15,'bot','--- Consultoría completada con éxito ---','2026-04-03 15:49:30'),(192,15,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-03 15:49:32'),(193,16,'bot','Un placer saludarte, simón lozada. ¿A qué empresa u organización representas?','2026-04-03 16:22:13'),(194,16,'user','SEGURIDAD ATALAYA LTDA.','2026-04-03 16:22:35'),(195,16,'bot','Para brindarte una atención especializada, ¿en cuál de nuestras líneas de consultoría tiene interés tu organización?','2026-04-03 16:22:36'),(196,16,'bot','¡Perfecto! En DataSense estamos listos para potenciar a SEGURIDAD ATALAYA LTDA..','2026-04-03 16:22:37'),(197,16,'user','🛡️ Calidad de Datos','2026-04-03 16:22:59'),(198,16,'bot','Excelente elección. ¿Qué desafío principal enfrentan actualmente en SEGURIDAD ATALAYA LTDA.?','2026-04-03 16:23:00'),(199,16,'user','PRUEBAS','2026-04-03 16:23:18'),(200,16,'bot','Comprendo. Nuestro equipo de especialistas analizará este requerimiento. Para enviarte la propuesta técnica, por favor escribe tu correo corporativo:','2026-04-03 16:23:20'),(201,16,'user','G','2026-04-03 16:23:24'),(202,16,'bot','⚠️ Por favor, ingresa un correo corporativo válido para que podamos contactarte:','2026-04-03 16:23:25'),(203,16,'user','contacto7@example.com','2026-04-03 16:23:48'),(204,16,'bot','--- Consultoría Finalizada ---','2026-04-03 16:23:48'),(205,16,'bot','¡Solicitud registrada! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-03 16:23:50'),(206,17,'bot','Un placer saludarte, mónica duque. ¿A qué empresa u organización representas?','2026-04-03 17:17:24'),(207,17,'user','panadería la espiga','2026-04-03 17:17:56'),(208,17,'bot','Para brindarte una atención especializada, ¿en cuál de nuestras líneas de consultoría tiene interés tu organización?','2026-04-03 17:17:57'),(209,17,'bot','¡Perfecto! En DataSense estamos listos para potenciar a panadería la espiga.','2026-04-03 17:17:57'),(210,17,'user','🛡️ Calidad de Datos','2026-04-03 17:18:59'),(211,17,'bot','Excelente elección. ¿Qué desafío principal enfrentan actualmente en panadería la espiga?','2026-04-03 17:19:01'),(212,17,'user','col','2026-04-03 17:19:07'),(213,17,'bot','Comprendo. Nuestro equipo de especialistas analizará este requerimiento. Para enviarte la propuesta técnica, por favor escribe tu correo corporativo:','2026-04-03 17:19:09'),(214,17,'user','gasas','2026-04-03 17:19:14'),(215,17,'bot','⚠️ Por favor, ingresa un correo corporativo válido para que podamos contactarte:','2026-04-03 17:19:15'),(216,17,'bot','--- Consultoría Finalizada ---','2026-04-03 17:19:30'),(217,17,'user','contacto8@example.com','2026-04-03 17:19:30'),(218,17,'bot','¡Solicitud registrada! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-03 17:19:32'),(219,18,'bot','Un placer saludarte, isabel botero. ¿A qué empresa u organización representas?','2026-04-03 17:22:19'),(220,18,'user','grupo empresarial sabana','2026-04-03 17:22:51'),(221,18,'bot','Para brindarte una atención especializada, ¿en cuál de nuestras líneas de consultoría tiene interés tu organización?','2026-04-03 17:22:51'),(222,18,'bot','¡Perfecto! En DataSense estamos listos para potenciar a grupo empresarial sabana.','2026-04-03 17:22:52'),(223,19,'bot','Un placer saludarte, claro. ¿A qué empresa u organización representas?','2026-04-03 17:23:59'),(224,19,'user','claro','2026-04-03 17:24:25'),(225,19,'bot','Para brindarte una atención especializada, ¿en cuál de nuestras líneas de consultoría tiene interés tu organización?','2026-04-03 17:24:26'),(226,19,'bot','¡Perfecto! En DataSense estamos listos para potenciar a claro.','2026-04-03 17:24:26'),(227,20,'bot','Un placer saludarte, julio. ¿A qué empresa u organización representas?','2026-04-03 17:34:58'),(228,20,'user','group','2026-04-03 17:35:16'),(229,20,'bot','¡Perfecto! En DataSense estamos listos para potenciar a group.','2026-04-03 17:35:17'),(230,20,'bot','Para brindarte una atención especializada, ¿en cuál de nuestras líneas de consultoría tiene interés tu organización?','2026-04-03 17:35:19'),(231,20,'user','🧠 Ciencia de Datos','2026-04-03 17:37:31'),(232,20,'bot','Excelente elección. ¿Qué desafío principal enfrentan actualmente en group?','2026-04-03 17:37:33'),(233,21,'bot','Un placer saludarte, luna. ¿A qué empresa u organización representas?','2026-04-03 17:44:09'),(234,21,'user','claro','2026-04-03 17:44:16'),(235,21,'bot','¡Perfecto! En DataSense estamos listos para potenciar a claro.','2026-04-03 17:44:17'),(236,21,'bot','Para brindarte una atención especializada, ¿en cuál de nuestras líneas de consultoría tiene interés tu organización? 👇 (Haz clic en una de las opciones)','2026-04-03 17:44:18'),(237,21,'user','hola','2026-04-03 17:44:34'),(238,21,'bot','👆 Por favor, selecciona una de nuestras líneas de consultoría haciendo clic en uno de los botones verdes de arriba.','2026-04-03 17:44:36'),(239,21,'user','🏛️ Analítica Sector Público','2026-04-03 17:44:49'),(240,21,'bot','Excelente elección. ¿Qué desafío principal enfrentan actualmente en claro?','2026-04-03 17:44:51'),(241,21,'user','robo','2026-04-03 17:45:05'),(242,21,'bot','Comprendo. Nuestro equipo de especialistas analizará este requerimiento. Para enviarte la propuesta técnica, por favor escribe tu correo corporativo:','2026-04-03 17:45:06'),(243,21,'user','h','2026-04-03 17:45:18'),(244,21,'bot','⚠️ Por favor, ingresa un correo corporativo válido para que podamos contactarte:','2026-04-03 17:45:19'),(245,21,'bot','--- Consultoría Finalizada ---','2026-04-03 17:45:33'),(246,21,'user','contacto9@example.com','2026-04-03 17:45:33'),(247,21,'bot','¡Solicitud registrada! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-03 17:45:35'),(248,22,'bot','Un placer saludarte, hola. ¿A qué empresa u organización representas?','2026-04-12 16:12:12'),(249,23,'bot','¡Un placer saludarte, simón lozada! ¿A qué empresa u organización representas?','2026-04-12 20:53:45'),(250,23,'user','seguridad atalaya ltda.','2026-04-12 20:54:06'),(251,23,'bot','¡Perfecto! En DataSense estamos listos para potenciar a seguridad atalaya ltda..','2026-04-12 20:54:07'),(252,23,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-12 20:54:08'),(253,23,'user','🏛️ Analítica Sector Público','2026-04-12 20:54:28'),(254,23,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente seguridad atalaya ltda. en esa área?','2026-04-12 20:54:29'),(255,23,'user','grficos','2026-04-12 20:54:38'),(256,23,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-12 20:54:39'),(257,23,'bot','--- Consultoría Finalizada ---','2026-04-12 20:54:59'),(258,23,'user','contacto10@example.com','2026-04-12 20:54:59'),(259,23,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-12 20:55:00'),(260,24,'bot','¡Un placer saludarte, federico navarro! ¿A qué empresa u organización representas?','2026-04-12 22:12:56'),(261,24,'user','redes y cómputo del centro s.a.s.','2026-04-12 22:13:00'),(262,24,'bot','¡Perfecto! En DataSense estamos listos para potenciar a redes y cómputo del centro s.a.s..','2026-04-12 22:13:01'),(263,24,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-12 22:13:02'),(264,24,'user','🛡️ Calidad de Datos','2026-04-12 22:13:11'),(265,24,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente redes y cómputo del centro s.a.s. en esa área?','2026-04-12 22:13:12'),(266,24,'user','problemas','2026-04-12 22:13:21'),(267,24,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-12 22:13:22'),(268,24,'bot','--- Consultoría Finalizada ---','2026-04-12 22:13:45'),(269,24,'user','contacto11@example.com','2026-04-12 22:13:45'),(270,24,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-12 22:13:47'),(271,25,'bot','¡Un placer saludarte, datasense! ¿A qué empresa u organización representas?','2026-04-12 22:26:54'),(272,25,'user','datasense','2026-04-12 22:27:02'),(273,25,'bot','¡Perfecto! En DataSense estamos listos para potenciar a datasense.','2026-04-12 22:27:04'),(274,25,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-12 22:27:05'),(275,25,'user','📊 Business Intelligence','2026-04-12 22:27:07'),(276,25,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente datasense en esa área?','2026-04-12 22:27:08'),(277,25,'user','tecnicos','2026-04-12 22:27:14'),(278,25,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-12 22:27:16'),(279,25,'user','datasense@datasense.com','2026-04-12 22:27:27'),(280,25,'bot','--- Consultoría Finalizada ---','2026-04-12 22:27:27'),(281,25,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-12 22:27:29'),(282,26,'bot','¡Un placer saludarte, advisor! ¿A qué empresa u organización representas?','2026-04-12 22:27:58'),(283,26,'user','constructora puente alto s.a.s.','2026-04-12 22:28:05'),(284,26,'bot','¡Perfecto! En DataSense estamos listos para potenciar a constructora puente alto s.a.s..','2026-04-12 22:28:06'),(285,26,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-12 22:28:08'),(286,26,'user','🏛️ Analítica Sector Público','2026-04-12 22:28:10'),(287,26,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente constructora puente alto s.a.s. en esa área?','2026-04-12 22:28:12'),(288,26,'user','advinsor','2026-04-12 22:28:19'),(289,26,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-12 22:28:21'),(290,26,'bot','--- Consultoría Finalizada ---','2026-04-12 22:28:39'),(291,26,'user','contacto12@example.com','2026-04-12 22:28:39'),(292,26,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-12 22:28:41'),(293,27,'bot','¡Un placer saludarte, prueba! ¿A qué empresa u organización representas?','2026-04-13 05:43:15'),(294,27,'user','prueba','2026-04-13 05:43:18'),(295,27,'bot','¡Perfecto! En DataSense estamos listos para potenciar a prueba.','2026-04-13 05:43:19'),(296,27,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 05:43:21'),(297,27,'user','🛡️ Calidad de Datos','2026-04-13 05:43:51'),(298,27,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente prueba en esa área?','2026-04-13 05:43:53'),(299,27,'user','prueba','2026-04-13 05:44:01'),(300,27,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 05:44:02'),(301,27,'user','julian.isaza@example.com','2026-04-13 05:44:11'),(302,27,'bot','--- Consultoría Finalizada ---','2026-04-13 05:44:11'),(303,27,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 05:44:12'),(304,28,'bot','¡Un placer saludarte, claro! ¿A qué empresa u organización representas?','2026-04-13 05:49:37'),(305,28,'user','claro','2026-04-13 05:49:53'),(306,28,'bot','¡Perfecto! En DataSense estamos listos para potenciar a claro.','2026-04-13 05:49:54'),(307,28,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 05:49:56'),(308,28,'user','🛡️ Calidad de Datos','2026-04-13 05:50:02'),(309,28,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente claro en esa área?','2026-04-13 05:50:03'),(310,28,'user','claro','2026-04-13 05:50:09'),(311,28,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 05:50:10'),(312,28,'user','contacto13@example.com','2026-04-13 05:50:17'),(313,28,'bot','--- Consultoría Finalizada ---','2026-04-13 05:50:17'),(314,28,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 05:50:18'),(315,29,'bot','¡Un placer saludarte, simón naranjo! ¿A qué empresa u organización representas?','2026-04-13 06:10:10'),(316,29,'user','instituto técnico del valle alto','2026-04-13 06:10:19'),(317,29,'bot','¡Perfecto! En DataSense estamos listos para potenciar a instituto técnico del valle alto.','2026-04-13 06:10:20'),(318,29,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 06:10:21'),(319,29,'user','📊 Business Intelligence','2026-04-13 06:10:23'),(320,29,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente instituto técnico del valle alto en esa área?','2026-04-13 06:10:25'),(321,29,'user','fallas','2026-04-13 06:10:29'),(322,29,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 06:10:30'),(323,29,'bot','--- Consultoría Finalizada ---','2026-04-13 06:21:39'),(324,29,'user','contacto14@example.com','2026-04-13 06:21:39'),(325,29,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 06:21:41'),(326,30,'bot','¡Un placer saludarte, julio! ¿A qué empresa u organización representas?','2026-04-13 08:16:48'),(327,30,'user','git','2026-04-13 09:29:59'),(328,30,'bot','¡Perfecto! En DataSense estamos listos para potenciar a git.','2026-04-13 09:30:00'),(329,30,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 09:30:01'),(330,30,'user','🛡️ Calidad de Datos','2026-04-13 09:30:10'),(331,30,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente git en esa área?','2026-04-13 09:30:11'),(332,30,'user','repositorio','2026-04-13 09:30:25'),(333,30,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 09:30:26'),(334,30,'user','julian.echeverri@example.com','2026-04-13 09:30:41'),(335,30,'bot','--- Consultoría Finalizada ---','2026-04-13 09:30:41'),(336,30,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 09:30:43'),(337,31,'bot','¡Un placer saludarte, tele! ¿A qué empresa u organización representas?','2026-04-13 09:31:05'),(338,31,'user','tele','2026-04-13 09:32:35'),(339,31,'bot','¡Perfecto! En DataSense estamos listos para potenciar a tele.','2026-04-13 09:32:36'),(340,31,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 09:32:37'),(341,31,'user','🧠 Ciencia de Datos','2026-04-13 09:32:40'),(342,31,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente tele en esa área?','2026-04-13 09:32:42'),(343,31,'user','contacto15@example.com','2026-04-13 09:32:53'),(344,31,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 09:32:54'),(345,31,'bot','--- Consultoría Finalizada ---','2026-04-13 09:33:04'),(346,31,'user','contacto15@example.com','2026-04-13 09:33:04'),(347,31,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 09:33:06'),(348,32,'bot','¡Un placer saludarte, chat! ¿A qué empresa u organización representas?','2026-04-13 09:45:56'),(349,33,'bot','¡Un placer saludarte, data! ¿A qué empresa u organización representas?','2026-04-13 14:59:21'),(350,33,'user','hola','2026-04-13 14:59:24'),(351,33,'bot','¡Perfecto! En DataSense estamos listos para potenciar a hola.','2026-04-13 14:59:25'),(352,33,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 14:59:27'),(353,33,'user','📊 Business Intelligence','2026-04-13 14:59:41'),(354,33,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente hola en esa área?','2026-04-13 14:59:43'),(355,33,'user','pruansa','2026-04-13 15:22:08'),(356,33,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 15:22:10'),(357,34,'bot','¡Un placer saludarte, mauricio fajardo! ¿A qué empresa u organización representas?','2026-04-13 15:34:06'),(358,34,'user','redes y cómputo del centro s.a.s.','2026-04-13 15:39:32'),(359,34,'bot','¡Perfecto! En DataSense estamos listos para potenciar a redes y cómputo del centro s.a.s..','2026-04-13 15:39:33'),(360,34,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 15:39:35'),(361,34,'user','🛡️ Calidad de Datos','2026-04-13 16:12:44'),(362,34,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente redes y cómputo del centro s.a.s. en esa área?','2026-04-13 16:12:45'),(363,34,'user','claro','2026-04-13 16:12:50'),(364,34,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 16:12:51'),(365,34,'user','contacto16@example.com','2026-04-13 16:13:00'),(366,34,'bot','--- Consultoría Finalizada ---','2026-04-13 16:13:00'),(367,34,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 16:13:01'),(368,35,'bot','¡Un placer saludarte, hugo rincón! ¿A qué empresa u organización representas?','2026-04-13 16:13:39'),(369,35,'user','inversiones altamira s.a.s.','2026-04-13 16:14:20'),(370,35,'bot','¡Perfecto! En DataSense estamos listos para potenciar a inversiones altamira s.a.s..','2026-04-13 16:14:21'),(371,35,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 16:14:23'),(372,35,'user','🏛️ Analítica Sector Público','2026-04-13 16:16:32'),(373,35,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente inversiones altamira s.a.s. en esa área?','2026-04-13 16:16:33'),(374,35,'user','claro','2026-04-13 16:16:47'),(375,35,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 16:16:49'),(376,35,'bot','--- Consultoría Finalizada ---','2026-04-13 16:16:56'),(377,35,'user','contacto17@example.com','2026-04-13 16:16:56'),(378,35,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 16:16:58'),(379,36,'bot','¡Un placer saludarte, clemente salazar! ¿A qué empresa u organización representas?','2026-04-13 16:20:02'),(380,36,'user','hotel mirador de los andes','2026-04-13 16:25:21'),(381,36,'bot','¡Perfecto! En DataSense estamos listos para potenciar a hotel mirador de los andes.','2026-04-13 16:25:22'),(382,36,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 16:25:23'),(383,36,'user','🛡️ Calidad de Datos','2026-04-13 16:25:35'),(384,36,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente hotel mirador de los andes en esa área?','2026-04-13 16:25:36'),(385,36,'user','clari','2026-04-13 16:25:40'),(386,36,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 16:25:41'),(387,36,'bot','--- Consultoría Finalizada ---','2026-04-13 16:25:47'),(388,36,'user','contacto18@example.com','2026-04-13 16:25:47'),(389,36,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 16:25:49'),(390,37,'bot','¡Un placer saludarte, kilo! ¿A qué empresa u organización representas?','2026-04-13 16:26:14'),(391,37,'user','turismo ruta verde s.a.s.','2026-04-13 16:27:07'),(392,37,'bot','¡Perfecto! En DataSense estamos listos para potenciar a turismo ruta verde s.a.s..','2026-04-13 16:27:08'),(393,37,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 16:27:10'),(394,37,'user','🛡️ Calidad de Datos','2026-04-13 16:27:22'),(395,37,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente turismo ruta verde s.a.s. en esa área?','2026-04-13 16:27:24'),(396,37,'user','sonia','2026-04-13 16:28:55'),(397,37,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 16:28:57'),(398,37,'user','contacto19@example.com','2026-04-13 16:29:07'),(399,37,'bot','--- Consultoría Finalizada ---','2026-04-13 16:29:07'),(400,37,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 16:29:09'),(401,38,'bot','¡Un placer saludarte, ia! ¿A qué empresa u organización representas?','2026-04-13 16:33:27'),(402,38,'user','consultor ia','2026-04-13 16:33:44'),(403,38,'bot','¡Perfecto! En DataSense estamos listos para potenciar a consultor ia.','2026-04-13 16:33:45'),(404,38,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 16:33:47'),(405,38,'user','🏛️ Analítica Sector Público','2026-04-13 16:33:58'),(406,38,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente consultor ia en esa área?','2026-04-13 16:34:00'),(407,38,'user','ia','2026-04-13 16:35:49'),(408,38,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 16:35:50'),(409,38,'bot','--- Consultoría Finalizada ---','2026-04-13 16:35:55'),(410,38,'user','contacto20@example.com','2026-04-13 16:35:55'),(411,38,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 16:35:57'),(412,39,'bot','¡Un placer saludarte, elías noreña! ¿A qué empresa u organización representas?','2026-04-13 16:43:03'),(413,40,'bot','¡Un placer saludarte, mario sierra! ¿A qué empresa u organización representas?','2026-04-13 16:47:32'),(414,40,'user','papelería arcoíris','2026-04-13 16:47:38'),(415,40,'bot','¡Perfecto! En DataSense estamos listos para potenciar a papelería arcoíris.','2026-04-13 16:47:40'),(416,40,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 16:47:41'),(417,40,'user','🛡️ Calidad de Datos','2026-04-13 16:50:54'),(418,40,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente papelería arcoíris en esa área?','2026-04-13 16:50:55'),(419,40,'user','contacto21@example.com','2026-04-13 16:51:29'),(420,40,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 16:51:31'),(421,40,'user','contacto21@example.com','2026-04-13 16:51:40'),(422,40,'bot','--- Consultoría Finalizada ---','2026-04-13 16:51:40'),(423,40,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 16:51:41'),(424,41,'bot','¡Un placer saludarte, verde! ¿A qué empresa u organización representas?','2026-04-13 16:51:57'),(425,41,'user','azul','2026-04-13 16:52:06'),(426,41,'bot','¡Perfecto! En DataSense estamos listos para potenciar a azul.','2026-04-13 16:52:07'),(427,41,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 16:52:09'),(428,41,'user','🛡️ Calidad de Datos','2026-04-13 16:52:50'),(429,41,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente azul en esa área?','2026-04-13 16:52:51'),(430,41,'user','claro','2026-04-13 16:52:54'),(431,41,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 16:52:55'),(432,41,'user','contacto22@example.com','2026-04-13 16:53:14'),(433,41,'bot','--- Consultoría Finalizada ---','2026-04-13 16:53:14'),(434,41,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 16:53:16'),(435,42,'bot','¡Un placer saludarte, data! ¿A qué empresa u organización representas?','2026-04-13 17:10:06'),(436,42,'user','colegio bilingüe los cedros','2026-04-13 17:10:18'),(437,42,'bot','¡Perfecto! En DataSense estamos listos para potenciar a colegio bilingüe los cedros.','2026-04-13 17:10:19'),(438,42,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 17:10:21'),(439,42,'user','🧠 Ciencia de Datos','2026-04-13 17:10:31'),(440,42,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente colegio bilingüe los cedros en esa área?','2026-04-13 17:10:32'),(441,43,'bot','¡Un placer saludarte, hola! ¿A qué empresa u organización representas?','2026-04-13 17:48:21'),(442,43,'user','pizza','2026-04-13 17:48:31'),(443,43,'bot','¡Perfecto! En DataSense estamos listos para potenciar a pizza.','2026-04-13 17:48:32'),(444,43,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 17:48:34'),(445,43,'user','🛡️ Calidad de Datos','2026-04-13 17:48:44'),(446,43,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente pizza en esa área?','2026-04-13 17:48:46'),(447,43,'user','claro','2026-04-13 17:48:49'),(448,43,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 17:48:50'),(449,43,'user','contacto23@example.com','2026-04-13 17:48:58'),(450,43,'bot','--- Consultoría Finalizada ---','2026-04-13 17:48:58'),(451,43,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 17:49:00'),(452,44,'bot','¡Un placer saludarte, hola! ¿A qué empresa u organización representas?','2026-04-13 17:59:35'),(453,44,'user','rojo','2026-04-13 17:59:50'),(454,44,'bot','¡Perfecto! En DataSense estamos listos para potenciar a rojo.','2026-04-13 17:59:51'),(455,44,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 17:59:53'),(456,44,'user','🛡️ Calidad de Datos','2026-04-13 18:00:11'),(457,44,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente rojo en esa área?','2026-04-13 18:00:12'),(458,44,'user','j','2026-04-13 18:00:15'),(459,44,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 18:00:16'),(460,44,'user','contacto24@example.com','2026-04-13 18:00:21'),(461,44,'bot','--- Consultoría Finalizada ---','2026-04-13 18:00:21'),(462,44,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 18:00:23'),(463,45,'bot','¡Un placer saludarte, darío lopera! ¿A qué empresa u organización representas?','2026-04-13 18:00:44'),(464,45,'user','servicios contables prisma','2026-04-13 18:00:54'),(465,45,'bot','¡Perfecto! En DataSense estamos listos para potenciar a servicios contables prisma.','2026-04-13 18:00:55'),(466,45,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 18:00:56'),(467,46,'bot','¡Un placer saludarte, raúl espinosa! ¿A qué empresa u organización representas?','2026-04-13 23:25:18'),(468,46,'user','verde','2026-04-13 23:25:32'),(469,46,'bot','¡Perfecto! En DataSense estamos listos para potenciar a verde.','2026-04-13 23:25:33'),(470,46,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 23:25:35'),(471,46,'user','🛡️ Calidad de Datos','2026-04-13 23:26:12'),(472,46,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente verde en esa área?','2026-04-13 23:26:13'),(473,46,'user','claro','2026-04-13 23:27:01'),(474,46,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-13 23:27:02'),(475,46,'bot','--- Consultoría Finalizada ---','2026-04-13 23:27:15'),(476,46,'user','contacto25@example.com','2026-04-13 23:27:15'),(477,46,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-13 23:27:17'),(478,47,'bot','¡Un placer saludarte, sense! ¿A qué empresa u organización representas?','2026-04-13 23:47:53'),(479,47,'user','notepad','2026-04-13 23:48:14'),(480,47,'bot','¡Perfecto! En DataSense estamos listos para potenciar a notepad.','2026-04-13 23:48:15'),(481,47,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 23:48:17'),(482,48,'bot','¡Un placer saludarte, mónica duque! ¿A qué empresa u organización representas?','2026-04-13 23:50:03'),(483,48,'user','loco','2026-04-13 23:50:04'),(484,48,'bot','¡Perfecto! En DataSense estamos listos para potenciar a loco.','2026-04-13 23:50:05'),(485,48,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 23:50:07'),(486,49,'bot','¡Un placer saludarte, armando estrada! ¿A qué empresa u organización representas?','2026-04-13 23:53:26'),(487,49,'user','transportes rápido llanero s.a.s.','2026-04-13 23:53:29'),(488,49,'bot','¡Perfecto! En DataSense estamos listos para potenciar a transportes rápido llanero s.a.s..','2026-04-13 23:53:30'),(489,49,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-13 23:53:32'),(490,50,'bot','¡Un placer saludarte, prueba! ¿A qué empresa u organización representas?','2026-04-14 16:42:21'),(491,50,'user','dos','2026-04-14 16:42:29'),(492,50,'bot','¡Perfecto! En DataSense estamos listos para potenciar a dos.','2026-04-14 16:42:30'),(493,50,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-04-14 16:42:31'),(494,50,'user','🛡️ Calidad de Datos','2026-04-14 16:42:44'),(495,50,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente dos en esa área?','2026-04-14 16:42:46'),(496,50,'user','contacto13@example.com','2026-04-14 16:43:05'),(497,50,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-04-14 16:43:06'),(498,50,'user','contacto13@example.com','2026-04-14 16:43:16'),(499,50,'bot','--- Consultoría Finalizada ---','2026-04-14 16:43:16'),(500,50,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-04-14 16:43:18'),(501,51,'bot','¡Un placer saludarte, Hola! ¿A qué empresa u organización representas?','2026-05-06 00:23:05'),(502,51,'user','Soluciones Tecnológicas Andinas S.A.S.','2026-05-06 00:23:15'),(503,51,'bot','¡Perfecto! En DataSense estamos listos para potenciar a Soluciones Tecnológicas Andinas S.A.S..','2026-05-06 00:23:16'),(504,51,'bot','Para brindarte atención especializada, ¿en cuál línea de consultoría tiene interés tu organización? 👇 (Selecciona una opción)','2026-05-06 00:23:17'),(505,51,'user','🧠 Ciencia de Datos','2026-05-06 00:23:30'),(506,51,'bot','Excelente elección. ¿Qué desafío principal enfrenta actualmente Soluciones Tecnológicas Andinas S.A.S. en esa área?','2026-05-06 00:23:32'),(507,51,'user','Soporte','2026-05-06 00:23:48'),(508,51,'bot','Comprendo. Nuestros especialistas analizarán este requerimiento. Para enviarte la propuesta técnica, escribe tu correo corporativo:','2026-05-06 00:23:50'),(509,51,'bot','--- Consultoría Finalizada ---','2026-05-06 00:24:12'),(510,51,'user','contacto26@example.com','2026-05-06 00:24:12'),(511,51,'bot','¡Solicitud registrada con éxito! 📩 Nuestro equipo analizará tu requerimiento y te contactará pronto. ¡Feliz día! 👋','2026-05-06 00:24:14');
/*!40000 ALTER TABLE `ds_chat_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ds_chat_sessions`
--

DROP TABLE IF EXISTS `ds_chat_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ds_chat_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_usuario` varchar(100) DEFAULT NULL,
  `empresa_entidad` varchar(150) DEFAULT NULL,
  `fecha_inicio` datetime DEFAULT current_timestamp(),
  `estado` enum('activo','finalizado') DEFAULT 'activo',
  `visto` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ds_chat_sessions`
--

LOCK TABLES `ds_chat_sessions` WRITE;
/*!40000 ALTER TABLE `ds_chat_sessions` DISABLE KEYS */;
INSERT INTO `ds_chat_sessions` VALUES (1,'wilson arbeláez','Redes y Cómputo del Centro S.A.S.','2026-03-16 16:22:17','activo',1),(2,'simón naranjo','Soluciones Tecnológicas Andinas S.A.S.','2026-03-16 16:25:51','activo',1),(3,'samuel mesa','Instituto Técnico del Valle Alto','2026-03-16 16:31:17','activo',1),(4,'Olga Guzmán','Ministerio de Cultura','2026-03-16 16:39:16','activo',1),(5,'bibiana cifuentes','Sector Publico','2026-03-16 17:41:43','activo',1),(6,'hola','Redes y Cómputo del Centro S.A.S.','2026-04-03 04:39:24','activo',1),(11,'candado','cliente','2026-04-03 14:18:19','finalizado',1),(12,'samuel mesa','Redes y Cómputo del Centro S.A.S.','2026-04-03 14:37:17','finalizado',1),(13,'wilson ocampo','Soluciones Tecnológicas Andinas S.A.S.','2026-04-03 14:39:32','finalizado',1),(14,'wilson arbeláez','Pendiente','2026-04-03 15:37:26','activo',1),(15,'Wilson Ocampo Flórez','Editorial Páramo','2026-04-03 15:48:23','finalizado',1),(16,'simón lozada','Seguridad Atalaya Ltda.','2026-04-03 16:22:13','finalizado',1),(17,'mónica duque','Panadería La Espiga','2026-04-03 17:17:24','finalizado',1),(18,'isabel botero','Grupo Empresarial Sabana','2026-04-03 17:22:19','activo',1),(19,'claro','claro','2026-04-03 17:23:59','activo',1),(20,'julio','group','2026-04-03 17:34:58','activo',1),(21,'luna','claro','2026-04-03 17:44:09','finalizado',1),(22,'hola','','2026-04-12 16:12:12','activo',1),(23,'simón lozada','Seguridad Atalaya Ltda.','2026-04-12 20:53:45','finalizado',1),(24,'federico navarro','Redes y Cómputo del Centro S.A.S.','2026-04-12 22:12:56','finalizado',1),(25,'datasense','datasense','2026-04-12 22:26:54','finalizado',1),(26,'advisor','Constructora Puente Alto S.A.S.','2026-04-12 22:27:58','finalizado',1),(27,'prueba','prueba','2026-04-13 05:43:15','finalizado',1),(28,'claro','claro','2026-04-13 05:49:37','finalizado',1),(29,'simón naranjo','Instituto Técnico del Valle Alto','2026-04-13 06:10:10','finalizado',1),(30,'julio','git','2026-04-13 08:16:48','finalizado',1),(31,'tele','tele','2026-04-13 09:31:05','finalizado',1),(32,'chat','empresa','2026-04-13 09:45:56','activo',1),(33,'data','hola','2026-04-13 14:59:21','activo',1),(34,'mauricio fajardo','Redes y Cómputo del Centro S.A.S.','2026-04-13 15:34:06','finalizado',1),(35,'hugo rincón','Inversiones Altamira S.A.S.','2026-04-13 16:13:39','finalizado',1),(36,'clemente salazar','Hotel Mirador de los Andes','2026-04-13 16:20:02','finalizado',1),(37,'kilo','Turismo Ruta Verde S.A.S.','2026-04-13 16:26:14','finalizado',1),(38,'ia','consultor ia','2026-04-13 16:33:27','finalizado',1),(39,'elías noreña','Pendiente','2026-04-13 16:43:03','activo',1),(40,'mario sierra','Papelería Arcoíris','2026-04-13 16:47:32','finalizado',1),(41,'verde','azul','2026-04-13 16:51:57','finalizado',1),(42,'data','Colegio Bilingüe Los Cedros','2026-04-13 17:10:06','activo',1),(43,'hola','pizza','2026-04-13 17:48:21','finalizado',1),(44,'hola','rojo','2026-04-13 17:59:35','finalizado',1),(45,'darío lopera','Servicios Contables Prisma','2026-04-13 18:00:44','activo',1),(46,'raúl espinosa','verde','2026-04-13 23:25:18','finalizado',0),(47,'sense','notepad','2026-04-13 23:47:53','activo',1),(48,'mónica duque','loco','2026-04-13 23:50:03','activo',1),(49,'armando estrada','Transportes Rápido Llanero S.A.S.','2026-04-13 23:53:26','activo',1),(50,'prueba','dos','2026-04-14 16:42:21','finalizado',1),(51,'Hola','Soluciones Tecnológicas Andinas S.A.S.','2026-05-06 00:23:05','finalizado',0);
/*!40000 ALTER TABLE `ds_chat_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `egresos`
--

DROP TABLE IF EXISTS `egresos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `egresos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero_egreso` varchar(20) NOT NULL,
  `fecha_pago` date NOT NULL,
  `beneficiario` varchar(150) NOT NULL,
  `documento_beneficiario` varchar(50) DEFAULT NULL,
  `concepto` text NOT NULL,
  `valor_total` decimal(15,2) NOT NULL,
  `valor_letras` varchar(255) NOT NULL,
  `metodo_pago` varchar(100) DEFAULT NULL,
  `estado` enum('Pendiente','Aprobado','Rechazado','Anulado') DEFAULT 'Pendiente',
  `preparado_por` int(11) DEFAULT NULL,
  `revisado_por` int(11) DEFAULT NULL,
  `fecha_aprobacion` datetime DEFAULT NULL,
  `fecha_creacion` timestamp NULL DEFAULT current_timestamp(),
  `motivo_anulacion` text DEFAULT NULL,
  `anulado_por` int(11) DEFAULT NULL,
  `fecha_anulacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_egreso` (`numero_egreso`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `egresos`
--

LOCK TABLES `egresos` WRITE;
/*!40000 ALTER TABLE `egresos` DISABLE KEYS */;
INSERT INTO `egresos` VALUES (1,'CE-000001','2026-04-01','DataSense','1084664393','pago de hosting y dominio',139320.00,'CIENTO TREINTA Y NUEVE MIL TRECIENTOS VEINTE PESOS','Transferencia Bancaria','Aprobado',5,6,'2026-04-01 22:54:43','2026-04-01 22:38:34',NULL,NULL,NULL),(2,'CE-000002','2026-04-02','DataSense','1084664393','pago de hosting y dominio',139320.00,'CIENTO TREINTA Y NUEVE MIL TRECIENTOS VEINTE PESOS','Transferencia Bancaria','Anulado',12,NULL,NULL,'2026-04-02 06:11:05','si',12,'2026-04-04 01:16:44'),(3,'CE-000003','2026-04-02','SAMUEL CORREA','1000261901','pago de hosting y dominio',139320.00,'CIENTO TREINTA Y NUEVE MIL TRESCIENTOS VEINTE PESOS M/CTE','Transferencia Bancaria','Anulado',12,NULL,NULL,'2026-04-02 07:05:47','DUPLICIDAD',12,'2026-04-02 08:10:40');
/*!40000 ALTER TABLE `egresos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `egresos_detalle`
--

DROP TABLE IF EXISTS `egresos_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `egresos_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `egreso_id` int(11) NOT NULL,
  `codigo_puc` varchar(10) NOT NULL,
  `nombre_cuenta` varchar(150) NOT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `debito` decimal(15,2) DEFAULT 0.00,
  `credito` decimal(15,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `egreso_id` (`egreso_id`),
  CONSTRAINT `egresos_detalle_ibfk_1` FOREIGN KEY (`egreso_id`) REFERENCES `egresos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `egresos_detalle`
--

LOCK TABLES `egresos_detalle` WRITE;
/*!40000 ALTER TABLE `egresos_detalle` DISABLE KEYS */;
INSERT INTO `egresos_detalle` VALUES (5,1,'514525','Mantenimiento de Equipo de Computación (Hosting)','',139320.00,0.00),(6,1,'1110','Bancos','',0.00,139320.00),(7,2,'514525','Mantenimiento de Equipo de Computación (Hosting)','',139320.00,0.00),(8,2,'111005','Bancos - Moneda Nacional','',0.00,139320.00),(9,3,'514525','Mantenimiento de Equipo de Computación (Hosting)','pago de hosting y dominio',139320.00,0.00),(10,3,'111005','Bancos - Moneda Nacional','pago de hosting y dominio',0.00,139320.00);
/*!40000 ALTER TABLE `egresos_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `encuestas`
--

DROP TABLE IF EXISTS `encuestas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `encuestas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(150) NOT NULL,
  `descripcion` text NOT NULL,
  `token_url` varchar(50) NOT NULL,
  `estado` varchar(20) DEFAULT 'Activo',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `encuestas`
--

LOCK TABLES `encuestas` WRITE;
/*!40000 ALTER TABLE `encuestas` DISABLE KEYS */;
INSERT INTO `encuestas` VALUES (1,'Encuesta de Satisfacción General','Queremos saber tu opinión. Por favor califica tu experiencia trabajando con el equipo de DataSense Analytics.','CSAT-001','Activo'),(2,'Formulario de Cierre de Proyecto','¡Felicidades por finalizar esta etapa! Ayúdanos a mejorar calificando los resultados entregados y el cumplimiento de los objetivos.','CSAT-002','Activo');
/*!40000 ALTER TABLE `encuestas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `encuestas_preguntas`
--

DROP TABLE IF EXISTS `encuestas_preguntas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `encuestas_preguntas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_encuesta` int(11) NOT NULL,
  `pregunta_texto` varchar(255) NOT NULL,
  `icono` varchar(50) DEFAULT 'fa-star',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `encuestas_preguntas`
--

LOCK TABLES `encuestas_preguntas` WRITE;
/*!40000 ALTER TABLE `encuestas_preguntas` DISABLE KEYS */;
INSERT INTO `encuestas_preguntas` VALUES (1,1,'¿Cómo califica la calidad técnica de la solución?','fa-microchip'),(2,1,'¿El tiempo de respuesta cumplió sus expectativas?','fa-clock'),(3,1,'¿La atención y trato recibido fue profesional?','fa-user-tie'),(4,1,'¿La plataforma es fácil de usar?','fa-mouse-pointer'),(5,1,'¿Qué tan probable es que nos recomiende?','fa-thumbs-up'),(6,2,'¿Se cumplieron todos los objetivos del proyecto?','fa-check-double'),(7,2,'¿La documentación entregada fue clara?','fa-file-alt'),(8,2,'¿El soporte post-entrega fue efectivo?','fa-headset'),(9,2,'¿Volvería a contratar nuestros servicios?','fa-handshake'),(10,2,'Calificación general del cierre de fase.','fa-flag-checkered');
/*!40000 ALTER TABLE `encuestas_preguntas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `encuestas_respuestas`
--

DROP TABLE IF EXISTS `encuestas_respuestas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `encuestas_respuestas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_encuesta` int(11) NOT NULL,
  `tecnico_id` int(11) DEFAULT NULL,
  `ticket_id` int(11) DEFAULT NULL,
  `id_contacto` int(11) DEFAULT NULL,
  `cliente_nombre` varchar(150) NOT NULL,
  `calificacion_1` int(11) DEFAULT 5,
  `calificacion_2` int(11) DEFAULT 5,
  `calificacion_3` int(11) DEFAULT 5,
  `calificacion_4` int(11) DEFAULT 5,
  `calificacion_5` int(11) DEFAULT 5,
  `calificacion` int(11) NOT NULL,
  `comentarios` text NOT NULL,
  `fecha_respuesta` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `encuestas_respuestas`
--

LOCK TABLES `encuestas_respuestas` WRITE;
/*!40000 ALTER TABLE `encuestas_respuestas` DISABLE KEYS */;
INSERT INTO `encuestas_respuestas` VALUES (1,2,3,12,NULL,'Wilson Hernán Ocampo Flórez',5,5,5,5,5,5,'77111515','2026-04-13 22:53:44'),(2,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #2',5,5,5,5,5,5,'',NULL),(3,2,NULL,NULL,NULL,'Evaluación sin datos del cliente #3',5,5,5,5,5,5,'',NULL),(4,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #4',5,5,5,5,5,5,'',NULL),(5,2,NULL,NULL,NULL,'Evaluación sin datos del cliente #5',5,5,5,5,5,4,'',NULL),(6,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #6',5,5,5,5,5,5,'',NULL),(7,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #7',5,5,5,5,5,5,'',NULL),(8,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #8',5,5,5,5,5,5,'',NULL),(9,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #9',5,5,5,5,5,4,'',NULL),(10,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #10',5,5,5,5,5,4,'',NULL),(11,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #11',5,5,5,5,5,2,'',NULL),(12,1,NULL,NULL,NULL,'Evaluación sin datos del cliente #12',5,5,5,5,5,5,'',NULL);
/*!40000 ALTER TABLE `encuestas_respuestas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `encuestas_resultados_detalle`
--

DROP TABLE IF EXISTS `encuestas_resultados_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `encuestas_resultados_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_respuesta_maestra` int(11) NOT NULL,
  `id_pregunta` int(11) NOT NULL,
  `valor_calificacion` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `encuestas_resultados_detalle`
--

LOCK TABLES `encuestas_resultados_detalle` WRITE;
/*!40000 ALTER TABLE `encuestas_resultados_detalle` DISABLE KEYS */;
INSERT INTO `encuestas_resultados_detalle` VALUES (1,1,6,5),(2,1,7,5),(3,1,8,4),(4,1,9,4),(5,1,10,4),(6,2,1,5),(7,2,2,4),(8,2,3,5),(9,2,4,5),(10,2,5,5),(11,3,6,5),(12,3,7,5),(13,3,8,5),(14,3,9,5),(15,3,10,5),(16,4,1,5),(17,4,2,5),(18,4,3,5),(19,4,4,5),(20,4,5,5),(21,5,6,3),(22,5,7,5),(23,5,8,5),(24,5,9,4),(25,5,10,4),(26,6,1,5),(27,6,2,5),(28,6,3,5),(29,6,4,5),(30,6,5,5),(31,7,1,5),(32,7,2,5),(33,7,3,5),(34,7,4,5),(35,7,5,5),(36,8,1,5),(37,8,2,5),(38,8,3,5),(39,8,4,5),(40,8,5,5),(41,9,1,4),(42,9,2,4),(43,9,3,3),(44,9,4,4),(45,9,5,3),(46,10,1,4),(47,10,2,5),(48,10,3,5),(49,10,4,2),(50,10,5,3),(51,11,1,3),(52,11,2,3),(53,11,3,2),(54,11,4,1),(55,11,5,1),(56,12,1,5),(57,12,2,5),(58,12,3,5),(59,12,4,5),(60,12,5,5);
/*!40000 ALTER TABLE `encuestas_resultados_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `estados_ticket`
--

DROP TABLE IF EXISTS `estados_ticket`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `estados_ticket` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  `comportamiento` varchar(20) NOT NULL COMMENT 'Activo, Pausa, Cerrado',
  `clase_color` varchar(50) NOT NULL COMMENT 'Colores Bootstrap',
  `activo` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `estados_ticket`
--

LOCK TABLES `estados_ticket` WRITE;
/*!40000 ALTER TABLE `estados_ticket` DISABLE KEYS */;
INSERT INTO `estados_ticket` VALUES (1,'Registrado','Abierto','bg-info text-dark',1),(2,'Asignado','Abierto','bg-secondary text-white',1),(3,'En Progreso','En Proceso','bg-primary text-white',1),(4,'En Espera','Pausa','bg-warning text-dark',1),(5,'Resuelto','Solucionado','bg-success text-white',1),(6,'Cerrado - Exitoso','Cerrado','bg-dark text-white',1),(7,'Cerrado - Inactividad','Cerrado','bg-dark text-white',1),(8,'Cerrado - Falta de información','Cerrado','bg-dark text-white',1);
/*!40000 ALTER TABLE `estados_ticket` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `extracto_movimientos`
--

DROP TABLE IF EXISTS `extracto_movimientos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `extracto_movimientos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conciliacion_id` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `valor` decimal(14,2) NOT NULL,
  `detalle_id` int(11) DEFAULT NULL,
  `conciliado_por` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_extracto_detalle` (`detalle_id`),
  KEY `idx_extracto_conciliacion` (`conciliacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `extracto_movimientos`
--

LOCK TABLES `extracto_movimientos` WRITE;
/*!40000 ALTER TABLE `extracto_movimientos` DISABLE KEYS */;
/*!40000 ALTER TABLE `extracto_movimientos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facturas`
--

DROP TABLE IF EXISTS `facturas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `facturas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `id_servicio` int(11) DEFAULT NULL,
  `numero_factura` varchar(50) NOT NULL,
  `servicio_contratado` varchar(255) NOT NULL,
  `fecha_emision` date NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `subtotal` decimal(15,2) DEFAULT 0.00,
  `porcentaje_iva` decimal(5,2) DEFAULT 0.00,
  `monto_total` decimal(15,2) NOT NULL,
  `estado` enum('Pendiente','Pagado','Vencido','En Proceso') DEFAULT 'Pendiente',
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `visto` tinyint(1) DEFAULT 0,
  `motivo_anulacion` varchar(255) DEFAULT NULL,
  `anulada_por` int(11) DEFAULT NULL,
  `fecha_anulacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `numero_factura` (`numero_factura`),
  KEY `fk_facturas_usuarios` (`id_usuario`),
  CONSTRAINT `fk_facturas_usuarios` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facturas`
--

LOCK TABLES `facturas` WRITE;
/*!40000 ALTER TABLE `facturas` DISABLE KEYS */;
INSERT INTO `facturas` VALUES (1,16,NULL,'FAC-2026-001','Dashboard Comercial','2026-01-10','2026-01-25',4500000.00,19.00,5355000.00,'Pagado','2026-03-10 20:52:52',0,NULL,NULL,NULL),(2,12,NULL,'FAC-2026-002','Soporte Mensual BI - Febrero','2026-02-10','2026-02-25',800000.00,19.00,952000.00,'Pagado','2026-03-10 20:52:52',0,NULL,NULL,NULL),(3,2,NULL,'FAC-2026-003','Piloto Analítica Datos Públicos','2026-01-15','2026-01-30',12500000.00,19.00,14875000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(4,2,NULL,'FAC-2026-004','Auditoría de Datos Municipales','2026-03-01','2026-03-15',5000.00,19.00,10000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(5,2,NULL,'FAC-2026-005','Modelo Predictivo Inventarios','2026-01-20','2026-02-04',10000.00,19.00,12000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(6,3,NULL,'FAC-2026-006','Mantenimiento Modelo Enero','2026-02-05','2026-02-20',1200000.00,19.00,1428000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(7,4,NULL,'FAC-2026-007','Auditoría Estratégica de Datos','2026-02-01','2026-02-15',15000000.00,19.00,17850000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(8,4,NULL,'FAC-2026-008','Consultoría de Seguridad','2026-03-05','2026-03-20',5000000.00,19.00,5950000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(9,5,NULL,'FAC-2026-009','Optimización IA - Fase Inicial','2026-01-25','2026-02-09',18000000.00,19.00,21420000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(10,5,NULL,'FAC-2026-010','Implementación IA en Cajas','2026-03-02','2026-03-17',12000000.00,19.00,14280000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(11,6,NULL,'FAC-2026-011','Análisis Sensores Pozo 1','2026-02-15','2026-03-02',22000000.00,19.00,26180000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(12,8,NULL,'FAC-2026-012','Licenciamiento Software Q1','2026-03-01','2026-03-15',6500000.00,19.00,7735000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(13,8,NULL,'FAC-2026-013','Migración Reportes BI','2026-01-18','2026-02-02',9500000.00,19.00,11305000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(14,7,NULL,'FAC-2026-014','Tableros de Control Directivo','2026-02-28','2026-03-14',7200000.00,19.00,8568000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(15,4,NULL,'FAC-2026-015','Análisis de Rotación PowerBI','2026-02-20','2026-02-20',3500000.00,19.00,4165000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(16,8,NULL,'FAC-2026-016','Soporte Plataforma RRHH','2026-03-09','2026-03-24',900000.00,19.00,1071000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(17,10,NULL,'FAC-2026-017','Revisión y Limpieza de BD','2026-02-25','2026-03-12',1500000.00,19.00,1785000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(18,10,NULL,'FAC-2026-018','Creación de Dashboard','2026-03-10','2026-03-25',2800000.00,19.00,3332000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(19,7,NULL,'FAC-2026-019','Extracción Datos Clínicos','2026-03-05','2026-03-20',4200000.00,19.00,4998000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(20,7,NULL,'FAC-2026-020','Pago Adelanto Consultoría','2026-03-08','2026-03-23',2000000.00,19.00,2380000.00,'Pagado','2026-03-10 20:52:52',1,NULL,NULL,NULL),(21,10,NULL,'FAC-2026-021','pago de servicios','2026-03-12','2026-03-26',200000.00,19.00,238000.00,'Pagado','2026-03-12 20:44:01',1,NULL,NULL,NULL),(25,10,NULL,'FAC-2026-022','Business Intelligence','2026-03-16','2026-03-19',200000.00,19.00,238000.00,'Pagado','2026-03-16 02:08:33',1,NULL,NULL,NULL),(27,5,16,'FAC-2026-023','Integración de APIs Corporativas','2026-03-16','2026-04-10',4800000.00,19.00,5712000.00,'Pagado','2026-03-16 02:21:05',1,NULL,NULL,NULL);
/*!40000 ALTER TABLE `facturas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingresos`
--

DROP TABLE IF EXISTS `ingresos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingresos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `factura_id` int(11) DEFAULT NULL,
  `numero_ingreso` varchar(20) NOT NULL,
  `cliente` varchar(150) NOT NULL,
  `documento_cliente` varchar(50) NOT NULL,
  `fecha_ingreso` date NOT NULL,
  `concepto` text NOT NULL,
  `valor_total` decimal(15,2) NOT NULL,
  `valor_letras` varchar(255) NOT NULL,
  `metodo_pago` varchar(50) NOT NULL,
  `estado` enum('Pendiente','Aprobado','Anulado') DEFAULT 'Pendiente',
  `preparado_por` int(11) DEFAULT NULL,
  `revisado_por` int(11) DEFAULT NULL,
  `fecha_aprobacion` datetime DEFAULT NULL,
  `motivo_anulacion` text DEFAULT NULL,
  `anulado_por` int(11) DEFAULT NULL,
  `fecha_anulacion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingresos`
--

LOCK TABLES `ingresos` WRITE;
/*!40000 ALTER TABLE `ingresos` DISABLE KEYS */;
INSERT INTO `ingresos` VALUES (3,1,'CI-000001','Bibiana Vargas Cardona (Métricas y Datos S.A.S.)','1056574956','2026-04-02','Recaudo de Factura FAC-2026-001 por concepto de Dashboard Comercial (Fase 1)',5355000.00,'CINCO MILLONES TRESCIENTOS CINCUENTA Y CINCO MIL  PESOS M/CTE','Transferencia Bancaria','Aprobado',12,6,'2026-04-02 02:43:37',NULL,NULL,NULL),(4,1,'CI-000002','Bibiana Vargas Cardona (Métricas y Datos S.A.S.)','1056574956','2026-04-02','Recaudo de Factura FAC-2026-001 por concepto de Dashboard Comercial',5355000.00,'CINCO MILLONES TRESCIENTOS CINCUENTA Y CINCO MIL  PESOS M/CTE','Transferencia Bancaria','Pendiente',12,NULL,NULL,NULL,NULL,NULL),(5,1,'CI-000003','Bibiana Vargas Cardona (Métricas y Datos S.A.S.)','1056574956','2026-04-02','Recaudo de Factura FAC-2026-001 por concepto de Dashboard Comercial',5355000.00,'CINCO MILLONES TRESCIENTOS CINCUENTA Y CINCO MIL  PESOS M/CTE','Transferencia Bancaria','Anulado',12,NULL,NULL,'error',12,'2026-04-03 01:56:33');
/*!40000 ALTER TABLE `ingresos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingresos_detalle`
--

DROP TABLE IF EXISTS `ingresos_detalle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingresos_detalle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ingreso_id` int(11) NOT NULL,
  `codigo_puc` varchar(20) NOT NULL,
  `nombre_cuenta` varchar(150) NOT NULL,
  `detalle` varchar(255) DEFAULT NULL,
  `debito` decimal(15,2) DEFAULT 0.00,
  `credito` decimal(15,2) DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingresos_detalle`
--

LOCK TABLES `ingresos_detalle` WRITE;
/*!40000 ALTER TABLE `ingresos_detalle` DISABLE KEYS */;
INSERT INTO `ingresos_detalle` VALUES (1,1,'111005','Bancos - Moneda Nacional','',5355000.00,0.00),(2,1,'130505','Clientes Nacionales','',0.00,5355000.01),(3,2,'111005','Bancos - Moneda Nacional','Recaudo de Factura FAC-2026-001 por concepto de Dashboard Comercial (Fase 1)',5355000.00,0.00),(4,2,'130505','Clientes Nacionales','',0.00,5355000.00),(5,3,'111005','Bancos - Moneda Nacional','Recaudo de Factura FAC-2026-001 por concepto de Dashboard Comercial (Fase 1)',5355000.00,0.00),(6,3,'130505','Clientes Nacionales','',0.00,5355000.00),(9,4,'111005','Bancos - Moneda Nacional','Recaudo de Factura FAC-2026-001 por concepto de Dashboard Comercial',5355000.00,0.00),(10,4,'130505','Clientes Nacionales','',0.00,5355000.00),(11,5,'130505','Clientes Nacionales','Recaudo de Factura FAC-2026-001 por concepto de Dashboard Comercial',0.00,5355000.00),(12,5,'111005','Bancos - Moneda Nacional','',5355000.00,0.00);
/*!40000 ALTER TABLE `ingresos_detalle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `itsm_configuracion`
--

DROP TABLE IF EXISTS `itsm_configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `itsm_configuracion` (
  `clave` varchar(40) NOT NULL,
  `valor` varchar(100) NOT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `itsm_configuracion`
--

LOCK TABLES `itsm_configuracion` WRITE;
/*!40000 ALTER TABLE `itsm_configuracion` DISABLE KEYS */;
INSERT INTO `itsm_configuracion` VALUES ('almuerzo_fin',''),('almuerzo_inicio',''),('asignacion_automatica','1'),('cierre_automatico_dias','3'),('dias_habiles','1,2,3,4,5'),('festivos_colombia','1'),('horas_sin_gestion','8'),('hora_fin','17:00'),('hora_inicio','08:00'),('meta_cumplimiento','95'),('reapertura_dias','5'),('umbral_atencion','50'),('umbral_critico','90'),('umbral_riesgo','75');
/*!40000 ALTER TABLE `itsm_configuracion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `itsm_especialistas`
--

DROP TABLE IF EXISTS `itsm_especialistas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `itsm_especialistas` (
  `usuario_id` int(11) NOT NULL,
  `nivel` tinyint(4) NOT NULL DEFAULT 1,
  `grupos` varchar(500) NOT NULL DEFAULT '',
  `capacidad` int(11) NOT NULL DEFAULT 20,
  `recibe_casos` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `itsm_especialistas`
--

LOCK TABLES `itsm_especialistas` WRITE;
/*!40000 ALTER TABLE `itsm_especialistas` DISABLE KEYS */;
INSERT INTO `itsm_especialistas` VALUES (1,3,'',20,0),(3,3,'Ingeniería de Datos|Infraestructura Cloud|Desarrollo Backend',20,1),(12,3,'',20,0),(13,2,'Soporte BI|Desarrollo Frontend',20,1),(15,2,'Gobierno y calidad de datos|Científico de Datos|Administración',20,1);
/*!40000 ALTER TABLE `itsm_especialistas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `modulos_sistema`
--

DROP TABLE IF EXISTS `modulos_sistema`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `modulos_sistema` (
  `id_modulo` varchar(50) NOT NULL,
  `etiqueta` varchar(100) NOT NULL,
  `icono` varchar(50) NOT NULL,
  `orden` int(11) DEFAULT 0,
  PRIMARY KEY (`id_modulo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `modulos_sistema`
--

LOCK TABLES `modulos_sistema` WRITE;
/*!40000 ALTER TABLE `modulos_sistema` DISABLE KEYS */;
INSERT INTO `modulos_sistema` VALUES ('balance_general','Balance General','fa-balance-scale-right',13),('catalogo','Catálogo Servicios','fa-boxes',17),('chats','Historial SenseBot','fa-robot',3),('cierre_contable','Cierre Contable','fa-lock',25),('clientes','Gestión Clientes','fa-users-cog',4),('comprobante_egreso','Comprobantes Egreso','fa-money-check-alt',11),('comprobante_ingreso','Recibos de Caja','fa-hand-holding-usd',10),('conciliacion_bancaria','Conciliación Bancaria','fa-university',23),('cuentas_por_pagar','Cuentas por Pagar','fa-file-invoice',22),('dashboard','Dashboard','fa-chart-line',1),('encuestas','Encuestas NPS','fa-poll-h',18),('equipo','Mi Equipo','fa-user-tie',19),('estado_resultados','Estado Resultados','fa-chart-pie',12),('facturacion','Gestión Facturación','fa-file-invoice-dollar',8),('gestion_it','Gestión IT','fa-laptop-code',7),('impuestos','Impuestos y Retenciones','fa-percent',24),('libro_auxiliar','Libro Auxiliar NIIF','fa-book-open',15),('libro_mayor','Libro Mayor','fa-book-reader',14),('mensajes','Mensajes Web','fa-envelope-open-text',2),('mis_facturas','Mis Facturas','fa-receipt',9),('mis_pedidos','Mis Pedidos','fa-bag-shopping',30),('mi_resumen','Mi Resumen','fa-chart-pie',2),('notas_factura','Notas Crédito y Débito','fa-file-circle-minus',21),('nota_contable','Notas Contables','fa-file-signature',20),('plantillas','Plantillas Auto','fa-magic',16),('pqrsd','PQRSD Ciudadano','fa-scale-balanced',31),('reporte_desempeno','Desempeño y Bonos','fa-ranking-star',33),('reporte_ejecutivo','Reporte Ejecutivo TIC','fa-medal',28),('reporte_pqrsd','Reporte PQRSD','fa-file-contract',34),('reuniones','Salas de Reunión','fa-video',26),('roles','Seguridad y Roles','fa-user-lock',20),('soporte','Centro de Soporte','fa-headset',6),('supervisor_it','Supervisión y Cargas','fa-eye',5),('supervisor_pqrsd','Supervisión PQRSD','fa-user-shield',32),('tienda','Tienda y Pedidos','fa-store',29);
/*!40000 ALTER TABLE `modulos_sistema` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pagos_auditoria`
--

DROP TABLE IF EXISTS `pagos_auditoria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pagos_auditoria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `factura_id` int(11) NOT NULL,
  `metodo_pago` varchar(50) DEFAULT NULL,
  `referencia_externa` varchar(100) DEFAULT NULL,
  `monto` decimal(15,2) DEFAULT NULL,
  `estado_pago` enum('Exitoso','Fallido','Pendiente_Verificacion') DEFAULT 'Pendiente_Verificacion',
  `usuario_validador` int(11) DEFAULT NULL,
  `notas_seguridad` text DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `factura_id` (`factura_id`),
  CONSTRAINT `pagos_auditoria_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `facturas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pagos_auditoria`
--

LOCK TABLES `pagos_auditoria` WRITE;
/*!40000 ALTER TABLE `pagos_auditoria` DISABLE KEYS */;
/*!40000 ALTER TABLE `pagos_auditoria` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pagos_registro`
--

DROP TABLE IF EXISTS `pagos_registro`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pagos_registro` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `factura_id` int(11) NOT NULL,
  `metodo_pago` varchar(100) NOT NULL,
  `referencia_transaccion` varchar(100) DEFAULT NULL,
  `fecha_pago` datetime DEFAULT current_timestamp(),
  `monto_pagado` decimal(15,2) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `soporte_visual` varchar(255) DEFAULT NULL,
  `estado_transaccion` enum('Aprobado','Pendiente_Validacion','Rechazado') DEFAULT 'Pendiente_Validacion',
  `moneda` varchar(5) DEFAULT NULL,
  `monto_moneda` decimal(14,2) DEFAULT NULL,
  `tasa_cambio` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `referencia_transaccion` (`referencia_transaccion`),
  KEY `factura_id` (`factura_id`),
  CONSTRAINT `pagos_registro_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `facturas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pagos_registro`
--

LOCK TABLES `pagos_registro` WRITE;
/*!40000 ALTER TABLE `pagos_registro` DISABLE KEYS */;
INSERT INTO `pagos_registro` VALUES (1,5,'Mercado Pago (PSE)','152700049180','2026-03-31 21:11:01',12000.00,'Pago online automático exitoso. Ref MP: 152700049180',NULL,'Aprobado',NULL,NULL,NULL),(2,4,'Mercado Pago Checkout','152735067568','2026-03-31 21:53:54',10000.00,'Validado vía API',NULL,'Aprobado',NULL,NULL,NULL),(3,27,'Transferencia_Bancaria','FAC-2026-023','2026-03-31 23:29:50',5712000.00,'pagfada',NULL,'Aprobado',NULL,NULL,NULL),(4,25,'Transferencia_Bancaria','FAC-2026-022','2026-03-31 23:41:42',238000.00,'pago',NULL,'Aprobado',NULL,NULL,NULL),(5,15,'Transferencia_Bancaria','FAC-2026-015','2026-03-31 23:49:04',4165000.00,'prueba',NULL,'Aprobado',NULL,NULL,NULL),(6,18,'Transferencia_Bancaria','FAC-2026-018','2026-03-31 23:50:41',3332000.00,'aditoria',NULL,'Aprobado',NULL,NULL,NULL),(7,3,'Transferencia_Bancaria','FAC-2026-003','2026-03-31 23:52:34',14875000.00,'dinero',NULL,'Aprobado',NULL,NULL,NULL),(8,1,'Transferencia_Bancaria','p-2026-001','2026-03-31 23:53:21',5355000.00,'pago',NULL,'Aprobado',NULL,NULL,NULL),(9,8,'Transferencia_Bancaria','2026-008','2026-03-31 23:57:59',5950000.00,'recibido',NULL,'Aprobado',NULL,NULL,NULL),(10,12,'Transferencia_Bancaria','FAC-2026-012','2026-04-01 00:41:32',7735000.00,NULL,NULL,'Aprobado',NULL,NULL,NULL),(11,10,'Transferencia_Bancaria','FAC-2026-010','2026-04-01 00:42:53',14280000.00,NULL,NULL,'Aprobado',NULL,NULL,NULL),(12,14,'Transferencia_Bancaria','FAC-2026-014','2026-04-01 00:44:29',8568000.00,'auditoria',NULL,'Aprobado',NULL,NULL,NULL),(13,19,'Transferencia_Bancaria','FAC-2026-019','2026-04-01 00:45:23',4998000.00,'pas',NULL,'Aprobado',NULL,NULL,NULL),(14,2,'Transferencia_Bancaria','FAC-2026-002','2026-04-01 00:50:19',952000.00,'PAGP',NULL,'Aprobado',NULL,NULL,NULL),(15,7,'Transferencia_Bancaria','FAC-2026-007','2026-04-01 04:10:43',17850000.00,'FI',NULL,'Aprobado',NULL,NULL,NULL);
/*!40000 ALTER TABLE `pagos_registro` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parametros_impacto`
--

DROP TABLE IF EXISTS `parametros_impacto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parametros_impacto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_cliente` varchar(100) NOT NULL,
  `valor_tecnico` varchar(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parametros_impacto`
--

LOCK TABLES `parametros_impacto` WRITE;
/*!40000 ALTER TABLE `parametros_impacto` DISABLE KEYS */;
INSERT INTO `parametros_impacto` VALUES (1,'Solo me afecta a mí (Bajo)','Bajo'),(2,'Afecta a todo mi equipo (Medio)','Medio'),(3,'Falla general en la empresa (Alto)','Alto');
/*!40000 ALTER TABLE `parametros_impacto` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `parametros_urgencia`
--

DROP TABLE IF EXISTS `parametros_urgencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `parametros_urgencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_cliente` varchar(100) NOT NULL,
  `valor_tecnico` varchar(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `parametros_urgencia`
--

LOCK TABLES `parametros_urgencia` WRITE;
/*!40000 ALTER TABLE `parametros_urgencia` DISABLE KEYS */;
INSERT INTO `parametros_urgencia` VALUES (1,'Baja (Puedo seguir trabajando en otras cosas)','Baja'),(2,'Media (Afecta tareas importantes)','Media'),(3,'Alta (Totalmente bloqueado)','Alta');
/*!40000 ALTER TABLE `parametros_urgencia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `periodos_contables`
--

DROP TABLE IF EXISTS `periodos_contables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `periodos_contables` (
  `anio` smallint(6) NOT NULL,
  `mes` tinyint(4) NOT NULL,
  `estado` varchar(10) NOT NULL DEFAULT 'Cerrado',
  `cerrado_por` int(11) DEFAULT NULL,
  `fecha_cierre` datetime DEFAULT NULL,
  `reabierto_por` int(11) DEFAULT NULL,
  `fecha_reapertura` datetime DEFAULT NULL,
  `motivo_reapertura` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`anio`,`mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `periodos_contables`
--

LOCK TABLES `periodos_contables` WRITE;
/*!40000 ALTER TABLE `periodos_contables` DISABLE KEYS */;
/*!40000 ALTER TABLE `periodos_contables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permisos_roles`
--

DROP TABLE IF EXISTS `permisos_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permisos_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rol` enum('Admin','Soporte','Cliente','Comercial','Analista','Finanzas','Supervisor') NOT NULL,
  `modulo` varchar(50) NOT NULL,
  `acceso` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rol` (`rol`,`modulo`),
  UNIQUE KEY `rol_modulo_unique` (`rol`,`modulo`)
) ENGINE=InnoDB AUTO_INCREMENT=117 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permisos_roles`
--

LOCK TABLES `permisos_roles` WRITE;
/*!40000 ALTER TABLE `permisos_roles` DISABLE KEYS */;
INSERT INTO `permisos_roles` VALUES (1,'Admin','dashboard',1),(2,'Admin','mensajes',1),(3,'Admin','chats',1),(4,'Admin','clientes',1),(5,'Admin','supervisor_it',1),(6,'Admin','soporte',1),(7,'Admin','gestion_it',1),(8,'Admin','facturacion',1),(9,'Admin','mis_facturas',1),(10,'Admin','comprobante_ingreso',1),(11,'Admin','comprobante_egreso',1),(12,'Admin','estado_resultados',1),(13,'Admin','balance_general',1),(14,'Admin','libro_mayor',1),(15,'Admin','libro_auxiliar',1),(16,'Admin','plantillas',1),(17,'Admin','catalogo',1),(18,'Admin','encuestas',1),(19,'Admin','equipo',1),(20,'Admin','roles',1),(21,'Supervisor','dashboard',1),(22,'Supervisor','mensajes',0),(23,'Supervisor','chats',0),(24,'Supervisor','clientes',1),(25,'Supervisor','supervisor_it',1),(26,'Supervisor','soporte',1),(27,'Supervisor','encuestas',1),(28,'Supervisor','equipo',0),(29,'Soporte','dashboard',1),(30,'Soporte','chats',0),(31,'Soporte','soporte',1),(32,'Soporte','plantillas',0),(33,'Soporte','catalogo',0),(34,'Finanzas','dashboard',1),(35,'Finanzas','clientes',1),(36,'Finanzas','facturacion',1),(37,'Finanzas','comprobante_ingreso',1),(38,'Finanzas','comprobante_egreso',1),(39,'Finanzas','estado_resultados',1),(40,'Finanzas','balance_general',1),(41,'Finanzas','libro_mayor',1),(42,'Finanzas','libro_auxiliar',1),(43,'Comercial','dashboard',1),(44,'Comercial','mensajes',1),(45,'Comercial','clientes',1),(46,'Comercial','catalogo',1),(47,'Comercial','encuestas',1),(48,'Analista','dashboard',1),(49,'Analista','chats',1),(50,'Analista','clientes',1),(51,'Analista','encuestas',1),(52,'Cliente','dashboard',0),(53,'Cliente','mensajes',0),(54,'Cliente','mis_facturas',1),(59,'Cliente','soporte',1),(61,'Soporte','supervisor_it',0),(63,'Soporte','gestion_it',1),(64,'Analista','soporte',1),(65,'Finanzas','soporte',1),(72,'Finanzas','nota_contable',1),(73,'Finanzas','notas_factura',1),(74,'Finanzas','cuentas_por_pagar',1),(75,'Finanzas','conciliacion_bancaria',1),(76,'Finanzas','impuestos',1),(77,'Finanzas','cierre_contable',1),(79,'Comercial','tienda',1),(80,'Cliente','mis_pedidos',1),(81,'Admin','pqrsd',1),(82,'Soporte','pqrsd',1),(83,'Comercial','pqrsd',0),(84,'Analista','pqrsd',0),(85,'Finanzas','pqrsd',0),(86,'Cliente','pqrsd',0),(93,'Admin','supervisor_pqrsd',1),(94,'Supervisor','supervisor_pqrsd',1),(95,'Soporte','supervisor_pqrsd',0),(96,'Admin','reporte_desempeno',1),(97,'Supervisor','reporte_desempeno',1),(98,'Soporte','reporte_desempeno',0),(99,'Admin','reporte_pqrsd',1),(100,'Supervisor','reporte_pqrsd',1),(101,'Soporte','reporte_pqrsd',0),(102,'Supervisor','gestion_it',1),(103,'Supervisor','pqrsd',1),(104,'Supervisor','reporte_ejecutivo',1),(105,'Admin','reuniones',1),(106,'Supervisor','reuniones',1),(107,'Comercial','reuniones',1),(108,'Soporte','clientes',1),(109,'Comercial','chats',1),(110,'Comercial','soporte',1),(111,'Cliente','mi_resumen',1),(112,'Soporte','mi_resumen',0),(113,'Supervisor','mi_resumen',0),(114,'Analista','mi_resumen',0),(115,'Finanzas','mi_resumen',0),(116,'Comercial','mi_resumen',0);
/*!40000 ALTER TABLE `permisos_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plantillas_correo`
--

DROP TABLE IF EXISTS `plantillas_correo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plantillas_correo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(100) NOT NULL,
  `asunto` varchar(150) NOT NULL,
  `cuerpo` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plantillas_correo`
--

LOCK TABLES `plantillas_correo` WRITE;
/*!40000 ALTER TABLE `plantillas_correo` DISABLE KEYS */;
INSERT INTO `plantillas_correo` VALUES (1,'[VENTAS] 1. Primer Contacto - BI','Información de Servicios: Business Intelligence - DataSense','Gracias por tu interés en nuestras soluciones de Business Intelligence.\n\nEn DataSense transformamos tus datos en tableros de control interactivos y KPIs estratégicos para que tomes decisiones basadas en información real, no en intuición. Me encantaría mostrarte cómo podemos ayudarte específicamente en:\n\n📊 Área de enfoque: [NOMBRE_DEL_SERVICIO]\n🎯 Solución: [DESCRIPCION_DETALLADA]\n\nEntiendo que actualmente un reto importante para ustedes es [RETO_PRINCIPAL_DEL_CLIENTE].\n\n¿Tendrías 15 minutos esta semana para una breve llamada introductoria y mostrarte un demo?\n\nQuedo atento a tu confirmación para enviarte una invitación formal.'),(2,'[VENTAS] 2. Propuesta Económica - BI','Propuesta Técnica y Comercial: Business Intelligence - DataSense','De acuerdo a nuestra conversación y entendiendo las necesidades de tu empresa, he estructurado una propuesta técnica y económica a la medida para potenciar su toma de decisiones.\r\n\r\nA continuación, el resumen del alcance:\r\n\r\n🎯 Solución Propuesta: [NOMBRE_DEL_SERVICIO]\r\n📌 Entregables y Alcance: [DESCRIPCION_DETALLADA]\r\n⏱️ Tiempo de ejecución estimado: [TIEMPO_DE_ENTREGA] semanas\r\n\r\n💰 Inversión Total: $[VALOR_PROPUESTA]\r\n\r\n*Nota: Esta propuesta tiene una validez de [DIAS_VALIDEZ] días.*\r\n\r\nMe gustaría que nos reuniéramos brevemente para revisar esta propuesta a detalle, alinear expectativas y resolver cualquier duda técnica. Puedes conectarte a nuestra sala virtual en este enlace:\r\n[ENLACE_MEET]\r\n\r\nQuedo muy atento a tus comentarios para definir los siguientes pasos.'),(3,'[VENTAS] 3. Primer Contacto - Ciencia de Datos','Información de Servicios: Ciencia de Datos - DataSense','He seguido de cerca el crecimiento de [NOMBRE] y veo un gran potencial para aplicar analítica avanzada en sus procesos estratégicos.\n\nEn DataSense Analytics nos especializamos en transformar datos históricos en modelos de decisión. Me gustaría compartirte cómo nuestro enfoque en:\n\n🧠 Área de especialidad: [NOMBRE_DEL_SERVICIO]\n🔬 Enfoque técnico: [DESCRIPCION_DETALLADA]\n\nNos permite ayudar a empresas de su sector a resolver retos específicos como [RETO_PRINCIPAL_DEL_CLIENTE].\n\n¿Tendrías 15 minutos esta semana para una charla exploratoria? Sin compromisos, el objetivo es simplemente presentarnos y evaluar si nuestra tecnología puede aportar valor a sus objetivos actuales.\n\nQuedo atento a tus comentarios.'),(4,'[VENTAS] 4. Propuesta Económica - Ciencia de Datos','Propuesta Técnica: Modelamiento Predictivo - DataSense','A partir de nuestra revisión de sus requerimientos, hemos diseñado una propuesta técnica y económica de Ciencia de Datos orientada a descubrir patrones y generar valor predictivo para su organización.\r\n\r\nA continuación, el resumen del alcance estructurado:\r\n\r\n🧠 Solución Propuesta: [NOMBRE_DEL_SERVICIO]\r\n🔬 Entregables y Alcance: [DESCRIPCION_DETALLADA]\r\n⏱️ Tiempo de ejecución estimado: [TIEMPO_DE_ENTREGA] semanas\r\n\r\n💰 Inversión Total: $[VALOR_PROPUESTA]\r\n\r\n*Nota: Esta propuesta tiene una validez de [DIAS_VALIDEZ] días.*\r\n\r\n¿Te parece bien si lo revisamos en una breve reunión para afinar detalles técnicos sobre los modelos de datos? Puedes unirte directamente en el siguiente enlace:\r\n[ENLACE_MEET]\r\n\r\nQuedo muy atento a tus comentarios para avanzar al siguiente nivel analítico.'),(5,'[VENTAS] 5. Sector Público - Presentación','Soluciones de Analítica para Gestión Institucional - DataSense','Hemos recibido su solicitud orientada al Sector Público.\n\nDiseñamos soluciones analíticas enfocadas en la transparencia, el control y la evaluación de políticas públicas, garantizando el cumplimiento normativo gubernamental.\n\nQuedamos a su disposición para coordinar una mesa de trabajo con su equipo directivo.'),(6,'[VENTAS] 6. Auditoría de Datos - Diagnóstico','Propuesta: Diagnóstico y Gobierno de Datos - DataSense','Para garantizar el éxito y la escalabilidad de cualquier proyecto analítico, la calidad y estructura de su información son vitales. Un diagnóstico a tiempo previene reprocesos y asegura decisiones basadas en datos confiables.\r\n\r\nTe compartimos nuestra propuesta de auditoría diseñada para tu organización:\r\n\r\n🔍 Servicio: [NOMBRE_DEL_SERVICIO]\r\n📋 Alcance del Diagnóstico: [DESCRIPCION_DETALLADA]\r\n⏱️ Tiempo estimado de auditoría: [TIEMPO_DE_ENTREGA] semanas\r\n\r\n💰 Inversión Total: $[VALOR_PROPUESTA]\r\n\r\n*Nota: Esta propuesta tiene una validez de [DIAS_VALIDEZ] días.*\r\n\r\n¿Te gustaría que revisemos juntos los detalles de este alcance o prefieres que te enviemos el brochure completo? Puedes agendar una sesión rápida en nuestra sala virtual aquí:\r\n[ENLACE_MEET]\r\n\r\nQuedo muy atento a tus comentarios para ayudarte a dar el primer paso hacia la madurez de tus datos.'),(7,'[VENTAS] 7. Seguimiento 1 - ¿Pudiste revisar?','Seguimiento de nuestra propuesta analítica - DataSense','Espero que estés teniendo una excelente semana.\n\nTe escribo para saber si pudiste revisar la propuesta técnico-comercial que te enviamos recientemente. Entiendo que estos proyectos requieren evaluación detallada.\n\n¿Tienes alguna duda técnica o de alcance que pueda resolverte hoy?'),(8,'[VENTAS] 8. Seguimiento 2 - Último intento','¿Sigue siendo prioridad este proyecto? - DataSense','Te saludo nuevamente.\n\nComo no he tenido noticias tuyas recientemente, asumo que las prioridades de tu equipo han cambiado o el proyecto se ha pausado.\n\nSi es así, no hay problema, cerraré tu solicitud en nuestro sistema. Si aún deseas avanzar, solo responde este correo y retomamos donde lo dejamos. ¡Un saludo!'),(9,'[VENTAS] 9. Agendamiento de Demo','Enlace para nuestra reunión de consultoría - DataSense','Confirmamos nuestra reunión para conversar sobre tus necesidades de análisis de datos.\r\n\r\nEn esta sesión de 20 minutos revisaremos tus fuentes de información, los desafíos actuales y te mostraremos cómo nuestros dashboards pueden solucionar este escenario.\r\n\r\nPor favor, responde con el día y hora de tu preferencia para enviarte el enlace de la reunión'),(10,'[ONBOARDING] 1. Bienvenida a Nuevo Cliente','¡Bienvenido a DataSense Analytics!','Es un honor darte la bienvenida a DataSense.\n\nA partir de este momento, nuestro equipo técnico comenzará a trabajar en la integración de tus datos. En los próximos días recibirás un cronograma detallado con las fases del proyecto y los accesos a tu portal de cliente.\n\nEstamos listos para transformar tu información en decisiones.'),(11,'[ONBOARDING] 2. Solicitud de Credenciales','Requerimiento de acceso a fuentes de datos - DataSense','Para iniciar formalmente con el proceso de extracción y modelado, necesitamos que tu equipo de TI nos proporcione acceso a las fuentes de datos acordadas (Bases de datos, ERP, APIs, etc.).\n\nPor favor, comparte las credenciales de lectura de forma segura o indícanos con quién debemos coordinar esta integración técnica.'),(12,'[ONBOARDING] 3. Entrega de Proyecto / Cierre','Entrega Final y Cierre de Proyecto Analítico - DataSense','Nos complace informarte que hemos concluido con éxito la implementación de tu solución de datos.\n\nTodos los tableros y modelos han sido desplegados y testeados. A partir de hoy, iniciamos el periodo de soporte y garantía acordado.\n\nHa sido un placer trabajar con tu equipo. ¡Esperamos seguir impulsando tu crecimiento con analítica avanzada!'),(13,'[ONBOARDING] 4. Encuesta de Satisfacción','¿Cómo calificarías tu experiencia con DataSense?','Tu opinión es el dato más importante para nosotros.\n\nHemos finalizado la entrega de nuestro servicio y nos encantaría saber cómo fue tu experiencia trabajando con el equipo de DataSense Analytics.\n\nPor favor, tómate solo 1 minuto para calificar nuestro servicio (de 1 a 5 estrellas) ingresando al siguiente enlace seguro:\n\n👉 [ENLACE_A_LA_ENCUESTA]\n\n¡Gracias por ayudarnos a crecer y darte cada día un mejor servicio!'),(14,'[SOPORTE] 1. Ticket Recibido','Hemos recibido tu reporte de soporte - DataSense','Confirmamos la recepción de tu solicitud de soporte técnico en nuestra plataforma.\n\nSe ha generado un ticket interno y nuestro equipo de ingenieros ya está revisando la incidencia o solicitud de ajuste en tus tableros.\n\nTe daremos una actualización o solución en el menor tiempo posible.'),(15,'[SOPORTE] 2. Ticket en Análisis Complejo','Actualización sobre tu caso de soporte - DataSense','Te escribimos para actualizarte sobre el ticket reportado.\n\nNuestros especialistas están realizando una revisión profunda en las conexiones (ETL) y modelos de datos para asegurar una solución definitiva. Este proceso tomará un poco más de tiempo del habitual.\n\nAgradecemos tu paciencia mientras resolvemos la incidencia.'),(16,'[SOPORTE] 3. Solución Entregada','Caso Resuelto: Actualización de tableros - DataSense','Te informamos que los ajustes y correcciones solicitados en tu plataforma analítica han sido aplicados con éxito.\n\nPor favor, verifica en tu Dashboard que todo funcione según lo esperado. Si confirmas que está solucionado, procederemos a cerrar el ticket en nuestro sistema.'),(17,'[SOPORTE] 4. Mantenimiento Programado','Aviso: Mantenimiento programado de plataforma - DataSense','Te informamos que realizaremos una actualización programada en nuestra infraestructura de servidores y motores de bases de datos para mejorar el rendimiento de tus tableros.\r\n\r\nDetalles de la ventana de mantenimiento:\r\n📅 Fecha: [FECHA_MANTENIMIENTO]\r\n⏰ Horario: De [HORA_INICIO] a [HORA_FIN] (Hora Colombia)\r\n\r\n¿Qué impacto tendrá?\r\nDurante este periodo, es posible que experimentes intermitencias o desconexiones temporales al intentar cargar tus reportes y paneles de análisis. \r\n\r\nTrabajamos continuamente para garantizar la seguridad, estabilidad y velocidad de tu información. Agradecemos tu comprensión.\r\n\r\nSi tienes alguna duda o un requerimiento crítico durante este periodo, no dudes en responder a este correo.'),(18,'[ADMINISTRACIÓN] 1. Envío de Factura','Factura Electrónica de Servicios - DataSense Analytics','Adjunto a este correo enviamos tu factura electrónica correspondiente a los servicios prestados por DataSense Analytics.\n\n📄 RESUMEN DE LA FACTURA:\n- N° Factura: [NUMERO_FACTURA]\n- Fecha de Emisión: [FECHA_EMISION]\n- Fecha de Vencimiento: [FECHA_VENCIMIENTO]\n- Estado actual: [ESTADO_DEL_PAGO]\n\n💼 DETALLE DEL SERVICIO:\n- Descripción: [DESCRIPCION_DEL_SERVICIO]\n- Total: $[TOTAL_A_PAGAR]\n\nPara visualizar o descargar el PDF completo de tu factura, por favor revisa el archivo adjunto o ingresa a nuestro portal de clientes.\n\nQuedamos a tu entera disposición para cualquier duda o aclaración.'),(19,'[ADMINISTRACIÓN] 2. Recordatorio de Pago','Recordatorio Amistoso: Factura próxima a vencer - DataSense','Esperamos que te encuentres muy bien.\n\nTe escribimos como un recordatorio amistoso de que tu factura de servicios de DataSense está próxima a su fecha de vencimiento.\n\nSi ya realizaste el pago, por favor omite este mensaje. De lo contrario, quedamos atentos al comprobante para conciliarlo en nuestro sistema.'),(20,'[ADMINISTRACIÓN] 3. Aviso de Suspensión','Aviso Importante: Suspensión de servicios por falta de pago','Nos comunicamos contigo ya que, a la fecha, no hemos registrado el pago de tu última factura vencida.\n\nPara evitar la suspensión automática del acceso a tus tableros de Business Intelligence y servicios en la nube, te solicitamos amablemente regularizar tu estado de cuenta en las próximas 24 horas.\n\nQuedamos a tu disposición si necesitas ayuda con el proceso de pago.'),(21,'[ADMINISTRACIÓN] 4. Pago Recibido','Confirmación de pago recibido - DataSense Analytics','Hemos recibido y procesado correctamente el pago de tu factura. Tu estado de cuenta se encuentra al día.\n\nTe agradecemos por tu puntualidad y confianza en DataSense.\n\n¡Seguimos trabajando juntos!'),(22,'[REUNIONES] 1. Confirmación de Reunión','Confirmación de nuestra reunión - DataSense Analytics','Excelente, confirmo nuestra reunión en la agenda.\n\nNos conectaremos el día [FECHA] a las [HORA] para conversar sobre tus requerimientos analíticos.\n\nAquí tienes el enlace de acceso a la videollamada:\n[ENLACE_MEET_O_TEAMS]\n\n¡Nos vemos pronto!'),(23,'[REUNIONES] 2. Recordatorio (Mismo Día)','Recordatorio: Nuestra reunión es hoy - DataSense','Espero que estés teniendo un excelente día.\n\nTe escribo para recordarte nuestra videollamada programada para hoy a las [HORA]. \n\nTe dejo nuevamente el enlace de acceso por aquí para mayor comodidad:\n[ENLACE_MEET_O_TEAMS]\n\nQuedo a tu disposición y nos vemos en un rato.'),(24,'[REUNIONES] 3. Solicitud de Reprogramación','Reprogramación de nuestra sesión - DataSense Analytics','Espero que te encuentres muy bien.\n\nPor un cruce de agenda de último minuto con nuestro equipo técnico, me veo en la necesidad de solicitarte amablemente reprogramar nuestra sesión de hoy.\n\n¿Tendrás disponibilidad el día [NUEVA_FECHA] en la mañana o en la tarde? Pido disculpas por el inconveniente y agradezco tu comprensión.'),(25,'[REUNIONES] 4. Inasistencia (No-Show)','¿Todo bien? Reprogramemos nuestra reunión - DataSense','Estuve conectado hace un momento en la sala virtual para nuestra reunión, pero imagino que surgió algún imprevisto de último minuto en tu agenda.\n\nNo te preocupes, entiendo perfectamente que estas cosas pasan.\n\nCuando tengas un espacio más tranquilo, por favor indícame qué día y hora te viene mejor para reagendar nuestra sesión. ¡Quedo atento!'),(26,'[REUNIONES] 5. Minuta / Resumen Post-Reunión','Resumen y Próximos Pasos - Reunión DataSense','Espero que te encuentres muy bien. \r\n\r\nAgradezco el tiempo y la disposición durante nuestra sesión de trabajo el día de hoy. Con el objetivo de mantener trazabilidad sobre nuestro proyecto, comparto la minuta correspondiente:\r\n\r\n📌 ACUERDOS ALCANZADOS:\r\n[RESUMEN_ACUERDOS]\r\n\r\nAgradezco de antemano tu confirmación de recibido. Si existe algún comentario adicional sobre estos puntos, no dudes en hacérmelo saber.'),(27,'[REUNIONES] 6. Kick-off (Inicio de Proyecto)','Invitación: Kick-off Oficial del Proyecto - DataSense','¡Ha llegado el momento de arrancar!\n\nTe invitamos formalmente a la reunión de \"Kick-off\" (Lanzamiento) de nuestro proyecto de analítica de datos. En esta sesión presentaremos al equipo técnico asignado, revisaremos el cronograma de trabajo y definiremos los canales de comunicación.\n\nPor favor, confírmanos tu disponibilidad para este [FECHA] a las [HORA] y te enviaremos la citación formal con el enlace.\n\n¡Estamos muy emocionados de comenzar!'),(28,'[VENTAS] 10. Primer Contacto - Sector Público','Modernización y Analítica de Datos Institucional','Es un gusto saludarle. En DataSense Analytics comprendemos los desafíos de modernización y transparencia que enfrentan las instituciones del sector público hoy en día.\n\nNuestra metodología permite optimizar el uso de recursos y mejorar el impacto ciudadano mediante datos. Nos gustaría presentarles nuestra propuesta para:\n\n🏛️ Proyecto: [NOMBRE_DEL_SERVICIO]\n📋 Alcance Técnico: [DESCRIPCION_DETALLADA]\n\nEstamos preparados para apoyarles abordando el reto de [RETO_PRINCIPAL_DEL_CLIENTE] con tecnología de vanguardia.\n\n¿Podríamos agendar una breve sesión virtual para presentarles casos de éxito similares?\n\nQuedamos a su entera disposición y atentos a su respuesta.'),(29,'[VENTAS] 11. Propuesta Económica - Sector Público','Propuesta Técnica y Económica - Análisis de Datos','Adjunto a la presente, compartimos la estructura de la propuesta económica y técnica para el proyecto de análisis de datos solicitado.\n\nBasados en los requerimientos institucionales, el esquema de trabajo es el siguiente:\n\n🛠️ Servicio: [NOMBRE_DEL_SERVICIO]\n📝 Detalle del Alcance: [DESCRIPCION_DETALLADA]\n⏱️ Tiempo de entrega estimado: [TIEMPO_DE_ENTREGA] semanas\n\n💰 Inversión Total: $[VALOR_PROPUESTA]\n\n*Nota: Esta propuesta cumple con los estándares de contratación pública y tiene una validez de [DIAS_VALIDEZ] días.*\n\n¿Les parece bien si realizamos una sesión de aclaración técnica sobre esta propuesta económica?\n[ENLACE_MEET]\n\nQuedamos atentos a sus instrucciones para proceder con los trámites correspondientes.');
/*!40000 ALTER TABLE `plantillas_correo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pqrsd`
--

DROP TABLE IF EXISTS `pqrsd`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pqrsd` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `radicado` varchar(20) NOT NULL,
  `token_consulta` varchar(32) NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `tema` varchar(60) DEFAULT NULL,
  `anonima` tinyint(1) NOT NULL DEFAULT 0,
  `prioritaria` tinyint(1) NOT NULL DEFAULT 0,
  `motivo_prioritaria` varchar(120) DEFAULT NULL,
  `nombre` varchar(150) DEFAULT NULL,
  `tipo_documento` varchar(20) DEFAULT NULL,
  `documento` varchar(30) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `direccion` varchar(200) DEFAULT NULL,
  `ciudad` varchar(80) DEFAULT NULL,
  `medio_respuesta` varchar(20) NOT NULL DEFAULT 'Correo',
  `asunto` varchar(180) NOT NULL,
  `descripcion` text NOT NULL,
  `adjunto` varchar(255) DEFAULT NULL,
  `estado` varchar(30) NOT NULL DEFAULT 'Radicada',
  `usuario_id` int(11) DEFAULT NULL,
  `asignado_a` int(11) DEFAULT NULL,
  `dias_termino` int(11) NOT NULL DEFAULT 15,
  `fecha_registro` datetime NOT NULL,
  `fecha_limite` datetime NOT NULL,
  `fecha_prorroga` datetime DEFAULT NULL,
  `prorroga_hasta` datetime DEFAULT NULL,
  `prorroga_motivo` varchar(300) DEFAULT NULL,
  `fecha_requerimiento` datetime DEFAULT NULL,
  `requerimiento_detalle` varchar(300) DEFAULT NULL,
  `desiste_el` datetime DEFAULT NULL,
  `trasladada_a` varchar(150) DEFAULT NULL,
  `fecha_traslado` datetime DEFAULT NULL,
  `traslado_detalle` varchar(300) DEFAULT NULL,
  `fecha_respuesta` datetime DEFAULT NULL,
  `respuesta` text DEFAULT NULL,
  `respondido_por` int(11) DEFAULT NULL,
  `fecha_cierre` datetime DEFAULT NULL,
  `cerrado_por` int(11) DEFAULT NULL,
  `motivo_cierre` varchar(200) DEFAULT NULL,
  `calificacion` int(11) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pqrsd_radicado` (`radicado`),
  KEY `idx_pqrsd_estado` (`estado`),
  KEY `idx_pqrsd_token` (`token_consulta`),
  KEY `idx_pqrsd_asignado` (`asignado_a`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pqrsd`
--

LOCK TABLES `pqrsd` WRITE;
/*!40000 ALTER TABLE `pqrsd` DISABLE KEYS */;
INSERT INTO `pqrsd` VALUES (1,'PQR-2026-0001','a1f3c9d20b7e4416','Reclamo',NULL,0,0,NULL,'Silvia Hurtado Peña','CC','1032455932','silvia.hurtado.pena@example.com','3225559137','Calle 10 # 20-30','Bogotá','Correo','El tablero de ventas dejó de actualizar los datos del mes','Desde hace tres semanas el tablero de ventas no actualiza la información y el comité gerencial está tomando decisiones con cifras viejas. Necesitamos una solución de fondo y saber por qué ocurrió.',NULL,'Cerrada',NULL,3,15,'2026-08-11 12:14:18','2026-09-01 12:14:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-24 12:14:18','Revisamos el proceso de carga: el conector con el ERP perdió las credenciales tras un cambio de contraseña. Ya quedó restablecido, se recargaron los datos del mes y dejamos una alerta automática para avisar si vuelve a fallar.',3,'2026-08-25 12:14:18',3,NULL,NULL,'127.0.0.1'),(2,'PQR-2026-0002','b2e4d8a71c6f5527','Solicitud de información',NULL,0,0,NULL,'Mario Bedoya Delgado','CC','1046732982','ministerio.de.planeacion.demo@example.com','3255550076','Calle 10 # 20-30','Bogotá','Correo','Solicito copia del acta de la reunión de seguimiento','En calidad de supervisor del contrato, solicito copia del acta de la reunión de seguimiento realizada el mes pasado, con la firma de los asistentes.',NULL,'Cerrada',NULL,6,10,'2026-08-19 12:14:18','2026-09-02 12:14:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-08-26 12:14:18','Adjuntamos el acta firmada de la reunión de seguimiento junto con la lista de asistentes. Queda también disponible en el portal de clientes, en la sección de Salas de Reuniones.',6,'2026-08-26 12:14:18',6,NULL,NULL,'127.0.0.1'),(3,'PQR-2026-0003','c3d5e7b82a9f6638','Petición',NULL,0,0,NULL,'Estefanía Quintero Acevedo','CC','1005623079','almacenes.la.economia@example.com','3215553761','Calle 10 # 20-30','Cali','Correo','Solicito el detalle de los servicios facturados en el semestre','Para la auditoría interna necesitamos el detalle de los servicios facturados entre enero y junio, con el número de cada factura, su fecha y su estado de pago.',NULL,'Cerrada',NULL,6,15,'2026-08-23 12:14:18','2026-09-13 12:14:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-04 12:14:18','Enviamos el detalle de los servicios facturados en el primer semestre, con número de factura, fecha, valor y estado de pago. Cualquier aclaración adicional la atendemos por este mismo canal.',6,'2026-09-04 12:14:18',6,NULL,NULL,'127.0.0.1'),(4,'PQR-2026-0004','d4c6f9a13b8e7749','Queja',NULL,0,0,NULL,'Federico Sanabria Navarro','CC','1064325979','ferreteria.la.llave.s.a.s@example.com','3135550360','Calle 10 # 20-30','Medellín','Correo','No recibí respuesta a mis llamadas durante dos días','Llamé tres veces a la línea de soporte por un problema con el acceso al tablero y nadie me devolvió la llamada. La atención debe mejorar.',NULL,'Respondida',NULL,3,15,'2026-09-08 12:14:18','2026-09-29 12:14:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 12:14:18','Le ofrecemos disculpas. Revisamos las llamadas del periodo y confirmamos que dos quedaron sin devolución por un error en el turno de la mesa. Reforzamos el procedimiento y su caso quedó atendido el mismo día que nos escribió.',3,NULL,NULL,NULL,NULL,'127.0.0.1'),(5,'PQR-2026-0005','e5b7a0c24d9f8851','Reclamo',NULL,0,0,NULL,'Elena Ospina','CC','1015947738','banco.cordillera@example.com','3175550627','Calle 10 # 20-30','Bogotá','Correo','La factura del mes llegó con un servicio que no contratamos','En la última factura aparece un servicio de mantenimiento que no está en nuestro contrato. Solicito la corrección y la nota crédito correspondiente.',NULL,'En trámite',NULL,6,15,'2026-08-29 12:14:18','2026-09-19 12:14:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'127.0.0.1'),(6,'PQR-2026-0006','f6a8b1d35e0c9962','Petición',NULL,0,0,NULL,'Wilson Santiago Villegas','CC','1057812160','alcaldia.de.villa.esperanza@example.com','3255559718','Calle 10 # 20-30','Medellín','Correo','Solicito una capacitación adicional para el equipo nuevo','Ingresaron cuatro funcionarios nuevos a la secretaría y necesitamos una sesión de capacitación sobre el uso de los tableros entregados.',NULL,'En trámite',NULL,3,15,'2026-09-07 12:14:18','2026-09-22 12:14:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'127.0.0.1'),(7,'PQR-2026-0007','a7b9c2e46f1d0073','Denuncia',NULL,1,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Ninguno','Posible uso indebido de información de clientes','Quiero reportar de forma anónima que se estarían compartiendo listados de clientes con terceros ajenos a la compañía. Ocurrió durante las últimas dos semanas.',NULL,'En trámite',NULL,1,15,'2026-09-14 12:14:18','2026-10-05 12:14:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'127.0.0.1'),(8,'PQR-2026-0008','b8c0d3f57a2e1184','Sugerencia',NULL,0,0,NULL,'Graciela Rivas Franco','CC','1087660474','fundacion.semillas.de.futuro@example.com','3215557405','Calle 10 # 20-30','Barranquilla','Correo','Sería útil recibir un resumen semanal por correo','Propongo que la plataforma envíe cada lunes un resumen con los indicadores principales, así no hay que entrar a revisarlos uno por uno.',NULL,'En trámite',NULL,13,15,'2026-09-18 12:14:18','2026-10-09 12:14:18',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'127.0.0.1');
/*!40000 ALTER TABLE `pqrsd` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pqrsd_configuracion`
--

DROP TABLE IF EXISTS `pqrsd_configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pqrsd_configuracion` (
  `clave` varchar(50) NOT NULL,
  `valor` varchar(200) NOT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pqrsd_configuracion`
--

LOCK TABLES `pqrsd_configuracion` WRITE;
/*!40000 ALTER TABLE `pqrsd_configuracion` DISABLE KEYS */;
INSERT INTO `pqrsd_configuracion` VALUES ('alerta_cumplimiento','85'),('asignacion_automatica','1'),('bono_cumplimiento','100'),('meta_cumplimiento','95'),('minimo_para_medir','3');
/*!40000 ALTER TABLE `pqrsd_configuracion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pqrsd_historial`
--

DROP TABLE IF EXISTS `pqrsd_historial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pqrsd_historial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pqrsd_id` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `evento` varchar(40) NOT NULL,
  `detalle` varchar(500) DEFAULT NULL,
  `estado_anterior` varchar(30) DEFAULT NULL,
  `estado_nuevo` varchar(30) DEFAULT NULL,
  `publico` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_pqrsd_historial` (`pqrsd_id`,`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pqrsd_historial`
--

LOCK TABLES `pqrsd_historial` WRITE;
/*!40000 ALTER TABLE `pqrsd_historial` DISABLE KEYS */;
INSERT INTO `pqrsd_historial` VALUES (1,1,'2026-08-11 12:14:18',NULL,'Radicación','Radicada por el canal web como Reclamo. Término legal: 15 días hábiles.',NULL,'Radicada',1),(2,2,'2026-08-19 12:14:18',NULL,'Radicación','Radicada por el canal web como Solicitud de información. Término legal: 10 días hábiles.',NULL,'Radicada',1),(3,3,'2026-08-23 12:14:18',NULL,'Radicación','Radicada por el canal web como Petición. Término legal: 15 días hábiles.',NULL,'Radicada',1),(4,4,'2026-09-08 12:14:18',NULL,'Radicación','Radicada por el canal web como Queja. Término legal: 15 días hábiles.',NULL,'Radicada',1),(5,5,'2026-08-29 12:14:18',NULL,'Radicación','Radicada por el canal web como Reclamo. Término legal: 15 días hábiles.',NULL,'Radicada',1),(6,6,'2026-09-07 12:14:18',NULL,'Radicación','Radicada por el canal web como Petición. Término legal: 15 días hábiles.',NULL,'Radicada',1),(7,7,'2026-09-14 12:14:18',NULL,'Radicación','Radicada por el canal web como Denuncia. Término legal: 15 días hábiles.',NULL,'Radicada',1),(8,8,'2026-09-18 12:14:18',NULL,'Radicación','Radicada por el canal web como Sugerencia. Término legal: 15 días hábiles.',NULL,'Radicada',1),(16,1,'2026-08-12 12:14:18',3,'Asignación','Responsable asignado para el estudio de la solicitud.','Radicada','En trámite',1),(17,2,'2026-08-20 12:14:18',6,'Asignación','Responsable asignado para el estudio de la solicitud.','Radicada','En trámite',1),(18,3,'2026-08-24 12:14:18',6,'Asignación','Responsable asignado para el estudio de la solicitud.','Radicada','En trámite',1),(19,4,'2026-09-09 12:14:18',3,'Asignación','Responsable asignado para el estudio de la solicitud.','Radicada','En trámite',1),(20,5,'2026-08-30 12:14:18',6,'Asignación','Responsable asignado para el estudio de la solicitud.','Radicada','En trámite',1),(21,7,'2026-09-15 12:14:18',1,'Asignación','Responsable asignado para el estudio de la solicitud.','Radicada','En trámite',1),(23,1,'2026-08-24 12:14:18',3,'Respuesta enviada','Se envió la respuesta al correo silvia.hurtado.pena@example.com.','En trámite','Respondida',1),(24,2,'2026-08-26 12:14:18',6,'Respuesta enviada','Se envió la respuesta al correo ministerio.de.planeacion.demo@example.com.','En trámite','Respondida',1),(25,3,'2026-09-04 12:14:18',6,'Respuesta enviada','Se envió la respuesta al correo almacenes.la.economia@example.com.','En trámite','Respondida',1),(26,4,'2026-09-17 12:14:18',3,'Respuesta enviada','Se envió la respuesta al correo ferreteria.la.llave.s.a.s@example.com.','En trámite','Respondida',1),(30,1,'2026-08-25 12:14:18',3,'Cambio de estado','Respondida → Cerrada','Respondida','Cerrada',1),(31,2,'2026-08-26 12:14:18',6,'Cambio de estado','Respondida → Cerrada','Respondida','Cerrada',1),(32,3,'2026-09-04 12:14:18',6,'Cambio de estado','Respondida → Cerrada','Respondida','Cerrada',1),(33,6,'2026-09-20 16:16:58',NULL,'Asignación automática','Asignada a Simón Ramírez: atiende todos los tipos · carga 0 de 10, la menor del grupo.','Radicada','En trámite',1),(34,8,'2026-09-20 16:16:58',NULL,'Asignación automática','Asignada a Simón Tobón: atiende todos los tipos · carga 0 de 10, la menor del grupo.','Radicada','En trámite',1);
/*!40000 ALTER TABLE `pqrsd_historial` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pqrsd_responsables`
--

DROP TABLE IF EXISTS `pqrsd_responsables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pqrsd_responsables` (
  `usuario_id` int(11) NOT NULL,
  `tipos` varchar(400) DEFAULT NULL COMMENT 'Tipos de PQRSD que atiende, separados por |. Vacío = todos',
  `temas` varchar(400) DEFAULT NULL,
  `capacidad` int(11) NOT NULL DEFAULT 10 COMMENT 'Solicitudes abiertas que puede llevar a la vez',
  `recibe_casos` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pqrsd_responsables`
--

LOCK TABLES `pqrsd_responsables` WRITE;
/*!40000 ALTER TABLE `pqrsd_responsables` DISABLE KEYS */;
INSERT INTO `pqrsd_responsables` VALUES (3,NULL,NULL,10,1),(13,NULL,NULL,10,1),(15,NULL,NULL,10,1);
/*!40000 ALTER TABLE `pqrsd_responsables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `puc_cuentas`
--

DROP TABLE IF EXISTS `puc_cuentas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `puc_cuentas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(10) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo` enum('Activo','Pasivo','Patrimonio','Ingreso','Gasto','Costo') NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `puc_cuentas`
--

LOCK TABLES `puc_cuentas` WRITE;
/*!40000 ALTER TABLE `puc_cuentas` DISABLE KEYS */;
INSERT INTO `puc_cuentas` VALUES (1,'1105','Caja General','Activo'),(2,'110505','Caja Menor','Activo'),(3,'1110','Bancos','Activo'),(4,'111005','Bancos - Moneda Nacional','Activo'),(5,'1305','Clientes','Activo'),(6,'1355','Anticipo de Impuestos y Saldos a Favor','Activo'),(7,'135515','Retención en la Fuente a Favor','Activo'),(8,'1524','Equipo de Oficina','Activo'),(9,'1528','Equipo de Computación y Comunicación','Activo'),(10,'1705','Gastos Pagados por Anticipado','Activo'),(11,'2105','Bancos Nacionales (Obligaciones)','Pasivo'),(12,'2205','Proveedores Nacionales','Pasivo'),(13,'2335','Costos y Gastos por Pagar','Pasivo'),(14,'233525','Honorarios por Pagar','Pasivo'),(15,'233530','Servicios Técnicos por Pagar','Pasivo'),(16,'233540','Arrendamientos por Pagar','Pasivo'),(17,'233550','Servicios Públicos por Pagar','Pasivo'),(18,'2365','Retención en la Fuente por Pagar','Pasivo'),(19,'236515','Retefuente - Honorarios','Pasivo'),(20,'236525','Retefuente - Servicios','Pasivo'),(21,'2367','Impuesto a las Ventas Retenido (ReteIVA)','Pasivo'),(22,'2368','Impuesto de Industria y Comercio Retenido (ReteICA)','Pasivo'),(23,'2408','Impuesto sobre las Ventas por Pagar (IVA)','Pasivo'),(24,'2505','Salarios por Pagar','Pasivo'),(25,'2510','Cesantías Consolidadas','Pasivo'),(26,'3105','Capital Suscrito y Pagado','Patrimonio'),(27,'3605','Utilidad del Ejercicio','Patrimonio'),(28,'4135','Comercio al por Mayor y al por Menor','Ingreso'),(29,'4155','Actividades Inmobiliarias, Empresariales y de Alquiler','Ingreso'),(30,'4210','Ingresos Financieros','Ingreso'),(31,'4220','Arrendamientos (Ingresos)','Ingreso'),(32,'5105','Gastos de Personal','Gasto'),(33,'510506','Sueldos','Gasto'),(34,'510527','Auxilio de Transporte','Gasto'),(35,'5110','Honorarios','Gasto'),(36,'5115','Impuestos (Gasto)','Gasto'),(37,'5120','Arrendamientos','Gasto'),(38,'5130','Seguros','Gasto'),(39,'5135','Servicios','Gasto'),(40,'513525','Acueducto y Alcantarillado','Gasto'),(41,'513530','Energía Eléctrica','Gasto'),(42,'513535','Teléfono e Internet','Gasto'),(43,'5145','Mantenimiento y Reparaciones','Gasto'),(44,'514525','Mantenimiento de Equipo de Computación (Hosting)','Gasto'),(45,'5195','Gastos Diversos','Gasto'),(46,'519525','Elementos de Aseo y Cafetería','Gasto'),(47,'519530','Útiles, Papelería y Fotocopias','Gasto'),(48,'519545','Taxis y Buses','Gasto'),(49,'5305','Gastos Financieros','Gasto'),(50,'530505','Gastos Bancarios y Comisiones','Gasto'),(51,'130505','Clientes Nacionales','Activo'),(52,'111010','Bancos - cuenta de ahorros','Activo'),(53,'152405','Muebles y enseres','Activo'),(54,'152805','Equipos de procesamiento de datos','Activo'),(55,'210510','Pagarés bancarios','Pasivo'),(56,'220505','Proveedores nacionales','Pasivo'),(57,'236530','Retención en la fuente - arrendamientos','Pasivo'),(58,'236540','Retención en la fuente - compras','Pasivo'),(59,'240805','IVA generado','Pasivo'),(60,'240810','IVA descontable','Pasivo'),(61,'310505','Capital autorizado','Patrimonio'),(62,'415540','Actividades empresariales de consultoría','Ingreso'),(63,'421005','Intereses','Ingreso'),(64,'4250','Recuperaciones','Ingreso'),(65,'425050','Reintegro de otros costos y gastos','Ingreso'),(66,'511025','Asesoría técnica','Gasto'),(67,'512010','Construcciones y edificaciones','Gasto'),(68,'513520','Procesamiento electrónico de datos','Gasto'),(69,'519595','Otros gastos diversos','Gasto'),(100,'111015','Bancos del exterior - Zelle (USD)','Activo'),(101,'111020','Billetera PayPal (USD)','Activo'),(102,'111025','Billetera de criptoactivos - USDT','Activo'),(103,'421020','Diferencia en cambio','Ingreso'),(104,'530515','Comisiones de pasarelas y bancos','Gasto'),(105,'530525','Diferencia en cambio','Gasto'),(106,'1592','Depreciación acumulada','Activo'),(107,'159215','Depreciación acumulada - equipo de oficina','Activo'),(108,'159220','Depreciación acumulada - equipo de computación y comunicación','Activo'),(109,'250505','Salarios por pagar','Pasivo'),(110,'511595','Gravamen a los movimientos financieros (4x1000)','Gasto'),(111,'5160','Depreciaciones','Gasto'),(112,'516015','Depreciación - equipo de oficina','Gasto'),(113,'516020','Depreciación - equipo de computación y comunicación','Gasto'),(116,'360505','Utilidad (o pérdida) del ejercicio','Patrimonio'),(117,'3705','Utilidades acumuladas','Patrimonio'),(118,'370505','Utilidades (o pérdidas) acumuladas','Patrimonio'),(119,'4175','Devoluciones en ventas (DB)','Ingreso'),(120,'417505','Devoluciones, rebajas y descuentos en ventas','Ingreso');
/*!40000 ALTER TABLE `puc_cuentas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reuniones_virtuales`
--

DROP TABLE IF EXISTS `reuniones_virtuales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reuniones_virtuales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `titulo` varchar(150) NOT NULL,
  `codigo_sala` varchar(100) NOT NULL,
  `fecha` date NOT NULL,
  `hora_inicio` time NOT NULL,
  `hora_fin` time NOT NULL,
  `objetivo` text DEFAULT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `estado` enum('Programada','En curso','Finalizada') DEFAULT 'Programada',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `link_reunion` text NOT NULL,
  `acta_reunion` text DEFAULT NULL,
  `evidencia` text DEFAULT NULL,
  `firma_digital` longtext DEFAULT NULL,
  `firmado_por` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reuniones_cliente` (`cliente_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reuniones_virtuales`
--

LOCK TABLES `reuniones_virtuales` WRITE;
/*!40000 ALTER TABLE `reuniones_virtuales` DISABLE KEYS */;
INSERT INTO `reuniones_virtuales` VALUES (1,'prueba','DS_08E726CB','2026-03-11','04:27:00','10:32:00','fu',NULL,'Finalizada','2026-03-11 09:27:50','https://meet.jit.si/DataSenseDemo-1',NULL,NULL,NULL,NULL),(2,'ADAD','DataSense_1859357962988005','2026-03-11','04:38:00','06:38:00','a',NULL,'Finalizada','2026-03-11 09:38:54','https://meet.jit.si/DataSenseDemo-2',NULL,NULL,NULL,NULL),(3,'ADAD','DataSense_1859358224651878','2026-03-11','04:46:00','07:43:00','dad',NULL,'Finalizada','2026-03-11 09:43:03','https://meet.jit.si/DataSenseDemo-3',NULL,NULL,NULL,NULL),(4,'sadda','DataSense_1859363577625670','2026-03-11','09:07:00','10:08:00','dada',NULL,'Finalizada','2026-03-11 11:08:08','https://meet.jit.si/DataSenseDemo-4',NULL,NULL,NULL,NULL),(5,'sadda','DataSense_1859363718385342','2026-03-12','06:14:00','00:12:00','adad',NULL,'Finalizada','2026-03-11 11:10:23','https://meet.jit.si/DataSenseDemo-5',NULL,NULL,NULL,NULL),(6,'prueba','DataSense_1859768572687874','2026-03-15','19:25:00','19:25:00','SSs',NULL,'Programada','2026-03-15 22:25:22','https://meet.jit.si/DataSenseDemo-6',NULL,NULL,NULL,NULL),(7,'prueba4','DataSense_1859800342314654','2026-03-16','14:51:00','15:52:00','clas',NULL,'Programada','2026-03-16 06:50:20','https://meet.jit.si/DataSenseDemo-7',NULL,NULL,NULL,NULL),(8,'publica','DataSense_1859820900396920','2026-03-16','08:16:00','09:16:00','validar datos',NULL,'Programada','2026-03-16 12:17:05','https://meet.jit.si/DataSenseDemo-8',NULL,NULL,NULL,NULL),(9,'19 de marzo','DataSense_1859820987662972','2026-03-19','16:17:00','18:17:00','reunion de datos ',NULL,'','2026-03-16 12:18:29','https://meet.jit.si/DataSenseDemo-9',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `reuniones_virtuales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `servicios_catalogo`
--

DROP TABLE IF EXISTS `servicios_catalogo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `servicios_catalogo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo_servicio` varchar(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `precio_base` decimal(15,2) NOT NULL,
  `impuesto_porcentaje` decimal(5,2) DEFAULT 19.00,
  `estado` enum('Activo','Inactivo') DEFAULT 'Activo',
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo_servicio` (`codigo_servicio`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `servicios_catalogo`
--

LOCK TABLES `servicios_catalogo` WRITE;
/*!40000 ALTER TABLE `servicios_catalogo` DISABLE KEYS */;
INSERT INTO `servicios_catalogo` VALUES (1,'DA-001','Auditoría y Diagnóstico de Datos','Revisión exhaustiva de la calidad, integridad y seguridad de sus bases de datos actuales.',2500000.00,19.00,'Activo'),(2,'BI-002','Dashboard PowerBI - Nivel Básico','Desarrollo de tablero interactivo con hasta 3 vistas y conexión a una única fuente de datos.',3500000.00,19.00,'Activo'),(3,'BI-003','Dashboard PowerBI - Nivel Avanzado','Creación de tableros directivos con múltiples fuentes de datos, DAX avanzado y actualización en tiempo real.',6500000.00,19.00,'Activo'),(4,'AI-004','Modelo Predictivo (Churn)','Desarrollo de algoritmo de Machine Learning para predecir fuga de clientes.',8200000.00,19.00,'Activo'),(5,'DA-005','Limpieza y Normalización de BD','Proceso de depuración, eliminación de duplicados y estandarización de registros en bases de datos.',1800000.00,19.00,'Activo'),(6,'CS-006','Consultoría Estratégica en Datos','Asesoría especializada para definir la arquitectura de datos (Por Hora).',150000.00,19.00,'Activo'),(7,'BI-007','Automatización de Reportes','Migración de informes manuales en Excel hacia procesos automatizados en la nube.',3200000.00,19.00,'Activo'),(8,'CS-008','Mantenimiento Mensual BI','Soporte, actualización de conectores y revisión mensual de tableros.',1200000.00,19.00,'Activo'),(9,'AR-009','Desarrollo de Data Warehouse','Diseño e implementación de repositorio centralizado de datos corporativos (AWS/Azure).',15000000.00,19.00,'Activo'),(10,'AI-010','Análisis de Sentimiento (RRSS)','Extracción y análisis de lenguaje natural (NLP) para medir la percepción de la marca.',4200000.00,19.00,'Activo'),(11,'ED-011','Capacitación Empresarial PowerBI','Entrenamiento intensivo de 20 horas para el equipo interno en el manejo de tableros.',2800000.00,19.00,'Activo'),(12,'IT-012','Soporte Técnico y TI (Mensual)','Administración de infraestructura tecnológica, redes y soporte a usuarios finales.',2000000.00,19.00,'Activo'),(13,'DB-013','Optimización de Consultas SQL','Refactorización y mejora de rendimiento (tuning) para consultas lentas.',2200000.00,19.00,'Activo'),(14,'WA-014','Configuración Google Analytics 4','Implementación de etiquetas y embudos de conversión avanzados en plataformas web.',1500000.00,19.00,'Activo'),(15,'DA-015','Análisis de Datos IoT (Sensores)','Ingesta, procesamiento y visualización de datos emitidos por maquinaria en tiempo real.',12500000.00,19.00,'Inactivo'),(16,'DV-016','Integración de APIs Corporativas','Desarrollo de scripts web para conectar sistemas (ERP con CRM) y automatizar datos.',4800000.00,19.00,'Activo'),(17,'SG-017','Auditoría de Seguridad (ISO 27001)','Evaluación de vulnerabilidades y protocolos de acceso a la información.',6000000.00,19.00,'Activo'),(18,'AI-018','Segmentación de Clientes (Clustering)','Agrupación de clientes basada en comportamiento de compra para marketing.',4500000.00,19.00,'Activo'),(19,'DB-019','Migración de BD a la Nube','Traslado seguro de bases de datos locales (On-Premise) hacia servidores Cloud.',9000000.00,19.00,'Activo'),(20,'BI-020','Dashboard Financiero de Flujo de Caja','Desarrollo de modelo analítico para el área contable con proyección de ingresos.',7500000.00,19.00,'Activo'),(21,'PSI-021','Charla de Psicología y Desarrollo Humano','Charla o taller sobre bienestar, inclusión y desarrollo humano, dirigido por la psicóloga Marcela Pacheco Parra.',650000.00,19.00,'Activo');
/*!40000 ALTER TABLE `servicios_catalogo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sla_objetivos`
--

DROP TABLE IF EXISTS `sla_objetivos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sla_objetivos` (
  `tipo` varchar(3) NOT NULL,
  `prioridad` varchar(10) NOT NULL,
  `respuesta_min` int(11) NOT NULL,
  `resolucion_min` int(11) NOT NULL,
  `calendario` varchar(5) NOT NULL DEFAULT 'habil',
  PRIMARY KEY (`tipo`,`prioridad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sla_objetivos`
--

LOCK TABLES `sla_objetivos` WRITE;
/*!40000 ALTER TABLE `sla_objetivos` DISABLE KEYS */;
INSERT INTO `sla_objetivos` VALUES ('INC','Alta',30,480,'habil'),('INC','Baja',240,2160,'habil'),('INC','Crítica',15,240,'habil'),('INC','Media',120,960,'habil'),('RF','Alta',120,1440,'habil'),('RF','Baja',480,4320,'habil'),('RF','Crítica',60,960,'habil'),('RF','Media',240,2400,'habil');
/*!40000 ALTER TABLE `sla_objetivos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `soporte_tickets`
--

DROP TABLE IF EXISTS `soporte_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `soporte_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo_ticket` varchar(20) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `tecnico_id` int(11) DEFAULT NULL,
  `asunto` varchar(150) DEFAULT NULL,
  `servicio` varchar(100) DEFAULT NULL,
  `categoria` varchar(150) DEFAULT NULL,
  `impacto` varchar(20) DEFAULT NULL,
  `urgencia` varchar(20) DEFAULT NULL,
  `grupo_resolutor` varchar(100) DEFAULT NULL,
  `prioridad` enum('Baja','Media','Alta','Crítica') DEFAULT 'Baja',
  `estado` varchar(50) NOT NULL DEFAULT 'Abierto',
  `descripcion` text DEFAULT NULL,
  `adjunto` varchar(255) DEFAULT NULL,
  `respuesta_tecnica` text DEFAULT NULL,
  `solucion` text DEFAULT NULL,
  `fecha_respuesta` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_vencimiento` datetime DEFAULT NULL,
  `fecha_pausa` datetime DEFAULT NULL,
  `ultima_gestion_fecha` datetime DEFAULT current_timestamp(),
  `calificacion_estrellas` int(11) DEFAULT 0,
  `comentario_usuario` text DEFAULT NULL,
  `notificar_tecnico` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = hay alerta SLA pendiente de mostrar al técnico',
  `tipo` varchar(3) DEFAULT NULL,
  `fecha_primera_respuesta` datetime DEFAULT NULL,
  `fecha_resolucion` datetime DEFAULT NULL,
  `fecha_cierre` datetime DEFAULT NULL,
  `minutos_pausa` int(11) NOT NULL DEFAULT 0,
  `vence_respuesta` datetime DEFAULT NULL,
  `sla_respuesta_min` int(11) DEFAULT NULL,
  `sla_resolucion_min` int(11) DEFAULT NULL,
  `calendario` varchar(5) DEFAULT NULL,
  `sla_respuesta_cumplido` tinyint(1) DEFAULT NULL,
  `sla_resolucion_cumplido` tinyint(1) DEFAULT NULL,
  `sla_alerta_nivel` int(11) NOT NULL DEFAULT 0,
  `reaperturas` int(11) NOT NULL DEFAULT 0,
  `codigo_cierre` varchar(60) DEFAULT NULL,
  `causa_raiz` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tickets_estado` (`estado`),
  KEY `idx_tickets_tecnico` (`tecnico_id`),
  KEY `idx_tickets_usuario` (`usuario_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `soporte_tickets`
--

LOCK TABLES `soporte_tickets` WRITE;
/*!40000 ALTER TABLE `soporte_tickets` DISABLE KEYS */;
INSERT INTO `soporte_tickets` VALUES (1,'INC-00001',12,15,'prueba','Cumplimiento y Seguridad de Datos','Datos sensibles (PII) expuestos sin enmascarar en un tablero o reporte.','Alto','Alta','Gobierno y calidad de datos','Crítica','Cerrado - Exitoso','pru\n\n-------------------------------------------------\n[SISTEMA]: Caso asignado automáticamente a Wilson Orozco por balanceo de cargas.',NULL,'|--|[VISIBLE CLIENTE] - 06/04/2026 13:44 Wilson Orozco: validacion\r\n|--|[AUDITORÍA] - 06/04/2026 13:45 Wilson Orozco: Estado cambió a [En Progreso]\r\n|--|[VISIBLE CLIENTE] - 06/04/2026 21:14 Wilson Orozco: queda funcionando\r\n|--|[AUDITORÍA] - 06/04/2026 21:15 Wilson Orozco: Estado cambió a [Resuelto]\r\n|--|[SISTEMA] - 06/04/2026 21:16 CLIENTE: El usuario indicó que el problema persiste. Caso REACTIVADO automáticamente y devuelto a [En Progreso].\r\n|--|[AUDITORÍA] - 06/04/2026 21:39: REASIGNACIÓN manual por Supervisor.\r\n|--|[AUDITORÍA] - 06/04/2026 21:59 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]\r\n|--|[SISTEMA] - 06/04/2026 22:00 CLIENTE: El usuario indicó que el problema persiste. Caso REACTIVADO automáticamente y devuelto a [En Progreso].\r\n|--|[AUDITORÍA] - 06/04/2026 22:08 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]\r\n|--|[SISTEMA] - 06/04/2026 22:08 CLIENTE: El usuario indicó que el problema persiste. Caso REACTIVADO automáticamente.\r\n|--|[AUDITORÍA] - 06/04/2026 22:13 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]\r\n|--|[SISTEMA] - CLIENTE: El usuario indicó que el problema persiste. Caso REACTIVADO automáticamente.\r\n|--|[AUDITORÍA] - 07/04/2026 09:32 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.','solucionado','2026-09-20 11:37:55','2026-04-06 18:34:20',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'INC','2026-04-07 09:32:30','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-07 08:15:00',15,240,'habil',0,0,101,0,'Solución definitiva',NULL),(2,'RF-00002',12,15,'se','Acceso y Seguridad','Bloquear acceso de un usuario por retiro de la empresa','Alto','Alta','Administración','Crítica','Cerrado - Exitoso','seguridada\n\n-------------------------------------------------\n[SISTEMA]: Caso asignado automáticamente a Simón Tobón por balanceo de cargas.',NULL,'|--|[AUDITORÍA] - 07/04/2026 09:36: REASIGNACIÓN manual por Supervisor.\r\n|--|[AUDITORÍA] - 07/04/2026 11:58 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.','pruab','2026-09-20 11:37:55','2026-04-06 18:34:45',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'RF','2026-04-07 11:58:36','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-07 09:00:00',60,960,'habil',0,0,101,0,'Solución definitiva',NULL),(3,'INC-00003',12,13,'datos','Calidad de Datos','Registros duplicados, nulos o inconsistentes en la base de datos maestra.','Alto','Alta','Gobierno y calidad de datos','Crítica','Cerrado - Exitoso','datos',NULL,'|--|[SISTEMA] - 06/04/2026 13:51 SISTEMA: Caso enrutado y asignado automáticamente a Simón Ramírez por balanceo de cargas.\n|--|[AUDITORÍA] - 06/04/2026 21:39: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 07/04/2026 09:33 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.','claro','2026-09-20 11:37:55','2026-04-06 18:51:58',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'INC','2026-04-07 09:33:59','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-07 08:15:00',15,240,'habil',0,0,101,0,'Solución definitiva',NULL),(4,'INC-00004',12,13,'falla','Visualización (Dashboards)','Un gráfico está roto, en blanco o arroja error visual','Alto','Alta','Soporte BI','Crítica','Cerrado - Exitoso','visulizacion\n\n-------------------------------------------------\n[SISTEMA]: Caso asignado automáticamente a Simón Ramírez por balanceo de cargas.',NULL,'|--|[AUDITORÍA] - 06/04/2026 21:39: REASIGNACIÓN manual por Supervisor.\r\n|--|[AUDITORÍA] - 07/04/2026 09:35 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.','claro','2026-09-20 11:37:55','2026-04-06 19:02:28',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'INC','2026-04-07 09:35:54','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-07 08:15:00',15,240,'habil',0,0,101,0,'Solución definitiva',NULL),(5,'INC-00005',12,15,'prueba','Rendimiento','Un tablero de control o reporte está extremadamente lento','Alto','Alta','Ingeniería de Datos','Crítica','Cerrado - Exitoso','prueba',NULL,'|--|[SISTEMA] - 06/04/2026 14:05 SISTEMA: Caso enrutado y asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[NOTA INTERNA] - 06/04/2026 14:20 Wilson Orozco: locos\n|--|[VISIBLE CLIENTE] - 06/04/2026 14:21 Wilson Orozco: validando en el servidor\n|--|[AUDITORÍA] - 06/04/2026 21:39: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 07/04/2026 09:33 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.','solucion','2026-09-20 11:37:55','2026-04-06 14:05:58',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'INC','2026-04-07 09:33:08','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-06 14:20:58',15,240,'habil',0,0,101,0,'Solución temporal (mientras se corrige la causa)',NULL),(6,'INC-00006',12,13,'integracion','Integración (APIs)','Error de conexión con una fuente externa (CRM, ERP, Google, etc.)','Alto','Alta','Ingeniería de Datos','Crítica','Cerrado - Exitoso','integracion',NULL,'|--|[SISTEMA] - 06/04/2026 14:17 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 07/04/2026 09:33 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.','solucionf','2026-09-20 11:37:55','2026-04-06 14:17:41',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'INC','2026-04-07 09:33:28','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-06 14:32:41',15,240,'habil',0,0,101,0,'Solución definitiva',NULL),(7,'RF-00007',12,15,'prueba','Acceso y Seguridad','Bloquear acceso de un usuario por retiro de la empresa','Alto','Alta','Administración','Crítica','Cerrado - Exitoso','n',NULL,'|--|[SISTEMA] - 07/04/2026 12:00 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 07/04/2026 13:23 Wilson Hernán Ocampo Flórez: Estado cambió a [Resuelto]\n|--|[AUDITORÍA] - 13/04/2026 23:02: REASIGNACIÓN manual por Supervisor.|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.','prueba','2026-09-20 11:37:55','2026-04-07 12:00:49',NULL,NULL,'2026-09-20 11:37:55',5,'Excelente servicio',0,'RF','2026-09-20 11:37:55','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-07 13:00:49',60,960,'habil',0,0,101,0,'Solución definitiva',NULL),(8,'INC-00008',12,3,'prueba','Cumplimiento y Seguridad de Datos','Datos sensibles (PII) expuestos sin enmascarar en un tablero o reporte.','Alto','Alta','Gobierno y calidad de datos','Crítica','Cerrado - Exitoso','h',NULL,'|--|[SISTEMA] - 07/04/2026 14:07 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 07/04/2026 14:07: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 07/04/2026 14:09 Simón Ramírez: Reloj SLA Pausado | Estado cambió a [En Espera]\n|--|[AUDITORÍA] - 07/04/2026 14:10 Simón Ramírez: Reloj SLA Reanudado | Estado cambió a [En Progreso]\n|--|[AUDITORÍA] - 07/04/2026 14:11 Simón Ramírez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.\n|--|[AUDITORÍA] - 13/04/2026 23:19 Simón Ramírez: Reloj SLA Pausado | Estado cambió a [En Espera]\n|--|[SISTEMA] - 14/04/2026 23:20: ALERTA SLA. Caso cambiado de \'En espera\' a \'En progreso\' automáticamente por falta de documentación durante más de 24 horas hábiles.','jnknknk','2026-09-20 11:37:55','2026-04-07 14:07:13',NULL,'2026-04-13 23:19:57','2026-09-20 11:37:55',2,'',0,'INC','2026-04-07 14:11:19','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-07 14:22:13',15,240,'habil',1,0,101,0,'Solución definitiva',NULL),(9,'INC-00009',1,3,'sadas','Alertas y Notificaciones','No me llegó el correo automático de alertas o KPIs','Alto','Alta','Soporte BI','Crítica','Cerrado - Exitoso','bloqueros',NULL,'|--|[SISTEMA] - 07/04/2026 14:28 SISTEMA: Caso asignado automáticamente a Wilson Orozco por balanceo de cargas.\n|--|[AUDITORÍA] - 07/04/2026 14:29: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 07/04/2026 14:34 Simón Ramírez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.\n|--|[AUDITORÍA] - 13/04/2026 23:20 Simón Ramírez: Reloj SLA Pausado | Estado cambió a [En Espera]\n|--|[SISTEMA] - 14/04/2026 23:20: ALERTA SLA. Caso cambiado de \'En espera\' a \'En progreso\' automáticamente por falta de documentación durante más de 24 horas hábiles.','cerrado','2026-09-20 11:37:55','2026-04-07 14:28:52',NULL,'2026-04-13 23:20:07','2026-09-20 11:37:55',5,'el mejor cabron',0,'INC','2026-04-07 14:34:03','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-07 14:43:52',15,240,'habil',1,0,101,0,'Solución definitiva',NULL),(10,'INC-00010',12,3,'prefe','Alertas y Notificaciones','No me llegó el correo automático de alertas o KPIs','Bajo','Baja','Soporte BI','Baja','Cerrado - Exitoso','sddsds',NULL,'|--|[SISTEMA] - 07/04/2026 14:57 SISTEMA: Caso asignado automáticamente a samuel mesa por balanceo de cargas.\n|--|[AUDITORÍA] - 07/04/2026 14:57: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 07/04/2026 14:57 Simón Ramírez: Estado cambió a [Resuelto]|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: ALERTA AUTOMÁTICA: El caso lleva 48h sin gestión documental.\n|--|[AUDITORÍA] - 13/04/2026 23:20 Simón Ramírez: Reloj SLA Pausado | Estado cambió a [En Espera] | Prioridad cambió a [Baja]\n|--|[SISTEMA] - 14/04/2026 23:20: ALERTA SLA. Caso cambiado de \'En espera\' a \'En progreso\' automáticamente por falta de documentación durante más de 24 horas hábiles.','axsas','2026-09-20 11:37:55','2026-04-07 14:57:05',NULL,'2026-04-13 23:20:22','2026-09-20 11:37:55',5,'el mejor',0,'INC','2026-04-07 14:57:52','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-08 09:57:05',240,2160,'habil',1,0,101,0,'Solución definitiva',NULL),(11,'INC-00011',12,3,'seguridad','Cumplimiento y Seguridad de Datos','Datos sensibles (PII) expuestos sin enmascarar en un tablero o reporte.','Alto','Alta','Gobierno y calidad de datos','Crítica','Cerrado - Inactividad','fallas',NULL,'|--|[SISTEMA] - 13/04/2026 21:31 SISTEMA: Caso asignado automáticamente a samuel mesa por balanceo de cargas.\n|--|[AUDITORÍA] - 13/04/2026 22:54: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 13/04/2026 22:54 Simón Ramírez: Reloj SLA Pausado | Estado cambió a [En Espera]\n|--|[AUDITORÍA] - 13/04/2026 23:18 Simón Ramírez: Reloj SLA Reanudado | Estado cambió a [Resuelto]','solucionado','2026-04-13 23:18:44','2026-04-13 21:31:01','2026-04-14 12:00:00',NULL,'2026-04-13 23:18:44',0,NULL,0,'INC','2026-04-13 23:18:44','2026-04-13 23:18:44','2026-09-20 11:07:36',0,'2026-04-14 08:15:00',15,240,'habil',1,1,0,0,NULL,NULL),(12,'RF-00012',12,3,'Cargar tablero de mi factura','Acceso y Seguridad','Bloquear acceso de un usuario por retiro de la empresa','Alto','Alta','Administración','Crítica','Cerrado - Inactividad','faklla',NULL,'|--|[SISTEMA] - 13/04/2026 21:32 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 13/04/2026 22:37: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 13/04/2026 22:47 Simón Ramírez: Prioridad cambió a [Crítica]\n|--|[AUDITORÍA] - 13/04/2026 22:49 Simón Ramírez: Estado cambió a [En Progreso]\n|--|[VISIBLE CLIENTE] - 13/04/2026 22:49 Simón Ramírez: falta info\n|--|[AUDITORÍA] - 13/04/2026 22:51 Simón Ramírez: Estado cambió a [Resuelto]\n|--|[SISTEMA] - CLIENTE: El usuario indicó que el problema persiste. Caso REACTIVADO automáticamente.\n|--|[AUDITORÍA] - 13/04/2026 22:52 Simón Ramírez: Estado cambió a [Resuelto]\n|--|[AUDITORÍA] - 14/04/2026 16:30 Simón Ramírez: Estado cambió a [Resuelto]','solucionado','2026-04-13 22:52:48','2026-04-13 21:32:04','2026-04-15 15:00:00',NULL,'2026-04-14 16:30:37',5,'77111515',0,'RF','2026-04-13 22:52:48','2026-04-13 22:52:48','2026-09-20 11:07:36',0,'2026-04-14 09:00:00',60,960,'habil',1,1,0,0,NULL,NULL),(13,'RF-00013',12,13,'sadas','Acceso y Seguridad','Bloquear acceso de un usuario por retiro de la empresa','Alto','Alta','Administración','Crítica','Cerrado - Exitoso','pladsad',NULL,'|--|[SISTEMA] - 13/04/2026 23:03 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 13/04/2026 23:05: REASIGNACIÓN manual por Supervisor.','Caso revisado y solucionado. El cliente confirmó la solución durante la puesta al día de la mesa de servicios.','2026-09-20 11:37:55','2026-04-13 23:03:52',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'RF','2026-09-20 11:37:55','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-14 09:00:00',60,960,'habil',0,0,101,0,'Solución definitiva',NULL),(14,'RF-00014',1,15,'adda','Acceso y Seguridad','Bloquear acceso de un usuario por retiro de la empresa','Alto','Alta','Administración','Crítica','Cerrado - Exitoso','adad',NULL,'|--|[SISTEMA] - 13/04/2026 23:06 SISTEMA: Caso asignado automáticamente a Wilson Orozco por balanceo de cargas.\n|--|[AUDITORÍA] - 13/04/2026 23:08: REASIGNACIÓN manual por Supervisor.','Caso revisado y solucionado. El cliente confirmó la solución durante la puesta al día de la mesa de servicios.','2026-09-20 11:37:55','2026-04-13 23:06:36',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'RF','2026-09-20 11:37:55','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-14 09:00:00',60,960,'habil',0,0,101,0,'Solución definitiva',NULL),(15,'INC-00015',1,13,'sa','Cumplimiento y Seguridad de Datos','Datos sensibles (PII) expuestos sin enmascarar en un tablero o reporte.','Alto','Alta','Gobierno y calidad de datos','Crítica','Cerrado - Exitoso','asasas',NULL,'|--|[SISTEMA] - 13/04/2026 23:07 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 13/04/2026 23:08: REASIGNACIÓN manual por Supervisor.','Caso revisado y solucionado. El cliente confirmó la solución durante la puesta al día de la mesa de servicios.','2026-09-20 11:37:55','2026-04-13 23:07:26',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'INC','2026-09-20 11:37:55','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-14 08:15:00',15,240,'habil',0,0,101,0,'Solución definitiva',NULL),(16,'INC-00016',1,15,'prueba','Integración (APIs)','Error de conexión con una fuente externa (CRM, ERP, Google, etc.)','Bajo','Baja','Ingeniería de Datos','Baja','Cerrado - Exitoso','adad',NULL,'|--|[SISTEMA] - 13/04/2026 23:09 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 13/04/2026 23:09: REASIGNACIÓN manual por Supervisor.','Caso revisado y solucionado. El cliente confirmó la solución durante la puesta al día de la mesa de servicios.','2026-09-20 11:37:55','2026-04-13 23:09:01',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'INC','2026-09-20 11:37:55','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-14 12:00:00',240,2160,'habil',0,0,101,0,'Solución definitiva',NULL),(17,'RF-00017',1,3,'prueba','Acceso y Seguridad','Bloquear acceso de un usuario por retiro de la empresa','Alto','Alta','Administración','Crítica','Cerrado - Exitoso','hola',NULL,'|--|[SISTEMA] - 14/04/2026 16:31 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 14/04/2026 16:31: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 14/04/2026 23:25 Simón Ramírez: Reloj SLA Pausado | Estado cambió a [En Espera]\n|--|[SISTEMA] - 06/05/2026 00:18: ALERTA SLA. Caso cambiado de \'En espera\' a \'En progreso\' automáticamente por falta de documentación durante más de 24 horas hábiles.','Caso revisado y solucionado. El cliente confirmó la solución durante la puesta al día de la mesa de servicios.','2026-09-20 11:37:55','2026-04-14 16:31:40',NULL,'2026-04-14 23:25:28','2026-09-20 11:37:55',0,NULL,0,'RF','2026-04-14 16:31:40','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-15 08:31:40',60,960,'habil',1,0,0,0,'Solución definitiva',NULL),(18,'RF-00018',12,3,'req','Acceso y Seguridad','Bloquear acceso de un usuario por retiro de la empresa',NULL,NULL,'Administración','Baja','Cerrado - Inactividad','ba',NULL,'|--|[SISTEMA] - 14/04/2026 23:20 SISTEMA: Caso asignado automáticamente a Wilson Orozco por balanceo de cargas.\n|--|[AUDITORÍA] - 14/04/2026 23:20: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 15/04/2026 02:14 Simón Ramírez: Estado cambió a [Resuelto]','BAJADO','2026-04-15 02:14:33','2026-04-14 23:20:19','2026-04-24 17:00:00',NULL,'2026-04-15 02:14:33',0,NULL,0,'RF','2026-04-15 02:14:33','2026-04-15 02:14:33','2026-09-20 11:07:36',0,'2026-04-15 16:00:00',480,4320,'habil',1,1,0,0,NULL,NULL),(19,'INC-00019',12,3,'ALERTYA','Alertas y Notificaciones','No me llegó el correo automático de alertas o KPIs','Alto','Alta','Soporte BI','Crítica','Cerrado - Exitoso','KNN',NULL,'|--|[SISTEMA] - 14/04/2026 23:31 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 14/04/2026 23:31: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 14/04/2026 23:33 Simón Ramírez: Reloj SLA Pausado | Estado cambió a [En Espera]\n|--|[SISTEMA] - 06/05/2026 00:18: ALERTA SLA. Caso cambiado de \'En espera\' a \'En progreso\' automáticamente por falta de documentación durante más de 24 horas hábiles.','Caso revisado y solucionado. El cliente confirmó la solución durante la puesta al día de la mesa de servicios.','2026-09-20 11:37:55','2026-04-14 23:31:47',NULL,'2026-04-14 23:33:40','2026-09-20 11:37:55',0,NULL,0,'INC','2026-04-14 23:31:47','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-15 08:15:00',15,240,'habil',1,0,0,0,'Solución definitiva',NULL),(20,'INC-00020',12,3,'TABLERO','Rendimiento','Un tablero de control o reporte está extremadamente lento','Alto','Alta','Ingeniería de Datos','Crítica','Cerrado - Inactividad','TABLERO\r\n4',NULL,'|--|[SISTEMA] - 15/04/2026 01:54 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 15/04/2026 01:54: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 15/04/2026 02:10 Simón Ramírez: Prioridad cambió a [Baja]\n|--|[AUDITORÍA] - 15/04/2026 02:14 Simón Ramírez: Estado cambió a [Resuelto]','ERROR','2026-04-15 02:14:54','2026-04-15 01:54:22','2026-04-15 12:00:00',NULL,'2026-04-15 02:14:54',0,NULL,0,'INC','2026-04-15 02:14:54','2026-04-15 02:14:54','2026-09-20 11:07:36',0,'2026-04-15 08:15:00',15,240,'habil',1,1,0,0,NULL,NULL),(21,'RF-00021',12,3,'sadas','Calidad de Datos','Implementar nueva regla de validación automática para limpiar datos.','Bajo','Baja','Gobierno y calidad de datos','Baja','Cerrado - Exitoso','FA',NULL,'|--|[SISTEMA] - 15/04/2026 02:15 SISTEMA: Caso asignado automáticamente a Wilson Hernán Ocampo Flórez por balanceo de cargas.\n|--|[AUDITORÍA] - 15/04/2026 02:16: REASIGNACIÓN manual por Supervisor.\n|--|[AUDITORÍA] - 15/04/2026 02:16 Simón Ramírez: Prioridad cambió a [Media]\n|--|[AUDITORÍA] - 15/04/2026 02:17 Simón Ramírez: Prioridad cambió a [Crítica]\n|--|[AUDITORÍA] - 15/04/2026 02:17 Simón Ramírez: Prioridad cambió a [Baja]','Caso revisado y solucionado. El cliente confirmó la solución durante la puesta al día de la mesa de servicios.','2026-09-20 11:37:55','2026-04-15 02:15:44',NULL,NULL,'2026-09-20 11:37:55',0,NULL,0,'RF','2026-09-20 11:37:55','2026-09-20 11:37:55','2026-09-20 11:37:55',0,'2026-04-15 16:00:00',480,4320,'habil',0,0,101,0,'Solución definitiva',NULL);
/*!40000 ALTER TABLE `soporte_tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tasas_cambio`
--

DROP TABLE IF EXISTS `tasas_cambio`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasas_cambio` (
  `fecha` date NOT NULL,
  `moneda` varchar(5) NOT NULL,
  `valor` decimal(12,2) NOT NULL,
  `fuente` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`fecha`,`moneda`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tasas_cambio`
--

LOCK TABLES `tasas_cambio` WRITE;
/*!40000 ALTER TABLE `tasas_cambio` DISABLE KEYS */;
/*!40000 ALTER TABLE `tasas_cambio` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `terceros`
--

DROP TABLE IF EXISTS `terceros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terceros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `documento` varchar(20) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'Proveedor',
  `id_usuario` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `documento` (`documento`),
  UNIQUE KEY `id_usuario` (`id_usuario`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `terceros`
--

LOCK TABLES `terceros` WRITE;
/*!40000 ALTER TABLE `terceros` DISABLE KEYS */;
INSERT INTO `terceros` VALUES (1,'1084664393','DataSense','Proveedor',NULL,'2026-09-20 16:05:03');
/*!40000 ALTER TABLE `terceros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_correos`
--

DROP TABLE IF EXISTS `ticket_correos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_correos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `tipo` varchar(40) NOT NULL,
  `destinatario` varchar(150) DEFAULT NULL,
  `asunto` varchar(255) NOT NULL,
  `contenido` text DEFAULT NULL,
  `enviado` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_correos_ticket` (`ticket_id`,`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_correos`
--

LOCK TABLES `ticket_correos` WRITE;
/*!40000 ALTER TABLE `ticket_correos` DISABLE KEYS */;
INSERT INTO `ticket_correos` VALUES (1,5,'2026-09-20 11:22:17',28,'Correo del especialista','cliente@example.com','[INC-00005] Seguimiento de tu caso','Hola 👋 Wilson Hernán Ocampo Flórez\nhola loco\nCaso INC-00005: prueba. Puedes responder desde el Centro de Soporte.\nPerfil de prueba ? Administrador\nEquipo de Operaciones IT',1);
/*!40000 ALTER TABLE `ticket_correos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_historial`
--

DROP TABLE IF EXISTS `ticket_historial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_historial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `evento` varchar(40) NOT NULL,
  `detalle` varchar(500) DEFAULT NULL,
  `estado_anterior` varchar(50) DEFAULT NULL,
  `estado_nuevo` varchar(50) DEFAULT NULL,
  `publico` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_historial_ticket` (`ticket_id`,`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_historial`
--

LOCK TABLES `ticket_historial` WRITE;
/*!40000 ALTER TABLE `ticket_historial` DISABLE KEYS */;
INSERT INTO `ticket_historial` VALUES (1,20,'2026-09-20 11:07:36',NULL,'Cierre automático','Cerrado sin respuesta del cliente 3 días hábiles después de la solución.','Resuelto','Cerrado - Inactividad',1),(2,18,'2026-09-20 11:07:36',NULL,'Cierre automático','Cerrado sin respuesta del cliente 3 días hábiles después de la solución.','Resuelto','Cerrado - Inactividad',1),(3,12,'2026-09-20 11:07:36',NULL,'Cierre automático','Cerrado sin respuesta del cliente 3 días hábiles después de la solución.','Resuelto','Cerrado - Inactividad',1),(4,11,'2026-09-20 11:07:36',NULL,'Cierre automático','Cerrado sin respuesta del cliente 3 días hábiles después de la solución.','Resuelto','Cerrado - Inactividad',1),(5,5,'2026-09-20 11:22:17',28,'Correo al cliente','Correo del especialista: «[INC-00005] Seguimiento de tu caso» para cliente@example.com.',NULL,NULL,0),(6,5,'2026-09-20 11:22:21',28,'Llamada al cliente','Marcó al 3165554893 desde el caso. La conversación ocurre fuera de la plataforma: lo acordado debe quedar en Notas.',NULL,NULL,0),(7,10,'2026-09-20 11:26:43',28,'Cambio de estado','En progreso → En Progreso','En progreso','En Progreso',1),(8,21,'2026-09-20 11:37:55',NULL,'Primera respuesta','Registrada al poner la mesa al día.',NULL,NULL,1),(9,21,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 945 h de 72 h (fuera del ANS).',NULL,NULL,1),(10,21,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(11,21,'2026-09-20 11:37:55',NULL,'Cambio de estado','Asignado → Cerrado - Exitoso','Asignado','Cerrado - Exitoso',1),(12,19,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 945 h de 4 h (fuera del ANS).',NULL,NULL,1),(13,19,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(14,19,'2026-09-20 11:37:55',NULL,'Cambio de estado','En progreso → Cerrado - Exitoso','En progreso','Cerrado - Exitoso',1),(15,17,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 945 h 28 min de 16 h (fuera del ANS).',NULL,NULL,1),(16,17,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(17,17,'2026-09-20 11:37:55',NULL,'Cambio de estado','En progreso → Cerrado - Exitoso','En progreso','Cerrado - Exitoso',1),(18,16,'2026-09-20 11:37:55',NULL,'Primera respuesta','Registrada al poner la mesa al día.',NULL,NULL,1),(19,16,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 954 h de 36 h (fuera del ANS).',NULL,NULL,1),(20,16,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(21,16,'2026-09-20 11:37:55',NULL,'Cambio de estado','Asignado → Cerrado - Exitoso','Asignado','Cerrado - Exitoso',1),(22,15,'2026-09-20 11:37:55',NULL,'Primera respuesta','Registrada al poner la mesa al día.',NULL,NULL,1),(23,15,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 954 h de 4 h (fuera del ANS).',NULL,NULL,1),(24,15,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(25,15,'2026-09-20 11:37:55',NULL,'Cambio de estado','Asignado → Cerrado - Exitoso','Asignado','Cerrado - Exitoso',1),(26,14,'2026-09-20 11:37:55',NULL,'Primera respuesta','Registrada al poner la mesa al día.',NULL,NULL,1),(27,14,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 954 h de 16 h (fuera del ANS).',NULL,NULL,1),(28,14,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(29,14,'2026-09-20 11:37:55',NULL,'Cambio de estado','Asignado → Cerrado - Exitoso','Asignado','Cerrado - Exitoso',1),(30,13,'2026-09-20 11:37:55',NULL,'Primera respuesta','Registrada al poner la mesa al día.',NULL,NULL,1),(31,13,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 954 h de 16 h (fuera del ANS).',NULL,NULL,1),(32,13,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(33,13,'2026-09-20 11:37:55',NULL,'Cambio de estado','Asignado → Cerrado - Exitoso','Asignado','Cerrado - Exitoso',1),(34,10,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 992 h 2 min de 36 h (fuera del ANS).',NULL,NULL,1),(35,10,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(36,10,'2026-09-20 11:37:55',NULL,'Cambio de estado','En Progreso → Cerrado - Exitoso','En Progreso','Cerrado - Exitoso',1),(37,9,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 992 h 31 min de 4 h (fuera del ANS).',NULL,NULL,1),(38,9,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(39,9,'2026-09-20 11:37:55',NULL,'Cambio de estado','En progreso → Cerrado - Exitoso','En progreso','Cerrado - Exitoso',1),(40,8,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 992 h 52 min de 4 h (fuera del ANS).',NULL,NULL,1),(41,8,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(42,8,'2026-09-20 11:37:55',NULL,'Cambio de estado','En progreso → Cerrado - Exitoso','En progreso','Cerrado - Exitoso',1),(43,7,'2026-09-20 11:37:55',NULL,'Primera respuesta','Registrada al poner la mesa al día.',NULL,NULL,1),(44,7,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 994 h 59 min de 16 h (fuera del ANS).',NULL,NULL,1),(45,7,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(46,7,'2026-09-20 11:37:55',NULL,'Cambio de estado','Asignado → Cerrado - Exitoso','Asignado','Cerrado - Exitoso',1),(47,4,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 999 h de 4 h (fuera del ANS).',NULL,NULL,1),(48,4,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(49,4,'2026-09-20 11:37:55',NULL,'Cambio de estado','En Progreso → Cerrado - Exitoso','En Progreso','Cerrado - Exitoso',1),(50,3,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 999 h de 4 h (fuera del ANS).',NULL,NULL,1),(51,3,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(52,3,'2026-09-20 11:37:55',NULL,'Cambio de estado','En Progreso → Cerrado - Exitoso','En Progreso','Cerrado - Exitoso',1),(53,2,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 999 h de 16 h (fuera del ANS).',NULL,NULL,1),(54,2,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(55,2,'2026-09-20 11:37:55',NULL,'Cambio de estado','En Progreso → Cerrado - Exitoso','En Progreso','Cerrado - Exitoso',1),(56,1,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 999 h de 4 h (fuera del ANS).',NULL,NULL,1),(57,1,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(58,1,'2026-09-20 11:37:55',NULL,'Cambio de estado','En Progreso → Cerrado - Exitoso','En Progreso','Cerrado - Exitoso',1),(59,6,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 1001 h 42 min de 4 h (fuera del ANS).',NULL,NULL,1),(60,6,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(61,6,'2026-09-20 11:37:55',NULL,'Cambio de estado','En Progreso → Cerrado - Exitoso','En Progreso','Cerrado - Exitoso',1),(62,5,'2026-09-20 11:37:55',NULL,'Solución','Solución registrada en 1001 h 54 min de 4 h (fuera del ANS).',NULL,NULL,1),(63,5,'2026-09-20 11:37:55',NULL,'Confirmación del cliente','Cierre confirmado por el cliente en la puesta al día de la mesa de servicios (20/09/2026).',NULL,NULL,1),(64,5,'2026-09-20 11:37:55',NULL,'Cambio de estado','En Progreso → Cerrado - Exitoso','En Progreso','Cerrado - Exitoso',1);
/*!40000 ALTER TABLE `ticket_historial` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tienda_categorias`
--

DROP TABLE IF EXISTS `tienda_categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tienda_categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `slug` varchar(90) NOT NULL,
  `icono` varchar(40) NOT NULL DEFAULT 'fa-tag',
  `orden` int(11) NOT NULL DEFAULT 0,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tienda_categoria_slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tienda_categorias`
--

LOCK TABLES `tienda_categorias` WRITE;
/*!40000 ALTER TABLE `tienda_categorias` DISABLE KEYS */;
INSERT INTO `tienda_categorias` VALUES (1,'Bienestar y Vida Saludable','bienestar-saludable','fa-leaf',0,1),(2,'Plantillas y Dashboards','plantillas-dashboards','fa-chart-pie',1,1),(3,'Cursos y Formación','cursos-formacion','fa-graduation-cap',2,1),(4,'Software y Licencias','software-licencias','fa-laptop-code',3,1),(5,'Servicios Express','servicios-express','fa-bolt',4,1),(6,'Hardware y Kits IoT','hardware-iot','fa-microchip',5,1),(7,'Productos de Marca','productos-marca','fa-shirt',6,1),(8,'Revistas y Publicaciones','revistas-publicaciones','fa-book-open',7,1);
/*!40000 ALTER TABLE `tienda_categorias` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tienda_cupones`
--

DROP TABLE IF EXISTS `tienda_cupones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tienda_cupones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(30) NOT NULL,
  `descripcion` varchar(150) NOT NULL DEFAULT '',
  `tipo` varchar(12) NOT NULL DEFAULT 'Porcentaje',
  `valor` decimal(14,2) NOT NULL,
  `minimo_compra` decimal(14,2) NOT NULL DEFAULT 0.00,
  `usos_maximos` int(11) DEFAULT NULL,
  `usos` int(11) NOT NULL DEFAULT 0,
  `vence` date DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tienda_cupon` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tienda_cupones`
--

LOCK TABLES `tienda_cupones` WRITE;
/*!40000 ALTER TABLE `tienda_cupones` DISABLE KEYS */;
INSERT INTO `tienda_cupones` VALUES (1,'BIENVENIDA10','10 % de descuento en tu primera compra','Porcentaje',10.00,0.00,NULL,0,'2027-09-20',1),(2,'DATOS50K','$ 50.000 de descuento en compras desde $ 300.000','Valor',50000.00,300000.00,100,0,'2027-03-20',1);
/*!40000 ALTER TABLE `tienda_cupones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tienda_pedido_historial`
--

DROP TABLE IF EXISTS `tienda_pedido_historial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tienda_pedido_historial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_id` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `estado` varchar(20) NOT NULL,
  `detalle` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tienda_historial_pedido` (`pedido_id`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tienda_pedido_historial`
--

LOCK TABLES `tienda_pedido_historial` WRITE;
/*!40000 ALTER TABLE `tienda_pedido_historial` DISABLE KEYS */;
/*!40000 ALTER TABLE `tienda_pedido_historial` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tienda_pedido_items`
--

DROP TABLE IF EXISTS `tienda_pedido_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tienda_pedido_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pedido_id` int(11) NOT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `sku` varchar(30) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `precio_unitario` decimal(14,2) NOT NULL,
  `cantidad` int(11) NOT NULL,
  `total` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tienda_item_pedido` (`pedido_id`),
  KEY `idx_tienda_item_producto` (`producto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tienda_pedido_items`
--

LOCK TABLES `tienda_pedido_items` WRITE;
/*!40000 ALTER TABLE `tienda_pedido_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `tienda_pedido_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tienda_pedidos`
--

DROP TABLE IF EXISTS `tienda_pedidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tienda_pedidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `factura_id` int(11) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'Pendiente de pago',
  `subtotal` decimal(14,2) NOT NULL DEFAULT 0.00,
  `descuento` decimal(14,2) NOT NULL DEFAULT 0.00,
  `envio` decimal(14,2) NOT NULL DEFAULT 0.00,
  `base` decimal(14,2) NOT NULL DEFAULT 0.00,
  `iva` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `cupon` varchar(30) DEFAULT NULL,
  `entrega_nombre` varchar(150) DEFAULT NULL,
  `entrega_telefono` varchar(30) DEFAULT NULL,
  `entrega_direccion` varchar(200) DEFAULT NULL,
  `entrega_ciudad` varchar(80) DEFAULT NULL,
  `guia_envio` varchar(80) DEFAULT NULL,
  `notas` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tienda_pedido_numero` (`numero`),
  KEY `idx_tienda_pedido_usuario` (`usuario_id`,`created_at`),
  KEY `idx_tienda_pedido_factura` (`factura_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tienda_pedidos`
--

LOCK TABLES `tienda_pedidos` WRITE;
/*!40000 ALTER TABLE `tienda_pedidos` DISABLE KEYS */;
/*!40000 ALTER TABLE `tienda_pedidos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tienda_productos`
--

DROP TABLE IF EXISTS `tienda_productos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tienda_productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `categoria_id` int(11) DEFAULT NULL,
  `sku` varchar(30) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `slug` varchar(170) NOT NULL,
  `resumen` varchar(255) NOT NULL DEFAULT '',
  `descripcion` text DEFAULT NULL,
  `incluye` text DEFAULT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'Digital',
  `precio` decimal(14,2) NOT NULL,
  `precio_anterior` decimal(14,2) DEFAULT NULL,
  `stock` int(11) DEFAULT NULL,
  `requiere_envio` tinyint(1) NOT NULL DEFAULT 0,
  `icono` varchar(40) NOT NULL DEFAULT 'fa-box',
  `color` varchar(7) NOT NULL DEFAULT '#0A2E4F',
  `imagen` varchar(255) DEFAULT NULL,
  `destacado` tinyint(1) NOT NULL DEFAULT 0,
  `estado` varchar(10) NOT NULL DEFAULT 'Activo',
  `vendidos` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tienda_producto_sku` (`sku`),
  UNIQUE KEY `uq_tienda_producto_slug` (`slug`),
  KEY `idx_tienda_producto_categoria` (`categoria_id`,`estado`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tienda_productos`
--

LOCK TABLES `tienda_productos` WRITE;
/*!40000 ALTER TABLE `tienda_productos` DISABLE KEYS */;
INSERT INTO `tienda_productos` VALUES (1,2,'PBI-VEN-01','Plantilla Power BI · Tablero de Ventas','plantilla-power-bi-tablero-ventas','Tablero gerencial listo para conectar a tu Excel, ERP o base de datos.','Mide ventas, margen, clientes y desempeño de vendedores en minutos. Modelo en estrella optimizado y medidas DAX documentadas.','Archivo .pbix con modelo de datos en estrella\n12 páginas: ventas, margen, clientes y vendedores\nGuía de conexión a Excel, SQL Server y ERP\nActualizaciones gratis durante 12 meses','Digital',249000.00,320000.00,NULL,0,'fa-chart-line','#0A2E4F','prod_2b0ba095d117fbec.jpg',1,'Activo',128,'2026-09-20 11:59:17',NULL),(2,2,'PBI-FIN-02','Plantilla Power BI · Estado Financiero y Flujo de Caja','plantilla-power-bi-financiera','Estado de resultados, balance y flujo de caja con comparativos mensuales.','Pensada para gerencias financieras: indicadores de liquidez, rentabilidad y endeudamiento con semáforos.','Archivo .pbix con plan de cuentas PUC\nComparativo presupuesto vs. real\nIndicadores de liquidez y rentabilidad\nManual de uso en PDF','Digital',289000.00,NULL,NULL,0,'fa-coins','#0f766e','prod_7d21aadf8ce2a33d.jpg',0,'Activo',87,'2026-09-20 11:59:17',NULL),(3,2,'PBI-PUB-03','Tablero de Transparencia Presupuestal','tablero-transparencia-presupuestal','Para entidades públicas: ejecución presupuestal clara para la ciudadanía.','Cumple las buenas prácticas de transparencia activa: ejecución por rubro, contratación y metas del plan de desarrollo.','Plantilla publicable en la web de la entidad\nEjecución por rubro, fuente y dependencia\nSeguimiento a metas del plan de desarrollo\nSesión de acompañamiento de 1 hora','Digital',390000.00,NULL,NULL,0,'fa-landmark','#1d4ed8','prod_36b70c3c95771944.jpg',1,'Activo',41,'2026-09-20 11:59:17',NULL),(4,2,'XLS-KPI-04','Kit de 25 plantillas de KPIs en Excel','kit-plantillas-kpis-excel','Indicadores comerciales, financieros, de talento humano y operación.','Plantillas con fórmulas protegidas y gráficos automáticos para empezar a medir sin programar.','25 archivos .xlsx listos para usar\nFichas técnicas de cada indicador\nGráficos que se actualizan solos\nDescarga inmediata','Digital',89000.00,129000.00,NULL,0,'fa-file-excel','#15803d','prod_02dc7f5e2622be0f.jpg',0,'Activo',312,'2026-09-20 11:59:17',NULL),(5,3,'CUR-PBI-01','Curso Power BI de cero a experto (40 h)','curso-power-bi-cero-experto','Aprende a modelar, calcular con DAX y publicar tableros profesionales.','Curso práctico con casos reales de empresas colombianas, clases en vivo grabadas y proyecto final revisado por un consultor.','40 horas de clases grabadas y en vivo\nProyecto final con retroalimentación\nCertificado digital de DataSense\nAcceso a la comunidad durante 6 meses','Curso',459000.00,690000.00,NULL,0,'fa-graduation-cap','#7c3aed','prod_221c271bbbc35508.jpg',1,'Activo',540,'2026-09-20 11:59:17',NULL),(6,3,'CUR-SQL-02','Curso SQL para análisis de datos (24 h)','curso-sql-analisis-datos','Consultas, uniones, funciones de ventana y buenas prácticas.','Desde SELECT hasta funciones de ventana, con bases de datos de práctica y retos semanales.','24 horas de clases\nBases de datos de práctica\nRetos semanales calificados\nCertificado digital','Curso',329000.00,NULL,NULL,0,'fa-database','#0369a1','prod_b2e850a8dc774e9e.jpg',0,'Activo',276,'2026-09-20 11:59:17',NULL),(7,3,'CUR-PY-03','Curso Python y Machine Learning aplicado (36 h)','curso-python-machine-learning','Pandas, scikit-learn y modelos predictivos para el negocio.','Construye modelos de pronóstico, segmentación y clasificación con datos reales y llévalos a producción.','36 horas de clases\nNotebooks descargables\nProyecto de pronóstico de demanda\nCertificado digital','Curso',549000.00,NULL,NULL,0,'fa-brain','#be185d','prod_7516fc0d60c7ec11.jpg',0,'Activo',198,'2026-09-20 11:59:17',NULL),(8,4,'LIC-CLD-01','DataSense Cloud · Plan Pyme (12 meses)','datasense-cloud-plan-pyme','Tus tableros publicados y actualizados en la nube, con usuarios ilimitados.','Hospedaje gestionado de tableros, actualización programada de datos y soporte con ANS.','Hasta 10 tableros publicados\nActualización automática diaria\nSoporte con ANS de 8 horas hábiles\nCopias de seguridad diarias','Licencia',1890000.00,NULL,NULL,0,'fa-cloud','#0A2E4F','prod_8c1cba76172522bf.jpg',1,'Activo',64,'2026-09-20 11:59:17',NULL),(9,4,'LIC-BOT-02','SenseBot · Chatbot con IA para tu sitio (12 meses)','sensebot-chatbot-ia','Atiende clientes 24/7, captura datos de contacto y agenda citas.','El mismo asistente que usa DataSense, configurado con la información de tu empresa.','Instalación en tu sitio web\nEntrenamiento con tus preguntas frecuentes\nPanel de conversaciones\nSoporte durante 12 meses','Licencia',1190000.00,NULL,NULL,0,'fa-robot','#0891b2','prod_0eaffaf05858ea98.jpg',0,'Activo',38,'2026-09-20 11:59:17',NULL),(10,5,'SRV-DIA-01','Diagnóstico express de calidad de datos','diagnostico-express-calidad-datos','En 5 días hábiles sabrás qué tan confiables son tus datos y qué corregir primero.','Revisión de completitud, duplicados, consistencia y gobierno de datos con un informe ejecutivo priorizado.','Revisión de hasta 3 fuentes de datos\nInforme ejecutivo con hallazgos priorizados\nHoja de ruta de 90 días\nReunión de entrega de resultados','Servicio',1490000.00,NULL,NULL,0,'fa-stethoscope','#b45309','prod_e02f547b510c01c0.jpg',0,'Activo',25,'2026-09-20 11:59:17',NULL),(11,5,'SRV-HRS-02','Bolsa de 10 horas de consultoría analítica','bolsa-10-horas-consultoria','Horas con un consultor senior para resolver lo que necesites.','Úsalas en ajustes de tableros, modelos, automatizaciones o acompañamiento a tu equipo. Vigencia de 6 meses.','10 horas con consultor senior\nAgenda en línea\nInforme de actividades\nVigencia de 6 meses','Servicio',1428000.00,NULL,NULL,0,'fa-user-clock','#334155','prod_206c884ee2fe9dc6.jpg',0,'Activo',52,'2026-09-20 11:59:17',NULL),(12,5,'SRV-API-03','Integración API express (hasta 2 sistemas)','integracion-api-express','Conectamos tu ERP, CRM o tienda con tus tableros en 10 días hábiles.','Desarrollo backend de la integración, monitoreo y documentación técnica entregada al equipo de TI.','Integración de hasta 2 sistemas\nMonitoreo y alertas de fallas\nDocumentación técnica\nGarantía de 60 días','Servicio',2380000.00,NULL,NULL,0,'fa-plug','#4338ca','prod_58f5a44073f502b2.jpg',0,'Activo',17,'2026-09-20 11:59:17',NULL),(13,6,'IOT-SEN-01','Kit IoT de sensores ambientales','kit-iot-sensores-ambientales','Temperatura y humedad en tiempo real conectados a tu tablero.','Cinco sensores inalámbricos con batería de larga duración y envío de datos a la nube de DataSense.','5 sensores de temperatura y humedad\nConcentrador wifi\nConexión a DataSense Cloud por 3 meses\nEnvío a toda Colombia','Físico',689000.00,NULL,25,1,'fa-temperature-half','#0e7490','prod_c9ca02e33cf7cf27.jpg',1,'Activo',33,'2026-09-20 11:59:17',NULL),(14,6,'IOT-GW-02','Gateway LoRaWAN para captura de datos en campo','gateway-lorawan-datos-campo','Cobertura de hasta 10 km para sensores en zonas rurales o plantas.','Ideal para agro, acueductos y logística: recibe datos de cientos de sensores sin depender de wifi.','Gateway LoRaWAN de 8 canales\nAntena exterior y kit de montaje\nConfiguración remota\nGarantía de 12 meses','Físico',1249000.00,NULL,8,1,'fa-tower-broadcast','#475569','prod_4ea400dbdb8ed287.jpg',0,'Activo',12,'2026-09-20 11:59:17',NULL),(15,6,'IOT-TAB-03','Pantalla táctil de 32\" para tableros en planta','pantalla-tactil-32-tableros','Muestra tus indicadores en la planta o la sala de juntas.','Pantalla industrial con reproductor integrado que abre tus tableros publicados al encender.','Pantalla táctil de 32 pulgadas\nReproductor integrado\nSoporte de pared\nConfiguración de tableros incluida','Físico',2150000.00,2490000.00,4,1,'fa-display','#1e293b','prod_97a27229aef2cb07.jpg',0,'Activo',9,'2026-09-20 11:59:17',NULL),(16,6,'HW-RPI-04','Mini PC para kioscos de indicadores','mini-pc-kioscos-indicadores','Equipo compacto para mostrar tableros en televisores.','Arranca directo en tus tableros, con actualización automática y administración remota.','Mini PC con sistema preconfigurado\nCable HDMI y fuente\nAdministración remota\nGarantía de 12 meses','Físico',890000.00,NULL,0,1,'fa-microchip','#64748b','prod_0f2c6f64abcb8e60.jpg',0,'Activo',21,'2026-09-20 11:59:17',NULL),(17,1,'BIO-KIT-08','Kit bienestar diario (colágeno, té verde y espirulina)','kit-bienestar-diario','Tres productos naturales para acompañar tu rutina saludable, con ahorro frente a comprarlos por separado.','Incluye colágeno hidrolizado con vitamina C, té verde con moringa y jengibre y espirulina orgánica. Complementa una alimentación balanceada y actividad física.','Colágeno hidrolizado con vitamina C (500 g)\nTé verde con moringa y jengibre (30 sobres)\nEspirulina orgánica en polvo (250 g)\nGuía de hábitos saludables','Físico',199000.00,225000.00,25,1,'fa-heart-pulse','#e11d48','prod_69529b05cb80385a.jpg',1,'Activo',58,'2026-09-20 11:59:17',NULL),(18,1,'BIO-CAF-01','Café orgánico con ganoderma · 250 g','cafe-organico-ganoderma','Café colombiano de altura con extracto de hongo ganoderma: sabor suave y energía para tu día.','Café 100 % arábica cultivado sin agroquímicos, mezclado con extracto de ganoderma. Molienda media para cafetera o prensa francesa.','Café arábica orgánico colombiano\nExtracto de ganoderma\nMolienda media · 250 g\nEmpaque con válvula de frescura','Físico',58000.00,NULL,80,1,'fa-mug-saucer','#78350f','prod_26f255145bb0f4a7.jpg',0,'Activo',96,'2026-09-20 11:59:17',NULL),(19,1,'BIO-COL-02','Colágeno hidrolizado con vitamina C · 500 g','colageno-hidrolizado-vitamina-c','Polvo sin sabor para mezclar en tus bebidas; aporta proteína y vitamina C.','Colágeno hidrolizado tipo I y III con vitamina C. Una porción diaria disuelta en agua, jugo o café.','500 g · 33 porciones\nColágeno tipo I y III\nCon vitamina C\nSin azúcar añadida','Físico',119000.00,139000.00,50,1,'fa-jar','#db2777','prod_eb138292848c12c0.jpg',0,'Activo',124,'2026-09-20 11:59:17',NULL),(20,1,'BIO-SPI-03','Espirulina orgánica en polvo · 250 g','espirulina-organica-polvo','Microalga rica en proteína vegetal, hierro y vitaminas del complejo B.','Espirulina cultivada de forma orgánica y secada a baja temperatura. Ideal en batidos, jugos o yogur.','250 g · 50 porciones\nProteína vegetal\nCultivo orgánico certificado\nSin aditivos','Físico',72000.00,NULL,60,1,'fa-seedling','#16a34a','prod_28684b7c61c283c6.jpg',0,'Activo',71,'2026-09-20 11:59:17',NULL),(21,1,'BIO-OMG-04','Omega 3 de aceite de pescado · 60 cápsulas','omega-3-aceite-pescado','EPA y DHA en cápsulas blandas de fácil consumo.','Aceite de pescado de aguas frías purificado, con EPA y DHA. Dos cápsulas diarias con las comidas.','60 cápsulas blandas\nEPA y DHA\nAceite purificado\nPara 30 días','Físico',68000.00,NULL,70,1,'fa-capsules','#0284c7','prod_863180704a15281c.jpg',0,'Activo',83,'2026-09-20 11:59:17',NULL),(22,1,'BIO-TEV-05','Té verde con moringa y jengibre · 30 sobres','te-verde-moringa-jengibre','Infusión natural y refrescante para cualquier momento del día.','Mezcla de té verde, hojas de moringa y jengibre deshidratado, en sobres individuales biodegradables.','30 sobres individuales\nTé verde, moringa y jengibre\nSobres biodegradables\nSin azúcar','Físico',34000.00,NULL,120,1,'fa-leaf','#15803d','prod_65811eb0509c41cd.jpg',0,'Activo',152,'2026-09-20 11:59:17',NULL),(23,1,'BIO-BEB-06','Bebida bioenergética de maca y guaraná · 12 unidades','bebida-bioenergetica-maca-guarana','Energía de origen natural, sin colorantes artificiales.','Bebida lista para tomar con maca andina, guaraná y panela, endulzada naturalmente. Presentación de 12 botellas de 300 ml.','12 botellas de 300 ml\nMaca andina y guaraná\nSin colorantes artificiales\nEndulzada con panela','Físico',96000.00,NULL,40,1,'fa-bottle-water','#f97316','prod_fea49e67d2fa39bf.jpg',0,'Activo',47,'2026-09-20 11:59:17',NULL),(24,1,'BIO-ACE-07','Aceite esencial de lavanda para aromaterapia · 30 ml','aceite-esencial-lavanda','Aroma relajante para tus espacios de descanso y trabajo.','Aceite esencial puro de lavanda para difusores y aromaterapia. Uso externo; no ingerir.','30 ml con gotero\nAceite esencial puro\nPara difusor y aromaterapia\nUso externo','Físico',42000.00,NULL,90,1,'fa-spa','#7c3aed','prod_881e4c8aae091ed7.jpg',0,'Activo',64,'2026-09-20 11:59:17',NULL),(25,7,'MRC-CAM-01','Camiseta DataSense «Data Driven»','camiseta-datasense-data-driven','Algodón peinado con el logo bordado. Tallas S a XL.','Camiseta cómoda para el equipo, eventos y hackatones, con el lema que nos mueve: decisiones con datos.','Algodón 100 % peinado\nLogo bordado en el pecho\nTallas S, M, L y XL\nEnvío a toda Colombia','Físico',69000.00,85000.00,60,1,'fa-shirt','#0A2E4F','prod_d2411b972e828561.jpg',0,'Activo',88,'2026-09-20 11:59:17',NULL),(26,7,'MRC-TAZ-02','Taza térmica «Decisiones con datos»','taza-termica-decisiones-con-datos','Acero inoxidable de doble pared: tu café caliente durante toda la reunión.','Taza térmica de 350 ml con tapa hermética y acabado mate con la marca DataSense.','Capacidad de 350 ml\nDoble pared de acero inoxidable\nTapa hermética\nEmpaque de regalo','Físico',45000.00,NULL,120,1,'fa-mug-hot','#3FAE49','prod_4825dff9d75ba910.jpg',0,'Activo',143,'2026-09-20 11:59:17',NULL),(27,7,'MRC-BUZ-03','Buzo con capota DataSense','buzo-capota-datasense','Buzo perchado con capota y bolsillo canguro.','Abrigado y con estilo para las jornadas de oficina o los viajes a campo.','Tela perchada de alta densidad\nCapota con cordón\nBolsillo canguro\nTallas S a XL','Físico',139000.00,NULL,35,1,'fa-vest','#1e293b','prod_d079ac03986e5ed8.jpg',0,'Activo',41,'2026-09-20 11:59:17',NULL),(28,7,'MRC-LIB-04','Libreta de ideas para analistas','libreta-ideas-analistas','Hojas punteadas, plantillas de lienzo analítico y bolsillo interior.','Tapa dura con elástico, 160 hojas punteadas y páginas guía para diseñar indicadores y tableros a mano.','160 hojas punteadas\nPlantillas de lienzo analítico\nTapa dura con elástico\nBolsillo interior','Físico',38000.00,NULL,80,1,'fa-pen-ruler','#7c3aed','prod_e9dc84b47826fdd5.jpg',0,'Activo',67,'2026-09-20 11:59:17',NULL),(29,7,'MRC-KIT-05','Kit de bienvenida DataSense','kit-bienvenida-datasense','Camiseta, taza térmica y stickers en una caja de regalo.','El regalo ideal para nuevos integrantes del equipo de datos o para clientes especiales.','Camiseta «Data Driven»\nTaza térmica de 350 ml\nPaquete de stickers\nCaja de regalo con tarjeta','Físico',139000.00,152000.00,20,1,'fa-gift','#f59e0b','prod_7bf09ed59745ef8b.jpg',1,'Activo',29,'2026-09-20 11:59:17',NULL),(30,8,'REV-INS-12','Revista DataSense Insights · Edición impresa N.° 12','revista-datasense-insights-12','Casos de éxito, tendencias de BI e inteligencia artificial y entrevistas a líderes de datos.','Edición trimestral de 84 páginas a todo color, pensada para gerentes y equipos que toman decisiones con datos.','84 páginas a todo color\nCasos de empresas y entidades públicas\nEntrevistas y columnas de expertos\nEnvío a toda Colombia','Físico',32000.00,NULL,150,1,'fa-newspaper','#0369a1','prod_a637ebb09f378686.jpg',0,'Activo',210,'2026-09-20 11:59:17',NULL),(31,8,'REV-DIG-13','Suscripción anual a la revista digital (6 ediciones)','suscripcion-revista-digital-anual','Recibe cada edición en tu correo, con acceso al archivo de números anteriores.','Seis ediciones al año en formato digital interactivo, con material descargable y plantillas exclusivas para suscriptores.','6 ediciones digitales al año\nArchivo de ediciones anteriores\nPlantillas exclusivas para suscriptores\nDescuentos en eventos','Digital',79000.00,96000.00,NULL,0,'fa-book-open-reader','#0f766e','prod_3cb06eb9388eb8aa.jpg',0,'Activo',176,'2026-09-20 11:59:17',NULL),(32,8,'EBK-KPI-14','E-book · Guía práctica de KPIs para gerentes','ebook-guia-kpis-gerentes','Cómo elegir, medir y presentar los indicadores que de verdad mueven el negocio.','Guía de 120 páginas con fichas de indicadores por área, errores comunes y ejemplos de tableros ejecutivos.','120 páginas en PDF\nFichas de indicadores por área\nEjemplos de tableros ejecutivos\nDescarga inmediata','Digital',49000.00,NULL,NULL,0,'fa-book-open','#be185d','prod_29ed08c1e3db8bb8.jpg',0,'Activo',254,'2026-09-20 11:59:17',NULL),(33,8,'INF-COL-15','Informe sectorial · Analítica de datos en Colombia 2026','informe-analitica-datos-colombia-2026','Madurez analítica por sector, inversión y talento: cifras para planear tu estrategia.','Estudio con encuesta a más de 300 organizaciones públicas y privadas, comparativos por sector y recomendaciones.','Informe de 60 páginas en PDF\nDatos por sector y tamaño de empresa\nAnexo en Excel con las cifras\nWebinar de presentación','Digital',190000.00,NULL,NULL,0,'fa-file-lines','#4338ca','prod_7351f5c115c6edd4.jpg',0,'Activo',37,'2026-09-20 11:59:17',NULL),(34,5,'SRV-PSI-04','Charla de psicología para equipos','charla-psicologia-equipos','Charla sobre bienestar, inclusión y desarrollo humano para tu equipo, colegio o entidad.','Dirigida por Marcela Pacheco Parra, psicóloga y magíster en Desarrollo Humano (FLACSO Argentina). Presencial o virtual, con espacio de preguntas y material de apoyo.','Charla presencial o virtual\nTema a elegir: bienestar, inclusión o desarrollo humano\nEspacio de preguntas con la psicóloga\nMaterial de apoyo en PDF','Servicio',650000.00,NULL,NULL,0,'fa-comments','#0f766e',NULL,1,'Activo',0,'2026-11-15 09:00:00',NULL);
/*!40000 ALTER TABLE `tienda_productos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tienda_resenas`
--

DROP TABLE IF EXISTS `tienda_resenas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tienda_resenas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `producto_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `calificacion` tinyint(4) NOT NULL,
  `titulo` varchar(100) DEFAULT NULL,
  `comentario` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tienda_resena` (`producto_id`,`usuario_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tienda_resenas`
--

LOCK TABLES `tienda_resenas` WRITE;
/*!40000 ALTER TABLE `tienda_resenas` DISABLE KEYS */;
/*!40000 ALTER TABLE `tienda_resenas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `documento` varchar(20) DEFAULT NULL,
  `correo` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `foto` longblob DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `organizacion` varchar(100) DEFAULT NULL,
  `rol` enum('Admin','Soporte','Cliente','Comercial','Analista','Finanzas','Supervisor') DEFAULT 'Cliente',
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(100) DEFAULT NULL,
  `token_expira` datetime DEFAULT NULL,
  `estado_cuenta` enum('Activo','Vacaciones','Suspendido','Retirado') DEFAULT 'Activo',
  `visto` tinyint(1) DEFAULT 0,
  `cargo` varchar(100) DEFAULT 'Consultor',
  PRIMARY KEY (`id`),
  UNIQUE KEY `correo` (`correo`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'Wilson Orozco',NULL,'admin@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3075558818','DataSense Analytics','Admin','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'Ingeniero de Sistemas'),(2,'Andrea Jaramillo',NULL,'andrea.jaramillo@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3155551169','Soluciones Tecnológicas Andinas S.A.S.','Cliente','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'soporte'),(3,'Simón Ramírez',NULL,'soporte@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3125557136','Colegio Nueva Era','Soporte','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'Consultor'),(4,'Federico Figueroa',NULL,'federico.figueroa@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3025554146','Soluciones Tecnológicas Andinas S.A.S.','Analista','2026-03-09 19:42:49',NULL,NULL,'Suspendido',1,'Consultor'),(5,'Silvia Montoya',NULL,'analista@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3295557910','Banco Cordillera','Analista','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'Consultor'),(6,'Darío Federico Palacio',NULL,'finanzas@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3235557422','DataSense Analytics','Finanzas','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'Consultor'),(7,'Ernesto Henao',NULL,'ernesto.henao@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3165558584','Petroandina Energía S.A.','Cliente','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'Consultor'),(8,'Fabiola Osorio',NULL,'fabiola.osorio@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3155559604','Aseguradora Brisas del Valle','Cliente','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'Consultor'),(9,'Santiago Benítez',NULL,'santiago.benitez@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3285554040','Soluciones Tecnológicas Andinas S.A.S.','Finanzas','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'Consultor'),(10,'Lorena Durán',NULL,'lorena.duran@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3055551545','Alimentos La Pradera S.A.S.','Cliente','2026-03-09 19:42:49',NULL,NULL,'Activo',1,'Consultor'),(12,'Wilson Hernán Ocampo Flórez',NULL,'cliente@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3165554893','Soluciones Tecnológicas Andinas S.A.S.','Cliente','2026-03-09 20:42:04',NULL,NULL,'Activo',1,'Ingeniero de Sistemas'),(13,'Simón Tobón',NULL,'simon.tobon@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3165554893','Fiduciaria Altos del Río','Soporte','2026-03-09 21:50:07',NULL,NULL,'Activo',1,'Consultor'),(14,'Silvia Urrego',NULL,'silvia.urrego@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3155551169','Banco Ciudad Verde','Cliente','2026-03-09 21:50:07',NULL,NULL,'Activo',1,'Consultor'),(15,'samuel mesa',NULL,'samuel.mesa@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3155557127','Corporación Universitaria Horizonte','Soporte','2026-03-10 05:01:49',NULL,NULL,'Activo',1,'Consultor'),(16,'Bibiana Vargas Cardona','1056574956','bibiana.vargas.cardona@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'','Métricas y Datos S.A.S.','Admin','2026-03-15 22:11:07',NULL,NULL,'Suspendido',0,'Consultor'),(19,'SAMUEL CORREA','1000261901','','',NULL,NULL,NULL,'','2026-04-02 07:05:47',NULL,NULL,'Activo',0,'Consultor'),(20,'wilson arbeláez',NULL,'wilson.arbelaez@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'','Soluciones Tecnológicas Andinas S.A.S.','Cliente','2026-04-03 23:19:08',NULL,NULL,'Activo',1,'Consultor'),(24,'Gilberto Gaviria',NULL,'gilberto.gaviria@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3245554468','Soluciones Tecnológicas Andinas S.A.S.','Cliente','2026-04-03 23:59:22',NULL,NULL,'',1,'Consultor'),(25,'arturo yepes',NULL,'arturo.yepes@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,NULL,'Distribuidora El Roble Ltda.','Cliente','2026-05-02 00:36:23',NULL,NULL,'Suspendido',0,'Consultor'),(26,'benjamín londoño',NULL,'benjamin.londono@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,NULL,'Clínica Valle Sereno','Cliente','2026-08-07 10:20:04',NULL,NULL,'Suspendido',0,'Consultor'),(59,'Alejandra Arango',NULL,'supervisor@example.com','$2y$10$7gkFAMC3mIT0BD8Qv7WrY.DKA5HUm0vpeUOmlTTkWCxDwH0z6svMi',NULL,'3275555216','DataSense Analytics','Supervisor','2026-11-15 06:42:30',NULL,NULL,'Activo',1,'Supervisora de servicio');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
