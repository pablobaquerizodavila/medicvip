# Expediente clínico Fase 1 — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** Expediente clínico consolidado para el médico: consultas estructuradas, tratamientos con resultado, signos vitales, documentos/adjuntos, y una página de expediente; el paciente ve sus tratamientos.

**Architecture:** Vanilla PHP + MariaDB + HTML/JS inline. Extiende `consulta_notas`; agrega `tratamientos`, `signos_vitales`, `documentos`. Endpoints de médico con verificación de relación médico↔paciente. Página nueva `expediente-paciente.html` (dashboard clínico). Subida de archivos base64.

**Entorno NAS (igual que planes previos):** `plink -pw "<pass>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" pbaquerizo@192.168.0.116`, técnica base64, `sudo -S`, PHP como `http` con `sudo -u http /usr/local/bin/php82`, API interna `curl -H 'Host: medicvip.org' http://127.0.0.1/api.php`, deploy por `pscp` (queda 644 legible por http). `php -l` en el NAS.

**Plantillas a espejar:** `medico-portal.html` (estilo, sidebar, `authHeaders` X-Medico-Token, modales); paleta `--green/--green-mid/--green-light/--border/--muted`, fonts DM Serif Display + DM Sans.

---

## Task 1: Migración DB + carpeta de documentos

**Files:** migración NAS + `schema.sql`.

- [ ] **Step 1: Aplicar en el NAS (base `mediconline`)** — ejecutar:
```sql
ALTER TABLE `consulta_notas`
  ADD COLUMN `plan` text DEFAULT NULL,
  ADD COLUMN `proximo_control` date DEFAULT NULL,
  ADD COLUMN `cie10` varchar(120) DEFAULT NULL;
```
más los 3 `CREATE TABLE` (`tratamientos`, `signos_vitales`, `documentos`) exactamente como en el spec `docs/superpowers/specs/2026-07-10-expediente-clinico-design.md`.

- [ ] **Step 2: Crear carpeta de documentos escribible por `http`** (PHP-FPM corre como http; `uploads/` es de pbaquerizo:users y http NO puede escribir ahí):
```
sudo mkdir -p /volume2/web/medicvip/uploads/documentos
sudo chown http:http /volume2/web/medicvip/uploads/documentos
sudo chmod 755 /volume2/web/medicvip/uploads/documentos
```

- [ ] **Step 3: Verificar** — `SHOW COLUMNS FROM consulta_notas LIKE 'plan';`, `SHOW TABLES LIKE 'tratamientos';`, `SHOW TABLES LIKE 'signos_vitales';`, `SHOW TABLES LIKE 'documentos';`, y `ls -la uploads/documentos` (http:http).

- [ ] **Step 4: Reflejar en `schema.sql`** (extender consulta_notas + 3 tablas) y commit.

---

## Task 2: Backend — expediente, tratamientos, vitales, documentos

**Files:** `api.php` (todo aditivo salvo la extensión de `pacientePerfil`).

- [ ] **Step 1: Insertar helpers + endpoints** (en zona de funciones, p.ej. tras el bloque de paciente/notas):

```php
// ── EXPEDIENTE CLÍNICO (MÉDICO) ───────────────────────────────────────────────
function checkRelacionMedicoPaciente(int $medicoId, int $pid): void {
    if (!fetchOne(query('SELECT id FROM reservas WHERE medico_id=? AND paciente_id=? LIMIT 1', 'ii', [$medicoId, $pid])))
        jsonError('No tienes citas con este paciente', 403);
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
    // reservas del médico con este paciente (para asociar nota/tratamiento/vitales/documento a una consulta)
    $st = $db->prepare('SELECT id,horario,estado_consulta,creado_en FROM reservas WHERE paciente_id=? AND medico_id=? ORDER BY creado_en DESC');
    $st->bind_param('ii',$pid,$medicoId); $st->execute(); $out['reservas']=$st->get_result()->fetch_all(MYSQLI_ASSOC);
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
```

