/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `medico_disponibilidad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `medico_disponibilidad` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `medico_id` int(10) unsigned NOT NULL,
  `dia_semana` enum('Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo') NOT NULL,
  `hora` varchar(10) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_medico_dia_hora` (`medico_id`,`dia_semana`,`hora`),
  CONSTRAINT `medico_disponibilidad_ibfk_1` FOREIGN KEY (`medico_id`) REFERENCES `medicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=630 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `medico_especialidad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `medico_especialidad` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `medico_id` int(10) unsigned NOT NULL,
  `especialidad` varchar(100) NOT NULL,
  `subespecialidad` varchar(100) DEFAULT NULL,
  `anos_experiencia` varchar(30) NOT NULL,
  `idiomas` varchar(100) DEFAULT 'Español',
  `universidad` varchar(200) NOT NULL,
  `postgrado` varchar(200) DEFAULT NULL,
  `biografia` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `medico_id` (`medico_id`),
  CONSTRAINT `medico_especialidad_ibfk_1` FOREIGN KEY (`medico_id`) REFERENCES `medicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `medico_pago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `medico_pago` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `medico_id` int(10) unsigned NOT NULL,
  `tarifa` decimal(8,2) NOT NULL,
  `duracion_minutos` tinyint(3) unsigned NOT NULL DEFAULT 30,
  `banco` varchar(100) NOT NULL,
  `tipo_cuenta` enum('Ahorros','Corriente') NOT NULL,
  `numero_cuenta` varchar(30) NOT NULL,
  `cedula_titular` varchar(20) NOT NULL,
  `nombre_titular` varchar(200) NOT NULL,
  `plan_liquidacion` enum('auto','manual') NOT NULL DEFAULT 'auto',
  `frecuencia_pago` varchar(60) NOT NULL DEFAULT 'Por consulta',
  `como_se_entero` varchar(80) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `medico_id` (`medico_id`),
  CONSTRAINT `medico_pago_ibfk_1` FOREIGN KEY (`medico_id`) REFERENCES `medicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `medicos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `medicos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `titulo` varchar(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `apellido` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telefono` varchar(30) NOT NULL,
  `ciudad` varchar(80) NOT NULL,
  `genero` varchar(30) DEFAULT NULL,
  `licencia` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `foto_perfil` varchar(255) DEFAULT NULL,
  `estado` enum('pendiente','activo','suspendido') NOT NULL DEFAULT 'pendiente',
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `disponible_emergencia` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `licencia` (`licencia`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pacientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pacientes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `email` varchar(150) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `edad` tinyint(3) unsigned DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `password_hash` varchar(255) DEFAULT NULL,
  `cedula` varchar(20) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `genero` varchar(30) DEFAULT NULL,
  `ciudad` varchar(80) DEFAULT NULL,
  `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resenas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resenas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reserva_id` int(10) unsigned NOT NULL,
  `medico_id` int(10) unsigned NOT NULL,
  `paciente_id` int(10) unsigned NOT NULL,
  `estrellas` tinyint(3) unsigned NOT NULL,
  `comentario` text DEFAULT NULL,
  `creado_en` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reserva_id` (`reserva_id`),
  KEY `idx_medico` (`medico_id`),
  CONSTRAINT `chk_estrellas` CHECK (`estrellas` between 1 and 5)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reservas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `medico_id` int(10) unsigned NOT NULL,
  `paciente_id` int(10) unsigned NOT NULL,
  `horario` varchar(30) NOT NULL,
  `motivo` text DEFAULT NULL,
  `alergias` text DEFAULT NULL,
  `metodo_pago` varchar(50) DEFAULT NULL,
  `monto_total` decimal(8,2) NOT NULL,
  `comision` decimal(8,2) NOT NULL,
  `monto_medico` decimal(8,2) NOT NULL,
  `estado_pago` enum('pendiente','en_custodia','pagado','reembolsado','exonerado') NOT NULL DEFAULT 'pendiente',
  `estado_consulta` enum('agendada','confirmada','realizada','cancelada','no_realizada') NOT NULL DEFAULT 'agendada',
  `estado_pago_medico` enum('pendiente','transferido') NOT NULL DEFAULT 'pendiente',
  `confirmada_en` datetime DEFAULT NULL,
  `reembolsada_en` datetime DEFAULT NULL,
  `limite_confirmacion` datetime DEFAULT NULL,
  `notas_cancelacion` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `sala_video` varchar(64) DEFAULT NULL,
  `token_acceso` varchar(32) DEFAULT NULL,
  `recordatorio_enviado` tinyint(1) DEFAULT 0,
  `inicio` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_medico_inicio` (`medico_id`,`inicio`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transacciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transacciones` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reserva_id` int(10) unsigned NOT NULL,
  `tipo` enum('custodia','liberacion','reembolso','comision') NOT NULL,
  `monto` decimal(8,2) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `reserva_id` (`reserva_id`),
  CONSTRAINT `transacciones_ibfk_1` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `medico_codigos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `medico_codigos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `medico_id` int(10) unsigned NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `nota` varchar(150) DEFAULT NULL,
  `usos_max` int(10) unsigned NOT NULL DEFAULT 1,
  `usos_count` int(10) unsigned NOT NULL DEFAULT 0,
  `estado` enum('activo','agotado','revocado') NOT NULL DEFAULT 'activo',
  `expira_en` date DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_codigo` (`codigo`),
  KEY `idx_medico` (`medico_id`),
  CONSTRAINT `fk_codigos_medico` FOREIGN KEY (`medico_id`) REFERENCES `medicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `codigo_usos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `codigo_usos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo_id` int(10) unsigned NOT NULL,
  `reserva_id` int(10) unsigned NOT NULL,
  `paciente_email` varchar(150) DEFAULT NULL,
  `usado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_codigo` (`codigo_id`),
  CONSTRAINT `fk_uso_codigo` FOREIGN KEY (`codigo_id`) REFERENCES `medico_codigos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paciente_historial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `paciente_historial` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `tipo_sangre` varchar(5) DEFAULT NULL,
  `alergias` text DEFAULT NULL,
  `enfermedades_cronicas` text DEFAULT NULL,
  `medicamentos_actuales` text DEFAULT NULL,
  `cirugias_previas` text DEFAULT NULL,
  `fuma` enum('No','Sí','Ex-fumador') NOT NULL DEFAULT 'No',
  `alcohol` enum('No','Ocasional','Frecuente') NOT NULL DEFAULT 'No',
  `peso` decimal(5,2) DEFAULT NULL,
  `estatura` smallint(5) unsigned DEFAULT NULL,
  `antecedentes_familiares` text DEFAULT NULL,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paciente` (`paciente_id`),
  CONSTRAINT `fk_hist_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `consulta_notas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `consulta_notas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reserva_id` int(10) unsigned NOT NULL,
  `medico_id` int(10) unsigned NOT NULL,
  `paciente_id` int(10) unsigned NOT NULL,
  `diagnostico` text DEFAULT NULL,
  `indicaciones` text DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `plan` text DEFAULT NULL,
  `proximo_control` date DEFAULT NULL,
  `cie10` varchar(120) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reserva` (`reserva_id`),
  KEY `idx_paciente` (`paciente_id`),
  CONSTRAINT `fk_nota_reserva` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `tratamientos`;
CREATE TABLE `tratamientos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `medico_id` int(10) unsigned NOT NULL,
  `reserva_id` int(10) unsigned DEFAULT NULL,
  `medicamento` varchar(200) NOT NULL,
  `dosis` varchar(100) DEFAULT NULL,
  `frecuencia` varchar(100) DEFAULT NULL,
  `via` varchar(60) DEFAULT NULL,
  `duracion` varchar(100) DEFAULT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `estado` enum('activo','finalizado','suspendido') NOT NULL DEFAULT 'activo',
  `resultado` enum('pendiente','resolvio','mejoro','sin_cambio','empeoro') NOT NULL DEFAULT 'pendiente',
  `nota_cierre` text DEFAULT NULL,
  `fecha_cierre` date DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_paciente` (`paciente_id`),
  CONSTRAINT `fk_trat_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `signos_vitales`;
CREATE TABLE `signos_vitales` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `medico_id` int(10) unsigned NOT NULL,
  `reserva_id` int(10) unsigned DEFAULT NULL,
  `presion_sistolica` smallint(5) unsigned DEFAULT NULL,
  `presion_diastolica` smallint(5) unsigned DEFAULT NULL,
  `frecuencia_cardiaca` smallint(5) unsigned DEFAULT NULL,
  `frecuencia_respiratoria` smallint(5) unsigned DEFAULT NULL,
  `saturacion_o2` tinyint(3) unsigned DEFAULT NULL,
  `temperatura` decimal(4,1) DEFAULT NULL,
  `peso` decimal(5,2) DEFAULT NULL,
  `estatura` smallint(5) unsigned DEFAULT NULL,
  `glucosa` smallint(5) unsigned DEFAULT NULL,
  `registrado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_paciente` (`paciente_id`),
  CONSTRAINT `fk_vitales_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `documentos`;
CREATE TABLE `documentos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `medico_id` int(10) unsigned NOT NULL,
  `reserva_id` int(10) unsigned DEFAULT NULL,
  `tipo` varchar(40) NOT NULL DEFAULT 'otro',
  `titulo` varchar(200) DEFAULT NULL,
  `archivo` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `tamano` int(10) unsigned DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_paciente` (`paciente_id`),
  CONSTRAINT `fk_doc_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `recetas`;
CREATE TABLE `recetas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `medico_id` int(10) unsigned NOT NULL,
  `reserva_id` int(10) unsigned DEFAULT NULL,
  `diagnostico` text DEFAULT NULL,
  `indicaciones` text DEFAULT NULL,
  `items` longtext DEFAULT NULL,
  `fecha_emision` date NOT NULL DEFAULT (curdate()),
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_paciente` (`paciente_id`),
  CONSTRAINT `fk_receta_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `medico_bloqueos`;
CREATE TABLE `medico_bloqueos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `medico_id` int(10) unsigned NOT NULL,
  `fecha_desde` date NOT NULL,
  `fecha_hasta` date NOT NULL,
  `motivo` varchar(200) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_medico` (`medico_id`),
  CONSTRAINT `fk_bloqueo_medico` FOREIGN KEY (`medico_id`) REFERENCES `medicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `v_medicos_activos`;
/*!50001 DROP VIEW IF EXISTS `v_medicos_activos`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_medicos_activos` AS SELECT
 1 AS `id`,
  1 AS `nombre_completo`,
  1 AS `email`,
  1 AS `telefono`,
  1 AS `ciudad`,
  1 AS `foto_perfil`,
  1 AS `estado`,
  1 AS `especialidad`,
  1 AS `anos_experiencia`,
  1 AS `idiomas`,
  1 AS `biografia`,
  1 AS `universidad`,
  1 AS `postgrado`,
  1 AS `tarifa`,
  1 AS `duracion_minutos` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `v_reservas_detalle`;
/*!50001 DROP VIEW IF EXISTS `v_reservas_detalle`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `v_reservas_detalle` AS SELECT
 1 AS `id`,
  1 AS `horario`,
  1 AS `monto_total`,
  1 AS `comision`,
  1 AS `monto_medico`,
  1 AS `estado_pago`,
  1 AS `estado_consulta`,
  1 AS `estado_pago_medico`,
  1 AS `confirmada_en`,
  1 AS `reembolsada_en`,
  1 AS `limite_confirmacion`,
  1 AS `notas_cancelacion`,
  1 AS `creado_en`,
  1 AS `medico`,
  1 AS `medico_id`,
  1 AS `paciente`,
  1 AS `email_paciente` */;
SET character_set_client = @saved_cs_client;
/*!50001 DROP VIEW IF EXISTS `v_medicos_activos`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_medicos_activos` AS select `m`.`id` AS `id`,concat(`m`.`titulo`,' ',`m`.`nombre`,' ',`m`.`apellido`) AS `nombre_completo`,`m`.`email` AS `email`,`m`.`telefono` AS `telefono`,`m`.`ciudad` AS `ciudad`,`m`.`foto_perfil` AS `foto_perfil`,`m`.`estado` AS `estado`,`e`.`especialidad` AS `especialidad`,`e`.`anos_experiencia` AS `anos_experiencia`,`e`.`idiomas` AS `idiomas`,`e`.`biografia` AS `biografia`,`e`.`universidad` AS `universidad`,`e`.`postgrado` AS `postgrado`,`p`.`tarifa` AS `tarifa`,`p`.`duracion_minutos` AS `duracion_minutos` from ((`medicos` `m` join `medico_especialidad` `e` on(`e`.`medico_id` = `m`.`id`)) join `medico_pago` `p` on(`p`.`medico_id` = `m`.`id`)) where `m`.`estado` in ('activo','pendiente') */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_reservas_detalle`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_reservas_detalle` AS select `r`.`id` AS `id`,`r`.`horario` AS `horario`,`r`.`monto_total` AS `monto_total`,`r`.`comision` AS `comision`,`r`.`monto_medico` AS `monto_medico`,`r`.`estado_pago` AS `estado_pago`,`r`.`estado_consulta` AS `estado_consulta`,`r`.`estado_pago_medico` AS `estado_pago_medico`,`r`.`confirmada_en` AS `confirmada_en`,`r`.`reembolsada_en` AS `reembolsada_en`,`r`.`limite_confirmacion` AS `limite_confirmacion`,`r`.`notas_cancelacion` AS `notas_cancelacion`,`r`.`creado_en` AS `creado_en`,concat(`m`.`titulo`,' ',`m`.`nombre`,' ',`m`.`apellido`) AS `medico`,`m`.`id` AS `medico_id`,`p`.`nombre` AS `paciente`,`p`.`email` AS `email_paciente` from ((`reservas` `r` join `medicos` `m` on(`m`.`id` = `r`.`medico_id`)) join `pacientes` `p` on(`p`.`id` = `r`.`paciente_id`)) order by `r`.`creado_en` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

