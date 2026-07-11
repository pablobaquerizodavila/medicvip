# Editar disponibilidad + horarios de la semana — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** El médico edita su disponibilidad semanal desde la Agenda del portal, y el card público muestra todos los horarios de la semana.

**Architecture:** endpoint dedicado `medico_disponibilidad_guardar` (DELETE+INSERT de `medico_disponibilidad`) + editor grid días×bloques en la Agenda + card que agrupa `disponibilidad` por día. Vanilla PHP + HTML/JS inline.

**Entorno NAS:** `plink -pw "<pass>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" pbaquerizo@192.168.0.116`, técnica base64, PHP como http `sudo -u http /usr/local/bin/php82`, API interna `curl -H 'Host: medicvip.org' http://127.0.0.1/api.php`, deploy `pscp` a home + script base64 (instala en `/volume2/web/medicvip/` con `chown http:http` + `chmod 644`), `php -l` en el NAS.

---

## Task 1: Backend — endpoint + fix de orden

**Files:** `api.php`.

- [ ] **Step 1: Endpoint** — insertar tras `medicoBloqueoEliminar` (o junto a las funciones de médico):
```php
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
        $dia = (string)($slot['dia'] ?? '');
        $hora = substr((string)($slot['hora'] ?? ''), 0, 5);
        if (!in_array($dia, $DIAS, true) || !preg_match('/^\d{2}:\d{2}$/', $hora)) continue;
        $k = $dia.'|'.$hora; if (isset($vistos[$k])) continue; $vistos[$k] = 1;
        $ins->bind_param('iss',$medicoId,$dia,$hora); $ins->execute(); $n++;
    }
    jsonOk(['mensaje'=>'Disponibilidad actualizada','slots'=>$n]);
}
```

- [ ] **Step 2: Switch** — agregar:
```php
        case 'medico_disponibilidad_guardar': medicoDisponibilidadGuardar(); break;
```

- [ ] **Step 3: Fix de orden en `listarMedicos`** — en la query de `$sd` (línea ~181), el `ORDER BY FIELD(dia_semana,"Lunes","Martes","Miercoles","Jueves","Viernes","Sabado","Domingo")` usa "Miercoles"/"Sabado" sin acento. Cambiar a `"Miércoles"` y `"Sábado"` para que casen con el enum.

- [ ] **Step 4: Auto-revisión** — bind `medicoDisponibilidadGuardar` INSERT `'iss'`(3), DELETE `'i'`(1); dedup por día|hora; valida día del enum + hora HH:MM. 1 `case`. FIELD con acentos. Llaves balanceadas.

---

## Task 2: `medico-portal.html` — editor de disponibilidad en Agenda

**Files:** `medico-portal.html`.

LEE el archivo: la sección `section-agenda` y sus controles, `authHeaders()`, `mostrarModalPortal`/`cerrarModalPortal`, y cómo se obtiene la disponibilidad actual (el endpoint `medico_agenda` devuelve `data.plantilla` = `[{dia_semana,hora}]`; `medico_perfil` devuelve `disponibilidad` igual). El estilo de los toggles del registro está en `registro-medico.html` (clases `.time-slot`, `.sel`) — puedes copiar estilos equivalentes o definir unos simples.

- [ ] **Step 1: Constantes** (si no existen ya en el script): `const DISPO_DIAS=['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];` y `const DISPO_SLOTS=['07:00','08:00','09:00','10:00','11:00','12:00','14:00','15:00','16:00','17:00','18:00','19:00'];`.

- [ ] **Step 2: Botón** en la barra de controles de la sección Agenda: `<button class="btn" onclick="abrirEditorDispo()">⚙️ Editar mis horarios de atención</button>`.

- [ ] **Step 3: JS del editor** — pre-cargar con la disponibilidad actual. Captura la plantilla en `loadAgenda` (guarda `_agPlantilla = data.plantilla || []` al renderizar) para usarla como set inicial; si no hubiera, hace un fetch a `medico_perfil`.
```js
let _agPlantilla = [];   // set en renderAgenda: _agPlantilla = data.plantilla || [];
function abrirEditorDispo(){
  const sel = new Set((_agPlantilla||[]).map(p => p.dia_semana + '|' + String(p.hora).slice(0,5)));
  let html = '<h3 style="margin:0 0 4px">Mis horarios de atención</h3>'
           + '<p style="color:var(--muted);font-size:13px;margin:0 0 12px">Activa los bloques en que atiendes. Se ofrecerán a los pacientes al agendar.</p>'
           + '<div id="dispo-grid">';
  DISPO_DIAS.forEach(d => {
    html += '<div style="margin-bottom:10px"><div style="font-weight:600;font-size:13px;margin-bottom:4px">'+d+'</div><div style="display:flex;flex-wrap:wrap;gap:6px">';
    DISPO_SLOTS.forEach(h => {
      const on = sel.has(d+'|'+h) ? ' dispo-on' : '';
      html += '<button type="button" class="dispo-slot'+on+'" data-dia="'+d+'" data-hora="'+h+'" onclick="this.classList.toggle(\'dispo-on\')">'+h+'</button>';
    });
    html += '</div></div>';
  });
  html += '</div><div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">'
        + '<button class="btn" onclick="cerrarModalPortal()">Cancelar</button>'
        + '<button class="btn btn-green" onclick="guardarDispo()">Guardar horarios</button></div>';
  mostrarModalPortal(html);
}
async function guardarDispo(){
  const disp = [];
  document.querySelectorAll('#dispo-grid .dispo-slot.dispo-on').forEach(b => disp.push({dia:b.dataset.dia, hora:b.dataset.hora}));
  const r = await fetch(API + '?action=medico_disponibilidad_guardar', {method:'POST', headers: authHeaders(), body: JSON.stringify({disponibilidad: disp})});
  const j = await r.json();
  if(!j.ok){ alert(j.error||'No se pudo guardar'); return; }
  cerrarModalPortal(); loadAgenda();
}
```
(Ajusta `API`/`authHeaders`/`mostrarModalPortal`/`cerrarModalPortal` a los nombres reales. Si `mostrarModalPortal` no acepta HTML crudo, usa el overlay existente del portal.)

