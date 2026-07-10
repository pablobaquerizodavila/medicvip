# Recortador de foto de perfil — spec

**Fecha:** 2026-07-10  
**Archivo afectado:** `registro-medico.html`  
**Approach elegido:** C — drag directo en el avatar (sin librerías externas)

---

## Problema

El widget de foto de 130×130 actual muestra la imagen completa con `object-fit: cover`. El médico no puede centrar ni ajustar qué parte de la foto queda visible. En el listado de pacientes las fotos aparecen en círculos de 48–72 px, por lo que una foto mal encuadrada queda inútil.

---

## Solución

Convertir el widget de foto en un recortador interactivo que se activa en el mismo espacio (130×130) cuando se selecciona una imagen. El médico arrastra y hace zoom; al confirmar, un `<canvas>` oculto exporta el recorte circular como JPEG base64 que ya existe en el campo `foto_base64` del formulario.

---

## Estados del widget

### 1. Vacío (sin cambios)
- Borde dashed, ícono 📷, texto "Foto de perfil"
- Click → `<input type="file" accept="image/*">`

### 2. Modo crop (nuevo)
Se activa automáticamente al cargar una imagen.

- La imagen se muestra a escala dentro del widget, más grande que el contenedor
- Overlay oscuro `rgba(0,0,0,0.45)` cubre todo excepto un **círculo central de 110 px** con borde dashed blanco — eso es lo que quedará
- **Drag**: el usuario arrastra la imagen para reencuadrar (mousedown/mousemove/mouseup + equivalentes touch)
- **Zoom slider**: `<input type="range" min="1" max="3" step="0.01">` debajo del widget controla `scale` (1× a 3×)
- Dos botones debajo del slider:
  - `↩ Cambiar` — abre el file picker de nuevo
  - `✓ Listo` — captura el recorte y pasa al estado 3

### 3. Confirmada
- El widget muestra el avatar circular recortado (sin overlay)
- Badge ✓ verde en esquina inferior derecha
- Link "Cambiar foto" debajo para reiniciar el flujo

---

## Implementación técnica

### Estructura HTML (reemplaza el widget actual)

```html
<!-- input file (oculto) -->
<input type="file" accept="image/*" id="photo-input" style="display:none">

<!-- Widget contenedor -->
<div id="photo-widget" style="width:130px;min-width:130px">
  <!-- Estado 1: placeholder -->
  <div id="photo-placeholder" …>📷 Foto de perfil</div>

  <!-- Estado 2: crop (display:none por defecto) -->
  <div id="photo-crop-container" style="display:none">
    <div id="photo-crop-area" style="width:130px;height:130px;overflow:hidden;position:relative;cursor:move">
      <img id="crop-img" style="position:absolute;transform-origin:center center">
      <!-- overlay + círculo gestionados por CSS -->
    </div>
    <input type="range" id="zoom-slider" min="1" max="3" step="0.01" value="1">
    <div style="display:flex;gap:6px">
      <button id="crop-change">↩ Cambiar</button>
      <button id="crop-confirm">✓ Listo</button>
    </div>
  </div>

  <!-- Estado 3: resultado (display:none por defecto) -->
  <div id="photo-result" style="display:none">
    <img id="photo-preview" style="width:130px;height:130px;border-radius:50%;object-fit:cover">
    <!-- badge ✓ -->
    <button id="crop-reset">Cambiar foto</button>
  </div>
</div>

<!-- Canvas oculto para exportar -->
<canvas id="crop-canvas" style="display:none"></canvas>
```

### Lógica JS (~80 líneas)

