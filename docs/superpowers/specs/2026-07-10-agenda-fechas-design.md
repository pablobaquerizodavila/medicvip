# Agenda por fechas (calendario del médico) — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `schema.sql`, `api.php`, `pacientes.html`, `medico-portal.html`, `index.html` + migración SQL en el NAS
**Estado:** Diseño aprobado

---

## Problema

Hoy una reserva guarda un **string** de slot semanal (`horario` = "Lunes 16:00"), sin fecha. Por eso la misma hora, una vez reservada, se bloquea indefinidamente y no hay una agenda real. Se necesita mover a **fechas concretas**: cada cita tiene fecha+hora, la misma hora semanal se reabre cada semana, y el médico ve un **calendario** de sus citas.

---

## Decisiones tomadas (confirmadas)

| Tema | Decisión |
|------|----------|
| Ventana de agendamiento | **4 semanas** hacia adelante. |
| Vista de agenda del médico | **Calendario semanal (grid)** con navegación semana anterior/siguiente. |
| Bloqueos/excepciones | **Sí**: el médico puede bloquear rangos de fechas (vacaciones). |
| Plantilla de disponibilidad | Se mantiene `medico_disponibilidad` (semanal) como fuente; se **proyecta** a fechas. |
| Semana del calendario | Lunes → Domingo. |
| Duración de la cita | Se usa `medico_pago.duracion_minutos` (que el médico configura: 20/30/… min). Los slots se muestran como **rango** (ej. 16:00–16:30). La duración debe ser **editable** en el perfil del médico. |
| Reservas legadas | Se **limpian** las reservas de prueba (string sin `inicio`) para empezar la agenda limpia. |

---

## Modelo de datos

### `reservas` (extender)
```sql
ALTER TABLE `reservas`
  ADD COLUMN `inicio` datetime DEFAULT NULL,
  ADD KEY `idx_medico_inicio` (`medico_id`,`inicio`);
```
- Las reservas nuevas guardan `inicio` (fecha+hora) y `horario` (string legible para compatibilidad y emails, p.ej. "Lun 14-jul 16:00").
- Las reservas antiguas (solo `horario`, sin `inicio`) quedan como legado; siguen mostrándose en las listas por `horario`, pero no aparecen en la agenda por fechas.

### `medico_bloqueos` (nueva)
```sql
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
```

---

## Lógica de generación de slots

Helper `generarSlotsDisponibles(int $medicoId, int $dias = 28): array`:
- Lee `medico_disponibilidad` (dia_semana, hora) con `activo=1`.
- Lee `medico_bloqueos` (rangos) del médico.
- Lee los `inicio` ya reservados: `SELECT inicio FROM reservas WHERE medico_id=? AND estado_consulta='agendada' AND inicio IS NOT NULL`.
- Mapa de día de semana: `date('N')` → `[1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo']` (con acentos, para casar con el enum).
- Para cada día desde hoy hasta `+$dias`: para cada slot de la plantilla que caiga en ese día de semana, construir `inicio = fecha + ' ' + hora + ':00'`. Incluir si: `inicio` es **futuro** (> ahora), la fecha **no** cae en un rango bloqueado, y el `inicio` **no** está ya reservado.
- Cada slot incluye la **duración** del médico (`medico_pago.duracion_minutos`, default 30) y la hora fin calculada (`fin = hora + duracion`).
- Devolver `[{fecha:'2026-07-14', dia:'Lunes', hora:'16:00', fin:'16:30', duracion:30, inicio:'2026-07-14 16:00:00', label:'Lun 14 jul · 16:00–16:30'}, ...]` ordenado cronológicamente.

---

## Backend (`api.php`)

### `horarios_disponibles` (POST, público)
Entrada: `{medico_id}`. Devuelve `generarSlotsDisponibles(medico_id, 28)`. Público (es info de disponibilidad; agendar sí requiere login).

### `crearReserva` (modificar)
- Requiere `X-Paciente-Token` (ya). El body ahora trae `inicio` (datetime `YYYY-MM-DD HH:MM:SS`) además de `medico_id`, `motivo`, `metodo_pago`, `codigo?`.
- Valida `inicio`: formato válido, **futuro**, dentro de la ventana de 4 semanas, no en fecha bloqueada, y **no ocupado** (`SELECT id FROM reservas WHERE medico_id=? AND inicio=? AND estado_consulta='agendada'` → si existe, rechazar "Ese horario ya fue tomado").
- (Recomendado) validar que `inicio` corresponde a un slot real de la plantilla (día de semana + hora en `medico_disponibilidad`).
- Guarda `reservas.inicio = <datetime>` y `reservas.horario = <string legible>` (ej. "Lun 14-jul 16:00", derivado de `inicio`).
- El resto (cortesía, custodia, emails, sala de video) igual.

### `medico_agenda` (POST, `X-Medico-Token`)
Entrada: `{semana}` (offset entero; 0 = semana actual, 1 = siguiente, -1 = anterior). Calcula el rango lunes–domingo de esa semana y devuelve:
```
{ desde:'2026-07-13', hasta:'2026-07-19',
  plantilla:[{dia_semana,hora}...],
  citas:[{reserva_id, inicio, paciente_id, paciente, estado_consulta, motivo}...],   // reservas del médico con inicio en esa semana
  bloqueos:[{id, fecha_desde, fecha_hasta, motivo}...] }                              // bloqueos que solapan la semana
```
El frontend arma el grid.

### `medico_bloqueo_crear` (POST, token)
Entrada: `{fecha_desde, fecha_hasta, motivo?}`. Valida fechas (desde ≤ hasta). Inserta.

