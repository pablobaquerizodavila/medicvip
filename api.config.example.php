<?php
// ============================================================
//  MedicVIP / MedicOnline — Configuración (PLANTILLA)
//
//  1. Copiar este archivo:  cp api.config.example.php api.config.php
//  2. Rellenar con tus datos reales.
//  3. NUNCA subir api.config.php al repositorio (ya está en .gitignore).
//  4. En el servidor, dar permisos restrictivos:  chmod 640 api.config.php
// ============================================================

// ── Base de datos ────────────────────────────────────────────────────────────
define('DB_HOST',   '127.0.0.1');
define('DB_PORT',   3306);
define('DB_NAME',   'mediconline');
define('DB_USER',   'TU_USUARIO_DB');
define('DB_PASS',   'TU_PASSWORD_DB');
// Synology: socket Unix de MariaDB10. Si NO estás en Synology, dejarlo en cadena vacía.
define('DB_SOCKET', '/run/mysqld/mysqld10.sock');

// ── Archivos ─────────────────────────────────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/uploads/fotos/');
define('UPLOAD_URL', '/uploads/fotos/');

// ── Negocio ──────────────────────────────────────────────────────────────────
define('COMMISSION_RATE', 0.15); // 15% comisión plataforma
define('EMERGENCY_RATE_MULTIPLIER', 1.5); // tarifa emergencia = tarifa normal × este factor

// ── Credenciales del panel admin ─────────────────────────────────────────────
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'TU_PASSWORD_ADMIN');

// ── Clave secreta del cron job ───────────────────────────────────────────────
define('CRON_KEY', 'TU_CLAVE_SECRETA_CRON');

// ── Clave secreta JWT (auth admin y médico) ──────────────────────────────────
// Generar con:  php -r "echo bin2hex(random_bytes(48));"
// Cambiarla invalida TODAS las sesiones activas (admin y médicos) — usar con cuidado.
define('JWT_SECRET', 'GENERAR_64_HEX_CHARS_O_MAS');
define('JWT_EXP_SECONDS', 28800); // 8h por defecto

// ── Email (mailcow SMTP autenticado — STARTTLS + AUTH LOGIN) ─────────────────
// Migrado de MailPlus local (fsockopen 127.0.0.1:25) a mailcow.
// El envío usa STARTTLS en el puerto 587 y se autentica con MAIL_USER/MAIL_PASS.
// El certificado TLS se valida contra peer_name 'mail.eneural.org' (hardcodeado
// en enviarEmail() dentro de api.php).
define('MAIL_HOST',  'TU_HOST_MAILCOW');        // host SMTP de mailcow (IP o FQDN)
define('MAIL_PORT',  587);                       // submission + STARTTLS
define('MAIL_USER',  'noreply@medicvip.org');    // buzón autenticado en mailcow
define('MAIL_PASS',  'TU_PASSWORD_MAILCOW');     // password del buzón (NUNCA versionar)
define('MAIL_FROM',  'noreply@medicvip.org');
define('MAIL_NAME',  'MedicVIP');

// ── Site URL (para links en emails) ──────────────────────────────────────────
define('SITE_URL',   'https://medicvip.org');

// ── CORS ─────────────────────────────────────────────────────────────────────
// Dominios autorizados para hacer fetch al API.
define('ALLOWED_ORIGINS', ['https://medicvip.org', 'https://www.medicvip.org']);
// Si necesitas abrir CORS para todos (sólo dev), descomenta:
// define('ALLOW_CORS_ANY', true);

// ── Mercado Pago (Fase 6B — pendiente de integrar) ───────────────────────────
// define('MP_ACCESS_TOKEN',  'TU_ACCESS_TOKEN');
// define('MP_PUBLIC_KEY',    'TU_PUBLIC_KEY');
// define('MP_WEBHOOK_SECRET','TU_WEBHOOK_SECRET');
