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
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, X-Medico-Token, X-Paciente-Token');
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
        case 'horarios_disponibles':    horariosDisponibles();    break;
        case 'medico_agenda':           medicoAgenda();           break;
        case 'medico_bloqueo_crear':    medicoBloqueoCrear();     break;
        case 'medico_bloqueo_eliminar': medicoBloqueoEliminar();  break;
        case 'medico_disponibilidad_guardar': medicoDisponibilidadGuardar(); break;
        case 'paciente_cancelar_reserva':    pacienteCancelarReserva();    break;
        case 'paciente_reprogramar_reserva': pacienteReprogramarReserva(); break;
        case 'medico_cancelar_reserva':      medicoCancelarReserva();      break;
        case 'admin_login':          adminLogin();           break;
        case 'admin_medicos':        adminMedicos();         break;
        case 'admin_eliminar':       adminEliminar();        break;
        case 'admin_estado':         adminEstado();          break;
        case 'admin_reservas':       adminReservas();        break;
        case 'admin_stats':          adminStats();           break;
        case 'admin_finanzas':       adminFinanzas();        break;
        case 'medico_login':         medicoLogin();          break;
        case 'medico_perfil':        medicoPerfil();         break;
        case 'medico_actualizar':    medicoActualizar();     break;
        case 'medico_toggle_estado': medicoToggleEstado();   break;
        case 'medico_cambiar_pass':  medicoCambiarPass();    break;
        case 'medico_recuperar':     medicoRecuperar();      break;
        case 'medico_codigos':        medicoCodigos();        break;
        case 'medico_codigo_crear':   medicoCodigoCrear();    break;
        case 'medico_codigo_revocar': medicoCodigoRevocar();  break;
        case 'medico_codigo_eliminar': medicoCodigoEliminar(); break;
        case 'validar_codigo':        validarCodigo();        break;
        case 'paciente_registro':             pacienteRegistro();            break;
        case 'paciente_login':                pacienteLogin();               break;
        case 'paciente_recuperar':            pacienteRecuperar();           break;
        case 'paciente_perfil':               pacientePerfil();              break;
        case 'paciente_actualizar':           pacienteActualizar();          break;
        case 'paciente_historial_actualizar': pacienteHistorialActualizar(); break;
        case 'medico_ver_historial':          medicoVerHistorialPaciente();  break;
        case 'medico_guardar_nota':           medicoGuardarNota();           break;
        case 'medico_pacientes':             medicoPacientes();             break;
        case 'medico_expediente':            medicoExpediente();            break;
        case 'medico_tratamiento_crear':     medicoTratamientoCrear();      break;
        case 'medico_tratamiento_actualizar':medicoTratamientoActualizar(); break;
        case 'medico_vitales_registrar':     medicoVitalesRegistrar();      break;
        case 'medico_documento_subir':       medicoDocumentoSubir();        break;
        case 'medico_documento_eliminar':    medicoDocumentoEliminar();     break;
        case 'medico_receta_crear': medicoRecetaCrear(); break;
        case 'receta_ver':          recetaVer();          break;
        case 'confirmar_consulta':   confirmarConsulta();    break;
        case 'procesar_reembolsos':  procesarReembolsos();   break;
        case 'admin_reembolso':      adminReembolso();       break;
        case 'admin_eliminar_reserva': adminEliminarReserva(); break;
        case 'paciente_sala':        pacienteSala();         break;
        case 'enviar_recordatorios': enviarRecordatorios();   break;
        case 'listar_emergencias':   listarEmergencias();    break;
        case 'reservar_emergencia':  reservarEmergencia();   break;
        case 'medico_toggle_emergencia': medicoToggleEmergencia(); break;
        case 'medico_pagos':         medicoPagos();           break;
        case 'crear_resena':         crearResena();           break;
        case 'listar_resenas_medico': listarResenasMedico();  break;
        case 'medico_resenas':       medicoResenas();         break;
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
    ensureResenasTable();
    $spec = $_GET['especialidad'] ?? null;
    $medicos = fetchAll(query('SELECT * FROM v_medicos_activos ORDER BY id DESC'));
    $db = getDB();
    $sd = $db->prepare('SELECT dia_semana,hora FROM medico_disponibilidad WHERE medico_id=? AND activo=1 ORDER BY FIELD(dia_semana,"Lunes","Martes","Miércoles","Jueves","Viernes","Sábado","Domingo"),hora');
    $sr = $db->prepare('SELECT COUNT(*) AS total, IFNULL(ROUND(AVG(estrellas),2),0) AS promedio FROM resenas WHERE medico_id=?');
    $se = $db->prepare('SELECT educacion,especialidades,idiomas_lista,experiencia FROM medico_especialidad WHERE medico_id=?');
    foreach ($medicos as &$m) {
        $sd->bind_param('i',$m['id']); $sd->execute();
        $slots=$sd->get_result()->fetch_all(MYSQLI_ASSOC);
        $m['disponibilidad']=array_map(fn($s)=>$s['dia_semana'].' '.$s['hora'],$slots);
        $se->bind_param('i',$m['id']); $se->execute();
        $er=$se->get_result()->fetch_assoc();
        $m['educacion']      = $er ? (json_decode($er['educacion'] ?: '[]', true) ?: []) : [];
        $m['especialidades'] = $er ? (json_decode($er['especialidades'] ?: '[]', true) ?: []) : [];
        $m['idiomas_lista']  = $er ? (json_decode($er['idiomas_lista'] ?: '[]', true) ?: []) : [];
        $m['experiencia']    = $er ? (json_decode($er['experiencia'] ?: '[]', true) ?: []) : [];
        $sr->bind_param('i',$m['id']); $sr->execute();
        $stats=$sr->get_result()->fetch_assoc();
        $m['total_resenas']     = (int)($stats['total'] ?? 0);
        $m['estrella_promedio'] = (float)($stats['promedio'] ?? 0);
        $sl=generarSlotsDisponibles($m['id'],28); $m['proximo_disponible']=$sl?$sl[0]['label']:null;
    }
    unset($m);
    if ($spec && $spec !== 'Todos') {
        $medicos = array_values(array_filter($medicos, fn($m) => in_array($spec, $m['especialidades'], true) || ($m['especialidad'] ?? '') === $spec));
    }
    jsonOk($medicos);
}

// ── CREAR RESERVA ─────────────────────────────────────────────────────────────
function crearReserva(): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    foreach (['medico_id','inicio','metodo_pago'] as $f)
        if (empty($data[$f])) throw new Exception("Campo requerido: $f");

    $pacienteId = checkPaciente();
    $db  = getDB();
    $inicioVal = (string)($data['inicio'] ?? '');
    $data['horario'] = validarNuevoInicio((int)$data['medico_id'], $inicioVal);
    $pac = fetchOne(query('SELECT nombre,email FROM pacientes WHERE id=?','i',[$pacienteId]));
    $data['nombre_paciente'] = $pac['nombre'];
    $data['email_paciente']  = $pac['email'];

    $pago = fetchOne(query('SELECT tarifa FROM medico_pago WHERE medico_id=?','i',[(int)$data['medico_id']]));
    if (!$pago) throw new Exception('Medico no encontrado o sin tarifa.');

    $medicoId   = (int)$data['medico_id'];
    $motivo     = (string)($data['motivo']   ?? '');
    $alergias   = (string)($data['alergias'] ?? '');
    $esCortesia = (($data['metodo_pago'] ?? '') === 'cortesia');

    // Asegurar columnas sala_video / token_acceso ANTES de abrir cualquier transacción
    // (ALTER hace commit implícito; ya existen, así que en la práctica no corre).
    $chk = $db->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservas' AND COLUMN_NAME='sala_video'");
    if ($chk && (int)$chk->fetch_assoc()['c'] === 0)
        $db->query("ALTER TABLE reservas ADD COLUMN sala_video VARCHAR(64) DEFAULT NULL");
    $chk2 = $db->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reservas' AND COLUMN_NAME='token_acceso'");
    if ($chk2 && (int)$chk2->fetch_assoc()['c'] === 0)
        $db->query("ALTER TABLE reservas ADD COLUMN token_acceso VARCHAR(32) DEFAULT NULL");

    // ── Cortesía: bloquear y validar el código dentro de una transacción ──
    $codigoId = null;
    if ($esCortesia) {
        $codigoStr = strtoupper(trim((string)($data['codigo'] ?? '')));
        if ($codigoStr === '') throw new Exception('Falta el código de cortesía.');
        $db->begin_transaction();
        $st = $db->prepare('SELECT id,usos_max,usos_count,estado,expira_en FROM medico_codigos WHERE codigo=? AND medico_id=? FOR UPDATE');
        $st->bind_param('si', $codigoStr, $medicoId); $st->execute();
        $cod = $st->get_result()->fetch_assoc();
        if (!$cod)                          { $db->rollback(); throw new Exception('Código de cortesía no válido para este médico.'); }
        if ($cod['estado'] === 'revocado')  { $db->rollback(); throw new Exception('El código de cortesía fue revocado.'); }
        if ($cod['estado'] === 'agotado' || (int)$cod['usos_count'] >= (int)$cod['usos_max']) { $db->rollback(); throw new Exception('El código de cortesía está agotado.'); }
        if ($cod['expira_en'] && $cod['expira_en'] < date('Y-m-d')) { $db->rollback(); throw new Exception('El código de cortesía está vencido.'); }
        $codigoId = (int)$cod['id'];
    }

    if ($esCortesia) {
        $total = 0.0; $comision = 0.0; $neto = 0.0; $estadoPago = 'exonerado';
    } else {
        $total      = (float)$pago['tarifa'];
        $comision   = round($total * COMMISSION_RATE, 2);
        $neto       = round($total - $comision, 2);
        $estadoPago = 'en_custodia';
    }

    $salaVideo   = 'medicnet-' . bin2hex(random_bytes(10));
    $tokenAcceso = strtoupper(substr(bin2hex(random_bytes(4)),0,4) . '-' . substr(bin2hex(random_bytes(4)),0,4) . '-' . substr(bin2hex(random_bytes(4)),0,4));

    $stmt = $db->prepare('INSERT INTO reservas (medico_id,paciente_id,horario,inicio,motivo,alergias,metodo_pago,monto_total,comision,monto_medico,estado_pago,limite_confirmacion,sala_video,token_acceso) VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR),?,?)');
    if (!$stmt) { if ($esCortesia) $db->rollback(); throw new Exception('Prepare reservas: '.$db->error); }
    $stmt->bind_param('iisssssdddsss',$medicoId,$pacienteId,$data['horario'],$inicioVal,$motivo,$alergias,$data['metodo_pago'],$total,$comision,$neto,$estadoPago,$salaVideo,$tokenAcceso);
    if (!$stmt->execute()) { if ($esCortesia) $db->rollback(); throw new Exception('Execute reservas: '.$stmt->error); }
    $reservaId = (int)$db->insert_id;
    if (!$reservaId) { if ($esCortesia) $db->rollback(); throw new Exception('insert_id=0 — revisar permisos INSERT en tabla reservas'); }

    if ($esCortesia) {
        // estado se evalúa ANTES de incrementar (MariaDB aplica los SET de izq. a der.)
        $u = $db->prepare('UPDATE medico_codigos SET estado=IF(usos_count+1>=usos_max,"agotado","activo"), usos_count=usos_count+1 WHERE id=?');
        if (!$u) { $db->rollback(); throw new Exception('Prepare update codigo: '.$db->error); }
        $u->bind_param('i', $codigoId);
        if (!$u->execute()) { $db->rollback(); throw new Exception('No se pudo actualizar el código de cortesía.'); }
        $cu = $db->prepare('INSERT INTO codigo_usos (codigo_id,reserva_id,paciente_email) VALUES (?,?,?)');
        if (!$cu) { $db->rollback(); throw new Exception('Prepare codigo_usos: '.$db->error); }
        $cu->bind_param('iis', $codigoId, $reservaId, $data['email_paciente']);
        if (!$cu->execute()) { $db->rollback(); throw new Exception('No se pudo registrar el canje del código.'); }
        $db->commit();
    } else {
        $ins = $db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"custodia",?,"Pago recibido en custodia")');
        if ($ins) { $ins->bind_param('id',$reservaId,$total); $ins->execute(); }
    }

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

