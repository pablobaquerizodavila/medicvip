<?php
ob_start(); // captura cualquier output espurio antes del JSON
ini_set("display_errors", 0);
ini_set("display_startup_errors", 0);
error_reporting(0);

// ── CONFIGURACIÓN ─────────────────────────────────────────────────────────────
// Toda la configuración (credenciales DB, admin, cron, mail) vive en api.config.php
// que está gitignored. Copiar api.config.example.php → api.config.php y rellenar.
$configFile = __DIR__ . '/api.config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Falta api.config.php. Copia api.config.example.php y rellena.']);
    exit;
}
require_once $configFile;

// ── CORS ──────────────────────────────────────────────────────────────────────
// En producción restringido al dominio. En dev (defined ALLOW_CORS_ANY=true) abierto.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : ['https://medicvip.org'];
if (defined('ALLOW_CORS_ANY') && ALLOW_CORS_ANY) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, X-Medico-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function getDB(): mysqli {
    static $db = null;
    if ($db === null) {
        $db = @new mysqli(null, DB_USER, DB_PASS, DB_NAME, null, DB_SOCKET);
        if ($db->connect_errno) $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        if ($db->connect_errno) jsonError('No se pudo conectar: ' . $db->connect_error, 500);
        $db->set_charset('utf8mb4');
    }
    return $db;
}
function query(string $sql, string $types = '', array $params = []): mysqli_stmt {
    $db = getDB(); $stmt = $db->prepare($sql);
    if (!$stmt) jsonError('Error SQL: ' . $db->error, 500);
    if ($types && $params) $stmt->bind_param($types, ...$params);
    $stmt->execute(); return $stmt;
}
function fetchAll(mysqli_stmt $stmt): array { $r=$stmt->get_result(); return $r?$r->fetch_all(MYSQLI_ASSOC):[]; }
function fetchOne(mysqli_stmt $stmt): ?array { $r=$stmt->get_result(); return $r?$r->fetch_assoc():null; }

$action = $_GET['action'] ?? '';
try {
    switch ($action) {
        case 'test':
            $db = getDB();
            $col = $db->query("SELECT COUNT(*) as c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservas' AND COLUMN_NAME='sala_video'");
            $colOk = $col ? (bool)$col->fetch_assoc()['c'] : false;
            $tablas=[]; $tr=$db->query('SHOW TABLES'); while($r=$tr->fetch_row()) $tablas[]=$r[0];
            jsonOk(['conexion'=>'OK','php'=>PHP_VERSION,'mysql'=>$db->server_info,'tablas'=>$tablas,'sala_video_col'=>$colOk?'existe ✓':'NO EXISTE — se crea en 1ra reserva']);
            break;
        case 'registro_medico':      registrarMedico();      break;
        case 'listar_medicos':       listarMedicos();        break;
        case 'reservar':             crearReserva();         break;
        case 'admin_login':          adminLogin();           break;
        case 'admin_medicos':        adminMedicos();         break;
        case 'admin_eliminar':       adminEliminar();        break;
        case 'admin_estado':         adminEstado();          break;
        case 'admin_reservas':       adminReservas();        break;
        case 'admin_stats':          adminStats();           break;
        case 'medico_login':         medicoLogin();          break;
        case 'medico_perfil':        medicoPerfil();         break;
        case 'medico_actualizar':    medicoActualizar();     break;
        case 'medico_toggle_estado': medicoToggleEstado();   break;
        case 'medico_cambiar_pass':  medicoCambiarPass();    break;
        case 'medico_recuperar':     medicoRecuperar();      break;
        case 'confirmar_consulta':   confirmarConsulta();    break;
        case 'procesar_reembolsos':  procesarReembolsos();   break;
        case 'admin_reembolso':      adminReembolso();       break;
        case 'admin_eliminar_reserva': adminEliminarReserva(); break;
        case 'paciente_sala':        pacienteSala();         break;
        case 'enviar_recordatorios': enviarRecordatorios();   break;
        case 'listar_emergencias':   listarEmergencias();    break;
        case 'reservar_emergencia':  reservarEmergencia();   break;
        default: jsonError('Accion no valida', 400);
    }
} catch (Throwable $e) {
    jsonError($e->getMessage() . ' [' . basename($e->getFile()) . ':' . $e->getLine() . ']', 500);
}

