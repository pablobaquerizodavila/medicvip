# Cuentas de paciente + historial médico — spec

**Fecha:** 2026-07-10
**Archivos afectados:** `schema.sql`, `api.php`, `pacientes.html`, `medico-portal.html`, `index.html` + 2 páginas nuevas (`registro-paciente.html`, `paciente-portal.html`) + migración SQL en el NAS
**Estado:** Diseño aprobado

---

## Problema

Hoy los pacientes NO tienen cuenta: se crean automáticamente al agendar (identificados por email en la tabla `pacientes`, sin contraseña ni perfil). No pueden ver sus citas ni su historial, y el médico no tiene contexto clínico del paciente.

Se necesita: cuentas de paciente (registro + login), un panel con sus citas (próximas e historial), un **historial médico** autoreportado (estilo siscormed), y que el médico pueda ver ese historial + escribir **notas clínicas por consulta** que queden disponibles para el próximo médico.

---

## Decisiones tomadas (confirmadas con el usuario)

| Tema | Decisión |
|------|----------|
| Cuenta vs agendar | **Cuenta obligatoria**: el paciente debe registrarse/loguearse antes de agendar. |
| Contenido del historial | **Set general completo** (ver campos abajo). |
| Notas del médico | **Sí**, notas clínicas por consulta, disponibles para el próximo médico. |
| Alcance | **Todo en un plan**. |
| Visibilidad de notas clínicas | Visibles también para el paciente (transparencia). |

**Implicación importante:** "cuenta obligatoria" **cambia el flujo de agendamiento** actual (`pacientes.html` + `crearReserva`): los datos del paciente ya no vienen del formulario de reserva, sino de su cuenta autenticada. Se conserva la opción de cortesía ya existente.

---

## Modelo de datos

### `pacientes` (extender)

Columnas actuales: `id, nombre, email(unique), telefono, edad, creado_en`. Agregar:

```sql
ALTER TABLE `pacientes`
  ADD COLUMN `password_hash` varchar(255) DEFAULT NULL,
  ADD COLUMN `cedula` varchar(20) DEFAULT NULL,
  ADD COLUMN `fecha_nacimiento` date DEFAULT NULL,
  ADD COLUMN `genero` varchar(30) DEFAULT NULL,
  ADD COLUMN `ciudad` varchar(80) DEFAULT NULL,
  ADD COLUMN `estado` enum('activo','inactivo') NOT NULL DEFAULT 'activo';
```

Nota: los registros existentes creados como invitado tienen `password_hash NULL`. Como la cuenta es obligatoria de aquí en adelante y hoy la tabla está vacía (pérdida de datos previa), no hay migración de datos. El registro permite "reclamar" un email preexistente sin contraseña.

### `paciente_historial` (nueva, 1:1 con paciente)

```sql
CREATE TABLE `paciente_historial` (
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
```

Campos del historial (form de registro): tipo de sangre, alergias, enfermedades crónicas, medicamentos actuales, cirugías previas, fuma, alcohol, peso (kg), estatura (cm), antecedentes familiares.

### `consulta_notas` (nueva, 1:1 con reserva)

