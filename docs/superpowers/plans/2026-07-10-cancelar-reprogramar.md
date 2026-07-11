# Cancelar / Reprogramar citas — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** El paciente cancela/reprograma sus citas (autoservicio, cutoff 12 h) y el médico cancela con motivo; una cita `confirmada` deja de liberar su horario.

**Architecture:** Endpoints nuevos en `api.php` + helper de validación reusado desde `crearReserva`; botones en `paciente-portal.html` y `medico-portal.html`. Reembolso = cambio de estado + fila en `transacciones` (no hay pasarela real). Vanilla PHP + HTML/JS inline.

**Entorno NAS (igual que planes previos):** `plink -pw "<pass>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" pbaquerizo@192.168.0.116`, técnica base64, `sudo -S`, PHP como http `sudo -u http /usr/local/bin/php82`, API interna `curl -H 'Host: medicvip.org' http://127.0.0.1/api.php`, deploy `pscp` a home + script base64 que instala en `/volume2/web/medicvip/` con `chown http:http` + `chmod 644`, `php -l` en el NAS.

---

## Task 1: Backend — helper, fix de ocupación y endpoints

**Files:** `api.php`.

- [ ] **Step 1: Helper `validarNuevoInicio`** — insertar junto a las funciones de agenda (tras `generarSlotsDisponibles`):
```php
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
```

- [ ] **Step 2: `crearReserva` usa el helper** — reemplazar el bloque actual (líneas ~201-212, desde `$inicioVal = (string)($data['inicio'] ?? '');` hasta `$data['horario'] = $_dAbr[...];`) por:
```php
    $inicioVal = (string)($data['inicio'] ?? '');
    $data['horario'] = validarNuevoInicio((int)$data['medico_id'], $inicioVal);
```
(No tocar el resto de `crearReserva`; `$inicioVal` sigue usándose en el INSERT.)

- [ ] **Step 3: Fix de ocupación en `generarSlotsDisponibles`** — en la query de ocupados, cambiar `estado_consulta='agendada'` por `estado_consulta IN ('agendada','confirmada')`:
```php
    $so = $db->prepare("SELECT inicio FROM reservas WHERE medico_id=? AND estado_consulta IN ('agendada','confirmada') AND inicio IS NOT NULL");
```

- [ ] **Step 4: Filtro de citas en `medicoAgenda`** — a la query de `citas`, agregar el filtro de estado. La query pasa a:
```php
    $sc = $db->prepare("SELECT r.id AS reserva_id, r.inicio, r.paciente_id, p.nombre AS paciente, r.estado_consulta, r.motivo FROM reservas r JOIN pacientes p ON p.id=r.paciente_id WHERE r.medico_id=? AND r.inicio IS NOT NULL AND r.estado_consulta IN ('agendada','confirmada','realizada') AND DATE(r.inicio) BETWEEN ? AND ? ORDER BY r.inicio");
```

- [ ] **Step 5: `pacientePerfil` — exponer `inicio` y `medico_id`** — en el SELECT de `$perfil['reservas']` (línea ~896) agregar `r.inicio, r.medico_id`. La lista de columnas pasa a empezar por:
```php
    $st = $db->prepare('SELECT r.id,r.inicio,r.medico_id,r.horario,r.motivo,r.estado_pago,r.estado_consulta,r.sala_video,r.token_acceso,r.creado_en, CONCAT(m.titulo," ",m.nombre," ",m.apellido) AS medico, e.especialidad, cn.diagnostico, cn.indicaciones, cn.notas FROM reservas r JOIN medicos m ON m.id=r.medico_id LEFT JOIN medico_especialidad e ON e.medico_id=m.id LEFT JOIN consulta_notas cn ON cn.reserva_id=r.id WHERE r.paciente_id=? ORDER BY r.creado_en DESC');
```

- [ ] **Step 6: Helper de cancelación + 3 endpoints** — insertar (p.ej. tras `medicoBloqueoEliminar`):
```php
// ── CANCELAR / REPROGRAMAR ────────────────────────────────────────────────────
function cancelarReservaInterno(mysqli $db, int $rid, array $r, string $nota): void {
    $ahora = date('Y-m-d H:i:s');
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
    $horario = validarNuevoInicio((int)$r['medico_id'], $nuevoInicio, $rid); // lanza Exception → el router responde el error
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
```
Nota: en `pacienteReprogramarReserva`, `$u->bind_param('sssi', ...)` sirve para ambas ramas del `if` porque ambas UPDATE tienen los mismos 4 placeholders en el mismo orden (`inicio, horario, limite_confirmacion, id`); las columnas extra de la rama `pagado` son literales sin placeholder.

- [ ] **Step 7: Casos en el switch** (junto a los otros `case`):
```php
        case 'paciente_cancelar_reserva':    pacienteCancelarReserva();    break;
        case 'paciente_reprogramar_reserva': pacienteReprogramarReserva(); break;
        case 'medico_cancelar_reserva':      medicoCancelarReserva();      break;
```

