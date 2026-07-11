# Cancelar / Reprogramar citas — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `api.php`, `paciente-portal.html`, `medico-portal.html`
**Estado:** Diseño aprobado

---

## Problema

Tras la agenda por fechas (reservas con `inicio`), no hay forma de que el paciente **cancele** o **reprograme** una cita, ni de que el médico cancele por un imprevisto. Solo el admin puede forzar reembolsos/eliminar. Falta el autoservicio.

Además hay un hueco: el cálculo de disponibilidad y el anti-doble-reserva solo descuentan citas `estado_consulta='agendada'`, así que una cita **`confirmada` libera su horario por error**. Se corrige en esta feature.

---

## Contexto del ciclo de vida actual

- **Reservar** → `estado_consulta='agendada'`, `estado_pago='en_custodia'` (o `'exonerado'` en cortesía), `limite_confirmacion = ahora+24h`.
- **Médico confirma** (`confirmar_consulta`) → `confirmada`; si era `en_custodia` → `pagado` + `estado_pago_medico='transferido'` + `confirmada_en`.
- Cron reembolsos: `agendada` con `limite_confirmacion` vencido → `no_realizada` + `reembolsado`.
- Admin: `admin_reembolso` (→ `cancelada`+`reembolsado`), `admin_eliminar_reserva`.
- **No hay pasarela de pago real** todavía: un "reembolso" es un cambio de estado + registro en `transacciones`, no movimiento de dinero.
- Enum `estado_consulta`: `agendada, confirmada, realizada, cancelada, no_realizada`.
- Enum `estado_pago`: `pendiente, en_custodia, pagado, reembolsado, exonerado`.
- Portal del paciente: `paciente-portal.html` (lista sus reservas vía `pacientePerfil`).
- Portal del médico: `medico-portal.html`, sección "Mis reservas" (`medicoReservas`).

---

## Reglas de negocio (aprobadas)

| Regla | Decisión |
|---|---|
| Límite de autoservicio del paciente | **≥ 12 h** antes del inicio. Dentro de las 12 h → bloqueado ("contacta soporte"). |
| Estados cancelables/reprogramables por el paciente | **`agendada` y `confirmada`**. |
| Médico cancela | **Sí**, con motivo, **sin** límite de 12 h (imprevistos). |
| Reprogramar | **Resetea a `agendada`** (reinicia ventana de confirmación de 24 h). |

---

## Transiciones de estado

### Cancelar (paciente) — `paciente_cancelar_reserva {reserva_id}`
- **Auth:** `checkPaciente()`; la reserva debe ser del paciente.
- **Precondición:** `estado_consulta ∈ {agendada, confirmada}`, `inicio` en el futuro, y `inicio - ahora ≥ 12h`. Si no → error explicativo.
- **Efecto:**
  - `estado_consulta = 'cancelada'`, `notas_cancelacion = 'Cancelada por el paciente'`, `reembolsada_en = ahora` (si aplica reembolso).
  - Si `estado_pago ∈ {en_custodia, pagado}` → `estado_pago = 'reembolsado'`, `estado_pago_medico = 'pendiente'`, e `INSERT transacciones (reserva_id, 'reembolso', monto_total, 'Cancelacion por el paciente')`.
  - Si `estado_pago = 'exonerado'` (cortesía) → se mantiene `exonerado` (sin transacción). El uso del código **no** se restituye en esta fase.
  - Libera el horario (automático: la ocupación ya no cuenta `cancelada`).
  - Email al médico (aviso de cancelación).

### Cancelar (médico) — `medico_cancelar_reserva {reserva_id, motivo}`
- **Auth:** `checkMedico()`; la reserva debe ser del médico. `motivo` requerido (1–200 chars).
- **Precondición:** `estado_consulta ∈ {agendada, confirmada}`, `inicio` en el futuro. (Sin límite de 12 h.)
- **Efecto:** igual que cancelar-paciente en cuanto a estados/reembolso, con `notas_cancelacion = 'Cancelada por el médico: ' + motivo`. Email al paciente (disculpa + aviso de reembolso si aplica).

### Reprogramar (paciente) — `paciente_reprogramar_reserva {reserva_id, inicio}`
- **Auth:** `checkPaciente()`; la reserva debe ser del paciente.
- **Precondición:** `estado_consulta ∈ {agendada, confirmada}`, `inicio` **actual** en el futuro y `inicio_actual - ahora ≥ 12h`. Nuevo `inicio` válido para el **mismo médico** (formato datetime, futuro, ≤ ventana de 4 semanas, no en fecha bloqueada, no ocupado por otra cita `agendada/confirmada`, excluyendo la propia).
- **Efecto:**
  - `inicio = nuevo`, `horario = <string legible del nuevo inicio>`.
  - `estado_consulta = 'agendada'`, `confirmada_en = NULL`, `limite_confirmacion = ahora+24h`.
  - Si `estado_pago = 'pagado'` → `estado_pago = 'en_custodia'`, `estado_pago_medico = 'pendiente'` (se re-retiene hasta reconfirmar). `en_custodia` y `exonerado` se mantienen.
  - Sin nueva transacción (mismo pago, mismo médico).
  - Emails a paciente (nuevo horario) y médico (reconfirmar).

---

## Backend (`api.php`)