// ── REGISTRO MÉDICO ───────────────────────────────────────────────────────────
function registrarMedico(): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $required = ['titulo','nombre','apellido','email','telefono','ciudad','licencia',
                 'password','especialidad','anos_experiencia','universidad','biografia',
                 'tarifa','banco','tipo_cuenta','numero_cuenta','cedula_titular','nombre_titular'];
    foreach ($required as $f) if (empty($data[$f])) throw new Exception("Campo requerido: $f");
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) throw new Exception('Correo no valido.');
    if (strlen($data['password']) < 8) throw new Exception('Password minimo 8 caracteres.');
    if (fetchOne(query('SELECT id FROM medicos WHERE email=? OR licencia=?','ss',[$data['email'],$data['licencia']])))
        throw new Exception('Correo o licencia ya registrados.');

    $fotoPath = null;
    if (!empty($data['foto_base64'])) { try { $fotoPath=guardarFotoBase64($data['foto_base64'],$data['email']); } catch(Exception $e){} }

    $db = getDB(); $db->begin_transaction();
    $hash   = password_hash($data['password'], PASSWORD_BCRYPT);
    $genero = (string)($data['genero'] ?? '');
    $foto   = (string)($fotoPath ?? '');
    $stmt = $db->prepare('INSERT INTO medicos (titulo,nombre,apellido,email,telefono,ciudad,genero,licencia,password_hash,foto_perfil) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('ssssssssss',$data['titulo'],$data['nombre'],$data['apellido'],$data['email'],$data['telefono'],$data['ciudad'],$genero,$data['licencia'],$hash,$foto);
    $stmt->execute(); $medicoId=(int)$db->insert_id;

    $sub  = (string)($data['subespecialidad'] ?? '');
    $idio = (string)($data['idiomas'] ?? 'Espanol');
    $post = (string)($data['postgrado'] ?? '');
    $stmt = $db->prepare('INSERT INTO medico_especialidad (medico_id,especialidad,subespecialidad,anos_experiencia,idiomas,universidad,postgrado,biografia) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->bind_param('isssssss',$medicoId,$data['especialidad'],$sub,$data['anos_experiencia'],$idio,$data['universidad'],$post,$data['biografia']);
    $stmt->execute();

    if (!empty($data['disponibilidad']) && is_array($data['disponibilidad'])) {
        $stmt = $db->prepare('INSERT IGNORE INTO medico_disponibilidad (medico_id,dia_semana,hora) VALUES (?,?,?)');
        foreach ($data['disponibilidad'] as $slot)
            if (!empty($slot['dia']) && !empty($slot['hora'])) { $stmt->bind_param('iss',$medicoId,$slot['dia'],$slot['hora']); $stmt->execute(); }
    }

    $tarifa=(float)$data['tarifa']; $dur=(int)($data['duracion_minutos']??30);
    $plan=(string)($data['plan_liquidacion']??'auto'); $frec=(string)($data['frecuencia_pago']??'Por consulta');
    $como=(string)($data['como_se_entero']??'');
    $stmt = $db->prepare('INSERT INTO medico_pago (medico_id,tarifa,duracion_minutos,banco,tipo_cuenta,numero_cuenta,cedula_titular,nombre_titular,plan_liquidacion,frecuencia_pago,como_se_entero) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('idissssssss',$medicoId,$tarifa,$dur,$data['banco'],$data['tipo_cuenta'],$data['numero_cuenta'],$data['cedula_titular'],$data['nombre_titular'],$plan,$frec,$como);
    $stmt->execute();
    $db->commit();
    jsonOk(['medico_id'=>$medicoId,'mensaje'=>'Perfil registrado. Pendiente de verificacion.']);
}

// ── LISTAR MÉDICOS ────────────────────────────────────────────────────────────
function listarMedicos(): void {
    $spec = $_GET['especialidad'] ?? null;
    $stmt = ($spec && $spec!=='Todos')
        ? query('SELECT * FROM v_medicos_activos WHERE especialidad=? ORDER BY id DESC','s',[$spec])
        : query('SELECT * FROM v_medicos_activos ORDER BY id DESC');
    $medicos = fetchAll($stmt);
    $db = getDB();
    $sd = $db->prepare('SELECT dia_semana,hora FROM medico_disponibilidad WHERE medico_id=? AND activo=1 ORDER BY FIELD(dia_semana,"Lunes","Martes","Miercoles","Jueves","Viernes","Sabado","Domingo"),hora');
    foreach ($medicos as &$m) {
        $sd->bind_param('i',$m['id']); $sd->execute();
        $slots=$sd->get_result()->fetch_all(MYSQLI_ASSOC);
        $m['disponibilidad']=array_map(fn($s)=>$s['dia_semana'].' '.$s['hora'],$slots);
    }
    jsonOk($medicos);
}

// ── CREAR RESERVA ─────────────────────────────────────────────────────────────
function crearReserva(): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    foreach (['medico_id','nombre_paciente','email_paciente','horario','metodo_pago'] as $f)
        if (empty($data[$f])) throw new Exception("Campo requerido: $f");

    $db  = getDB();
    $row = fetchOne(query('SELECT id FROM pacientes WHERE email=?','s',[$data['email_paciente']]));
    if ($row) {
        $pacienteId = $row['id'];
    } else {
        $edad = !empty($data['edad']) ? (int)$data['edad'] : 0;
        $tel  = (string)($data['telefono_paciente'] ?? '');
        $stmt = $db->prepare('INSERT INTO pacientes (nombre,email,telefono,edad) VALUES (?,?,?,?)');
        if (!$stmt) throw new Exception('Prepare pacientes: '.$db->error);
        $stmt->bind_param('sssi',$data['nombre_paciente'],$data['email_paciente'],$tel,$edad);
        if (!$stmt->execute()) throw new Exception('Execute pacientes: '.$stmt->error);
        $pacienteId = (int)$db->insert_id;
    }

    $pago = fetchOne(query('SELECT tarifa FROM medico_pago WHERE medico_id=?','i',[(int)$data['medico_id']]));
    if (!$pago) throw new Exception('Medico no encontrado o sin tarifa.');

    $total    = (float)$pago['tarifa'];
    $comision = round($total * COMMISSION_RATE, 2);
    $neto     = round($total - $comision, 2);
    $medicoId = (int)$data['medico_id'];
    $motivo   = (string)($data['motivo']   ?? '');  // NUNCA null en bind_param
    $alergias = (string)($data['alergias'] ?? '');  // NUNCA null en bind_param
    $salaVideo    = 'medicnet-' . bin2hex(random_bytes(10));
    $tokenAcceso  = strtoupper(substr(bin2hex(random_bytes(4)),0,4) . '-' . substr(bin2hex(random_bytes(4)),0,4) . '-' . substr(bin2hex(random_bytes(4)),0,4));

    // Crear columnas sala_video y token_acceso si no existen (MariaDB 10 compatible)
    $chk = $db->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservas' AND COLUMN_NAME='sala_video'");
    if ($chk && (int)$chk->fetch_assoc()['c'] === 0)
        $db->query("ALTER TABLE reservas ADD COLUMN sala_video VARCHAR(64) DEFAULT NULL");
    $chk2 = $db->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservas' AND COLUMN_NAME='token_acceso'");
    if ($chk2 && (int)$chk2->fetch_assoc()['c'] === 0)
        $db->query("ALTER TABLE reservas ADD COLUMN token_acceso VARCHAR(32) DEFAULT NULL");

    $stmt = $db->prepare('INSERT INTO reservas (medico_id,paciente_id,horario,motivo,alergias,metodo_pago,monto_total,comision,monto_medico,estado_pago,limite_confirmacion,sala_video,token_acceso) VALUES (?,?,?,?,?,?,?,?,?,"en_custodia",DATE_ADD(NOW(),INTERVAL 24 HOUR),?,?)');
    if (!$stmt) throw new Exception('Prepare reservas: '.$db->error);
    $stmt->bind_param('iissssdddss',$medicoId,$pacienteId,$data['horario'],$motivo,$alergias,$data['metodo_pago'],$total,$comision,$neto,$salaVideo,$tokenAcceso);
    if (!$stmt->execute()) throw new Exception('Execute reservas: '.$stmt->error);
    $reservaId = (int)$db->insert_id;
    if (!$reservaId) throw new Exception('insert_id=0 — revisar permisos INSERT en tabla reservas');

    $ins = $db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"custodia",?,"Pago recibido en custodia")');
    if ($ins) { $ins->bind_param('id',$reservaId,$total); $ins->execute(); }

    // Obtener datos del médico para emails
    $medDat = fetchOne(query('SELECT m.nombre,m.apellido,m.titulo,m.email,e.especialidad FROM medicos m JOIN medico_especialidad e ON e.medico_id=m.id WHERE m.id=?','i',[$medicoId]));
    if ($medDat) {
        $nombreMedico = $medDat['titulo'].' '.$medDat['nombre'].' '.$medDat['apellido'];
        // Email al paciente
        emailReservaConfirmada([
            'paciente'       => $data['nombre_paciente'],
            'email_paciente' => $data['email_paciente'],
            'medico'         => $nombreMedico,
            'especialidad'   => $medDat['especialidad'],
            'horario'        => $data['horario'],
            'reserva_id'     => $reservaId,
            'sala_video'     => $salaVideo,
            'monto'          => number_format($total, 2),
        ]);
        // Email al médico
        emailNuevaReservaDoctor([
            'medico_nombre' => $nombreMedico,
            'email_medico'  => $medDat['email'],
            'paciente'      => $data['nombre_paciente'],
            'motivo'        => $motivo ?: 'No especificado',
            'horario'       => $data['horario'],
            'monto_medico'  => number_format($neto, 2),
        ]);
    }
    jsonOk(['reserva_id'=>$reservaId,'sala_video'=>$salaVideo,'monto_total'=>$total,'mensaje'=>'Reserva creada exitosamente.']);
}

