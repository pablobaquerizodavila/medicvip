# Agenda por fechas — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** Agendamiento por fecha/hora concreta (no slots semanales), calendario semanal para el médico, y bloqueos de fechas.

**Architecture:** `reservas.inicio` (datetime) + tabla `medico_bloqueos`. La plantilla `medico_disponibilidad` (semanal) se proyecta a fechas reales (4 semanas). Duración de cada cita = `medico_pago.duracion_minutos` (ya configurable en el perfil). Todo vanilla PHP + HTML/JS inline.

**Entorno NAS (igual que planes previos):** `plink -pw "<pass>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" pbaquerizo@192.168.0.116`, técnica base64, `sudo -S`, PHP como http `sudo -u http /usr/local/bin/php82`, API interna `curl -H 'Host: medicvip.org' http://127.0.0.1/api.php`, deploy `pscp` (644), `php -l` en el NAS.

**Plantillas a espejar:** `medico-portal.html` (secciones/nav/modales), `pacientes.html` (flujo de reserva actual).

---

## Task 1: Migración DB + limpieza

**Files:** migración NAS + `schema.sql`.

- [ ] **Step 1: Migración (base `mediconline`)**
```sql
ALTER TABLE `reservas` ADD COLUMN `inicio` datetime DEFAULT NULL, ADD KEY `idx_medico_inicio` (`medico_id`,`inicio`);
CREATE TABLE IF NOT EXISTS `medico_bloqueos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `medico_id` int(10) unsigned NOT NULL,
  `fecha_desde` date NOT NULL,
  `fecha_hasta` date NOT NULL,
  `motivo` varchar(200) DEFAULT NULL,
  `creado_en` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `idx_medico` (`medico_id`),
  CONSTRAINT `fk_bloqueo_medico` FOREIGN KEY (`medico_id`) REFERENCES `medicos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
- [ ] **Step 2: Limpiar reservas legadas** (test data sin fecha): `DELETE FROM reservas WHERE inicio IS NULL;`
- [ ] **Step 3: Verificar** — `SHOW COLUMNS FROM reservas LIKE 'inicio';`, `SHOW TABLES LIKE 'medico_bloqueos';`, `SELECT COUNT(*) FROM reservas;` (debe quedar en 0 tras la limpieza).
- [ ] **Step 4: Reflejar en `schema.sql`** (reservas.inicio + medico_bloqueos) y commit.

---

## Task 2: Backend

**Files:** `api.php`.

- [ ] **Step 1: Insertar helper + endpoints** (zona de funciones):
```php
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
    $so = $db->prepare("SELECT inicio FROM reservas WHERE medico_id=? AND estado_consulta='agendada' AND inicio IS NOT NULL");
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
    $sc = $db->prepare("SELECT r.id AS reserva_id, r.inicio, r.paciente_id, p.nombre AS paciente, r.estado_consulta, r.motivo FROM reservas r JOIN pacientes p ON p.id=r.paciente_id WHERE r.medico_id=? AND r.inicio IS NOT NULL AND DATE(r.inicio) BETWEEN ? AND ? ORDER BY r.inicio");
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
```

- [ ] **Step 2: `crearReserva` — reservar por `inicio`**
  a) Cambiar la validación de campos requeridos de `['medico_id','horario','metodo_pago']` a `['medico_id','inicio','metodo_pago']`.
  b) Reemplazar el chequeo de doble-reserva por string (el bloque `// No permitir doble reserva ... AND horario=? AND estado_consulta='agendada' ...`) por la validación de `inicio`:
```php
    $inicioVal = (string)($data['inicio'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $inicioVal)) throw new Exception('Fecha/hora inválida.');
    $ its = strtotime($inicioVal);
    if ($its === false || $its <= time()) throw new Exception('Ese horario ya pasó, elige otro.');
    if ($its > strtotime('+29 days')) throw new Exception('Solo puedes agendar hasta 4 semanas adelante.');
    $fechaCita = substr($inicioVal, 0, 10);
    if (fetchOne(query('SELECT id FROM medico_bloqueos WHERE medico_id=? AND ? BETWEEN fecha_desde AND fecha_hasta LIMIT 1','is',[(int)$data['medico_id'],$fechaCita])))
        throw new Exception('Ese día no está disponible.');
    if (fetchOne(query("SELECT id FROM reservas WHERE medico_id=? AND inicio=? AND estado_consulta='agendada' LIMIT 1",'is',[(int)$data['medico_id'],$inicioVal])))
        throw new Exception('Ese horario ya fue tomado. Elige otro.');
    $_dAbr = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie','Sat'=>'Sáb','Sun'=>'Dom'];
    $data['horario'] = $_dAbr[date('D',$its)].' '.date('d/m',$its).' '.date('H:i',$its);
```
  (corrige el typo: es `$its`, no `$ its`.)
  c) En el `INSERT INTO reservas`, agregar la columna `inicio` y su valor. Reemplazar el prepare + bind por:
