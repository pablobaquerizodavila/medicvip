# HANDOFF — MedicVIP

**Última sesión:** 2026-07-10  
**Último commit:** `d6b1454` — feat: editar disponibilidad desde Agenda + horarios de la semana en el card  
**Rama activa:** `main`  
**Repo:** https://github.com/pablobaquerizodavila/medicvip  
**Producción:** https://medicvip.org  

---

## Qué se hizo en esta sesión (2026-07-10)

Ciclo de vida completo del **agendamiento** (además de features previas de esta tanda: cuentas de paciente + historial, expediente clínico Fase 1, recetas descargables, lista/búsqueda de pacientes, gráficas de tendencias y línea de tiempo, códigos de cortesía, backup off-NAS — ver README y `git log`).

### Agenda por fechas (commit `f13e162`)
- `reservas.inicio` (datetime) + tabla `medico_bloqueos`; se limpiaron reservas legadas sin `inicio`.
- `generarSlotsDisponibles()` proyecta la plantilla semanal (`medico_disponibilidad`) a fechas reales (ventana 4 semanas), descuenta ocupados y días bloqueados, muestra rango `hora–fin` según `medico_pago.duracion_minutos`.
- Endpoints: `horarios_disponibles`, `medico_agenda`, `medico_bloqueo_crear/eliminar`. `listarMedicos` expone `proximo_disponible`.
- Frontend: `pacientes.html` selector de horarios con fecha; `medico-portal.html` sección **📅 Agenda** (grid semanal Lun–Dom + bloqueos); `index.html` "Próximo disponible".

### Cancelar / Reprogramar citas (commit `63e9c51`)
- Endpoints: `paciente_cancelar_reserva`, `paciente_reprogramar_reserva` (cutoff **12 h**, resetea a `agendada` +24 h), `medico_cancelar_reserva` (con motivo).
- Helper `validarNuevoInicio()` (DRY con `crearReserva`). **Fix de ocupación:** los slots y el anti-doble-reserva pasan a `IN ('agendada','confirmada')` — una cita confirmada ya no libera su horario por error.
- Cancelar → `cancelada` + reembolso contable (`reembolsado` + fila en `transacciones`) + libera horario + email a la otra parte.
- Frontend: botones Cancelar/Reprogramar en `paciente-portal.html` (modal selector de fechas) y Cancelar con motivo en `medico-portal.html`.

### Restituir uso de código de cortesía al cancelar (commit `b9b7608`)
- En `cancelarReservaInterno` (paciente y médico): si la cita consumió un código, decrementa `usos_count` (GREATEST 0), reactiva `agotado→activo` (`revocado` sigue revocado) y borra la fila de `codigo_usos`. Reprogramar NO restituye.

### Editar disponibilidad + horarios de la semana en el card (commit `d6b1454`)
- **Editor de disponibilidad en el portal:** el médico fijaba sus horarios solo al registrarse; ahora los edita desde **Agenda** con el botón "⚙️ Editar mis horarios de atención" (grid días×bloques 07:00–19:00, precargado). Endpoint dedicado `medico_disponibilidad_guardar` (DELETE+INSERT de `medico_disponibilidad`, valida día del enum + hora, dedup) — no toca otros campos del perfil.
- **Card público:** `pacientes.html` e `index.html` muestran los horarios de la semana agrupados por día (helper `horariosSemanaHtml`) en vez de solo "próximo disponible".
- **Fix:** `ORDER BY FIELD` de `listarMedicos` usaba "Miercoles"/"Sabado" sin acento → no casaban con el enum; corregido.

**Verificación:** todo probado E2E contra la API interna (curl + probe SQL), datos de prueba limpiados. Deploys con `php -l` limpio y `chown http:http` + `chmod 644`.

---

## Qué se hizo en la sesión previa (2026-06-10)

### Restauración de BD mediconline (perdida en rebuild NAS)
El rebuild del NAS (NAS1821 → Nasr24, ~2026-05-28) se llevó la BD `mediconline` pero no los archivos del share `web`. Se reconstruyó desde cero:

1. `CREATE DATABASE mediconline` con utf8mb4
2. `CREATE USER 'mediconline'@'localhost'` con `Medic@2025!`, GRANTs SELECT/INSERT/UPDATE/DELETE/CREATE/ALTER/INDEX/CREATE VIEW/SHOW VIEW en mediconline.*
3. Schema cargado desde `schema.sql` del repo (8 tablas + 2 vistas + resenas)
4. Crons re-agregados a `/etc/crontab`:
   - `0 * * * * root /usr/local/bin/php82 /volume2/web/medicvip/cron_reembolsos.php > /dev/null 2>&1`
   - `30 8 * * * root /usr/local/bin/php82 /volume2/web/medicvip/cron_recordatorios.php > /dev/null 2>&1`
5. Verificado end-to-end: `action=test` OK, JWT login OK, write test OK
6. **Tabla `medicos` quedó vacía** — no había data de valor (solo testing). Para agregar un médico demo usar https://medicvip.org/registro-medico.html

