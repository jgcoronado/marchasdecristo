<?php

declare(strict_types=1);

namespace App;

/**
 * Envío de email transaccional mediante PHP mail().
 *
 * Configurar en config.local.php:
 *   'mail_from'      => 'noreply@marchasdecristo.com',
 *   'mail_from_name' => 'Marchas de Cristo',        // opcional
 *   'mail_admin_to'  => 'admin@ejemplo.com',         // destino del digest semanal
 *   'notif_emails'   => ['usuario' => 'email@...'],  // mapa editor → email
 *
 * Si mail_from no está definido, send() devuelve false sin intentar nada.
 */
final class Mailer
{
    /**
     * Envía un email multipart (texto plano + HTML) usando mail().
     * No lanza excepción en caso de fallo: devuelve false silenciosamente.
     */
    public static function send(string $to, string $subject, string $html, string $text = ''): bool
    {
        $config = $GLOBALS['config'] ?? [];
        $from = trim((string) ($config['mail_from'] ?? ''));
        if ($from === '' || $to === '') return false;

        $fromName = (string) ($config['mail_from_name'] ?? 'Marchas de Cristo');
        if ($text === '') $text = self::htmlToText($html);

        $boundary = 'mdc' . bin2hex(random_bytes(6));

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'From: ' . self::encodeHeader($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
        ]);

        $body = "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . "--{$boundary}--";

        return @mail($to, self::encodeHeader($subject), $body, $headers);
    }

    /**
     * Devuelve la dirección de email de un editor por su nombre de usuario,
     * leyendo el mapa $config['notif_emails']['usuario'] => 'email@...'.
     * Devuelve '' si no hay entrada para ese usuario.
     */
    public static function editorEmail(string $username): string
    {
        $map = (array) ($GLOBALS['config']['notif_emails'] ?? []);
        return (string) ($map[$username] ?? '');
    }

    /** Codifica una cabecera non-ASCII como RFC 2047 base64 (UTF-8). */
    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7e]/', $s) === 1
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }

    /** Convierte HTML simple a texto plano para la parte alternativa. */
    private static function htmlToText(string $html): string
    {
        $t = preg_replace(['/<br\s*\/?>/i', '/<\/p>/i', '/<\/h[1-6]>/i'], ["\n", "\n\n", "\n\n"], $html) ?? '';
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/[ \t]+/', ' ', $t));
    }
}
