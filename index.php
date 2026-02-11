<?php
/**
 * PROCESADOR AUDIO CANAL SUR RADIO
 * Interfaz Principal - Aplicación Web
 * 
 * Versión: 1.0.0
 * Fecha: 2026-02-09
 */

// ============================================================================
// CONFIGURACIÓN INICIAL
// ============================================================================

// Detectar IP del cliente
$clientIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

// Constantes de configuración
define('FFMPEG_PATH', '/usr/bin/ffmpeg');
define('TEMP_DIR', __DIR__ . '/temp');
define('RNNOISE_MODELS_DIR', '/usr/local/share/rnnoise-models');
define('SESSION_TIMEOUT', 900); // 15 minutos
define('FFMPEG_TIMEOUT', 600); // 10 minutos
define('MAX_CONCURRENT_PROCESSES', 10);
define('OUTPUT_SAMPLERATE', 48000); // 48 kHz

// Crear directorio temp si no existe
if (!is_dir(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0750, true);
}

// Crear directorio de sesión si no existe
$sessionDir = TEMP_DIR . '/' . $clientIP . '_' . time();
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0750, true);
}

// Detectar si hay sesión existente del usuario
$existingSessions = glob(TEMP_DIR . '/' . $clientIP . '_*', GLOB_ONLYDIR);
$hasExistingSession = false;
$existingSessionDir = null;

if (!empty($existingSessions)) {
    $existingSessionDir = end($existingSessions);
    $sessionTimestamp = (int)substr($existingSessionDir, strrpos($existingSessionDir, '_') + 1);
    $sessionAge = time() - $sessionTimestamp;
    
    // Si la sesión anterior no ha expirado (15 min), usarla
    if ($sessionAge < SESSION_TIMEOUT) {
        $hasExistingSession = true;
        $sessionDir = $existingSessionDir;
    } else {
        // Limpiar sesión expirada
        @shell_exec('rm -rf ' . escapeshellarg($existingSessionDir));
    }
}

// Validar que FFmpeg existe
if (!file_exists(FFMPEG_PATH)) {
    die('ERROR: FFmpeg no encontrado en ' . FFMPEG_PATH);
}

// Validar que modelos RNNoise existen
$requiredModels = ['bd.rnnn', 'cb.rnnn', 'mp.rnnn', 'sh.rnnn'];
$missingModels = [];
foreach ($requiredModels as $model) {
    if (!file_exists(RNNOISE_MODELS_DIR . '/' . $model)) {
        $missingModels[] = $model;
    }
}

