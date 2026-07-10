# Photo Crop Widget — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el widget estático de foto en `registro-medico.html` por un recortador interactivo drag+zoom que exporta un círculo de 400×400 px en JPEG base64.

**Architecture:** Tres estados (placeholder → crop → resultado) gestionados por `display` CSS. La imagen se arrastra y escala dentro de un contenedor 130×130 con overlay circular. Al confirmar, un `<canvas>` oculto renderiza el recorte y lo exporta como base64 al campo `#photo-preview` que ya usa el formulario.

**Tech Stack:** HTML/CSS/JS vanilla, Canvas API — sin librerías externas.

---

## Archivo afectado

| Archivo | Cambios |
|---|---|
| `registro-medico.html` | 3 bloques: CSS (reemplazar `.photo-upload`), HTML (reemplazar widget), JS (reemplazar `previewPhoto`) |

Ningún otro archivo se toca.

---

### Task 1: Reemplazar el CSS del widget de foto

**Files:**
- Modify: `registro-medico.html` líneas 84–90 (bloque `/* PHOTO UPLOAD */`)

- [ ] **Step 1: Localizar el bloque CSS a reemplazar**

En `registro-medico.html` busca el comentario `/* PHOTO UPLOAD */` (~línea 84). El bloque termina en `.photo-upload strong { ... }` (~línea 90).

- [ ] **Step 2: Reemplazar el bloque completo**

Elimina desde `/* PHOTO UPLOAD */` hasta `}` del `.photo-upload strong` y pon en su lugar:

```css
  /* PHOTO UPLOAD — estado 1 */
  .photo-upload { border: 2px dashed var(--border); border-radius: var(--radius); text-align: center; cursor: pointer; transition: all .2s; }
  .photo-upload:hover { border-color: var(--green-mid); background: var(--green-light); }
  .photo-upload-icon { font-size: 32px; margin-bottom: 8px; }
  .photo-upload p { font-size: 13px; color: var(--muted); margin: 0; }

  /* PHOTO CROP — estado 2: overlay circular */
  #photo-crop-area::before {
    content: '';
    position: absolute;
    width: 110px; height: 110px;
    top: 50%; left: 50%; transform: translate(-50%, -50%);
    border-radius: 50%;
    border: 2px dashed rgba(255,255,255,.85);
    pointer-events: none; z-index: 2;
  }
  #photo-crop-area::after {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(circle 55px at center, transparent 55px, rgba(0,0,0,.45) 55px);
    pointer-events: none; z-index: 1;
  }
```

- [ ] **Step 3: Verificar que no hay errores de CSS**

Abre `registro-medico.html` en el browser. La página debe cargar sin errores de consola relacionados con CSS. El widget de foto actual debe seguir viéndose igual (el CSS nuevo aún no tiene efecto porque el HTML no cambió).

---

### Task 2: Reemplazar el HTML del widget de foto

**Files:**
- Modify: `registro-medico.html` líneas 212–220

- [ ] **Step 1: Localizar el bloque HTML a reemplazar**

Busca el comentario `<!-- ESTADO -->` o la línea con `id="photo-input"` (~línea 213). El bloque a reemplazar son estas 8 líneas exactas:

```html
      <input type="file" accept="image/*" id="photo-input" style="display:none" onchange="previewPhoto(this)">
      <div class="photo-upload" id="photo-widget" style="width:130px;min-width:130px;height:130px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;overflow:hidden" onclick="document.getElementById('photo-input').click()">
        <div id="photo-placeholder" style="display:flex;flex-direction:column;align-items:center;pointer-events:none">
          <div class="photo-upload-icon">📷</div>
          <p style="font-size:11px;margin-top:4px">Foto de perfil</p>
        </div>
        <img id="photo-preview" style="display:none;width:100%;height:100%;object-fit:cover;position:absolute;top:0;left:0;border-radius:inherit;pointer-events:none">
      </div>
```

- [ ] **Step 2: Reemplazar con la estructura de 3 estados**

