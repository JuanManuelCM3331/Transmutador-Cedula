# 🪪 Cédula Colombiana API & SDK (v2.0 Universal)

Sistema completo, ligero y adaptable a **cualquier tipo de proyecto** para escanear, decodificar, validar y almacenar datos de documentos de identidad colombianos (**Cédula de Ciudadanía Amarilla**, **Cédula Digital Policarbonato** y **Tarjeta de Identidad**).

Soporta lectura de **código de barras 2D PDF417**, **zona MRZ TD1 (ICAO 9303)** mediante OCR y respaldo óptico para documentos clásicos deteriorados.

---

## ✨ Características Principales

- **Adaptable a cualquier proyecto (4 modos de integración)**:
  1. **SDK JavaScript Universal (`cedula-sdk.js` / `mrz.js`)**: ES Module sin dependencias de framework (compatible con React, Vue, Angular, Astro o HTML/JS puro).
  2. **API REST Completa (`api.php` / `guardar.php`)**: Endpoints JSON con soporte CORS, autenticación por CSRF o API Key (`X-API-Key` / `Bearer`), Webhooks y exportación a CSV (Excel) / JSON.
  3. **Motor PHP Nativo (`src/CedulaParser.php`)**: Clase independiente en PHP 8+ lista para importar en Laravel, Symfony, WordPress o proyectos legacy para decodificar PDF417 y MRZ directamente en el backend.
  4. **Widget Embebible (`index.php?mode=embed`)**: Incrustable vía `<iframe>` o popup en cualquier ERP/CRM, emitiendo resultados en tiempo real por `window.postMessage`.
- **Múltiples métodos de captura**:
  - **Subida de imagen / Drag & Drop / Portapapeles (`Ctrl + V`)** con pre-procesamiento multi-escala, recorte inteligente, alto contraste y rotación de 180°.
  - **Cámara en vivo** con guía visual y captura de fotograma para MRZ (Cédula Digital).
  - **Pistola Lectora 2D USB (HID) / Texto Crudo**, tolerando lecturas donde los bytes nulos (`\x00`) fueron eliminados por el lector físico.
- **Almacenamiento flexible (`config.php`)**:
  - **Archivos JSON** en `storage/` (por defecto, cero configuración).
  - **Base de datos SQLite** automática (`storage/cedulas.sqlite`).

---

## 📁 Estructura del Proyecto

```text
Cedula_api/
├── index.php              # Interfaz moderna (Escáner, Tarjeta Visual, Historial y Hub de Integración)
├── api.php                # API REST (health, parse, save, list, get, delete, export CSV/JSON)
├── guardar.php            # Endpoint clásico de guardado (100% retrocompatible)
├── config.php             # Configuración central (driver JSON/SQLite, CORS, API Key, Webhook)
├── cedula-sdk.js          # SDK JavaScript (ES Module): PDF417 + MRZ + Auto-fill de formularios
├── mrz.js                 # Parser ICAO 9303 TD1 + validación de dígitos + respaldo OCR Cédula Amarilla
├── src/
│   └── CedulaParser.php   # Clase PHP 8+ independiente para parsear PDF417 y MRZ en el servidor
└── storage/               # Almacenamiento protegido por .htaccess
```

---

## 🚀 Ejecución Rápida

Con el servidor integrado de PHP o desde XAMPP/Apache/Nginx:

```bash
php -S localhost:8000
# Abre http://localhost:8000 en tu navegador
```

> **Nota sobre la cámara**: Los navegadores exigen `HTTPS` o `http://localhost` para conceder permisos de cámara (`getUserMedia`).

---

## 🔌 Cómo Adaptarlo a tu Proyecto

### 1. En Frontend (JavaScript / React / Vue / HTML)

```javascript
import {
  CedulaReader,
  parseAuto,
  fillFormWithCedula
} from './cedula-sdk.js';

// A) Escanear desde un archivo de imagen (<input type="file">)
const reader = new CedulaReader();
const { data, format } = await reader.scanFile(fileInput.files[0]);
console.log(data.documentInfo.documentNumber, data.fullName, format);

// B) Decodificar texto crudo (ej. pistola lectora USB o cadena MRZ)
const resultado = parseAuto(textoCrudo);

// C) Auto-llenar cualquier formulario HTML automáticamente
fillFormWithCedula('#formularioRegistro', data);
```

### 2. Mediante la API REST (`api.php`)

- **Decodificar PDF417 o MRZ en el servidor (`POST api.php?action=parse`)**:
  ```bash
  curl -X POST http://localhost:8000/api.php?action=parse \
    -H "Content-Type: application/json" \
    -d '{"raw": "ICCOL0019482732<<<<<<<<<<<<<<<\n9805242F3205242COL1020304050<3\nGARCIA<RESTREPO<<VALENTINA<SOF"}'
  ```
- **Guardar registro (`POST api.php?action=save` o `POST guardar.php`)**:
  Acepta `multipart/form-data` (con imagen opcional) o `application/json`.
- **Listar / Buscar registros (`GET api.php?action=list&q=1020304050`)**
- **Exportar a Excel CSV o JSON (`GET api.php?action=export&format=csv`)**

### 3. En Backend PHP (Laravel, Symfony, WordPress, PHP nativo)

```php
require_once __DIR__ . '/src/CedulaParser.php';
use CedulaApi\CedulaParser;

$datos = CedulaParser::parseAuto($cadenaPdf417oMrz);
if ($datos !== null) {
    echo $datos['documentInfo']['documentNumber']; // Ej: 1020304050
    echo $datos['fullName'];                       // Ej: VALENTINA SOFIA GARCIA RESTREPO
    echo $datos['birthDateISO'];                   // Ej: 1998-05-24
}
```

### 4. Como Widget Embebido (`<iframe>` / Popup)

```html
<iframe src="http://localhost:8000/index.php?mode=embed" width="100%" height="580" frameborder="0"></iframe>
<script>
  window.addEventListener('message', (event) => {
    if (event.data?.type === 'CEDULA_SCANNED') {
      console.log('Datos de la cédula:', event.data.data);
    }
  });
</script>
```

---

## ⚙️ Configuración (`config.php` / Variables de Entorno)

| Variable de Entorno | Clave en `config.php` | Por defecto | Descripción |
| :--- | :--- | :--- | :--- |
| `CEDULA_STORAGE_DRIVER` | `storage_driver` | `'json'` | `'json'` (archivos) o `'sqlite'` (base de datos SQLite) |
| `CEDULA_STORAGE_DIR` | `storage_dir` | `./storage` | Carpeta donde se guardan registros e imágenes |
| `CEDULA_CORS_ORIGINS` | `cors_allowed_origins` | `'*'` | Orígenes permitidos para peticiones CORS |
| `CEDULA_API_KEY` | `api_key` | `''` | API Key opcional (`X-API-Key` o `Bearer`) para clientes externos |
| `CEDULA_WEBHOOK_URL` | `webhook_url` | `''` | URL a la que se notificará por `POST` cada registro guardado |

---

## 📄 Licencia

Este proyecto está distribuido bajo la licencia **GNU General Public License v3.0 (GPL-3.0)** — Consulta el archivo [`LICENSE`](./LICENSE) para el texto legal completo.

- **Copyright © 2026 Juan Manuel Cardona Molina**
- Eres libre de usar, estudiar, compartir y modificar este software, siempre que cualquier distribución o trabajo derivado mantenga la misma licencia abierta **GPL v3.0** y el reconocimiento de autoría.

