#!/bin/bash
# ============================================================
#  Backup diario de MariaDB — NAS Synology (Nasr24)
#  Bases: mediconline (MedicVIP) + siscormed
#
#  Instalado en:  /volume2/web/db-backups/backup_dbs.sh  (chmod 700, owner root)
#  Cron:          30 2 * * * root /volume2/web/db-backups/backup_dbs.sh
#
#  Por qué aquí: el share `web` tiene snapshots (@sharesnap) cada 3h,
#  así que cada dump queda protegido doblemente (archivo + snapshot).
#  El datadir de MariaDB NO está cubierto por esos snapshots — esta es
#  la única protección real de las bases. (Lección del rebuild 2026-05-28
#  que se llevó la BD mediconline.)
#
#  El directorio va chmod 700 para que nginx/Web Station (user http)
#  jamás pueda servir los dumps por HTTP.
# ============================================================
set -u

MARIADB_DUMP=/usr/local/mariadb10/bin/mariadb-dump
SOCKET=/run/mysqld/mysqld10.sock
BACKUP_DIR=/volume2/web/db-backups
RETENTION_DAYS=14
DATE=$(date +%Y%m%d_%H%M%S)
LOG="$BACKUP_DIR/backup.log"
DBS="mediconline siscormed"

# Credenciales de MariaDB — viven en un archivo aparte FUERA del repo:
#   /volume2/web/db-backups/.db_credentials   (chmod 600, owner root)
# Formato (2 líneas):
#   DB_USER=root
#   DB_PASS=el_password_real
CRED_FILE="$BACKUP_DIR/.db_credentials"
if [ ! -f "$CRED_FILE" ]; then
    echo "$(date '+%F %T') | FAIL | falta $CRED_FILE" >> "$LOG" 2>/dev/null || true
    exit 1
fi
. "$CRED_FILE"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

status=0
for db in $DBS; do
    out="$BACKUP_DIR/${db}_${DATE}.sql.gz"
    if "$MARIADB_DUMP" -u "$DB_USER" -p"$DB_PASS" --socket="$SOCKET" \
        --single-transaction --routines --triggers --events "$db" 2>>"$LOG" | gzip > "$out"; then
        size=$(du -h "$out" | cut -f1)
        echo "$(date '+%F %T') | OK   | $db -> $(basename "$out") ($size)" >> "$LOG"
    else
        echo "$(date '+%F %T') | FAIL | $db — revisar errores arriba" >> "$LOG"
        rm -f "$out"
        status=1
    fi
done

# Rotación: borrar dumps más viejos que RETENTION_DAYS
find "$BACKUP_DIR" -name '*.sql.gz' -mtime +"$RETENTION_DAYS" -delete

exit $status
