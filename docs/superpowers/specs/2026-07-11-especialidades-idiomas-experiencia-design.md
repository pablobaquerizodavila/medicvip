# Especialidades, idiomas y experiencia profesional editables — spec

**Fecha:** 2026-07-11
**Archivos afectados:** `api.php`, `medico-portal.html`, `pacientes.html`, `index.html`, `schema.sql` + migración
**Estado:** Diseño aprobado

---

## Problema

Hoy el médico tiene una sola especialidad (`select`), un solo campo de idiomas (texto), y "años de experiencia" (dropdown). Se quiere que **especialidades** e **idiomas** sean listas editables (agregar/quitar), y agregar **experiencia profesional** como lista de entradas (cargo/institución/período), igual que el historial educativo.

## Modelo de datos

Nuevas columnas JSON en `medico_especialidad` (TEXT), patrón `educacion`:
- `especialidades` — `["Cardiología","Medicina interna"]`. La **1ª es la principal**.
- `idiomas_lista` — `["Español","Inglés"]`.
- `experiencia` — `[{"cargo":"Cardiólogo","institucion":"Hospital Luis Vernaza","periodo":"2018–2022"}]`.

**Compatibilidad:** las columnas viejas se conservan y se **sincronizan** al guardar:
- `especialidad` (varchar) = `especialidades[0]` (para el filtro y la vista `v_medicos_activos`).
- `idiomas` (varchar) = `implode(', ', idiomas_lista)`.
- `anos_experiencia` (dropdown) se mantiene igual (resumen general, no es lista).

## Migración

```sql
ALTER TABLE medico_especialidad
  ADD COLUMN especialidades TEXT DEFAULT NULL,
  ADD COLUMN idiomas_lista TEXT DEFAULT NULL,
  ADD COLUMN experiencia TEXT DEFAULT NULL;
```
Seed por médico donde `especialidades IS NULL`:
- `especialidades` = `[especialidad]` si `especialidad` no vacío, si no `[]`.
- `idiomas_lista` = `idiomas` partido por comas (trim, sin vacíos); si vacío `[]`.
- `experiencia` = `[]`.
Idempotente (solo donde `especialidades IS NULL`). Con `set_charset('utf8mb4')` en el script (evitar doble-codificación de acentos).

## Backend (`api.php`)

- `medicoPerfil`: agregar `e.especialidades,e.idiomas_lista,e.experiencia` al SELECT; devolver los 3 parseados (`json_decode(... ?: '[]', true) ?: []`).
- `medicoActualizar`: aceptar `especialidades` (array de strings), `idiomas_lista` (array de strings), `experiencia` (array de `{cargo,institucion,periodo}`). Sanitizar (trim, límites, descartar vacíos), `json_encode(..., JSON_UNESCAPED_UNICODE)`, `UPDATE`. Sincronizar `especialidad` = primer elemento no vacío de `especialidades` (si hay); `idiomas` = `implode(', ', idiomas_lista)`. Todo dentro de la transacción existente. Convive con el COALESCE (que ya no recibe `especialidad`/`idiomas` sueltos del portal).
- `listarMedicos`:
  - Ampliar la consulta extra por médico a `SELECT educacion,especialidades,idiomas_lista,experiencia FROM medico_especialidad WHERE medico_id=?`; adjuntar los 4 parseados.
  - **Filtro "coincide con cualquiera":** dejar de filtrar por `WHERE especialidad=?` en SQL. Traer todos, adjuntar las listas, y si viene `especialidad` (≠ Todos), filtrar en PHP: conservar médicos donde `in_array($spec, $m['especialidades'])` **o** `$m['especialidad']===$spec`.

## Frontend

### `medico-portal.html`
- **Card "Especialidad y experiencia"**:
  - Reemplazar el `select` único `p-especialidad` por una **lista dinámica de especialidades** (cada fila: un `select` del catálogo + ✕; botón ➕). La 1ª fila = principal.
  - Reemplazar el input único `p-idiomas` por una **lista dinámica de idiomas** (cada fila: input texto + ✕; botón ➕).
  - Mantener el `select` `p-experiencia` (años) y `p-biografia`.
  - Catálogo de especialidades = las mismas opciones del select actual (const JS `CAT_ESPECIALIDADES`).
- **Card nueva "Experiencia profesional"** (después de "Historial educativo"): lista dinámica de entradas `{cargo, institucion, periodo}` con ➕/✕ (mismo patrón que educación).
- `loadPerfil`: poblar las 3 listas (`_especialidades`, `_idiomas`, `_experiencia`); quitar el populate de `p-especialidad`/`p-idiomas` sueltos.
- `guardarPerfil`: enviar `especialidades`, `idiomas_lista`, `experiencia`; quitar `especialidad`/`idiomas` sueltos del payload.

### `pacientes.html` / `index.html`
- Card público:
  - Especialidad principal grande = `d.especialidades?.[0] || d.spec` + chips de las secundarias (`especialidades[1..]`).
  - Idiomas: `d.idiomas_lista?.join(' · ') || d.languages`.
  - **Experiencia profesional**: helper `experienciaHtml(arr)` que lista `💼 {cargo} · {institucion} · {periodo}` por entrada (omitir partes vacías).
  - `educacionHtml` (ya existe) se mantiene.
- Agregar `especialidades`, `idiomas_lista`, `experiencia` al objeto `d`/`m` desde `listar_medicos`.

## Casos borde

| Caso | Manejo |
|---|---|
| Médico sin especialidades | Fallback a `especialidad` (columna); si vacío, no muestra. Sincronización no rompe. |
| Guardar sin enviar una lista | La columna respectiva no se toca (guardado desde otra pantalla no borra). |
| Especialidad principal vacía | `especialidad` varchar se deja como estaba (no se nulifica; es NOT NULL). |
| Filtro por especialidad secundaria | El médico aparece (match any). |
| Acentos (Cardiología, Español) | `set_charset utf8mb4` en migración; en el pipeline base64 leer con `-Encoding UTF8`. |

## Fuera de alcance
- Reordenar entradas (drag&drop).
- Autocompletar idiomas desde un catálogo (texto libre).
- Exponer las nuevas columnas por la vista (se usa consulta extra + PHP).

## Criterios de aceptación
1. El médico agrega/quita varias especialidades e idiomas, y entradas de experiencia profesional; persisten.
2. `especialidad` (principal) se sincroniza con la 1ª especialidad.
3. El buscador por especialidad muestra al médico si cualquiera de sus especialidades coincide.
4. El perfil público muestra especialidad principal + secundarias, idiomas y experiencia profesional (💼).
5. Guardar sin tocar una lista no la borra.
6. Acentos correctos (sin mojibake).
