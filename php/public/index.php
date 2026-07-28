<?php

declare(strict_types=1);

// Servidor embebido de PHP (php -S) en local: deja que sirva ficheros estáticos
// existentes (portadas, CSS) tal cual. En Apache esto lo resuelve el .htaccess.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

// env.php (opcional, por host, NO versionado) permite mover APP_DIR/DATA_DIR
// fuera de su sitio por defecto. Lo genera el deploy de preproducción: el
// subdominio de PRE es hermano del docroot de PRO, así que sin este desvío
// ambos resolverían el MISMO app/ y PRE serviría el código de producción.
$envOverrides = is_file(__DIR__ . '/env.php') ? (array) require __DIR__ . '/env.php' : [];

define('PUBLIC_DIR', __DIR__);
define('BASE_DIR', dirname(__DIR__));   // php/ en local · /home/USER en HelioHost
define('APP_DIR', $envOverrides['app_dir'] ?? BASE_DIR . '/app');
define('DATA_DIR', $envOverrides['data_dir'] ?? BASE_DIR . '/data');

require APP_DIR . '/bootstrap.php';
