<?php
/**
 * API: Descarga de archivos MP2 procesados
 * 
 * Parámetros: file, session_dir
 */

define('TEMP_DIR', __DIR__ . '/../temp');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$filename = $_GET['file'] ?? null;
$sessionDir = $_GET['session_dir'] ?? null;

// Validar parámetros
if (!$filename || !$sessionDir) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetros faltantes']);
    exit;
}

if (!preg_match('/^[\w\-._]+$/', $filename) || !preg_match('/^[\w\-._]+$/', $sessionDir)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetros inválidos']);
    exit;
}

$sessionPath = TEMP_DIR . '/' . $sessionDir;
$filePath = $sessionPath . '/' . $filename;

// Validar que la ruta no escapa del directorio
if (realpath($filePath) === false || strpos(realpath($filePath), realpath($sessionPath)) !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit;
}

// Verificar que el archivo existe
if (!file_exists($filePath)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Archivo no encontrado']);
    exit;
}

// Descargar archivo
header('Content-Type: audio/mp2');
header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;
?>