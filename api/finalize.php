<?php
/**
 * API: Finalizar sesión y limpiar archivos
 * 
 * Recibe: session_dir
 * Acción: Elimina todos los archivos de la sesión
 */

define('TEMP_DIR', __DIR__ . '/../temp');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$sessionDir = $input['session_dir'] ?? null;

if (!$sessionDir || !preg_match('/^[\w\-._]+$/', $sessionDir)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Sesión inválida']);
    exit;
}

$sessionPath = TEMP_DIR . '/' . $sessionDir;

// Validar que la ruta está dentro de TEMP_DIR
if (strpos(realpath($sessionPath) ?? '', realpath(TEMP_DIR)) !== 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso denegado']);
    exit;
}

// Limpiar directorio recursivamente
function deleteDirectory($path) {
    if (is_dir($path)) {
        $files = scandir($path);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                $filePath = $path . '/' . $file;
                if (is_dir($filePath)) {
                    deleteDirectory($filePath);
                } else {
                    @unlink($filePath);
                }
            }
        }
        @rmdir($path);
    }
}

deleteDirectory($sessionPath);

echo json_encode(['success' => true, 'message' => 'Sesión finalizada y archivos eliminados']);
?>