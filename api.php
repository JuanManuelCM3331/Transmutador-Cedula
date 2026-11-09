<?php
declare(strict_types=1);

require_once __DIR__ . '/src/CedulaParser.php';

use CedulaApi\CedulaParser;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/config.php';

// --- Cabeceras CORS para permitir integración con frontends externos (React, Vue, Mobile, etc.)
$allowedOrigins = (string)($config['cors_allowed_origins'] ?? '*');
header('Access-Control-Allow-Origin: ' . $allowedOrigins);
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-API-Key, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

/**
 * Envía respuesta JSON de error y termina la ejecución.
 */
function apiFail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Envía respuesta JSON exitosa y termina la ejecución.
 *
 * @param array<string, mixed> $payload
 */
function apiSuccess(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => true], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Obtiene el cuerpo de la petición (soporta application/json y form-data).
 *
 * @return array<string, mixed>
 */
function getRequestBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $rawBody = file_get_contents('php://input');
        $decoded = is_string($rawBody) ? json_decode($rawBody, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

/**
 * Verifica autorización mediante Token CSRF de sesión o API Key (Header X-API-Key / Bearer).
 *
 * @param array<string, mixed> $body
 * @param array<string, mixed> $config
 */
function verifyAuthorization(array $body, array $config): void
{
    $configuredKey = (string)($config['api_key'] ?? '');
    $headerKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($headerKey === '' && preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $headerKey = trim($m[1]);
    }

    // 1. Si el cliente envía API Key válida (cuando está configurada)
    if ($configuredKey !== '' && $headerKey !== '' && hash_equals($configuredKey, $headerKey)) {
        return;
    }

    // 2. Verificar Token CSRF (en body o en header X-CSRF-Token)
    $sessionCsrf = (string)($_SESSION['csrf'] ?? '');
    $providedCsrf = (string)($body['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if ($sessionCsrf !== '' && $providedCsrf !== '' && hash_equals($sessionCsrf, $providedCsrf)) {
        return;
    }

    // 3. Si en config.php se deshabilitó require_auth_on_save (útil para APIs internas sin sesión) y no hay api_key fija
    if (empty($config['require_auth_on_save']) && $configuredKey === '') {
        return;
    }

    apiFail('No autorizado: Token CSRF o API Key inválidos', 403);
}

/**
 * Carga todos los registros almacenados según el driver configurado ('json' o 'sqlite').
 *
 * @param array<string, mixed> $config
 * @return list<array<string, mixed>>
 */
function loadAllRecords(array $config): array
{
    $dir = (string)$config['storage_dir'];
    $driver = (string)($config['storage_driver'] ?? 'json');

    if (!is_dir($dir)) {
        return [];
    }

    if ($driver === 'sqlite') {
        $pdo = CedulaParser::getSqliteConnection($dir);
        $stmt = $pdo->query('SELECT payload_json FROM cedulas ORDER BY created_at DESC LIMIT 500');
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $item = json_decode((string)$row['payload_json'], true);
            if (is_array($item)) {
                $rows[] = $item;
            }
        }
        return $rows;
    }

    // Driver JSON por defecto
    $files = glob($dir . '/*.json') ?: [];
    $records = [];
    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) {
            continue;
        }
        $item = json_decode($content, true);
        if (is_array($item) && isset($item['id'])) {
            $records[] = $item;
        }
    }

    usort($records, static fn(array $a, array $b): int =>
        strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''))
    );

    return $records;
}

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Si se invoca sin action pero por POST, asumir 'save' o 'parse' según los campos
if ($action === '') {
    if ($method === 'POST') {
        $body = getRequestBody();
        $action = isset($body['raw']) && !isset($body['data']) ? 'parse' : 'save';
    } else {
        $action = 'health';
    }
}

