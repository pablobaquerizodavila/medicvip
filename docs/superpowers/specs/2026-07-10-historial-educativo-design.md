# Historial educativo dinámico — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `api.php`, `medico-portal.html`, `pacientes.html`, `index.html`, `schema.sql` + migración
**Estado:** Diseño aprobado

---

## Problema

La educación del médico se guarda en 2 columnas fijas (`medico_especialidad.universidad`, `postgrado`). Se quiere un **historial educativo flexible**: una card aparte donde el médico **agregue o quite** entradas (título universitario, postgrado, doctorado, pasantías, cursos, etc.) según su historial real, y que se muestre en el perfil público.

## Modelo de datos

Nueva columna `medico_especialidad.educacion` **TEXT** (JSON), como `recetas.items`. Guarda un arreglo de entradas:
```json
[{"tipo":"Título universitario","institucion":"UEES","titulo":"Médico Cirujano","anio":"2015"},
 {"tipo":"Postgrado","institucion":"Florida University","titulo":"MD","anio":"2019"}]
```
Cada entrada: `tipo` (string, de una lista), `institucion`, `titulo` (detalle, opcional), `anio` (opcional). Se guarda el arreglo completo (no hay endpoints por fila).

**Tipos:** Título universitario · Postgrado · Especialización · Doctorado · Maestría · Pasantía · Curso · Otro.

**Íconos (público):** Título universitario 🎓 · Postgrado 📜 · Especialización 🩺 · Doctorado 🎖 · Maestría 📘 · Pasantía 🏥 · Curso 📗 · Otro 📄.

## Migración (no perder datos)

- `ALTER TABLE medico_especialidad ADD COLUMN educacion TEXT DEFAULT NULL;`
- Para cada médico con `educacion` NULL: construir el arreglo desde `universidad`/`postgrado` actuales y guardarlo:
  - si `universidad` no vacío → `{"tipo":"Título universitario","institucion":<universidad>}`
  - si `postgrado` no vacío → `{"tipo":"Postgrado","titulo":<postgrado>}`
- Las columnas `universidad`/`postgrado` **se conservan** (no se borran) pero dejan de usarse/mostrarse (backward-compat).

## Backend (`api.php`)

- `medicoPerfil`: agregar `e.educacion` al SELECT; devolver `educacion` ya parseado: `json_decode($row['educacion'] ?: '[]', true) ?: []`.
- `medicoActualizar`: si `isset($data['educacion']) && is_array(...)`, sanitizar cada entrada a `{tipo,institucion,titulo,anio}` (strings, recortar, descartar entradas totalmente vacías) y `UPDATE medico_especialidad SET educacion=? WHERE medico_id=?` con `json_encode(..., JSON_UNESCAPED_UNICODE)`. (No toca las demás columnas; convive con el COALESCE existente.)
- `listarMedicos`: la vista `v_medicos_activos` no expone `educacion` (no se puede ALTER la vista sin root). Por cada médico, consulta extra `SELECT educacion FROM medico_especialidad WHERE medico_id=?`, parsear y adjuntar `$m['educacion']` (igual patrón que disponibilidad/reseñas/proximo_disponible).

## Frontend

### `medico-portal.html` — nueva card "Historial educativo"
- Card **"Historial educativo"** (separada, después de "Especialidad y experiencia"): un contenedor `#edu-lista` con filas dinámicas + botón **➕ Agregar formación**.
- Cada fila: `<select>` tipo, `<input>` institución, `<input>` título/detalle, `<input>` año, y botón **✕** (quitar).
- Estado JS `_educacion` (array). `renderEducacion()` dibuja las filas; `eduAgregar()` añade fila vacía; `eduQuitar(i)` borra; se leen los valores del DOM al guardar (o se mantienen sincronizados).
- `loadPerfil()`: `_educacion = m.educacion || []; renderEducacion();`.
- `guardarPerfil()`: recolectar filas de `#edu-lista` → array `[{tipo,institucion,titulo,anio}]` (descartar filas vacías) → agregar `educacion` al payload.
- **Quitar** de la card "Especialidad y experiencia" los campos `p-universidad` y `p-postgrado` (migrados a la lista). **Mantener** idiomas y años de experiencia. Quitar también su populate en `loadPerfil` y su envío en `guardarPerfil`.

### `pacientes.html` / `index.html` — card público
- Reemplazar las 2 líneas fijas `🎓 universidad` / `📜 postgrado` por el render de `d.educacion` / `m.educacion`: por cada entrada, una línea `{ícono} {institucion}{ · titulo si hay}{ (año) si hay}`.
- Helper `educacionHtml(arr)`: si vacío → '' (nada). Máx. razonable de líneas (todas; son pocas).
- `d.educacion` / `m.educacion` vienen de `listar_medicos`.

## Casos borde

| Caso | Manejo |
|---|---|
| Médico sin educación | Lista vacía; card público no muestra sección educativa. |
| Entrada con solo tipo (sin institución/título) | Se descarta al guardar (vacía). |
| JSON inválido en DB | `json_decode(...) ?: []` → arreglo vacío. |
| `educacion` no enviado en el payload | `medico_actualizar` no toca la columna (guardado desde otra pantalla no la borra). |
| Migración corrida 2 veces | Solo siembra donde `educacion IS NULL`; idempotente. |

## Fuera de alcance
- Reordenar entradas con drag&drop (se agregan/quitan; el orden es el de inserción).
- Subir diplomas/certificados (archivos).
- Exponer `educacion` vía la vista (requiere root; se usa consulta extra).

## Criterios de aceptación
1. El médico ve una card "Historial educativo" separada, con su educación actual (UESS, MD Florida U migrados) como entradas.
2. Puede agregar y quitar entradas y guardar; persiste en `medico_especialidad.educacion`.
3. El perfil público (pacientes.html e index.html) muestra la lista de educación con íconos por tipo.
4. Guardar el perfil sin tocar la educación no la borra.
5. Un médico sin educación no rompe nada (portal ni público).
