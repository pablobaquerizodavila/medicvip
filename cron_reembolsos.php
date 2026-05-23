<?php
// ============================================================
//  MedicVIP — Cron Job: procesar reembolsos automáticos
//  Guardar en: /volume2/web/medicvip/cron_reembolsos.php
//
//  Configurar en DSM → Panel de control → Programador de tareas
//  → Crear → Tarea programada → Script definido por el usuario
//  Frecuencia: cada hora
//  Comando: php /volume2/web/medicvip/cron_reembolsos.php
// ============================================================

// Lee CRON_KEY desde la misma config que api.php — no duplicar.
$configFile = __DIR__ . '/api.config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Falta api.config.php\n");
    exit(1);
}
require_once $configFile;

$url = 'http://127.0.0.1/api.php?action=procesar_reembolsos&cron_key=' . urlencode(CRON_KEY);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$log = date('Y-m-d H:i:s') . " | HTTP $httpCode | $response\n";
file_put_contents(__DIR__ . '/cron.log', $log, FILE_APPEND);

echo $log;
