# Cuentas de paciente + historial médico — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development para ejecutar tarea por tarea.

**Goal:** Cuentas de paciente (registro con historial médico + login), panel del paciente (citas + historial), agendamiento con cuenta obligatoria, y acceso del médico al historial + notas clínicas por consulta.

**Architecture:** Vanilla PHP + MariaDB + HTML/JS inline. Extiende `pacientes`, agrega `paciente_historial` (1:1) y `consulta_notas` (1:1 con reserva). Auth de paciente por JWT (`role:'paciente'`, header `X-Paciente-Token`) espejando el de médico. Dos páginas nuevas espejando las de médico.

**Tech Stack:** PHP 8.2, MariaDB 10.11 (NAS, base `mediconline`), mysqli, HTML/CSS/JS inline. Sin tests automáticos — verificación por `curl` (plink/base64) y navegador.

**Notas de entorno (NAS):** iguales al plan de cortesía —
- `plink -pw "<pass>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" pbaquerizo@192.168.0.116`, técnica base64, `sudo -S`.
- API interna: `curl -H 'Host: medicvip.org' http://127.0.0.1/api.php?action=...`.
- Ejecutar PHP en el NAS como `http`: `sudo -u http /usr/local/bin/php82`.
- Deploy: `pscp` a `/volume2/web/medicvip/` (queda 644 pbaquerizo:users, legible por `http`).
- Tras editar web files como root, re-asertar `chown http:http` + `chmod 644`.

**Plantillas a espejar (leerlas al construir el frontend):** `registro-medico.html` (registro), `medico-portal.html` (portal con sidebar/secciones, `authHeaders`, `showSection`, CSS vars `--green/--green-mid/--green-light/--border/--muted`, fonts DM Serif Display + DM Sans).

---

## Task 1: Migración de base de datos

**Files:** migración en NAS + `schema.sql`.

- [ ] **Step 1: Aplicar en el NAS (base `mediconline`)**
```sql
ALTER TABLE `pacientes`
  ADD COLUMN `password_hash` varchar(255) DEFAULT NULL,
  ADD COLUMN `cedula` varchar(20) DEFAULT NULL,
  ADD COLUMN `fecha_nacimiento` date DEFAULT NULL,
  ADD COLUMN `genero` varchar(30) DEFAULT NULL,
  ADD COLUMN `ciudad` varchar(80) DEFAULT NULL,
  ADD COLUMN `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo';

CREATE TABLE IF NOT EXISTS `paciente_historial` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `paciente_id` int(10) unsigned NOT NULL,
  `tipo_sangre` varchar(5) DEFAULT NULL,
  `alergias` text DEFAULT NULL,
  `enfermedades_cronicas` text DEFAULT NULL,
  `medicamentos_actuales` text DEFAULT NULL,
  `cirugias_previas` text DEFAULT NULL,
  `fuma` enum('No','Sí','Ex-fumador') NOT NULL DEFAULT 'No',
  `alcohol` enum('No','Ocasional','Frecuente') NOT NULL DEFAULT 'No',
  `peso` decimal(5,2) DEFAULT NULL,
  `estatura` smallint(5) unsigned DEFAULT NULL,
  `antecedentes_familiares` text DEFAULT NULL,
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_paciente` (`paciente_id`),
  CONSTRAINT `fk_hist_paciente` FOREIGN KEY (`paciente_id`) REFERENCES `pacientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `consulta_notas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reserva_id` int(10) unsigned NOT NULL,
  `medico_id` int(10) unsigned NOT NULL,
  `paciente_id` int(10) unsigned NOT NULL,
  `diagnostico` text DEFAULT NULL,
  `indicaciones` text DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  `actualizado_en` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reserva` (`reserva_id`),
  KEY `idx_paciente` (`paciente_id`),
  CONSTRAINT `fk_nota_reserva` FOREIGN KEY (`reserva_id`) REFERENCES `reservas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Verificar** — `SHOW COLUMNS FROM pacientes;` (incluye password_hash, cedula, etc.), `SHOW TABLES LIKE 'paciente_historial';`, `SHOW TABLES LIKE 'consulta_notas';`.
- [ ] **Step 3: Reflejar en `schema.sql`** (extender `pacientes` + 2 CREATE TABLE nuevas).
- [ ] **Step 4: Commit** `schema.sql`.

---

## Task 2: Backend — auth de paciente

**Files:** `api.php`.

