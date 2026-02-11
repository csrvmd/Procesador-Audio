#!/bin/bash

##############################################################################
# Script: Procesamiento de audio con FFmpeg
# 
# Propósito: Ejecutar FFmpeg con los filtros especificados
# Uso: ./process.sh <input_file> <output_mp3> <output_mp2> <filter_chain>
##############################################################################

set -e

# Validar parámetros
if [ $# -lt 4 ]; then
    echo "Uso: $0 <input_file> <output_mp3> <output_mp2> <filter_chain>"
    exit 1
fi

INPUT_FILE="$1"
OUTPUT_MP3="$2"
OUTPUT_MP2="$3"
FILTER_CHAIN="$4"
FFMPEG_PATH="/usr/bin/ffmpeg"
SAMPLE_RATE="48000"
TIMEOUT="600"

# Validar que FFmpeg existe
if [ ! -f "$FFMPEG_PATH" ]; then
    echo "Error: FFmpeg no encontrado en $FFMPEG_PATH"
    exit 1
fi

# Validar que archivo de entrada existe
if [ ! -f "$INPUT_FILE" ]; then
    echo "Error: Archivo de entrada no encontrado: $INPUT_FILE"
    exit 1
fi

# Crear lock file para control de concurrencia
LOCK_DIR="/tmp/noise_locks"
mkdir -p "$LOCK_DIR"
LOCK_FILE="$LOCK_DIR/process_$$.lock"
touch "$LOCK_FILE"

# Trap para limpiar lock en caso de error
cleanup() {
    rm -f "$LOCK_FILE"
    rm -f "$OUTPUT_MP3" "$OUTPUT_MP2"
}
trap cleanup EXIT

# Construir comando FFmpeg
if [ -z "$FILTER_CHAIN" ]; then
    FFMPEG_CMD="$FFMPEG_PATH -i \"$INPUT_FILE\" \
        -c:a libmp3lame -q:a 5 -b:a 128k \"$OUTPUT_MP3\" \
        -c:a libtwolame -b:a 256k -ar $SAMPLE_RATE \"$OUTPUT_MP2\" 2>&1"
else
    FFMPEG_CMD="$FFMPEG_PATH -i \"$INPUT_FILE\" \
        -af \"$FILTER_CHAIN\" \
        -c:a libmp3lame -q:a 5 -b:a 128k \"$OUTPUT_MP3\" \
        -c:a libtwolame -b:a 256k -ar $SAMPLE_RATE \"$OUTPUT_MP2\" 2>&1"
fi

# Ejecutar FFmpeg con timeout
timeout $TIMEOUT bash -c "$FFMPEG_CMD" || {
    EXIT_CODE=$?
    if [ $EXIT_CODE -eq 124 ]; then
        echo "Error: Timeout procesando archivo (${TIMEOUT}s)"
        exit 124
    else
        echo "Error: FFmpeg falló con código $EXIT_CODE"
        exit $EXIT_CODE
    fi
}

# Verificar que se crearon los archivos
if [ ! -f "$OUTPUT_MP3" ]; then
    echo "Error: No se generó archivo MP3"
    exit 1
fi

if [ ! -f "$OUTPUT_MP2" ]; then
    echo "Error: No se generó archivo MP2"
    exit 1
fi

# Establecer permisos
chmod 644 "$OUTPUT_MP3" "$OUTPUT_MP2"

echo "Procesamiento completado exitosamente"
exit 0