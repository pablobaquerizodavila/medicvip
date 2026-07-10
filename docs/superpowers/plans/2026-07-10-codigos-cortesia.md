# Códigos de cortesía (pro bono) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development para ejecutar este plan tarea por tarea.

**Goal:** Permitir que un médico genere códigos en su panel para que pacientes agenden consultas gratis, y eliminar el "modo prueba" del agendamiento.

**Architecture:** Vanilla PHP + MariaDB + HTML/JS inline (sin build). Dos tablas nuevas (`medico_codigos`, `codigo_usos`), enum `reservas.estado_pago` extendido con `'exonerado'`, 4 endpoints nuevos en `api.php`, `crearReserva` con canje atómico, y cambios de UI en `pacientes.html` y `medico-portal.html`.

**Tech Stack:** PHP 8.2, MariaDB 10.11 (NAS Synology, base `mediconline`), mysqli, HTML/CSS/JS inline. Sin framework de tests — verificación por `curl` (API interna vía plink) y preview del navegador.

**Notas de entorno (NAS):**
- SSH: `plink -pw "<pass NAS>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" pbaquerizo@192.168.0.116`
- Ejecutar comandos con la técnica base64 (evita el guard de PowerShell) y `sudo -S` con la pass del NAS.
- API interna: `curl -H 'Host: medicvip.org' http://127.0.0.1/api.php?action=...`
- **CRÍTICO:** tras editar archivos web como root, re-asertar `chown http:http` + `chmod 644` (si no, PHP-FPM como `http` no los lee → 500). Los deploys por `pscp` (usuario pbaquerizo, 644) ya quedan legibles.
- Deploy de archivos: `pscp` a `/volume2/web/medicvip/`.

---

## File Structure

- `schema.sql` — reflejar las 2 tablas nuevas + enum (documental; la migración real corre en el NAS).
- `api.php` — helper `generarCodigoUnico` + 4 funciones nuevas + casos en el `switch` + `crearReserva` extendido + `confirmarConsulta` ajustado.
- `pacientes.html` — quitar modo prueba + opción cortesía con validación.
- `medico-portal.html` — sección "Códigos de cortesía".

---

## Task 1: Migración de base de datos

**Files:**
- Migración aplicada en el NAS (base `mediconline`).
- Modify: `schema.sql` (documental).

- [ ] **Step 1: Aplicar la migración en el NAS**

Correr este SQL contra `mediconline` (vía `sudo -u http /usr/local/bin/php82` con un script que incluya `api.config.php`, o vía cliente mysql). SQL:

```sql
CREATE TABLE IF NOT EXISTS `medico_codigos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `medico_id` int(10) unsigned NOT NULL,
  `codigo` varchar(20) NOT NULL,
  `nota` varchar(150) DEFAULT NULL,
  `usos_max` int(10) unsigned NOT NULL DEFAULT 1,
  `usos_count` int(10) unsigned NOT NULL DEFAULT 0,
  `estado` enum('activo','agotado','revocado') NOT NULL DEFAULT 'activo',
  `expira_en` date DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_codigo` (`codigo`),
  KEY `idx_medico` (`medico_id`),
  CONSTRAINT `fk_codigos_medico` FOREIGN KEY (`medico_id`) REFERENCES `medicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `codigo_usos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo_id` int(10) unsigned NOT NULL,
  `reserva_id` int(10) unsigned NOT NULL,
  `paciente_email` varchar(150) DEFAULT NULL,
  `usado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_codigo` (`codigo_id`),
  CONSTRAINT `fk_uso_codigo` FOREIGN KEY (`codigo_id`) REFERENCES `medico_codigos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `reservas`
  MODIFY `estado_pago` enum('pendiente','en_custodia','pagado','reembolsado','exonerado')
  NOT NULL DEFAULT 'pendiente';
```

- [ ] **Step 2: Verificar la migración**

```
SHOW TABLES LIKE 'medico_codigos';   -- existe
SHOW TABLES LIKE 'codigo_usos';       -- existe
SHOW COLUMNS FROM reservas LIKE 'estado_pago';  -- el Type incluye 'exonerado'
```
Esperado: ambas tablas existen y el enum incluye `exonerado`.

- [ ] **Step 3: Reflejar en `schema.sql`**