// ── EMAIL ─────────────────────────────────────────────────────────────────────
function enviarEmail(string $to, string $toName, string $subject, string $htmlBody): bool {
    // Conexión SMTP directa a MailPlus local (sin librería externa)
    $sock = @fsockopen(MAIL_HOST, MAIL_PORT, $errno, $errstr, 5);
    if (!$sock) return false;

    $from     = MAIL_FROM;
    $fromName = MAIL_NAME;
    $boundary = md5(uniqid());

    // Leer saludo del servidor
    fgets($sock, 1024);

    $cmds = [
        "EHLO medicvip.org
",
        "MAIL FROM:<$from>
",
        "RCPT TO:<$to>
",
        "DATA
",
    ];
    foreach ($cmds as $cmd) { fwrite($sock, $cmd); fgets($sock, 1024); }

    // Headers + body
    $msg  = "From: $fromName <$from>
";
    $msg .= "To: $toName <$to>
";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=
";
    $msg .= "MIME-Version: 1.0
";
    $msg .= "Content-Type: text/html; charset=UTF-8
";
    $msg .= "Content-Transfer-Encoding: base64
";
    $msg .= "
";
    $msg .= chunk_split(base64_encode($htmlBody)) . "
";
    $msg .= ".
";
    fwrite($sock, $msg);
    fgets($sock, 1024);
    fwrite($sock, "QUIT
");
    fclose($sock);
    return true;
}

function emailReservaConfirmada(array $data): void {
    // Email al paciente
    $link    = 'https://meet.jit.si/' . $data['sala_video'];
    $linkBtn = SITE_URL . '/pacientes.html';
    $html = <<<HTML
<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px">
<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <div style="background:#0D7A5F;padding:28px 32px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:22px">MedicOnline</h1>
    <p style="color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px">Confirmación de consulta</p>
  </div>
  <div style="padding:32px">
    <h2 style="color:#1A1A18;font-size:18px;margin:0 0 16px">¡Tu reserva está confirmada, {$data['paciente']}!</h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:24px">
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Médico</td><td style="padding:10px 0;font-size:13px;font-weight:500;text-align:right">{$data['medico']}</td></tr>
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Especialidad</td><td style="padding:10px 0;font-size:13px;text-align:right">{$data['especialidad']}</td></tr>
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Horario</td><td style="padding:10px 0;font-size:13px;font-weight:500;text-align:right">{$data['horario']}</td></tr>
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">N° de reserva</td><td style="padding:10px 0;font-size:18px;font-weight:700;color:#0D7A5F;text-align:right">#{$data['reserva_id']}</td></tr>
      <tr><td style="padding:10px 0;color:#888;font-size:13px">Total pagado</td><td style="padding:10px 0;font-size:13px;text-align:right">\${$data['monto']} USD</td></tr>
    </table>
    <div style="background:#E8F5F1;border-radius:12px;padding:20px;margin-bottom:24px;border-left:4px solid #0D7A5F">
      <p style="margin:0 0 8px;font-size:13px;font-weight:600;color:#0D7A5F">📹 Tu sala de videollamada</p>
      <p style="margin:0 0 12px;font-size:13px;color:#333">Entra a este enlace <strong>el día y hora de tu consulta</strong>:</p>
      <div style="background:#fff;border-radius:8px;padding:10px 14px;font-size:12px;color:#0D7A5F;word-break:break-all;border:1px solid #b2ddd0">$link</div>
      <p style="margin:12px 0 0;font-size:11px;color:#666">⚠️ No compartas este enlace. Solo para uso personal.</p>
    </div>
    <div style="background:#FFF8E6;border-radius:10px;padding:14px;margin-bottom:24px;font-size:12px;color:#633806">
      📌 Guarda tu N° de reserva <strong>#{$data['reserva_id']}</strong>. También puedes acceder a tu sala en cualquier momento desde <a href="$linkBtn" style="color:#0D7A5F">MedicOnline</a> usando tu correo.
    </div>
  </div>
  <div style="background:#f5f5f5;padding:16px 32px;text-align:center;font-size:11px;color:#aaa">MedicOnline · Consultas médicas online · © 2025</div>
</div></body></html>
HTML;
    enviarEmail($data['email_paciente'], $data['paciente'], '✅ Reserva confirmada — MedicOnline #' . $data['reserva_id'], $html);
}

function emailNuevaReservaDoctor(array $data): void {
    $html = <<<HTML
<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px">
<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <div style="background:#0D7A5F;padding:28px 32px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:22px">MedicOnline</h1>
    <p style="color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px">Nueva consulta agendada</p>
  </div>
  <div style="padding:32px">
    <h2 style="color:#1A1A18;font-size:18px;margin:0 0 6px">Tienes una nueva consulta, {$data['medico_nombre']}</h2>
    <p style="color:#666;font-size:14px;margin:0 0 24px">Un paciente ha reservado una consulta contigo.</p>
    <table style="width:100%;border-collapse:collapse;margin-bottom:24px">
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Paciente</td><td style="padding:10px 0;font-size:13px;font-weight:500;text-align:right">{$data['paciente']}</td></tr>
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Motivo</td><td style="padding:10px 0;font-size:13px;text-align:right">{$data['motivo']}</td></tr>
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Horario</td><td style="padding:10px 0;font-size:13px;font-weight:600;color:#0D7A5F;text-align:right">{$data['horario']}</td></tr>
      <tr><td style="padding:10px 0;color:#888;font-size:13px">Tu pago neto</td><td style="padding:10px 0;font-size:16px;font-weight:700;color:#0D7A5F;text-align:right">\${$data['monto_medico']} USD</td></tr>
    </table>
    <div style="background:#FFF8E6;border-radius:10px;padding:14px;margin-bottom:24px;font-size:13px;color:#633806;border-left:4px solid #F5C842">
      ⏱ <strong>Tienes 24 horas</strong> para confirmar la consulta desde tu portal. Si no confirmas, el pago será reembolsado automáticamente al paciente.
    </div>
    <div style="text-align:center">
      <a href="{SITE_URL}/medico-portal.html" style="display:inline-block;background:#0D7A5F;color:#fff;padding:14px 32px;border-radius:100px;text-decoration:none;font-size:14px;font-weight:600">Ir a mi portal →</a>
    </div>
  </div>
  <div style="background:#f5f5f5;padding:16px 32px;text-align:center;font-size:11px;color:#aaa">MedicOnline · Consultas médicas online · © 2025</div>
</div></body></html>
HTML;
    $html = str_replace('{SITE_URL}', SITE_URL, $html);
    enviarEmail($data['email_medico'], $data['medico_nombre'], '📅 Nueva consulta agendada — ' . $data['horario'], $html);
}

function emailPagoLiberado(array $data): void {
    $html = <<<HTML
<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px">
<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <div style="background:#0D7A5F;padding:28px 32px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:22px">MedicOnline</h1>
    <p style="color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px">Pago liberado</p>
  </div>
  <div style="padding:32px;text-align:center">
    <div style="width:64px;height:64px;background:#E8F5F1;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:28px">💰</div>
    <h2 style="color:#1A1A18;font-size:20px;margin:0 0 8px">¡Tu pago fue liberado!</h2>
    <p style="color:#666;font-size:14px;margin:0 0 24px">Gracias por confirmar la consulta con {$data['paciente']}.</p>
    <div style="background:#E8F5F1;border-radius:12px;padding:20px;margin-bottom:24px">
      <p style="margin:0;font-size:13px;color:#666">Monto transferido a tu cuenta</p>
      <p style="margin:8px 0 0;font-size:36px;font-weight:700;color:#0D7A5F">\${$data['monto_medico']}</p>
      <p style="margin:4px 0 0;font-size:12px;color:#888">USD · {$data['confirmada_en']}</p>
    </div>
    <a href="{SITE_URL}/medico-portal.html" style="display:inline-block;background:#0D7A5F;color:#fff;padding:14px 32px;border-radius:100px;text-decoration:none;font-size:14px;font-weight:600">Ver mi portal →</a>
  </div>
  <div style="background:#f5f5f5;padding:16px 32px;text-align:center;font-size:11px;color:#aaa">MedicOnline · Consultas médicas online · © 2025</div>
</div></body></html>
HTML;
    $html = str_replace('{SITE_URL}', SITE_URL, $html);
    enviarEmail($data['email_medico'], $data['medico_nombre'], '💰 Pago liberado — $' . $data['monto_medico'] . ' USD', $html);
}

// ── FOTO ──────────────────────────────────────────────────────────────────────
function guardarFotoBase64(string $base64, string $email): ?string {
    if (!preg_match('/^data:image\/(\w+);base64,/',$base64,$m)) return null;
    $imgData=base64_decode(substr($base64,strpos($base64,',')+1));
    if (!$imgData||strlen($imgData)>5*1024*1024) return null;
    if (!is_dir(UPLOAD_DIR)&&!mkdir(UPLOAD_DIR,0755,true)) return null;
    if (!is_writable(UPLOAD_DIR)) return null;
    $filename=md5($email.time()).'.'.strtolower($m[1]);
    return file_put_contents(UPLOAD_DIR.$filename,$imgData)!==false ? UPLOAD_URL.$filename : null;
}

// ── ADMIN ─────────────────────────────────────────────────────────────────────
function adminLogin(): void {
    $data=json_decode(file_get_contents('php://input'),true);
    if(($data['usuario']??'')===ADMIN_USER&&($data['password']??'')===ADMIN_PASS)
        jsonOk(['token'=>base64_encode(ADMIN_USER.':'.ADMIN_PASS)]);
    jsonError('Credenciales incorrectas',401);
}
function checkAdmin(): void {
    if(($_SERVER['HTTP_X_ADMIN_TOKEN']??'')!==base64_encode(ADMIN_USER.':'.ADMIN_PASS))
        jsonError('No autorizado',401);
}
function adminMedicos(): void {
    checkAdmin(); $db=getDB();
    $stmt=$db->prepare('SELECT m.id,m.titulo,m.nombre,m.apellido,m.email,m.telefono,m.ciudad,m.licencia,m.estado,m.creado_en,m.foto_perfil,e.especialidad,e.anos_experiencia,e.biografia,p.tarifa,p.banco,p.tipo_cuenta,p.numero_cuenta,p.nombre_titular FROM medicos m LEFT JOIN medico_especialidad e ON e.medico_id=m.id LEFT JOIN medico_pago p ON p.medico_id=m.id ORDER BY m.creado_en DESC');
    $stmt->execute(); jsonOk($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}
function adminEstado(): void {
    checkAdmin(); $data=json_decode(file_get_contents('php://input'),true);
    if(empty($data['id'])||empty($data['estado'])) jsonError('Faltan datos');
    if(!in_array($data['estado'],['pendiente','activo','suspendido'])) jsonError('Estado invalido');
    $db=getDB(); $stmt=$db->prepare('UPDATE medicos SET estado=? WHERE id=?');
    $stmt->bind_param('si',$data['estado'],$data['id']); $stmt->execute();
    jsonOk(['actualizado'=>$stmt->affected_rows]);
}
function adminEliminar(): void {
    checkAdmin(); $data=json_decode(file_get_contents('php://input'),true);
    if(empty($data['id'])) jsonError('Falta id');
    $db=getDB(); $stmt=$db->prepare('DELETE FROM medicos WHERE id=?');
    $stmt->bind_param('i',$data['id']); $stmt->execute();
    jsonOk(['eliminado'=>$stmt->affected_rows]);
}
function adminReservas(): void {
    checkAdmin(); $db=getDB();
    $stmt=$db->prepare('SELECT r.id,r.horario,r.monto_total,r.comision,r.monto_medico,r.estado_pago,r.estado_consulta,r.estado_pago_medico,r.creado_en,CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico,p.nombre AS paciente,p.email AS email_paciente FROM reservas r JOIN medicos m ON m.id=r.medico_id JOIN pacientes p ON p.id=r.paciente_id ORDER BY r.creado_en DESC');
    $stmt->execute(); jsonOk($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}
function adminStats(): void {
    checkAdmin(); $db=getDB(); $s=[];
    foreach(['total_medicos'=>'SELECT COUNT(*) FROM medicos','medicos_activos'=>"SELECT COUNT(*) FROM medicos WHERE estado='activo'",'medicos_pendientes'=>"SELECT COUNT(*) FROM medicos WHERE estado='pendiente'",'total_reservas'=>'SELECT COUNT(*) FROM reservas','total_pacientes'=>'SELECT COUNT(*) FROM pacientes','ingresos_totales'=>'SELECT IFNULL(SUM(comision),0) FROM reservas WHERE estado_pago="pagado"'] as $k=>$sql)
        { $r=$db->query($sql)->fetch_row(); $s[$k]=$r[0]; }
    jsonOk($s);
}

// ── PORTAL MÉDICO ─────────────────────────────────────────────────────────────
function medicoLogin(): void {
    $data=json_decode(file_get_contents('php://input'),true);
    if(empty($data['email'])||empty($data['password'])) jsonError('Email y password requeridos');
    $row=fetchOne(query('SELECT id,nombre,apellido,titulo,email,password_hash,estado,foto_perfil FROM medicos WHERE email=?','s',[strtolower(trim($data['email']))]));
    if(!$row||!password_verify($data['password'],$row['password_hash'])) jsonError('Credenciales incorrectas',401);
    $token=base64_encode($row['id'].'|'.$row['email'].'|'.md5($row['password_hash'].'medic_secret'));
    unset($row['password_hash']); jsonOk(['token'=>$token,'medico'=>$row]);
}
function checkMedico(): int {
    $auth=$_SERVER['HTTP_X_MEDICO_TOKEN']??''; if(!$auth) jsonError('No autorizado',401);
    $parts=explode('|',base64_decode($auth)); if(count($parts)!==3) jsonError('Token invalido',401);
    [$id,$email,$hash]=$parts;
    $row=fetchOne(query('SELECT id,password_hash FROM medicos WHERE id=? AND email=?','is',[(int)$id,$email]));
    if(!$row||md5($row['password_hash'].'medic_secret')!==$hash) jsonError('Token invalido',401);
    return (int)$id;
}
function medicoPerfil(): void {
    $medicoId=checkMedico(); $db=getDB();
    $stmt=$db->prepare('SELECT m.id,m.titulo,m.nombre,m.apellido,m.email,m.telefono,m.ciudad,m.genero,m.licencia,m.estado,m.foto_perfil,m.creado_en,e.especialidad,e.subespecialidad,e.anos_experiencia,e.idiomas,e.universidad,e.postgrado,e.biografia,p.tarifa,p.duracion_minutos,p.banco,p.tipo_cuenta,p.numero_cuenta,p.cedula_titular,p.nombre_titular,p.plan_liquidacion,p.frecuencia_pago FROM medicos m LEFT JOIN medico_especialidad e ON e.medico_id=m.id LEFT JOIN medico_pago p ON p.medico_id=m.id WHERE m.id=?');
    $stmt->bind_param('i',$medicoId); $stmt->execute(); $perfil=fetchOne($stmt);
    $stmt2=$db->prepare('SELECT dia_semana,hora FROM medico_disponibilidad WHERE medico_id=? AND activo=1 ORDER BY FIELD(dia_semana,"Lunes","Martes","Miércoles","Jueves","Viernes","Sábado","Domingo"),hora');
    $stmt2->bind_param('i',$medicoId); $stmt2->execute();
    $perfil['disponibilidad']=$stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt3=$db->prepare('SELECT r.id,r.horario,r.motivo,r.monto_medico,r.estado_pago,r.estado_consulta,r.limite_confirmacion,r.notas_cancelacion,r.sala_video,p.nombre AS paciente,p.email AS email_paciente FROM reservas r JOIN pacientes p ON p.id=r.paciente_id WHERE r.medico_id=? ORDER BY r.horario DESC');
    $stmt3->bind_param('i',$medicoId); $stmt3->execute();
    $perfil['reservas']=$stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    $perfil['total_reservas']=count($perfil['reservas']);
    jsonOk($perfil);
}
function medicoActualizar(): void {
    $medicoId=checkMedico(); $data=json_decode(file_get_contents('php://input'),true);
    $db=getDB(); $db->begin_transaction();
    $stmt=$db->prepare('UPDATE medicos SET telefono=?,ciudad=?,genero=? WHERE id=?');
    $stmt->bind_param('sssi',$data['telefono'],$data['ciudad'],$data['genero'],$medicoId); $stmt->execute();
    $sub=(string)($data['subespecialidad']??''); $post=(string)($data['postgrado']??'');
    $stmt=$db->prepare('UPDATE medico_especialidad SET especialidad=?,subespecialidad=?,anos_experiencia=?,idiomas=?,universidad=?,postgrado=?,biografia=? WHERE medico_id=?');
    $stmt->bind_param('sssssssi',$data['especialidad'],$sub,$data['anos_experiencia'],$data['idiomas'],$data['universidad'],$post,$data['biografia'],$medicoId); $stmt->execute();
    $tarifa=(float)($data['tarifa']??0); $dur=(int)($data['duracion_minutos']??30);
    $stmt=$db->prepare('UPDATE medico_pago SET tarifa=?,duracion_minutos=?,banco=?,tipo_cuenta=?,numero_cuenta=?,cedula_titular=?,nombre_titular=? WHERE medico_id=?');
    $stmt->bind_param('disssssi',$tarifa,$dur,$data['banco'],$data['tipo_cuenta'],$data['numero_cuenta'],$data['cedula_titular'],$data['nombre_titular'],$medicoId); $stmt->execute();
    if(!empty($data['foto_base64'])){try{$fp=guardarFotoBase64($data['foto_base64'],'update_'.$medicoId);if($fp){$st=$db->prepare('UPDATE medicos SET foto_perfil=? WHERE id=?');$st->bind_param('si',$fp,$medicoId);$st->execute();}}catch(Exception $e){}}
    if(isset($data['disponibilidad'])&&is_array($data['disponibilidad'])){
        $db->query("DELETE FROM medico_disponibilidad WHERE medico_id=$medicoId");
        $stmt=$db->prepare('INSERT INTO medico_disponibilidad (medico_id,dia_semana,hora) VALUES (?,?,?)');
        foreach($data['disponibilidad'] as $slot) if(!empty($slot['dia'])&&!empty($slot['hora'])){$stmt->bind_param('iss',$medicoId,$slot['dia'],$slot['hora']);$stmt->execute();}
    }
    $db->commit(); jsonOk(['mensaje'=>'Perfil actualizado correctamente']);
}
function medicoToggleEstado(): void {
    $medicoId=checkMedico(); $data=json_decode(file_get_contents('php://input'),true);
    $nuevo=$data['estado']??null;
    if(!in_array($nuevo,['activo','pendiente','suspendido'])){$row=fetchOne(query('SELECT estado FROM medicos WHERE id=?','i',[$medicoId]));$nuevo=$row['estado']==='activo'?'pendiente':'activo';}
    $db=getDB();$stmt=$db->prepare('UPDATE medicos SET estado=? WHERE id=?');$stmt->bind_param('si',$nuevo,$medicoId);$stmt->execute();
    jsonOk(['estado'=>$nuevo]);
}
function medicoCambiarPass(): void {
    $medicoId=checkMedico(); $data=json_decode(file_get_contents('php://input'),true);
    $actual=$data['password_actual']??''; $nueva=$data['password_nueva']??$data['password_nuevo']??'';
    if(!$actual||!$nueva) jsonError('Faltan datos');
    if(strlen($nueva)<8) jsonError('Password minimo 8 caracteres');
    $row=fetchOne(query('SELECT password_hash FROM medicos WHERE id=?','i',[$medicoId]));
    if(!password_verify($actual,$row['password_hash'])) jsonError('Password actual incorrecto');
    $hash=password_hash($nueva,PASSWORD_BCRYPT); $db=getDB();
    $stmt=$db->prepare('UPDATE medicos SET password_hash=? WHERE id=?');$stmt->bind_param('si',$hash,$medicoId);$stmt->execute();
    jsonOk(['mensaje'=>'Password actualizado']);
}
function medicoRecuperar(): void {
    $data=json_decode(file_get_contents('php://input'),true);
    if(empty($data['email'])) jsonError('Email requerido');
    $row=fetchOne(query('SELECT id,nombre FROM medicos WHERE email=?','s',[strtolower(trim($data['email']))]));
    if(!$row){jsonOk(['mensaje'=>'Si el correo existe recibirás instrucciones']);return;}
    $temp='Med'.rand(1000,9999).'!'; $hash=password_hash($temp,PASSWORD_BCRYPT);
    $db=getDB();$stmt=$db->prepare('UPDATE medicos SET password_hash=? WHERE id=?');$stmt->bind_param('si',$hash,$row['id']);$stmt->execute();
    jsonOk(['mensaje'=>'Password temporal generado','password_temp'=>$temp,'nota'=>'Pídele al médico que lo cambie al ingresar']);
}

// ── CONFIRMAR CONSULTA ────────────────────────────────────────────────────────
function confirmarConsulta(): void {
    $medicoId=checkMedico(); $data=json_decode(file_get_contents('php://input'),true);
    if(empty($data['reserva_id'])) jsonError('Falta reserva_id');
    $db=getDB();
    $stmt=$db->prepare('SELECT id,estado_pago,estado_consulta,limite_confirmacion,monto_total,comision,monto_medico FROM reservas WHERE id=? AND medico_id=?');
    $stmt->bind_param('ii',$data['reserva_id'],$medicoId);$stmt->execute();
    $r=$stmt->get_result()->fetch_assoc();
    if(!$r) jsonError('Reserva no encontrada');
    if($r['estado_consulta']==='confirmada') jsonError('Ya fue confirmada');
    if($r['estado_consulta']==='cancelada')  jsonError('Fue cancelada');
    if($r['estado_pago']==='reembolsado')    jsonError('Ya fue reembolsada');
    if($r['limite_confirmacion']&&strtotime($r['limite_confirmacion'])<time()) jsonError('Tiempo límite expirado');
    $ahora=date('Y-m-d H:i:s'); $rid=(int)$data['reserva_id'];
    $com=(float)$r['comision']; $neto=(float)$r['monto_medico'];
    $upd=$db->prepare('UPDATE reservas SET estado_consulta="confirmada",estado_pago="pagado",estado_pago_medico="transferido",confirmada_en=? WHERE id=?');
    $upd->bind_param('si',$ahora,$rid);$upd->execute();
    $ins=$db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"comision",?,"Comision MedicOnline 15%")');
    $ins->bind_param('id',$rid,$com);$ins->execute();
    $ins2=$db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"liberacion",?,"Pago liberado al medico")');
    $ins2->bind_param('id',$rid,$neto);$ins2->execute();
    // Email al médico — pago liberado
    $medInfo = fetchOne(query('SELECT m.nombre,m.apellido,m.titulo,m.email FROM medicos m WHERE m.id=?','i',[$medicoId]));
    $pacInfo = fetchOne(query('SELECT p.nombre FROM pacientes p JOIN reservas r ON r.paciente_id=p.id WHERE r.id=?','i',[$rid]));
    if ($medInfo) {
        emailPagoLiberado([
            'medico_nombre' => $medInfo['titulo'].' '.$medInfo['nombre'].' '.$medInfo['apellido'],
            'email_medico'  => $medInfo['email'],
            'paciente'      => $pacInfo['nombre'] ?? 'Paciente',
            'monto_medico'  => number_format($neto, 2),
            'confirmada_en' => $ahora,
        ]);
    }
    jsonOk(['mensaje'=>'Consulta confirmada. Pago liberado.','monto_recibido'=>'$'.number_format($neto,2),'confirmada_en'=>$ahora]);
}

// ── REEMBOLSOS ────────────────────────────────────────────────────────────────
function procesarReembolsos(): void {
    if(($_GET['cron_key']??'')!==CRON_KEY) jsonError('No autorizado',401);
    $db=getDB(); $ahora=date('Y-m-d H:i:s');
    $stmt=$db->prepare('SELECT id,monto_total FROM reservas WHERE estado_pago IN ("en_custodia","pagado") AND estado_consulta="agendada" AND limite_confirmacion IS NOT NULL AND limite_confirmacion<?');
    $stmt->bind_param('s',$ahora);$stmt->execute();
    $pend=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $n=0;
    foreach($pend as $r){
        $id=(int)$r['id']; $monto=(float)$r['monto_total'];
        $upd=$db->prepare('UPDATE reservas SET estado_pago="reembolsado",estado_consulta="no_realizada",reembolsada_en=?,notas_cancelacion="Reembolso automatico: medico no confirmo en 24h" WHERE id=?');
        $upd->bind_param('si',$ahora,$id);$upd->execute();
        $ins=$db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"reembolso",?,"Reembolso automatico")');
        $ins->bind_param('id',$id,$monto);$ins->execute(); $n++;
    }
    jsonOk(['reembolsos_procesados'=>$n,'ejecutado_en'=>$ahora]);
}
function adminReembolso(): void {
    checkAdmin(); $data=json_decode(file_get_contents('php://input'),true);
    if(empty($data['reserva_id'])) jsonError('Falta reserva_id');
    $db=getDB(); $ahora=date('Y-m-d H:i:s'); $nota=(string)($data['motivo']??'Reembolso manual'); $id=(int)$data['reserva_id'];
    $stmt=$db->prepare('SELECT id,monto_total,estado_pago FROM reservas WHERE id=?');$stmt->bind_param('i',$id);$stmt->execute();
    $r=$stmt->get_result()->fetch_assoc();
    if(!$r) jsonError('Reserva no encontrada');
    if($r['estado_pago']==='reembolsado') jsonError('Ya fue reembolsada');
    $monto=(float)$r['monto_total'];
    $upd=$db->prepare('UPDATE reservas SET estado_pago="reembolsado",estado_consulta="cancelada",reembolsada_en=?,notas_cancelacion=? WHERE id=?');
    $upd->bind_param('ssi',$ahora,$nota,$id);$upd->execute();
    $ins=$db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"reembolso",?,?)');
    $ins->bind_param('ids',$id,$monto,$nota);$ins->execute();
    jsonOk(['mensaje'=>'Reembolso procesado','monto'=>'$'.number_format($monto,2)]);
}