### `medico_bloqueo_eliminar` (POST, token)
Entrada: `{bloqueo_id}`. Borra si es del médico autenticado.

### `listarMedicos` (ajustar)
Quitar el filtro de "ocultar horas ocupadas" agregado antes (ya no aplica: la ocupación es por fecha). `disponibilidad` vuelve a ser la plantilla semanal completa (mostrada como "días de atención"). Opcional: agregar `proximo_disponible` = primer slot de `generarSlotsDisponibles(medico_id,28)` (o null) para el card.

---

## Frontend

### `pacientes.html` (agendamiento)
- El selector de horario del modal de reserva pasa a **slots con fecha**: al abrir, `POST horarios_disponibles {medico_id}` y mostrar los slots **agrupados por día** (ej. encabezado "Lun 14 jul" con chips de horas). El paciente elige un slot concreto (guarda su `inicio`).
- Si no hay slots: "Este médico no tiene horarios disponibles en las próximas 4 semanas."
- Al confirmar, `crearReserva` con `inicio` (en vez del string de slot semanal). Conserva el gate de login, cortesía y método de pago.
- El card del médico muestra "Próximo disponible: {label}" (de `proximo_disponible`) en lugar de los chips semanales.

### `medico-portal.html` (Agenda)
- Nueva sección **"📅 Agenda"** (nav-item + sección).
- **Calendario semanal (grid):** 7 columnas (Lun–Dom de la semana seleccionada), encabezado con día + fecha. En cada columna, los slots del médico ese día de semana (de la plantilla), cada celda con estado:
  - **Reservado** (hay cita con `inicio` en esa fecha+hora): coloreado, con nombre del paciente + link a su expediente.
  - **Bloqueado** (fecha en un rango de bloqueo): gris "Bloqueado".
  - **Libre**: celda tenue "Libre".
- Navegación **← semana anterior / semana siguiente →** (mueve `semana`), y un indicador del rango de fechas.
- **Gestión de bloqueos:** botón "Bloquear días" (modal: desde, hasta, motivo → `medico_bloqueo_crear`) y lista de bloqueos activos con "Quitar" (`medico_bloqueo_eliminar`).
- Cada celda/slot muestra el **rango horario** (ej. 16:00–16:30) según `duracion_minutos`.
- Datos vía `medico_agenda(semana)` (incluir `duracion_minutos` en la respuesta).
- **Duración de la consulta:** verificar que en **"Mi perfil"** del portal médico exista el campo **duración (minutos)** editable (viene de `medico_pago.duracion_minutos`, ya soportado por `medico_actualizar`). Si no está expuesto en la UI, agregarlo (select 15/20/30/45/60 min).

### `index.html`
- En los cards de médicos destacados, mostrar "Próximo disponible: {label}" si `proximo_disponible` viene (sino, "Agenda disponible").

---

## Compatibilidad y migración

- **Limpieza:** borrar las reservas de prueba legadas (las que tienen `horario` string y `inicio` NULL) para empezar la agenda limpia — `DELETE FROM reservas WHERE inicio IS NULL` tras aplicar la migración (son datos de prueba pre-lanzamiento).
- `reservar_emergencia` NO cambia (es inmediata, no usa slots con fecha).
- Los crons (recordatorios/reembolsos) siguen usando `limite_confirmacion`; sin cambios.

---

## Casos borde

| Caso | Manejo |
|------|--------|
| Dos pacientes toman el mismo `inicio` a la vez | Chequeo `WHERE inicio=? AND estado_consulta='agendada'`; el segundo recibe "ya fue tomado". |
| Slot en fecha bloqueada | No se genera / se rechaza al reservar. |
| Médico sin disponibilidad configurada | Sin slots; el paciente ve el aviso. |
| `inicio` en el pasado o fuera de 4 semanas | Rechazado. |
| Bloqueo con desde > hasta | Rechazado. |
| Reserva legada (sin `inicio`) | No aparece en la agenda por fechas; sí en "Mis reservas". |

---

## Archivos a modificar / crear

- `schema.sql` — `reservas.inicio` + tabla `medico_bloqueos` (documental).
- Migración SQL en el NAS.
- `api.php` — `generarSlotsDisponibles`, `horarios_disponibles`, `crearReserva` (inicio), `medico_agenda`, `medico_bloqueo_crear/eliminar`, ajuste `listarMedicos`.
- `pacientes.html` — selector de slots con fecha + reservar con `inicio` + card "próximo disponible".
- `medico-portal.html` — sección Agenda (grid semanal + bloqueos).
- `index.html` — "próximo disponible" en cards.

---

## Fuera de alcance (roadmap)

Reprogramar/cancelar cita desde la agenda con drag&drop, duración variable por especialidad, sincronización con Google Calendar, recordatorios por WhatsApp, franjas horarias configurables (working hours) en vez de slots discretos.

---

## Criterios de aceptación

1. El paciente ve horarios **con fecha** (próximas 4 semanas), agrupados por día, y agenda uno concreto.
2. La misma hora semanal se reabre en semanas distintas (no se bloquea para siempre).
3. Dos reservas al mismo `inicio` no coexisten (la segunda se rechaza).
4. El médico ve su **calendario semanal** con citas (paciente), libres y bloqueados, navegable por semana.
5. El médico bloquea un rango de fechas y esos días dejan de ofrecer slots; puede quitar el bloqueo.
6. Un slot en fecha pasada, fuera de ventana o bloqueada no se puede reservar.