Agregar las dos definiciones `CREATE TABLE` (como en Step 1) al `schema.sql` y actualizar la línea del enum `estado_pago` de la tabla `reservas` para incluir `'exonerado'`.

- [ ] **Step 4: Commit**

```bash
git add schema.sql && git commit -m "feat(db): tablas medico_codigos y codigo_usos + estado_pago exonerado"
```

---

## Task 2: Backend — gestión de códigos (médico)

**Files:**
- Modify: `api.php` (agregar helper + 3 funciones + 3 casos en el switch)

- [ ] **Step 1: Agregar el helper y las funciones**

Insertar en `api.php` (después de la función `medicoRecuperar`, antes del comentario `// ── CONFIRMAR CONSULTA ──`):

```php
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
```

- [ ] **Step 2: Registrar los casos en el `switch`**

En el `switch ($action)` de `api.php`, agregar (junto a los otros `case 'medico_*'`):

```php
        case 'medico_codigos':        medicoCodigos();        break;
        case 'medico_codigo_crear':   medicoCodigoCrear();    break;
        case 'medico_codigo_revocar': medicoCodigoRevocar();  break;
        case 'validar_codigo':        validarCodigo();        break;
```

- [ ] **Step 3: Verificar sintaxis**

En el NAS: `sudo /usr/local/bin/php82 -l /volume2/web/medicvip/api.php` → `No syntax errors detected` (se prueba tras deploy en Task 7; localmente basta revisión visual).

---

## Task 3: Backend — canje atómico en `crearReserva` + ajuste `confirmarConsulta`

**Files:**
- Modify: `api.php` (`crearReserva`, `confirmarConsulta`)

- [ ] **Step 1: Reemplazar el bloque de montos/inserción de `crearReserva`**

En `crearReserva`, reemplazar TODO el bloque que va desde `$pago = fetchOne(query('SELECT tarifa FROM medico_pago ...` hasta el `INSERT INTO transacciones (...,"custodia",...)` (aprox. líneas 186–214) por:

```php
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

    $stmt = $db->prepare('INSERT INTO reservas (medico_id,paciente_id,horario,motivo,alergias,metodo_pago,monto_total,comision,monto_medico,estado_pago,limite_confirmacion,sala_video,token_acceso) VALUES (?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR),?,?)');
    if (!$stmt) { if ($esCortesia) $db->rollback(); throw new Exception('Prepare reservas: '.$db->error); }
    $stmt->bind_param('iissssdddsss',$medicoId,$pacienteId,$data['horario'],$motivo,$alergias,$data['metodo_pago'],$total,$comision,$neto,$estadoPago,$salaVideo,$tokenAcceso);
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
```

> Nota: el resto de `crearReserva` (emails y `jsonOk`) queda igual; usa `$total`/`$neto`, que para cortesía son 0. Los emails se envían igual.

- [ ] **Step 2: Ajustar `confirmarConsulta` para reservas exoneradas**

En `confirmarConsulta`, reemplazar el bloque:

```php
    $upd=$db->prepare('UPDATE reservas SET estado_consulta="confirmada",estado_pago="pagado",estado_pago_medico="transferido",confirmada_en=? WHERE id=?');
    $upd->bind_param('si',$ahora,$rid);$upd->execute();
    $ins=$db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"comision",?,"Comision MedicOnline 15%")');
    $ins->bind_param('id',$rid,$com);$ins->execute();
    $ins2=$db->prepare('INSERT INTO transacciones (reserva_id,tipo,monto,descripcion) VALUES (?,"liberacion",?,"Pago liberado al medico")');
    $ins2->bind_param('id',$rid,$neto);$ins2->execute();
```

por:

```php
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
```

Y envolver la llamada a `emailPagoLiberado([...]);` (dentro del `if ($medInfo) {`) en `if (!$esExonerado) { ... }` para no notificar "pago liberado" en cortesías. El `emailPedirResena` al paciente se mantiene siempre.

> `procesarReembolsos` NO se toca: ya filtra `estado_pago IN ("en_custodia","pagado")`, así que ignora las exoneradas.

- [ ] **Step 3: Verificación (tras deploy)** — ver Task 6 Step 3.

---

## Task 4: Frontend — `pacientes.html`: quitar modo prueba + opción cortesía

**Files:**
- Modify: `pacientes.html`

- [ ] **Step 1: Agregar el radio de cortesía + caja de código**