```php
    $stmt = $db->prepare('INSERT INTO reservas (medico_id,paciente_id,horario,inicio,motivo,alergias,metodo_pago,monto_total,comision,monto_medico,estado_pago,limite_confirmacion,sala_video,token_acceso) VALUES (?,?,?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR),?,?)');
    if (!$stmt) { if ($esCortesia) $db->rollback(); throw new Exception('Prepare reservas: '.$db->error); }
    $stmt->bind_param('iisssssdddsss',$medicoId,$pacienteId,$data['horario'],$inicioVal,$motivo,$alergias,$data['metodo_pago'],$total,$comision,$neto,$estadoPago,$salaVideo,$tokenAcceso);
```
  (14 columnas, `limite_confirmacion` es DATE_ADD → 13 placeholders `?`; bind `'iisssssdddsss'` = 13 tipos. Verificar el orden: medico_id,paciente_id,horario,inicio,motivo,alergias,metodo_pago,monto_total,comision,monto_medico,estado_pago,sala_video,token_acceso.)

- [ ] **Step 3: `listarMedicos` — quitar filtro viejo + próximo disponible**
  - Quitar el bloque `$sh = ...` y las 2 líneas del `foreach` que filtraban `disponibilidad` por horarios ocupados (agregadas antes). `disponibilidad` vuelve a ser la plantilla completa.
  - En el `foreach ($medicos as &$m)`, agregar: `$sl=generarSlotsDisponibles($m['id'],28); $m['proximo_disponible']=$sl?$sl[0]['label']:null;`

- [ ] **Step 4: Casos en el switch**
```php
        case 'horarios_disponibles':    horariosDisponibles();    break;
        case 'medico_agenda':           medicoAgenda();           break;
        case 'medico_bloqueo_crear':    medicoBloqueoCrear();     break;
        case 'medico_bloqueo_eliminar': medicoBloqueoEliminar();  break;
```

- [ ] **Step 5: Auto-revisión** — binds: `medicoBloqueoCrear` `'isss'`(4); `crearReserva` INSERT `'iisssssdddsss'`(13). `generarSlotsDisponibles` mapea `date('N')`→día con acentos. `medicoAgenda` calcula lunes–domingo. 4 `case`. Llaves balanceadas.

---

## Task 3: `pacientes.html` — selector de horarios con fecha

**Files:** `pacientes.html`.

- [ ] **Step 1: Card del médico** — reemplazar el bloque de `dc-slots` (que mapea `d.slots` a `slot-btn` con `pickSlot`) por un texto "Próximo disponible: {d.proximo_disponible || 'Consultar'}" (usar el campo nuevo `proximo_disponible` que ahora trae `listar_medicos`; agregarlo al map de `d` en `DOCTORS`, línea ~341). El botón "Agendar →" (`openBooking`) se conserva. Quitar `pickSlot`/`selectedSlots` (o dejarlos sin uso).

- [ ] **Step 2: `openBooking(docId)` — cargar slots con fecha** — hacerla `async`: al abrir, `POST /api.php?action=horarios_disponibles {medico_id: docId}`, obtener `slots`. Si vacío → mostrar en el modal "Este médico no tiene horarios disponibles en las próximas 4 semanas." Si hay, renderizar en el modal un **selector de slots agrupado por día**: agrupar `slots` por `fecha`, y por cada día un encabezado (usar `slots[i].label` da día+fecha+hora; para agrupar, usar `fecha` y mostrar el primer `label` sin la hora, o construir encabezado con día/fecha) y chips de horas (cada chip = un slot, texto `hora–fin`, `onclick="pickSlotFecha('<inicio>','<label>',this)"`). Mantener debajo el resto del modal (motivo, método de pago, cortesía). Un `<div id="slot-elegido">` muestra el slot elegido.
  - Estado: `let _slotInicio=null;` `function pickSlotFecha(inicio,label,el){ _slotInicio=inicio; document.querySelectorAll('.slot-btn').forEach(b=>b.classList.remove('picked')); el.classList.add('picked'); document.getElementById('slot-elegido').textContent='Seleccionado: '+label; _actualizarBotonesPago&&_actualizarBotonesPago(); }`
  - Al inicio de `openBooking`, mantener el **gate de login** (si `!PACIENTE_TOKEN` → redirigir) ANTES de cargar slots.

- [ ] **Step 3: Confirmar con `inicio`** — en `_doConfirmBooking` (y donde arme el body de `reservar`), reemplazar `horario: slot` por `inicio: _slotInicio`, y validar que `_slotInicio` no sea null (si lo es, alert "Elige un horario"). En `confirmBookingDesdeStep1`/`confirmCortesia`/`confirmBooking`/`showPaymentStep` el parámetro `slot` deja de venir del string; usar `_slotInicio`. El botón de confirmar debe estar deshabilitado hasta que haya `_slotInicio` (similar a la validación de cortesía).
  - En la pantalla de éxito, mostrar el `label` del slot en vez de `slot`.

- [ ] **Step 4: Auto-revisión** — sin sesión el gate corta; se cargan slots con fecha; elegir uno habilita confirmar; se envía `inicio`; el JS compila (`new Function`).

