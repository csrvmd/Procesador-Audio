# PROCESADOR AUDIO CANAL SUR RADIO

## 📋 Descripción

Aplicación web para procesamiento de archivos de audio con tres filtros avanzados:
- **Reductor de Ruido (RNNoise)** - Múltiples modelos precalibirados
- **Ecualizador Gráfico** - 5 bandas + High-Pass + Low-Pass
- **Normalizador Dinámico** - Optimización de niveles de audio

Generación de previews en MP3 (128 kbps, 48 kHz) y archivos de descarga en MP2 (256 kbps, 48 kHz, estéreo).

---

## 🛠️ REQUISITOS DEL SISTEMA

### Software Requerido
- **Ubuntu 20.04 LTS o superior**
- **Apache 2.4.x** con módulo PHP habilitado
- **PHP 7.4 o superior**
- **FFmpeg** (versión 4.2 o superior)
  - Compilado con soporte para `arnndn` (RNNoise filter)
  - Compilado con soporte para `dynaudnorm` (Dynamic Audio Normalizer)
- **Modelos RNNoise** - Repositorio `@richardpl/arnndn-models`

### Permisos
- Usuario Apache: `www-data`
- Directorio `/var/www/html/` existente y accesible

### Hardware Recomendado
- CPU: 2+ cores
- RAM: 2GB mínimo
- Disco: 50GB disponible (para archivos temporales)
- Red: Acceso local 10.204.2.0/24

---

## 📥 INSTALACIÓN

### 1. Clonar o descargar archivos

```bash
cd /var/www/html
sudo mkdir -p noise
sudo chown www-data:www-data noise
sudo chmod 755 noise
cd noise

===============================================

PROYECTO 20260209

mkdir -p api scripts temp logs
chmod 750 temp logs
chmod 755 api scripts

Copiar estos archivos al directorio /var/www/html/noise/:
    • index.php
    • api/upload.php
    • api/process.php
    • api/get_status.php
    • api/download.php
    • api/finalize.php
    • api/check_session.php
    • scripts/process.sh
    • .htaccess

cd /var/www/html/noise

# Archivos PHP y estáticos
chmod 644 index.php
chmod 644 api/*.php
chmod 755 .htaccess

# Script BASH
chmod 755 scripts/process.sh
chown www-data:www-data scripts/process.sh

# Directorios
chmod 750 temp logs
chown www-data:www-data temp logs api scripts

# Permisos recursivos
sudo chown -R www-data:www-data /var/www/html/noise

# Verificar FFmpeg
ffmpeg -version

# Verificar filtro arnndn
ffmpeg -h filter=arnndn

# Verificar filtro dynaudnorm
ffmpeg -h filter=dynaudnorm

# Verificar filtro highpass, lowpass, equalizer
ffmpeg -h filter=highpass
ffmpeg -h filter=lowpass
ffmpeg -h filter=equalizer

# Verificar showwavespic
ffmpeg -h filter=showwavespic

# Verificar que existen los modelos
ls -la /usr/local/share/rnnoise-models/

# Archivos esperados:
# - bd.rnnn (Discursos)
# - cb.rnnn (General)
# - mp.rnnn (Música)
# - sh.rnnn (Ruidos extremos)

Si los modelos no existen, descargarlos:

sudo mkdir -p /usr/local/share/rnnoise-models
cd /tmp
git clone https://github.com/richardpl/arnndn-models.git
sudo cp arnndn-models/*.rnnn /usr/local/share/rnnoise-models/
sudo chmod 644 /usr/local/share/rnnoise-models/*.rnnn

Configurar Apache (si es necesario)

# Habilitar módulos requeridos
sudo a2enmod php7.4
sudo a2enmod rewrite

# Reiniciar Apache
sudo systemctl restart apache2

Verificar acceso a la aplicación

# Acceso desde servidor
curl http://10.204.2.5/noise/

# Acceso desde cliente en red local
# Abrir en navegador: http://10.204.2.5/noise/