// ── AGENDA / SLOTS ────────────────────────────────────────────────────────────
function generarSlotsDisponibles(int $medicoId, int $dias = 28): array {
    $db = getDB();
    $st = $db->prepare("SELECT dia_semana,hora FROM medico_disponibilidad WHERE medico_id=? AND activo=1");
    $st->bind_param('i',$medicoId); $st->execute();
    $plantilla = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$plantilla) return [];
    $pago = fetchOne(query('SELECT duracion_minutos FROM medico_pago WHERE medico_id=?','i',[$medicoId]));
    $dur = $pago ? (int)$pago['duracion_minutos'] : 30; if ($dur <= 0) $dur = 30;
    $sb = $db->prepare("SELECT fecha_desde,fecha_hasta FROM medico_bloqueos WHERE medico_id=?");
    $sb->bind_param('i',$medicoId); $sb->execute();
    $bloqueos = $sb->get_result()->fetch_all(MYSQLI_ASSOC);
    $so = $db->prepare("SELECT inicio FROM reservas WHERE medico_id=? AND estado_consulta IN ('agendada','confirmada') AND inicio IS NOT NULL");
    $so->bind_param('i',$medicoId); $so->execute();
    $ocupSet = array_flip(array_column($so->get_result()->fetch_all(MYSQLI_ASSOC),'inicio'));
    $map = [1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
    $mAbr = [1=>'ene',2=>'feb',3=>'mar',4=>'abr',5=>'may',6=>'jun',7=>'jul',8=>'ago',9=>'sep',10=>'oct',11=>'nov',12=>'dic'];
    $dAbr = ['Lunes'=>'Lun','Martes'=>'Mar','Miércoles'=>'Mié','Jueves'=>'Jue','Viernes'=>'Vie','Sábado'=>'Sáb','Domingo'=>'Dom'];
    $ahora = time(); $slots = [];
    for ($d = 0; $d < $dias; $d++) {
        $ts = strtotime("+$d day", $ahora); $fecha = date('Y-m-d',$ts); $diaNom = $map[(int)date('N',$ts)];
        $blocked = false; foreach ($bloqueos as $b) { if ($fecha >= $b['fecha_desde'] && $fecha <= $b['fecha_hasta']) { $blocked = true; break; } }
        if ($blocked) continue;
        foreach ($plantilla as $p) {
            if ($p['dia_semana'] !== $diaNom) continue;
            $hora = substr($p['hora'],0,5);
            $inicio = $fecha.' '.$hora.':00';
            $its = strtotime($inicio);
            if ($its <= $ahora || isset($ocupSet[$inicio])) continue;
            $fin = date('H:i', $its + $dur*60);
            $slots[] = ['fecha'=>$fecha,'dia'=>$diaNom,'hora'=>$hora,'fin'=>$fin,'duracion'=>$dur,'inicio'=>$inicio,
                'label'=>$dAbr[$diaNom].' '.(int)date('j',$ts).' '.$mAbr[(int)date('n',$ts)].' · '.$hora.'–'.$fin];
        }
    }
    usort($slots, fn($a,$b)=>strcmp($a['inicio'],$b['inicio']));
    return $slots;
}

function validarNuevoInicio(int $medicoId, string $inicio, ?int $excluirId = null): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $inicio)) throw new Exception('Fecha/hora inválida.');
    $its = strtotime($inicio);
    if ($its === false || $its <= time()) throw new Exception('Ese horario ya pasó, elige otro.');
    if ($its > strtotime('+29 days')) throw new Exception('Solo puedes agendar hasta 4 semanas adelante.');
    $fecha = substr($inicio, 0, 10);
    if (fetchOne(query('SELECT id FROM medico_bloqueos WHERE medico_id=? AND ? BETWEEN fecha_desde AND fecha_hasta LIMIT 1','is',[$medicoId,$fecha])))
        throw new Exception('Ese día no está disponible.');
    if ($excluirId) {
        $ocupado = fetchOne(query("SELECT id FROM reservas WHERE medico_id=? AND inicio=? AND estado_consulta IN ('agendada','confirmada') AND id<>? LIMIT 1",'isi',[$medicoId,$inicio,$excluirId]));
    } else {
        $ocupado = fetchOne(query("SELECT id FROM reservas WHERE medico_id=? AND inicio=? AND estado_consulta IN ('agendada','confirmada') LIMIT 1",'is',[$medicoId,$inicio]));
    }
    if ($ocupado) throw new Exception('Ese horario ya fue tomado. Elige otro.');
    $dAbr = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie','Sat'=>'Sáb','Sun'=>'Dom'];
    return $dAbr[date('D',$its)].' '.date('d/m',$its).' '.date('H:i',$its);
}

function horariosDisponibles(): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $mid = (int)($data['medico_id'] ?? ($_GET['medico_id'] ?? 0));
    if (!$mid) jsonError('Falta medico_id');
    jsonOk(generarSlotsDisponibles($mid, 28));
}

function medicoAgenda(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $semana = (int)($data['semana'] ?? 0);
    $lunesTs = strtotime('monday this week', strtotime(($semana*7).' days'));
    $desde = date('Y-m-d',$lunesTs); $hasta = date('Y-m-d', strtotime('+6 days',$lunesTs));
    $db = getDB();
    $st = $db->prepare("SELECT dia_semana,hora FROM medico_disponibilidad WHERE medico_id=? AND activo=1");
    $st->bind_param('i',$medicoId); $st->execute(); $plantilla = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $pago = fetchOne(query('SELECT duracion_minutos FROM medico_pago WHERE medico_id=?','i',[$medicoId]));
    $dur = $pago ? (int)$pago['duracion_minutos'] : 30; if ($dur <= 0) $dur = 30;
    $sc = $db->prepare("SELECT r.id AS reserva_id, r.inicio, r.paciente_id, p.nombre AS paciente, r.estado_consulta, r.motivo FROM reservas r JOIN pacientes p ON p.id=r.paciente_id WHERE r.medico_id=? AND r.inicio IS NOT NULL AND r.estado_consulta IN ('agendada','confirmada','realizada') AND DATE(r.inicio) BETWEEN ? AND ? ORDER BY r.inicio");
    $sc->bind_param('iss',$medicoId,$desde,$hasta); $sc->execute(); $citas = $sc->get_result()->fetch_all(MYSQLI_ASSOC);
    $sbl = $db->prepare("SELECT id,fecha_desde,fecha_hasta,motivo FROM medico_bloqueos WHERE medico_id=? AND fecha_desde<=? AND fecha_hasta>=? ORDER BY fecha_desde");
    $sbl->bind_param('iss',$medicoId,$hasta,$desde); $sbl->execute(); $bloqueos = $sbl->get_result()->fetch_all(MYSQLI_ASSOC);
    jsonOk(['desde'=>$desde,'hasta'=>$hasta,'duracion'=>$dur,'plantilla'=>$plantilla,'citas'=>$citas,'bloqueos'=>$bloqueos]);
}

function medicoBloqueoCrear(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $d = (string)($data['fecha_desde'] ?? ''); $h = (string)($data['fecha_hasta'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$h)) jsonError('Fechas inválidas');
    if ($h < $d) jsonError('La fecha "hasta" no puede ser menor que "desde"');
    $motivo = mb_substr(trim((string)($data['motivo'] ?? '')), 0, 200);
    $db = getDB(); $st = $db->prepare('INSERT INTO medico_bloqueos (medico_id,fecha_desde,fecha_hasta,motivo) VALUES (?,?,?,?)');
    $st->bind_param('isss',$medicoId,$d,$h,$motivo); $st->execute();
    jsonOk(['id'=>(int)$db->insert_id,'mensaje'=>'Días bloqueados']);
}

function medicoBloqueoEliminar(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['bloqueo_id'] ?? 0); if (!$id) jsonError('Falta bloqueo_id');
    $db = getDB(); $st = $db->prepare('DELETE FROM medico_bloqueos WHERE id=? AND medico_id=?');
    $st->bind_param('ii',$id,$medicoId); $st->execute();
    if ($db->affected_rows < 1) jsonError('Bloqueo no encontrado');
    jsonOk(['mensaje'=>'Bloqueo eliminado']);
}

// ── DISPONIBILIDAD (plantilla semanal editable) ───────────────────────────────
function medicoDisponibilidadGuardar(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $disp = $data['disponibilidad'] ?? null;
    if (!is_array($disp)) jsonError('Falta disponibilidad');
    $DIAS = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
    $db = getDB();
    $del = $db->prepare('DELETE FROM medico_disponibilidad WHERE medico_id=?');
    $del->bind_param('i',$medicoId); $del->execute();
    $ins = $db->prepare('INSERT INTO medico_disponibilidad (medico_id,dia_semana,hora) VALUES (?,?,?)');
    $n = 0; $vistos = [];
    foreach ($disp as $slot) {
        $dia  = (string)($slot['dia'] ?? '');
        $hora = substr((string)($slot['hora'] ?? ''), 0, 5);
        if (!in_array($dia, $DIAS, true) || !preg_match('/^\d{2}:\d{2}$/', $hora)) continue;
        $k = $dia.'|'.$hora; if (isset($vistos[$k])) continue; $vistos[$k] = 1;
        $ins->bind_param('iss',$medicoId,$dia,$hora); $ins->execute(); $n++;
    }
    jsonOk(['mensaje'=>'Disponibilidad actualizada','slots'=>$n]);
}

// ── CANCELAR / REPROGRAMAR ────────────────────────────────────────────────────
function cancelarReservaInterno(mysqli $db, int $rid, array $r, string $nota): void {
    $ahora = date('Y-m-d H:i:s');
    // Restituir el uso del código de cortesía si esta cita lo consumió
    $cu = $db->prepare('SELECT codigo_id FROM codigo_usos WHERE reserva_id=? LIMIT 1');
    $cu->bind_param('i',$rid); $cu->execute();
    $uso = $cu->get_result()->fetch_assoc();
    if ($uso) {
        $cid = (int)$uso['codigo_id'];
        $ucod = $db->prepare('UPDATE medico_codigos SET usos_count=GREATEST(usos_count-1,0), estado=IF(estado="agotado","activo",estado) WHERE id=?');
        $ucod->bind_param('i',$cid); $ucod->execute();
        $dus = $db->prepare('DELETE FROM codigo_usos WHERE reserva_id=?');
        $dus->bind_param('i',$rid); $dus->execute();
    }
    if (in_array($r['estado_pago'], ['en_custodia','pagado'], true)) {
        $u = $db->prepare('UPDATE reservas SET estado_consulta="cancelada", estado_pago="reembolsado", estado_pago_medico="pendiente", reembolsada_en=?, notas_cancelacion=? WHERE id=?');
        $u->bind_param('ssi',$ahora,$nota,$rid); $u->execute();
        $monto = (float)$r['monto_total'];
        $ins = $db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"reembolso",?,?)');
        $ins->bind_param('ids',$rid,$monto,$nota); $ins->execute();
    } else {
        $u = $db->prepare('UPDATE reservas SET estado_consulta="cancelada", notas_cancelacion=? WHERE id=?');
        $u->bind_param('si',$nota,$rid); $u->execute();
    }
}

