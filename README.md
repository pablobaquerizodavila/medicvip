# 🩺 MedicVIP

Plataforma de telemedicina para agendar y gestionar consultas médicas online, con sistema de pagos en custodia (authorize & capture), portal para médicos, panel de administración y consultas de emergencia.

🌐 **Producción:** https://medicvip.org

> **Stack:** PHP 8.2 · MariaDB 10 (mysqli) · HTML/CSS/JS Vanilla · Synology NAS (DSM Web Station)

---

## 📋 Tabla de contenidos

- [Características](#-características)
- [Estructura del proyecto](#-estructura-del-proyecto)
- [Requisitos](#-requisitos)
- [Instalación](#-instalación)
- [Configuración](#-configuración)
- [Base de datos](#-base-de-datos)
- [API Reference](#-api-reference)
- [Flujo de pagos](#-flujo-de-pagos)
- [Consultas de emergencia](#-consultas-de-emergencia)
- [Cron Jobs](#-cron-jobs)
- [Despliegue en Synology](#-despliegue-en-synology)
- [Seguridad](#-seguridad)
- [Roadmap](#-roadmap)

---

## ✨ Características

- **Búsqueda de médicos** por especialidad con filtros
- **Registro de médicos** con foto de perfil, disponibilidad y datos bancarios
- **Sistema de reservas** con validación de disponibilidad y emails automáticos
- **Pagos en custodia** — el paciente autoriza el cobro al agendar; se ejecuta solo cuando el médico confirma la consulta
- **Consultas de emergencia** — el paciente conecta con un médico activo inmediatamente, con tarifa premium configurable (default 1.5×)
- **Portal del médico** — gestión de agenda, perfil, consultas y pagos
- **Panel de administración** — gestión de médicos, reservas, estadísticas y reembolsos manuales
- **Reembolsos automáticos** vía cron job si el médico no confirma en 24h (2h para emergencias)
- **Recordatorios** automáticos el día de la consulta al paciente y al médico
- **Salas de video Jitsi** generadas únicas por reserva, con token de acceso para el paciente
- **Notificaciones por email** desde SMTP local MailPlus (Synology)
- **Comisión configurable** (default 15% para la plataforma)

---

## 📁 Estructura del proyecto

```
medicvip/
├── index.html              # Landing page pública
├── pacientes.html          # Búsqueda, agendamiento y consultas de emergencia
├── registro-medico.html    # Formulario de registro para médicos
├── medico-portal.html      # Portal del médico (agenda, perfil, confirmar consultas)
├── admin.html              # Panel de administración
├── api.php                 # API REST backend (action-based routing)
├── api.config.example.php  # Plantilla de configuración (sin credenciales)
├── api.config.php          # Configuración real con credenciales (gitignored)
├── cron_reembolsos.php     # Cron job de reembolsos automáticos
├── schema.sql              # Schema de la BD (8 tablas + 2 vistas)
├── uploads/
│   └── fotos/              # Fotos de perfil de médicos (gitignored)
└── cron.log                # Log del cron job (generado, gitignored)
```

---

## ⚙️ Requisitos

- PHP 8.0+ (probado en 8.2) con extensiones `mysqli`, `curl`, `json`, `mbstring`
- MariaDB 10.3+ / MySQL 5.7+
- Servidor web: DSM Web Station (Synology) con servicio `nginx_php`, o Nginx/Apache externo
- Acceso a cron jobs (DSM Programador de tareas o `/etc/crontab` en Linux)
- SMTP local o remoto para emails (en producción se usa MailPlus de Synology vía `fsockopen` 127.0.0.1:25)

---

## 🚀 Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/pablobaquerizodavila/medicvip.git
cd medicvip
```

### 2. Crear `api.config.php`

```bash
cp api.config.example.php api.config.php
# editar y rellenar credenciales reales
chmod 640 api.config.php   # restringir lectura solo al user y al grupo http
```

### 3. Crear la base de datos

```bash
mysql -u root -p < schema.sql
```

Luego crear un usuario dedicado de aplicación (NO usar root):

```sql
CREATE USER 'mediconline'@'localhost' IDENTIFIED BY 'TU_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON mediconline.* TO 'mediconline'@'localhost';
FLUSH PRIVILEGES;
```

### 4. Permisos de uploads

```bash
mkdir -p uploads/fotos
chmod 775 uploads/fotos
chown -R pbaquerizo:http uploads
```

---

## 🔧 Configuración

Ver `api.config.example.php` para todas las claves. Las críticas:

```php
// Base de datos
define('DB_USER', 'mediconline');
define('DB_PASS', 'tu_password');

// Admin
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'tu_password_admin');

// Seguridad
define('CRON_KEY', 'clave_secreta_unica_para_cron');
define('ALLOWED_ORIGINS', ['https://medicvip.org']);

// Negocio
define('COMMISSION_RATE', 0.15);            // 15% comisión plataforma
define('EMERGENCY_RATE_MULTIPLIER', 1.5);   // tarifa emergencia = tarifa × 1.5
```

---

## 🗄️ Base de datos

### Tablas

| Tabla | Descripción |
|---|---|
| `medicos` | Datos de registro, hash de password (bcrypt), foto, estado (`activo`/`pendiente`/`suspendido`) |
| `medico_especialidad` | Especialidad, universidad, biografía, años de experiencia |
| `medico_disponibilidad` | Slots horarios (día de la semana + hora) |
| `medico_pago` | Tarifa, duración consulta, datos bancarios |
| `pacientes` | Pacientes únicos por email |
| `reservas` | Reservas con sala de video Jitsi, token de acceso, estados |
| `transacciones` | Registro de cobros, comisiones, liberaciones, reembolsos |

### Vistas

- `v_medicos_activos` — médicos en estado `activo` con sus datos de perfil + tarifa
- `v_reservas_detalle` — reservas con joins a médico, paciente y especialidad

### Estados de una reserva

```
estado_pago:      pendiente_pago → en_custodia → pagado / reembolsado
estado_consulta:  agendada → confirmada / cancelada / no_realizada
```

---

## 📡 API Reference

**Base URL:** `https://medicvip.org/api.php`

Todas las respuestas son JSON con la forma `{ "ok": bool, "data"|"error": ... }`.

### Público

| Método | Action | Descripción |
|---|---|---|
| GET | `test` | Verificar conexión, versión PHP, tablas |
| POST | `registro_medico` | Registrar nuevo médico (con foto base64 opcional) |
| GET | `listar_medicos` | Listar médicos activos (filtro `?especialidad=`) |
| POST | `reservar` | Crear una reserva |
| GET | `listar_emergencias` | Listar médicos disponibles ahora (con `tarifa_final` premium) |
| POST | `reservar_emergencia` | Crear reserva inmediata con horario = ahora |
| GET | `paciente_sala` | Obtener sala de video por `?token=&email=` |

### Portal del médico (auth: header `X-Medico-Token`)

| Método | Action | Descripción |
|---|---|---|
| POST | `medico_login` | Autenticar médico, devuelve token |
| GET | `medico_perfil` | Perfil + disponibilidad + reservas del médico |
| POST | `medico_actualizar` | Actualizar datos del perfil |
| POST | `medico_toggle_estado` | Activar/desactivar disponibilidad |
| POST | `medico_cambiar_pass` | Cambiar contraseña (requiere actual) |
| POST | `medico_recuperar` | Generar password temporal por email |
| POST | `confirmar_consulta` | Confirmar consulta (libera el pago) |
| POST | `medico_toggle_emergencia` | Activar/desactivar disponibilidad para consultas de emergencia |

### Panel admin (auth: header `X-Admin-Token`)

| Método | Action | Descripción |
|---|---|---|
| POST | `admin_login` | Autenticar admin, devuelve token |
| GET | `admin_medicos` | Listar todos los médicos (todos los estados) |
| POST | `admin_eliminar` | Eliminar médico |
| POST | `admin_estado` | Cambiar estado (activo/pendiente/suspendido) |
| GET | `admin_reservas` | Listar todas las reservas |
| GET | `admin_stats` | Estadísticas del dashboard |
| POST | `admin_reembolso` | Emitir reembolso manual |
| POST | `admin_eliminar_reserva` | Eliminar reserva (con advertencia si tenía pago activo) |

### Cron (auth: query `?cron_key=`)

| Método | Action | Descripción |
|---|---|---|
| GET | `procesar_reembolsos` | Procesar reembolsos automáticos vencidos |
| GET | `enviar_recordatorios` | Enviar recordatorios del día a paciente y médico |

---

## 💳 Flujo de pagos

MedicVIP implementa un modelo de **authorize & capture** (pago en custodia):

```
1. Paciente agenda
      ↓
2. Se autoriza el monto en la tarjeta (fondos reservados, NO cobrados)
      ↓
3. Se realiza la consulta
      ↓
4a. Médico confirma → se ejecuta el capture → se cobra la tarjeta
    └─ 85% al médico · 15% comisión MedicVIP

4b. Médico NO confirma en 24h → autorización cancelada → tarjeta nunca cobrada
```

> **Pasarela elegida:** Mercado Pago (soporta authorize & capture en Ecuador, cancelación automática a los 5 días si no se captura).
> **Estado:** integración pendiente (Fase 6B). Actualmente las reservas pasan a `en_custodia` sin pasarela real.

---

## 🚨 Consultas de emergencia

A diferencia del flujo normal (paciente escoge horario futuro), una emergencia conecta inmediatamente:

```
1. Paciente abre "🚨 Consulta ahora" en pacientes.html
2. /listar_emergencias devuelve médicos activos con tarifa premium (tarifa × EMERGENCY_RATE_MULTIPLIER)
3. Paciente selecciona médico y rellena datos mínimos
4. /reservar_emergencia crea reserva con horario = NOW(), ventana de confirmación = 2h
5. Sala Jitsi generada al instante, email al médico con tag 🚨 EMERGENCIA
6. Paciente entra al video, médico confirma → liberación de pago
```

---

## ⏰ Cron Jobs

### Reembolsos automáticos (cada hora)

```cron
0  *  *  *  *  root  /usr/local/bin/php82 /volume2/web/medicvip/cron_reembolsos.php > /dev/null 2>&1
```

Llama a `api.php?action=procesar_reembolsos&cron_key=…` con el header `Host: medicvip.org` (necesario porque nginx hace virtual hosting).

### Recordatorios diarios (8:30 AM)

```cron
30 8  *  *  *  root  /usr/local/bin/php82 /volume2/web/medicvip/cron_recordatorios.php > /dev/null 2>&1
```

Llama a `api.php?action=enviar_recordatorios&cron_key=…`. Para cada reserva con `DATE(horario) = hoy` y `estado_consulta = agendada`, manda un email al paciente con su sala Jitsi y al médico con el detalle del paciente. Marca `recordatorio_enviado=1` para no duplicar.

---

## 🖥️ Despliegue en Synology

El sitio corre en `/volume2/web/medicvip/` con un servicio Web Station tipo `nginx_php` (PHP 8.2 profile compartido con siscormed-php). El portal `medicvip.org` apunta a ese servicio.

Para crear un sitio nuevo similar (otro dominio):

1. **DSM Web Station → Crear servicio** tipo `nginx_php`, raíz `/volume2/web/TUSITIO/`, perfil PHP 8.2.
2. **DSM Web Station → Crear portal** tipo "server", FQDN `tudominio.com`, asociar al servicio.
3. **DSM Certificado** → emitir Let's Encrypt (o usar reverse proxy externo con TLS).
4. **Cron** vía `/etc/crontab` o DSM Task Scheduler.

---

## 🔒 Seguridad

- Contraseñas hasheadas con `password_hash(BCRYPT)`
- Prepared statements en todas las queries (protección SQLi)
- `api.config.php` excluido del repo y `chmod 640` en el server
- CORS restringido a `ALLOWED_ORIGINS` (en producción solo `medicvip.org`)
- Datos de tarjetas NUNCA almacenados localmente (delegados a pasarela)
- **Auth admin y médico vía JWT HS256** firmado con `JWT_SECRET`, expiración configurable (default 8h). Tokens incluyen `role`, `sub`, `iat`, `exp` y se validan con `hash_equals` para evitar timing attacks.
- **Email saliente firmado con DKIM** (selector `mail`, RSA 2048-bit) vía rspamd local. SPF y DMARC publicados en GoDaddy (DMARC en modo `p=quarantine`).

---

## 🗺️ Roadmap

**Hechos:**
- [x] Registro y login de médicos
- [x] Búsqueda y filtrado de médicos
- [x] Sistema de reservas con pago en custodia
- [x] Portal del médico (agenda, perfil, confirmación de consultas)
- [x] Panel de administración con estadísticas
- [x] Reembolsos automáticos vía cron
- [x] Notificaciones por email (paciente y médico)
- [x] Consultas de emergencia con tarifa premium
- [x] Schema SQL formal en el repo
- [x] Despliegue en producción (https://medicvip.org)

**Pendientes:**
- [ ] **Fase 6B — Mercado Pago real** (authorize & capture con webhook)
- [x] **Fase 6C — DKIM** activo (rspamd selector `mail`) + SPF y DMARC ya publicados en GoDaddy
- [ ] **Fase 6D — Notificaciones WhatsApp** (WhatsApp Cloud API)
- [ ] **Fase 6E — Historial de pagos** del médico en el portal
- [ ] **Fase 6F — Calificaciones y reseñas** de pacientes
- [x] **Fase 6G — JWT con expiración** para auth admin y médico (HS256, 8h)
- [x] **Fase 6H — Toggle "disponible para emergencias"** en el portal médico
- [x] **Fase 6I — Cron de recordatorios** diarios (8:30 AM via /etc/crontab)
- [ ] **Fase 6J — Mejoras de UI/UX** y flujo móvil

---

## 👤 Autor

Pablo Baquerizo Dávila — desarrollado con asistencia de Claude (Anthropic).

---

## 📄 Licencia

Uso privado — todos los derechos reservados.
