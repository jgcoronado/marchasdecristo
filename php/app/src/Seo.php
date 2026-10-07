<?php

declare(strict_types=1);

namespace App;

/**
 * Port de nextjs/lib/schema.ts — genera los objetos JSON-LD (schema.org).
 * Las URLs internas del JSON-LD usan Slug::buildDetailPath (M8) — antes
 * tenían un slugify propio, distinto del de Slug.php, heredado de que
 * schema.ts en Next.js ya divergía de lib/slugify.ts; unificado porque esa
 * paridad histórica ya no aporta nada y era fuente de incoherencia entre la
 * URL canónica real y la que veían los robots en el JSON-LD.
 * Las claves se construyen en el mismo orden que el objeto JS y se omiten las
 * que en JS quedarían `undefined`.
 */
final class Seo
{
    private static function base(): string
    {
        return rtrim((string) ($GLOBALS['config']['site_url'] ?? 'https://marchasdecristo.com'), '/');
    }

    private static function formatDate(mixed $dateStr): ?string
    {
        if (empty($dateStr)) return null;
        $str = (string) $dateStr;
        if (str_contains($str, '-')) return $str;
        if (strlen($str) === 4) return $str;
        return preg_match('/^\d{4}/', $str) ? $str : null;
    }

    // yt-dlp solo da la fecha (upload_date = YYYYMMDD → 'Y-m-d'), sin hora ni
    // zona horaria. Google exige un dateTime completo con offset para
    // VideoObject.uploadDate; un 'Y-m-d' a secas lo marca como "no válido"
    // en Search Console (no crítico, pero evitable). Como no conocemos la
    // hora real de publicación, fijamos medianoche en la zona del sitio.
    private static function isoDateTime(mixed $dateStr): ?string
    {
        if (empty($dateStr)) return null;
        $str = (string) $dateStr;
        try {
            $dt = new \DateTime($str, new \DateTimeZone('Europe/Madrid'));
        } catch (\Exception) {
            return null;
        }
        $dt->setTime(0, 0, 0);
        return $dt->format('c');
    }

    public static function marcha(array $data, string $url): array
    {
        $base = self::base();
        $autores = array_map(static fn(array $a): array => [
            '@type' => 'Person',
            'name' => $a['nombre'],
            'url' => $base . Slug::buildDetailPath('autor', $a['autorId'], (string) $a['nombre']),
        ], $data['AUTOR']);

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'MusicComposition',
            'name' => $data['TITULO'],
            'url' => $url,
        ];
        if (count($autores) > 0) $schema['creator'] = $autores;
        if (!empty($data['FECHA'])) $schema['dateCreated'] = (string) $data['FECHA'];
        $schema['description'] = 'Marcha procesional'
            . (!empty($data['DEDICATORIA']) ? ' dedicada a ' . $data['DEDICATORIA'] : '') . '.';
        if (!empty($data['BANDA_ESTRENO'])) {
            // La URL usa NOMBRE_COMPLETO (el mismo campo que Pages::bandaDetail
            // usa para la canónica) — 'name' sigue mostrando el nombre corto +
            // localidad de $data['BANDA']: qué se muestra y a qué URL apunta
            // son cosas distintas.
            $bandaNombreCompleto = !empty($data['BANDA_NOMBRE_COMPLETO']) ? $data['BANDA_NOMBRE_COMPLETO'] : $data['BANDA'];
            $schema['performanceLocation'] = [
                '@type' => 'MusicGroup',
                'name' => $data['BANDA'],
                'url' => $base . Slug::buildDetailPath('banda', $data['BANDA_ESTRENO'], (string) $bandaNombreCompleto),
            ];
        }
        if (($data['discosLength'] ?? 0) > 0) {
            $schema['recordedAs'] = array_map(static fn(array $d): array => [
                '@type' => 'MusicRecording',
                'name' => $d['NOMBRE_CD'],
                'byArtist' => ['@type' => 'MusicGroup', 'name' => $d['BANDA']],
            ], $data['discos']);
        }

