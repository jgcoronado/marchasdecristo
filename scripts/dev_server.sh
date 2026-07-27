#!/usr/bin/env bash
#
# Arranca el servidor embebido de PHP para desarrollo en local, con varios
# workers.
#
# El servidor embebido atiende por defecto UNA petición a la vez
# (PHP_CLI_SERVER_WORKERS=1): mientras genera una página, las peticiones de
# CSS/JS que el navegador lanza en paralelo se encolan. Con la BD real, una
# home tarda cientos de ms, así que los assets llegan tarde o se descartan
# bajo carga → páginas sin estilos y 503 intermitentes en app.css.
#
# Uso:
#   scripts/dev_server.sh                 # localhost:8000, 4 workers
#   scripts/dev_server.sh 8080            # otro puerto
#   HOST=0.0.0.0 WORKERS=8 scripts/dev_server.sh
#   DB_PATH=/ruta/otra.db scripts/dev_server.sh   # BD alternativa (p. ej. una fixture)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUBLIC_DIR="$ROOT/php/public"

HOST="${HOST:-localhost}"
PORT="${1:-${PORT:-8000}}"
WORKERS="${WORKERS:-${PHP_CLI_SERVER_WORKERS:-4}}"

if ! command -v php > /dev/null 2>&1; then
    echo "ERROR: no se encuentra 'php' en el PATH." >&2
    exit 1
fi

# La BD por defecto vive fuera del webroot (php/data/mdc.db) y no se versiona:
# hay que descargarla de producción. Ver php/README.md § Desarrollo en local.
DB="${DB_PATH:-$ROOT/php/data/mdc.db}"
if [ ! -f "$DB" ]; then
    echo "ERROR: no existe la base de datos: $DB" >&2
    echo "       Descarga mdc.db a php/data/ (ver php/README.md) o pasa DB_PATH=..." >&2
    exit 1
fi

# Sin config.local.php la app arranca en modo 'production': sin debug y con el
# panel de admin en solo lectura (Db::assertWritable). Avisar, no bloquear.
if [ ! -f "$ROOT/php/app/config.local.php" ]; then
    echo "AVISO: no hay php/app/config.local.php — sin 'debug' y con el panel en solo lectura."
    echo "       cp php/app/config.local.example.php php/app/config.local.php"
fi

# Los workers usan fork(): en Windows el servidor embebido ignora la variable y
# sigue atendiendo de una en una.
case "$(uname -s 2> /dev/null || echo unknown)" in
    MINGW* | MSYS* | CYGWIN* | Windows_NT)
        echo "AVISO: en Windows el servidor embebido ignora PHP_CLI_SERVER_WORKERS"
        echo "       (atenderá una petición a la vez; espera assets lentos)."
        ;;
esac

echo "→ http://$HOST:$PORT/         home"
echo "→ http://$HOST:$PORT/health   diagnóstico app → PDO → SQLite → FTS5"
echo "   BD: $DB · workers: $WORKERS · Ctrl-C para parar"
echo

export DB_PATH="$DB"
export PHP_CLI_SERVER_WORKERS="$WORKERS"
exec php -S "$HOST:$PORT" -t "$PUBLIC_DIR" "$PUBLIC_DIR/index.php"
