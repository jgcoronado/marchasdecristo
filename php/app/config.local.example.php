<?php

declare(strict_types=1);

// Copia este archivo a config.local.php (NO se sube al git) y ajusta lo que necesites.
//   - En local: activa debug para ver errores.
//   - En el host (staging): apunta site_url al subdominio de pruebas.
//   - Fase 3 (auth): define secret_key con 48+ bytes aleatorios.

return [
    'debug' => true,
    'env'   => 'local', // habilita las escrituras del panel de admin. NO poner 'local' en el host de producción.

    // 'site_url' => 'https://jaguerra27.helioho.st', // staging durante la migración
    // 'db_path'  => __DIR__ . '/../data/mdc.db',      // por defecto ya apunta aquí
    // 'secret_key' => 'genera-48-bytes-aleatorios',   // Fase 3
    // 'force_canonical_host' => true,                 // ACTIVAR TRAS EL CUTOVER: 301 de
    //                                                 // jaguerra27.helioho.st y www a site_url
    // 'goatcounter_code' => 'marchasdecristo',        // crear cuenta gratis en goatcounter.com;
    //                                                 // fijar SOLO en prod para no contar visitas locales

    // 'indexnow_key' => 'genera-un-hex-random-p-ej-bin2hex-random_bytes-16', // IndexNow (C2):
    //   1. Genera un valor con: php -r "echo bin2hex(random_bytes(16));"
    //   2. Copia EL MISMO valor aquí Y en el config.local.php de producción
    //      (el admin lo necesita para firmar el ping tras el sync; producción
    //      lo necesita para servir /<clave>.txt, que IndexNow usa para verificar
    //      que el sitio es tuyo). Sin esto, sync_db_to_prod.php omite el ping.

    // 'preproduccion' => true, // SOLO en el host de PRE (subdominio de pruebas):
    //                          // noindex global + robots.txt Disallow + cinta visible.
    //                          // En PRE, dejar 'env' => 'production' (solo lectura,
    //                          // paridad con producción) y site_url apuntando al
    //                          // subdominio. Ver docs/entornos.md.
    // 'cover_base_url' => 'https://marchasdecristo.com', // SOLO en PRE: las portadas
    //                          // solo existen en el docroot de producción.

    // 'secciones_publicadas' => ['mapa'], // secciones de App\Secciones::EN_MADURACION
    //                          // que este host enseña pese a no estar publicadas
    //                          // todavía. Sirve para sacarlas primero en PRE y
    //                          // validarlas antes de tocarlas en PRO. En local
    //                          // no hace falta: local las ve todas.
];