- [ ] **Step 1: CORS** — en el header, cambiar
`header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, X-Medico-Token');`
por
`header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, X-Medico-Token, X-Paciente-Token');`

- [ ] **Step 2: Helpers + endpoints** — insertar un bloque nuevo (p.ej. después de `medicoRecuperar()` o del bloque de códigos de cortesía):

```php
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

// Upsert del historial (DRY: usado por registro y por actualizar historial)
function upsertHistorial(mysqli $db, int $pid, array $data): void {
    $fuma    = in_array($data['fuma'] ?? 'No', ['No','Sí','Ex-fumador'], true) ? $data['fuma'] : 'No';
    $alcohol = in_array($data['alcohol'] ?? 'No', ['No','Ocasional','Frecuente'], true) ? $data['alcohol'] : 'No';
    $peso    = (isset($data['peso']) && $data['peso'] !== '') ? (float)$data['peso'] : null;
    $est     = (isset($data['estatura']) && $data['estatura'] !== '') ? (int)$data['estatura'] : null;
    $ts  = (string)($data['tipo_sangre'] ?? '');
    $al  = (string)($data['alergias'] ?? '');
    $ec  = (string)($data['enfermedades_cronicas'] ?? '');
    $ma  = (string)($data['medicamentos_actuales'] ?? '');
    $cp  = (string)($data['cirugias_previas'] ?? '');
    $af  = (string)($data['antecedentes_familiares'] ?? '');
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
```

- [ ] **Step 3: Casos en el switch**
```php
        case 'paciente_registro':    pacienteRegistro();     break;
        case 'paciente_login':       pacienteLogin();        break;
        case 'paciente_recuperar':   pacienteRecuperar();    break;
```

---

## Task 3: Backend — perfil e historial del paciente

**Files:** `api.php`.

- [ ] **Step 1: Endpoints**
```php
function pacientePerfil(): void {
    $pid = checkPaciente(); $db = getDB();
    $perfil = fetchOne(query('SELECT id,nombre,email,telefono,cedula,fecha_nacimiento,genero,ciudad,creado_en FROM pacientes WHERE id=?', 'i', [$pid]));
    $perfil['historial'] = fetchOne(query('SELECT tipo_sangre,alergias,enfermedades_cronicas,medicamentos_actuales,cirugias_previas,fuma,alcohol,peso,estatura,antecedentes_familiares,actualizado_en FROM paciente_historial WHERE paciente_id=?', 'i', [$pid]));
    $st = $db->prepare('SELECT r.id,r.horario,r.motivo,r.estado_pago,r.estado_consulta,r.sala_video,r.token_acceso,r.creado_en, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico, e.especialidad, cn.diagnostico, cn.indicaciones, cn.notas FROM reservas r JOIN medicos m ON m.id=r.medico_id LEFT JOIN medico_especialidad e ON e.medico_id=m.id LEFT JOIN consulta_notas cn ON cn.reserva_id=r.id WHERE r.paciente_id=? ORDER BY r.creado_en DESC');
    $st->bind_param('i', $pid); $st->execute();
    $perfil['reservas'] = $st->get_result()->fetch_all(MYSQLI_ASSOC);
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
```

- [ ] **Step 2: Casos en el switch**
```php
        case 'paciente_perfil':               pacientePerfil();             break;
        case 'paciente_actualizar':           pacienteActualizar();         break;
        case 'paciente_historial_actualizar': pacienteHistorialActualizar(); break;
```

---

## Task 4: Backend — agendamiento con cuenta obligatoria

**Files:** `api.php` (`crearReserva`, `reservarEmergencia`).

- [ ] **Step 1: `crearReserva`** — reemplazar el inicio (desde `$data = json_decode(...)` hasta el cierre del bloque que obtiene/crea `$pacienteId` a partir de `email_paciente`) por:
```php
function crearReserva(): void {
    $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    foreach (['medico_id','horario','metodo_pago'] as $f)
        if (empty($data[$f])) throw new Exception("Campo requerido: $f");
    $pacienteId = checkPaciente();
    $db  = getDB();
    $pac = fetchOne(query('SELECT nombre,email FROM pacientes WHERE id=?', 'i', [$pacienteId]));
    $data['nombre_paciente'] = $pac['nombre'];
    $data['email_paciente']  = $pac['email'];
```
El resto de `crearReserva` (tarifa, cortesía, INSERT reserva, emails, `jsonOk`) queda igual — ya usa `$pacienteId`, `$data['nombre_paciente']`, `$data['email_paciente']`.

