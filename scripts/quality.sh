#!/usr/bin/env bash
#
# Auditoría de calidad y duplicidad de código. Ver docs/code-quality.md.
#
#   scripts/quality.sh              todo (lo mismo que corre CI)
#   scripts/quality.sh phpstan      análisis estático
#   scripts/quality.sh baseline     regenera phpstan-baseline.neon
#   scripts/quality.sh dup          duplicidad (jscpd, multi-lenguaje)
#   scripts/quality.sh phpmd        tamaño y complejidad
#   scripts/quality.sh metrics      resumen de métricas del repo
#
# Sin composer ni vendor/ (igual que el resto del proyecto, ver php/README.md):
# las herramientas son PHARs que se descargan una vez a .tools/ (gitignorado).
# jscpd se ejecuta con npx, sin instalación permanente.
#
# Código de salida: 0 si todo pasa; 1 si falla algún gate. `phpmd` y `metrics`
# son informativos y nunca hacen fallar el conjunto.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

TOOLS_DIR="$ROOT/.tools"
BUILD_DIR="$ROOT/build"
PHPSTAN_VERSION="2.2.7"
PHPMD_VERSION="2.15.0"
JSCPD_VERSION="4.0.5"

# Rutas de código propio. php/app/geo/municipios_es.php son datos generados y se
# excluye en cada configuración (phpstan.neon.dist, .jscpd.json, aquí).
PHP_PATHS="php/app,php/tools,scripts,php/app/tools"

mkdir -p "$TOOLS_DIR" "$BUILD_DIR"

# A stderr a propósito: fetch_phar devuelve la ruta del PHAR por stdout y se
# consume con $(...). Si los mensajes fueran por stdout se colarían en la ruta.
log()  { printf '\n\033[1m▸ %s\033[0m\n' "$*" >&2; }
warn() { printf '\033[33m⚠ %s\033[0m\n' "$*" >&2; }

# Descarga un PHAR una sola vez y lo cachea por versión.
# (Las asignaciones van en sentencias separadas: `local` es un builtin y sus
# argumentos se expanden ANTES de ejecutarse, así que $name no existiría todavía
# al construir $dest en la misma línea — con `set -u` eso aborta el script.)
fetch_phar() {
  local name="$1" version="$2" url="$3"
  local dest="$TOOLS_DIR/$name-$version.phar"
  if [ ! -f "$dest" ]; then
    log "Descargando $name $version → .tools/"
    curl -fsSL -o "$dest.tmp" "$url"
    mv "$dest.tmp" "$dest"
  fi
  echo "$dest"
}

phpstan_phar() {
  fetch_phar phpstan "$PHPSTAN_VERSION" \
    "https://github.com/phpstan/phpstan/releases/download/$PHPSTAN_VERSION/phpstan.phar"
}

phpmd_phar() {
  fetch_phar phpmd "$PHPMD_VERSION" \
    "https://github.com/phpmd/phpmd/releases/download/$PHPMD_VERSION/phpmd.phar"
}

# ── Gates (hacen fallar CI) ──────────────────────────────────────────────────

run_phpstan() {
  log "PHPStan (nivel según phpstan.neon.dist, sobre el baseline)"
  php "$(phpstan_phar)" analyse --no-progress --memory-limit=1G
}

run_baseline() {
  log "Regenerando phpstan-baseline.neon"
  php "$(phpstan_phar)" analyse --no-progress --memory-limit=1G \
    --generate-baseline phpstan-baseline.neon
  warn "Baseline regenerado: revisa el diff antes de commitear."
  warn "Añadir errores nuevos al baseline es aceptable al SUBIR de nivel; para código nuevo, arréglalos."
}

run_dup() {
  log "Duplicidad (jscpd $JSCPD_VERSION — PHP, JS, Python, CSS, SQL)"
  # El umbral y las exclusiones viven en .jscpd.json. --gitignore respeta
  # .gitignore, así que .tools/ y build/ quedan fuera solos.
  npx --yes "jscpd@$JSCPD_VERSION" .
}

# ── Informes (nunca hacen fallar) ────────────────────────────────────────────

run_phpmd() {
  log "PHPMD — tamaño y complejidad (informativo)"
  php "$(phpmd_phar)" "$PHP_PATHS" text phpmd.xml || true
  php "$(phpmd_phar)" "$PHP_PATHS" html phpmd.xml \
    --reportfile "$BUILD_DIR/phpmd.html" >/dev/null 2>&1 || true
  echo "Informe HTML: build/phpmd.html"
}

run_metrics() {
  log "Métricas del repositorio (informativo)"
  php scripts/quality_metrics.php
}

# ── Dispatch ─────────────────────────────────────────────────────────────────

case "${1:-all}" in
  phpstan)  run_phpstan ;;
  baseline) run_baseline ;;
  dup)      run_dup ;;
  phpmd)    run_phpmd ;;
  metrics)  run_metrics ;;
  all)
    failed=0
    run_phpstan || failed=1
    run_dup     || failed=1
    run_phpmd
    run_metrics
    if [ "$failed" -ne 0 ]; then
      warn "Hay gates en rojo (PHPStan y/o duplicidad). Detalle arriba."
      exit 1
    fi
    log "Todo en verde."
    ;;
  *)
    echo "Uso: scripts/quality.sh [all|phpstan|baseline|dup|phpmd|metrics]" >&2
    exit 2
    ;;
esac
