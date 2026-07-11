# Restituir uso de código de cortesía al cancelar — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `api.php`
**Estado:** Diseño aprobado

---

## Problema

Al reservar una cita de cortesía, `crearReserva` incrementa `medico_codigos.usos_count` (y marca `estado='agotado'` al llegar a `usos_max`) e inserta una fila en `codigo_usos (codigo_id, reserva_id, paciente_email)`. Si esa cita luego se **cancela**, la cuenta no se revierte: el código queda gastado aunque la consulta nunca ocurrió.

## Solución

En `cancelarReservaInterno()` (helper compartido por la cancelación de paciente **y** de médico), antes de marcar la cita como cancelada, restituir el uso si la reserva consumió un código:

1. Buscar `codigo_usos` por `reserva_id`. Si no hay fila → no-op (la cita no era de cortesía).
2. Si hay, sobre ese `codigo_id`:
   - `usos_count = GREATEST(usos_count-1, 0)` (nunca negativo).
   - `estado = IF(estado='agotado','activo', estado)` → un código `agotado` se **reactiva**; uno `revocado` **sigue revocado**; uno `activo` se queda `activo`.
   - Borrar la fila de `codigo_usos` de esa reserva (el canje se deshace).

## Alcance / decisiones

- Aplica a cancelación por paciente **y** por médico (ambas pasan por `cancelarReservaInterno`).
- **Reprogramar NO** restituye: es la misma cita/consulta, el uso sigue consumido.
- Solo backend. El portal del médico ya lee `usos_count`/`codigo_usos`, así que el contador y la lista de canjes se corrigen solos.
- La auditoría de la cancelación queda en `reservas.notas_cancelacion` (+ `transacciones` si hubo reembolso).

## Fuera de alcance

- Notificar al médico que se liberó un uso del código.
- Restituir el uso a un código `revocado` (se mantiene revocado a propósito).

## Casos borde

| Caso | Manejo |
|---|---|
| Reserva no-cortesía cancelada | Sin fila en `codigo_usos` → no-op. |
| Código ya `agotado` | Se reactiva a `activo` (usos_count baja por debajo del máximo). |
| Código `revocado` | Se decrementa `usos_count`, pero el estado sigue `revocado`. |
| Código eliminado tras el canje | La FK de `codigo_usos` (si `ON DELETE CASCADE`) ya habría borrado la fila; el lookup no encuentra nada → no-op. |
| `usos_count` ya en 0 (inconsistencia) | `GREATEST(...,0)` evita negativos. |

## Criterios de aceptación

1. Cancelar (paciente o médico) una cita de cortesía decrementa `usos_count` del código y borra su fila en `codigo_usos`.
2. Un código `agotado` cuya última cita se cancela vuelve a `activo` y puede volver a canjearse.
3. Un código `revocado` cancelado decrementa su uso pero permanece `revocado`.
4. Cancelar una cita que **no** usó código no afecta ningún `medico_codigos`.
5. Reprogramar una cita de cortesía **no** cambia `usos_count` ni `codigo_usos`.