- [ ] **Step 2: `reservarEmergencia`** — leer la función y aplicar el MISMO patrón: quitar el requerimiento de `nombre_paciente`/`email_paciente` del body y la creación de paciente por email; en su lugar `$pacienteId = checkPaciente();` y obtener `nombre`/`email` de `pacientes`. Conservar el resto (sala, emails, `metodo_pago`).

- [ ] **Step 3: Verificación** — en Task 11 (requiere token de paciente).

---

## Task 5: Backend — acceso del médico (historial + notas)

**Files:** `api.php`.

- [ ] **Step 1: Endpoints**
```php
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
    $st = $db->prepare('INSERT INTO consulta_notas (reserva_id,medico_id,paciente_id,diagnostico,indicaciones,notas) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE diagnostico=VALUES(diagnostico),indicaciones=VALUES(indicaciones),notas=VALUES(notas)');
    $st->bind_param('iiisss', $rid, $medicoId, $pid, $diag, $ind, $not); $st->execute();
    jsonOk(['mensaje' => 'Nota clínica guardada']);
}
```

- [ ] **Step 2: Casos en el switch**
```php
        case 'medico_ver_historial':  medicoVerHistorialPaciente(); break;
        case 'medico_guardar_nota':   medicoGuardarNota();          break;
```

---

## Task 6: `registro-paciente.html` (página nueva)

**Files:** Create `registro-paciente.html`.

- [ ] **Step 1: Construir la página espejando `registro-medico.html`** (mismo `<head>`, fonts, CSS vars, nav, estilo de tarjetas/inputs). Contenido:
  - Encabezado "Crear cuenta de paciente".
  - **Sección "Datos de la cuenta":** Nombres*, Apellidos*, Cédula, Fecha de nacimiento*, Género (select: Masculino/Femenino/Otro), Teléfono*, Correo*, Ciudad, Contraseña* (min 8), Confirmar contraseña*.
  - **Sección "Historial médico":** Tipo de sangre (select A+/A-/B+/B-/AB+/AB-/O+/O-/No sé), Alergias (textarea), Enfermedades crónicas (textarea, hint: diabetes, hipertensión, asma…), Medicamentos actuales (textarea), Cirugías previas (textarea), Fuma (select No/Sí/Ex-fumador), Alcohol (select No/Ocasional/Frecuente), Peso kg (number), Estatura cm (number), Antecedentes familiares (textarea).
  - Botón "Crear cuenta".
- [ ] **Step 2: JS de envío**
```javascript
const API = '/api.php';
async function registrar() {
  const g = id => document.getElementById(id).value.trim();
  if (g('pass') !== g('pass2')) { alert('Las contraseñas no coinciden'); return; }
  if (g('pass').length < 8) { alert('La contraseña debe tener mínimo 8 caracteres'); return; }
  const payload = {
    nombres:g('nombres'), apellidos:g('apellidos'), cedula:g('cedula'), fecha_nacimiento:g('fnac'),
    genero:g('genero'), telefono:g('telefono'), email:g('email'), ciudad:g('ciudad'), password:g('pass'),
    tipo_sangre:g('tipo_sangre'), alergias:g('alergias'), enfermedades_cronicas:g('enfermedades'),
    medicamentos_actuales:g('medicamentos'), cirugias_previas:g('cirugias'), fuma:g('fuma'),
    alcohol:g('alcohol'), peso:g('peso'), estatura:g('estatura'), antecedentes_familiares:g('antecedentes')
  };
  const btn = document.getElementById('btn-registrar'); btn.disabled = true; btn.textContent = 'Creando...';
  try {
    const res = await fetch(API + '?action=paciente_registro', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
    const json = await res.json();
    if (!json.ok) { alert('Error: ' + json.error); return; }
    localStorage.setItem('paciente_token', json.data.token);
    localStorage.setItem('paciente_id', json.data.paciente.id);
    localStorage.setItem('paciente_nombre', json.data.paciente.nombre);
    window.location.href = '/paciente-portal.html';
  } catch(e) { alert('Error de conexión'); }
  finally { btn.disabled = false; btn.textContent = 'Crear cuenta'; }
}
```
(Los `id` del form deben coincidir con los usados en `g(...)`.)

- [ ] **Step 3: Verificación** — Task 11 (navegador + curl).

---

