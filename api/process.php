<?php
/**
 * API: Procesamiento de audio con filtros FFmpeg
 * 
 * Recibe: configuración de filtros
 * Retorna: URLs de archivos procesados
 */

define('TEMP_DIR', __DIR__ . '/../temp');
define('FFMPEG_PATH', '/usr/bin/ffmpeg');
define('RNNOISE_MODELS_DIR', '/usr/local/share/rnnoise-models');
define('OUTPUT_SAMPLERATE', 48000);
define('FFMPEG_TIMEOUT', 600);
define('MAX_CONCURRENT_PROCESSES', 10);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$sessionDir = $input['session_dir'] ?? null;
$originalFilename = $input['original_filename'] ?? null;
$suffix = $input['suffix'] ?? 1;
$filters = $input['filters'] ?? [];

if (!$sessionDir || !preg_match('/^[\w\-._]+$/', $sessionDir)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Sesión inválida']);
    exit;
}

$sessionPath = TEMP_DIR . '/' . $sessionDir;
if (!is_dir($sessionPath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Sesión no encontrada']);
    exit;
}

// Verificar límite de concurrencia
$locks = glob('/tmp/noise_locks/*.lock');
if (count($locks) >= MAX_CONCURRENT_PROCESSES) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'En este momento se están procesando 10 trabajos; inténtelo en unos minutos.'
    ]);
    exit;
}

// Crear lock file
@mkdir('/tmp/noise_locks', 0750, true);
$lockFile = '/tmp/noise_locks/' . uniqid() . '.lock';
touch($lockFile);

// Verificar que archivo original existe
$originalPath = $sessionPath . '/original.wav';
if (!file_exists($originalPath)) {
    unlink($lockFile);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Archivo original no encontrado']);
    exit;
}

// Construir comando FFmpeg
$outputFileName = $originalFilename . '_' . $suffix;
$outputMp3 = $sessionPath . '/' . $outputFileName . '.mp3';
$outputMp2 = $sessionPath . '/' . $outputFileName . '.mp2';
$outputPng = $sessionPath . '/' . $outputFileName . '.png';

$ffmpegCmd = buildFFmpegCommand($originalPath, $outputMp3, $outputMp2, $filters);

// Ejecutar con timeout
$descriptorspec = array(
    0 => array("pipe", "r"),
    1 => array("pipe", "w"),
    2 => array("pipe", "w")
);

$process = proc_open($ffmpegCmd, $descriptorspec, $pipes);
$startTime = time();

if (!is_resource($process)) {
    unlink($lockFile);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error ejecutando FFmpeg']);
    exit;
}

fclose($pipes[0]);

while (true) {
    $status = proc_get_status($process);
    
    if (!$status['running']) {
        break;
    }
    
    if ((time() - $startTime) > FFMPEG_TIMEOUT) {
        proc_terminate($process, 9);
        unlink($lockFile);
        @unlink($outputMp3);
        @unlink($outputMp2);
        http_response_code(504);
        echo json_encode(['success' => false, 'error' => 'Timeout procesando archivo (600s)']);
        exit;
    }
    
    usleep(100000); // 100ms
}

proc_close($process);
unlink($lockFile);

// Verificar que se generó el archivo
if (!file_exists($outputMp3)) {
    @unlink($outputMp2);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error generando archivo procesado']);
    exit;
}

// Generar waveform
$waveformCmd = sprintf(
    '%s -i %s -filter_complex "showwavespic=s=-1x100:colors=0x87CEEB:scale=log" -frames:v 1 %s 2>&1',
    FFMPEG_PATH,
    escapeshellarg($outputMp3),
    escapeshellarg($outputPng)
);

shell_exec($waveformCmd);

// Establecer permisos
chmod($outputMp3, 0644);
if (file_exists($outputMp2)) {
    chmod($outputMp2, 0644);
}
if (file_exists($outputPng)) {
    chmod($outputPng, 0644);
}

echo json_encode([
    'success' => true,
    'preview_url' => '/noise/temp/' . $sessionDir . '/' . $outputFileName . '.mp3',
    'waveform_url' => '/noise/temp/' . $sessionDir . '/' . $outputFileName . '.png',
    'download_file' => $outputFileName . '.mp2'
]);