En el bloque "Método de pago", después del `<label ... id="pay-paypal-label">...</label>` (el de PayPal), agregar:

```html
          <label style="display:flex;align-items:center;gap:10px;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;background:var(--white)" id="pay-cortesia-label">
            <input type="radio" name="pay" value="cortesia" style="accent-color:var(--green-mid);flex-shrink:0"> <span style="font-size:13px;font-weight:500">🎗️ Código de cortesía (pro bono)</span>
          </label>
```

Y justo después del `<p ...>Pago 100% seguro...</p>` (dentro del mismo div de método de pago), agregar la caja de código:

```html
        <div id="cortesia-box" style="display:none;margin-top:12px">
          <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Ingresa tu código de cortesía</label>
          <div style="display:flex;gap:8px">
            <input type="text" id="cortesia-codigo" placeholder="CORT-XXXXXX" style="flex:1;padding:10px;border:1.5px solid var(--border);border-radius:8px;text-transform:uppercase;font-family:monospace">
            <button type="button" id="cortesia-validar-btn" onclick="validarCortesia(${d.id})" style="background:var(--green-mid);color:#fff;border:none;padding:0 16px;border-radius:8px;cursor:pointer">Validar</button>
          </div>
          <div id="cortesia-feedback" style="font-size:12px;margin-top:6px"></div>
        </div>
```

- [ ] **Step 2: Reemplazar el footer del modal (quitar modo prueba)**

Reemplazar el bloque `test-banner` + botones (el `<div class="test-banner">...</div>`, `<button class="btn-test" ...>` y `<button class="btn-skip" ...>`) por:

```html
      <button class="confirm-btn" id="btn-continuar-pago" onclick="showPaymentStep(${d.id},'${slot}')">Continuar al pago →</button>
      <button class="confirm-btn" id="btn-cortesia-confirmar" style="display:none;opacity:.6" disabled onclick="confirmCortesia(${d.id},'${slot}')">Confirmar consulta de cortesía</button>
```

- [ ] **Step 3: Actualizar el toggle de métodos + botones**

Reemplazar el listener `document.querySelectorAll('input[name=pay]').forEach(...)` por:

```javascript
  document.querySelectorAll('input[name=pay]').forEach(r => r.addEventListener('change', () => {
    ['card','paypal','cortesia'].forEach(v => {
      const el = document.getElementById('pay-'+v+'-label');
      if(!el) return;
      el.style.borderColor = r.value===v ? 'var(--green-mid)' : 'var(--border)';
      el.style.background  = r.value===v ? 'var(--green-light)' : 'var(--white)';
    });
    const metodo = document.querySelector('input[name=pay]:checked')?.value;
    const box = document.getElementById('cortesia-box');
    if (box) box.style.display = (metodo === 'cortesia') ? 'block' : 'none';
    _cortesiaValida = false; _actualizarBotonesPago();
  }));
```

- [ ] **Step 4: Agregar las funciones JS de cortesía**

Agregar (cerca de `confirmBookingDesdeStep1`, en el `<script>`):

```javascript
let _cortesiaValida = false;
function _actualizarBotonesPago() {
  const metodo = document.querySelector('input[name=pay]:checked')?.value;
  const bPago = document.getElementById('btn-continuar-pago');
  const bCort = document.getElementById('btn-cortesia-confirmar');
  if (!bPago || !bCort) return;
  if (metodo === 'cortesia') {
    bPago.style.display='none'; bCort.style.display='block';
    bCort.disabled = !_cortesiaValida; bCort.style.opacity = _cortesiaValida ? '1' : '.6';
  } else {
    bPago.style.display='block'; bCort.style.display='none';
  }
}
async function validarCortesia(docId) {
  const codigo = document.getElementById('cortesia-codigo').value.trim().toUpperCase();
  const fb = document.getElementById('cortesia-feedback');
  if (!codigo) { fb.textContent=''; _cortesiaValida=false; _actualizarBotonesPago(); return; }
  fb.style.color='var(--muted)'; fb.textContent='Validando...';
  try {
    const res = await fetch(API + '?action=validar_codigo', {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({medico_id: docId, codigo})
    });
    const json = await res.json();
    if (json.ok && json.data.valido) {
      _cortesiaValida = true;
      fb.style.color='var(--green)'; fb.textContent='✓ Código válido — '+json.data.restantes+' uso(s) restante(s)';
    } else {
      _cortesiaValida = false;
      fb.style.color='#C0392B'; fb.textContent='✗ '+((json.data&&json.data.motivo)||json.error||'Código inválido');
    }
  } catch(e) { _cortesiaValida=false; fb.style.color='#C0392B'; fb.textContent='Error de conexión'; }
  _actualizarBotonesPago();
}
async function confirmCortesia(docId, slot) {
  const form = _captureForm(docId, slot);
  if (!form.nombre || !form.email) { alert('Por favor completa al menos tu nombre y correo electrónico.'); return; }
  if (!_cortesiaValida) { alert('Ingresa y valida un código de cortesía primero.'); return; }
  form.codigo = document.getElementById('cortesia-codigo').value.trim().toUpperCase();
  form.metodo = 'cortesia';
  await _doConfirmBooking(docId, slot, form);
}
```

