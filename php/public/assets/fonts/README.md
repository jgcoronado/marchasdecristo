# Fuentes web

IBM Plex Serif e IBM Plex Sans, subconjunto latino, pesos 400 y 600, en woff2.
Licencia SIL Open Font License 1.1 — el texto completo está en `OFL.txt` (el
mismo que acompaña a los TTF de `php/app/fonts/`, que usa Og.php para dibujar
las tarjetas de Open Graph).

Son la misma familia que esas tarjetas a propósito: el enlace que se comparte y
la página a la que lleva tienen que parecer del mismo sitio.

Origen: paquetes npm `@fontsource/ibm-plex-serif` y `@fontsource/ibm-plex-sans`
5.3.0, ficheros `*-latin-{400,600}-normal.woff2`. Para actualizarlas basta con
volver a copiar esos ficheros; los nombres de aquí son los que espera el
`@font-face` de `app.css`.

92 KB en total. `.htaccess` ya les da Cache-Control de 30 días (el `FilesMatch`
incluye `woff2?`) y la CSP los permite por `default-src 'self'`.
