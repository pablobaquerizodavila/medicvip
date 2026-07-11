# Historial educativo dinámico — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** El médico gestiona un historial educativo flexible (agregar/quitar entradas) en una card aparte, y se muestra en el perfil público.

**Architecture:** columna JSON `medico_especialidad.educacion` + lista dinámica en el portal + render en los cards públicos. Vanilla PHP + HTML/JS inline.

**Entorno NAS:** `plink -pw "<pass>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" pbaquerizo@192.168.0.116`, técnica base64, PHP como http, API interna `curl -H 'Host: medicvip.org' http://127.0.0.1/api.php`, deploy `pscp` a home + script base64 (instala en `/volume2/web/medicvip/` con `chown http:http` + `chmod 644`), `php -l` en el NAS.

---

## Task 1: Migración DB + schema.sql

- [ ] **Step 1: ALTER + seed** (base `mediconline`):
```sql
ALTER TABLE medico_especialidad ADD COLUMN educacion TEXT DEFAULT NULL;
```
- [ ] **Step 2: Seed** — por cada médico con `educacion IS NULL`, construir el JSON desde `universidad`/`postgrado` y guardarlo. En PHP:
```php
$rows=$db->query("SELECT medico_id,universidad,postgrado FROM medico_especialidad WHERE educacion IS NULL")->fetch_all(MYSQLI_ASSOC);
foreach($rows as $r){
  $edu=[];
  if(trim((string)$r['universidad'])!=='') $edu[]=['tipo'=>'Título universitario','institucion'=>trim($r['universidad']),'titulo'=>'','anio'=>''];
  if(trim((string)$r['postgrado'])!=='')   $edu[]=['tipo'=>'Postgrado','institucion'=>'','titulo'=>trim($r['postgrado']),'anio'=>''];
  $j=json_encode($edu,JSON_UNESCAPED_UNICODE);
  $st=$db->prepare('UPDATE medico_especialidad SET educacion=? WHERE medico_id=?');
  $st->bind_param('si',$j,$r['medico_id']); $st->execute();
}
```
- [ ] **Step 3: Verificar** — `SELECT medico_id,educacion FROM medico_especialidad WHERE medico_id=22;` → debe traer las 2 entradas (UESS, MD Florida U).
- [ ] **Step 4: schema.sql** — agregar `\`educacion\` text DEFAULT NULL,` a la tabla `medico_especialidad`.

---

## Task 2: Backend `api.php`

- [ ] **Step 1: `medicoPerfil`** — agregar `e.educacion` al SELECT (línea del `$stmt` que trae `e.universidad,e.postgrado,...`). Tras `$perfil=fetchOne($stmt);` agregar:
```php
    if ($perfil) $perfil['educacion'] = json_decode($perfil['educacion'] ?: '[]', true) ?: [];
```
- [ ] **Step 2: `medicoActualizar`** — antes de `$db->commit();`, agregar:
```php
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
```
- [ ] **Step 3: `listarMedicos`** — junto a los `$sd`/`$sr` prepared antes del `foreach`, agregar:
```php
    $se = $db->prepare('SELECT educacion FROM medico_especialidad WHERE medico_id=?');
```
y dentro del `foreach ($medicos as &$m)`:
```php
        $se->bind_param('i',$m['id']); $se->execute();
        $eduRow = $se->get_result()->fetch_assoc();
        $m['educacion'] = $eduRow ? (json_decode($eduRow['educacion'] ?: '[]', true) ?: []) : [];
```
- [ ] **Step 4: Auto-revisión** — binds `'si'`/`'i'`; JSON con `JSON_UNESCAPED_UNICODE`; medicoActualizar dentro de la transacción antes del commit; llaves balanceadas.

---

## Task 3: `medico-portal.html` — card "Historial educativo" dinámica

**Files:** `medico-portal.html`.

