<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Autenticación — port de lib/auth-session.ts + app/api/login/route.ts.
 *   - Sesión firmada HMAC-SHA256 (mismo formato que Next → cookies compatibles si
 *     SECRET_KEY coincide).
 *   - Password PBKDF2-SHA512 / MD5 legacy con auto-upgrade (mismos hashes válidos).
 *   - Rate limiting persistido en fichero (PHP no comparte memoria entre peticiones).
 *   - Token CSRF derivado de la sesión para los formularios.
 */
final class Auth
{
    private const MIN_SECRET = 32;

    private static function cfg(string $k, mixed $default): mixed
    {
        return $GLOBALS['config'][$k] ?? $default;
    }

    private static function secret(): string
    {
        $s = (string) self::cfg('secret_key', '');
        if (strlen($s) < self::MIN_SECRET) {
            throw new RuntimeException('SECRET_KEY ausente o demasiado corta (mín. 32 caracteres). Defínela en app/config.local.php');
        }
        return $s;
    }

    public static function cookieName(): string
    {
        return (string) self::cfg('auth_cookie_name', 'mdc_session');
    }

    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private static function secureCookie(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
        return (bool) self::cfg('cookie_secure', false);
    }

    // ── base64url ──────────────────────────────────────────────────────────
    private static function b64urlEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
    private static function b64urlDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return base64_decode($s) ?: '';
    }

    // ── Sesión firmada ─────────────────────────────────────────────────────
    public static function signSession(array $payload): string
    {
        $encoded = self::b64urlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $sig = self::b64urlEncode(hash_hmac('sha256', $encoded, self::secret(), true));
        return $encoded . '.' . $sig;
    }

    public static function verifySession(?string $token): ?array
    {
        if (!$token) return null;
        $dot = strpos($token, '.');
        if ($dot === false) return null;
        $encoded = substr($token, 0, $dot);
        $sig = substr($token, $dot + 1);
        $expected = self::b64urlEncode(hash_hmac('sha256', $encoded, self::secret(), true));
        if (!hash_equals($expected, $sig)) return null;
        $payload = json_decode(self::b64urlDecode($encoded), true);
        if (!is_array($payload)) return null;
        if (empty($payload['exp']) || self::nowMs() > (int) $payload['exp']) return null;
        return $payload;
    }

    // ── Cookies ────────────────────────────────────────────────────────────
    public static function setSessionCookie(string $token): void
    {
        $ttl = (int) self::cfg('login_ttl_ms', 8 * 60 * 60 * 1000);
        setcookie(self::cookieName(), $token, [
            'expires' => time() + intdiv($ttl, 1000),
            'path' => '/',
            'httponly' => true,
            'secure' => self::secureCookie(),
            'samesite' => 'Lax',
        ]);
    }
    public static function clearSessionCookie(): void
    {
        setcookie(self::cookieName(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'secure' => self::secureCookie(),
            'samesite' => 'Lax',
        ]);
    }

    public static function currentSession(): ?array
    {
        return self::verifySession($_COOKIE[self::cookieName()] ?? null);
    }

    /**
     * Rol del usuario, leído de la BD (no de la cookie) para que un cambio de rol
     * o un reset surtan efecto de inmediato. Compatibilidad hacia atrás: si la
     * columna ROL aún no existe (BD sin migrar), se trata al usuario como admin
     * —el comportamiento previo, cuando el panel tenía un solo administrador—.
     */
    public static function roleOf(string $user): string
    {
        // Memoizado por petición: el guard lo pide una vez (requireAuth) y el
        // layout otra (aviso de desincronización del panel), y el rol no puede
        // cambiar a mitad de una petición.
        static $cache = [];
        if (isset($cache[$user])) {
            return $cache[$user];
        }
        try {
            $row = Db::one('SELECT ROL FROM usuarios WHERE usuario = ? LIMIT 1', [$user]);
        } catch (\PDOException) {
            return Roles::ADMIN; // BD pre-migración: sin columna ROL (no se cachea)
        }
        $rol = $row === null
            ? Roles::EDITOR
            : Roles::normalize(is_string($row['ROL'] ?? null) ? (string) $row['ROL'] : null);
        return $cache[$user] = $rol;
    }

    /**
     * Guard: exige sesión válida o redirige a /login. Enriquece el payload con
     * 'rol' (desde la BD) y fija el usuario de auditoría para admin_log.
     *
     * @return array{user:string,rol:string,jti?:string,iat?:int,exp?:int}
     */
    public static function requireAuth(): array
    {
        Http::noStore(); // el contenido de admin nunca se cachea
        $payload = self::currentSession();
        if ($payload === null) {
            Http::redirect('/login', 302);
        }
        $user = (string) ($payload['user'] ?? '');
        $payload['rol'] = self::roleOf($user);
        Db::setAuditUser($user !== '' ? $user : 'system');
        return $payload;
    }

    /**
     * Guard con capacidad: exige sesión y que el rol tenga $cap; si no, 403.
     *
     * @return array{user:string,rol:string,jti?:string,iat?:int,exp?:int}
     */
    public static function requireCap(string $cap): array
    {
        $session = self::requireAuth();
        if (!Roles::has($session['rol'] ?? null, $cap)) {
            Http::forbidden();
        }
        return $session;
    }

    /** Guard: exige rol admin; si no, 403. */
    public static function requireAdmin(): array
    {
        $session = self::requireAuth();
        if (!Roles::isAdmin($session['rol'] ?? null)) {
            Http::forbidden();
        }
        return $session;
    }

    // ── CSRF ───────────────────────────────────────────────────────────────
    public static function csrfToken(array $session): string
    {
        return hash_hmac('sha256', 'csrf|' . ($session['jti'] ?? ''), self::secret());
    }
    public static function checkCsrf(?string $submitted, array $session): bool
    {
        return is_string($submitted) && hash_equals(self::csrfToken($session), $submitted);
    }

    // ── Passwords ──────────────────────────────────────────────────────────
    public static function verifyPassword(string $plain, string $stored): bool
    {
        if ($stored === '') return false;
        if (str_starts_with($stored, 'pbkdf2$')) {
            [$_, $digest, $iterStr, $salt, $expected] = array_pad(explode('$', $stored), 5, '');
            $iters = (int) $iterStr;
            if ($digest === '' || $salt === '' || $expected === '' || $iters <= 0) return false;
            $derived = self::b64urlEncode(hash_pbkdf2($digest, $plain, $salt, $iters, 64, true));
            return hash_equals($expected, $derived);
        }
        return hash_equals($stored, md5($plain));
    }

    public static function hashPassword(string $plain): string
    {
        $iters = (int) self::cfg('password_pbkdf2_iterations', 210000);
        $salt = self::b64urlEncode(random_bytes(16));
        $derived = self::b64urlEncode(hash_pbkdf2('sha512', $plain, $salt, $iters, 64, true));
        return "pbkdf2\$sha512\$$iters\$$salt\$$derived";
    }

    public static function isLegacyMd5(string $stored): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/i', $stored);
    }

    /**
     * Contraseña aleatoria legible para altas y resets (se muestra una sola vez).
     * Alfabeto sin caracteres ambiguos (0/O, 1/l/I) para dictarla sin errores.
     */
    public static function generatePassword(int $length = 14): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
    }

    // ── Rate limiting (persistido en fichero) ───────────────────────────────
    public static function rateKey(string $username): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ip = trim(explode(',', (string) $ip)[0]);
        return $ip . ':' . strtolower(trim($username));
    }

    private static function attemptsFile(): string
    {
        return dirname((string) self::cfg('db_path', '')) . '/.login_attempts.json';
    }
    private static function loadAttempts(): array
    {
        $f = self::attemptsFile();
        if (!is_file($f)) return [];
        $data = json_decode((string) file_get_contents($f), true);
        return is_array($data) ? $data : [];
    }
    private static function saveAttempts(array $data): void
    {
        @file_put_contents(self::attemptsFile(), json_encode($data), LOCK_EX);
    }

    /** Segundos que quedan de bloqueo, o 0 si no está bloqueado. */
    public static function rateRetryAfter(string $key): int
    {
        $s = self::loadAttempts()[$key] ?? null;
        if ($s && ($s['lockUntil'] ?? 0) > self::nowMs()) {
            return (int) ceil((($s['lockUntil']) - self::nowMs()) / 1000);
        }
        return 0;
    }

    public static function rateFail(string $key): void
    {
        $now = self::nowMs();
        $windowMs = (int) self::cfg('login_window_ms', 15 * 60 * 1000);
        $lockMs = (int) self::cfg('login_lock_ms', 15 * 60 * 1000);
        $max = (int) self::cfg('login_max_attempts', 6);

        $all = self::loadAttempts();
        $s = $all[$key] ?? null;
        // Reiniciar si la ventana expiró y no hay bloqueo vigente.
        if (!$s || ($now - ($s['firstAt'] ?? 0) > $windowMs && ($s['lockUntil'] ?? 0) < $now)) {
            $s = ['count' => 0, 'firstAt' => $now, 'lockUntil' => 0];
        }
        $s['count']++;
        if ($s['count'] >= $max) $s['lockUntil'] = $now + $lockMs;
        $all[$key] = $s;
        // Poda de entradas viejas.
        foreach ($all as $k => $v) {
            if (($v['lockUntil'] ?? 0) < $now && ($now - ($v['firstAt'] ?? 0)) > $windowMs) unset($all[$k]);
        }
        self::saveAttempts($all);
    }

    public static function rateClear(string $key): void
    {
        $all = self::loadAttempts();
        unset($all[$key]);
        self::saveAttempts($all);
    }
}