function pacienteCancelarReserva(): void {
    $pid = checkPaciente(); $data = json_decode(file_get_contents('php://input'), true);
    $rid = (int)($data['reserva_id'] ?? 0); if (!$rid) jsonError('Falta reserva_id');
    $db = getDB();
    $r = fetchOne(query('SELECT id,medico_id,inicio,estado_consulta,estado_pago,monto_total FROM reservas WHERE id=? AND paciente_id=?','ii',[$rid,$pid]));
    if (!$r) jsonError('Reserva no encontrada');
    if (!in_array($r['estado_consulta'], ['agendada','confirmada'], true)) jsonError('No se puede cancelar esta cita.');
    if (empty($r['inicio']) || strtotime($r['inicio']) <= time()) jsonError('La cita ya pasó.');
    if (strtotime($r['inicio']) - time() < 12*3600) jsonError('No puedes cancelar con menos de 12 horas de anticipación. Contacta a soporte.');
    cancelarReservaInterno($db, $rid, $r, 'Cancelada por el paciente');
    $info = fetchOne(query('SELECT m.email AS med_email, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico, p.nombre AS paciente, r.horario FROM reservas r JOIN medicos m ON m.id=r.medico_id JOIN pacientes p ON p.id=r.paciente_id WHERE r.id=?','i',[$rid]));
    if ($info && !empty($info['med_email']))
        enviarEmail($info['med_email'], $info['medico'], 'Cita cancelada por el paciente',
            '<p>La cita de <strong>'.htmlspecialchars($info['paciente']).'</strong> del horario <strong>'.htmlspecialchars($info['horario']).'</strong> fue cancelada por el paciente.</p>');
    jsonOk(['mensaje'=>'Cita cancelada.']);
}

function medicoCancelarReserva(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $rid = (int)($data['reserva_id'] ?? 0); if (!$rid) jsonError('Falta reserva_id');
    $motivo = mb_substr(trim((string)($data['motivo'] ?? '')), 0, 200);
    if ($motivo === '') jsonError('Indica el motivo de la cancelación.');
    $db = getDB();
    $r = fetchOne(query('SELECT id,inicio,estado_consulta,estado_pago,monto_total FROM reservas WHERE id=? AND medico_id=?','ii',[$rid,$medicoId]));
    if (!$r) jsonError('Reserva no encontrada');
    if (!in_array($r['estado_consulta'], ['agendada','confirmada'], true)) jsonError('No se puede cancelar esta cita.');
    if (empty($r['inicio']) || strtotime($r['inicio']) <= time()) jsonError('La cita ya pasó.');
    cancelarReservaInterno($db, $rid, $r, 'Cancelada por el médico: '.$motivo);
    $info = fetchOne(query('SELECT p.email AS pac_email, p.nombre AS paciente, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico, r.horario, r.estado_pago FROM reservas r JOIN medicos m ON m.id=r.medico_id JOIN pacientes p ON p.id=r.paciente_id WHERE r.id=?','i',[$rid]));
    if ($info && !empty($info['pac_email'])) {
        $reemb = ($info['estado_pago']==='reembolsado') ? '<p>El pago fue reembolsado.</p>' : '';
        enviarEmail($info['pac_email'], $info['paciente'], 'Tu cita fue cancelada',
            '<p>Lamentamos informarte que <strong>'.htmlspecialchars($info['medico']).'</strong> canceló tu cita del <strong>'.htmlspecialchars($info['horario']).'</strong>.</p><p>Motivo: '.htmlspecialchars($motivo).'</p>'.$reemb.'<p>Puedes agendar un nuevo horario en medicvip.org.</p>');
    }
    jsonOk(['mensaje'=>'Cita cancelada. Se notificó al paciente.']);
}

function pacienteReprogramarReserva(): void {
    $pid = checkPaciente(); $data = json_decode(file_get_contents('php://input'), true);
    $rid = (int)($data['reserva_id'] ?? 0); if (!$rid) jsonError('Falta reserva_id');
    $nuevoInicio = (string)($data['inicio'] ?? '');
    $db = getDB();
    $r = fetchOne(query('SELECT id,medico_id,inicio,estado_consulta,estado_pago FROM reservas WHERE id=? AND paciente_id=?','ii',[$rid,$pid]));
    if (!$r) jsonError('Reserva no encontrada');
    if (!in_array($r['estado_consulta'], ['agendada','confirmada'], true)) jsonError('No se puede reprogramar esta cita.');
    if (empty($r['inicio']) || strtotime($r['inicio']) <= time()) jsonError('La cita ya pasó.');
    if (strtotime($r['inicio']) - time() < 12*3600) jsonError('No puedes reprogramar con menos de 12 horas de anticipación. Contacta a soporte.');
    $horario = validarNuevoInicio((int)$r['medico_id'], $nuevoInicio, $rid);
    $limite = date('Y-m-d H:i:s', time()+24*3600);
    if ($r['estado_pago'] === 'pagado') {
        $u = $db->prepare('UPDATE reservas SET inicio=?, horario=?, estado_consulta="agendada", confirmada_en=NULL, limite_confirmacion=?, estado_pago="en_custodia", estado_pago_medico="pendiente" WHERE id=?');
    } else {
        $u = $db->prepare('UPDATE reservas SET inicio=?, horario=?, estado_consulta="agendada", confirmada_en=NULL, limite_confirmacion=? WHERE id=?');
    }
    $u->bind_param('sssi',$nuevoInicio,$horario,$limite,$rid); $u->execute();
    $info = fetchOne(query('SELECT p.email AS pac_email, p.nombre AS paciente, m.email AS med_email, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico FROM reservas r JOIN medicos m ON m.id=r.medico_id JOIN pacientes p ON p.id=r.paciente_id WHERE r.id=?','i',[$rid]));
    if ($info) {
        if (!empty($info['pac_email'])) enviarEmail($info['pac_email'],$info['paciente'],'Cita reprogramada','<p>Tu cita con <strong>'.htmlspecialchars($info['medico']).'</strong> quedó reprogramada para <strong>'.htmlspecialchars($horario).'</strong>. El médico la confirmará.</p>');
        if (!empty($info['med_email'])) enviarEmail($info['med_email'],$info['medico'],'Cita reprogramada — confirmar','<p><strong>'.htmlspecialchars($info['paciente']).'</strong> reprogramó su cita para <strong>'.htmlspecialchars($horario).'</strong>. Confírmala desde tu portal.</p>');
    }
    jsonOk(['mensaje'=>'Cita reprogramada.','horario'=>$horario]);
}