switch ($action) {
    case 'health': {
        $records = loadAllRecords($config);
        apiSuccess([
            'service'        => 'Cedula Colombiana API',
            'version'        => '2.0.0',
            'storageDriver'  => $config['storage_driver'] ?? 'json',
            'savedCount'     => count($records),
            'endpoints'      => [
                'health' => 'GET  api.php?action=health',
                'parse'  => 'POST api.php?action=parse  (Body: { "raw": "..." })',
                'save'   => 'POST api.php?action=save   (Body: { "data": {...}, "csrf": "..." })',
                'list'   => 'GET  api.php?action=list   (Opcional: &q=texto&limit=50)',
                'get'    => 'GET  api.php?action=get&id=<id>',
                'export' => 'GET  api.php?action=export&format=csv|json',
                'delete' => 'POST api.php?action=delete&id=<id>',
            ],
        ]);
    }

    case 'parse': {
        if ($method !== 'POST') {
            apiFail('Método no permitido. Usa POST.', 405);
        }
        $body = getRequestBody();
        $raw = (string)($body['raw'] ?? '');
        if (trim($raw) === '') {
            apiFail('El campo "raw" (texto PDF417 o MRZ) es obligatorio.');
        }
        $parsed = CedulaParser::parseAuto($raw);
        if ($parsed === null) {
            apiFail('No se reconoció un formato válido de PDF417 ni MRZ colombiano en el texto enviado.', 422);
        }
        apiSuccess(['data' => $parsed]);
    }

    case 'save': {
        if ($method !== 'POST') {
            apiFail('Método no permitido. Usa POST.', 405);
        }
        $body = getRequestBody();
        verifyAuthorization($body, $config);

        $rawData = $body['data'] ?? null;
        if (is_string($rawData)) {
            $in = json_decode($rawData, true);
        } elseif (is_array($rawData)) {
            $in = $rawData;
        } elseif (!empty($body['raw']) && is_string($body['raw'])) {
            $in = CedulaParser::parseAuto($body['raw']);
        } else {
            $in = null;
        }

        if (!is_array($in)) {
            apiFail('Datos inválidos o mal formados.');
        }

        try {
            $record = CedulaParser::validateAndSanitize($in);
        } catch (\InvalidArgumentException $e) {
            apiFail($e->getMessage(), 400);
        }

        $dir = (string)$config['storage_dir'];
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            apiFail('No se pudo crear el directorio storage/', 500);
        }

        $id = bin2hex(random_bytes(8));
        $maxImageSize = (int)($config['max_image_size'] ?? (8 * 1024 * 1024));

        // --- Imagen adjunta (opcional)
        if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $f = $_FILES['image'];
            if ($f['size'] > $maxImageSize) {
                apiFail('La imagen supera el límite permitido de ' . round($maxImageSize / (1024 * 1024), 1) . ' MB');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
            if (!$ext) {
                apiFail('Formato de imagen no permitido (usa JPG, PNG o WebP)');
            }
            if (!move_uploaded_file($f['tmp_name'], "$dir/$id.$ext")) {
                apiFail('No se pudo guardar la imagen en el servidor', 500);
            }
            $record['image'] = "$id.$ext";
        }

        $record['id'] = $id;
        $record['createdAt'] = date('c');

        $driver = (string)($config['storage_driver'] ?? 'json');
        if ($driver === 'sqlite') {
            $pdo = CedulaParser::getSqliteConnection($dir);
            $stmt = $pdo->prepare('
                INSERT INTO cedulas (
                    id, document_number, document_type, first_name, middle_name,
                    last_name, second_last_name, full_name, birth_date_iso,
                    gender, blood_type, source, image, payload_json, created_at
                ) VALUES (
                    :id, :doc_num, :doc_type, :first_name, :middle_name,
                    :last_name, :second_last_name, :full_name, :birth_iso,
                    :gender, :blood, :source, :image, :payload, :created_at
                )
            ');
            $stmt->execute([
                ':id'               => $id,
                ':doc_num'          => $record['documentInfo']['documentNumber'],
                ':doc_type'         => $record['documentType'] ?? 'CC',
                ':first_name'       => $record['firstName'],
                ':middle_name'      => $record['middleName'],
                ':last_name'        => $record['lastName'],
                ':second_last_name' => $record['secondLastName'],
                ':full_name'        => $record['fullName'],
                ':birth_iso'        => $record['birthDateISO'],
                ':gender'           => $record['gender'],
                ':blood'            => $record['bloodType'],
                ':source'           => $record['source'],
                ':image'            => $record['image'] ?? null,
                ':payload'          => json_encode($record, JSON_UNESCAPED_UNICODE),
                ':created_at'       => $record['createdAt'],
            ]);
        } else {
            $written = file_put_contents(
                "$dir/$id.json",
                json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
            if ($written === false) {
                apiFail('No se pudo escribir el archivo JSON en storage/', 500);
            }
        }

        // --- Webhook opcional para notificar a sistemas externos (ERP, CRM, etc.)
        $webhookUrl = trim((string)($config['webhook_url'] ?? ''));
        if ($webhookUrl !== '' && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nUser-Agent: CedulaAPI/2.0\r\n",
                    'content' => (string)json_encode(['event' => 'cedula.saved', 'id' => $id, 'data' => $record], JSON_UNESCAPED_UNICODE),
                    'timeout' => 3,
                ],
            ]);
            @file_get_contents($webhookUrl, false, $ctx);
        }

        apiSuccess(['id' => $id, 'data' => $record]);
    }

    case 'list': {
        $records = loadAllRecords($config);
        $q = mb_strtolower(trim((string)($_GET['q'] ?? '')), 'UTF-8');
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));

        if ($q !== '') {
            $records = array_values(array_filter($records, static function (array $r) use ($q): bool {
                $haystack = mb_strtolower(implode(' ', [
                    $r['documentInfo']['documentNumber'] ?? '',
                    $r['firstName'] ?? '',
                    $r['middleName'] ?? '',
                    $r['lastName'] ?? '',
                    $r['secondLastName'] ?? '',
                    $r['bloodType'] ?? '',
                    $r['id'] ?? '',
                ]), 'UTF-8');
                return str_contains($haystack, $q);
            }));
        }

        $records = array_slice($records, 0, $limit);
        apiSuccess(['count' => count($records), 'items' => $records]);
    }

    case 'get': {
        $id = (string)($_GET['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            apiFail('ID de registro inválido', 400);
        }
        $records = loadAllRecords($config);
        foreach ($records as $r) {
            if (($r['id'] ?? '') === $id) {
                apiSuccess(['data' => $r]);
            }
        }
        apiFail('Registro no encontrado', 404);
    }

    case 'delete': {
        if ($method !== 'POST' && $method !== 'DELETE') {
            apiFail('Método no permitido. Usa POST o DELETE.', 405);
        }
        $body = getRequestBody();
        verifyAuthorization($body, $config);

        $id = (string)($_GET['id'] ?? ($body['id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{16}$/', $id)) {
            apiFail('ID de registro inválido', 400);
        }

        $dir = (string)$config['storage_dir'];
        $driver = (string)($config['storage_driver'] ?? 'json');
        $deleted = false;

        if ($driver === 'sqlite') {
            $pdo = CedulaParser::getSqliteConnection($dir);
            $stmt = $pdo->prepare('DELETE FROM cedulas WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $deleted = $stmt->rowCount() > 0;
        } else {
            $jsonPath = "$dir/$id.json";
            if (is_file($jsonPath)) {
                $deleted = @unlink($jsonPath);
            }
        }

        // Eliminar imagen asociada si existe
        foreach (['jpg', 'png', 'webp'] as $ext) {
            $imgPath = "$dir/$id.$ext";
            if (is_file($imgPath)) {
                @unlink($imgPath);
            }
        }

        if (!$deleted) {
            apiFail('Registro no encontrado', 404);
        }
        apiSuccess(['id' => $id, 'deleted' => true]);
    }

    case 'export': {
        $format = strtolower((string)($_GET['format'] ?? 'csv'));
        $records = loadAllRecords($config);

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="cedulas-export-' . date('Ymd-His') . '.json"');
            echo json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cedulas-export-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        // BOM UTF-8 para compatibilidad directa con Microsoft Excel
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            'ID',
            'Tipo Doc',
            'Numero Documento',
            'Primer Nombre',
            'Segundo Nombre',
            'Primer Apellido',
            'Segundo Apellido',
            'Fecha Nacimiento (ISO)',
            'Edad',
            'Sexo',
            'RH',
            'Fecha Vencimiento',
            'Codigo AFIS',
            'Tarjeta Dactilar',
            'Fuente',
            'Fecha Registro',
        ]);
        foreach ($records as $r) {
            fputcsv($out, [
                $r['id'] ?? '',
                $r['documentType'] ?? 'CC',
                $r['documentInfo']['documentNumber'] ?? '',
                $r['firstName'] ?? '',
                $r['middleName'] ?? '',
                $r['lastName'] ?? '',
                $r['secondLastName'] ?? '',
                $r['birthDateISO'] ?? ($r['birthDate'] ?? ''),
                $r['age'] ?? '',
                $r['gender'] ?? '',
                $r['bloodType'] ?? '',
                $r['expiryDateISO'] ?? '',
                $r['documentInfo']['afisCode'] ?? '',
                $r['documentInfo']['fingerCard'] ?? '',
                $r['source'] ?? '',
                $r['createdAt'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    default:
        apiFail('Acción desconocida: ' . $action, 400);
}
