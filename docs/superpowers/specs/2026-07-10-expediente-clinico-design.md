# Expediente clínico (Fase 1, perspectiva médico) — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `schema.sql`, `api.php`, `medico-portal.html`, `paciente-portal.html` + página nueva `expediente-paciente.html` + migración SQL en el NAS
**Estado:** Diseño aprobado

---

## Problema

Hoy el "historial médico" del paciente es solo la data **autoreportada** al registrarse. Falta el **registro clínico que crece con cada consulta**: qué diagnosticó y recetó el médico, la evolución de los tratamientos (y si funcionaron o no), signos vitales y documentos. Y falta una **vista consolidada para el médico** que muestre lo clínicamente importante en segundos.

Esta Fase 1 refuerza lo existente (`consulta_notas`) y agrega tratamientos con resultado, signos vitales, documentos y un expediente clínico consolidado. **No** es un EMR hospitalario completo — eso es roadmap (ver "Fuera de alcance").

---

## Decisiones tomadas (confirmadas)

| Tema | Decisión |
|------|----------|
| Enfoque | Fase 1 núcleo, incremental. |
| Módulos | Los 4: tratamientos+resultado, vista clínica del médico, signos vitales, documentos/adjuntos. |
| Diagnóstico | Texto libre + campo CIE-10 opcional (texto, sin catálogo). |
| Edición/auditoría | El médico puede editar sus entradas; auditoría inmutable = fase posterior. |
| Documentos | Subida base64 (tope ~8 MB), ver/descargar; sin visor DICOM. |
| Acceso | Un médico ve/edita el expediente de un paciente con el que tiene ≥1 reserva (mismo modelo de privacidad ya existente). |

---

## Modelo de datos

### `consulta_notas` (extender)
```sql
ALTER TABLE `consulta_notas`
  ADD COLUMN `plan` text DEFAULT NULL,
  ADD COLUMN `proximo_control` date DEFAULT NULL,
  ADD COLUMN `cie10` varchar(120) DEFAULT NULL;
```

### `tratamientos` (nueva)
```sql
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
```

### `signos_vitales` (nueva)
```sql
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
```

### `documentos` (nueva)
```sql
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
```

Todas con `paciente_id` FK CASCADE. `reserva_id` queda como referencia suave (sin FK) para no perder la nota/tratamiento si se borra una reserva.

---

## Backend (`api.php`)

Helper `checkRelacionMedicoPaciente($medicoId, $pid)`: verifica `SELECT id FROM reservas WHERE medico_id=? AND paciente_id=? LIMIT 1`; si no hay → `jsonError(403)`. Reutilizado por todos los endpoints de expediente.

### `medico_expediente` (POST, `X-Medico-Token`)
Entrada: `{paciente_id}`. Verifica relación. Devuelve consolidado:
```
{ paciente:{id,nombre,email,telefono,cedula,fecha_nacimiento,genero,ciudad,foto?,edad},
  historial:{...paciente_historial...},
  consultas:[{reserva_id,horario,creado_en,medico,diagnostico,cie10,indicaciones,plan,proximo_control,notas}...],
  tratamientos:[{id,medicamento,dosis,frecuencia,via,duracion,fecha_inicio,estado,resultado,nota_cierre,fecha_cierre,medico}...],
  vitales:[{...,registrado_en}...],
  documentos:[{id,tipo,titulo,archivo,mime,observaciones,creado_en}...] }
```
Las **alertas** (alergias/crónicas) las deriva el frontend desde `historial`.

### `medico_tratamiento_crear` (POST, token)
`{paciente_id, reserva_id?, medicamento, dosis, frecuencia, via, duracion, fecha_inicio}` → verifica relación, inserta (estado 'activo', resultado 'pendiente').

### `medico_tratamiento_actualizar` (POST, token)
`{tratamiento_id, estado?, resultado?, nota_cierre?, fecha_cierre?, medicamento?, dosis?, frecuencia?, via?, duracion?}` → verifica que el tratamiento sea de un paciente con el que el médico tiene relación; actualiza. Cerrar = enviar `estado:'finalizado'` + `resultado` + `nota_cierre` (+ fecha_cierre = hoy si no viene).

### `medico_vitales_registrar` (POST, token)
`{paciente_id, reserva_id?, presion_sistolica, presion_diastolica, frecuencia_cardiaca, frecuencia_respiratoria, saturacion_o2, temperatura, peso, estatura, glucosa}` → verifica relación, inserta.

### `medico_documento_subir` (POST, token)
`{paciente_id, reserva_id?, tipo, titulo, archivo_base64, mime?, observaciones?}` → verifica relación; valida tamaño (base64 ≤ ~11 MB ≈ 8 MB de archivo); guarda con helper `guardarDocumentoBase64()` en `uploads/documentos/<hash>.<ext>` (ext desde mime: pdf/jpg/png/webp); inserta fila.

### `medico_documento_eliminar` (POST, token)
`{documento_id}` → verifica relación; borra el archivo del disco y la fila. (Los documentos sí se pueden borrar — pueden ser cargas erróneas; los registros clínicos como tratamientos/consultas no se borran, se editan.)

### `pacientePerfil` (extender)
Agregar `tratamientos` (los del paciente) a la respuesta, para la sección "Mis tratamientos" del portal del paciente.

---

## Frontend

### `expediente-paciente.html` (página nueva, solo médico)
Se abre como `expediente-paciente.html?paciente=<id>`. Requiere `medico_token` en `localStorage` (si no, redirige a `medico-portal.html`). Carga `medico_expediente`. Diseño **dashboard clínico**: fondo claro, tarjetas blancas con bordes suaves, tipografía legible, íconos simples, jerarquía clara, colores semánticos moderados (**rojo** alertas críticas, **amarillo** advertencias, **verde** favorable, **azul** info/navegación). Responsive (PC/tablet/móvil).