// ── RECORDATORIOS DÍA DE CONSULTA ────────────────────────────────────────────
function enviarRecordatorios(): void {
    if(($_GET['cron_key']??'')!==CRON_KEY) jsonError('No autorizado',401);
    $db   = getDB();
    $hoy  = date('Y-m-d');

    // Asegurar columna recordatorio_enviado ANTES del SELECT que la usa
    $chk = $db->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservas' AND COLUMN_NAME='recordatorio_enviado'");
    if ($chk && (int)$chk->fetch_assoc()['c'] === 0)
        $db->query("ALTER TABLE reservas ADD COLUMN recordatorio_enviado TINYINT(1) DEFAULT 0");

    // Buscar reservas agendadas para hoy que no hayan sido notificadas
    $stmt = $db->prepare("
        SELECT r.id, r.horario, r.sala_video, r.motivo,
               p.nombre AS paciente, p.email AS email_paciente,
               CONCAT(m.titulo,' ',m.nombre,' ',m.apellido) AS medico,
               m.email AS email_medico,
               e.especialidad
        FROM reservas r
        JOIN pacientes p ON p.id = r.paciente_id
        JOIN medicos m ON m.id = r.medico_id
        JOIN medico_especialidad e ON e.medico_id = m.id
        WHERE DATE(r.horario) = ?
          AND r.estado_consulta = 'agendada'
          AND r.estado_pago IN ('en_custodia','pagado')
          AND (r.recordatorio_enviado IS NULL OR r.recordatorio_enviado = 0)
    ");
    $stmt->bind_param('s', $hoy);
    $stmt->execute();
    $consultas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $enviados = 0;
    foreach ($consultas as $c) {
        $link = 'https://meet.jit.si/' . $c['sala_video'];

        // Email al paciente
        $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px">
<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <div style="background:#0D7A5F;padding:28px 32px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:22px">MedicOnline</h1>
    <p style="color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px">Recordatorio de consulta</p>
  </div>
  <div style="padding:32px">
    <h2 style="color:#1A1A18;font-size:18px;margin:0 0 6px">¡Hoy es tu consulta, '.$c['paciente'].'!</h2>
    <p style="color:#666;font-size:14px;margin:0 0 24px">Recuerda conectarte a tiempo con tu médico.</p>
    <table style="width:100%;border-collapse:collapse;margin-bottom:24px">
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Médico</td><td style="padding:10px 0;font-size:13px;font-weight:500;text-align:right">'.$c['medico'].'</td></tr>
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Especialidad</td><td style="padding:10px 0;font-size:13px;text-align:right">'.$c['especialidad'].'</td></tr>
      <tr><td style="padding:10px 0;color:#888;font-size:13px">Horario</td><td style="padding:10px 0;font-size:15px;font-weight:700;color:#0D7A5F;text-align:right">'.$c['horario'].'</td></tr>
    </table>
    <div style="background:#E8F5F1;border-radius:12px;padding:20px;margin-bottom:16px;border-left:4px solid #0D7A5F">
      <p style="margin:0 0 10px;font-size:13px;font-weight:600;color:#0D7A5F">📹 Tu enlace de videollamada</p>
      <div style="background:#fff;border-radius:8px;padding:10px 14px;font-size:12px;color:#0D7A5F;word-break:break-all;border:1px solid #b2ddd0">'.$link.'</div>
      <p style="margin:10px 0 0;font-size:11px;color:#666">Entra 5 minutos antes del horario para asegurar conexión.</p>
    </div>
  </div>
  <div style="background:#f5f5f5;padding:16px 32px;text-align:center;font-size:11px;color:#aaa">MedicOnline · Consultas médicas online · © 2025</div>
</div></body></html>';
        enviarEmail($c['email_paciente'], $c['paciente'], '⏰ Recordatorio: tu consulta es hoy — ' . $c['horario'], $html);

        // Email al médico
        $htmlM = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px">
<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <div style="background:#0D7A5F;padding:28px 32px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:22px">MedicOnline</h1>
    <p style="color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px">Recordatorio de consulta</p>
  </div>
  <div style="padding:32px">
    <h2 style="color:#1A1A18;font-size:18px;margin:0 0 6px">Tienes una consulta hoy</h2>
    <table style="width:100%;border-collapse:collapse;margin-bottom:24px">
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Paciente</td><td style="padding:10px 0;font-size:13px;font-weight:500;text-align:right">'.$c['paciente'].'</td></tr>
      <tr style="border-bottom:1px solid #eee"><td style="padding:10px 0;color:#888;font-size:13px">Motivo</td><td style="padding:10px 0;font-size:13px;text-align:right">'.($c['motivo']?:'No especificado').'</td></tr>
      <tr><td style="padding:10px 0;color:#888;font-size:13px">Horario</td><td style="padding:10px 0;font-size:15px;font-weight:700;color:#0D7A5F;text-align:right">'.$c['horario'].'</td></tr>
    </table>
    <div style="text-align:center">
      <a href="'.SITE_URL.'/medico-portal.html" style="display:inline-block;background:#0D7A5F;color:#fff;padding:14px 32px;border-radius:100px;text-decoration:none;font-size:14px;font-weight:600">Ir a mi portal →</a>
    </div>
  </div>
  <div style="background:#f5f5f5;padding:16px 32px;text-align:center;font-size:11px;color:#aaa">MedicOnline · © 2025</div>
</div></body></html>';
        enviarEmail($c['email_medico'], $c['medico'], '⏰ Tienes una consulta hoy — ' . $c['horario'], $htmlM);

        // Marcar recordatorio enviado
        $upd = $db->prepare('UPDATE reservas SET recordatorio_enviado=1 WHERE id=?');
        $upd->bind_param('i', $c['id']); $upd->execute();
        $enviados++;
    }

    jsonOk(['recordatorios_enviados' => $enviados, 'fecha' => $hoy]);
}

// ── ADMIN — Eliminar reserva ─────────────────────────────────────────────────
function adminEliminarReserva(): void {
    checkAdmin();
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['reserva_id'])) jsonError('Falta reserva_id');
    $id = (int)$data['reserva_id'];
    $db = getDB();

    // Verificar que existe
    $chk = $db->prepare('SELECT id, estado_pago FROM reservas WHERE id=?');
    $chk->bind_param('i',$id); $chk->execute();
    $r = $chk->get_result()->fetch_assoc();
    if (!$r) jsonError('Reserva no encontrada');

    // Advertir si tiene pago activo (igual se puede eliminar pero el admin decidió)
    $tienePago = in_array($r['estado_pago'], ['en_custodia','pagado']);

    // Eliminar transacciones asociadas primero (FK)
    $db->query("DELETE FROM transacciones WHERE reserva_id=$id");

    // Eliminar la reserva
    $stmt = $db->prepare('DELETE FROM reservas WHERE id=?');
    $stmt->bind_param('i',$id); $stmt->execute();

    jsonOk([
        'eliminada'   => $stmt->affected_rows > 0,
        'reserva_id'  => $id,
        'aviso'       => $tienePago ? 'La reserva tenía un pago activo. Recuerda gestionar el reembolso externamente si aplica.' : null,
    ]);
}

