# Fuentes para las og:image dinámicas (M4)

IBM Plex Serif SemiBold e IBM Plex Sans Regular y SemiBold, © IBM Corp., bajo
SIL Open Font License 1.1 (ver `OFL.txt`). Son las mismas familias y pesos que
usa la web (`php/public/assets/fonts/`, 400/600), en TTF porque GD/FreeType no
lee woff2. Solo las usa `App\Og` para generar las tarjetas sociales por entidad
(`/og/{tipo}/{id}.jpg`). No se sirven por web (viven en `app/`, fuera del
webroot).

Origen: paquetes npm `@ibm/plex-serif` 2.0.0 e `@ibm/plex-sans` 1.1.0,
`fonts/complete/woff2/*`, convertidos a TTF con fontTools (sin subconjunto:
glifos completos, para que ningún título se quede sin carácter).