- [ ] **Step 2: Extender `pacientePerfil`** — antes del `jsonOk($perfil);`, agregar:
```php
    $stT = $db->prepare('SELECT id,medicamento,dosis,frecuencia,via,duracion,fecha_inicio,estado,resultado,nota_cierre,fecha_cierre FROM tratamientos WHERE paciente_id=? ORDER BY creado_en DESC');
    $stT->bind_param('i', $pid); $stT->execute();
    $perfil['tratamientos'] = $stT->get_result()->fetch_all(MYSQLI_ASSOC);
```
(Usa `$db` y `$pid`, ya definidos en `pacientePerfil`.)

- [ ] **Step 2b: Extender `medicoGuardarNota`** para guardar también `cie10`, `plan` y `proximo_control`. Reemplazar su `INSERT ... ON DUPLICATE KEY UPDATE` y su `bind_param` por:
```php
    $cie = (string)($data['cie10'] ?? ''); $plan = (string)($data['plan'] ?? '');
    $pc  = !empty($data['proximo_control']) ? (string)$data['proximo_control'] : null;
    $st = $db->prepare('INSERT INTO consulta_notas (reserva_id,medico_id,paciente_id,diagnostico,indicaciones,notas,cie10,plan,proximo_control) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE diagnostico=VALUES(diagnostico),indicaciones=VALUES(indicaciones),notas=VALUES(notas),cie10=VALUES(cie10),plan=VALUES(plan),proximo_control=VALUES(proximo_control)');
    $st->bind_param('iiissssss', $rid, $medicoId, $pid, $diag, $ind, $not, $cie, $plan, $pc);
```
(mantener el resto de `medicoGuardarNota` igual; `$diag/$ind/$not/$rid/$medicoId/$pid` ya existen).

- [ ] **Step 3: Casos en el switch**
```php
        case 'medico_expediente':            medicoExpediente();            break;
        case 'medico_tratamiento_crear':     medicoTratamientoCrear();      break;
        case 'medico_tratamiento_actualizar':medicoTratamientoActualizar(); break;
        case 'medico_vitales_registrar':     medicoVitalesRegistrar();      break;
        case 'medico_documento_subir':       medicoDocumentoSubir();        break;
        case 'medico_documento_eliminar':    medicoDocumentoEliminar();     break;
```

- [ ] **Step 4: Auto-revisión de bind_param**
  - `medicoTratamientoCrear`: `'iiissssss'` (9) vs 9 columnas.
  - `medicoTratamientoActualizar`: `'ssssi'` (5).
  - `medicoVitalesRegistrar`: `'iiiiiiiiddii'` (12) vs 12 columnas (ps,pd,fc,fr,sat = int; temp,peso = double; est,glu = int).
  - `medicoDocumentoSubir`: `'iiissssis'` (9) vs 9 columnas.
  - Llaves balanceadas; 6 `case` en el switch.

---

## Task 3: `medico-portal.html` — botón "Expediente"

**Files:** `medico-portal.html`.

- [ ] **Step 1:** En el render de `loadReservas` (junto a los botones "Ver historial"/"Nota clínica"), agregar por reserva:
```javascript
`<button onclick="window.open('/expediente-paciente.html?paciente=${r.paciente_id}','_blank')" style="background:#1565C0;border:none;color:#fff;font-size:12px;padding:7px 14px;border-radius:6px;cursor:pointer">📋 Expediente</button>`
```
(usar el `paciente_id` que ya trae la reserva). Auto-revisión: el JS compila; no rompe `loadReservas`.

---

## Task 4: `paciente-portal.html` — sección "Mis tratamientos"

**Files:** `paciente-portal.html`.

