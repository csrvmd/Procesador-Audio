<?php
/**
 * API: Manejo de subida de archivos de audio
 * 
 * Recibe: archivo de audio
 * Retorna: JSON con URLs de preview y waveform
 */

define('TEMP_DIR', __DIR__ . '/../temp');
define('FFMPEG_PATH', '/usr/bin/ffmpeg');
define('OUTPUT_SAMPLERATE', 48000);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se recibió archivo de audio']);
    exit;
}

$sessionDir = $_POST['session_dir'] ?? null;
if (!$sessionDir || !preg_match('/^[\w\-._]+$/', $sessionDir)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Sesión inválida']);
    exit;
}

$sessionPath = TEMP_DIR . '/' . $sessionDir;
if (!is_dir($sessionPath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Directorio de sesión no existe']);
    exit;
}

$uploadedFile = $_FILES['audio']['tmp_name'];
$originalName = pathinfo($_FILES['audio']['name'], PATHINFO_FILENAME);

// Sanitizar nombre
$originalName = preg_replace('/[^a-zA-Z0-9\-_]/', '', $originalName);
if (empty($originalName)) {
    $originalName = 'audio_' . time();
}

// Guardar archivo original
$originalPath = $sessionPath . '/original_uploaded';
move_uploaded_file($uploadedFile, $originalPath);

// Obtener información del archivo
$ffprobeCmd = sprintf(
    'ffprobe -v quiet -print_format json -show_format -show_streams %s',
    escapeshellarg($originalPath)
);

$ffprobeOutput = shell_exec($ffprobeCmd);
$fileInfo = json_decode($ffprobeOutput, true);

if (!$fileInfo || !isset($fileInfo['streams'][0])) {
    unlink($originalPath);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Archivo de audio inválido']);
    exit;
}

$stream = $fileInfo['streams'][0];
$channels = $stream['channels'] ?? 2;
$sampleRate = $stream['sample_rate'] ?? 44100;
$duration = isset($fileInfo['format']['duration']) ? floatval($fileInfo['format']['duration']) : 0;

// Convertir a 48 kHz si es necesario
$needsResample = $sampleRate != OUTPUT_SAMPLERATE;
$tmpPath = $sessionPath . '/original_temp.wav';
$finalPath = $sessionPath . '/original.wav';

$resampleCmd = sprintf(
    '%s -i %s -acodec pcm_s16le -ar %d %s 2>&1',
    FFMPEG_PATH,
    escapeshellarg($originalPath),
    OUTPUT_SAMPLERATE,
    escapeshellarg($tmpPath)
);

$resampleOutput = shell_exec($resampleCmd);

if (!file_exists($tmpPath)) {
    unlink($originalPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error procesando audio']);
    exit;
}

rename($tmpPath, $finalPath);
unlink($originalPath);

// Generar preview MP3
$previewPath = $sessionPath . '/original.mp3';
$previewCmd = sprintf(
    '%s -i %s -q:a 5 -b:a 128k %s 2>&1',
    FFMPEG_PATH,
    escapeshellarg($finalPath),
    escapeshellarg($previewPath)
);

shell_exec($previewCmd);

// Generar waveform
$waveformPath = $sessionPath . '/original.png';
$waveformCmd = sprintf(
    '%s -i %s -filter_complex "showwavespic=s=-1x100:colors=0x87CEEB:scale=log" -frames:v 1 %s 2>&1',
    FFMPEG_PATH,
    escapeshellarg($previewPath),
    escapeshellarg($waveformPath)
);

shell_exec($waveformCmd);

// Retornar información
echo json_encode([
    'success' => true,
    'preview_url' => '/noise/temp/' . $sessionDir . '/original.mp3',
    'waveform_url' => '/noise/temp/' . $sessionDir . '/original.png',
    'duration' => gmdate('H:i:s', (int)$duration),
    'channels' => $channels,
    'sample_rate' => OUTPUT_SAMPLERATE,
    'filename' => $originalName
]);
?>