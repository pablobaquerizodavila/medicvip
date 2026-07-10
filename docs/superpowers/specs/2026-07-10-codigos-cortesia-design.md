# Códigos de cortesía (pro bono) — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `schema.sql`, `api.php`, `pacientes.html`, `medico-portal.html` + migración SQL en el NAS
**Estado:** Diseño aprobado

---

## Problema

El agendamiento actual solo funciona de verdad con un botón "🧪 MODO PRUEBA — Confirmar reserva sin pago". Tarjeta y PayPal son maquetas (sin pasarela real todavía). Se necesita:

1. Eliminar los elementos visibles de "modo prueba".
2. Permitir que un médico otorgue **consultas gratuitas** mediante códigos que él mismo genera en su panel.
3. Que el sistema valide y procese el agendamiento sin pago cuando el paciente ingresa un código válido.

---

## Solución

Un sistema de **códigos de cortesía** por médico. Cada médico genera códigos en su panel; el paciente los ingresa al agendar y obtiene la consulta gratis. Los códigos son de **usos múltiples** (el médico define cuántos), con **vencimiento opcional**, y con **auditoría** de quién los canjeó.

---

## Decisiones tomadas (confirmadas con el usuario)

| Tema | Decisión |
|------|----------|
| Tarjeta/PayPal al quitar modo prueba | Se quedan igual (maquetas). Solo se eliminan los 3 elementos de modo prueba. |
| Usos por código | Múltiples: el médico define `usos_max` al generar. |
| Vencimiento | Opcional al generar (fecha o sin vencimiento). |
| Alcance del código | Solo válido para el médico que lo generó. |
| Monto de una cortesía | $0 (el médico dona la consulta). |

---

## Base de datos

### Tabla nueva: `medico_codigos`

```sql
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
```

### Tabla nueva: `codigo_usos` (auditoría de canjes)

```sql
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
```

### Alteración: enum `reservas.estado_pago`

```sql
ALTER TABLE `reservas`
  MODIFY `estado_pago` enum('pendiente','en_custodia','pagado','reembolsado','exonerado')
  NOT NULL DEFAULT 'pendiente';
```

La migración se aplica en el NAS (base `mediconline`) y se refleja en `schema.sql`.

---

## Formato del código

- Autogenerado: prefijo `CORT-` + 6 caracteres alfanuméricos en mayúsculas sin ambigüedad (sin `0/O`, `1/I`). Ej: `CORT-A3F9K2`.
- Se reintenta hasta obtener uno único (colisión improbable pero manejada).

---

## Backend (`api.php`)

Nuevos casos en el `switch` de acciones:

### `medico_codigos` (GET, requiere token de médico)
Lista los códigos del médico autenticado, con su uso y canjes.
```json
{ "ok": true, "data": [
  { "id": 3, "codigo": "CORT-A3F9K2", "nota": "Para Juan Pérez", "usos_max": 5,
    "usos_count": 2, "estado": "activo", "expira_en": "2026-08-10", "creado_en": "...",
    "canjes": [ { "paciente_email": "juan@...", "usado_en": "..." } ] }
]}
```

### `medico_codigo_crear` (POST, requiere token de médico)
Entrada: `{ "usos_max": 5, "nota": "opcional", "expira_en": "2026-08-10" | null }`.
Genera código único, lo inserta, y devuelve `{ "ok": true, "data": { "codigo": "CORT-A3F9K2", ... } }`.
Validaciones: `usos_max` entre 1 y 100; `expira_en` (si viene) debe ser fecha futura.

### `medico_codigo_revocar` (POST, requiere token de médico)
Entrada: `{ "codigo_id": 3 }`. Marca `estado='revocado'` solo si el código pertenece al médico autenticado.

### `validar_codigo` (POST, público)
Entrada: `{ "medico_id": 7, "codigo": "CORT-A3F9K2" }`.
Salida: `{ "ok": true, "data": { "valido": true, "restantes": 3 } }` o `{ "valido": false, "motivo": "Código vencido" }`.
Motivos posibles: no existe / no es de este médico / revocado / agotado / vencido.
**No** incrementa el uso (solo consulta, para feedback en tiempo real).

### `crearReserva` (extendido)
Si `metodo_pago === 'cortesia'`:
1. Requiere `codigo` en el payload.
2. Abre transacción. `SELECT ... FOR UPDATE` del código por `codigo` + `medico_id`.
3. Valida: `estado='activo'`, `usos_count < usos_max`, `expira_en IS NULL OR expira_en >= CURDATE()`. Si falla → rollback + `jsonError('Código de cortesía inválido o agotado', 400)`.
4. Crea/obtiene paciente igual que hoy.
5. Inserta la reserva con `monto_total=0, comision=0, monto_medico=0, metodo_pago='cortesia', estado_pago='exonerado'` (más `sala_video`, `token_acceso`, `limite_confirmacion` como siempre).
6. `UPDATE medico_codigos SET usos_count=usos_count+1, estado=IF(usos_count+1>=usos_max,'agotado','activo') WHERE id=?`.
7. Inserta fila en `codigo_usos`.
8. **No** inserta transacción de custodia (no hay dinero).
9. `COMMIT`. Envía los mismos emails de confirmación (paciente + médico) que una reserva normal.