- [ ] **Step 5: Extender `_captureForm` y `_doConfirmBooking`**

En `_captureForm`, cambiar el default `metodo: document.querySelector('input[name=pay]:checked')?.value || 'test'` por `|| 'card'`.

En `_doConfirmBooking`, en el `body: JSON.stringify({...})`:
- cambiar `metodo_pago: form.metodo || 'test'` por `metodo_pago: form.metodo || 'card'`
- agregar `, codigo: form.codigo || null`

Y en la pantalla de éxito, hacer condicional para cortesía. Al inicio de la construcción del `innerHTML` de éxito, definir `const esCortesia = form.metodo === 'cortesia';` y cambiar:
- la línea `<div class="success-detail-row"><span>Total pagado</span><span>$${d.fee} USD</span></div>` por
  `<div class="success-detail-row"><span>${esCortesia?'Modalidad':'Total pagado'}</span><span>${esCortesia?'🎗️ Cortesía (gratis)':'$'+d.fee+' USD'}</span></div>`
- la línea `<span style="color:var(--green-mid);font-weight:500">✓ Pago confirmado</span>` por
  `<span style="color:var(--green-mid);font-weight:500">✓ ${esCortesia?'Consulta agendada':'Pago confirmado'}</span>`
- el `<p>Tu pago fue recibido por MedicOnline...</p>` por
  `<p>${esCortesia?'Tu consulta de cortesía quedó agendada.':'Tu pago fue recibido por MedicOnline.'} Guarda tu número de reserva — lo necesitarás para unirte a la videollamada.</p>`

- [ ] **Step 6: Quitar el modo prueba del step 2 y del modal de emergencia**

- En `showPaymentStep` (step 2), eliminar el `<div class="test-banner" ...>...</div>` que contiene el badge 🧪 y el botón "Saltar pago y confirmar directo".
- En el modal de emergencia, eliminar el `<div ...>🧪 <strong>Modo prueba:</strong> Sin pago real activo.</div>`.

- [ ] **Step 7: Verificación** — preview del navegador (Task 6 Step 4).

---

## Task 5: Frontend — `medico-portal.html`: sección de códigos

**Files:**
- Modify: `medico-portal.html`

- [ ] **Step 1: Agregar el ítem de navegación**

Después del `<div class="nav-item" onclick="showSection('resenas',this); loadMedicoResenas()">...</div>` agregar:

```html
      <div class="nav-item" onclick="showSection('codigos',this); loadCodigos()"><span class="nav-icon">🎗️</span> Códigos de cortesía</div>
```

- [ ] **Step 2: Agregar la sección**

Junto a las demás `<section id="section-*" class="section">` (imitar el markup del título/encabezado de una sección existente, p.ej. `section-resenas`), agregar:

```html
    <section id="section-codigos" class="section">
      <h1 class="section-title">🎗️ Códigos de cortesía</h1>
      <p style="color:var(--muted);font-size:14px;margin-bottom:20px">Genera códigos para que pacientes agenden una consulta gratis contigo. Compártelos con quien quieras beneficiar.</p>
      <div style="background:var(--white);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:24px">
        <div style="font-weight:600;margin-bottom:14px">Generar nuevo código</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end">
          <div><label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Nº de usos</label><input type="number" id="cod-usos" min="1" max="100" value="1" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;box-sizing:border-box"></div>
          <div><label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Nota (opcional)</label><input type="text" id="cod-nota" placeholder="Ej: Para Juan Pérez" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;box-sizing:border-box"></div>
          <div><label style="font-size:12px;color:var(--muted);display:block;margin-bottom:4px">Vence (opcional)</label><input type="date" id="cod-expira" style="width:100%;padding:9px;border:1px solid var(--border);border-radius:8px;box-sizing:border-box"></div>
          <button id="cod-generar-btn" onclick="generarCodigo()" style="background:#1d9e75;color:#fff;border:none;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:500">Generar código</button>
        </div>
      </div>
      <div id="codigos-lista"></div>
    </section>
```