```html
      <!-- file input oculto — sin onchange, gestionado por JS -->
      <input type="file" accept="image/*" id="photo-input" style="display:none">

      <!-- Wrapper — mantiene el ancho 130px en el flex layout -->
      <div id="photo-widget-outer" style="width:130px;min-width:130px;flex-shrink:0">

        <!-- ESTADO 1: placeholder (visible por defecto) -->
        <div id="photo-placeholder"
             class="photo-upload"
             style="width:130px;height:130px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;cursor:pointer"
             onclick="document.getElementById('photo-input').click()">
          <div class="photo-upload-icon">📷</div>
          <p style="font-size:11px;margin-top:4px">Foto de perfil</p>
        </div>

        <!-- ESTADO 2: crop (oculto por defecto) -->
        <div id="photo-crop-container" style="display:none">
          <div id="photo-crop-area"
               style="width:130px;height:130px;border-radius:12px;overflow:hidden;position:relative;cursor:move;user-select:none;touch-action:none;background:#dee2e6">
            <img id="crop-img" style="position:absolute;transform-origin:0 0;will-change:transform;pointer-events:none">
          </div>
          <div style="display:flex;align-items:center;gap:6px;margin-top:6px">
            <span style="font-size:12px;color:#adb5bd">🔍</span>
            <input type="range" id="zoom-slider" min="1" max="3" step="0.01" value="1"
                   style="flex:1;accent-color:#1d9e75;cursor:pointer">
            <span style="font-size:11px;color:#adb5bd">+</span>
          </div>
          <div style="display:flex;gap:6px;margin-top:6px">
            <button type="button" id="crop-change-btn"
                    style="flex:1;background:#f8f9fa;border:1px solid #dee2e6;font-size:11px;padding:6px 0;border-radius:6px;cursor:pointer;color:#6c757d">
              ↩ Cambiar
            </button>
            <button type="button" id="crop-confirm-btn"
                    style="flex:1;background:#1d9e75;border:none;color:#fff;font-size:11px;padding:6px 0;border-radius:6px;cursor:pointer">
              ✓ Listo
            </button>
          </div>
        </div>

        <!-- ESTADO 3: resultado (oculto por defecto) -->
        <div id="photo-result" style="display:none;text-align:center">
          <div style="position:relative;display:inline-block">
            <img id="photo-preview"
                 style="width:130px;height:130px;border-radius:50%;object-fit:cover;border:3px solid #1d9e75;display:block">
            <div style="position:absolute;bottom:4px;right:4px;width:22px;height:22px;border-radius:50%;background:#1d9e75;border:2px solid #fff;display:flex;align-items:center;justify-content:center">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="white"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            </div>
          </div>
          <div style="margin-top:6px">
            <button type="button" id="crop-reset-btn"
                    style="background:none;border:none;color:#1d9e75;font-size:11px;cursor:pointer;text-decoration:underline">
              Cambiar foto
            </button>
          </div>
        </div>

      </div><!-- /photo-widget-outer -->

      <!-- Canvas oculto para exportar el recorte -->
      <canvas id="crop-canvas" style="display:none" width="400" height="400"></canvas>
```

- [ ] **Step 3: Verificar que el layout no se rompe**

Abre la página en el browser. El estado 1 (placeholder 📷) debe verse igual que antes: 130×130, al lado del bloque de campos nombre/apellido. Los estados 2 y 3 están ocultos — no se ven todavía.

---

### Task 3: Reemplazar `previewPhoto` con la lógica del recortador

**Files:**
- Modify: `registro-medico.html` líneas 750–762 (función `previewPhoto`)

- [ ] **Step 1: Localizar la función a eliminar**

Busca `function previewPhoto(input)` (~línea 750). La función ocupa hasta la línea ~762 (cierre `}`).

- [ ] **Step 2: Reemplazar con el módulo crop completo**

Elimina la función `previewPhoto` (12 líneas) y pon en su lugar:

```javascript
// ── PHOTO CROP ────────────────────────────────────────────────────────────────
(function () {
  const CIRCLE_R = 55;    // radio px del círculo de recorte (diámetro = 110px)
  const WIDGET   = 130;   // tamaño del widget en px
  const OUT_SIZE = 400;   // tamaño de salida del canvas en px

  let scale    = 1;
  let offsetX  = 0;       // desplazamiento de la imagen desde el centro del widget
  let offsetY  = 0;
  let natW     = 0;       // dimensiones naturales de la imagen
  let natH     = 0;
  let baseScale = 1;      // scale mínima para cubrir el círculo

  const fileInput = document.getElementById('photo-input');
  const pholder   = document.getElementById('photo-placeholder');
  const cropCont  = document.getElementById('photo-crop-container');
  const cropArea  = document.getElementById('photo-crop-area');
  const cropImg   = document.getElementById('crop-img');
  const zSlider   = document.getElementById('zoom-slider');
  const resultDiv = document.getElementById('photo-result');
  const preview   = document.getElementById('photo-preview');
  const canvas    = document.getElementById('crop-canvas');
  const ctx       = canvas.getContext('2d');

  // ── Estado ──────────────────────────────────────────────────────────────────
  function showState(n) {
    pholder.style.display  = n === 1 ? '' : 'none';
    cropCont.style.display = n === 2 ? '' : 'none';
    resultDiv.style.display = n === 3 ? '' : 'none';
  }

  // ── Cargar imagen ────────────────────────────────────────────────────────────
  fileInput.addEventListener('change', function () {
    if (!this.files || !this.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
      cropImg.onload = () => {
        natW = cropImg.naturalWidth;
        natH = cropImg.naturalHeight;
        baseScale = (CIRCLE_R * 2) / Math.min(natW, natH);
        scale = baseScale;
        offsetX = 0;
        offsetY = 0;
        zSlider.min   = baseScale.toFixed(4);
        zSlider.max   = (baseScale * 3).toFixed(4);
        zSlider.value = baseScale.toFixed(4);
        applyTransform();
        showState(2);
      };
      cropImg.src = e.target.result;
    };
    reader.readAsDataURL(this.files[0]);
  });

  // ── Transform ────────────────────────────────────────────────────────────────
  function applyTransform() {
    const tx = WIDGET / 2 + offsetX - (natW * scale) / 2;
    const ty = WIDGET / 2 + offsetY - (natH * scale) / 2;
    cropImg.style.width  = natW + 'px';
    cropImg.style.height = natH + 'px';
    cropImg.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`;
    cropImg.style.transformOrigin = '0 0';
  }

  function clampOffset() {
    const maxX = Math.max(0, (natW * scale) / 2 - CIRCLE_R);
    const maxY = Math.max(0, (natH * scale) / 2 - CIRCLE_R);
    offsetX = Math.max(-maxX, Math.min(maxX, offsetX));
    offsetY = Math.max(-maxY, Math.min(maxY, offsetY));
  }

  // ── Zoom slider ──────────────────────────────────────────────────────────────
  zSlider.addEventListener('input', function () {
    scale = parseFloat(this.value);
    clampOffset();
    applyTransform();
  });

  // ── Drag (mouse) ─────────────────────────────────────────────────────────────
  let drag = false, sx = 0, sy = 0, sox = 0, soy = 0;

  cropArea.addEventListener('mousedown', e => {
    drag = true; sx = e.clientX; sy = e.clientY; sox = offsetX; soy = offsetY;
    e.preventDefault();
  });
  document.addEventListener('mousemove', e => {
    if (!drag) return;
    offsetX = sox + (e.clientX - sx);
    offsetY = soy + (e.clientY - sy);
    clampOffset(); applyTransform();
  });
  document.addEventListener('mouseup', () => { drag = false; });

  // ── Drag (touch) ─────────────────────────────────────────────────────────────
  cropArea.addEventListener('touchstart', e => {
    const t = e.touches[0];
    drag = true; sx = t.clientX; sy = t.clientY; sox = offsetX; soy = offsetY;
    e.preventDefault();
  }, { passive: false });
  cropArea.addEventListener('touchmove', e => {
    if (!drag) return;
    const t = e.touches[0];
    offsetX = sox + (t.clientX - sx);
    offsetY = soy + (t.clientY - sy);
    clampOffset(); applyTransform();
    e.preventDefault();
  }, { passive: false });
  cropArea.addEventListener('touchend', () => { drag = false; });

  // ── Confirmar recorte ────────────────────────────────────────────────────────
  document.getElementById('crop-confirm-btn').addEventListener('click', () => {
    canvas.width  = OUT_SIZE;
    canvas.height = OUT_SIZE;
    ctx.clearRect(0, 0, OUT_SIZE, OUT_SIZE);

    // Clip circular
    ctx.save();
    ctx.beginPath();
    ctx.arc(OUT_SIZE / 2, OUT_SIZE / 2, OUT_SIZE / 2, 0, Math.PI * 2);
    ctx.clip();

    // Origen del círculo de recorte en el espacio del widget
    const clipLeft = (WIDGET / 2) - CIRCLE_R;
    const clipTop  = (WIDGET / 2) - CIRCLE_R;

    // Posición de la imagen en el espacio del widget
    const imgX = WIDGET / 2 + offsetX - (natW * scale) / 2;
    const imgY = WIDGET / 2 + offsetY - (natH * scale) / 2;

    // Coordenadas de origen en la imagen natural
    const srcX = (clipLeft - imgX) / scale;
    const srcY = (clipTop  - imgY) / scale;
    const srcS = (CIRCLE_R * 2) / scale;

    ctx.drawImage(cropImg, srcX, srcY, srcS, srcS, 0, 0, OUT_SIZE, OUT_SIZE);
    ctx.restore();

    preview.src = canvas.toDataURL('image/jpeg', 0.85);
    showState(3);
  });

  // ── Cambiar foto (desde modo crop) ───────────────────────────────────────────
  document.getElementById('crop-change-btn').addEventListener('click', () => {
    fileInput.value = '';
    fileInput.click();
  });

  // ── Reset (desde resultado) ───────────────────────────────────────────────────
  document.getElementById('crop-reset-btn').addEventListener('click', () => {
    preview.src = '';
    fileInput.value = '';
    zSlider.value = 1;
    offsetX = offsetY = 0; scale = 1;
    showState(1);
  });
})();
```

- [ ] **Step 3: Verificar que no hay errores de JS**

Abre la consola del browser. Al cargar la página no debe haber errores. Haz clic en el widget 📷 — debe abrir el selector de archivo.

---

### Task 4: Prueba end-to-end + deploy + commit

**Files:**
- Test: manual en browser
- Deploy: NAS vía `pscp`

- [ ] **Step 1: Prueba flujo completo — desktop**

1. Abre `registro-medico.html` en Chrome/Firefox/Safari
2. Haz clic en el widget 📷 → selecciona una foto horizontal (más ancha que alta)
3. **Esperado**: el widget entra en modo crop — ves la foto con overlay oscuro y círculo dashed blanco
4. Arrastra la imagen → la imagen se mueve; el overlay/círculo permanecen fijos
5. Mueve el slider de zoom → la imagen escala; sigue cubriendo el círculo
6. Haz clic en **✓ Listo**
7. **Esperado**: widget muestra avatar circular con badge ✓ verde
8. Haz clic en **Cambiar foto** → vuelve al estado 1 (placeholder)
9. Repite con foto vertical (más alta que ancha)

- [ ] **Step 2: Prueba en móvil (o DevTools device mode)**

1. Abre DevTools → toggle device toolbar → selecciona un teléfono (iPhone SE o similar)
2. Recarga la página
3. Selecciona una foto → arrastra con el dedo (simulado con mouse en DevTools) → confirma
4. **Esperado**: drag funciona igual que en desktop

- [ ] **Step 3: Verificar que el formulario envía foto_base64 correcta**

1. Completa el formulario de registro (pasos 1–4) con la foto recortada
2. Antes de enviar, abre consola y ejecuta:
   ```js
   const d = document.getElementById('photo-preview');
   console.log(d.src.substring(0, 30), '| length:', d.src.length);
   ```
3. **Esperado**: `data:image/jpeg;base64,/9j/...` con longitud > 10000
4. Envía el formulario → verifica que la API responde `{"ok":true}`
5. Verifica en el panel admin que el médico registrado tiene foto circular

- [ ] **Step 4: Deploy al NAS**

```bash
MSYS_NO_PATHCONV=1 pscp -pw "Groundunder8299*" -hostkey "SHA256:QoI4JtkGYcHZLypBwZ5m+/NJiGzdlQS0uCRFmHYfEN8" \
  "G:/Documentos/compañias/Desarrollos/medicvip/code-medicvip/registro-medico.html" \
  "pbaquerizo@192.168.0.116:/volume2/web/medicvip/registro-medico.html"
```

Verifica en producción: https://medicvip.org/registro-medico.html → el widget debe comportarse igual que en local.

- [ ] **Step 5: Commit y push**

```bash
cd "G:/Documentos/compañias/Desarrollos/medicvip/code-medicvip"
git add registro-medico.html
git commit -m "feat: recortador circular drag+zoom en widget de foto del médico

Sin librerías externas. Canvas 400×400 JPEG. Funciona en desktop y móvil.

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>"
git push origin main
```

---

## Criterios de aceptación

- [ ] Seleccionar una foto entra en modo crop automáticamente
- [ ] La imagen se arrastra y el slider de zoom funciona
- [ ] El círculo dashed muestra en todo momento qué área quedará recortada
- [ ] "✓ Listo" produce un avatar circular con badge ✓
- [ ] El formulario envía `foto_base64` como JPEG 400×400 válido
- [ ] "Cambiar foto" / "↩ Cambiar" permiten volver a seleccionar
- [ ] Funciona en Chrome, Firefox y Safari — desktop y móvil
