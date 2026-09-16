<?php
// Ajusta la ruta al .sqlite: no sé dónde lo tienes montado en el contenedor
$db = new PDO('sqlite:' . __DIR__ . '/../data/marchas.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "
SELECT b.id, COUNT(*) AS estrenos
FROM banda b
JOIN marcha m ON m.banda_estreno_id = b.id
WHERE b.provincia IN ('Sevilla','Cádiz','Córdoba','Granada','Huelva','Jaén','Málaga','Almería')
  AND (b.extincion IS NULL OR b.extincion = '')
  AND m.anio >= 2010
GROUP BY b.id
ORDER BY estrenos DESC, b.id ASC";

foreach ($db->query($sql) as $r) {
    echo "https://marchasdecristo.com/banda/x-{$r['id']}\n";
}