// ── PACIENTE SALA ─────────────────────────────────────────────────────────────
function pacienteSala(): void {
    $token = trim($_GET['token'] ?? '');
    $email = strtolower(trim($_GET['email'] ?? ''));
    if (!$token || !$email) jsonError('Faltan datos (token y email).', 400);
    $db = getDB();
    $stmt = $db->prepare('SELECT r.id,r.horario,r.sala_video,r.estado_consulta,r.estado_pago,CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico,e.especialidad FROM reservas r JOIN medicos m ON m.id=r.medico_id JOIN medico_especialidad e ON e.medico_id=m.id JOIN pacientes p ON p.id=r.paciente_id WHERE r.token_acceso=? AND LOWER(p.email)=?');
    $stmt->bind_param('ss', $token, $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) jsonError('Reserva no encontrada.', 404);
    if (!$row['sala_video']) jsonError('Esta reserva no tiene sala de video.', 404);
    jsonOk(['reserva_id'=>$row['id'],'horario'=>$row['horario'],'medico'=>$row['medico'],'especialidad'=>$row['especialidad'],'sala_video'=>$row['sala_video'],'sala_url'=>'https://meet.jit.si/'.$row['sala_video'],'estado_consulta'=>$row['estado_consulta'],'estado_pago'=>$row['estado_pago']]);
}