Estructura:
- **Encabezado del paciente:** foto/iniciales, nombre, edad (calculada de fecha_nacimiento), sexo, tipo de sangre, teléfono, última consulta.
- **Alertas** (banda superior): alergias (rojo si hay), enfermedades crónicas (amarillo si hay). Si no hay, no satura.
- **Tarjetas resumen:** Diagnósticos recientes (de las consultas) · Tratamientos activos · Signos vitales más recientes.
- **Pestañas internas:** Resumen · Consultas · Tratamientos · Signos vitales · Documentos.
  - *Consultas*: lista cronológica (diagnóstico, CIE-10, indicaciones, plan, próximo control, notas) + botón "Nueva nota de consulta" (elige reserva).
  - *Tratamientos*: lista con estado (chip activo/finalizado/suspendido) y resultado (chip verde/amarillo/rojo); botón "Nuevo tratamiento"; en cada activo, botón "Cerrar/registrar resultado" (modal: resultado + nota de cierre).
  - *Signos vitales*: tabla de registros recientes; botón "Registrar signos vitales".
  - *Documentos*: lista (tipo, título, fecha, ver/descargar, eliminar); botón "Subir documento".
- **Acciones rápidas** (barra): Nueva nota · Nuevo tratamiento · Registrar vitales · Subir documento.

### `medico-portal.html` (modificar)
En "Mis reservas", cada reserva agrega botón **"📋 Expediente"** → abre `expediente-paciente.html?paciente=<paciente_id>` (nueva pestaña o misma). Se conservan los botones "Ver historial" / "Nota clínica" existentes.

### `paciente-portal.html` (modificar)
Nueva sección **"Mis tratamientos"** (o dentro de "Mis citas"): lista de tratamientos con medicamento, dosis, estado y **resultado** (con color). El historial de consultas ya muestra diagnóstico/indicaciones.

---

## Subida de archivos

Helper `guardarDocumentoBase64($b64, $mime)`: valida mime permitido (application/pdf, image/jpeg, image/png, image/webp), decodifica, valida tamaño (≤ 8 MB), guarda en `/volume2/web/medicvip/uploads/documentos/` con nombre aleatorio (`bin2hex(random_bytes(16)).ext`), devuelve la ruta relativa `uploads/documentos/<hash>.<ext>`. Los archivos se sirven por URL directa (nombre aleatorio no adivinable, mismo modelo que las fotos de perfil).

**Limitación conocida (Fase 1):** los documentos bajo el webroot no tienen control de acceso por-archivo (una URL filtrada es accesible). Aceptable para Fase 1; el gate por PHP es endurecimiento futuro.

---

## Seguridad y privacidad

- Todos los endpoints de expediente requieren `X-Medico-Token` y verifican relación médico↔paciente (≥1 reserva); 403 si no.
- El paciente ve sus propios tratamientos vía `checkPaciente`.
- Sin borrado de registros clínicos (tratamientos/consultas/vitales se editan, no se borran); documentos sí se pueden borrar.
- `api.config.php` gitignored; no se tocan `eneural.org`/`panel.eneural.org`.

---

## Casos borde

| Caso | Manejo |
|------|--------|
| Médico abre expediente de paciente sin cita con él | 403. |
| Documento > 8 MB o mime no permitido | Rechazado con mensaje. |
| Tratamiento sin fecha_inicio | Permitido (opcional). |
| Cerrar tratamiento ya finalizado | Se permite re-editar el resultado/nota. |
| Paciente sin consultas/tratamientos aún | El expediente muestra secciones vacías con estado claro. |
| Edad sin fecha_nacimiento | Mostrar "—". |

---

## Archivos a modificar / crear

- `schema.sql` — extender `consulta_notas` + 3 tablas nuevas (documental).
- Migración SQL en el NAS.
- `api.php` — `checkRelacionMedicoPaciente`, `guardarDocumentoBase64`, 6 endpoints nuevos + `medico_expediente` + `pacientePerfil` extendido + carpeta `uploads/documentos/`.
- `expediente-paciente.html` (nueva).
- `medico-portal.html` — botón "Expediente".
- `paciente-portal.html` — sección "Mis tratamientos".

---

## Fuera de alcance (roadmap posterior)

Gráficos de tendencia (peso/glucosa/PA), catálogo CIE-10, línea de tiempo con filtros avanzados, multi-rol (enfermería/lab/farmacia/recepción/admin), firma electrónica, 2FA, auditoría inmutable de cambios, visor DICOM, laboratorios con rangos de referencia estructurados, facturación/seguros, mensajería interna, reportes.

---

## Criterios de aceptación

1. El médico abre el **expediente** de un paciente con el que tiene cita (403 si no) y ve encabezado, alertas, resúmenes y pestañas.
2. El médico crea un **tratamiento** y luego lo **cierra** registrando resultado (resolvió/mejoró/etc.) + nota de cierre.
3. El médico registra **signos vitales** y se ven en el expediente.
4. El médico **sube un documento** (PDF/imagen ≤ 8 MB) y puede verlo/descargarlo/eliminarlo.
5. El médico agrega/edita la **nota de consulta** (diagnóstico, CIE-10, indicaciones, plan, próximo control).
6. El **paciente** ve sus tratamientos con estado y resultado en su portal.
7. El expediente es legible y responsive, con colores semánticos y sin saturación.
8. Ningún endpoint expone datos de un paciente sin relación con el médico.