LEE el archivo. La card "Especialidad y experiencia" tiene hoy los campos `p-universidad` y `p-postgrado` (agregados antes) — hay que **quitarlos** (van a la lista). `loadPerfil()` los precarga (líneas con `p-universidad`/`p-postgrado`) y `guardarPerfil()` los envía — **quitar** ambas referencias. Mantener `p-idiomas` y `p-experiencia`.

- [ ] **Step 1: Constante de tipos** (en el `<script>`):
```js
const EDU_TIPOS = ['Título universitario','Postgrado','Especialización','Doctorado','Maestría','Pasantía','Curso','Otro'];
```
- [ ] **Step 2: Card HTML** — agregar DESPUÉS de la card "Especialidad y experiencia":
```html
      <div class="card">
        <div class="card-title">Historial educativo</div>
        <p style="font-size:13px;color:var(--muted);margin:0 0 12px">Agrega tus títulos, postgrados, especializaciones, pasantías y cursos. Aparecen en tu perfil público.</p>
        <div id="edu-lista"></div>
        <button type="button" class="btn" onclick="eduAgregar()">➕ Agregar formación</button>
      </div>
```
- [ ] **Step 3: JS de la lista dinámica**:
```js
let _educacion = [];
function renderEducacion(){
  const cont = document.getElementById('edu-lista');
  if(!cont) return;
  cont.innerHTML = _educacion.map((e,i) => `
    <div class="edu-row" style="display:grid;grid-template-columns:1.1fr 1.3fr 1.3fr 0.7fr auto;gap:8px;align-items:end;margin-bottom:10px">
      <div class="field" style="margin:0"><label>Tipo</label>
        <select onchange="eduSet(${i},'tipo',this.value)">${EDU_TIPOS.map(t=>`<option${e.tipo===t?' selected':''}>${t}</option>`).join('')}</select></div>
      <div class="field" style="margin:0"><label>Institución</label><input type="text" value="${(e.institucion||'').replace(/"/g,'&quot;')}" oninput="eduSet(${i},'institucion',this.value)"></div>
      <div class="field" style="margin:0"><label>Título / detalle</label><input type="text" value="${(e.titulo||'').replace(/"/g,'&quot;')}" oninput="eduSet(${i},'titulo',this.value)"></div>
      <div class="field" style="margin:0"><label>Año</label><input type="text" value="${(e.anio||'').replace(/"/g,'&quot;')}" oninput="eduSet(${i},'anio',this.value)"></div>
      <button type="button" class="btn" title="Quitar" onclick="eduQuitar(${i})" style="border-color:#C0392B;color:#C0392B;padding:8px 12px">✕</button>
    </div>`).join('') || '<p style="font-size:13px;color:var(--muted)">Sin formación registrada. Agrega tu primera entrada.</p>';
}
function eduSet(i,k,v){ if(_educacion[i]) _educacion[i][k]=v; }
function eduAgregar(){ _educacion.push({tipo:'Título universitario',institucion:'',titulo:'',anio:''}); renderEducacion(); }
function eduQuitar(i){ _educacion.splice(i,1); renderEducacion(); }
```
(Ajusta el markup de `.field`/`label`/`select` al estilo real del portal. En móvil el grid puede colapsar; opcional un media query, no bloqueante.)
- [ ] **Step 4: `loadPerfil`** — donde se procesan los campos de `m`, agregar: `_educacion = Array.isArray(m.educacion) ? m.educacion : []; renderEducacion();`. Y **quitar** las líneas `document.getElementById('p-universidad').value = ...` y `p-postgrado` (esos campos ya no existen).
- [ ] **Step 5: `guardarPerfil`** — en el `payload`, **quitar** `universidad` y `postgrado`; agregar:
```js
    educacion: _educacion.filter(e => (e.institucion||'').trim() || (e.titulo||'').trim() || (e.anio||'').trim()),
```
- [ ] **Step 6: Quitar campos viejos del HTML** — eliminar del card "Especialidad y experiencia" los dos `<div class="field">` de `p-universidad` y `p-postgrado`. Mantener `p-idiomas`.
- [ ] **Step 7: Auto-revisión** — `node --check` del `<script>`: OK. Agregar/quitar entradas funciona; `loadPerfil` precarga; `guardarPerfil` envía `educacion` sin `universidad`/`postgrado`; no quedan referencias a `p-universidad`/`p-postgrado`.