- [ ] **Step 1:** Agregar nav-item "💊 Mis tratamientos" → `showSection('tratamientos',...)` y una `<section id="section-tratamientos" class="section">` con un contenedor `<div id="tratamientos-lista"></div>`.
- [ ] **Step 2:** En `loadPerfil()` (donde ya se puebla el perfil), poblar la lista desde `data.tratamientos`:
```javascript
const cont = document.getElementById('tratamientos-lista');
if (cont) {
  const ts = perfil.tratamientos || [];
  const resLbl = {pendiente:'En curso',resolvio:'Resolvió',mejoro:'Mejoró',sin_cambio:'Sin cambio',empeoro:'Empeoró'};
  const resCol = {pendiente:'#6c757d',resolvio:'#1d9e75',mejoro:'#1d9e75',sin_cambio:'#b8860b',empeoro:'#C0392B'};
  cont.innerHTML = ts.length ? ts.map(t => `<div style="border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:10px;background:var(--white)">
      <div style="font-weight:600">${t.medicamento||''} ${t.dosis?('· '+t.dosis):''}</div>
      <div style="font-size:13px;color:var(--muted)">${[t.frecuencia,t.via,t.duracion].filter(Boolean).join(' · ')}</div>
      <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
        <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;background:#eee">${t.estado}</span>
        <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px;background:${resCol[t.resultado]||'#6c757d'}22;color:${resCol[t.resultado]||'#6c757d'}">${resLbl[t.resultado]||t.resultado}</span>
      </div>
      ${t.nota_cierre?`<div style="font-size:12px;color:var(--muted);margin-top:6px">🏁 ${t.nota_cierre}</div>`:''}
    </div>`).join('') : '<p style="color:var(--muted);font-size:14px">Aún no tienes tratamientos registrados.</p>';
}
```
(usar la variable de perfil que ya exista en `loadPerfil`, p.ej. `perfil` o `data`). Auto-revisión: JS compila.

---

## Task 5: `expediente-paciente.html` (página nueva — dashboard clínico)

**Files:** Create `expediente-paciente.html`.

- [ ] **Step 1: Construir la página** (HTML+CSS+JS inline, sin build), **solo médico**. Diseño **dashboard clínico**: fondo claro, tarjetas blancas con bordes suaves, tipografía legible, jerarquía clara, colores semánticos moderados — **rojo** `#C0392B` (alertas críticas), **amarillo** `#B8860B` (advertencias), **verde** `#1D9E75` (favorable), **azul** `#1565C0` (info/navegación). Responsive (PC/tablet/móvil). Reusa fonts/paleta base del portal.
  - Lee `paciente` de la query string (`?paciente=<id>`). Requiere `localStorage.getItem('medico_token')`; si no hay, redirige a `/medico-portal.html`. `authHeaders()` = `{'Content-Type':'application/json','X-Medico-Token': token}`.
  - Al cargar: `POST /api.php?action=medico_expediente` con `{paciente_id}` → renderiza.
  - **Encabezado del paciente:** iniciales/nombre, edad (calcular de `fecha_nacimiento`), sexo (genero), tipo de sangre (`historial.tipo_sangre`), teléfono, última consulta (fecha de la 1ª de `consultas`). Botón "← Volver".
  - **Alertas** (banda): si `historial.alergias` → tarjeta roja "Alergias: …"; si `historial.enfermedades_cronicas` → tarjeta amarilla "Crónicas: …". Si ninguna, ocultar la banda.
  - **Pestañas internas** (botones que muestran/ocultan paneles): **Resumen · Consultas · Tratamientos · Signos vitales · Documentos**.
    - *Resumen:* tarjetas — últimos diagnósticos (de `consultas`), tratamientos activos (`estado==='activo'`), últimos signos vitales (`vitales[0]` con IMC calculado de peso/estatura).
    - *Consultas:* lista cronológica (diagnóstico, cie10, indicaciones, plan, próximo control, notas, médico, fecha). Botón "➕ Nueva nota" → modal con un `<select>` de `reservas` (del expediente) para elegir la consulta + campos diagnóstico, cie10, indicaciones, plan, próximo control, notas → `POST medico_guardar_nota` con `{reserva_id, diagnostico, indicaciones, notas}` (los campos extra `cie10/plan/proximo_control` requieren extender `medico_guardar_nota` — ver nota). **Nota:** `medico_guardar_nota` hoy solo guarda diagnostico/indicaciones/notas; para guardar también `cie10/plan/proximo_control` hay que extender ese endpoint (agregar esos 3 campos al `INSERT ... ON DUPLICATE KEY UPDATE`). Incluir esa extensión mínima en Task 2.
    - *Tratamientos:* lista con chips de estado y resultado (colores semánticos). Botón "➕ Nuevo tratamiento" → modal (medicamento, dosis, frecuencia, vía, duración, fecha_inicio) → `POST medico_tratamiento_crear`. En tratamientos con `estado!=='finalizado'`, botón "Cerrar/registrar resultado" → modal (resultado select: resolvió/mejoró/sin cambio/empeoró; nota_cierre) → `POST medico_tratamiento_actualizar` con `estado:'finalizado'`.
    - *Signos vitales:* tabla de `vitales`. Botón "➕ Registrar" → modal (PA sist/diast, FC, FR, SatO₂, temp, peso, estatura, glucosa) → `POST medico_vitales_registrar`.
    - *Documentos:* lista (tipo, título, fecha, enlace "Ver" a `archivo`, botón "Eliminar" → `medico_documento_eliminar`). Botón "➕ Subir" → modal (tipo select, título, input file) → leer el archivo con `FileReader.readAsDataURL`, enviar `{archivo_base64, mime: file.type, tamano: file.size, tipo, titulo}` a `medico_documento_subir`. Validar tamaño ≤ 8 MB en el cliente.
  - Tras cada acción exitosa, recargar el expediente (`cargarExpediente()`).
  - Un modal simple reutilizable (overlay fijo + tarjeta) como el de `medico-portal.html`.