if (!empty($missingModels)) {
    die('ERROR: Modelos RNNoise no encontrados: ' . implode(', ', $missingModels));
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Procesador Audio Canal Sur Radio</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 20px;
            text-align: center;
        }
        
        header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 600;
        }
        
        header p {
            font-size: 14px;
            opacity: 0.9;
            line-height: 1.6;
        }
        
        main {
            padding: 40px 20px;
        }
        
        .upload-section {
            background: #f8f9fa;
            border: 2px dashed #667eea;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            margin-bottom: 40px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-section:hover {
            background: #f0f1ff;
            border-color: #764ba2;
        }
        
        .upload-section input[type="file"] {
            display: none;
        }
        
        .upload-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .upload-section h3 {
            color: #333;
            margin-bottom: 10px;
        }
        
        .upload-section p {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }
        
        .file-info {
            background: white;
            padding: 15px;
            border-radius: 6px;
            margin-top: 20px;
            display: none;
            text-align: left;
        }
        
        .file-info.active {
            display: block;
        }
        
        .file-info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }
        
        .file-info-item:last-child {
            border-bottom: none;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        
        .alert-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .alert-info {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            color: #0c5460;
        }
        
        .alert-success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            text-align: center;
        }
        
        .modal-content h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .modal-content p {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        
        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .filters-section {
            display: none;
        }
        
        .filters-section.active {
            display: block;
        }
        
        .filter-group {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .filter-group h3 {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-toggle-main {
            width: 50px;
            height: 26px;
            background: #ccc;
            border-radius: 13px;
            position: relative;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .filter-toggle-main.active {
            background: #667eea;
        }
        
        .filter-toggle-main::after {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: left 0.3s;
        }
        
        .filter-toggle-main.active::after {
            left: 26px;
        }
        
        .rnnoise-section {
            display: none;
        }
        
        .rnnoise-section.active {
            display: block;
        }
        
        .models-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .models-table thead {
            background: #667eea;
            color: white;
        }
        
        .models-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .models-table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
        }
        
        .models-table tbody tr:hover {
            background: #f5f5f5;
        }
        
        .mix-slider-container {
            margin-top: 20px;
            background: white;
            padding: 15px;
            border-radius: 6px;
        }
        
        .mix-slider-container label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #333;
        }
        
        .slider-info {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 12px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #1976d2;
            border-radius: 4px;
            line-height: 1.6;
        }
        
        .slider-wrapper {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        input[type="range"] {
            flex: 1;
            height: 6px;
            border-radius: 3px;
            background: linear-gradient(to right, #ddd 0%, #667eea 50%, #ddd 100%);
            outline: none;
            -webkit-appearance: none;
            appearance: none;
        }
        
        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            border: 2px solid white;
        }
        
        input[type="range"]::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            border: 2px solid white;
        }
        
        .slider-value {
            min-width: 50px;
            text-align: center;
            font-weight: bold;
            color: #667eea;
            font-size: 16px;
        }
        
        .eq-section {
            display: none;
        }
        
        .eq-section.active {
            display: block;
        }
        
        .eq-subsection {
            background: white;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            display: none;
        }
        
        .eq-subsection.active {
            display: block;
        }
        
        .eq-subsection h4 {
            color: #333;
            margin-bottom: 15px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-control {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        .filter-control:last-child {
            margin-bottom: 0;
        }
        
        .filter-label {
            min-width: 120px;
            font-size: 13px;
            color: #666;
        }
        
        .filter-slider {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filter-slider input[type="range"] {
            flex: 1;
        }
        
        .filter-value {
            min-width: 60px;
            text-align: right;
            font-weight: bold;
            color: #667eea;
            font-size: 13px;
        }
        
        .eq-graphic-container {
            background: white;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 15px;
        }
        
        .eq-graphic-container h4 {
            color: #333;
            margin-bottom: 15px;
            font-size: 14px;
        }
        
        #eqGraphic {
            width: 100%;
            height: 200px;
            background: linear-gradient(to bottom, #f5f5f5, #ffffff);
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .eq-bands-controls {
            display: flex;
            justify-content: space-around;
            align-items: flex-end;
            gap: 30px;
            padding: 30px 20px;
            background: linear-gradient(to bottom, #f8f9fa, #ffffff);
            border-radius: 8px;
            min-height: 300px;
        }
        
        .eq-band {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }
        
        .eq-band label {
            font-weight: bold;
            font-size: 13px;
            color: #333;
            white-space: nowrap;
        }
        
        .vertical-slider {
            height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .vertical-slider input[type="range"] {
            width: 40px;
            height: 200px;
            padding: 0;
            margin: 0;
            writing-mode: bt-lr;
            -webkit-appearance: slider-vertical;
            appearance: slider-vertical;
            cursor: pointer;
            background: linear-gradient(to top, #667eea 0%, #ddd 50%, #667eea 100%);
        }
        
        .vertical-slider input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: slider-thumb;
            appearance: slider-thumb;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            border: 2px solid white;
        }
        
        .vertical-slider input[type="range"]::-moz-range-thumb {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            border: 2px solid white;
        }
        
        .gain-display {
            font-size: 12px;
            font-weight: bold;
            color: #667eea;
            min-width: 50px;
            text-align: center;
        }
        
        .dynaudnorm-section {
            display: none;
        }
        
        .dynaudnorm-section.active {
            display: block;
        }
        
        .intensity-slider-container {
            background: white;
            padding: 15px;
            border-radius: 6px;
        }
        
        .intensity-slider-container label {
            display: block;
            margin-bottom: 10px;
            font-weight: 600;
            color: #333;
        }
        
        .intensity-info {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #856404;
            border-radius: 4px;
            line-height: 1.6;
        }
        
        .previews-container {
            display: none;
            margin-top: 40px;
        }
        
        .previews-container.active {
            display: block;
        }
        
        .preview-item {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        
        .preview-title {
            font-weight: 600;
            color: #333;
            font-size: 15px;
        }
        
        .preview-badge {
            background: #667eea;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .preview-waveform {
            margin-bottom: 15px;
        }
        
        .waveform-canvas {
            width: 100%;
            height: 100px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            display: block;
        }
        
        .audio-player {
            width: 100%;
            margin-bottom: 15px;
        }
        
        .audio-player audio {
            width: 100%;
            outline: none;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #ddd;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
            flex: 1;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
            padding: 8px 16px;
            font-size: 12px;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .spinner {
            display: none;
            position: fixed;
            z-index: 999;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }
        
        .spinner.active {
            display: block;
        }
        
        .spinner-overlay {
            position: fixed;
            z-index: 998;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
        }
        
        .spinner-overlay.active {
            display: block;
        }
        
        .spinner-content {
            background: white;
            padding: 40px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }
        
        .spinner-animation {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .spinner-text {
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .spinner-time {
            color: #666;
            font-size: 13px;
        }
        
        @media (max-width: 768px) {
            main {
                padding: 20px;
            }
            
            .eq-bands-controls {
                gap: 15px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<?php if ($hasExistingSession): ?>
    <div class="alert alert-warning" style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); width: 90%; max-width: 500px; z-index: 10;">
        ⚠️ <strong>Sesión existente detectada</strong> - Ya tienes una sesión activa. Si deseas comenzar una nueva, pulsa "FINALIZAR".
    </div>
<?php endif; ?>

<div class="container">
    <header>
        <h1>🎙️ Procesador Audio Canal Sur Radio</h1>
        <p>Selecciona los filtros que desees aplicar y ajusta los parámetros de cada uno de ellos.<br>
        Tras pulsar el botón de APLICAR se generará un archivo preview con el nombre original y un sufijo numérico.<br>
        Puedes aplicar diferentes combinaciones de filtros y parámetros que generarán nuevos archivos preview.<br>
        Cada preview generado tendrá su correspondiente botón de descarga.<br>
        Pulsa FINALIZAR para comenzar un nuevo proceso. Esto borrará todos los archivos.</p>
    </header>

    <main>
        <div class="upload-section" id="uploadSection">
            <div class="upload-icon">📁</div>
            <h3>Selecciona un archivo de audio</h3>
            <p>Haz clic para explorar o arrastra un archivo aquí</p>
            <p style="font-size: 12px; color: #999;">Formatos soportados: MP3, WAV, FLAC, OGG, M4A, etc.</p>
            <input type="file" id="audioFile" accept="audio/*">
            <div class="file-info" id="fileInfo">
                <div class="file-info-item">
                    <span><strong>Nombre:</strong></span>
                    <span id="fileName">-</span>
                </div>
                <div class="file-info-item">
                    <span><strong>Tamaño:</strong></span>
                    <span id="fileSize">-</span>
                </div>
                <div class="file-info-item">
                    <span><strong>Duración:</strong></span>
                    <span id="fileDuration">-</span>
                </div>
            </div>
        </div>

        <div id="alertContainer"></div>

        <div class="filters-section" id="filtersSection">
            
            <div class="filter-group">
                <h3>
                    <span style="flex: 1; text-align: left;">🔇 Reductor de Ruido (RNNoise)</span>
                    <div class="filter-toggle-main" id="rnnoiseToggle" data-filter="rnnoise"></div>
                </h3>
                
                <div class="rnnoise-section" id="rnnoiseSection">
                    <table class="models-table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Modelo</th>
                                <th style="width: 150px;">Nombre</th>
                                <th style="width: 120px;">Nivel de Ruido</th>
                                <th>Uso Principal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="cursor: pointer;" onclick="selectModel('bd.rnnn')">
                                <td><input type="radio" name="rnnoise_model" value="bd.rnnn"></td>
                                <td><strong>bd.rnnn</strong></td>
                                <td>Moderado</td>
                                <td>Discursos y locución (mejor claridad)</td>
                            </tr>
                            <tr style="cursor: pointer;" onclick="selectModel('cb.rnnn')">
                                <td><input type="radio" name="rnnoise_model" value="cb.rnnn" checked></td>
                                <td><strong>cb.rnnn</strong></td>
                                <td>General</td>
                                <td>Uso diario y estándar</td>
                            </tr>
                            <tr style="cursor: pointer;" onclick="selectModel('mp.rnnn')">
                                <td><input type="radio" name="rnnoise_model" value="mp.rnnn"></td>
                                <td><strong>mp.rnnn</strong></td>
                                <td>Bajo</td>
                                <td>Grabaciones con música o ambiente suave</td>
                            </tr>
                            <tr style="cursor: pointer;" onclick="selectModel('sh.rnnn')">
                                <td><input type="radio" name="rnnoise_model" value="sh.rnnn"></td>
                                <td><strong>sh.rnnn</strong></td>
                                <td>Muy Alto</td>
                                <td>Ruidos extremos (ventiladores industriales, estática fuerte)</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div class="mix-slider-container">
                        <label>Nivel de Mezcla (Mix)</label>
                        <div class="slider-info">
                            📌 <strong>Valor por defecto:</strong> 0.8 (buen equilibrio entre reducción de ruido y preservación de calidad)<br>
                            <strong>Valores altos (→ 1.0):</strong> Reducción más fuerte, pero puede afectar la calidad del habla<br>
                            <strong>Valores bajos (→ 0.0):</strong> Reducción suave, conserva más audio original
                        </div>
                        <div class="slider-wrapper">
                            <span style="min-width: 30px; text-align: right;">0.0</span>
                            <input type="range" id="rnnoiseMix" min="0" max="10" value="8" step="1">
                            <span class="slider-value" id="rnnoiseMixValue">0.8</span>
                            <span style="min-width: 30px;">1.0</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="filter-group">
                <h3>
                    <span style="flex: 1; text-align: left;">🎛️ Ecualizador</span>
                    <div class="filter-toggle-main" id="eqToggle" data-filter="eq"></div>
                </h3>
                
                <div class="eq-section" id="eqSection">
                    <div class="eq-subsection active">
                        <h4>
                            ↗️ High-Pass Filter (HP)
                            <div class="filter-toggle-main" id="hpToggle" data-filter="hp"></div>
                        </h4>
                        <div class="filter-control">
                            <span class="filter-label">Frecuencia de corte:</span>
                            <div class="filter-slider">
                                <input type="range" id="hpFreq" min="20" max="1000" value="200" step="10">
                                <span class="filter-value"><span id="hpFreqValue">200</span> Hz</span>
                            </div>
                        </div>
                    </div>

                    <div class="eq-graphic-container">
                        <h4>Gráfico de Respuesta Frecuencial</h4>
                        <canvas id="eqGraphic"></canvas>
                        
                        <h4 style="margin-top: 20px;">Bandas Gráficas (5 Bandas)</h4>
                        <div class="eq-bands-controls">
                            <div class="eq-band">
                                <label>125 Hz</label>
                                <div class="vertical-slider">
                                    <input type="range" class="band-gain" data-band="0" data-freq="125" min="-60" max="60" value="0" step="5">
                                </div>
                                <div class="gain-display" id="gain0">0.0 dB</div>
                            </div>
                            
                            <div class="eq-band">
                                <label>500 Hz</label>
                                <div class="vertical-slider">
                                    <input type="range" class="band-gain" data-band="1" data-freq="500" min="-60" max="60" value="0" step="5">
                                </div>
                                <div class="gain-display" id="gain1">0.0 dB</div>
                            </div>
                            
                            <div class="eq-band">
                                <label>1 kHz</label>
                                <div class="vertical-slider">
                                    <input type="range" class="band-gain" data-band="2" data-freq="1000" min="-60" max="60" value="0" step="5">
                                </div>
                                <div class="gain-display" id="gain2">0.0 dB</div>
                            </div>
                            
                            <div class="eq-band">
                                <label>3 kHz</label>
                                <div class="vertical-slider">
                                    <input type="range" class="band-gain" data-band="3" data-freq="3000" min="-60" max="60" value="0" step="5">
                                </div>
                                <div class="gain-display" id="gain3">0.0 dB</div>
                            </div>
                            
                            <div class="eq-band">
                                <label>6 kHz</label>
                                <div class="vertical-slider">
                                    <input type="range" class="band-gain" data-band="4" data-freq="6000" min="-60" max="60" value="0" step="5">
                                </div>
                                <div class="gain-display" id="gain4">0.0 dB</div>
                            </div>
                        </div>
                    </div>

                    <div class="eq-subsection active" style="margin-top: 15px;">
                        <h4>
                            ↙️ Low-Pass Filter (LP)
                            <div class="filter-toggle-main" id="lpToggle" data-filter="lp"></div>
                        </h4>
                        <div class="filter-control">
                            <span class="filter-label">Frecuencia de corte:</span>
                            <div class="filter-slider">
                                <input type="range" id="lpFreq" min="1000" max="8000" value="3000" step="100">
                                <span class="filter-value"><span id="lpFreqValue">3000</span> Hz</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="filter-group">
                <h3>
                    <span style="flex: 1; text-align: left;">📊 Normalizador Dinámico</span>
                    <div class="filter-toggle-main" id="dynaudnormToggle" data-filter="dynaudnorm"></div>
                </h3>
                
                <div class="dynaudnorm-section" id="dynaudnormSection">
                    <div class="intensity-slider-container">
                        <label>Nivel de Intensidad</label>
                        <div class="intensity-info">
                            💡 <strong>0.0 (Conservador):</strong> Ajustes suaves, preserva máxima calidad<br>
                            <strong>0.5 (Normal):</strong> Balance equilibrado<br>
                            <strong>1.0 (Agresivo):</strong> Normalización máxima, mejor consistencia de niveles
                        </div>
                        <div class="slider-wrapper">
                            <span style="min-width: 30px; text-align: right;">0.0</span>
                            <input type="range" id="dynaudnormIntensity" min="0" max="10" value="5" step="1">
                            <span class="slider-value" id="dynaudnormIntensityValue">0.5</span>
                            <span style="min-width: 30px;">1.0</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <button class="btn btn-primary" id="applyBtn" onclick="applyFilters()">
                    ✓ APLICAR FILTROS
                </button>
                <button class="btn btn-danger" id="finalizeBtn" onclick="confirmFinalize()">
                    🏁 FINALIZAR
                </button>
            </div>
        </div>

        <div class="previews-container" id="previewsContainer">
            <h2 style="color: #333; margin-bottom: 20px;">📺 Archivos Generados</h2>
            <div id="previewsList"></div>
        </div>
    </main>
</div>

<div class="modal" id="confirmModal">
    <div class="modal-content">
        <h3>⚠️ Comenzar Nuevo Proceso</h3>
        <p>¿Deseas comenzar un nuevo proceso de audio? Esto borrará todos los archivos generados anteriormente.</p>
        <div class="modal-buttons">
            <button class="btn btn-primary" onclick="confirmNewUpload(true)">Aceptar</button>
            <button class="btn btn-danger" onclick="confirmNewUpload(false)">Cancelar</button>
        </div>
    </div>
</div>

<div class="modal" id="finalizeModal">
    <div class="modal-content">
        <h3>🏁 Finalizar Sesión</h3>
        <p>¿Deseas finalizar la sesión? Se borrarán todos los archivos y la aplicación volverá al inicio.</p>
        <div class="modal-buttons">
            <button class="btn btn-danger" onclick="confirmFinalizeAction(true)">Sí, Finalizar</button>
            <button class="btn btn-primary" onclick="confirmFinalizeAction(false)">Cancelar</button>
        </div>
    </div>
</div>

<div class="spinner-overlay" id="spinnerOverlay"></div>
<div class="spinner" id="spinner">
    <div class="spinner-content">
        <div class="spinner-animation"></div>
        <div class="spinner-text" id="spinnerText">Procesando...</div>
        <div class="spinner-time" id="spinnerTime">Tiempo estimado: --</div>
    </div>
</div>

<script>
    const CONFIG = {
        clientIP: '<?php echo $clientIP; ?>',
        sessionDir: '<?php echo basename($sessionDir); ?>'
    };
    
    const state = {
        audioFile: null,
        audioFilename: null,
        hasOriginalPreview: false,
        fileChannels: null,
        fileSampleRate: null,
        filtersApplied: 0,
        currentlyPlaying: null,
        
        filters: {
            rnnoise: {
                enabled: false,
                model: 'cb.rnnn',
                mix: 0.8
            },
            eq: {
                enabled: false,
                hp: {
                    enabled: true,
                    freq: 200
                },
                bands: [0, 0, 0, 0, 0],
                lp: {
                    enabled: true,
                    freq: 3000
                }
            },
            dynaudnorm: {
                enabled: false,
                intensity: 0.5
            }
        }
    };
    
    document.addEventListener('DOMContentLoaded', function() {
        setupEventListeners();
        drawEQResponse();
    });
    
    function setupEventListeners() {
        const uploadSection = document.getElementById('uploadSection');
        const audioFileInput = document.getElementById('audioFile');
        
        uploadSection.addEventListener('click', () => audioFileInput.click());
        uploadSection.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadSection.style.background = '#f0f1ff';
        });
        uploadSection.addEventListener('dragleave', () => {
            uploadSection.style.background = '#f8f9fa';
        });
        uploadSection.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadSection.style.background = '#f8f9fa';
            if (e.dataTransfer.files.length > 0) {
                audioFileInput.files = e.dataTransfer.files;
                handleFileSelect();
            }
        });
        
        audioFileInput.addEventListener('change', handleFileSelect);
        
        document.getElementById('rnnoiseToggle').addEventListener('click', () => toggleFilter('rnnoise'));
        document.getElementById('eqToggle').addEventListener('click', () => toggleFilter('eq'));
        document.getElementById('dynaudnormToggle').addEventListener('click', () => toggleFilter('dynaudnorm'));
        document.getElementById('hpToggle').addEventListener('click', () => toggleSubfilter('hp'));
        document.getElementById('lpToggle').addEventListener('click', () => toggleSubfilter('lp'));
        
        document.getElementById('rnnoiseMix').addEventListener('input', (e) => {
            state.filters.rnnoise.mix = e.target.value / 10;
            document.getElementById('rnnoiseMixValue').textContent = (e.target.value / 10).toFixed(1);
        });
        
        document.getElementById('hpFreq').addEventListener('input', (e) => {
            state.filters.eq.hp.freq = parseInt(e.target.value);
            document.getElementById('hpFreqValue').textContent = e.target.value;
            drawEQResponse();
        });
        
        document.querySelectorAll('.band-gain').forEach(input => {
            input.addEventListener('input', (e) => {
                const band = parseInt(e.target.dataset.band);
                const gainValue = parseInt(e.target.value) / 10;
                state.filters.eq.bands[band] = gainValue;
                document.getElementById(`gain${band}`).textContent = 
                    (gainValue >= 0 ? '+' : '') + gainValue.toFixed(1) + ' dB';
                drawEQResponse();
            });
        });
        
        document.getElementById('lpFreq').addEventListener('input', (e) => {
            state.filters.eq.lp.freq = parseInt(e.target.value);
            document.getElementById('lpFreqValue').textContent = e.target.value;
            drawEQResponse();
        });
        
        document.getElementById('dynaudnormIntensity').addEventListener('input', (e) => {
            state.filters.dynaudnorm.intensity = e.target.value / 10;
            document.getElementById('dynaudnormIntensityValue').textContent = (e.target.value / 10).toFixed(1);
        });
    }
    
    function handleFileSelect() {
        const fileInput = document.getElementById('audioFile');
        const file = fileInput.files[0];
        
        if (!file) return;
        
        if (state.audioFile && !confirm('¿Deseas comenzar un nuevo proceso? Esto borrará todos los archivos generados.')) {
            fileInput.value = '';
            return;
        }
        
        state.audioFile = file;
        state.audioFilename = file.name.replace(/\.[^.]+$/, '');
        state.filtersApplied = 0;
        state.hasOriginalPreview = false;
        
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('fileSize').textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
        document.getElementById('fileInfo').classList.add('active');
        
        uploadFile();
    }
    
    function uploadFile() {
        const formData = new FormData();
        formData.append('audio', state.audioFile);
        formData.append('session_dir', CONFIG.sessionDir);
        
        showSpinner('Subiendo archivo...', '1-2 minutos');
        
        fetch('api/upload.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            hideSpinner();
            if (data.success) {
                showAlert('✓ Archivo subido correctamente', 'success');
                state.hasOriginalPreview = true;
                state.fileChannels = data.channels;
                state.fileSampleRate = data.sample_rate;
                document.getElementById('fileDuration').textContent = data.duration;
                
                document.getElementById('filtersSection').classList.add('active');
                document.getElementById('previewsContainer').classList.add('active');
                
                renderPreview('original', data.preview_url, data.waveform_url, true);
                
                document.getElementById('applyBtn').disabled = false;
            } else {
                showAlert('❌ Error: ' + data.error, 'error');
            }
        })
        .catch(error => {
            hideSpinner();
            showAlert('❌ Error en la subida: ' + error.message, 'error');
        });
    }
    
    function toggleFilter(filter) {
        const toggle = document.getElementById(filter + 'Toggle');
        const section = document.getElementById(filter + 'Section');
        
        state.filters[filter].enabled = !state.filters[filter].enabled;
        toggle.classList.toggle('active');
        section.classList.toggle('active');
    }
    
    function toggleSubfilter(subfilter) {
        const toggle = document.getElementById(subfilter + 'Toggle');
        state.filters.eq[subfilter].enabled = !state.filters.eq[subfilter].enabled;
        toggle.classList.toggle('active');
        drawEQResponse();
    }
    
    function selectModel(model) {
        document.querySelector(`input[name="rnnoise_model"][value="${model}"]`).checked = true;
        state.filters.rnnoise.model = model;
    }
    
    function drawEQResponse() {
        const canvas = document.getElementById('eqGraphic');
        if (!canvas) return;
        
        const ctx = canvas.getContext('2d');
        const width = canvas.width;
        const height = canvas.height;
        
        ctx.fillStyle = '#f5f5f5';
        ctx.fillRect(0, 0, width, height);
        
        ctx.strokeStyle = '#999';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, height / 2);
        ctx.lineTo(width, height / 2);
        ctx.stroke();
        
        const freqs = [125, 500, 1000, 3000, 6000];
        const maxFreq = 8000;
        
        ctx.strokeStyle = '#667eea';
        ctx.fillStyle = '#667eea';
        ctx.lineWidth = 2;
        ctx.beginPath();
        
        freqs.forEach((freq, index) => {
            const x = (freq / maxFreq) * width;
            const y = height / 2 - (state.filters.eq.bands[index] * (height / 12));
            
            if (index === 0) {
                ctx.moveTo(x, y);
            } else {
                ctx.lineTo(x, y);
            }
            
            ctx.fillRect(x - 4, y - 4, 8, 8);
        });
        
        ctx.stroke();
        
        ctx.fillStyle = '#333';
        ctx.font = '12px Arial';
        ctx.textAlign = 'center';
        freqs.forEach(freq => {
            const x = (freq / maxFreq) * width;
            ctx.fillText(freq + ' Hz', x, height - 10);
        });
    }
    
    function applyFilters() {
        if (!state.audioFile) {
            showAlert('⚠️ Debes subir un archivo de audio primero', 'error');
            return;
        }
        
        if (!state.filters.rnnoise.enabled && !state.filters.eq.enabled && !state.filters.dynaudnorm.enabled) {
            showAlert('⚠️ Debes activar al menos un filtro', 'error');
            return;
        }
        
        state.filtersApplied++;
        
        const filterConfig = {
            rnnoise: state.filters.rnnoise.enabled ? {
                model: state.filters.rnnoise.model,
                mix: state.filters.rnnoise.mix
            } : null,
            eq: state.filters.eq.enabled ? {
                hp: state.filters.eq.hp.enabled ? { freq: state.filters.eq.hp.freq } : null,
                bands: state.filters.eq.bands,
                lp: state.filters.eq.lp.enabled ? { freq: state.filters.eq.lp.freq } : null
            } : null,
            dynaudnorm: state.filters.dynaudnorm.enabled ? {
                intensity: state.filters.dynaudnorm.intensity
            } : null,
            channels: state.fileChannels,
            sample_rate: state.fileSampleRate
        };
        
        const estimatedTime = Math.ceil(parseFloat(document.getElementById('fileDuration').textContent || 0) * 1.5);
        
        showSpinner('Aplicando filtros...', estimatedTime + ' segundos');
        
        fetch('api/process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                session_dir: CONFIG.sessionDir,
                original_filename: state.audioFilename,
                suffix: state.filtersApplied,
                filters: filterConfig
            })
        })
        .then(response => response.json())
        .then(data => {
            hideSpinner();
            if (data.success) {
                showAlert('✓ Filtros aplicados correctamente', 'success');
                renderPreview(
                    state.audioFilename + '_' + state.filtersApplied,
                    data.preview_url,
                    data.waveform_url,
                    false
                );
            } else {
                showAlert('❌ Error: ' + data.error, 'error');
            }
        })
        .catch(error => {
            hideSpinner();
            showAlert('❌ Error en procesamiento: ' + error.message, 'error');
        });
    }
    
    function renderPreview(filename, previewUrl, waveformUrl, isOriginal) {
        const previewsList = document.getElementById('previewsList');
        const badge = isOriginal ? '<span class="preview-badge">Original</span>' : '';
        
        const downloadBtn = isOriginal ? '' : `<button class="btn btn-success" onclick="downloadFile('${filename}.mp2')">⬇️ DESCARGAR</button>`;
        
        const html = `
            <div class="preview-item">
                <div class="preview-header">
                    <span class="preview-title">🎵 ${filename}</span>
                    ${badge}
                </div>
                <div class="preview-waveform">
                    <canvas class="waveform-canvas" id="waveform-${filename}"></canvas>
                </div>
                <div class="audio-player">
                    <audio controls>
                        <source src="${previewUrl}?v=${Date.now()}" type="audio/mpeg">
                    </audio>
                </div>
                ${downloadBtn}
            </div>
        `;
        
        if (isOriginal) {
            previewsList.innerHTML = html + (previewsList.innerHTML || '');
        } else {
            previewsList.innerHTML += html;
        }
        
        // Dibujar waveform
        const canvas = document.getElementById(`waveform-${filename}`);
        if (canvas && waveformUrl) {
            const ctx = canvas.getContext('2d');
            const img = new Image();
            img.onload = function() {
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            };
            img.src = waveformUrl + '?v=' + Date.now();
        }
    }
    
    function downloadFile(filename) {
        window.location.href = `api/download.php?file=${encodeURIComponent(filename)}&session_dir=${CONFIG.sessionDir}`;
    }
    
    function confirmFinalize() {
        document.getElementById('finalizeModal').classList.add('active');
    }
    
    function confirmFinalizeAction(confirm) {
        document.getElementById('finalizeModal').classList.remove('active');
        
        if (confirm) {
            fetch('api/finalize.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_dir: CONFIG.sessionDir })
            })
            .then(() => {
                window.location.href = window.location.href;
            });
        }
    }
    
    function showSpinner(text, time) {
        document.getElementById('spinnerText').textContent = text;
        document.getElementById('spinnerTime').textContent = 'Tiempo estimado: ' + time;
        document.getElementById('spinner').classList.add('active');
        document.getElementById('spinnerOverlay').classList.add('active');
    }
    
    function hideSpinner() {
        document.getElementById('spinner').classList.remove('active');
        document.getElementById('spinnerOverlay').classList.remove('active');
    }
    
    function showAlert(message, type) {
        const container = document.getElementById('alertContainer');
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.innerHTML = message;
        container.appendChild(alertDiv);
        
        setTimeout(() => alertDiv.remove(), 5000);
    }
</script>

</body>
</html>