# Recetas médicas descargables — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `schema.sql`, `api.php`, `expediente-paciente.html`, `paciente-portal.html` + página nueva `receta.html` + migración SQL en el NAS
**Estado:** Diseño aprobado

---

## Problema

Tras una consulta, el médico registra tratamientos, pero no puede **entregar al paciente una receta formal** (imprimible/descargable con membrete). Se necesita generar recetas con los datos del médico (membrete), del paciente y de los medicamentos, guardarlas (re-imprimibles, en el expediente y en el portal del paciente) y permitir descargarlas.

---

## Decisiones tomadas (confirmadas)

| Tema | Decisión |
|------|----------|
| Método PDF | **Página imprimible** con CSS de impresión + botón "Imprimir / Guardar como PDF" del navegador. Sin librerías nuevas. |
| Origen de los medicamentos | **Pre-llenar** de los tratamientos de la consulta elegida, **editables** (agregar/quitar/modificar). |
| Membrete | MedicOnline + Dr(a). [título nombre apellido], [especialidad], Reg. médico [licencia]; datos del paciente; Rp/ + medicamentos; indicaciones; diagnóstico; folio + fecha; línea de firma. |
| Firma | Manuscrita al imprimir (línea de firma). Firma electrónica = roadmap. |

---

## Modelo de datos

### `recetas` (nueva)
```sql
CREATE TABLE `recetas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `medico_id` int(10) unsigned NOT NULL,
  `reserva_id` int(10) unsigned DEFAULT NULL,
  `diagnostico` text DEFAULT NULL,
  `indicaciones` text DEFAULT NULL,
  `items` longtext DEFAULT NULL,  -- JSON: [{medicamento,dosis,frecuencia,duracion,indicaciones}, ...]
  `fecha_emision` date NOT NULL DEFAULT (curdate()),
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_paciente` (`paciente_id`),
  CONSTRAINT `fk_receta_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- Los medicamentos se guardan como **JSON** en `items` (documento; nunca se consultan individualmente). El backend valida y re-serializa (solo campos permitidos, máx. ~30 renglones).
- El **folio** se deriva del `id` al renderizar: `REC-` + id con ceros a la izquierda (ej. `REC-000123`). No se almacena columna aparte.

---

## Backend (`api.php`)

### `medico_receta_crear` (POST, `X-Medico-Token`)
Entrada: `{paciente_id, reserva_id?, diagnostico?, indicaciones?, items:[{medicamento,dosis,frecuencia,duracion,indicaciones}, ...]}`.
- `checkMedico()` + `checkRelacionMedicoPaciente()`.
- Valida que `items` sea un array no vacío y que cada renglón tenga `medicamento`. Limita a 30 renglones. Sanitiza cada campo (string, recortado).
- Inserta la receta con `items` = `json_encode` de los renglones saneados.
- Devuelve `{id, folio}` (folio derivado del id).

### `receta_ver` (POST, `X-Medico-Token` **o** `X-Paciente-Token`)
Entrada: `{receta_id}`.
- Si viene `X-Paciente-Token`: `checkPaciente()`; la receta debe ser de ese paciente (`recetas.paciente_id == pacienteId`), si no 403.
- Si viene `X-Medico-Token`: `checkMedico()` + `checkRelacionMedicoPaciente(medicoId, recetas.paciente_id)`.
- Devuelve todo para renderizar el membrete:
```
{ folio, fecha_emision, diagnostico, indicaciones, items:[...],
  paciente:{nombre,cedula,fecha_nacimiento,edad,genero},
  medico:{nombre_completo, especialidad, licencia} }
```
(`items` se devuelve ya parseado como array.)

### Extender `medico_expediente` y `pacientePerfil`
Agregar `recetas` (lista resumida: `id, fecha_emision, diagnostico, num_items`) del paciente a ambas respuestas, para listarlas en el expediente y en el portal del paciente.

---

## Frontend