- [ ] **Step 8: Auto-revisión** — binds: `cancelarReservaInterno` UPDATE `'ssi'`(3) / `'si'`(2), INSERT `'ids'`(3); `pacienteReprogramarReserva` UPDATE `'sssi'`(4) en ambas ramas; SELECTs `'ii'`/`'i'`. Cutoff 12 h solo en paciente (cancelar y reprogramar), no en médico. `validarNuevoInicio` con `IN ('agendada','confirmada')` y exclusión propia. `crearReserva` sigue definiendo `$inicioVal` para el INSERT. 3 `case`. Llaves balanceadas.

---

## Task 2: `paciente-portal.html` — Cancelar y Reprogramar

**Files:** `paciente-portal.html`.

LEE el archivo primero: cómo se renderiza "Mis reservas" (usa `pacientePerfil` → `data.reservas`), `pacienteAuthHeaders()` (X-Paciente-Token), el patrón de modal/toast existente, y cómo se hace `POST` a la API. Reusa el patrón del selector de horarios de `pacientes.html` (fetch `horarios_disponibles`, chips agrupados por día) para reprogramar.

- [ ] **Step 1: Botones por reserva** — cada reserva ahora trae `id, inicio, medico_id, horario, estado_consulta, estado_pago, medico`. Para cada una calcula en JS:
  - `activa = ['agendada','confirmada'].includes(r.estado_consulta)`.
  - `futura = r.inicio && new Date(r.inicio.replace(' ','T')) > new Date()`.
  - `hay12h = r.inicio && (new Date(r.inicio.replace(' ','T')) - new Date()) >= 12*3600*1000`.
  - Si `activa && futura && hay12h`: muestra botones **Cancelar** (`onclick="cancelarReserva(ID)"`) y **Reprogramar** (`onclick="abrirReprogramar(ID, MEDICO_ID)"`).
  - Si `activa && futura && !hay12h`: muestra nota "Para cambios con menos de 12 h, contacta a soporte." (sin botones).
  - Estados no activos: solo la etiqueta de estado actual (sin botones).

- [ ] **Step 2: Cancelar** —
```
async function cancelarReserva(id){
  if(!confirm('¿Seguro que quieres cancelar esta cita? Si tenías un pago en custodia, será reembolsado.')) return;
  const r = await fetch('/api.php?action=paciente_cancelar_reserva',{method:'POST',headers:{...pacienteAuthHeaders(),'Content-Type':'application/json'},body:JSON.stringify({reserva_id:id})});
  const j = await r.json();
  if(!j.ok){ alert(j.error||'No se pudo cancelar'); return; }
  cargarPerfil(); // recargar la lista (usar el nombre real de la función que llena "Mis reservas")
}
```
(Ajusta `cargarPerfil` al nombre real de la función de recarga; ajusta `j.ok` si el resto del portal usa otra bandera — verifica que la API responde `{ok:true/false}`.)

- [ ] **Step 3: Reprogramar** — modal con selector de horarios con fecha:
```
let _reprogId=null, _reprogInicio=null;
async function abrirReprogramar(id, medicoId){
  _reprogId=id; _reprogInicio=null;
  // abre el modal existente; muestra "Cargando horarios…"
  const r = await fetch('/api.php?action=horarios_disponibles',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({medico_id:medicoId})});
  const j = await r.json(); const slots=(j.data)||[];
  if(!slots.length){ /* mostrar "Sin horarios disponibles en 4 semanas" en el modal */ return; }
  // render agrupado por día (igual que pacientes.html): chips con onclick="pickReprog('<inicio>', '<label escapado>', this)"
}
function pickReprog(inicio,label,el){ _reprogInicio=inicio; /* marca chip + muestra "Nuevo horario: "+label */ }
async function confirmarReprogramar(){
  if(!_reprogInicio){ alert('Elige un nuevo horario'); return; }
  const r = await fetch('/api.php?action=paciente_reprogramar_reserva',{method:'POST',headers:{...pacienteAuthHeaders(),'Content-Type':'application/json'},body:JSON.stringify({reserva_id:_reprogId, inicio:_reprogInicio})});
  const j = await r.json();
  if(!j.ok){ alert(j.error||'No se pudo reprogramar'); return; }
  cerrarModal(); cargarPerfil();
}
```
(Reusa el modal y `cerrarModal`/toast existentes del portal; escapa el label como en `pacientes.html`.)

- [ ] **Step 4: Auto-revisión** — botones solo aparecen cuando corresponde (activa+futura+≥12 h); cancelar pide confirmación y recarga; reprogramar carga slots del médico correcto, exige selección y manda `{reserva_id, inicio}`; usa `j.ok`; JS válido (`node --check`).

---

## Task 3: `medico-portal.html` — Cancelar con motivo

**Files:** `medico-portal.html`.