Si `metodo_pago !== 'cortesia'`: comportamiento actual sin cambios (custodia, transacción, etc.).

### `confirmarConsulta` y crons
- `confirmarConsulta`: para reservas `exonerado`, marca la consulta como realizada **sin** mover dinero ni crear transacción de liberación.
- `cron_reembolsos`: ya filtra por `estado_pago='en_custodia'`, por lo que ignora naturalmente las exoneradas. Verificar y, si hace falta, excluir explícitamente `'exonerado'`.

---

## Frontend — Agendamiento (`pacientes.html`)

### Eliminar (modo prueba)
- Step 1: el badge `🧪 MODO PRUEBA`, el texto "El pago real aún no está activo…", y el botón "🧪 Confirmar reserva sin pago" (`btn-test`). El botón "Ver flujo de pago completo →" pasa a ser el flujo normal.
- Step 2 (form de tarjeta): el badge de prueba y el enlace "Saltar pago y confirmar directo".
- Modal de emergencia: la nota "🧪 Modo prueba: Sin pago real activo".

### Agregar (opción cortesía)
- Tercer radio en "Método de pago": `🎗️ Código de cortesía (pro bono)`.
- Al seleccionarlo: se muestra un input para el código + botón/acción **Validar**.
  - Validación en tiempo real contra `validar_codigo` con el `medico_id` del médico que se agenda. Feedback visual: ✓ verde "Código válido — N usos restantes" / ✗ rojo con el motivo.
  - Mientras el código no sea válido, el botón de confirmar cortesía queda deshabilitado.
- Botón **"Confirmar consulta de cortesía"** → llama a `crearReserva` con `metodo_pago:'cortesia'` y `codigo`.
- Los métodos tarjeta/PayPal conservan su flujo actual (maqueta) intacto.

---

## Frontend — Panel del médico (`medico-portal.html`)

Sección nueva **"🎗️ Códigos de cortesía"**:

- Encabezado + botón **Generar código**.
- Formulario de generación: `usos_max` (número, default 1), `nota` (texto opcional), `expira_en` (fecha opcional). Al generar, se muestra el nuevo código destacado con botón **Copiar**.
- Tabla/lista de códigos existentes:
  - Código (con copiar), nota, estado (chip: activo/agotado/revocado), uso (`2/5`), vencimiento.
  - Expandible: lista de canjes (email del paciente + fecha).
  - Botón **Revocar** (para códigos activos).
- Carga vía `medico_codigos` usando el token de médico ya presente en el portal.

---

## Casos borde

| Caso | Manejo |
|------|--------|
| Código de otro médico | `validar_codigo`/`crearReserva` filtran por `medico_id` → inválido. |
| Código agotado o revocado | Rechazado con motivo claro. |
| Código vencido | `expira_en < hoy` → rechazado. |
| Dos pacientes usan el último uso a la vez | `FOR UPDATE` serializa; el segundo recibe "agotado". |
| Paciente edita el payload a `metodo_pago:'cortesia'` sin código | Rechazado (código requerido). |
| Reserva exonerada en crons de dinero | Ignorada (estado `exonerado`). |
| `usos_max` fuera de rango | Validado (1–100). |

---

## Archivos a modificar

- `schema.sql` — dos tablas nuevas + enum alterado (documental).
- Migración SQL aplicada en el NAS (base `mediconline`).
- `api.php` — 4 endpoints nuevos + `crearReserva` extendido + ajuste en `confirmarConsulta`/reembolsos.
- `pacientes.html` — quitar modo prueba + agregar opción cortesía con validación.
- `medico-portal.html` — sección de gestión de códigos.

No se modifica `api.config.php` ni la lógica de pago de tarjeta/PayPal.

---

## Criterios de aceptación

1. En agendamiento ya no aparece ningún elemento de "modo prueba".
2. El médico puede generar un código (con usos y vencimiento opcional) desde su panel y verlo listado.
3. El médico puede revocar un código y ver quién lo ha canjeado.
4. Un paciente que ingresa un código válido agenda gratis; la reserva queda `monto 0`, `estado_pago='exonerado'`, `metodo_pago='cortesia'`.
5. El código incrementa su uso y, al llegar a `usos_max`, pasa a `agotado`.
6. Un código de otro médico, vencido, revocado o agotado es rechazado con motivo claro.
7. Dos canjes simultáneos del último uso no exceden `usos_max` (canje atómico).
8. Se envían los emails de confirmación al paciente y al médico.
9. Los crons de reembolso no tocan las reservas exoneradas.
