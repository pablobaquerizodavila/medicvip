# 🩺 MedicOnline

Plataforma de telemedicina para agendar y gestionar consultas médicas online, con sistema de pagos en custodia (authorize & capture), portal para médicos y panel de administración.

> **Stack:** PHP 8 · MySQLi · HTML/CSS/JS Vanilla · Synology NAS (DSM)

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
- [Cron Jobs](#-cron-jobs)
- [Roadmap](#-roadmap)

---

## ✨ Características

- **Búsqueda de médicos** por especialidad con filtros
- **Registro de médicos** con foto de perfil, disponibilidad y datos bancarios
- **Sistema de reservas** con validación de disponibilidad
- **Pagos en custodia** — el paciente autoriza el cobro al agendar; se ejecuta solo cuando el médico confirma la consulta
- **Portal del médico** — gestión de agenda, perfil, consultas y pagos
- **Panel de administración** — gestión de médicos, reservas, estadísticas y reembolsos manuales
- **Reembolsos automáticos** vía cron job si el médico no confirma en 24h
- **Comisión configurable** (por defecto 15% para la plataforma)

---

## 📁 Estructura del proyecto

```
mediconline/
├── index.html              # Landing page pública
├── pacientes.html          # Búsqueda y agendamiento de consultas
├── registro-medico.html    # Formulario de registro para médicos
├── medico-portal.html      # Portal del médico (agenda, perfil, pagos)
├── admin.html              # Panel de administración
├── api.php                 # API REST backend (NO subir con credenciales)
├── api.config.php          # Configuración con credenciales (en .gitignore)
├── api.config.example.php  # Plantilla de configuración sin credenciales
├── cron_reembolsos.php     # Script para reembolsos automáticos (cron job)
├── uploads/
│   └── fotos/              # Fotos de perfil de médicos
└── cron.log                # Log del cron job (generado automáticamente)
```

---

## ⚙️ Requisitos

- PHP 8.0 o superior con extensiones: `mysqli`, `curl`, `json`, `mbstring`
- MySQL 5.7+ / MariaDB 10.3+
- Servidor web: Apache/Nginx o DSM Web Station (Synology)
- Acceso a cron jobs (Synology DSM → Programador de tareas)

---

## 🚀 Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/TU_USUARIO/mediconline.git
cd mediconline
```

### 2. Configurar credenciales

```bash
cp api.config.example.php api.config.php
nano api.config.php   # Editar con tus datos reales
```

### 3. Crear la base de datos

```bash
mysql -u root -p < database/schema.sql
```

### 4. Configurar permisos de uploads

```bash
mkdir -p uploads/fotos
chmod 775 uploads/fotos
```

### 5. En Synology DSM

Copiar archivos a `/volume2/web/mediconline/` y asegurarse de que PHP tenga permisos de escritura sobre `uploads/fotos/`.

---

## 🔧 Configuración

Editar `api.config.php` (nunca subir este archivo al repositorio):

```php
// Base de datos
define('DB_HOST',   '127.0.0.1');
define('DB_PORT',   3306);
define('DB_NAME',   'mediconline');
define('DB_USER',   'tu_usuario');
define('DB_PASS',   'tu_password');
define('DB_SOCKET', '/run/mysqld/mysqld10.sock'); // Solo Synology

// Uploads
define('UPLOAD_DIR', __DIR__ . '/uploads/fotos/');
define('UPLOAD_URL', '/mediconline/uploads/fotos/');

// Comisión de la plataforma (0.15 = 15%)
define('COMMISSION_RATE', 0.15);

// Credenciales del panel admin
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'TuPasswordSeguro!');

// Clave secreta para el cron job
define('CRON_KEY', 'clave_secreta_unica');
```

---

## 🗄️ Base de datos

### Tablas principales

| Tabla | Descripción |
|---|---|
| `medicos` | Datos de registro y autenticación |
| `medico_especialidad` | Especialidad, universidad, biografía |
| `medico_disponibilidad` | Slots horarios disponibles |
| `medico_pago` | Datos bancarios y tarifa |
| `reservas` | Reservas con estado de pago y custodia |
| `transacciones` | Registro de cobros, comisiones y reembolsos |

### Estados de una reserva

```
pendiente_pago → en_custodia → pagado       (flujo exitoso)
                             → reembolsado  (no se realizó la consulta)
```

### Estados de la consulta

```
agendada → confirmada    (médico confirmó que atendió)
         → cancelada     (cancelada por admin o reembolso)
         → no_realizada  (venció el límite de 24h sin confirmar)
```

---

## 📡 API Reference

**Base URL:** `https://tu-dominio.com/mediconline/api.php`

### Público

| Método | Action | Descripción |
|---|---|---|
| GET | `test` | Verificar conexión a BD |
| POST | `registro_medico` | Registrar nuevo médico |
| GET | `listar_medicos` | Listar médicos activos (filtro por especialidad) |
| POST | `reservar` | Crear una reserva |

### Portal del médico

| Método | Action | Descripción |
|---|---|---|
| POST | `medico_login` | Autenticar médico |
| GET | `medico_perfil` | Obtener perfil y agenda |
| POST | `medico_actualizar` | Actualizar datos del perfil |
| POST | `medico_toggle_estado` | Activar/desactivar disponibilidad |
| POST | `medico_cambiar_pass` | Cambiar contraseña |
| POST | `medico_recuperar` | Recuperar contraseña |
| POST | `confirmar_consulta` | Confirmar que atendió (libera pago) |

### Panel admin

| Método | Action | Descripción |
|---|---|---|
| POST | `admin_login` | Autenticar administrador |
| GET | `admin_medicos` | Listar todos los médicos |
| POST | `admin_eliminar` | Eliminar médico |
| POST | `admin_estado` | Cambiar estado médico (activo/inactivo/pendiente) |
| GET | `admin_reservas` | Listar reservas con filtros |
| GET | `admin_stats` | Estadísticas del dashboard |
| POST | `admin_reembolso` | Emitir reembolso manual |

### Cron (protegido por clave)

| Método | Action | Descripción |
|---|---|---|
| GET | `procesar_reembolsos` | Procesar reembolsos automáticos vencidos |

---

## 💳 Flujo de pagos

MedicOnline implementa un modelo de **authorize & capture** (pago en custodia):

```
1. Paciente agenda
      ↓
2. Se autoriza el monto en la tarjeta (fondos reservados, NO cobrados)
      ↓
3. Se realiza la consulta
      ↓
4a. Médico confirma → se ejecuta el capture → se cobra la tarjeta
    └─ 85% al médico · 15% comisión MedicOnline

4b. Médico NO confirma en 24h → autorización cancelada → tarjeta nunca cobrada
```

> **Pasarela elegida:** Mercado Pago (soporta authorize & capture en Ecuador con cancelación automática a los 5 días si no se captura).

**Comisión:** configurable en `COMMISSION_RATE` (por defecto 15%).

---

## ⏰ Cron Jobs

### Reembolsos automáticos

Configurar en Synology DSM → Panel de control → Programador de tareas:

```
Frecuencia: cada hora
Comando:    php /volume2/web/mediconline/cron_reembolsos.php
```

El script llama a `api.php?action=procesar_reembolsos` y registra el resultado en `cron.log`.

---

## 🗺️ Roadmap

- [x] Registro y login de médicos
- [x] Búsqueda y filtrado de médicos
- [x] Sistema de reservas con custodia
- [x] Portal del médico (agenda, perfil, confirmación de consultas)
- [x] Panel de administración con estadísticas
- [x] Reembolsos automáticos vía cron job
- [ ] **Integración Mercado Pago** (authorize & capture)
- [ ] **Notificaciones por email** (Synology mail server + PHPMailer)
- [ ] **Panel de historial y pagos** para el médico
- [ ] **Sistema de calificaciones y reseñas**
- [ ] Mejoras de UI/UX y flujo del sitio

---

## 🔒 Seguridad

- Contraseñas hasheadas con `password_hash()` (bcrypt)
- Consultas SQL con prepared statements (protección contra SQLi)
- Datos de tarjetas nunca almacenados localmente (manejados por pasarela)
- Credenciales separadas en `api.config.php` (excluido del repositorio)
- CORS configurado — ajustar `Access-Control-Allow-Origin` en producción

---

## 👤 Autor

Desarrollado con asistencia de Claude (Anthropic).

---

## 📄 Licencia

Uso privado — todos los derechos reservados.