---

## Task 4: `medico-portal.html` — sección Agenda (calendario semanal + bloqueos)

**Files:** `medico-portal.html`.

- [ ] **Step 1: Nav + sección** — nav-item "📅 Agenda" → `showSection('agenda',this); loadAgenda()`. Sección `<section id="section-agenda" class="section">` con: encabezado (page-title/page-sub), controles de navegación (← / rango de fechas / →), botón "🚫 Bloquear días", un `<div id="agenda-grid"></div>` y un `<div id="agenda-bloqueos"></div>`.
- [ ] **Step 2: JS** (usar `authHeaders()` = X-Medico-Token):
  - `let _agSemana=0;` `async function loadAgenda(){ POST medico_agenda {semana:_agSemana} → data → renderAgenda(data) }`.
  - `renderAgenda(data)`: construir un **grid de 7 columnas** (Lun→Dom, fechas `data.desde`..`data.hasta`). Para cada día: los slots de `data.plantilla` cuyo `dia_semana` corresponda a ese día; por cada slot (hora) calcular la celda:
    - si hay una `cita` en `data.citas` con `DATE(inicio)` = esa fecha y hora de `inicio` = esa hora → **Reservado**: mostrar hora–fin + nombre del paciente + botón "Expediente" (`window.open('/expediente-paciente.html?paciente='+c.paciente_id)`).
    - si la fecha cae en un `data.bloqueos` rango → **Bloqueado** (celda gris).
    - si no → **Libre** (celda tenue) con el rango hora–fin (usar `data.duracion`).
    - Colores: reservado azul, bloqueado gris, libre borde tenue.
  - Navegación: `function agSemana(delta){ _agSemana+=delta; loadAgenda(); }` para los botones ← →.
  - `renderBloqueos(data.bloqueos)`: lista de bloqueos con "Quitar" → `medico_bloqueo_eliminar {bloqueo_id}` → recargar.
  - Modal "Bloquear días": inputs fecha_desde, fecha_hasta, motivo → `medico_bloqueo_crear` → recargar. (Usar el patrón de modal existente del portal, `mostrarModalPortal` si existe, o un overlay simple.)
- [ ] **Step 3: Auto-revisión** — el grid muestra 7 días con estados correctos; navegar semanas funciona; bloquear/quitar recarga; JS compila.

---

## Task 5: `index.html` — próximo disponible

**Files:** `index.html`.

- [ ] **Step 1:** En el render de médicos destacados (`loadDestacados`), donde se muestran horarios/slots, mostrar "Próximo disponible: {m.proximo_disponible || 'Consultar'}" usando el campo nuevo de `listar_medicos`. Quitar cualquier render de slots semanales viejo si lo hubiera.

---

## Task 6: Deploy, verificación E2E y commit

**Files:** deploy de `api.php`, `pacientes.html`, `medico-portal.html`, `index.html`.

- [ ] **Step 1: Deploy** por `pscp` + `schema.sql` commit.
- [ ] **Step 2: `php -l api.php`** → sin errores.
- [ ] **Step 3: Verificación E2E (curl interno)** — con médico #22 (tiene disponibilidad Lunes 16:00, Martes 19:00, Miércoles 19:00):
  1. `horarios_disponibles {medico_id:22}` → devuelve slots con fecha/label (rango hora–fin), ordenados, sin pasados.
  2. Registrar paciente de prueba + login → token.
  3. `reservar` con `{medico_id:22, inicio:'<un inicio de la lista>', motivo, metodo_pago:'card'}` → ok (reserva con inicio).
  4. `horarios_disponibles {medico_id:22}` de nuevo → ese `inicio` ya NO aparece (ocupado); pero la misma hora la **semana siguiente** SÍ aparece.
  5. Reservar el mismo `inicio` con otro paciente → rechazo "ya fue tomado".
  6. `medico_bloqueo_crear` que cubra una fecha con slot → `horarios_disponibles` ya no ofrece esa fecha. `medico_bloqueo_eliminar` → vuelve a ofrecerla.
  7. Login médico → `medico_agenda {semana:0}` → estructura con la cita creada.
  8. Limpiar datos de prueba (reserva, paciente, bloqueo).
- [ ] **Step 4: Verificación UI** — paciente: al agendar ve slots con fecha, elige uno, reserva; médico: sección Agenda muestra el grid semanal con la cita, navega semanas, bloquea/quita días.
- [ ] **Step 5: Commit y push** de todo + `schema.sql`.

---

## Criterios de aceptación (del spec)
1. Paciente ve horarios con fecha (4 semanas), agrupados por día; agenda uno concreto (rango hora–fin).
2. La misma hora semanal se reabre en semanas distintas.
3. Dos reservas al mismo `inicio` no coexisten.
4. Médico ve calendario semanal navegable con citas/libres/bloqueados.
5. Médico bloquea/desbloquea rangos de fechas.
6. Slot pasado, fuera de ventana o bloqueado → no reservable.