```sql
CREATE TABLE `consulta_notas` (
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

---

## Autenticación de paciente

Reutiliza `jwtEncode`/`jwtDecode`. Rol `'paciente'`, header `X-Paciente-Token`. Agregar `X-Paciente-Token` a `Access-Control-Allow-Headers` en `api.php`.

```php
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
```

---

## Endpoints (`api.php`)

### `paciente_registro` (POST, público)
Crea cuenta + historial. Entrada: `nombre, email, password, cedula, fecha_nacimiento, genero, telefono, ciudad` + campos de historial.
- Valida email y password (min 8).
- Si el email existe con `password_hash` NULL (invitado previo) → lo "reclama" (rellena datos + fija password). Si existe con password → error "ya registrado".
- Hashea password (BCRYPT). Inserta/actualiza `pacientes`, inserta `paciente_historial`.
- Devuelve token (auto-login) + datos básicos.

### `paciente_login` (POST, público)
`email+password` → `password_verify` → JWT `role:'paciente'`. Devuelve token + paciente básico.

### `paciente_perfil` (GET, `X-Paciente-Token`)
Devuelve perfil + historial + reservas separadas en **próximas** e **historial** (join con médico y con `consulta_notas` → diagnóstico/indicaciones visibles al paciente).

### `paciente_actualizar` (POST, token)
Actualiza perfil (telefono, ciudad, genero, fecha_nacimiento).

### `paciente_historial_actualizar` (POST, token)
Upsert de `paciente_historial` del paciente autenticado.

### `paciente_recuperar` (POST, público)
Igual que `medicoRecuperar`: genera password temporal.

### `medico_ver_historial_paciente` (POST, `X-Medico-Token`)
Entrada: `paciente_id`. **Verifica** que el médico autenticado tenga ≥1 reserva con ese paciente; si no → 403. Devuelve perfil básico + `paciente_historial` + todas las `consulta_notas` previas del paciente (historial clínico compartido entre médicos).

### `medico_guardar_nota` (POST, `X-Medico-Token`)
Entrada: `reserva_id, diagnostico, indicaciones, notas`. Verifica que la reserva pertenezca al médico. Upsert de `consulta_notas`.

---

## Cambio en el agendamiento (`crearReserva` + `reservar_emergencia`)

**Antes:** tomaba `nombre_paciente`, `email_paciente`, etc. del body y creaba el paciente al vuelo.
**Ahora:** requiere `X-Paciente-Token`. `checkPaciente()` da el `paciente_id`; el nombre/email se leen de `pacientes`. El body ya NO trae datos personales, solo: `medico_id, horario, motivo, alergias, metodo_pago, codigo` (cortesía). Se conserva íntegro el flujo de cortesía (validación + canje atómico) y el de custodia.

`reservar_emergencia` también pasa a requerir `X-Paciente-Token`.

---

## Frontend

### `registro-paciente.html` (nueva)
Formulario en secciones: **Datos de la cuenta** (nombres, apellidos, cédula, fecha de nacimiento, género, teléfono, email, ciudad, contraseña) + **Historial médico** (los campos de `paciente_historial`). Al enviar → `paciente_registro` → guarda token en `localStorage` → redirige a `paciente-portal.html`.

> Nota: se conserva la columna existente `pacientes.nombre` (una sola columna) para el **nombre completo**. El form recoge "Nombres" + "Apellidos" y el backend los concatena en `nombre` (`trim(nombres.' '.apellidos)`). No se agrega columna `apellido`.

### `paciente-portal.html` (nueva)
- **Login** (email + password) si no hay sesión; link a `registro-paciente.html` y a recuperar contraseña.
- **Dashboard** (con token): 
  - **Próximas citas** (médico, fecha, sala de video).
  - **Historial de citas** (pasadas, con diagnóstico/indicaciones de cada consulta si el médico las escribió).
  - **Mi historial médico** (ver + editar → `paciente_historial_actualizar`).
  - **Mi perfil** (editar datos básicos + cambiar contraseña).
- Patrón visual/estructura equivalente a `medico-portal.html` (sidebar + secciones, `authHeaders` con `X-Paciente-Token`).

### `pacientes.html` (modificar — búsqueda/agendamiento)
- Al cargar, lee `paciente_token` de `localStorage`.
- **Sin sesión:** al intentar agendar, muestra aviso "Inicia sesión o regístrate para agendar" con enlaces a `paciente-portal.html` (login) y `registro-paciente.html`. La navegación de médicos/perfiles sigue visible sin login.
- **Con sesión:** el modal de reserva ya NO pide nombre/edad/email (vienen de la cuenta); pide solo motivo/alergias de esa consulta + método de pago. Envía con `X-Paciente-Token`. Se conserva la opción de **cortesía**.
- El modal de emergencia igual: requiere sesión.

### `medico-portal.html` (modificar)
En la sección **Mis reservas**, cada reserva agrega:
- Botón **"Ver historial del paciente"** → modal con historial médico + notas de consultas previas (`medico_ver_historial_paciente`).
- Botón **"Nota clínica"** → modal para escribir/editar diagnóstico, indicaciones y notas de esa consulta (`medico_guardar_nota`).

### `index.html` (modificar)
Agregar acceso "Soy paciente / Mi cuenta" en el nav (→ `paciente-portal.html`). La ruta "Buscar médico" sigue llevando a `pacientes.html`, que ahora exige login para agendar.

---

## Seguridad y privacidad

- Un médico solo accede al historial de pacientes con los que tiene ≥1 reserva (verificación en `medico_ver_historial_paciente`).
- El paciente solo ve/edita su propio historial y sus propias citas (via `checkPaciente`).
- Notas clínicas visibles para el paciente (decisión confirmada).
- Contraseñas con `password_hash` BCRYPT; nunca en texto plano. `api.config.php` sigue gitignored.
- No se tocan `eneural.org` ni `panel.eneural.org`.

---

## Casos borde

| Caso | Manejo |
|------|--------|
| Email ya registrado (con password) | Error "ya existe una cuenta con ese correo". |
| Email existe como invitado (sin password) | El registro lo reclama (fija password + rellena datos). |
| Paciente intenta agendar sin sesión | Aviso + enlaces a login/registro; no se crea reserva. |
| Médico intenta ver historial de un paciente sin cita con él | 403. |
| Reserva de cortesía | Sigue funcionando; ahora con `paciente_id` de la cuenta. |
| Nota clínica en reserva ajena | Rechazada (verifica `medico_id`). |
| Paciente sin historial aún | El panel muestra el form vacío para completarlo. |

---

## Archivos a modificar / crear

- `schema.sql` — extender `pacientes` + 2 tablas nuevas (documental).
- Migración SQL en el NAS (base `mediconline`).
- `api.php` — `checkPaciente` + 8 endpoints + `crearReserva`/`reservar_emergencia` con auth de paciente + header CORS.
- `pacientes.html` — gate de login + modal de reserva sin datos personales.
- `registro-paciente.html` (nueva).
- `paciente-portal.html` (nueva).
- `medico-portal.html` — botones "Ver historial" y "Nota clínica" en reservas.
- `index.html` — enlace a cuenta de paciente.

No se modifica la lógica de pago de tarjeta/PayPal ni `api.config.php`.

---

## Criterios de aceptación

1. Un paciente puede registrarse (cuenta + historial) y quedar logueado.
2. Un paciente puede iniciar sesión y ver su panel: próximas citas, historial de citas y su historial médico.
3. El paciente puede editar su historial médico y su perfil.
4. Sin sesión no se puede agendar; el sistema invita a registrarse/iniciar sesión.
5. Con sesión, el agendamiento usa los datos de la cuenta (no los pide de nuevo) y conserva la opción de cortesía.
6. El médico ve el historial médico y las notas previas de un paciente con el que tiene cita, y solo de esos.
7. El médico puede escribir/editar una nota clínica (diagnóstico/indicaciones/notas) por consulta.
8. Las notas clínicas aparecen en el historial de citas del paciente.
9. Un médico no puede ver el historial de un paciente con el que no tiene ninguna cita (403).