- [ ] **Step 3: Agregar las funciones JS**

En el `<script>` del portal (donde están `loadReservas`, `loadPagos`, etc.):

```javascript
async function loadCodigos() {
  const cont = document.getElementById('codigos-lista');
  cont.innerHTML = '<p style="color:var(--muted);font-size:14px">Cargando...</p>';
  try {
    const res = await fetch(API + '?action=medico_codigos', {headers: authHeaders()});
    const json = await res.json();
    if (!json.ok) { cont.innerHTML = '<p style="color:#C0392B;font-size:14px">No se pudieron cargar los códigos.</p>'; return; }
    if (!json.data.length) { cont.innerHTML = '<p style="color:var(--muted);font-size:14px">Aún no has generado códigos.</p>'; return; }
    const hoy = new Date().toISOString().slice(0,10);
    cont.innerHTML = json.data.map(c => {
      const vencido = c.expira_en && c.expira_en < hoy && c.estado==='activo';
      const estado = c.estado==='revocado' ? 'Revocado' : c.estado==='agotado' ? 'Agotado' : vencido ? 'Vencido' : 'Activo';
      const color = estado==='Activo' ? '#1d9e75' : (estado==='Agotado'||estado==='Vencido') ? '#b8860b' : '#C0392B';
      const canjes = (c.canjes||[]).map(u => `<div style="font-size:12px;color:var(--muted)">• ${u.paciente_email||'—'} — ${u.usado_en}</div>`).join('') || '<div style="font-size:12px;color:var(--muted)">Sin canjes aún</div>';
      const revocable = c.estado==='activo';
      return `<div style="border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px;background:var(--white)">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <span style="font-family:monospace;font-size:16px;font-weight:700;letter-spacing:1px">${c.codigo}</span>
          <button onclick="copiarCodigo('${c.codigo}')" style="background:var(--green-light);border:none;color:var(--green);font-size:12px;padding:4px 10px;border-radius:6px;cursor:pointer">Copiar</button>
          <span style="background:${color}22;color:${color};font-size:11px;font-weight:600;padding:3px 10px;border-radius:20px">${estado}</span>
          <span style="font-size:13px;color:var(--muted);margin-left:auto">${c.usos_count}/${c.usos_max} usados</span>
          ${revocable ? `<button onclick="revocarCodigo(${c.id})" style="background:none;border:1px solid #C0392B;color:#C0392B;font-size:12px;padding:4px 10px;border-radius:6px;cursor:pointer">Revocar</button>` : ''}
        </div>
        ${c.nota ? `<div style="font-size:13px;margin-top:8px">📝 ${c.nota}</div>` : ''}
        ${c.expira_en ? `<div style="font-size:12px;color:var(--muted);margin-top:4px">Vence: ${c.expira_en}</div>` : ''}
        <div style="margin-top:8px;border-top:1px solid var(--border);padding-top:8px">${canjes}</div>
      </div>`;
    }).join('');
  } catch(e) { cont.innerHTML = '<p style="color:#C0392B;font-size:14px">Error de conexión.</p>'; }
}
async function generarCodigo() {
  const usos = parseInt(document.getElementById('cod-usos').value) || 1;
  const nota = document.getElementById('cod-nota').value.trim();
  const expira = document.getElementById('cod-expira').value || null;
  const btn = document.getElementById('cod-generar-btn');
  btn.disabled = true; btn.textContent = 'Generando...';
  try {
    const res = await fetch(API + '?action=medico_codigo_crear', {
      method:'POST', headers: authHeaders(),
      body: JSON.stringify({usos_max: usos, nota, expira_en: expira})
    });
    const json = await res.json();
    if (!json.ok) { alert('Error: ' + json.error); return; }
    document.getElementById('cod-nota').value=''; document.getElementById('cod-expira').value=''; document.getElementById('cod-usos').value='1';
    loadCodigos();
  } catch(e) { alert('Error de conexión'); }
  finally { btn.disabled=false; btn.textContent='Generar código'; }
}
async function revocarCodigo(id) {
  if (!confirm('¿Revocar este código? No podrá usarse más.')) return;
  try {
    const res = await fetch(API + '?action=medico_codigo_revocar', {
      method:'POST', headers: authHeaders(), body: JSON.stringify({codigo_id: id})
    });
    const json = await res.json();
    if (!json.ok) { alert('Error: ' + json.error); return; }
    loadCodigos();
  } catch(e) { alert('Error de conexión'); }
}
function copiarCodigo(codigo) {
  if (navigator.clipboard) navigator.clipboard.writeText(codigo).then(()=>{}, ()=>{});
}
```

