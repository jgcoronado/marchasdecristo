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

## Crimson Pro (rediseño C)

Crimson Pro variable (eje de peso 200–900), redonda y cursiva, subconjuntos
latino y latino extendido, en woff2. La declara `c/base.css` y la usan h1, h2,
la frase de la ficha y las Notas (`--f-serif` en `c/tokens.css`). Licencia SIL
Open Font License 1.1, sin nombre reservado: el texto está en
`OFL-crimson-pro.txt`, copiado del repositorio oficial
(https://github.com/Fonthausen/CrimsonPro/blob/master/OFL.txt).

La cursiva se aloja porque las líneas maestras (§4) la piden: «Crimson Pro
(variable, con cursiva)», y «cursiva solo dentro de la prosa, para títulos de
obra», es decir, en las Notas. Sin el fichero el navegador inclinaría la
redonda (cursiva sintética). Los titulares siguen siempre en redonda.

Origen: paquete npm `@fontsource-variable/crimson-pro` 5.3.0 (licencia
OFL-1.1), ficheros `crimson-pro-latin-wght-{normal,italic}.woff2` y
`crimson-pro-latin-ext-wght-{normal,italic}.woff2`, con los mismos
`unicode-range` que sus `wght.css` y `wght-italic.css`. Ninguna petición a
CDN. 173 KB en total (84 KB la redonda, 89 KB la cursiva); solo se precarga
la redonda latina, y la cursiva solo se descarga en páginas que la usan.