## Task 7: `paciente-portal.html` (página nueva)

**Files:** Create `paciente-portal.html`.

- [ ] **Step 1: Construir espejando `medico-portal.html`** (sidebar + secciones + `showSection` + patrón `authHeaders`). Diferencias:
  - Token en `localStorage.getItem('paciente_token')`; `authHeaders()` devuelve `{'Content-Type':'application/json','X-Paciente-Token': token}`.
  - **Login gate:** si no hay token, mostrar formulario de login (email+password → `paciente_login`, guarda token → recarga). Link a `registro-paciente.html` y a "¿Olvidaste tu contraseña?" (`paciente_recuperar`).
  - **Secciones del dashboard:**
    - **Inicio:** saludo + resumen (próximas citas, total de citas).
    - **Mis citas:** próximas (estado_consulta `agendada`/`confirmada`) con médico/horario/enlace de sala; e historial (resto) con diagnóstico/indicaciones si existen.
    - **Mi historial médico:** ver + editar (form con los campos de `paciente_historial`) → `paciente_historial_actualizar`.
    - **Mi perfil:** editar telefono/ciudad/genero/fecha_nacimiento → `paciente_actualizar`.
- [ ] **Step 2: JS** — `loadPerfilPaciente()` (fetch `paciente_perfil`, poblar secciones y separar reservas por estado), `guardarHistorial()`, `guardarPerfilPaciente()`, `doLoginPaciente()`, `doLogoutPaciente()`. Usar el mismo estilo que las funciones `loadX` de `medico-portal.html`.
- [ ] **Step 3: Verificación** — Task 11.

---

## Task 8: `pacientes.html` — gate de login + reserva con cuenta

**Files:** `pacientes.html`.

- [ ] **Step 1: Detectar sesión** — al cargar el `<script>`, definir:
```javascript
const PACIENTE_TOKEN = localStorage.getItem('paciente_token') || '';
const PACIENTE_NOMBRE = localStorage.getItem('paciente_nombre') || '';
function pacienteAuthHeaders(){ return {'Content-Type':'application/json','X-Paciente-Token': PACIENTE_TOKEN}; }
```
Agregar en el nav un indicador: si hay sesión, "Mi cuenta" (→ `/paciente-portal.html`); si no, "Iniciar sesión" (→ `/paciente-portal.html`).

- [ ] **Step 2: Gate al abrir el modal de reserva** — en la función que abre el modal de agendamiento (y en la de emergencia), al inicio:
```javascript
  if (!PACIENTE_TOKEN) {
    if (confirm('Para agendar necesitas una cuenta. ¿Ir a iniciar sesión / registrarte?')) window.location.href = '/paciente-portal.html';
    return;
  }
```

- [ ] **Step 3: Modal sin datos personales** — quitar del modal los inputs de nombre/edad/email/teléfono del paciente (ahora vienen de la cuenta). Mantener: motivo, alergias, método de pago (incl. cortesía). Ajustar `_captureForm` para no leer esos campos (usar solo motivo/alergias + método).

- [ ] **Step 4: Enviar con token** — en `_doConfirmBooking` y en `confirmarEmergencia`, agregar el header `X-Paciente-Token` (usar `pacienteAuthHeaders()`), y quitar del body `nombre_paciente`, `email_paciente`, `telefono_paciente`, `edad` (el backend los toma de la cuenta). Conservar `medico_id`, `horario`, `motivo`, `alergias`, `metodo_pago`, `codigo`.

- [ ] **Step 5: Verificación** — Task 11 (navegador).

---

## Task 9: `medico-portal.html` — ver historial + nota clínica

**Files:** `medico-portal.html`.

