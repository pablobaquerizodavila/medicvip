# Editar disponibilidad + horarios de la semana en el card — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `api.php`, `medico-portal.html`, `pacientes.html`, `index.html`
**Estado:** Diseño aprobado

---

## Problema

1. El médico fija su disponibilidad (`medico_disponibilidad`, plantilla semanal) **solo al registrarse**. El portal **no tiene editor** — el registro incluso promete "puedes cambiar tu disponibilidad desde tu panel", pero esa UI nunca se construyó. `guardarPerfil` ni siquiera envía `disponibilidad`.
2. El card público del médico muestra solo **"Próximo disponible: Lun 13 jul · 16:00–16:20"**; se quiere ver **todos los horarios de atención de la semana** que el médico programó.

## Contexto

- `medico_disponibilidad (medico_id, dia_semana, hora, activo)`. `dia_semana` enum con acentos: Lunes/Martes/Miércoles/Jueves/Viernes/Sábado/Domingo. `hora` = "HH:MM". `activo` default 1.
- `listarMedicos` ya devuelve `disponibilidad` = `["Lunes 16:00","Martes 19:00", ...]` (línea 186) y `proximo_disponible`.
- `medico_perfil` (portal) devuelve `disponibilidad` = `[{dia_semana,hora}, ...]`. `medico_agenda` devuelve `plantilla` = `[{dia_semana,hora}, ...]`.
- `medico_actualizar` **sí** soporta `disponibilidad` (DELETE+INSERT) pero actualiza además todos los demás campos del perfil → no sirve para guardar solo horarios.
- El registro (`registro-medico.html`) usa un grid días × bloques: `DAYS=['Lunes'..'Domingo']` (con acento), `SLOTS=['07:00','08:00','09:00','10:00','11:00','12:00','14:00','15:00','16:00','17:00','18:00','19:00']`, con `toggleDay`/`toggleSlot`.

---

## Solución

### Backend (`api.php`)

**Endpoint nuevo `medico_disponibilidad_guardar`** (token médico):
- Body: `{disponibilidad: [{dia, hora}, ...]}`.
- Valida `dia ∈ {Lunes..Domingo}` (con acentos) y `hora` formato `HH:MM`; ignora inválidos.
- `DELETE FROM medico_disponibilidad WHERE medico_id=?` + `INSERT (medico_id,dia_semana,hora)` por cada slot válido (activo default 1).
- Devuelve `{mensaje, slots:<n>}`.
- Nota: no afecta reservas existentes (tienen su propio `inicio`); solo cambia qué horarios nuevos se ofrecen.

**Fix menor:** en `listarMedicos` el `ORDER BY FIELD(dia_semana,"Lunes","Martes","Miercoles",...)` usa "Miercoles"/"Sabado" sin acento → no casan con el enum y esos días quedan al final. Corregir a `"Miércoles"`/`"Sábado"`.

### Frontend

**`medico-portal.html` — sección Agenda:**
- Botón **"⚙️ Editar mis horarios de atención"** (junto a los controles de la Agenda) → abre modal.
- Modal con grid días × bloques (réplica del registro: `DAYS` × `SLOTS`, `toggleDay`/`toggleSlot`), **precargado** con la disponibilidad actual (de `medico_perfil`/`medico_agenda` → `plantilla`/`disponibilidad`, campos `dia_semana`+`hora`).
- Botón "Guardar horarios" → recolecta los slots seleccionados como `[{dia,hora}]` → `POST medico_disponibilidad_guardar` → cierra modal + `loadAgenda()`.
- Reusa el patrón de modal del portal (`mostrarModalPortal`/`cerrarModalPortal`).

**`pacientes.html` — card del médico:**
- Reemplazar la línea "Próximo disponible: …" por los **horarios de atención agrupados por día**, a partir de `d.disponibilidad` (`["Lunes 16:00", ...]`): agrupar por día, ordenar Lun→Dom, formato compacto `Lun 16:00 · Mar 19:00 · Mié 19:00` (día abreviado). Si vacío → "Sin horarios publicados".
- El selector con fecha del modal de reserva **no cambia**.

**`index.html` — card de destacados:**
- Mismo reemplazo usando `m.disponibilidad`.

---

## Casos borde

| Caso | Manejo |
|---|---|
| Médico guarda 0 slots | Se borran todos; el card muestra "Sin horarios publicados" y no genera slots agendables. |
| Slot con día/hora inválido | Se ignora en el guardado. |
| Reserva futura en un horario que el médico quita | La reserva subsiste (tiene `inicio`); solo dejan de ofrecerse nuevos slots a esa hora. |
| Card sin `disponibilidad` | "Sin horarios publicados". |

## Fuera de alcance

- Bloques de media hora o duración variable por día (los `SLOTS` son en punto, como el registro).
- Mostrar fechas concretas de la próxima semana en el card (se muestra la plantilla recurrente; las fechas concretas siguen en el modal de reserva).
- Editor de disponibilidad también en "Mi perfil" (va en Agenda, que es donde se buscó).

## Criterios de aceptación

1. El médico abre "Editar mis horarios de atención" desde Agenda, ve su disponibilidad actual precargada, la modifica y guarda; el cambio persiste en `medico_disponibilidad`.
2. Tras guardar, los slots con fecha que ve el paciente reflejan la nueva disponibilidad.
3. El card público (pacientes.html e index.html) muestra los horarios de la semana agrupados por día, no solo el próximo.
4. Guardar solo la disponibilidad no altera los demás campos del perfil del médico.
5. Un médico sin horarios muestra "Sin horarios publicados" y no ofrece slots.
