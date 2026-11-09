<?php
declare(strict_types=1);

/**
 * Endpoint clásico de guardado (mantenido por compatibilidad 100% con versiones previas).
 * Delega el procesamiento al motor unificado en api.php (action=save).
 */
$_GET['action'] = 'save';
require __DIR__ . '/api.php';
