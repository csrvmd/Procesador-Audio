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