```
VARIABLES DE ESTADO:
  imgEl       = <img id="crop-img">
  naturalW, naturalH  (dimensiones reales de la imagen)
  offsetX, offsetY    (desplazamiento actual del drag, en px de pantalla)
  scale               (factor de zoom, 1–3)
  isDragging, startX, startY  (para el drag)

AL CARGAR IMAGEN (FileReader.readAsDataURL):
  1. Poner src del imgEl
  2. Al onload: calcular scale inicial para que la imagen llene el círculo (110px / min(w,h) * devicePixelRatio)
  3. offsetX = offsetY = 0
  4. Mostrar estado 2

DRAG (mousedown / touchstart):
  isDragging = true; guardar startX, startY

MOUSEMOVE / TOUCHMOVE:
  offsetX += e.clientX - startX; startX = e.clientX (idem Y)
  clampOffset()  ← mantiene la imagen cubriendo el círculo de 110px siempre
  applyTransform()

MOUSEUP / TOUCHEND: isDragging = false

ZOOM SLIDER (input):
  scale = parseFloat(slider.value)
  clampOffset()
  applyTransform()

applyTransform():
  imgEl.style.transform = `translate(calc(-50% + ${offsetX}px), calc(-50% + ${offsetY}px)) scale(${scale})`
  imgEl.style.left = '50%'; imgEl.style.top = '50%'

clampOffset():
  halfVisible = 110/2
  maxShiftX = (imgEl.naturalWidth * scale / (130/imgEl.naturalWidth) - 110) / 2  [aproximado]
  offsetX = Math.max(-maxShiftX, Math.min(maxShiftX, offsetX))
  idem Y

CONFIRMAR (crop-confirm click):
  canvas.width = canvas.height = 400
  ctx.beginPath(); ctx.arc(200,200,200,0,Math.PI*2); ctx.clip()
  calcular rect de la imagen en coordenadas de canvas según offsetX, offsetY, scale
  ctx.drawImage(imgEl, srcX, srcY, srcW, srcH, 0, 0, 400, 400)
  foto_base64 = canvas.toDataURL('image/jpeg', 0.85)
  Mostrar estado 3 con photo-preview.src = foto_base64

CAMBIAR / RESET: volver a estado 1 + limpiar variables
```

### CSS adicional

```css
/* Overlay circular */
#photo-crop-area::after {
  content: '';
  position: absolute; inset: 0;
  background: radial-gradient(circle 55px at center, transparent 55px, rgba(0,0,0,0.45) 55px);
  pointer-events: none;
}
/* Borde dashed del círculo de recorte */
#photo-crop-area::before {
  content: '';
  position: absolute;
  width: 110px; height: 110px;
  top: 50%; left: 50%; transform: translate(-50%, -50%);
  border-radius: 50%; border: 2px dashed rgba(255,255,255,0.85);
  pointer-events: none; z-index: 2;
}
```

---

## Campo de salida

Sin cambios en el backend. `foto_base64` ya llega como JPEG base64 a `api.php → registrarMedico()`. El canvas genera 400×400 px que es suficiente para cualquier tamaño de avatar mostrado en la UI (máximo 72px).

---

## Casos borde

| Caso | Manejo |
|------|--------|
| Imagen muy pequeña (<110px) | Scale inicial = 1; el círculo queda más grande que la imagen → el clamp lo centra |
| GIF / PNG con transparencia | `toDataURL('image/jpeg')` rellena con blanco (comportamiento nativo del canvas) |
| Móvil (touch) | touchstart/touchmove/touchend con `e.touches[0]` |
| Usuario no confirma | `foto_base64` queda `null` → el backend acepta médicos sin foto (muestra iniciales) |

---

## Archivos a modificar

- `registro-medico.html` — único archivo afectado (HTML + CSS + JS inline)

No se modifica `api.php`, `api.config.php` ni ningún otro archivo.

---

## Criterio de aceptación

1. Al seleccionar una foto, el widget entra en modo crop sin abrir modal
2. Se puede arrastrar la imagen y usar el slider de zoom
3. El círculo muestra en todo momento qué área quedará recortada
4. Al hacer "✓ Listo" el widget colapsa al avatar circular con badge ✓
5. Al enviar el formulario, `foto_base64` contiene un JPEG 400×400 del área recortada
6. Funciona en Chrome/Firefox/Safari desktop y en móvil (touch)