        // Vídeo asociado (VideoObject). Solo se emite cuando conocemos la fecha
        // de publicación (uploadDate): Google la exige como campo obligatorio,
        // así que sin ella preferimos no generar un item inválido en el informe
        // de vídeos de Search Console.
        $ytid = Media::youtubeId($data['AUDIO'] ?? null);
        if ($ytid !== null && !empty($data['VIDEO_UPLOAD'])) {
            $schema['associatedMedia'] = [
                '@type' => 'VideoObject',
                'name' => 'Interpretación de «' . $data['TITULO'] . '»',
                'description' => 'Interpretación en vídeo de la marcha procesional «' . $data['TITULO'] . '»'
                    . (!empty($data['BANDA_ESTRENO']) ? ' por ' . $data['BANDA'] : '') . '.',
                'thumbnailUrl' => Media::youtubeThumb($ytid),
                'uploadDate' => self::isoDateTime($data['VIDEO_UPLOAD']),
                'contentUrl' => $data['AUDIO'],
                'embedUrl' => Media::youtubeEmbed($ytid),
            ];
        }
        return $schema;
    }

    public static function autor(array $data, string $url): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => trim(($data['NOMBRE'] ?? '') . ' ' . ($data['APELLIDOS'] ?? '')),
            'url' => $url,
        ];
        if (!empty($data['F_NAC'])) {
            $bd = self::formatDate($data['F_NAC']);
            if ($bd !== null) $schema['birthDate'] = $bd;
        }
        if (!empty($data['LUGAR_NAC'])) {
            $schema['birthPlace'] = ['@type' => 'Place', 'name' => $data['LUGAR_NAC']];
        }
        $n = (int) $data['marchasLength'];
        $schema['description'] = 'Compositor de música procesional. Ha compuesto ' . $n . ($n === 1 ? ' marcha.' : ' marchas.');
        if (!empty($data['BIO'])) $schema['knowsAbout'] = $data['BIO'];
        return $schema;
    }

    public static function banda(array $data, string $url): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'MusicGroup',
            'name' => !empty($data['NOMBRE_COMPLETO']) ? $data['NOMBRE_COMPLETO'] : $data['NOMBRE_BREVE'],
            'url' => $url,
        ];
        if (!empty($data['FECHA_FUND'])) $schema['foundingDate'] = (string) $data['FECHA_FUND'];
        if (!empty($data['FECHA_EXT'])) $schema['dissolutionDate'] = (string) $data['FECHA_EXT'];
        $schema['location'] = ['@type' => 'Place', 'name' => $data['LOCALIDAD']];
        $schema['description'] = 'Banda de música procesional. Ha estrenado ' . $data['marchasLength']
            . ' marchas y grabado ' . $data['discosLength'] . ' discos.';
        return $schema;
    }

    public static function disco(array $data, string $url): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'MusicAlbum',
            'name' => $data['NOMBRE_CD'],
            'url' => $url,
            'byArtist' => ['@type' => 'MusicGroup', 'name' => $data['BANDA']],
        ];
        if (!empty($data['FECHA_CD'])) $schema['datePublished'] = (string) $data['FECHA_CD'];
        $schema['description'] = 'Disco que contiene ' . $data['marchasLength'] . ' marchas de música procesional.';
        if (($data['marchasLength'] ?? 0) > 0) {
            $marchas = $data['marchas'];
            $schema['tracks'] = [
                '@type' => 'ItemList',
                'itemListElement' => array_map(
                    static fn(array $m, int $idx): array => [
                        '@type' => 'MusicRecording',
                        'position' => $idx + 1,
                        'name' => $m['TITULO'],
                        'byArtist' => array_map(
                            static fn(array $a): array => ['@type' => 'Person', 'name' => $a['nombre']],
                            $m['AUTOR']
                        ),
                    ],
                    $marchas,
                    array_keys($marchas)
                ),
            ];
        }
        return $schema;
    }

    /**
     * Hub de dedicatoria (pantalla N-01): CollectionPage cuyo mainEntity es un
     * ItemList de las marchas dedicadas a la advocación.
     *
     * @param array{NOMBRE:string,LOCALIDAD:string,marchas:list<array<string,mixed>>} $data
     */
    public static function dedicatoria(array $data, string $url): array
    {
        $loc = trim((string) ($data['LOCALIDAD'] ?? ''));
        $titular = $data['NOMBRE'] . ($loc !== '' ? ' (' . $loc . ')' : '');
        $items = array_map(
            static fn(array $m, int $idx): array => [
                '@type' => 'ListItem',
                'position' => $idx + 1,
                'item' => [
                    '@type' => 'MusicComposition',
                    'name' => $m['TITULO'],
                    'url' => self::base() . Slug::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO']),
                ],
            ],
            $data['marchas'],
            array_keys($data['marchas'])
        );
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => 'Marchas dedicadas a ' . $titular,
            'url' => $url,
            'description' => 'Marchas procesionales dedicadas a ' . $titular . '.',
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ],
        ];
    }

    /**
     * Hub de catálogo (C1: año / estilo / provincia): CollectionPage cuyo
     * mainEntity es un ItemList con las marchas de la página actual;
     * numberOfItems refleja el total del hub. Las URLs usan Slug::buildDetailPath
     * (la canónica real), no el slugify legado de paridad.
     *
     * @param list<array<string,mixed>> $marchas  filas de la página actual
     */
    public static function marchaHub(string $nombre, string $descripcion, array $marchas, int $total, string $url): array
    {
        $base = self::base();
        $items = array_map(
            static fn(array $m, int $idx): array => [
                '@type' => 'ListItem',
                'position' => $idx + 1,
                'item' => [
                    '@type' => 'MusicComposition',
                    'name' => $m['TITULO'],
                    'url' => $base . Slug::buildDetailPath('marcha', $m['ID_MARCHA'], (string) $m['TITULO']),
                ],
            ],
            $marchas,
            array_keys($marchas)
        );
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $nombre,
            'url' => $url,
            'description' => $descripcion,
            'mainEntity' => [
                '@type' => 'ItemList',
                'numberOfItems' => $total,
                'itemListElement' => $items,
            ],
        ];
    }

    /** @param list<array{name:string,url:string}> $items */
    public static function breadcrumbs(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(
                static fn(array $it, int $idx): array => [
                    '@type' => 'ListItem',
                    'position' => $idx + 1,
                    'name' => $it['name'],
                    'item' => $it['url'],
                ],
                $items,
                array_keys($items)
            ),
        ];
    }

    /** Serializa un objeto schema para incrustar en <script type="application/ld+json">. */
    public static function json(array $schema): string
    {
        return (string) json_encode(
            $schema,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );
    }
}
