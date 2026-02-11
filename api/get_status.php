<?php
/**
 * API: Obtener estado de procesamiento en tiempo real
 * 
 * Retorna: información de procesos activos
 */

define('TEMP_DIR', __DIR__ . '/../temp');
define('MAX_CONCURRENT_PROCESSES', 10);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

// Contar procesos activos
$locks = glob('/tmp/noise_locks/*.lock');
$activeProcesses = count($locks);

// Limpiar locks expirados (más de 15 minutos)
foreach ($locks as $lockFile) {
    $age = time() - filemtime($lockFile);
    if ($age > 900) {
        @unlink($lockFile);
    }
}

echo json_encode([
    'success' => true,
    'active_processes' => $activeProcesses,
    'max_processes' => MAX_CONCURRENT_PROCESSES,
    'can_process' => $activeProcesses < MAX_CONCURRENT_PROCESSES
]);
?>