LEE el archivo: la lista "Mis reservas" (`loadReservas`, botones por reserva como "Ver historial"/"Nota clínica"/"Expediente"), `authHeaders()` (X-Medico-Token), y el patrón de modal/toast. La respuesta de `medicoReservas` incluye `estado_consulta` por reserva.

- [ ] **Step 1: Botón Cancelar** — en cada reserva **activa** (`estado_consulta ∈ {agendada, confirmada}`), agregar botón **Cancelar cita** (`onclick="cancelarReservaMedico(ID)"`). (Opcional: ocultarlo si la cita ya pasó, comparando `inicio`/`horario` si está disponible; si no hay `inicio` fácil, basta con el filtro de estado.)
- [ ] **Step 2: Handler** —
```
async function cancelarReservaMedico(id){
  const motivo = prompt('Motivo de la cancelación (se enviará al paciente):');
  if(motivo===null) return;
  if(!motivo.trim()){ alert('Debes indicar un motivo'); return; }
  const r = await fetch('/api.php?action=medico_cancelar_reserva',{method:'POST',headers:{...authHeaders(),'Content-Type':'application/json'},body:JSON.stringify({reserva_id:id, motivo:motivo.trim()})});
  const j = await r.json();
  if(!j.ok){ alert(j.error||'No se pudo cancelar'); return; }
  loadReservas(); // recargar (usar el nombre real)
}
```
(Ajusta `loadReservas`/`authHeaders` a los nombres reales; verifica `j.ok`.)
- [ ] **Step 3: Auto-revisión** — el botón aparece solo en citas activas; pide motivo (obligatorio); manda `{reserva_id, motivo}`; recarga; JS válido.

---

## Task 4: Deploy, verificación E2E y commit

**Files:** deploy de `api.php`, `paciente-portal.html`, `medico-portal.html`.

- [ ] **Step 1: Deploy** por `pscp` a home + script base64 (instala en `/volume2/web/medicvip/` con `http:http` 644).
- [ ] **Step 2: `php -l api.php`** en el NAS → sin errores.
- [ ] **Step 3: E2E interno (curl)** — con médico #22 y pacientes de prueba `e2e_%@medicvip.test` (limpiar antes/después con el patrón de `mve2e_clean.php`). **Importante:** como el cutoff es 12 h, para probar cancelar/reprogramar hay que insertar una reserva con `inicio` **> 12 h** en el futuro (usar un slot de `horarios_disponibles`, que ya son ≥ hoy; elegir uno a > 12 h — o insertar directo por SQL un `inicio` a +2 días). Casos:
  1. Reservar A (slot > 12 h) → `paciente_cancelar_reserva` con token A → `ok`; verificar en DB `estado_consulta='cancelada'`, `estado_pago='reembolsado'` (si era en_custodia), y una fila en `transacciones` tipo `reembolso`; y que `horarios_disponibles` vuelve a ofrecer ese `inicio`.
  2. Reservar A (slot > 12 h) e **insertar por SQL** un `inicio` a **+3 h** (dentro de 12 h) → `paciente_cancelar_reserva` → error "menos de 12 horas".
  3. Reservar A (slot > 12 h) → `paciente_reprogramar_reserva` a otro slot libre → `ok`; verificar `inicio` nuevo, `estado_consulta='agendada'`, `confirmada_en=NULL`; el slot viejo se libera y el nuevo se ocupa; reprogramar a un `inicio` ocupado → error.
  4. Confirmar una reserva (`confirmar_consulta` con token médico) → luego `horarios_disponibles` **no** ofrece ese `inicio` (fix de ocupación: confirmada ocupa el slot).
  5. `medico_cancelar_reserva` sin motivo → error; con motivo → `ok`, `estado_consulta='cancelada'`, notas contienen el motivo.
  6. Limpiar datos de prueba.
- [ ] **Step 4: Verificación UI** — cargar `https://medicvip.org` (login de paciente de prueba) y confirmar que en "Mis reservas" aparecen los botones Cancelar/Reprogramar en una cita > 12 h; en el portal médico, el botón Cancelar en una cita activa. (read_page/console; screenshots pueden timeoutear.)
- [ ] **Step 5: Commit y push** de `api.php`, `paciente-portal.html`, `medico-portal.html` + actualizar README (línea de la feature).

---

## Criterios de aceptación (del spec)
1. Paciente cancela cita > 12 h → `cancelada` + reembolso + transacción + horario liberado.
2. Cancelar/reprogramar con < 12 h → rechazado con mensaje.
3. Paciente reprograma → libera viejo, ocupa nuevo, vuelve a `agendada` (+24 h), pago conservado (pagado→en_custodia).
4. Médico cancela con motivo → paciente reembolsado + horario liberado.
5. Cita `confirmada` ocupa su horario; canceladas/no-realizadas fuera de la agenda.
6. Reprogramar a horario ocupado/bloqueado/pasado/fuera de ventana → rechazado.
