# Especialidades, idiomas y experiencia editables — Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development.

**Goal:** Especialidades e idiomas como listas editables + experiencia profesional (lista), con perfil público actualizado.

**Architecture:** 3 columnas JSON en `medico_especialidad` + listas dinámicas en el portal (patrón `educacion`) + filtro por especialidad "match any" + render público. Vanilla PHP + HTML/JS inline.

**Entorno NAS:** `plink -pw "<pass>" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" pbaquerizo@192.168.0.116`, técnica base64 (⚠️ leer los `.sh`/`.php` con acentos con **`Get-Content -Raw -Encoding UTF8`** en PowerShell, si no se doble-codifican), PHP como http, API interna `curl -H 'Host: medicvip.org' http://127.0.0.1/api.php`, deploy `pscp` a home + script base64 (con `php -l`), `chown http:http` + `chmod 644`.

---

## Task 1: Migración + schema.sql

- [ ] **Step 1: PHP migración** (con `set_charset('utf8mb4')`):
```php
$db->set_charset('utf8mb4');
foreach(['especialidades','idiomas_lista','experiencia'] as $col){
  if($db->query("SHOW COLUMNS FROM medico_especialidad LIKE '$col'")->num_rows==0)
    $db->query("ALTER TABLE medico_especialidad ADD COLUMN $col TEXT DEFAULT NULL");
}
$rows=$db->query("SELECT medico_id,especialidad,idiomas FROM medico_especialidad WHERE especialidades IS NULL")->fetch_all(MYSQLI_ASSOC);
$n=0;
foreach($rows as $r){
  $esp = trim((string)$r['especialidad'])!=='' ? [trim($r['especialidad'])] : [];
  $idi = array_values(array_filter(array_map('trim', explode(',', (string)$r['idiomas'])), fn($x)=>$x!==''));
  $je=json_encode($esp,JSON_UNESCAPED_UNICODE); $ji=json_encode($idi,JSON_UNESCAPED_UNICODE); $jx='[]';
  $st=$db->prepare('UPDATE medico_especialidad SET especialidades=?, idiomas_lista=?, experiencia=? WHERE medico_id=?');
  $st->bind_param('sssi',$je,$ji,$jx,$r['medico_id']); $st->execute(); $n++;
}
echo "sembrados: $n\n";
```
- [ ] **Step 2: Verificar** — `SELECT especialidades,idiomas_lista,experiencia FROM medico_especialidad WHERE medico_id=22` → `["Cardiología"]`, `["Español"]`, `[]`.
- [ ] **Step 3: schema.sql** — agregar `especialidades text DEFAULT NULL, idiomas_lista text DEFAULT NULL, experiencia text DEFAULT NULL,` a la tabla `medico_especialidad`.

---

## Task 2: Backend `api.php`

- [ ] **Step 1: `medicoPerfil`** — agregar `e.especialidades,e.idiomas_lista,e.experiencia` al SELECT (junto a `e.educacion`). Tras el `json_decode` de educacion, agregar:
```php
    if ($perfil) { $perfil['especialidades']=json_decode($perfil['especialidades']?:'[]',true)?:[]; $perfil['idiomas_lista']=json_decode($perfil['idiomas_lista']?:'[]',true)?:[]; $perfil['experiencia']=json_decode($perfil['experiencia']?:'[]',true)?:[]; }
```
- [ ] **Step 2: `medicoActualizar`** — antes de `$db->commit();` (junto al bloque `educacion`), agregar:
```php
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
```
(Nota: el bloque COALESCE de `medico_especialidad` ya no recibe `especialidad`/`idiomas` sueltos del portal → los preserva; estos bloques los sobreescriben con la sincronización. Orden OK.)
- [ ] **Step 3: `listarMedicos`** —
  a) Cambiar la consulta principal para NO filtrar en SQL: usar siempre `$medicos = fetchAll(query('SELECT * FROM v_medicos_activos ORDER BY id DESC'));` (quitar la rama `WHERE especialidad=?`). Conservar `$spec = $_GET['especialidad'] ?? null;`.
  b) Cambiar la consulta extra `$se` a: `$se = $db->prepare('SELECT educacion,especialidades,idiomas_lista,experiencia FROM medico_especialidad WHERE medico_id=?');` y dentro del `foreach` adjuntar los 4:
```php
        $se->bind_param('i',$m['id']); $se->execute();
        $er=$se->get_result()->fetch_assoc();
        $m['educacion']      = $er ? (json_decode($er['educacion'] ?: '[]', true) ?: []) : [];
        $m['especialidades'] = $er ? (json_decode($er['especialidades'] ?: '[]', true) ?: []) : [];
        $m['idiomas_lista']  = $er ? (json_decode($er['idiomas_lista'] ?: '[]', true) ?: []) : [];
        $m['experiencia']    = $er ? (json_decode($er['experiencia'] ?: '[]', true) ?: []) : [];
```
  c) Tras el `foreach` (y su `unset($m)` si existe; si no, agregarlo), filtrar en PHP:
```php
    if ($spec && $spec !== 'Todos') {
        $medicos = array_values(array_filter($medicos, fn($m) => in_array($spec, $m['especialidades'], true) || ($m['especialidad'] ?? '') === $spec));
    }
```
- [ ] **Step 4: Auto-revisión** — binds `'si'`/`'ssi'`/`'i'`; JSON `JSON_UNESCAPED_UNICODE`; sincronización especialidad=principal, idiomas=join; filtro match-any en PHP; `unset($m)` tras el foreach; llaves balanceadas.

---

## Task 3: `medico-portal.html` — listas en la card + card experiencia

**Files:** `medico-portal.html`.

LEE el archivo. Reusa el patrón dinámico existente de `_educacion`/`renderEducacion`/`eduAgregar`/`eduQuitar` (busca `EDU_TIPOS` y `#edu-lista`). El backend ahora devuelve `m.especialidades`, `m.idiomas_lista`, `m.experiencia` (arreglos) y acepta esas mismas claves.

- [ ] **Step 1: Catálogo** — `const CAT_ESPECIALIDADES = ['Medicina general','Pediatría','Cardiología','Dermatología','Psicología clínica','Ginecología y obstetricia','Traumatología','Nutrición y dietética','Oftalmología','Neurología','Endocrinología','Otra'];` (las mismas opciones del `select` actual `p-especialidad`).
- [ ] **Step 2: Card "Especialidad y experiencia"** —
  - Reemplazar el `<div class="field">` del `select#p-especialidad` por: etiqueta "Especialidades" + `<div id="esp-lista"></div>` + botón `<button type="button" class="btn" onclick="espAgregar()">➕ Agregar especialidad</button>`.
  - Reemplazar el `<div class="field">` del `input#p-idiomas` por: etiqueta "Idiomas" + `<div id="idi-lista"></div>` + botón `➕ Agregar idioma` (`onclick="idiAgregar()"`).
  - Mantener `p-experiencia` (años) y `p-biografia`.