- [ ] **Step 1: Botones en cada reserva** — en el render de la sección "Mis reservas" (`loadReservas`), para cada reserva agregar dos botones: `Ver historial` (`onclick="verHistorialPaciente(<paciente_id>)"`) y `Nota clínica` (`onclick="abrirNota(<reserva_id>)"`). El `paciente_id` debe venir en los datos de la reserva; si `medico_perfil`→reservas no lo trae, agregar `r.paciente_id` a ese SELECT en `medicoPerfil` (api.php).
- [ ] **Step 2: JS** — usando `authHeaders()` (X-Medico-Token):
```javascript
async function verHistorialPaciente(pid) {
  const res = await fetch(API + '?action=medico_ver_historial', {method:'POST', headers: authHeaders(), body: JSON.stringify({paciente_id: pid})});
  const json = await res.json();
  if (!json.ok) { alert('Error: ' + json.error); return; }
  const d = json.data, h = d.historial || {};
  const notas = (d.notas_previas||[]).map(n => `<div style="border-top:1px solid var(--border);padding:8px 0"><b>${n.horario||n.creado_en}</b> — ${n.medico}<br>Dx: ${n.diagnostico||'—'}<br>Indicaciones: ${n.indicaciones||'—'}</div>`).join('') || 'Sin notas previas';
  // Mostrar en un modal/overlay del portal (usar el patrón de modal existente o un overlay simple):
  mostrarModalHistorial(d, h, notas);
}
async function abrirNota(reservaId) { /* abre modal con textareas diagnostico/indicaciones/notas + guardarNota(reservaId) */ }
async function guardarNota(reservaId) {
  const payload = {reserva_id: reservaId, diagnostico: document.getElementById('n-diag').value, indicaciones: document.getElementById('n-ind').value, notas: document.getElementById('n-notas').value};
  const res = await fetch(API + '?action=medico_guardar_nota', {method:'POST', headers: authHeaders(), body: JSON.stringify(payload)});
  const json = await res.json();
  if (!json.ok) { alert('Error: ' + json.error); return; }
  alert('Nota guardada'); cerrarModal();
}
```
Construir `mostrarModalHistorial` y `abrirNota` con un overlay simple consistente con el estilo del portal (leer cómo el portal ya hace modales/overlays; si no hay, crear un `<div>` overlay fijo). Precargar la nota existente si la reserva ya tiene una (opcional: el modal puede empezar vacío y el upsert se encarga).

- [ ] **Step 3: Verificación** — Task 11.

---

## Task 10: `index.html` — acceso de paciente

**Files:** `index.html`.

- [ ] **Step 1** — en el nav, agregar un enlace "Mi cuenta" → `/paciente-portal.html` (junto a los CTAs existentes). Mantener "Buscar médico" → `pacientes.html`.

---

## Task 11: Deploy, verificación end-to-end y commit

**Files:** deploy de todos los archivos tocados.

- [ ] **Step 1: Deploy** — `pscp` de `api.php`, `pacientes.html`, `medico-portal.html`, `index.html`, `registro-paciente.html`, `paciente-portal.html` a `/volume2/web/medicvip/`.
- [ ] **Step 2: `php -l api.php`** en el NAS → sin errores.
- [ ] **Step 3: Verificación E2E por curl (interno):**
  1. `paciente_registro` (cuenta + historial) → token de paciente.
  2. `paciente_login` con esas credenciales → token.
  3. `paciente_perfil` (con `X-Paciente-Token`) → perfil + historial + reservas vacías.
  4. `paciente_historial_actualizar` → cambia un campo; `paciente_perfil` lo refleja.
  5. `reservar` (con `X-Paciente-Token`, sin datos personales en el body) → crea reserva; sin token → 401.
  6. Login médico (#22) → token; `medico_ver_historial` con el `paciente_id` (que ya tiene la reserva) → devuelve historial + notas. Con un `paciente_id` sin cita con ese médico → 403.
  7. `medico_guardar_nota` para esa reserva → ok; `paciente_perfil` muestra el diagnóstico en esa reserva.
  8. Limpiar los datos de prueba (reserva, nota, paciente de prueba).
- [ ] **Step 4: Verificación de UI (navegador):** registro-paciente crea cuenta y redirige al portal; el portal muestra secciones; `pacientes.html` sin sesión bloquea el agendar e invita a login; con sesión agenda sin pedir datos personales; el portal médico muestra "Ver historial"/"Nota clínica".
- [ ] **Step 5: Commit y push** de todos los archivos + `schema.sql`.

---

## Criterios de aceptación (del spec)

1. Registro de paciente (cuenta + historial) deja al paciente logueado.
2. Login de paciente y panel con próximas citas, historial de citas e historial médico.
3. El paciente edita su historial médico y su perfil.
4. Sin sesión no se agenda; se invita a registrarse/iniciar sesión.
5. Con sesión, el agendamiento usa los datos de la cuenta y conserva la cortesía.
6. El médico ve historial + notas previas de un paciente con el que tiene cita, y solo de esos (403 si no).
7. El médico escribe/edita una nota clínica por consulta.
8. Las notas clínicas aparecen en el historial de citas del paciente.