---

## Task 4: `pacientes.html` + `index.html` — render público de educación

**Files:** `pacientes.html`, `index.html`.

- [ ] **Step 1: Helper** (en ambos `<script>`):
```js
function educacionHtml(arr){
  if(!Array.isArray(arr) || !arr.length) return '';
  const IC = {'Título universitario':'🎓','Postgrado':'📜','Especialización':'🩺','Doctorado':'🎖','Maestría':'📘','Pasantía':'🏥','Curso':'📗','Otro':'📄'};
  return arr.map(e => {
    const ic = IC[e.tipo] || '📄';
    const partes = [e.institucion, e.titulo].filter(x => (x||'').trim()).join(' · ');
    const anio = (e.anio||'').trim() ? ` (${e.anio})` : '';
    return `<div style="font-size:12px;color:var(--muted);margin-top:2px">${ic} ${partes}${anio}</div>`;
  }).join('');
}
```
- [ ] **Step 2: `pacientes.html`** — reemplazar las 2 líneas fijas del card (las que hoy hacen `${d.universidad ? '🎓...' : ''}` y `${d.postgrado ? '📜...' : ''}`) por `${educacionHtml(d.educacion)}`. Agregar `educacion: m.educacion || []` al objeto `d` en el map de `DOCTORS`.
- [ ] **Step 3: `index.html`** — si el card de destacados mostraba universidad/postgrado, reemplazar por `${educacionHtml(m.educacion)}` (insertar cerca de la especialidad/bio). `m.educacion` viene de `listar_medicos`.
- [ ] **Step 4: Auto-revisión** — `node --check` OK en ambos; el card muestra las entradas con íconos; sin educación no muestra nada roto.

---

## Task 5: Deploy, E2E y commit

- [ ] **Step 1: Deploy** `api.php`, `medico-portal.html`, `pacientes.html`, `index.html` por `pscp` + script base64 (con `php -l`).
- [ ] **Step 2: E2E interno (curl)** con médico #22:
  1. `medico_perfil` (con token) → trae `educacion` con las 2 entradas migradas (UESS, MD Florida U).
  2. `medico_actualizar` con `{"educacion":[{"tipo":"Título universitario","institucion":"UEES","titulo":"Médico Cirujano","anio":"2015"},{"tipo":"Doctorado","institucion":"Florida U","titulo":"PhD","anio":"2020"}]}` → `ok`.
  3. `medico_perfil` de nuevo → refleja las 2 nuevas entradas.
  4. `listar_medicos` → médico #22 trae `educacion` con esas entradas.
  5. `medico_actualizar` con solo `{"ciudad":"Guayaquil"}` (sin educacion) → la educación **no** se borra (sigue).
  6. Restaurar la educación original del médico #22 (las 2 entradas UESS / MD Florida U) para dejar el entorno como estaba.
- [ ] **Step 3: Verificación UI** — `https://medicvip.org/pacientes.html` muestra la lista educativa en el card de Dr. Pablo; portal médico (login) → Mi perfil → card "Historial educativo" con las entradas + agregar/quitar. (read_page/console.)
- [ ] **Step 4: Commit y push** de los 4 archivos + `schema.sql` + README.

---

## Criterios de aceptación (del spec)
1. Card "Historial educativo" separada con la educación migrada como entradas.
2. Agregar/quitar entradas y guardar persiste en `medico_especialidad.educacion`.
3. Perfil público (pacientes.html/index.html) muestra la lista con íconos.
4. Guardar sin tocar educación no la borra.
5. Médico sin educación no rompe nada.
