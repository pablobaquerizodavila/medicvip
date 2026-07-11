# Panel financiero de admin — spec

**Fecha:** 2026-07-11
**Archivos afectados:** `api.php`, `admin.html`
**Estado:** Diseño aprobado

---

## Problema

El admin no tiene una vista de estados financieros: cuánto se ha cobrado, cuánto ganan los médicos, cuánto está por cobrar en consultas futuras, cuánto retiene la plataforma. `admin_stats` solo da un total de comisión.

## Modelo (recordatorio)

`reservas`: `monto_total` (bruto), `comision` (corte MedicOnline), `monto_medico` (neto al médico), `estado_pago` (pendiente/en_custodia/pagado/reembolsado/exonerado), `estado_consulta` (agendada/…), `inicio` (datetime), `confirmada_en`, `creado_en`, `medico_id`. "Cobrado" = `estado_pago='pagado'` (se liberó al médico tras confirmar). Cortesía = `exonerado` (no suma ingresos).

## Backend — endpoint `admin_finanzas` (token admin)

Devuelve `{ resumen, por_medico, por_mes }`:

**resumen** (una query agregada sobre `reservas`):
- `bruto_cobrado` = `SUM(monto_total)` de `pagado`.
- `comision_plataforma` = `SUM(comision)` de `pagado`.
- `pagado_medicos` = `SUM(monto_medico)` de `pagado`.
- `consultas_cobradas` = `COUNT` de `pagado`.
- `en_custodia` = `SUM(monto_total)` de `en_custodia`.
- `por_cobrar` = `SUM(monto_total)` de `estado_consulta='agendada' AND inicio > NOW()`; `proximas_count` = su conteo.
- `reembolsado` = `SUM(monto_total)` de `reembolsado`.
- `cortesias` = `COUNT` de `exonerado`.
- `ticket_promedio` = `bruto_cobrado / consultas_cobradas` (0 si no hay), calculado en PHP.

**por_medico** (GROUP BY médico; solo los que tienen algún movimiento):
`medico`, `consultas_cobradas`, `bruto_cobrado`, `comision_generada`, `neto_ganado` (SUM monto_medico pagadas), `en_custodia`, `por_cobrar`. Orden por `neto_ganado DESC`.

**por_mes** (últimos 6 meses, ingresos cobrados por mes de `confirmada_en`):
`mes` (YYYY-MM), `bruto`, `comision`, `neto`. Orden por mes DESC.

Todo con `IFNULL(...,0)`. `checkAdmin()` al inicio. Switch: `case 'admin_finanzas'`.

## Frontend — `admin.html`

- **Nav-item** "💰 Finanzas" (tras "Reservas") → `showSection('finanzas',this)`.
- **Sección** `#section-finanzas`:
  - Título + subtítulo.
  - **Fila de tarjetas KPI**: Ingreso bruto cobrado · Ganancia plataforma · Pagado a médicos · En custodia · Por cobrar (próximas) · Reembolsado (+ Ticket promedio, # consultas cobradas). Reusa las clases de stat-card del admin.
  - **Tabla "Ingresos por médico"**: Médico · Consultas · Bruto cobrado · Comisión generada · Neto ganado · En custodia · Por cobrar.
  - **Tabla "Ingresos por mes"**: Mes · Bruto · Comisión · Neto.
- **JS** `loadFinanzas()` (fetch `admin_finanzas` con `authHeaders()`), disparada al mostrar la sección (igual patrón que `loadDashboard`/`loadReservas`). Formatear montos `$` con 2 decimales.

## Casos borde

| Caso | Manejo |
|---|---|
| Sin reservas pagadas | Todo en $0.00; ticket promedio 0; tablas vacías con mensaje. |
| Médico sin movimientos | No aparece en la tabla por_medico (HAVING). |
| Reserva agendada sin `inicio` (legado) | No cuenta en "por cobrar" (filtro `inicio IS NOT NULL AND inicio>NOW()`). |
| Cortesía | No suma a ingresos; se cuenta aparte (`cortesias`). |

## Fuera de alcance
- Filtro por rango de fechas / exportar CSV (posible siguiente iteración).
- Gráficas (solo tablas/KPIs por ahora).

## Criterios de aceptación
1. Admin ve una sección "💰 Finanzas" con KPIs de bruto cobrado, comisión plataforma, pagado a médicos, en custodia, por cobrar y reembolsado.
2. Tabla de ingresos por médico (neto ganado, etc.), ordenada por lo que más ganan.
3. Tabla de ingresos por mes (últimos 6 meses).
4. Los montos cuadran con las reservas (pagadas = cobrado; agendadas futuras = por cobrar).
