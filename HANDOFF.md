# HANDOFF — MedicVIP

**Última sesión:** 2026-06-10  
**Último commit:** `2710ba4` — ops: daily MariaDB backup script (mediconline + siscormed)  
**Rama activa:** `main`  
**Repo:** https://github.com/pablobaquerizodavila/medicvip  
**Producción:** https://medicvip.org  

---

## Qué se hizo en esta sesión (2026-06-10)

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

---

## Pendientes (bloqueados en recursos externos)

| Fase | Qué falta | Bloqueador |
|---|---|---|
| **6B — Mercado Pago real** | authorize & capture con webhook | `MP_ACCESS_TOKEN`, `MP_PUBLIC_KEY`, `MP_WEBHOOK_SECRET` de la cuenta MP de Pablo |
| **6D — WhatsApp Cloud API** | notificaciones WhatsApp | Token + `phone_number_id` de Meta for Developers |
| **6J — UX/UI móvil** | mejoras responsive | Solo trabajo de diseño, sin credenciales externas |

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
