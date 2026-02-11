<?php
/**
 * API: Validar si existe sesión activa del usuario
 * 
 * Retorna: información de sesión activa
 */

define('TEMP_DIR', __DIR__ . '/../temp');
define('SESSION_TIMEOUT', 900); // 15 minutos

header('Content-Type: application/json; charset=utf-8');

$clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Buscar sesiones existentes del usuario
$existingSessions = glob(TEMP_DIR . '/' . $clientIP . '_*', GLOB_ONLYDIR);

$hasSession = false;
$sessionInfo = null;

if (!empty($existingSessions)) {
    $sessionDir = end($existingSessions);
    $sessionTimestamp = (int)substr($sessionDir, strrpos($sessionDir, '_') + 1);
    $sessionAge = time() - $sessionTimestamp;
    
    // Verificar si sesión es válida (no expirada)
    if ($sessionAge < SESSION_TIMEOUT) {
        $hasSession = true;
        $sessionInfo = [
            'session_dir' => basename($sessionDir),
            'age_seconds' => $sessionAge,
            'expires_in' => SESSION_TIMEOUT - $sessionAge,
            'files' => count(glob($sessionDir . '/*.*'))
        ];
    } else {
        // Limpiar sesión expirada
        deleteDirectory($sessionDir);
    }
}

echo json_encode([
    'success' => true,
    'has_session' => $hasSession,
    'session' => $sessionInfo
]);

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
?>