// ── EMAIL ─────────────────────────────────────────────────────────────────────
function enviarEmail(string $to, string $toName, string $subject, string $htmlBody): bool {
    // SMTP autenticado a mailcow: 587 STARTTLS + AUTH LOGIN
    $ctx = stream_context_create(['ssl' => [
        'peer_name'        => 'mail.eneural.org',
        'verify_peer'      => true,
        'verify_peer_name' => true,
    ]]);
    $sock = @stream_socket_client('tcp://' . MAIL_HOST . ':' . MAIL_PORT, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) { error_log("medicvip mail: conexion fallo $errstr ($errno)"); return false; }
    stream_set_timeout($sock, 15);

    $read = function () use ($sock) {
        $data = '';
        while (($line = fgets($sock, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function ($c) use ($sock, $read) { fwrite($sock, $c . "\r\n"); return $read(); };
    $ok  = function ($resp, $code) { return strpos($resp, $code) === 0; };

    $read(); // saludo 220
    $cmd('EHLO medicvip.org');
    $r = $cmd('STARTTLS');
    if (!$ok($r, '220')) { error_log("medicvip mail: STARTTLS rechazado: $r"); fclose($sock); return false; }
    if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { error_log('medicvip mail: TLS handshake fallo'); fclose($sock); return false; }
    $cmd('EHLO medicvip.org');
    $cmd('AUTH LOGIN');
    $cmd(base64_encode(MAIL_USER));
    $r = $cmd(base64_encode(MAIL_PASS));
    if (!$ok($r, '235')) { error_log("medicvip mail: AUTH fallo: $r"); fclose($sock); return false; }

    $from = MAIL_FROM; $fromName = MAIL_NAME;
    $cmd("MAIL FROM:<$from>");
    $cmd("RCPT TO:<$to>");
    $r = $cmd('DATA');
    if (!$ok($r, '354')) { error_log("medicvip mail: DATA rechazado: $r"); fclose($sock); return false; }

    $msg  = "From: $fromName <$from>\r\n";
    $msg .= "To: $toName <$to>\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n";
    $msg .= "\r\n";
    $msg .= chunk_split(base64_encode($htmlBody));
    fwrite($sock, $msg . "\r\n.\r\n");
    $r = $read();
    $cmd('QUIT');
    fclose($sock);
    return $ok($r, '250');
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

// ── JWT (HS256) ───────────────────────────────────────────────────────────────
function jwtB64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function jwtB64UrlDecode(string $data): string {
    $pad = 4 - (strlen($data) % 4);
    if ($pad < 4) $data .= str_repeat('=', $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}
function jwtSecret(): string {
    if (!defined('JWT_SECRET') || JWT_SECRET === '' || JWT_SECRET === 'GENERAR_64_HEX_CHARS_O_MAS')
        throw new Exception('JWT_SECRET no configurado en api.config.php');
    return JWT_SECRET;
}
function jwtEncode(array $claims): string {
    $ttl = defined('JWT_EXP_SECONDS') ? (int)JWT_EXP_SECONDS : 28800;
    $claims['iat'] = time();
    $claims['exp'] = time() + $ttl;
    $header  = jwtB64UrlEncode(json_encode(['alg'=>'HS256','typ'=>'JWT']));
    $payload = jwtB64UrlEncode(json_encode($claims));
    $sig     = jwtB64UrlEncode(hash_hmac('sha256', "$header.$payload", jwtSecret(), true));
    return "$header.$payload.$sig";
}
function jwtDecode(string $token): array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) throw new Exception('Token mal formado');
    [$header, $payload, $sig] = $parts;
    $expected = jwtB64UrlEncode(hash_hmac('sha256', "$header.$payload", jwtSecret(), true));
    if (!hash_equals($expected, $sig)) throw new Exception('Firma JWT inválida');
    $claims = json_decode(jwtB64UrlDecode($payload), true);
    if (!is_array($claims)) throw new Exception('Payload JWT inválido');
    if (!isset($claims['exp']) || $claims['exp'] < time()) throw new Exception('Token expirado');
    return $claims;
}

// ── ADMIN ─────────────────────────────────────────────────────────────────────
function adminLogin(): void {
    $data = json_decode(file_get_contents('php://input'), true);
    if (($data['usuario'] ?? '') !== ADMIN_USER || ($data['password'] ?? '') !== ADMIN_PASS)
        jsonError('Credenciales incorrectas', 401);
    $token = jwtEncode(['role' => 'admin', 'sub' => ADMIN_USER]);
    jsonOk(['token' => $token, 'expira_en' => defined('JWT_EXP_SECONDS') ? (int)JWT_EXP_SECONDS : 28800]);
}
function checkAdmin(): void {
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '';
    if (!$token) jsonError('No autorizado', 401);
    try {
        $claims = jwtDecode($token);
        if (($claims['role'] ?? '') !== 'admin') throw new Exception('Rol incorrecto');
    } catch (Exception $e) {
        jsonError('Sesión inválida o expirada: ' . $e->getMessage(), 401);
    }
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
function adminFinanzas(): void {
    checkAdmin(); $db=getDB();
    $resumen = $db->query("SELECT
        IFNULL(SUM(CASE WHEN estado_pago='pagado' THEN monto_total END),0) AS bruto_cobrado,
        IFNULL(SUM(CASE WHEN estado_pago='pagado' THEN comision END),0) AS comision_plataforma,
        IFNULL(SUM(CASE WHEN estado_pago='pagado' THEN monto_medico END),0) AS pagado_medicos,
        IFNULL(SUM(CASE WHEN estado_pago='pagado' THEN 1 ELSE 0 END),0) AS consultas_cobradas,
        IFNULL(SUM(CASE WHEN estado_pago='en_custodia' THEN monto_total END),0) AS en_custodia,
        IFNULL(SUM(CASE WHEN estado_consulta='agendada' AND inicio IS NOT NULL AND inicio>NOW() THEN monto_total END),0) AS por_cobrar,
        IFNULL(SUM(CASE WHEN estado_consulta='agendada' AND inicio IS NOT NULL AND inicio>NOW() THEN 1 ELSE 0 END),0) AS proximas_count,
        IFNULL(SUM(CASE WHEN estado_pago='reembolsado' THEN monto_total END),0) AS reembolsado,
        IFNULL(SUM(CASE WHEN estado_pago='exonerado' THEN 1 ELSE 0 END),0) AS cortesias
      FROM reservas")->fetch_assoc();
    $cc = (int)$resumen['consultas_cobradas'];
    $resumen['ticket_promedio'] = $cc>0 ? round(((float)$resumen['bruto_cobrado'])/$cc, 2) : 0;
    $porMedico = $db->query("SELECT m.id, CONCAT(m.titulo,' ',m.nombre,' ',m.apellido) AS medico,
        SUM(CASE WHEN r.estado_pago='pagado' THEN 1 ELSE 0 END) AS consultas_cobradas,
        IFNULL(SUM(CASE WHEN r.estado_pago='pagado' THEN r.monto_total END),0) AS bruto_cobrado,
        IFNULL(SUM(CASE WHEN r.estado_pago='pagado' THEN r.comision END),0) AS comision_generada,
        IFNULL(SUM(CASE WHEN r.estado_pago='pagado' THEN r.monto_medico END),0) AS neto_ganado,
        IFNULL(SUM(CASE WHEN r.estado_pago='en_custodia' THEN r.monto_total END),0) AS en_custodia,
        IFNULL(SUM(CASE WHEN r.estado_consulta='agendada' AND r.inicio IS NOT NULL AND r.inicio>NOW() THEN r.monto_total END),0) AS por_cobrar
      FROM medicos m LEFT JOIN reservas r ON r.medico_id=m.id
      GROUP BY m.id HAVING (consultas_cobradas>0 OR en_custodia>0 OR por_cobrar>0)
      ORDER BY neto_ganado DESC")->fetch_all(MYSQLI_ASSOC);
    $porMes = $db->query("SELECT DATE_FORMAT(confirmada_en,'%Y-%m') AS mes,
        IFNULL(SUM(monto_total),0) AS bruto, IFNULL(SUM(comision),0) AS comision, IFNULL(SUM(monto_medico),0) AS neto
      FROM reservas
      WHERE estado_pago='pagado' AND confirmada_en IS NOT NULL AND confirmada_en>=DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
      GROUP BY mes ORDER BY mes DESC")->fetch_all(MYSQLI_ASSOC);
    jsonOk(['resumen'=>$resumen, 'por_medico'=>$porMedico, 'por_mes'=>$porMes]);
}

// ── PORTAL MÉDICO ─────────────────────────────────────────────────────────────
function medicoLogin(): void {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['email']) || empty($data['password'])) jsonError('Email y password requeridos');
    $row = fetchOne(query('SELECT id,nombre,apellido,titulo,email,password_hash,estado,foto_perfil FROM medicos WHERE email=?', 's', [strtolower(trim($data['email']))]));
    if (!$row || !password_verify($data['password'], $row['password_hash']))
        jsonError('Credenciales incorrectas', 401);
    $token = jwtEncode(['role' => 'medico', 'sub' => (int)$row['id']]);
    unset($row['password_hash']);
    jsonOk(['token' => $token, 'medico' => $row, 'expira_en' => defined('JWT_EXP_SECONDS') ? (int)JWT_EXP_SECONDS : 28800]);
}
function checkMedico(): int {
    $token = $_SERVER['HTTP_X_MEDICO_TOKEN'] ?? '';
    if (!$token) jsonError('No autorizado', 401);
    try {
        $claims = jwtDecode($token);
        if (($claims['role'] ?? '') !== 'medico' || empty($claims['sub']))
            throw new Exception('Rol o sub ausente');
        $id = (int)$claims['sub'];
        $row = fetchOne(query('SELECT id FROM medicos WHERE id=?', 'i', [$id]));
        if (!$row) throw new Exception('Médico no existe');
        return $id;
    } catch (Exception $e) {
        jsonError('Sesión inválida o expirada: ' . $e->getMessage(), 401);
        return 0; // unreachable
    }
}
function medicoPerfil(): void {
    $medicoId=checkMedico(); ensureEmergenciaColumn(); $db=getDB();
    $stmt=$db->prepare('SELECT m.id,m.titulo,m.nombre,m.apellido,m.email,m.telefono,m.ciudad,m.genero,m.licencia,m.estado,m.foto_perfil,m.disponible_emergencia,m.creado_en,e.especialidad,e.subespecialidad,e.anos_experiencia,e.idiomas,e.universidad,e.postgrado,e.educacion,e.especialidades,e.idiomas_lista,e.experiencia,e.biografia,p.tarifa,p.duracion_minutos,p.banco,p.tipo_cuenta,p.numero_cuenta,p.cedula_titular,p.nombre_titular,p.plan_liquidacion,p.frecuencia_pago FROM medicos m LEFT JOIN medico_especialidad e ON e.medico_id=m.id LEFT JOIN medico_pago p ON p.medico_id=m.id WHERE m.id=?');
    $stmt->bind_param('i',$medicoId); $stmt->execute(); $perfil=fetchOne($stmt);
    if ($perfil) {
        $perfil['educacion']      = json_decode($perfil['educacion'] ?: '[]', true) ?: [];
        $perfil['especialidades'] = json_decode($perfil['especialidades'] ?: '[]', true) ?: [];
        $perfil['idiomas_lista']  = json_decode($perfil['idiomas_lista'] ?: '[]', true) ?: [];
        $perfil['experiencia']    = json_decode($perfil['experiencia'] ?: '[]', true) ?: [];
    }
    $stmt2=$db->prepare('SELECT dia_semana,hora FROM medico_disponibilidad WHERE medico_id=? AND activo=1 ORDER BY FIELD(dia_semana,"Lunes","Martes","Miércoles","Jueves","Viernes","Sábado","Domingo"),hora');
    $stmt2->bind_param('i',$medicoId); $stmt2->execute();
    $perfil['disponibilidad']=$stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt3=$db->prepare('SELECT r.id,r.paciente_id,r.horario,r.motivo,r.monto_medico,r.estado_pago,r.estado_consulta,r.limite_confirmacion,r.notas_cancelacion,r.sala_video,p.nombre AS paciente,p.email AS email_paciente FROM reservas r JOIN pacientes p ON p.id=r.paciente_id WHERE r.medico_id=? ORDER BY r.horario DESC');
    $stmt3->bind_param('i',$medicoId); $stmt3->execute();
    $perfil['reservas']=$stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    $perfil['total_reservas']=count($perfil['reservas']);
    jsonOk($perfil);
}
function medicoActualizar(): void {
    $medicoId=checkMedico(); $data=json_decode(file_get_contents('php://input'),true);
    $db=getDB(); $db->begin_transaction();
    // COALESCE(?, col): si el campo no viene en el payload (null), se conserva el valor actual
    // (evita nulificar columnas NOT NULL y no borra datos que el form del portal no envía).
    $v_nom=$data['nombre']??null; $v_ape=$data['apellido']??null; $v_tel=$data['telefono']??null; $v_ciu=$data['ciudad']??null; $v_gen=$data['genero']??null;
    $stmt=$db->prepare('UPDATE medicos SET nombre=COALESCE(?,nombre),apellido=COALESCE(?,apellido),telefono=COALESCE(?,telefono),ciudad=COALESCE(?,ciudad),genero=COALESCE(?,genero) WHERE id=?');
    $stmt->bind_param('sssssi',$v_nom,$v_ape,$v_tel,$v_ciu,$v_gen,$medicoId); $stmt->execute();
    $v_esp=$data['especialidad']??null; $v_sub=$data['subespecialidad']??null; $v_ann=$data['anos_experiencia']??null; $v_idi=$data['idiomas']??null; $v_uni=$data['universidad']??null; $v_pos=$data['postgrado']??null; $v_bio=$data['biografia']??null;
    $stmt=$db->prepare('UPDATE medico_especialidad SET especialidad=COALESCE(?,especialidad),subespecialidad=COALESCE(?,subespecialidad),anos_experiencia=COALESCE(?,anos_experiencia),idiomas=COALESCE(?,idiomas),universidad=COALESCE(?,universidad),postgrado=COALESCE(?,postgrado),biografia=COALESCE(?,biografia) WHERE medico_id=?');
    $stmt->bind_param('sssssssi',$v_esp,$v_sub,$v_ann,$v_idi,$v_uni,$v_pos,$v_bio,$medicoId); $stmt->execute();
    $v_tar = (isset($data['tarifa']) && $data['tarifa']!=='') ? (float)$data['tarifa'] : null;
    $v_dur = (isset($data['duracion_minutos']) && $data['duracion_minutos']!=='') ? (int)$data['duracion_minutos'] : null;
    $v_ban=$data['banco']??null; $v_tip=$data['tipo_cuenta']??null; $v_num=$data['numero_cuenta']??null; $v_ced=$data['cedula_titular']??null; $v_tit=$data['nombre_titular']??null;
    $stmt=$db->prepare('UPDATE medico_pago SET tarifa=COALESCE(?,tarifa),duracion_minutos=COALESCE(?,duracion_minutos),banco=COALESCE(?,banco),tipo_cuenta=COALESCE(?,tipo_cuenta),numero_cuenta=COALESCE(?,numero_cuenta),cedula_titular=COALESCE(?,cedula_titular),nombre_titular=COALESCE(?,nombre_titular) WHERE medico_id=?');
    $stmt->bind_param('disssssi',$v_tar,$v_dur,$v_ban,$v_tip,$v_num,$v_ced,$v_tit,$medicoId); $stmt->execute();
    $fotoAviso=null;
    if(!empty($data['foto_base64'])){try{$fp=guardarFotoBase64($data['foto_base64'],'update_'.$medicoId);if($fp){$st=$db->prepare('UPDATE medicos SET foto_perfil=? WHERE id=?');$st->bind_param('si',$fp,$medicoId);$st->execute();}else{$fotoAviso='No se pudo guardar la foto (permisos del servidor de archivos). El resto del perfil sí se actualizó.';}}catch(Exception $e){$fotoAviso='No se pudo guardar la foto: '.$e->getMessage();}}
    if(isset($data['disponibilidad'])&&is_array($data['disponibilidad'])){
        $db->query("DELETE FROM medico_disponibilidad WHERE medico_id=$medicoId");
        $stmt=$db->prepare('INSERT INTO medico_disponibilidad (medico_id,dia_semana,hora) VALUES (?,?,?)');
        foreach($data['disponibilidad'] as $slot) if(!empty($slot['dia'])&&!empty($slot['hora'])){$stmt->bind_param('iss',$medicoId,$slot['dia'],$slot['hora']);$stmt->execute();}
    }
    if (isset($data['educacion']) && is_array($data['educacion'])) {
        $edu = [];
        foreach ($data['educacion'] as $e) {
            if (!is_array($e)) continue;
            $tipo=trim((string)($e['tipo']??'')); $inst=trim((string)($e['institucion']??''));
            $tit=trim((string)($e['titulo']??'')); $anio=trim((string)($e['anio']??''));
            if ($inst==='' && $tit==='' && $anio==='') continue;
            $edu[] = ['tipo'=>mb_substr($tipo,0,60),'institucion'=>mb_substr($inst,0,120),'titulo'=>mb_substr($tit,0,120),'anio'=>mb_substr($anio,0,10)];
        }
        $eduJson = json_encode($edu, JSON_UNESCAPED_UNICODE);
        $ste = $db->prepare('UPDATE medico_especialidad SET educacion=? WHERE medico_id=?');
        $ste->bind_param('si',$eduJson,$medicoId); $ste->execute();
    }
    if (isset($data['especialidades']) && is_array($data['especialidades'])) {
        $esp=[]; foreach($data['especialidades'] as $s){ $s=trim((string)$s); if($s!=='') $esp[]=mb_substr($s,0,80); }
        $esp=array_values($esp);
        $ej=json_encode($esp,JSON_UNESCAPED_UNICODE);
        $s1=$db->prepare('UPDATE medico_especialidad SET especialidades=? WHERE medico_id=?'); $s1->bind_param('si',$ej,$medicoId); $s1->execute();
        if (!empty($esp)) { $prin=$esp[0]; $s2=$db->prepare('UPDATE medico_especialidad SET especialidad=? WHERE medico_id=?'); $s2->bind_param('si',$prin,$medicoId); $s2->execute(); }
    }
    if (isset($data['idiomas_lista']) && is_array($data['idiomas_lista'])) {
        $idi=[]; foreach($data['idiomas_lista'] as $s){ $s=trim((string)$s); if($s!=='') $idi[]=mb_substr($s,0,40); }
        $idi=array_values($idi); $ij=json_encode($idi,JSON_UNESCAPED_UNICODE); $istr=implode(', ',$idi);
        $s3=$db->prepare('UPDATE medico_especialidad SET idiomas_lista=?, idiomas=? WHERE medico_id=?'); $s3->bind_param('ssi',$ij,$istr,$medicoId); $s3->execute();
    }
    if (isset($data['experiencia']) && is_array($data['experiencia'])) {
        $exp=[]; foreach($data['experiencia'] as $e){ if(!is_array($e))continue; $c=trim((string)($e['cargo']??'')); $in=trim((string)($e['institucion']??'')); $p=trim((string)($e['periodo']??'')); if($c===''&&$in===''&&$p==='')continue; $exp[]=['cargo'=>mb_substr($c,0,100),'institucion'=>mb_substr($in,0,120),'periodo'=>mb_substr($p,0,40)]; }
        $xj=json_encode($exp,JSON_UNESCAPED_UNICODE);
        $s4=$db->prepare('UPDATE medico_especialidad SET experiencia=? WHERE medico_id=?'); $s4->bind_param('si',$xj,$medicoId); $s4->execute();
    }
    $db->commit(); jsonOk(['mensaje'=>'Perfil actualizado correctamente','foto_aviso'=>$fotoAviso]);
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

// ── CÓDIGOS DE CORTESÍA ───────────────────────────────────────────────────────
function generarCodigoUnico(mysqli $db): string {
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O/1/I
    for ($intento = 0; $intento < 12; $intento++) {
        $s = '';
        for ($i = 0; $i < 6; $i++) $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        $codigo = 'CORT-' . $s;
        if (!fetchOne(query('SELECT id FROM medico_codigos WHERE codigo=?', 's', [$codigo]))) return $codigo;
    }
    throw new Exception('No se pudo generar un código único, reintenta.');
}

function medicoCodigos(): void {
    $medicoId = checkMedico(); $db = getDB();
    $stmt = $db->prepare('SELECT id,codigo,nota,usos_max,usos_count,estado,expira_en,creado_en FROM medico_codigos WHERE medico_id=? ORDER BY creado_en DESC');
    $stmt->bind_param('i', $medicoId); $stmt->execute();
    $codigos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($codigos as &$c) {
        $s2 = $db->prepare('SELECT paciente_email,usado_en FROM codigo_usos WHERE codigo_id=? ORDER BY usado_en DESC');
        $s2->bind_param('i', $c['id']); $s2->execute();
        $c['canjes'] = $s2->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    jsonOk($codigos);
}

function medicoCodigoCrear(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $usosMax = (int)($data['usos_max'] ?? 1);
    if ($usosMax < 1 || $usosMax > 100) jsonError('El número de usos debe estar entre 1 y 100');
    $nota = trim((string)($data['nota'] ?? '')); if (mb_strlen($nota) > 150) $nota = mb_substr($nota, 0, 150);
    $notaParam = $nota !== '' ? $nota : null;
    $expira = null;
    if (!empty($data['expira_en'])) {
        $d = (string)$data['expira_en'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) jsonError('Fecha de vencimiento inválida');
        if ($d < date('Y-m-d')) jsonError('El vencimiento debe ser una fecha futura');
        $expira = $d;
    }
    $db = getDB();
    $codigo = generarCodigoUnico($db);
    $stmt = $db->prepare('INSERT INTO medico_codigos (medico_id,codigo,nota,usos_max,expira_en) VALUES (?,?,?,?,?)');
    $stmt->bind_param('issis', $medicoId, $codigo, $notaParam, $usosMax, $expira);
    if (!$stmt->execute()) jsonError('No se pudo crear el código: ' . $stmt->error);
    jsonOk(['id' => (int)$db->insert_id, 'codigo' => $codigo, 'usos_max' => $usosMax, 'nota' => $notaParam, 'expira_en' => $expira]);
}

function medicoCodigoRevocar(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['codigo_id'] ?? 0);
    if (!$id) jsonError('Falta codigo_id');
    $db = getDB();
    $stmt = $db->prepare("UPDATE medico_codigos SET estado='revocado' WHERE id=? AND medico_id=? AND estado<>'revocado'");
    $stmt->bind_param('ii', $id, $medicoId); $stmt->execute();
    if ($db->affected_rows < 1) jsonError('Código no encontrado o ya revocado');
    jsonOk(['mensaje' => 'Código revocado']);
}

function medicoCodigoEliminar(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)($data['codigo_id'] ?? 0);
    if (!$id) jsonError('Falta codigo_id');
    $db = getDB();
    // DELETE cascada: borra también las filas de codigo_usos (FK ON DELETE CASCADE).
    // Las reservas ya creadas NO se tocan (no dependen de esta tabla).
    $stmt = $db->prepare('DELETE FROM medico_codigos WHERE id=? AND medico_id=?');
    $stmt->bind_param('ii', $id, $medicoId); $stmt->execute();
    if ($db->affected_rows < 1) jsonError('Código no encontrado');
    jsonOk(['mensaje' => 'Código eliminado']);
}

function validarCodigo(): void {
    $data = json_decode(file_get_contents('php://input'), true);
    $medicoId = (int)($data['medico_id'] ?? 0);
    $codigo   = strtoupper(trim((string)($data['codigo'] ?? '')));
    if (!$medicoId || $codigo === '') jsonError('Faltan datos');
    $row = fetchOne(query('SELECT usos_max,usos_count,estado,expira_en FROM medico_codigos WHERE codigo=? AND medico_id=?', 'si', [$codigo, $medicoId]));
    if (!$row)                          { jsonOk(['valido' => false, 'motivo' => 'Código no válido para este médico']); return; }
    if ($row['estado'] === 'revocado')  { jsonOk(['valido' => false, 'motivo' => 'Código revocado']); return; }
    if ($row['estado'] === 'agotado' || (int)$row['usos_count'] >= (int)$row['usos_max']) { jsonOk(['valido' => false, 'motivo' => 'Código agotado']); return; }
    if ($row['expira_en'] && $row['expira_en'] < date('Y-m-d')) { jsonOk(['valido' => false, 'motivo' => 'Código vencido']); return; }
    jsonOk(['valido' => true, 'restantes' => (int)$row['usos_max'] - (int)$row['usos_count']]);
}

// ── PACIENTE: AUTH ────────────────────────────────────────────────────────────
function checkPaciente(): int {
    $token = $_SERVER['HTTP_X_PACIENTE_TOKEN'] ?? '';
    if (!$token) jsonError('No autorizado', 401);
    try {
        $claims = jwtDecode($token);
        if (($claims['role'] ?? '') !== 'paciente' || empty($claims['sub'])) throw new Exception('Rol o sub ausente');
        $id = (int)$claims['sub'];
        if (!fetchOne(query('SELECT id FROM pacientes WHERE id=?', 'i', [$id]))) throw new Exception('Paciente no existe');
        return $id;
    } catch (Exception $e) {
        jsonError('Sesión inválida o expirada: ' . $e->getMessage(), 401);
        return 0;
    }
}

function upsertHistorial(mysqli $db, int $pid, array $data): void {
    $fuma    = in_array($data['fuma'] ?? '', ['No','Sí','Ex-fumador'], true) ? $data['fuma'] : 'No';
    $alcohol = in_array($data['alcohol'] ?? '', ['No','Ocasional','Frecuente'], true) ? $data['alcohol'] : 'No';
    $peso    = (isset($data['peso']) && $data['peso'] !== '') ? (float)$data['peso'] : null;
    $est     = (isset($data['estatura']) && $data['estatura'] !== '') ? (int)$data['estatura'] : null;
    $ts = (string)($data['tipo_sangre'] ?? ''); $al = (string)($data['alergias'] ?? '');
    $ec = (string)($data['enfermedades_cronicas'] ?? ''); $ma = (string)($data['medicamentos_actuales'] ?? '');
    $cp = (string)($data['cirugias_previas'] ?? ''); $af = (string)($data['antecedentes_familiares'] ?? '');
    $st = $db->prepare('INSERT INTO paciente_historial (paciente_id,tipo_sangre,alergias,enfermedades_cronicas,medicamentos_actuales,cirugias_previas,fuma,alcohol,peso,estatura,antecedentes_familiares) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE tipo_sangre=VALUES(tipo_sangre),alergias=VALUES(alergias),enfermedades_cronicas=VALUES(enfermedades_cronicas),medicamentos_actuales=VALUES(medicamentos_actuales),cirugias_previas=VALUES(cirugias_previas),fuma=VALUES(fuma),alcohol=VALUES(alcohol),peso=VALUES(peso),estatura=VALUES(estatura),antecedentes_familiares=VALUES(antecedentes_familiares)');
    $st->bind_param('isssssssdis', $pid, $ts, $al, $ec, $ma, $cp, $fuma, $alcohol, $peso, $est, $af);
    $st->execute();
}

function pacienteRegistro(): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $nombre = trim(trim((string)($data['nombres'] ?? $data['nombre'] ?? '')) . ' ' . trim((string)($data['apellidos'] ?? '')));
    $email  = strtolower(trim((string)($data['email'] ?? '')));
    $pass   = (string)($data['password'] ?? '');
    if ($nombre === '' || $email === '') throw new Exception('Nombre y correo requeridos.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Correo no válido.');
    if (strlen($pass) < 8) throw new Exception('La contraseña debe tener mínimo 8 caracteres.');
    $db = getDB();
    $existe = fetchOne(query('SELECT id,password_hash FROM pacientes WHERE email=?', 's', [$email]));
    if ($existe && !empty($existe['password_hash'])) throw new Exception('Ya existe una cuenta con ese correo.');
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $tel = (string)($data['telefono'] ?? ''); $ced = (string)($data['cedula'] ?? '');
    $fn  = !empty($data['fecha_nacimiento']) ? (string)$data['fecha_nacimiento'] : null;
    $gen = (string)($data['genero'] ?? ''); $ciu = (string)($data['ciudad'] ?? '');
    $db->begin_transaction();
    if ($existe) {
        $pacienteId = (int)$existe['id'];
        $st = $db->prepare('UPDATE pacientes SET nombre=?,telefono=?,cedula=?,fecha_nacimiento=?,genero=?,ciudad=?,password_hash=? WHERE id=?');
        $st->bind_param('sssssssi', $nombre, $tel, $ced, $fn, $gen, $ciu, $hash, $pacienteId);
        if (!$st->execute()) { $db->rollback(); throw new Exception('No se pudo actualizar: '.$st->error); }
    } else {
        $st = $db->prepare('INSERT INTO pacientes (nombre,email,telefono,cedula,fecha_nacimiento,genero,ciudad,password_hash) VALUES (?,?,?,?,?,?,?,?)');
        $st->bind_param('ssssssss', $nombre, $email, $tel, $ced, $fn, $gen, $ciu, $hash);
        if (!$st->execute()) { $db->rollback(); throw new Exception('No se pudo registrar: '.$st->error); }
        $pacienteId = (int)$db->insert_id;
    }
    upsertHistorial($db, $pacienteId, $data);
    $db->commit();
    $token = jwtEncode(['role' => 'paciente', 'sub' => $pacienteId]);
    jsonOk(['token' => $token, 'paciente' => ['id' => $pacienteId, 'nombre' => $nombre, 'email' => $email], 'expira_en' => defined('JWT_EXP_SECONDS') ? (int)JWT_EXP_SECONDS : 28800]);
}

function pacienteLogin(): void {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['email']) || empty($data['password'])) jsonError('Email y password requeridos');
    $row = fetchOne(query('SELECT id,nombre,email,password_hash,estado FROM pacientes WHERE email=?', 's', [strtolower(trim($data['email']))]));
    if (!$row || empty($row['password_hash']) || !password_verify($data['password'], $row['password_hash'])) jsonError('Credenciales incorrectas', 401);
    if (($row['estado'] ?? 'activo') === 'inactivo') jsonError('Cuenta inactiva', 403);
    $token = jwtEncode(['role' => 'paciente', 'sub' => (int)$row['id']]);
    jsonOk(['token' => $token, 'paciente' => ['id' => (int)$row['id'], 'nombre' => $row['nombre'], 'email' => $row['email']], 'expira_en' => defined('JWT_EXP_SECONDS') ? (int)JWT_EXP_SECONDS : 28800]);
}

function pacienteRecuperar(): void {
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['email'])) jsonError('Email requerido');
    $row = fetchOne(query('SELECT id FROM pacientes WHERE email=?', 's', [strtolower(trim($data['email']))]));
    if (!$row) { jsonOk(['mensaje' => 'Si el correo existe recibirás instrucciones']); return; }
    $temp = 'Pac' . rand(1000, 9999) . '!'; $hash = password_hash($temp, PASSWORD_BCRYPT);
    $db = getDB(); $st = $db->prepare('UPDATE pacientes SET password_hash=? WHERE id=?'); $st->bind_param('si', $hash, $row['id']); $st->execute();
    jsonOk(['mensaje' => 'Password temporal generado', 'password_temp' => $temp]);
}

// ── PACIENTE: PERFIL E HISTORIAL ──────────────────────────────────────────────
function pacientePerfil(): void {
    $pid = checkPaciente(); $db = getDB();
    $perfil = fetchOne(query('SELECT id,nombre,email,telefono,cedula,fecha_nacimiento,genero,ciudad,creado_en FROM pacientes WHERE id=?', 'i', [$pid]));
    $perfil['historial'] = fetchOne(query('SELECT tipo_sangre,alergias,enfermedades_cronicas,medicamentos_actuales,cirugias_previas,fuma,alcohol,peso,estatura,antecedentes_familiares,actualizado_en FROM paciente_historial WHERE paciente_id=?', 'i', [$pid]));
    $st = $db->prepare('SELECT r.id,r.inicio,r.medico_id,r.horario,r.motivo,r.estado_pago,r.estado_consulta,r.sala_video,r.token_acceso,r.creado_en, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico, e.especialidad, cn.diagnostico, cn.indicaciones, cn.notas FROM reservas r JOIN medicos m ON m.id=r.medico_id LEFT JOIN medico_especialidad e ON e.medico_id=m.id LEFT JOIN consulta_notas cn ON cn.reserva_id=r.id WHERE r.paciente_id=? ORDER BY r.creado_en DESC');
    $st->bind_param('i', $pid); $st->execute();
    $perfil['reservas'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $stT = $db->prepare('SELECT id,medicamento,dosis,frecuencia,via,duracion,fecha_inicio,estado,resultado,nota_cierre,fecha_cierre FROM tratamientos WHERE paciente_id=? ORDER BY creado_en DESC');
    $stT->bind_param('i', $pid); $stT->execute();
    $perfil['tratamientos'] = $stT->get_result()->fetch_all(MYSQLI_ASSOC);
    $stR = $db->prepare('SELECT id,fecha_emision,diagnostico,items,creado_en FROM recetas WHERE paciente_id=? ORDER BY creado_en DESC');
    $stR->bind_param('i',$pid); $stR->execute();
    $recsP = $stR->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($recsP as &$rp) { $arr=json_decode($rp['items']?:'[]',true); $rp['num_items']=is_array($arr)?count($arr):0; unset($rp['items']); $rp['folio']=recetaFolio($rp['id']); }
    unset($rp);
    $perfil['recetas'] = $recsP;
    jsonOk($perfil);
}

function pacienteActualizar(): void {
    $pid = checkPaciente(); $data = json_decode(file_get_contents('php://input'), true);
    $tel = (string)($data['telefono'] ?? ''); $gen = (string)($data['genero'] ?? ''); $ciu = (string)($data['ciudad'] ?? '');
    $fn  = !empty($data['fecha_nacimiento']) ? (string)$data['fecha_nacimiento'] : null;
    $db = getDB(); $st = $db->prepare('UPDATE pacientes SET telefono=?,genero=?,ciudad=?,fecha_nacimiento=? WHERE id=?');
    $st->bind_param('ssssi', $tel, $gen, $ciu, $fn, $pid); $st->execute();
    jsonOk(['mensaje' => 'Perfil actualizado']);
}

function pacienteHistorialActualizar(): void {
    $pid = checkPaciente(); $data = json_decode(file_get_contents('php://input'), true);
    upsertHistorial(getDB(), $pid, $data);
    jsonOk(['mensaje' => 'Historial actualizado']);
}

// ── MÉDICO: HISTORIAL Y NOTAS DEL PACIENTE ────────────────────────────────────
function medicoVerHistorialPaciente(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $pid = (int)($data['paciente_id'] ?? 0);
    if (!$pid) jsonError('Falta paciente_id');
    if (!fetchOne(query('SELECT id FROM reservas WHERE medico_id=? AND paciente_id=? LIMIT 1', 'ii', [$medicoId, $pid])))
        jsonError('No tienes citas con este paciente', 403);
    $db = getDB();
    $perfil = fetchOne(query('SELECT id,nombre,email,telefono,fecha_nacimiento,genero,ciudad FROM pacientes WHERE id=?', 'i', [$pid]));
    $perfil['historial'] = fetchOne(query('SELECT tipo_sangre,alergias,enfermedades_cronicas,medicamentos_actuales,cirugias_previas,fuma,alcohol,peso,estatura,antecedentes_familiares,actualizado_en FROM paciente_historial WHERE paciente_id=?', 'i', [$pid]));
    $st = $db->prepare('SELECT r.id,r.horario,r.creado_en, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico, cn.diagnostico,cn.indicaciones,cn.notas,cn.actualizado_en FROM consulta_notas cn JOIN reservas r ON r.id=cn.reserva_id JOIN medicos m ON m.id=cn.medico_id WHERE cn.paciente_id=? ORDER BY cn.creado_en DESC');
    $st->bind_param('i', $pid); $st->execute();
    $perfil['notas_previas'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    jsonOk($perfil);
}

function medicoGuardarNota(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $rid = (int)($data['reserva_id'] ?? 0);
    if (!$rid) jsonError('Falta reserva_id');
    $res = fetchOne(query('SELECT id,paciente_id FROM reservas WHERE id=? AND medico_id=?', 'ii', [$rid, $medicoId]));
    if (!$res) jsonError('Reserva no encontrada', 404);
    $pid = (int)$res['paciente_id'];
    $diag = (string)($data['diagnostico'] ?? ''); $ind = (string)($data['indicaciones'] ?? ''); $not = (string)($data['notas'] ?? '');
    $db = getDB();
    $cie = (string)($data['cie10'] ?? ''); $plan = (string)($data['plan'] ?? '');
    $pc  = !empty($data['proximo_control']) ? (string)$data['proximo_control'] : null;
    $st = $db->prepare('INSERT INTO consulta_notas (reserva_id,medico_id,paciente_id,diagnostico,indicaciones,notas,cie10,plan,proximo_control) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE diagnostico=VALUES(diagnostico),indicaciones=VALUES(indicaciones),notas=VALUES(notas),cie10=VALUES(cie10),plan=VALUES(plan),proximo_control=VALUES(proximo_control)');
    $st->bind_param('iiissssss', $rid, $medicoId, $pid, $diag, $ind, $not, $cie, $plan, $pc); $st->execute();
    jsonOk(['mensaje' => 'Nota clínica guardada']);
}

// ── EXPEDIENTE CLÍNICO (MÉDICO) ───────────────────────────────────────────────
function checkRelacionMedicoPaciente(int $medicoId, int $pid): void {
    if (!fetchOne(query('SELECT id FROM reservas WHERE medico_id=? AND paciente_id=? LIMIT 1', 'ii', [$medicoId, $pid])))
        jsonError('No tienes citas con este paciente', 403);
}

function medicoPacientes(): void {
    $medicoId = checkMedico(); $db = getDB();
    $st = $db->prepare('SELECT p.id,p.nombre,p.email,p.telefono,p.cedula,p.fecha_nacimiento,p.genero, MAX(r.creado_en) AS ultima_cita, COUNT(r.id) AS num_citas FROM pacientes p JOIN reservas r ON r.paciente_id=p.id WHERE r.medico_id=? GROUP BY p.id ORDER BY ultima_cita DESC');
    $st->bind_param('i', $medicoId); $st->execute();
    jsonOk($st->get_result()->fetch_all(MYSQLI_ASSOC));
}

function guardarDocumentoBase64(string $base64, string $mime): ?string {
    $extMap = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (preg_match('#^data:([^;]+);base64,#', $base64, $m)) { $mime = $m[1]; $base64 = substr($base64, strpos($base64, ',') + 1); }
    if (!isset($extMap[$mime])) return null;
    $bin = base64_decode($base64, true);
    if ($bin === false || strlen($bin) < 1 || strlen($bin) > 8 * 1024 * 1024) return null;
    $dir = rtrim(dirname(rtrim(UPLOAD_DIR, '/')), '/') . '/documentos/';
    $url = rtrim(dirname(rtrim(UPLOAD_URL, '/')), '/') . '/documentos/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_writable($dir)) return null;
    $filename = bin2hex(random_bytes(16)) . '.' . $extMap[$mime];
    return file_put_contents($dir . $filename, $bin) !== false ? $url . $filename : null;
}

function medicoExpediente(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $pid = (int)($data['paciente_id'] ?? 0);
    if (!$pid) jsonError('Falta paciente_id');
    checkRelacionMedicoPaciente($medicoId, $pid);
    $db = getDB(); $out = [];
    $out['paciente'] = fetchOne(query('SELECT id,nombre,email,telefono,cedula,fecha_nacimiento,genero,ciudad FROM pacientes WHERE id=?', 'i', [$pid]));
    $out['historial'] = fetchOne(query('SELECT tipo_sangre,alergias,enfermedades_cronicas,medicamentos_actuales,cirugias_previas,fuma,alcohol,peso,estatura,antecedentes_familiares,actualizado_en FROM paciente_historial WHERE paciente_id=?', 'i', [$pid]));
    $st = $db->prepare('SELECT cn.reserva_id,r.horario,cn.creado_en,cn.diagnostico,cn.cie10,cn.indicaciones,cn.plan,cn.proximo_control,cn.notas,cn.actualizado_en, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico FROM consulta_notas cn JOIN reservas r ON r.id=cn.reserva_id JOIN medicos m ON m.id=cn.medico_id WHERE cn.paciente_id=? ORDER BY cn.creado_en DESC');
    $st->bind_param('i',$pid); $st->execute(); $out['consultas']=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st = $db->prepare('SELECT t.id,t.medicamento,t.dosis,t.frecuencia,t.via,t.duracion,t.fecha_inicio,t.estado,t.resultado,t.nota_cierre,t.fecha_cierre,t.creado_en, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico FROM tratamientos t JOIN medicos m ON m.id=t.medico_id WHERE t.paciente_id=? ORDER BY t.creado_en DESC');
    $st->bind_param('i',$pid); $st->execute(); $out['tratamientos']=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st = $db->prepare('SELECT id,presion_sistolica,presion_diastolica,frecuencia_cardiaca,frecuencia_respiratoria,saturacion_o2,temperatura,peso,estatura,glucosa,registrado_en FROM signos_vitales WHERE paciente_id=? ORDER BY registrado_en DESC');
    $st->bind_param('i',$pid); $st->execute(); $out['vitales']=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st = $db->prepare('SELECT id,tipo,titulo,archivo,mime,observaciones,creado_en FROM documentos WHERE paciente_id=? ORDER BY creado_en DESC');
    $st->bind_param('i',$pid); $st->execute(); $out['documentos']=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st = $db->prepare('SELECT id,horario,estado_consulta,creado_en FROM reservas WHERE paciente_id=? AND medico_id=? ORDER BY creado_en DESC');
    $st->bind_param('ii',$pid,$medicoId); $st->execute(); $out['reservas']=$st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st = $db->prepare('SELECT id,fecha_emision,diagnostico,items,creado_en FROM recetas WHERE paciente_id=? ORDER BY creado_en DESC');
    $st->bind_param('i',$pid); $st->execute();
    $recs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    foreach ($recs as &$r) { $arr=json_decode($r['items']?:'[]',true); $r['num_items']=is_array($arr)?count($arr):0; unset($r['items']); $r['folio']=recetaFolio($r['id']); }
    unset($r);
    $out['recetas'] = $recs;
    jsonOk($out);
}

function medicoTratamientoCrear(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $pid = (int)($data['paciente_id'] ?? 0); if (!$pid) jsonError('Falta paciente_id');
    checkRelacionMedicoPaciente($medicoId, $pid);
    $med = trim((string)($data['medicamento'] ?? '')); if ($med === '') jsonError('Falta el medicamento');
    $rid = !empty($data['reserva_id']) ? (int)$data['reserva_id'] : null;
    $dosis=(string)($data['dosis']??''); $frec=(string)($data['frecuencia']??''); $via=(string)($data['via']??''); $dur=(string)($data['duracion']??'');
    $fi = !empty($data['fecha_inicio']) ? (string)$data['fecha_inicio'] : null;
    $db = getDB();
    $st = $db->prepare('INSERT INTO tratamientos (paciente_id,medico_id,reserva_id,medicamento,dosis,frecuencia,via,duracion,fecha_inicio) VALUES (?,?,?,?,?,?,?,?,?)');
    $st->bind_param('iiissssss', $pid, $medicoId, $rid, $med, $dosis, $frec, $via, $dur, $fi);
    $st->execute();
    jsonOk(['id'=>(int)$db->insert_id,'mensaje'=>'Tratamiento registrado']);
}

function medicoTratamientoActualizar(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $tid = (int)($data['tratamiento_id'] ?? 0); if (!$tid) jsonError('Falta tratamiento_id');
    $t = fetchOne(query('SELECT paciente_id FROM tratamientos WHERE id=?', 'i', [$tid]));
    if (!$t) jsonError('Tratamiento no encontrado', 404);
    checkRelacionMedicoPaciente($medicoId, (int)$t['paciente_id']);
    $estado = in_array($data['estado'] ?? '', ['activo','finalizado','suspendido'], true) ? $data['estado'] : 'activo';
    $resultado = in_array($data['resultado'] ?? '', ['pendiente','resolvio','mejoro','sin_cambio','empeoro'], true) ? $data['resultado'] : 'pendiente';
    $nota = (string)($data['nota_cierre'] ?? '');
    $fc = !empty($data['fecha_cierre']) ? (string)$data['fecha_cierre'] : (($estado==='finalizado') ? date('Y-m-d') : null);
    $db = getDB();
    $st = $db->prepare('UPDATE tratamientos SET estado=?, resultado=?, nota_cierre=?, fecha_cierre=? WHERE id=?');
    $st->bind_param('ssssi', $estado, $resultado, $nota, $fc, $tid);
    $st->execute();
    jsonOk(['mensaje'=>'Tratamiento actualizado']);
}

function medicoVitalesRegistrar(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $pid = (int)($data['paciente_id'] ?? 0); if (!$pid) jsonError('Falta paciente_id');
    checkRelacionMedicoPaciente($medicoId, $pid);
    $rid = !empty($data['reserva_id']) ? (int)$data['reserva_id'] : null;
    $iv = function($k) use ($data){ return (isset($data[$k]) && $data[$k] !== '') ? (int)$data[$k] : null; };
    $fv = function($k) use ($data){ return (isset($data[$k]) && $data[$k] !== '') ? (float)$data[$k] : null; };
    $ps=$iv('presion_sistolica'); $pd=$iv('presion_diastolica'); $fc=$iv('frecuencia_cardiaca'); $fr=$iv('frecuencia_respiratoria');
    $sat=$iv('saturacion_o2'); $temp=$fv('temperatura'); $peso=$fv('peso'); $est=$iv('estatura'); $glu=$iv('glucosa');
    $db = getDB();
    $st = $db->prepare('INSERT INTO signos_vitales (paciente_id,medico_id,reserva_id,presion_sistolica,presion_diastolica,frecuencia_cardiaca,frecuencia_respiratoria,saturacion_o2,temperatura,peso,estatura,glucosa) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->bind_param('iiiiiiiiddii', $pid, $medicoId, $rid, $ps, $pd, $fc, $fr, $sat, $temp, $peso, $est, $glu);
    $st->execute();
    jsonOk(['mensaje'=>'Signos vitales registrados']);
}

function medicoDocumentoSubir(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $pid = (int)($data['paciente_id'] ?? 0); if (!$pid) jsonError('Falta paciente_id');
    checkRelacionMedicoPaciente($medicoId, $pid);
    $b64 = (string)($data['archivo_base64'] ?? ''); if ($b64 === '') jsonError('Falta el archivo');
    $ruta = guardarDocumentoBase64($b64, (string)($data['mime'] ?? ''));
    if (!$ruta) jsonError('Archivo no válido (PDF/JPG/PNG/WEBP ≤ 8 MB)');
    $rid = !empty($data['reserva_id']) ? (int)$data['reserva_id'] : null;
    $tipo=(string)($data['tipo']??'otro'); $tit=(string)($data['titulo']??''); $mime=(string)($data['mime']??''); $obs=(string)($data['observaciones']??'');
    $tam = isset($data['tamano']) ? (int)$data['tamano'] : 0;
    $db = getDB();
    $st = $db->prepare('INSERT INTO documentos (paciente_id,medico_id,reserva_id,tipo,titulo,archivo,mime,tamano,observaciones) VALUES (?,?,?,?,?,?,?,?,?)');
    $st->bind_param('iiissssis', $pid, $medicoId, $rid, $tipo, $tit, $ruta, $mime, $tam, $obs);
    $st->execute();
    jsonOk(['id'=>(int)$db->insert_id,'archivo'=>$ruta,'mensaje'=>'Documento subido']);
}

function medicoDocumentoEliminar(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $did = (int)($data['documento_id'] ?? 0); if (!$did) jsonError('Falta documento_id');
    $doc = fetchOne(query('SELECT paciente_id,archivo FROM documentos WHERE id=?', 'i', [$did]));
    if (!$doc) jsonError('Documento no encontrado', 404);
    checkRelacionMedicoPaciente($medicoId, (int)$doc['paciente_id']);
    $abs = __DIR__ . '/' . ltrim((string)$doc['archivo'], '/');
    if (is_file($abs)) @unlink($abs);
    getDB()->query('DELETE FROM documentos WHERE id=' . $did);
    jsonOk(['mensaje'=>'Documento eliminado']);
}

// ── RECETAS ───────────────────────────────────────────────────────────────────
function recetaFolio($id): string { return 'REC-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT); }

function medicoRecetaCrear(): void {
    $medicoId = checkMedico(); $data = json_decode(file_get_contents('php://input'), true);
    $pid = (int)($data['paciente_id'] ?? 0); if (!$pid) jsonError('Falta paciente_id');
    checkRelacionMedicoPaciente($medicoId, $pid);
    $items = $data['items'] ?? null;
    if (!is_array($items) || count($items) === 0) jsonError('La receta debe tener al menos un medicamento');
    $limpios = [];
    foreach ($items as $it) {
        $med = trim((string)($it['medicamento'] ?? '')); if ($med === '') continue;
        $limpios[] = [
            'medicamento'  => mb_substr($med, 0, 200),
            'dosis'        => mb_substr(trim((string)($it['dosis'] ?? '')), 0, 100),
            'frecuencia'   => mb_substr(trim((string)($it['frecuencia'] ?? '')), 0, 100),
            'duracion'     => mb_substr(trim((string)($it['duracion'] ?? '')), 0, 100),
            'indicaciones' => mb_substr(trim((string)($it['indicaciones'] ?? '')), 0, 300),
        ];
        if (count($limpios) >= 30) break;
    }
    if (!count($limpios)) jsonError('La receta debe tener al menos un medicamento');
    $rid = !empty($data['reserva_id']) ? (int)$data['reserva_id'] : null;
    $diag = mb_substr(trim((string)($data['diagnostico'] ?? '')), 0, 2000);
    $ind  = mb_substr(trim((string)($data['indicaciones'] ?? '')), 0, 2000);
    $json = json_encode($limpios, JSON_UNESCAPED_UNICODE);
    $db = getDB();
    $st = $db->prepare('INSERT INTO recetas (paciente_id,medico_id,reserva_id,diagnostico,indicaciones,items) VALUES (?,?,?,?,?,?)');
    $st->bind_param('iiisss', $pid, $medicoId, $rid, $diag, $ind, $json);
    $st->execute();
    $id = (int)$db->insert_id;
    jsonOk(['id' => $id, 'folio' => recetaFolio($id)]);
}

function recetaVer(): void {
    $data = json_decode(file_get_contents('php://input'), true);
    $rid = (int)($data['receta_id'] ?? 0); if (!$rid) jsonError('Falta receta_id');
    $rec = fetchOne(query('SELECT id,paciente_id,medico_id,diagnostico,indicaciones,items,fecha_emision FROM recetas WHERE id=?', 'i', [$rid]));
    if (!$rec) jsonError('Receta no encontrada', 404);
    if (!empty($_SERVER['HTTP_X_PACIENTE_TOKEN'])) {
        $pidTok = checkPaciente();
        if ((int)$rec['paciente_id'] !== $pidTok) jsonError('No autorizado', 403);
    } else {
        $medicoId = checkMedico();
        checkRelacionMedicoPaciente($medicoId, (int)$rec['paciente_id']);
    }
    $pac = fetchOne(query('SELECT nombre,cedula,fecha_nacimiento,genero FROM pacientes WHERE id=?', 'i', [(int)$rec['paciente_id']]));
    $med = fetchOne(query('SELECT CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS nombre_completo, m.licencia, e.especialidad FROM medicos m LEFT JOIN medico_especialidad e ON e.medico_id=m.id WHERE m.id=?', 'i', [(int)$rec['medico_id']]));
    $edad = null;
    if (!empty($pac['fecha_nacimiento'])) { try { $edad = (new DateTime($pac['fecha_nacimiento']))->diff(new DateTime('now'))->y; } catch (Exception $e) {} }
    jsonOk([
        'folio' => recetaFolio($rec['id']),
        'fecha_emision' => $rec['fecha_emision'],
        'diagnostico' => $rec['diagnostico'],
        'indicaciones' => $rec['indicaciones'],
        'items' => json_decode($rec['items'] ?: '[]', true),
        'paciente' => ['nombre'=>$pac['nombre']??'', 'cedula'=>$pac['cedula']??'', 'fecha_nacimiento'=>$pac['fecha_nacimiento']??null, 'genero'=>$pac['genero']??'', 'edad'=>$edad],
        'medico' => ['nombre_completo'=>$med['nombre_completo']??'', 'especialidad'=>$med['especialidad']??'', 'licencia'=>$med['licencia']??''],
    ]);
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
    $esExonerado = ($r['estado_pago'] === 'exonerado');
    if ($esExonerado) {
        $upd=$db->prepare('UPDATE reservas SET estado_consulta="confirmada",confirmada_en=? WHERE id=?');
        $upd->bind_param('si',$ahora,$rid);$upd->execute();
    } else {
        $upd=$db->prepare('UPDATE reservas SET estado_consulta="confirmada",estado_pago="pagado",estado_pago_medico="transferido",confirmada_en=? WHERE id=?');
        $upd->bind_param('si',$ahora,$rid);$upd->execute();
        $ins=$db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"comision",?,"Comision MedicOnline 15%")');
        $ins->bind_param('id',$rid,$com);$ins->execute();
        $ins2=$db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"liberacion",?,"Pago liberado al medico")');
        $ins2->bind_param('id',$rid,$neto);$ins2->execute();
    }
    // Email al médico — pago liberado
    $medInfo = fetchOne(query('SELECT m.nombre,m.apellido,m.titulo,m.email FROM medicos m WHERE m.id=?','i',[$medicoId]));
    $pacInfo = fetchOne(query('SELECT p.nombre, p.email FROM pacientes p JOIN reservas r ON r.paciente_id=p.id WHERE r.id=?','i',[$rid]));
    $resInfo = fetchOne(query('SELECT token_acceso FROM reservas WHERE id=?','i',[$rid]));
    if ($medInfo) {
        $nombreMedicoFull = $medInfo['titulo'].' '.$medInfo['nombre'].' '.$medInfo['apellido'];
        if (!$esExonerado) emailPagoLiberado([
            'medico_nombre' => $nombreMedicoFull,
            'email_medico'  => $medInfo['email'],
            'paciente'      => $pacInfo['nombre'] ?? 'Paciente',
            'monto_medico'  => number_format($neto, 2),
            'confirmada_en' => $ahora,
        ]);
        // Email al paciente con link para calificar
        if ($pacInfo && !empty($pacInfo['email']) && $resInfo && !empty($resInfo['token_acceso'])) {
            emailPedirResena([
                'paciente'       => $pacInfo['nombre'] ?? 'Paciente',
                'email_paciente' => $pacInfo['email'],
                'medico'         => $nombreMedicoFull,
                'reserva_id'     => $rid,
                'token_acceso'   => $resInfo['token_acceso'],
            ]);
        }
    }
    jsonOk(['mensaje'=>'Consulta confirmada. Pago liberado.','monto_recibido'=>'$'.number_format($neto,2),'confirmada_en'=>$ahora]);
}

function emailPedirResena(array $data): void {
    $link = SITE_URL . '/pacientes.html?calificar=' . urlencode($data['token_acceso']) . '&email=' . urlencode($data['email_paciente']);
    $html = <<<HTML
<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px">
<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
  <div style="background:#0D7A5F;padding:28px 32px;text-align:center">
    <h1 style="color:#fff;margin:0;font-size:22px">MedicVIP</h1>
    <p style="color:rgba(255,255,255,.85);margin:6px 0 0;font-size:14px">¿Cómo fue tu consulta?</p>
  </div>
  <div style="padding:32px;text-align:center">
    <h2 style="color:#1A1A18;font-size:18px;margin:0 0 8px">Califica tu consulta con {$data['medico']}</h2>
    <p style="color:#666;font-size:14px;margin:0 0 24px">Tu opinión ayuda a otros pacientes a elegir mejor. Toma menos de 30 segundos.</p>
    <div style="font-size:36px;letter-spacing:8px;margin:24px 0;color:#F5C842">☆ ☆ ☆ ☆ ☆</div>
    <a href="$link" style="display:inline-block;background:#0D7A5F;color:#fff;padding:14px 32px;border-radius:100px;text-decoration:none;font-size:14px;font-weight:600">Dejar mi reseña →</a>
    <p style="margin:24px 0 0;font-size:11px;color:#aaa">Reserva #{$data['reserva_id']}</p>
  </div>
  <div style="background:#f5f5f5;padding:16px 32px;text-align:center;font-size:11px;color:#aaa">MedicVIP · © 2026</div>
</div></body></html>
HTML;
    enviarEmail($data['email_paciente'], $data['paciente'], '⭐ Califica tu consulta — MedicVIP', $html);
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

function ensureEmergenciaColumn(): void {
    $db = getDB();
    $chk = $db->query("SELECT COUNT(*) AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='medicos' AND COLUMN_NAME='disponible_emergencia'");
    if ($chk && (int)$chk->fetch_assoc()['c'] === 0)
        $db->query("ALTER TABLE medicos ADD COLUMN disponible_emergencia TINYINT(1) NOT NULL DEFAULT 0");
}

function listarEmergencias(): void {
    ensureEmergenciaColumn();
    $mult = emergencyMultiplier();
    $stmt = query(
        'SELECT m.id, m.titulo, m.nombre, m.apellido, m.foto_perfil, e.especialidad, e.anos_experiencia, p.tarifa, ROUND(p.tarifa * ?, 2) AS tarifa_final
         FROM medicos m
         JOIN medico_especialidad e ON e.medico_id = m.id
         JOIN medico_pago p ON p.medico_id = m.id
         WHERE m.estado = "activo" AND m.disponible_emergencia = 1
         ORDER BY m.id DESC',
        'd', [$mult]
    );
    jsonOk(fetchAll($stmt));
}

function medicoPagos(): void {
    $medicoId = checkMedico();
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT r.id AS reserva_id, r.horario, r.confirmada_en, r.reembolsada_en,
                r.monto_total, r.comision, r.monto_medico,
                r.estado_pago, r.estado_consulta, r.estado_pago_medico,
                r.metodo_pago,
                p.nombre AS paciente, p.email AS email_paciente
         FROM reservas r
         JOIN pacientes p ON p.id = r.paciente_id
         WHERE r.medico_id = ?
         ORDER BY r.horario DESC'
    );
    $stmt->bind_param('i', $medicoId);
    $stmt->execute();
    $reservas = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Resumen agregado: total cobrado, en custodia, reembolsado, pendiente
    $totales = ['cobrado'=>0.0, 'en_custodia'=>0.0, 'reembolsado'=>0.0, 'pendiente'=>0.0, 'comision_total'=>0.0];
    foreach ($reservas as $r) {
        $neto = (float)$r['monto_medico'];
        $com  = (float)$r['comision'];
        switch ($r['estado_pago']) {
            case 'pagado':      $totales['cobrado']      += $neto; $totales['comision_total'] += $com; break;
            case 'en_custodia': $totales['en_custodia']  += $neto; break;
            case 'reembolsado': $totales['reembolsado']  += $neto; break;
            default:            $totales['pendiente']    += $neto;
        }
    }
    foreach ($totales as $k => $v) $totales[$k] = round($v, 2);

    jsonOk(['totales' => $totales, 'reservas' => $reservas]);
}

function medicoToggleEmergencia(): void {
    $medicoId = checkMedico();
    ensureEmergenciaColumn();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $db = getDB();
    if (isset($data['disponible'])) {
        $nuevo = !empty($data['disponible']) ? 1 : 0;
    } else {
        // toggle implícito
        $row = fetchOne(query('SELECT disponible_emergencia FROM medicos WHERE id=?','i',[$medicoId]));
        $nuevo = ((int)($row['disponible_emergencia'] ?? 0) === 1) ? 0 : 1;
    }
    $stmt = $db->prepare('UPDATE medicos SET disponible_emergencia=? WHERE id=?');
    $stmt->bind_param('ii', $nuevo, $medicoId);
    $stmt->execute();
    jsonOk(['disponible_emergencia' => $nuevo]);
}

function reservarEmergencia(): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    foreach (['medico_id'] as $f)
        if (empty($data[$f])) throw new Exception("Campo requerido: $f");

    $db = getDB();

    $pacienteId = checkPaciente();
    $pac = fetchOne(query('SELECT nombre,email FROM pacientes WHERE id=?','i',[$pacienteId]));
    $data['nombre_paciente'] = $pac['nombre'];
    $data['email_paciente']  = $pac['email'];

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

// ── RESEÑAS ──────────────────────────────────────────────────────────────────
function ensureResenasTable(): void {
    $db = getDB();
    $chk = $db->query("SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='resenas'");
    if ($chk && (int)$chk->fetch_assoc()['c'] === 0) {
        $db->query("CREATE TABLE resenas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reserva_id INT UNSIGNED NOT NULL UNIQUE,
            medico_id INT UNSIGNED NOT NULL,
            paciente_id INT UNSIGNED NOT NULL,
            estrellas TINYINT UNSIGNED NOT NULL,
            comentario TEXT,
            creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_medico (medico_id),
            CONSTRAINT chk_estrellas CHECK (estrellas BETWEEN 1 AND 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

function crearResena(): void {
    ensureResenasTable();
    $data  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $token = trim($data['token_acceso'] ?? '');
    $email = strtolower(trim($data['email'] ?? ''));
    $stars = (int)($data['estrellas'] ?? 0);
    $comentario = trim((string)($data['comentario'] ?? ''));
    if (!$token || !$email)              throw new Exception('Faltan token_acceso y email.');
    if ($stars < 1 || $stars > 5)        throw new Exception('Estrellas debe ser 1–5.');
    if (mb_strlen($comentario) > 2000)   throw new Exception('Comentario máximo 2000 caracteres.');

    $reserva = fetchOne(query(
        'SELECT r.id AS reserva_id, r.medico_id, r.paciente_id, r.estado_consulta
         FROM reservas r
         JOIN pacientes p ON p.id = r.paciente_id
         WHERE r.token_acceso = ? AND LOWER(p.email) = ?',
        'ss', [$token, $email]
    ));
    if (!$reserva)                                  throw new Exception('Reserva no encontrada o email no coincide.');
    if ($reserva['estado_consulta'] !== 'confirmada') throw new Exception('Solo puedes calificar consultas confirmadas.');

    $db = getDB();
    $stmt = $db->prepare('INSERT INTO resenas (reserva_id, medico_id, paciente_id, estrellas, comentario) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('iiiis', $reserva['reserva_id'], $reserva['medico_id'], $reserva['paciente_id'], $stars, $comentario);
    if (!$stmt->execute()) {
        if ($stmt->errno === 1062 || $db->errno === 1062)
            throw new Exception('Esta consulta ya fue calificada.');
        throw new Exception('Error guardando reseña: ' . $stmt->error);
    }
    jsonOk(['resena_id' => (int)$db->insert_id, 'estrellas' => $stars]);
}

function listarResenasMedico(): void {
    ensureResenasTable();
    $medicoId = (int)($_GET['medico_id'] ?? 0);
    if (!$medicoId) throw new Exception('Falta medico_id.');
    $stmt = query(
        'SELECT r.id, r.estrellas, r.comentario, r.creado_en, p.nombre AS paciente
         FROM resenas r
         JOIN pacientes p ON p.id = r.paciente_id
         WHERE r.medico_id = ?
         ORDER BY r.creado_en DESC',
        'i', [$medicoId]
    );
    $resenas = fetchAll($stmt);
    $promedio = 0.0; $count = count($resenas);
    if ($count > 0) {
        $sum = array_sum(array_map(fn($r) => (int)$r['estrellas'], $resenas));
        $promedio = round($sum / $count, 2);
    }
    // Mostrar solo primer nombre del paciente para privacidad
    foreach ($resenas as &$r) {
        $r['paciente'] = strtok($r['paciente'] ?? '', ' ');
    }
    jsonOk(['medico_id' => $medicoId, 'total' => $count, 'estrella_promedio' => $promedio, 'resenas' => $resenas]);
}

function medicoResenas(): void {
    $medicoId = checkMedico();
    ensureResenasTable();
    $stmt = query(
        'SELECT r.id, r.estrellas, r.comentario, r.creado_en, p.nombre AS paciente, res.horario
         FROM resenas r
         JOIN pacientes p ON p.id = r.paciente_id
         JOIN reservas res ON res.id = r.reserva_id
         WHERE r.medico_id = ?
         ORDER BY r.creado_en DESC',
        'i', [$medicoId]
    );
    $resenas = fetchAll($stmt);
    $promedio = 0.0; $count = count($resenas);
    if ($count > 0) {
        $sum = array_sum(array_map(fn($r) => (int)$r['estrellas'], $resenas));
        $promedio = round($sum / $count, 2);
    }
    jsonOk(['total' => $count, 'estrella_promedio' => $promedio, 'resenas' => $resenas]);
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
