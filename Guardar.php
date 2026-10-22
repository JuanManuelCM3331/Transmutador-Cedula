<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido', 405);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) fail('Token inválido', 403);

// --- Validar JSON recibido (nunca confiar en lo que manda el cliente)
$in = json_decode((string)($_POST['data'] ?? ''), true);
if (!is_array($in) || !isset($in['documentInfo']) || !is_array($in['documentInfo'])) fail('Datos inválidos');

$clean = fn($v, int $max = 60): string =>
    mb_substr(preg_replace('/[^\p{L}\p{N} \-+\/]/u', '', (string)$v), 0, $max);

$record = [
    'firstName'      => $clean($in['firstName'] ?? ''),
    'middleName'     => $clean($in['middleName'] ?? ''),
    'lastName'       => $clean($in['lastName'] ?? ''),
    'secondLastName' => $clean($in['secondLastName'] ?? ''),
    'birthDate'      => $clean($in['birthDate'] ?? '', 10),
    'birthDateISO'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['birthDateISO'] ?? '')) ? $in['birthDateISO'] : '',
    'bloodType'      => $clean($in['bloodType'] ?? '', 3),
    'gender'         => $clean($in['gender'] ?? '', 1),
    'expiryDateISO'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['expiryDateISO'] ?? '')) ? $in['expiryDateISO'] : '',
    'source'         => in_array($in['source'] ?? '', ['pdf417', 'mrz'], true) ? $in['source'] : '',
    'documentInfo'   => [
        'documentNumber' => $clean($in['documentInfo']['documentNumber'] ?? '', 12),
        'afisCode'       => $clean($in['documentInfo']['afisCode'] ?? '', 12),
        'fingerCard'     => $clean($in['documentInfo']['fingerCard'] ?? '', 12),
    ],
];
if (!preg_match('/^\d{4,12}$/', $record['documentInfo']['documentNumber'])) fail('Número de documento inválido');

$dir = __DIR__ . '/storage';
if (!is_dir($dir) && !mkdir($dir, 0750, true)) fail('No se pudo crear storage/', 500);

$id = bin2hex(random_bytes(8));

// --- Imagen (opcional)
if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $f = $_FILES['image'];
    if ($f['size'] > 8 * 1024 * 1024) fail('La imagen supera 8 MB');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) fail('Formato de imagen no permitido');
    if (!move_uploaded_file($f['tmp_name'], "$dir/$id.$ext")) fail('No se pudo guardar la imagen', 500);
    $record['image'] = "$id.$ext";
}

$record['id'] = $id;
$record['createdAt'] = date('c');
file_put_contents("$dir/$id.json", json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

echo json_encode(['ok' => true, 'id' => $id, 'data' => $record], JSON_UNESCAPED_UNICODE);