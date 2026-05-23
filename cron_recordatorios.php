<?php
// ============================================================
//  MedicVIP — Cron Job: enviar recordatorios del día
//  Guardar en: /volume2/web/medicvip/cron_recordatorios.php
//
//  Configurar en /etc/crontab:
//  30 8 * * * root /usr/local/bin/php82 /volume2/web/medicvip/cron_recordatorios.php > /dev/null 2>&1
//
//  Recorre las reservas con horario = HOY y estado_consulta='agendada',
//  manda email al paciente con su sala Jitsi y al médico con el detalle.
//  Marca recordatorio_enviado=1 para no duplicar.
// ============================================================

$configFile = __DIR__ . '/api.config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Falta api.config.php\n");
    exit(1);
}
require_once $configFile;

// nginx hace virtual hosting por server_name → mandar Host header explícito
$url = 'http://127.0.0.1/api.php?action=enviar_recordatorios&cron_key=' . urlencode(CRON_KEY);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Host: medicvip.org']);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$log = date('Y-m-d H:i:s') . " | HTTP $httpCode | $response\n";
file_put_contents(__DIR__ . '/cron.log', $log, FILE_APPEND);

echo $log;