// ── EMERGENCIAS ───────────────────────────────────────────────────────────────
function emergencyMultiplier(): float {
    return defined('EMERGENCY_RATE_MULTIPLIER') ? (float)EMERGENCY_RATE_MULTIPLIER : 1.5;
}

function listarEmergencias(): void {
    $mult = emergencyMultiplier();
    $stmt = query(
        'SELECT m.id, m.titulo, m.nombre, m.apellido, m.foto_perfil, e.especialidad, e.anos_experiencia, p.tarifa, ROUND(p.tarifa * ?, 2) AS tarifa_final
         FROM medicos m
         JOIN medico_especialidad e ON e.medico_id = m.id
         JOIN medico_pago p ON p.medico_id = m.id
         WHERE m.estado = "activo"
         ORDER BY m.id DESC',
        'd', [$mult]
    );
    jsonOk(fetchAll($stmt));
}

function reservarEmergencia(): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    foreach (['medico_id','nombre_paciente','email_paciente'] as $f)
        if (empty($data[$f])) throw new Exception("Campo requerido: $f");
    if (!filter_var($data['email_paciente'], FILTER_VALIDATE_EMAIL))
        throw new Exception('Correo inválido.');

    $db = getDB();

    // upsert paciente
    $row = fetchOne(query('SELECT id FROM pacientes WHERE email=?','s',[$data['email_paciente']]));
    if ($row) {
        $pacienteId = (int)$row['id'];
    } else {
        $tel = (string)($data['telefono_paciente'] ?? '');
        $stmt = $db->prepare('INSERT INTO pacientes (nombre,email,telefono,edad) VALUES (?,?,?,0)');
        $stmt->bind_param('sss', $data['nombre_paciente'], $data['email_paciente'], $tel);
        $stmt->execute();
        $pacienteId = (int)$db->insert_id;
    }

    $medicoId = (int)$data['medico_id'];
    $pago = fetchOne(query('SELECT tarifa FROM medico_pago WHERE medico_id=?','i',[$medicoId]));
    if (!$pago) throw new Exception('Médico no encontrado o sin tarifa.');

    // Validar médico activo
    $med = fetchOne(query('SELECT estado FROM medicos WHERE id=?','i',[$medicoId]));
    if (!$med || $med['estado'] !== 'activo') throw new Exception('Médico no disponible para emergencias.');

    $tarifaBase = (float)$pago['tarifa'];
    $total      = round($tarifaBase * emergencyMultiplier(), 2);
    $comision   = round($total * COMMISSION_RATE, 2);
    $neto       = round($total - $comision, 2);
    $motivo     = (string)($data['motivo']     ?? 'Emergencia');
    $alergias   = (string)($data['alergias']   ?? '');
    $metodoPago = (string)($data['metodo_pago']?? 'emergencia');
    $horario    = date('Y-m-d H:i:s'); // ahora
    $salaVideo   = 'medicnet-em-' . bin2hex(random_bytes(10));
    $tokenAcceso = strtoupper(substr(bin2hex(random_bytes(4)),0,4) . '-' . substr(bin2hex(random_bytes(4)),0,4) . '-' . substr(bin2hex(random_bytes(4)),0,4));

    $stmt = $db->prepare('INSERT INTO reservas (medico_id,paciente_id,horario,motivo,alergias,metodo_pago,monto_total,comision,monto_medico,estado_pago,limite_confirmacion,sala_video,token_acceso) VALUES (?,?,?,?,?,?,?,?,?,"en_custodia",DATE_ADD(NOW(),INTERVAL 2 HOUR),?,?)');
    $stmt->bind_param('iissssdddss', $medicoId, $pacienteId, $horario, $motivo, $alergias, $metodoPago, $total, $comision, $neto, $salaVideo, $tokenAcceso);
    $stmt->execute();
    $reservaId = (int)$db->insert_id;
    if (!$reservaId) throw new Exception('No se pudo crear la reserva de emergencia.');

    $ins = $db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"custodia",?,"Pago de emergencia recibido en custodia")');
    $ins->bind_param('id', $reservaId, $total);
    $ins->execute();

    // Email al paciente + médico (best-effort, no rompe la transacción si falla)
    $medDat = fetchOne(query('SELECT m.nombre,m.apellido,m.titulo,m.email,e.especialidad FROM medicos m JOIN medico_especialidad e ON e.medico_id=m.id WHERE m.id=?','i',[$medicoId]));
    if ($medDat) {
        $nombreMedico = $medDat['titulo'].' '.$medDat['nombre'].' '.$medDat['apellido'];
        emailReservaConfirmada([
            'paciente'       => $data['nombre_paciente'],
            'email_paciente' => $data['email_paciente'],
            'medico'         => $nombreMedico,
            'especialidad'   => $medDat['especialidad'],
            'horario'        => $horario . ' (EMERGENCIA — ahora)',
            'reserva_id'     => $reservaId,
            'sala_video'     => $salaVideo,
            'monto'          => number_format($total, 2),
        ]);
        emailNuevaReservaDoctor([
            'medico_nombre' => $nombreMedico,
            'email_medico'  => $medDat['email'],
            'paciente'      => $data['nombre_paciente'],
            'motivo'        => '🚨 EMERGENCIA: ' . ($motivo ?: 'No especificado'),
            'horario'       => $horario,
            'monto_medico'  => number_format($neto, 2),
        ]);
    }

    jsonOk([
        'reserva_id'   => $reservaId,
        'sala_video'   => $salaVideo,
        'token_acceso' => $tokenAcceso,
        'monto_total'  => $total,
        'mensaje'      => 'Reserva de emergencia creada. Conectándote al médico ahora.',
    ]);
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
function jsonOk(mixed $data): void {
    ob_end_clean();
    echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError(string $msg, int $code=400): void {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
    exit;
}