### Backup automático de BD (nuevo, montado 2026-06-10)
Motivación: el rebuild del NAS se llevó `mediconline` porque los snapshots del share `web` cubren archivos pero NO el datadir de MariaDB. Esta era la brecha de protección.

- **Script:** `/volume2/web/db-backups/backup_dbs.sh` (chmod 700, owner root)
- Dumpea `mediconline` **y** `siscormed` con `mariadb-dump --single-transaction`, gzip, rotación 14 días
- **Log:** `/volume2/web/db-backups/backup.log`
- **Cron:** `30 2 * * * root /volume2/web/db-backups/backup_dbs.sh` (2:30 AM diario)
- **Credenciales:** `/volume2/web/db-backups/.db_credentials` (chmod 600, root — NO en repo)
- El directorio vive dentro del share `web` → cada dump queda con doble protección (archivo + snapshot cada 3h)
- Script versionado en `ops/backup_dbs.sh` (sin secretos)

**Verificado:** dump manual OK en ambas bases, `gzip -t` OK.

---

## Estado actual de producción

| Componente | Estado |
|---|---|
| medicvip.org HTTPS | ✅ OK |
| API (api.php) | ✅ OK |
| JWT auth admin + médico | ✅ OK |
| Cron reembolsos (cada hora) | ✅ en /etc/crontab |
| Cron recordatorios (8:30 AM) | ✅ en /etc/crontab |
| Cron backup BD (2:30 AM) | ✅ en /etc/crontab (nuevo) |
| Email → mailcow SMTP auth | ✅ OK (commit 7befc2d) |
| DKIM medicvip.org | ✅ rspamd selector `mail` |
| SPF + DMARC GoDaddy | ✅ publicados |
| DB mediconline | ✅ recreada (sin data demo) |
| Backup mediconline + siscormed | ✅ diario 2:30 AM |
| Cuentas de paciente + historial | ✅ OK (registro obligatorio) |
| Expediente clínico (médico) | ✅ Fase 1 (tratamientos, vitales, docs, recetas, tendencias, timeline) |
| Agenda por fechas (`inicio` + bloqueos) | ✅ OK (calendario semanal del médico) |
| Cancelar / reprogramar citas | ✅ OK (paciente autoservicio 12 h + médico con motivo) |
| Restitución de código de cortesía | ✅ al cancelar cita exonerada |
| Editar disponibilidad desde el portal | ✅ Agenda → "Editar mis horarios" (grid días×bloques) |
| Card público con horarios de la semana | ✅ agrupados por día (no solo próximo) |

---

## Pendientes (bloqueados en recursos externos)

| Fase | Qué falta | Bloqueador |
|---|---|---|
| **6B — Mercado Pago real** | authorize & capture con webhook | `MP_ACCESS_TOKEN`, `MP_PUBLIC_KEY`, `MP_WEBHOOK_SECRET` de la cuenta MP de Pablo |
| **6D — WhatsApp Cloud API** | notificaciones WhatsApp | Token + `phone_number_id` de Meta for Developers |
| **6J — UX/UI móvil** | mejoras responsive | Solo trabajo de diseño, sin credenciales externas |

**Roadmap sin bloqueadores (candidatos para la próxima sesión):**
- **Recordatorios de cita por email** usando el nuevo `inicio` (avisos 24 h / 1 h antes) — reaprovecha el cron existente.
- **Controles de privacidad**: el paciente decide qué médicos ven su expediente (hoy cualquier médico con una reserva lo ve).
- **Cancelar/reprogramar desde el grid de Agenda** del médico (hoy solo desde la lista de reservas).
- **CIE-10** en notas clínicas (requiere cargar catálogo).

Cuando estén disponibles los tokens:
- **MP**: agregar constants a `api.config.php` y `api.config.example.php`, implementar `mp_crear_preferencia`, `mp_webhook` y `mp_capturar` en `api.php`, integrar en `pacientes.html`
- **WhatsApp**: nuevo helper `enviarWhatsApp()` en `api.php`, reemplazar / complementar `enviarEmail()` en los flujos de reserva, confirmación y recordatorio

---

## Cómo continuar

```bash
# Desde G:\Documentos\compañias\Desarrollos\medicvip\code-medicvip
git pull
# Verificar producción:
#   https://medicvip.org/api.php?action=test
#   Backup log: /volume2/web/db-backups/backup.log
```

**Para restaurar la BD si el NAS vuelve a morir:**
```bash
# En el NAS — desde el último dump en /volume2/web/db-backups/
zcat mediconline_YYYYMMDD_HHMMSS.sql.gz | mariadb -u root -p mediconline
```

---

## Infra clave

| Recurso | Valor |
|---|---|
| NAS SSH | `pbaquerizo@192.168.0.116` (creds en memoria/NAS, no en repo) |
| NAS hostkey (plink) | `SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8` |
| MariaDB root | `root` (creds en memoria/NAS, no en repo) |
| DB mediconline user | `mediconline` (creds en `api.config.php` — gitignored) |
| Ruta producción | `/volume2/web/medicvip/` |
| Backup dir | `/volume2/web/db-backups/` |
| api.config.php | en producción, gitignored — usar `api.config.example.php` como plantilla |