- [ ] **Step 4: Estilos** — agregar `.dispo-slot` (chip con borde) y `.dispo-slot.dispo-on` (fondo verde/activo), similares a `.time-slot`/`.sel` del registro.

- [ ] **Step 5: Auto-revisión** — `node --check` del `<script>` OK; el editor precarga los slots activos, permite togglear, guarda como `[{dia,hora}]`, cierra y recarga; `_agPlantilla` se setea en `renderAgenda`.

---

## Task 3: `pacientes.html` — card muestra horarios de la semana

**Files:** `pacientes.html`.

LEE el archivo: dónde se arma el card del médico y la línea actual "Próximo disponible: ${d.proximo_disponible...}". El objeto `d` trae `disponibilidad` = `["Lunes 16:00","Martes 19:00", ...]` (de `listar_medicos`).

- [ ] **Step 1: Helper de formato** — agregar una función que agrupe `disponibilidad` por día y devuelva HTML compacto:
```js
function horariosSemanaHtml(dispo){
  if(!dispo || !dispo.length) return 'Sin horarios publicados';
  const ORD = {'Lunes':1,'Martes':2,'Miércoles':3,'Jueves':4,'Viernes':5,'Sábado':6,'Domingo':7};
  const AB = {'Lunes':'Lun','Martes':'Mar','Miércoles':'Mié','Jueves':'Jue','Viernes':'Vie','Sábado':'Sáb','Domingo':'Dom'};
  const g = {};
  dispo.forEach(s => { const i=String(s).indexOf(' '); const d=String(s).slice(0,i); const h=String(s).slice(i+1); if(!g[d]) g[d]=[]; g[d].push(h); });
  return Object.keys(g).sort((a,b)=>(ORD[a]||9)-(ORD[b]||9))
    .map(d => (AB[d]||d)+' '+g[d].sort().join(', ')).join(' · ');
}
```
- [ ] **Step 2: Reemplazar la línea del card** — cambiar "Próximo disponible: …" por:
```
Horarios: ${horariosSemanaHtml(d.disponibilidad)}
```
(mantener el estilo/contenedor existente de esa línea).

- [ ] **Step 3: Auto-revisión** — `node --check` OK; el card muestra los horarios agrupados por día ordenados Lun→Dom; vacío → "Sin horarios publicados"; el botón Agendar y el modal no cambian.

---

## Task 4: `index.html` — card de destacados muestra horarios de la semana

**Files:** `index.html`.

LEE el archivo: `loadDestacados` y la línea "Próximo disponible: ${m.proximo_disponible...}". `m.disponibilidad` viene de `listar_medicos`.

- [ ] **Step 1:** agregar el mismo helper `horariosSemanaHtml(dispo)` (idéntico al de Task 3) en el script de `index.html`.
- [ ] **Step 2:** reemplazar la línea "Próximo disponible: …" por `Horarios: ${horariosSemanaHtml(m.disponibilidad)}` (manteniendo el estilo del contenedor).
- [ ] **Step 3: Auto-revisión** — `node --check` OK; card muestra horarios de la semana; vacío → "Sin horarios publicados".

---

## Task 5: Deploy, E2E y commit

**Files:** deploy de `api.php`, `medico-portal.html`, `pacientes.html`, `index.html`.

- [ ] **Step 1: Deploy** por `pscp` a home + script base64 (instala con `http:http` 644).
- [ ] **Step 2: `php -l api.php`** → sin errores.
- [ ] **Step 3: E2E interno (curl)** con médico #22:
  1. Login médico → token. `medico_disponibilidad_guardar` con `[{dia:'Lunes',hora:'10:00'},{dia:'Jueves',hora:'11:00'}]` → `{ok, slots:2}`.
  2. `listar_medicos` → el médico #22 trae `disponibilidad` con "Lunes 10:00" y "Jueves 11:00" (y ya no los anteriores).
  3. `horarios_disponibles {medico_id:22}` → ahora ofrece slots de Lunes 10:00 / Jueves 11:00 (fechas futuras) y no los viejos.
  4. Restaurar la disponibilidad original del médico #22 (guardarla de vuelta: Lunes 16:00, Martes 19:00, Miércoles 19:00) para no dejar el entorno alterado.
- [ ] **Step 4: Verificación UI** — `https://medicvip.org` (index) muestra "Horarios: …" agrupados en el card; login médico de prueba → Agenda → "⚙️ Editar mis horarios" abre el editor precargado. (read_page/console; screenshots pueden timeoutear.)
- [ ] **Step 5: Commit y push** de los 4 archivos + README (línea de la feature).

---

## Criterios de aceptación (del spec)
1. Editor de disponibilidad accesible desde Agenda, precargado, persiste al guardar.
2. Los slots con fecha del paciente reflejan la nueva disponibilidad.
3. El card (pacientes.html + index.html) muestra los horarios de la semana agrupados por día.
4. Guardar disponibilidad no altera otros campos del perfil.
5. Médico sin horarios → "Sin horarios publicados".