### Helper `validarNuevoInicio(int $medicoId, string $inicio, ?int $excluirId = null): string`
Extrae la validación que hoy está inline en `crearReserva` para reusarla en reprogramar (DRY):
- Regex `^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$`; `strtotime` válido; futuro; `≤ +29 days`.
- No en `medico_bloqueos` de ese médico.
- No ocupado: `SELECT id FROM reservas WHERE medico_id=? AND inicio=? AND estado_consulta IN ('agendada','confirmada')` (+ `AND id<>?` si `$excluirId`).
- Devuelve el string legible `"Lun 14/07 16:00"` (mismo formato actual). Lanza `Exception` con mensaje amable si algo falla.
`crearReserva` pasa a llamar este helper (se re-verifica su E2E).

### Endpoints nuevos (switch + funciones)
- `paciente_cancelar_reserva` → `pacienteCancelarReserva()`
- `paciente_reprogramar_reserva` → `pacienteReprogramarReserva()`
- `medico_cancelar_reserva` → `medicoCancelarReserva()`

### Ajustes de ocupación (fix del hueco)
- `generarSlotsDisponibles`: la query de ocupados pasa a `estado_consulta IN ('agendada','confirmada')`.
- `crearReserva`: el chequeo de doble-reserva pasa a `IN ('agendada','confirmada')` (queda cubierto por el helper).
- `medicoAgenda`: las `citas` de la semana filtran `estado_consulta IN ('agendada','confirmada','realizada')` (se excluyen `cancelada`/`no_realizada` del grid).

### Regla de 12 h (helper interno)
`$MIN_ANTICIPACION = 12*3600;` Reutilizado en cancelar-paciente y reprogramar.

---

## Frontend

### `paciente-portal.html` — "Mis reservas"
- `pacientePerfil` ya devuelve las reservas del paciente. **Verificar/ampliar** que cada reserva traiga: `id`, `inicio`, `horario`, `estado_consulta`, `estado_pago`, `medico_id`, `medico` (nombre). Si falta `inicio`/`medico_id`/`estado_consulta`, agregarlos al SELECT.
- Por cada reserva **futura y elegible** (estado ∈ {agendada, confirmada} y `inicio - ahora ≥ 12h`): botones **Cancelar** y **Reprogramar**.
  - Si `inicio - ahora < 12h` (pero futura y activa): mostrar nota "Para cambios con menos de 12 h, contacta soporte" (sin botones).
  - Estados `cancelada/realizada/no_realizada`: sin botones (solo etiqueta de estado).
- **Cancelar:** `confirm()` → `POST paciente_cancelar_reserva {reserva_id}` → recargar lista + toast.
- **Reprogramar:** abre un selector de horarios con fecha (reusar `horarios_disponibles {medico_id}` de esa reserva, agrupado por día como en `pacientes.html`), el paciente elige un nuevo `inicio` → `POST paciente_reprogramar_reserva {reserva_id, inicio}` → recargar + toast.

### `medico-portal.html` — lista de reservas
- Por cada reserva **activa y futura** (estado ∈ {agendada, confirmada}): botón **Cancelar** → `prompt()`/modal pide motivo → `POST medico_cancelar_reserva {reserva_id, motivo}` → recargar + toast.
- (La sección Agenda no cambia su UI; solo se beneficia del filtro de citas en `medicoAgenda`.)

---

## Casos borde

| Caso | Manejo |
|---|---|
| Cancelar dentro de las 12 h (paciente) | Rechazado con mensaje; el médico sí puede. |
| Reprogramar a un horario ya ocupado | Rechazado por `validarNuevoInicio`. |
| Reprogramar a fecha bloqueada / pasada / fuera de ventana | Rechazado. |
| Cancelar una cita ya `cancelada`/`realizada`/`no_realizada` | Rechazado ("No se puede modificar esta cita"). |
| Reserva de otro paciente/médico | 404 / "No encontrada" (filtro por owner). |
| Cortesía (`exonerado`) cancelada | Libera el horario; sin reembolso; uso del código no se restituye (roadmap). |
| Reprogramar al mismo `inicio` | `validarNuevoInicio` con `excluirId` lo permite (no-op de horario) pero igual resetea a `agendada`; aceptable. |
| Cita `confirmada+pagado` cancelada | Reembolso: `reembolsado` + `estado_pago_medico=pendiente` + transacción. |

---

## Fuera de alcance (roadmap)

- Restituir el uso del código de cortesía al cancelar una cita exonerada.
- Reprogramar a **otro** médico (sería cancelar + reservar nueva; distinto precio).
- Penalizaciones/cargos por cancelación tardía (requiere pasarela real).
- Cancelar/reprogramar desde las celdas del grid de Agenda del médico (por ahora desde la lista de reservas).
- Notificaciones por WhatsApp (pendiente de credenciales).

---

## Criterios de aceptación

1. El paciente cancela una cita futura (≥12 h) desde su portal; queda `cancelada`, el pago en custodia pasa a `reembolsado` con transacción, y el horario se libera.
2. El paciente no puede cancelar/reprogramar con < 12 h de anticipación (mensaje claro).
3. El paciente reprograma una cita a otro horario disponible del mismo médico; el horario viejo se libera, el nuevo se ocupa, la cita vuelve a `agendada` con nueva ventana de 24 h, y el pago se mantiene (si estaba `pagado`, vuelve a `en_custodia`).
4. El médico cancela una cita con motivo desde su portal; el paciente queda reembolsado y el horario se libera.
5. Una cita `confirmada` **ocupa** su horario (ya no se ofrece ni permite doble-reserva); las canceladas/no-realizadas no aparecen en la agenda.
6. Reprogramar a un horario ocupado/bloqueado/pasado/fuera de ventana es rechazado.