function buildFFmpegCommand($inputFile, $outputMp3, $outputMp2, $filters) {
    $ffmpegPath = FFMPEG_PATH;
    $sampleRate = OUTPUT_SAMPLERATE;
    
    // Construir cadena de filtros
    $filterChain = [];
    $channels = getFileChannels($inputFile);
    
    // 1. Convertir a formato apropiado (mono si es necesario para RNNoise)
    if ($filters['rnnoise']) {
        $filterChain[] = 'aformat=channel_layouts=mono';
        $applyRnnoise = true;
    } else {
        $applyRnnoise = false;
        if ($channels == 1) {
            $filterChain[] = 'aformat=channel_layouts=mono';
        }
    }
    
    // 2. RNNoise
    if ($filters['rnnoise']) {
        $model = $filters['rnnoise']['model'] ?? 'cb.rnnn';
        $mix = $filters['rnnoise']['mix'] ?? 0.8;
        $modelPath = RNNOISE_MODELS_DIR . '/' . $model;
        
        if (file_exists($modelPath)) {
            $filterChain[] = sprintf(
                'arnndn=m=%s:mix=%s',
                escapeshellarg($modelPath),
                $mix
            );
        }
    }
    
    // 3. Ecualizador (HP + Bandas + LP)
    if ($filters['eq']) {
        // High-Pass
        if ($filters['eq']['hp'] && $filters['eq']['hp']['freq']) {
            $filterChain[] = sprintf('highpass=f=%d:poles=2', $filters['eq']['hp']['freq']);
        }
        
        // Bandas gráficas
        $freqs = [125, 500, 1000, 3000, 6000];
        $gains = $filters['eq']['bands'] ?? [0, 0, 0, 0, 0];
        
        for ($i = 0; $i < 5; $i++) {
            if ($gains[$i] != 0) {
                $filterChain[] = sprintf(
                    'equalizer=f=%d:t=q:width=1.0:g=%s',
                    $freqs[$i],
                    $gains[$i]
                );
            }
        }
        
        // Low-Pass
        if ($filters['eq']['lp'] && $filters['eq']['lp']['freq']) {
            $filterChain[] = sprintf('lowpass=f=%d:poles=2', $filters['eq']['lp']['freq']);
        }
    }
    
    // 4. Dynamic Audio Normalizer
    if ($filters['dynaudnorm']) {
        $intensity = $filters['dynaudnorm']['intensity'] ?? 0.5;
        
        // Mapear intensidad a parámetros
        if ($intensity <= 0.3) {
            // Conservador
            $targetrms = '0.20';
            $threshold = '0.10';
            $m = '5.0';
        } elseif ($intensity <= 0.7) {
            // Normal
            $targetrms = '0.15';
            $threshold = '0.05';
            $m = '10.0';
        } else {
            // Agresivo
            $targetrms = '0.10';
            $threshold = '0.01';
            $m = '15.0';
        }
        
        $filterChain[] = sprintf(
            'dynaudnorm=f=200:g=15:p=0.9:m=%s:cf=0.0:targetrms=%s:threshold=%s',
            $m,
            $targetrms,
            $threshold
        );
    }
    
    // 5. Convertir a estéreo si era mono al inicio
    if ($channels == 1 && $applyRnnoise) {
        $filterChain[] = 'aformat=channel_layouts=stereo';
    }
    
    $filterString = implode(',', $filterChain);
    
    if (!empty($filterString)) {
        $filterArg = '-af "' . $filterString . '"';
    } else {
        $filterArg = '';
    }
    
    // Comando completo: genera MP3 y MP2 simultáneamente
    $cmd = sprintf(
        '%s -i %s %s -c:a libmp3lame -q:a 5 -b:a 128k %s -c:a libtwolame -b:a 256k -ar %d %s 2>&1',
        $ffmpegPath,
        escapeshellarg($inputFile),
        $filterArg,
        escapeshellarg($outputMp3),
        $sampleRate,
        escapeshellarg($outputMp2)
    );
    
    return $cmd;
}

function getFileChannels($filepath) {
    $cmd = sprintf(
        'ffprobe -v quiet -print_format json -show_streams %s',
        escapeshellarg($filepath)
    );
    
    $output = shell_exec($cmd);
    $info = json_decode($output, true);
    
    if (isset($info['streams'][0]['channels'])) {
        return $info['streams'][0]['channels'];
    }
    
    return 2; // Default estéreo
}
?>