- [ ] **Step 2: Auto-revisión** — la página abre como HTML completo; el JS compila (`new Function`); requiere token; llama a `medico_expediente` y a los endpoints de acción con `X-Medico-Token`; responsive.

---

## Task 6: Deploy, verificación E2E y commit

**Files:** deploy de `api.php`, `medico-portal.html`, `paciente-portal.html`, `expediente-paciente.html`.

- [ ] **Step 1: Deploy** por `pscp` de los 4 archivos + `schema.sql` (commit).
- [ ] **Step 2: `php -l api.php`** en el NAS → sin errores.
- [ ] **Step 3: Verificación E2E (curl interno)** — usando un paciente de prueba CON una reserva con el médico #22 (crear paciente + reserva como en el plan anterior):
  1. Login médico #22 → token.
  2. `medico_expediente` con el `paciente_id` (con reserva) → devuelve estructura completa; con un paciente sin reserva → 403.
  3. `medico_tratamiento_crear` → id; `medico_expediente` lo lista con estado 'activo'.
  4. `medico_tratamiento_actualizar` (estado finalizado + resultado 'mejoro' + nota) → el expediente lo muestra finalizado/mejoró.
  5. `medico_vitales_registrar` → aparece en `vitales`.
  6. `medico_documento_subir` con un PDF/imagen chica en base64 → id + archivo; `medico_expediente` lo lista; el archivo existe en `uploads/documentos/`. `medico_documento_eliminar` → borra fila y archivo.
  7. `paciente_perfil` (token de paciente) → incluye `tratamientos`.
  8. Limpiar datos de prueba (paciente, reserva, tratamientos, vitales, documentos + archivo).
- [ ] **Step 4: Verificación UI** — abrir `expediente-paciente.html?paciente=<id>` logueado como médico: se ve encabezado, alertas, pestañas; crear tratamiento y cerrarlo; registrar vitales; subir/eliminar documento. El portal médico muestra "📋 Expediente"; el portal del paciente muestra "Mis tratamientos".
- [ ] **Step 5: Commit y push** de todo + `schema.sql`.

---

## Criterios de aceptación (del spec)
1. Expediente consolidado visible para el médico con relación (403 si no).
2. Crear tratamiento y cerrarlo con resultado + nota de cierre.
3. Registrar signos vitales y verlos.
4. Subir/ver/descargar/eliminar documento (PDF/imagen ≤ 8 MB).
5. Nota de consulta con diagnóstico/CIE-10/indicaciones/plan/próximo control.
6. El paciente ve sus tratamientos con estado y resultado.
7. Expediente legible, responsive, con colores semánticos.
8. Ningún endpoint expone datos de un paciente sin relación con el médico.
