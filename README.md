# Marchas de Cristo — Backend

Aplicación web de música procesional española ([marchasdecristo.com](https://marchasdecristo.com)).

**Stack actual (julio 2026):** PHP 8.4 plano · PDO/SQLite (FTS5) · sin build, deploy directo a hosting compartido (HelioHost/Plesk).

---

## Estructura del repositorio

```
mdc-back/
├── php/                # Toda la aplicación (ver php/README.md)
│   ├── public/          # document root del hosting
│   ├── app/             # código privado (fuera del document root)
│   ├── data/            # mdc.db en local (no versionado)
│   └── tools/           # scripts de paridad, import, backup
├── scripts/            # sync_db_to_prod.php, sync_propuestas_from_prod.php (ver docs/entornos.md)
├── .github/workflows/  # CI (lint + smoke tests) y deploy a producción (FTP, manual)
├── tools/
│   ├── ingest/          # herramienta offline de ingesta de YouTube (yt-dlp → candidatos → panel admin)
│   └── music_links/     # matching de enlaces de streaming (Spotify/Apple/Deezer) — ver docs/plan-music-apps.md
├── docs/               # contexto, arquitectura, deuda técnica, roadmap (ver docs/README.md para el índice completo)
├── ANALISIS_UX.md      # log narrativo del análisis UX comparativo (ver docs/ux-analysis-estado.md)
└── .env.ftp            # credenciales de deploy por FTP (gitignored)
```

Detalles de desarrollo local, deploy y estructura interna: [`php/README.md`](php/README.md).

---

## Documentación

Índice completo en [`docs/README.md`](docs/README.md) — empezar por
[`docs/context.md`](docs/context.md) para el punto de entrada (stack,
convenciones, estado actual).
