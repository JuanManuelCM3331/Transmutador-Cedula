<?php
declare(strict_types=1);

/**
 * Configuración central de Cedula API.
 * Puedes sobreescribir cualquier valor mediante variables de entorno (.env / servidor)
 * o editando este archivo directamente para adaptarlo a tu proyecto.
 */
return [
    // Driver de almacenamiento: 'json' (archivos en storage/) o 'sqlite' (base de datos SQLite en storage/cedulas.sqlite)
    'storage_driver' => getenv('CEDULA_STORAGE_DRIVER') ?: 'json',

    // Ruta donde se almacenan los registros e imágenes
    'storage_dir' => getenv('CEDULA_STORAGE_DIR') ?: (__DIR__ . '/storage'),

    // Tamaño máximo de imagen permitido (por defecto 8 MB)
    'max_image_size' => (int)(getenv('CEDULA_MAX_IMAGE_SIZE') ?: (8 * 1024 * 1024)),

    // Orígenes permitidos para peticiones CORS (ej. '*' o 'https://mi-app.com')
    'cors_allowed_origins' => getenv('CEDULA_CORS_ORIGINS') ?: '*',

    // API Key opcional para clientes externos (Header X-API-Key o Authorization: Bearer).
    // Si está vacía (''), se permite el acceso con token CSRF de sesión o peticiones REST directas.
    'api_key' => getenv('CEDULA_API_KEY') ?: '',

    // Exigir obligatoriamente CSRF o API Key al guardar (true recomendado en producción)
    'require_auth_on_save' => getenv('CEDULA_REQUIRE_AUTH') !== false
        ? filter_var(getenv('CEDULA_REQUIRE_AUTH'), FILTER_VALIDATE_BOOLEAN)
        : true,

    // URL de Webhook opcional: si se configura, cada vez que se guarde una cédula se enviará un POST JSON a esta URL
    'webhook_url' => getenv('CEDULA_WEBHOOK_URL') ?: '',
];