- [ ] **Step 3: JS listas** (estado + render):
```js
let _especialidades=[], _idiomas=[];
function renderEsp(){ const c=document.getElementById('esp-lista'); if(!c)return;
  c.innerHTML=_especialidades.map((v,i)=>`<div style="display:flex;gap:8px;margin-bottom:8px">
    <select style="flex:1" onchange="_especialidades[${i}]=this.value">${CAT_ESPECIALIDADES.map(o=>`<option${o===v?' selected':''}>${o}</option>`).join('')}</select>
    <button type="button" class="btn" onclick="espQuitar(${i})" style="border-color:#C0392B;color:#C0392B;padding:8px 12px">✕</button></div>`).join('')
    || '<p style="font-size:13px;color:var(--muted)">Agrega tu(s) especialidad(es). La primera es la principal.</p>'; }
function espAgregar(){ _especialidades.push(CAT_ESPECIALIDADES[0]); renderEsp(); }
function espQuitar(i){ _especialidades.splice(i,1); renderEsp(); }
function renderIdi(){ const c=document.getElementById('idi-lista'); if(!c)return;
  c.innerHTML=_idiomas.map((v,i)=>`<div style="display:flex;gap:8px;margin-bottom:8px">
    <input type="text" style="flex:1" value="${(v||'').replace(/"/g,'&quot;')}" oninput="_idiomas[${i}]=this.value" placeholder="Ej. Español">
    <button type="button" class="btn" onclick="idiQuitar(${i})" style="border-color:#C0392B;color:#C0392B;padding:8px 12px">✕</button></div>`).join('')
    || '<p style="font-size:13px;color:var(--muted)">Agrega los idiomas en que atiendes.</p>'; }
function idiAgregar(){ _idiomas.push(''); renderIdi(); }
function idiQuitar(i){ _idiomas.splice(i,1); renderIdi(); }
```
- [ ] **Step 4: Card nueva "Experiencia profesional"** (después de "Historial educativo"):
```html
      <div class="card">
        <div class="card-title">Experiencia profesional</div>
        <p style="font-size:13px;color:var(--muted);margin:0 0 12px">Cargos y lugares donde has trabajado. Aparecen en tu perfil público.</p>
        <div id="exp-lista"></div>
        <button type="button" class="btn" onclick="expAgregar()">➕ Agregar experiencia</button>
      </div>
```
```js
let _experiencia=[];
function renderExp(){ const c=document.getElementById('exp-lista'); if(!c)return;
  c.innerHTML=_experiencia.map((e,i)=>`<div style="display:grid;grid-template-columns:1.2fr 1.3fr 0.9fr auto;gap:8px;align-items:end;margin-bottom:10px">
    <div class="field" style="margin:0"><label>Cargo / rol</label><input type="text" value="${(e.cargo||'').replace(/"/g,'&quot;')}" oninput="_experiencia[${i}].cargo=this.value"></div>
    <div class="field" style="margin:0"><label>Institución</label><input type="text" value="${(e.institucion||'').replace(/"/g,'&quot;')}" oninput="_experiencia[${i}].institucion=this.value"></div>
    <div class="field" style="margin:0"><label>Período</label><input type="text" value="${(e.periodo||'').replace(/"/g,'&quot;')}" oninput="_experiencia[${i}].periodo=this.value" placeholder="2018–2022"></div>
    <button type="button" class="btn" onclick="expQuitar(${i})" style="border-color:#C0392B;color:#C0392B;padding:8px 12px">✕</button></div>`).join('')
    || '<p style="font-size:13px;color:var(--muted)">Sin experiencia registrada.</p>'; }
function expAgregar(){ _experiencia.push({cargo:'',institucion:'',periodo:''}); renderExp(); }
function expQuitar(i){ _experiencia.splice(i,1); renderExp(); }
```
- [ ] **Step 5: `loadPerfil`** — agregar: `_especialidades = Array.isArray(m.especialidades)&&m.especialidades.length ? m.especialidades.slice() : (m.especialidad?[m.especialidad]:[]); renderEsp(); _idiomas = Array.isArray(m.idiomas_lista)&&m.idiomas_lista.length ? m.idiomas_lista.slice() : (m.idiomas?m.idiomas.split(',').map(s=>s.trim()).filter(Boolean):[]); renderIdi(); _experiencia = Array.isArray(m.experiencia) ? m.experiencia.map(x=>({cargo:x.cargo||'',institucion:x.institucion||'',periodo:x.periodo||''})) : []; renderExp();`. **Quitar** el `setSelect('p-especialidad',...)` y el populate de `p-idiomas`.
- [ ] **Step 6: `guardarPerfil`** — en el `payload`: **quitar** `especialidad` e (si está) `idiomas`; agregar:
```js
    especialidades: _especialidades.filter(s=>(s||'').trim()),
    idiomas_lista: _idiomas.filter(s=>(s||'').trim()),
    experiencia: _experiencia.filter(e=>(e.cargo||'').trim()||(e.institucion||'').trim()||(e.periodo||'').trim()),
```
- [ ] **Step 7: Auto-revisión** — `node --check` OK; no quedan `p-especialidad`/`p-idiomas` (select/input únicos eliminados); agregar/quitar en las 3 listas funciona; loadPerfil precarga; guardarPerfil envía las 3 claves.

---

## Task 4: `pacientes.html` + `index.html` — render público

**Files:** `pacientes.html`, `index.html`.