### `receta.html` (página nueva — impresión)
- Lee `id` de la query (`?id=<recetaId>`) y el token de `localStorage` (`medico_token` **o** `paciente_token`, el que exista; si ninguno → mensaje "Inicia sesión").
- `POST receta_ver` con el header del token correspondiente → renderiza:
  - **Membrete:** "MedicOnline" + Dr(a). [nombre] · [especialidad] · Reg. médico [licencia].
  - **Datos del paciente:** nombre · edad · cédula · fecha de emisión · folio.
  - **Rp/** y la tabla/lista de medicamentos (cada uno: medicamento — dosis · frecuencia · duración; indicaciones).
  - **Indicaciones generales** y **diagnóstico** (si hay).
  - **Línea de firma** + nombre y registro del médico.
- Botón **"🖨️ Imprimir / Descargar PDF"** (`window.print()`). CSS `@media print` oculta el botón y optimiza el layout a tamaño carta/A4.

### `expediente-paciente.html` (modificar)
- Nueva pestaña **"Recetas"** (o dentro de Consultas): lista las recetas del paciente (folio, fecha, diagnóstico, nº de medicamentos) con enlace **"Ver / Imprimir"** → abre `receta.html?id=<id>` en pestaña nueva.
- Botón **"➕ Nueva receta"** → modal:
  - `<select>` de consulta (opcional) — al elegir una con tratamientos, **pre-llena** los renglones de medicamentos y el diagnóstico (editables).
  - Renglones de medicamentos dinámicos (medicamento*, dosis, frecuencia, duración, indicaciones) con "＋ agregar renglón" / "quitar".
  - Indicaciones generales.
  - Guardar → `medico_receta_crear` → abre `receta.html?id=<id>` en pestaña nueva.

### `paciente-portal.html` (modificar)
- Nueva sección **"Recetas"**: lista de las recetas del paciente (folio, fecha, diagnóstico) con botón **"Ver / Descargar"** → abre `receta.html?id=<id>` (usa el `paciente_token`).

---

## Seguridad y privacidad

- `medico_receta_crear` y la vista con token de médico verifican relación médico↔paciente (403 si no).
- El paciente solo ve/descarga sus propias recetas (`checkPaciente` + coincidencia de `paciente_id`).
- `receta.html` requiere token válido; sin token no muestra datos.
- Sin dependencias nuevas ni librerías de PDF. `api.config.php` sigue gitignored.

---

## Casos borde

| Caso | Manejo |
|------|--------|
| Receta sin medicamentos | Rechazada (items no vacío). |
| Consulta elegida sin tratamientos | Los renglones quedan vacíos para llenar a mano. |
| Paciente intenta ver receta de otro | 403. |
| Médico sin relación con el paciente | 403. |
| Más de 30 renglones | Se limita a 30. |
| Paciente sin fecha_nacimiento | Edad "—". |

---

## Archivos a modificar / crear

- `schema.sql` — tabla `recetas` (documental).
- Migración SQL en el NAS.
- `api.php` — `medico_receta_crear`, `receta_ver` + `recetas` en `medico_expediente` y `pacientePerfil`.
- `receta.html` (nueva — impresión).
- `expediente-paciente.html` — pestaña/acción "Recetas" + modal "Nueva receta".
- `paciente-portal.html` — sección "Recetas".

---

## Fuera de alcance (roadmap)

Firma electrónica real, código QR de verificación, envío automático de la receta por email/WhatsApp al paciente, catálogo de medicamentos con autocompletar/interacciones, numeración fiscal/legal formal.

---

## Criterios de aceptación

1. El médico crea una receta desde el expediente, pre-llenando medicamentos de una consulta y editándolos.
2. Al guardar, se abre la página imprimible con membrete (médico + MedicOnline), datos del paciente, Rp/ + medicamentos, indicaciones, diagnóstico, folio y línea de firma.
3. El botón "Imprimir / Descargar PDF" abre el diálogo del navegador y permite guardar como PDF.
4. La receta queda listada en el expediente (médico) y re-imprimible.
5. El paciente ve sus recetas en su portal y las descarga.
6. Un médico sin relación o un paciente ajeno no pueden ver la receta (403).