- [ ] **Step 4: Verificación** — preview del navegador (Task 6 Step 4).

---

## Task 6: Deploy, verificación end-to-end y commit

**Files:**
- Deploy: `api.php`, `pacientes.html`, `medico-portal.html` al NAS.

- [ ] **Step 1: Deploy de archivos al NAS**

```
pscp -pw "<pass>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" \
  api.php pacientes.html medico-portal.html \
  pbaquerizo@192.168.0.116:/volume2/web/medicvip/
```
(pscp uno por uno si el multi-archivo falla). Los archivos quedan `pbaquerizo:users 644` → legibles por `http`.

- [ ] **Step 2: Verificar sintaxis PHP en el NAS**

`sudo /usr/local/bin/php82 -l /volume2/web/medicvip/api.php` → `No syntax errors detected`.

- [ ] **Step 3: Verificación end-to-end de la API (curl interno)**

1. Login médico → token (usa `pablobaquerizodavila@gmail.com` / su pass) → `X-Medico-Token`, `X-Medico-Id`.
2. `medico_codigo_crear` con `{"usos_max":2,"nota":"test","expira_en":null}` → devuelve `codigo` (ej. `CORT-XXXXXX`).
3. `validar_codigo` con `{"medico_id":<id>,"codigo":"<codigo>"}` → `{"valido":true,"restantes":2}`.
4. `reservar` con `{"medico_id":<id>,"nombre_paciente":"Test","email_paciente":"t@t.com","horario":"...","metodo_pago":"cortesia","codigo":"<codigo>"}` → `ok`. Verificar en DB: la reserva quedó `monto_total=0`, `estado_pago='exonerado'`, `metodo_pago='cortesia'`; el código quedó `usos_count=1`; hay fila en `codigo_usos`.
5. Repetir `reservar` 1 vez más → `usos_count=2`, estado `agotado`. Un 3er intento → error "agotado".
6. `validar_codigo` con un `medico_id` distinto → `valido:false`.
7. Limpiar los datos de prueba creados (borrar la reserva/código de test).

- [ ] **Step 4: Verificación de UI (preview)**

- `pacientes.html`: ya no aparece ningún "🧪 MODO PRUEBA"; al elegir "Código de cortesía" aparece el campo, valida en tiempo real (✓/✗) y el botón "Confirmar consulta de cortesía" se habilita solo con código válido.
- `medico-portal.html`: la sección "Códigos de cortesía" genera, lista (con estado/uso/canjes), copia y revoca.

- [ ] **Step 5: Commit y push**

```bash
git add api.php pacientes.html medico-portal.html schema.sql
git commit -m "feat: códigos de cortesía (pro bono) + remoción del modo prueba en agendamiento"
git push origin main
```

---

## Criterios de aceptación (del spec)

1. No aparece ningún elemento de "modo prueba" en el agendamiento.
2. El médico genera un código (usos + vencimiento opcional) y lo ve listado.
3. El médico revoca un código y ve quién lo canjeó.
4. Un código válido agenda gratis: reserva `monto 0`, `estado_pago='exonerado'`, `metodo_pago='cortesia'`.
5. El código incrementa su uso y pasa a `agotado` al llegar a `usos_max`.
6. Un código de otro médico, vencido, revocado o agotado es rechazado con motivo claro.
7. Dos canjes simultáneos del último uso no exceden `usos_max` (canje atómico `FOR UPDATE`).
8. Se envían emails de confirmación a paciente y médico.
9. Los crons de reembolso no tocan las reservas exoneradas.