- [ ] **Step 1: Helper experiencia** (ambos `<script>`):
```js
function experienciaHtml(arr){
  if(!Array.isArray(arr) || !arr.length) return '';
  return arr.map(e => { const p=[e.cargo,e.institucion,e.periodo].filter(x=>(x||'').trim()).join(' · '); return p?`<div style="font-size:12px;color:var(--muted);margin-top:2px">💼 ${p}</div>`:''; }).join('');
}
```
- [ ] **Step 2: `pacientes.html`** —
  - Agregar al objeto `d` en el map: `especialidades: m.especialidades||[], idiomas_lista: m.idiomas_lista||[], experiencia: m.experiencia||[]`.
  - Especialidad principal: donde hoy muestra `${d.spec}...`, usar `d.especialidades?.[0] || d.spec` como principal; agregar chips de las secundarias `d.especialidades?.slice(1)` (ej. `<span>` pequeños). 
  - Idiomas: donde muestra `🗣 ${d.languages}`, usar `${(d.idiomas_lista&&d.idiomas_lista.length)?d.idiomas_lista.join(' · '):d.languages}`.
  - Agregar `${experienciaHtml(d.experiencia)}` cerca de la educación (después de `educacionHtml`).
- [ ] **Step 3: `index.html`** —
  - Agregar `especialidades/idiomas_lista/experiencia` al objeto `m` si se mapea; especialidad principal = `m.especialidades?.[0]||m.especialidad`. Agregar `${experienciaHtml(m.educacion?…)}`… en la zona de info del card (después de `educacionHtml(m.educacion)`), usando `experienciaHtml(m.experiencia)`.
- [ ] **Step 4: Auto-revisión** — `node --check` OK ambos; card muestra especialidad principal + secundarias + idiomas (lista) + experiencia (💼); sin datos no rompe.

---

## Task 5: Deploy, E2E y commit

- [ ] **Step 1: Deploy** `api.php`, `medico-portal.html`, `pacientes.html`, `index.html` (pscp + base64, `php -l`). **Los `.sh` de deploy leerlos con `-Encoding UTF8`.**
- [ ] **Step 2: E2E interno (curl)** con médico #22:
  1. `medico_perfil` → trae `especialidades` (["Cardiología"]), `idiomas_lista` (["Español"]), `experiencia` ([]).
  2. `medico_actualizar` con `{"especialidades":["Cardiología","Medicina interna"],"idiomas_lista":["Español","Inglés"],"experiencia":[{"cargo":"Cardiólogo","institucion":"Hospital Luis Vernaza","periodo":"2018-2022"}]}` → `ok`. (⚠️ enviar el JSON con acentos leyendo el `.sh` con `-Encoding UTF8`.)
  3. `medico_perfil` → refleja lo nuevo; y `SELECT especialidad` (columna) = "Cardiología" (principal sincronizada).
  4. `listar_medicos?especialidad=Medicina interna` → el médico #22 **aparece** (match any por especialidad secundaria).
  5. `listar_medicos` (sin filtro) → médico #22 trae las 3 listas.
  6. `medico_actualizar` con solo `{"ciudad":"Guayaquil"}` → las listas NO se borran.
  7. Restaurar médico #22 a `{"especialidades":["Cardiología"],"idiomas_lista":["Español"],"experiencia":[]}`.
- [ ] **Step 3: Verificación UI** — `pacientes.html` card de Dr. Pablo: especialidad principal + (si aplica) secundarias + idiomas + experiencia. Portal (login) → Mi perfil: listas de especialidad/idiomas + card "Experiencia profesional" con agregar/quitar. Verificar acentos correctos ("Cardiología"). (read_page/console; `tipo`/`especialidad` sin mojibake.)
- [ ] **Step 4: Commit y push** de los 4 archivos + `schema.sql` + README.

---

## Criterios de aceptación (del spec)
1. Agregar/quitar especialidades, idiomas y experiencia profesional; persisten.
2. `especialidad` principal sincronizada con la 1ª.
3. Filtro por especialidad = match any.
4. Perfil público muestra principal + secundarias + idiomas + experiencia (💼).
5. Guardar sin tocar una lista no la borra.
6. Acentos correctos.
