# Plan: enlaces a servicios de streaming (rama `feature/music-apps`)

> Nota 2026-07-27: la migración `004_enlace_streaming.sql` ya se aplica
> automáticamente (`migrate_ingest.php` aplica todos los `.sql` de
> `app/tools/sql/`, no hace falta "visto bueno" aparte — ver §6, obsoleto). El
> render en ficha de marcha (§4, componente 5) también está hecho: `H::streaming()`
> se invoca ya en `marcha_detail.php`, no solo en disco/banda. Iteración
> posterior no contemplada en este plan original: `app/tools/fill_enlaces_streaming.php`,
> que cruza el catálogo completo de Spotify de cada banda (álbumes/pistas) en
> vez de buscar disco a disco — ver [admin-panel.md §10](admin-panel.md) y
> [technical-debt.md](technical-debt.md).

Objetivo: automatizar los enlaces de **marchas, discos y bandas** a servicios de
streaming (Spotify, Apple Music, Deezer, YouTube…) para poder ofrecer audio en
cada consulta de la BD.

Decisiones tomadas (2026-07-10):
- **Servicios:** Deezer, Apple/iTunes, Spotify, YouTube.
- **Almacenamiento:** tabla genérica `enlace_streaming` (+ `enlace_candidato`). Ver `php/app/tools/sql/004_enlace_streaming.sql`.
- **Escritura:** candidatos con confianza → **curación en panel admin** (mismo patrón que la ingesta de YouTube). Nada se publica sin aprobar.

---

## 1. Modelo de datos

Relación existente: `banda 1─N disco 1─N disco_marcha N─1 marcha`.

Tabla nueva `enlace_streaming` (aditiva, no rompe nada):

| Campo | Uso |
|---|---|
| `TIPO_ENT` | `banda` \| `disco` \| `marcha` |
| `ID_ENT` | id de esa entidad |
| `SERVICIO` | `spotify` `apple` `deezer` `youtube` `tidal` `amazon` |
| `URL`, `ID_EXT` | enlace público + id nativo del servicio |
| `VERIFICADO`, `FECHA_ALTA` | trazabilidad |

`enlace_candidato` = cola de revisión (título/artista/año encontrados + `SCORE` + `CONFIANZA` + `ESTADO`).

Nota: `marcha.AUDIO` ya guarda 1 URL de YouTube por marcha (1.277/4.413). Es
migrable a `(marcha, youtube)` en esta tabla, pero es **opcional** — decisión aparte.

---

## 2. Fases (por orden)

### Fase 1 — Discos de bandas activas de Sevilla capital  ← *primer intento hecho*
- Alcance: `LOCALIDAD='Sevilla' AND PROVINCIA='Sevilla' AND FECHA_EXT vacío` → 14 bandas, 93 discos.
- Estado: `tools/music_links/match_discos.py` genera candidatos con Deezer + iTunes.
- Resultado primer intento (solo lectura): **34 ALTA · 12 MEDIA · 25 BAJA · 22 sin match**. Precisión del tramo ALTA verificada alta.
- Pendiente para cerrar fase 1: añadir Spotify (cubre los sin-match tipo *Redención*/*Encarnación*), volcar a `enlace_candidato`, revisar en panel.

### Fase 2 — Página de artista de la banda  ← *hecho*
- `tools/music_links/match_bandas.py`: busca el perfil de artista de cada banda en Spotify/Deezer/Apple, con refuerzo por el artista de sus discos de fase 1 y guard anti-falsos-positivos de un solo token.
- Resultado: 26 candidatos de banda (22 ALTA / 4 MEDIA), 9 de 14 bandas con ALTA. Volcados a `enlace_candidato` (TIPO_ENT='banda').
- Falsos positivos conocidos que exige curar el panel: "Los Angeles" (≠ BCT Ángeles), "Tres Caidas de Triana", "La Santa Cecilia" (banda mexicana) — nombres genéricos que comparten tokens; sin señal de localidad no son distinguibles automáticamente.
- El panel `/dashboard/enlaces` ya muestra y cura candidatos de disco **y** de banda (vista polimórfica).

### Fase 3 — Singles / marchas estrenadas fuera de disco  ← *hecho*
- `tools/music_links/match_marchas.py`: busca pista/single en Spotify/Apple/Deezer para las 240 marchas estrenadas por las 14 bandas que **no** están en ningún disco.
- Heurística del usuario aplicada: boost fuerte (+0.2) cuando el año de la pista == año de estreno → gran precisión.
- Resultado: 70 candidatos (52 ALTA), 56/240 marchas. Precisión ALTA muy alta (título exacto + banda + año). Volcados a `enlace_candidato` (TIPO_ENT='marcha').
- Panel y ficha de marcha ya integrados: el panel cura candidatos de marcha; la ficha `/marcha/…` pinta la botonera (sustituye el antiguo placeholder "TODO · más servicios" y los chips falsos), conviviendo con el embed de YouTube (`marcha.AUDIO`).

---

## 3. Métodos por servicio (viabilidad)

| Servicio | Método | Credenciales | Nivel |
|---|---|---|---|
| **Deezer** | `api.deezer.com/search` | ninguna | ✅ operativo |
| **Apple** | `itunes.apple.com/search` | ninguna | ✅ operativo |
| **Spotify** | Web API `/search` (client-credentials) | **Client ID+Secret** (app gratuita en developer.spotify.com) | ⏳ falta credencial |
| **YouTube** | yt-dlp / Data API v3 | key (o yt-dlp sin key) | ya en uso |
| Tidal | API partner / OAuth | acceso partner | ❌ difícil, descartado por ahora |
| Amazon Music | sin API pública de búsqueda | — | ❌ descartado |

---

## 4. Pipeline (común a las 3 fases)

```
seleccionar entidades (fase/alcance)
  → buscar en cada servicio (1 llamada por artista, cacheada)
  → puntuar candidato: 0.55·sim(título) + 0.30·sim(artista) + 0.15·(año coincide)
     · descarta si sim(artista) < 0.34   (evita "Various Artists")
     · confianza: ALTA ≥0.55 · MEDIA ≥0.40 · BAJA <0.40 · SIN_MATCH
  → INSERT en enlace_candidato (ESTADO='pendiente')
  → PANEL ADMIN: aprobar/rechazar
  → aprobado → INSERT enlace_streaming
  → ficha pública lee enlace_streaming y pinta botones por servicio
```

### Ampliación de ámbito
Los 3 matchers comparten `tools/music_links/mdc_music.py` (HTTP con reintentos/backoff
para el rate-limit de iTunes, búsquedas por servicio, token Spotify, selección de ámbito)
y aceptan `--scope`:

| scope | criterio |
|---|---|
| `sevilla-capital` | activas, PROVINCIA=Sevilla y LOCALIDAD=Sevilla (14 bandas) |
| `sevilla-provincia` | activas, PROVINCIA=Sevilla (34) |
| `all-active` (por defecto) | todas las no extintas (90) |
| `all` | todas (268, incl. extintas) |

Fase 3 admite `--no-itunes` (útil en ámbitos grandes: 1.138 marchas × iTunes choca con su
límite de tasa; Spotify+Deezer cubren el grueso). Volúmenes: 90 activas, 290 discos, 1.138
marchas de estreno sin disco.

Componentes a construir:
1. **Matcher** parametrizable por fase/servicio/alcance (evolución del script actual). ← *hecho* (`--scope`, módulo compartido)
2. **Módulo Spotify** (token client-credentials + search) — en cuanto haya credenciales.
3. **Migración** `004_enlace_streaming.sql` — ✅ aplicada (vía `migrate_ingest.php`, automático).
4. **Panel admin** de curación de enlaces (reutilizar UI de ingesta YouTube). ← *hecho* (`/dashboard/enlaces`)
5. **Render en ficha** (banda/disco/marcha) de los botones de streaming. ← *hecho en las tres* (`Html::streaming` + `EnlaceRepo::publicadosDe`, leyendo `enlace_streaming`; también en `marcha_detail.php`). Solo pinta enlaces aprobados.

---

## 5. Riesgos / decisiones abiertas
- **Falsos positivos** por nombres genéricos de disco (*Sevilla*, *Aniversario*) → mitigado con umbral de artista + curación humana.
- **Cobertura desigual**: Deezer/iTunes no tienen algunas AM sevillanas → Spotify necesario.
- **Rate limits**: cachear por artista; ~1 llamada/banda/servicio (bajo volumen en Sevilla).
- ¿Migrar `marcha.AUDIO` (YouTube) a `enlace_streaming` o dejarlo como está? → pendiente, sigue sin decidirse.
- ~~Aplicar la migración~~ ✅ resuelto — automático desde `migrate_ingest.php`.

---

## 6. Qué necesito de ti para seguir
1. **Credenciales de Spotify**: crea una app gratuita en developer.spotify.com y pásame `Client ID` y `Client Secret` (son de una app de desarrollo, no tu cuenta personal). Sin eso avanzo con Deezer/iTunes. *(Sin verificar si esto ya se resolvió — `fill_enlaces_streaming.php` solo usa Spotify, así que si ese script funciona en producción, las credenciales ya están puestas.)*
2. ~~Visto bueno para aplicar `004_enlace_streaming.sql`~~ ✅ ya no aplica, ver nota de cabecera.
