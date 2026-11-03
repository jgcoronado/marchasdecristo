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
        $base = (string) self::cfg('auth_cookie_name', 'mdc_session');
        // Prefijo __Host- cuando la conexión es segura: el navegador solo acepta
        // una cookie así si va con Secure, Path=/ y SIN Domain, lo que la blinda
        // frente a la fijación/inyección desde un subdominio (queda un subdominio
        // heredado, ver .htaccess). En local por HTTP no se puede usar —el
        // navegador la rechazaría—, así que ahí se mantiene el nombre llano.
        // set/read usan ambos esta función, así que el nombre es coherente dentro
        // de un mismo entorno; en producción el cambio solo obliga a un reinicio
        // de sesión único (la cookie vieja deja de leerse).
        return self::secureCookie() ? '__Host-' . $base : $base;
    }

    /** Nombre de la cookie del token CSRF de pre-sesión del formulario de login. */
    public static function loginCsrfCookieName(): string
    {
        $base = 'mdc_login_csrf';
        return self::secureCookie() ? '__Host-' . $base : $base;
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

    /**
     * Sesión vigente o null. Además de la firma y la caducidad del token, exige
     * que la cuenta siga existiendo y que su contraseña no haya cambiado desde
     * el login (huella 'pv'): borrar un usuario o resetearle la clave corta sus
     * sesiones abiertas en la siguiente petición. Enriquece con 'rol' (de la BD).
     *
     * @return array{user:string,rol:string,jti?:string,iat?:int,exp?:int}|null
     */
    public static function currentSession(): ?array
    {
        $payload = self::verifySession($_COOKIE[self::cookieName()] ?? null);
        if ($payload === null) return null;
        $user = (string) ($payload['user'] ?? '');
        $cuenta = $user !== '' ? self::cuenta($user) : null;
        if ($cuenta === null) return null;
        if (!hash_equals(self::huellaClave($cuenta['clave']), (string) ($payload['pv'] ?? ''))) return null;
        $payload['user'] = $user;
        $payload['rol'] = $cuenta['rol'];
        return $payload;
    }

    /**
     * Huella de la clave almacenada que viaja en el token de sesión. Cambia en
     * cuanto cambia el hash (reset, cambio de contraseña, migración MD5→PBKDF2),
     * lo que invalida los tokens emitidos antes. No revela el hash: es un HMAC
     * con la SECRET_KEY.
     */
    public static function huellaClave(string $claveAlmacenada): string
    {
        return self::b64urlEncode(substr(hash_hmac('sha256', 'pv|' . $claveAlmacenada, self::secret(), true), 0, 16));
    }

    /**
     * Rol y hash de la cuenta, memoizados por petición (el guard y el layout la
     * piden varias veces; no puede cambiar a mitad de una petición). Null si la
     * cuenta no existe. Un fallo de la BD NO se traduce en ningún rol: se
     * propaga y la petición falla en cerrado.
     *
     * @return array{rol:string,clave:string}|null
     */
    private static function cuenta(string $user): ?array
    {
        static $cache = [];
        if (array_key_exists($user, $cache)) return $cache[$user];
        $row = Db::one('SELECT ROL, clave FROM usuarios WHERE usuario = ? LIMIT 1', [$user]);
        return $cache[$user] = $row === null ? null : [
            'rol' => Roles::normalize(is_string($row['ROL'] ?? null) ? (string) $row['ROL'] : null),
            'clave' => (string) ($row['clave'] ?? ''),
        ];
    }

    /**
     * Rol del usuario, leído de la BD (no de la cookie) para que un cambio de
     * rol surta efecto de inmediato. Null si la cuenta no existe: sin cuenta no
     * hay rol, y Roles::has(null, …) es false.
     */
    public static function roleOf(string $user): ?string
    {
        return self::cuenta($user)['rol'] ?? null;
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
        $user = $payload['user'];
        Db::setAuditUser($user);
        // Barrera única para todo el panel: fuera de local ningún envío del
        // admin llega a su controlador (ni escribe ni encola propuestas). Todos
        // los POST del panel pasan por aquí vía requireCap/requireAdmin.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && self::soloLectura($payload)) {
            Http::soloLectura();
        }
        return $payload;
    }

    /**
     * ¿La sesión solo puede MIRAR el panel? El admin fuera de local: la BD
     * maestra es la local y scripts/sync_db_to_prod.php reemplaza el .db
     * remoto entero, así que en PRE y PRO no guarda, no propone, no acepta ni
     * rechaza propuestas y no gestiona usuarios — los cambios se hacen en
     * local. El editor no entra aquí: él siempre propone (Admin::proposalMode),
     * y una propuesta no toca la BD.
     *
     * @param array<string,mixed> $session con 'rol'
     */
    public static function soloLectura(array $session): bool
    {
        return Roles::isAdmin($session['rol'] ?? null) && !Entorno::permiteEscrituraDirecta();
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

    // ── CSRF de pre-sesión para el login ─────────────────────────────────────
    // El formulario de login aún no tiene sesión de la que derivar el token CSRF
    // de los formularios del panel, así que usa un "double submit": un token
    // aleatorio que viaja a la vez en una cookie (SameSite=Lax, que el navegador
    // NO envía en un POST cross-site) y en un campo oculto; loginPost exige que
    // coincidan. Una petición forjada desde otro sitio no lleva la cookie Lax, y
    // el atacante no puede leerla ni fijarla, de modo que no puede falsificar el
    // par y el login-CSRF queda bloqueado.
    public static function issueLoginCsrf(): string
    {
        $token = bin2hex(random_bytes(16));
        setcookie(self::loginCsrfCookieName(), $token, [
            'expires'  => time() + 2 * 60 * 60, // 2 h: margen para rellenar el formulario
            'path'     => '/',                  // '/' es obligatorio con el prefijo __Host-
            'httponly' => true,
            'secure'   => self::secureCookie(),
            'samesite' => 'Lax',
        ]);
        return $token;
    }

    public static function checkLoginCsrf(?string $submitted): bool
    {
        $cookie = $_COOKIE[self::loginCsrfCookieName()] ?? null;
        return is_string($cookie) && $cookie !== ''
            && is_string($submitted)
            && hash_equals($cookie, $submitted);
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
     * Hash señuelo con el mismo coste PBKDF2 que los reales. loginPost lo
     * verifica cuando el usuario NO existe, para que el tiempo de respuesta sea
     * indistinguible del de un usuario existente y no se pueda enumerar usuarios
     * por temporización. Al verificarse siempre devuelve false (ninguna
     * contraseña real coincide con el «esperado» arbitrario).
     */
    public static function dummyHash(): string
    {
        $iters = (int) self::cfg('password_pbkdf2_iterations', 210000);
        // Salt y «esperado» fijos y arbitrarios: solo importa que verifyPassword
        // recorra la rama PBKDF2 (mismo coste) y luego falle el hash_equals.
        return "pbkdf2\$sha512\$$iters\$ZHVtbXlzYWx0X21kYw\$ZHVtbXlfbm90X2FfcmVhbF9oYXNo";
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

    /**
     * IP del cliente para el rate limit, NO falseable por el atacante.
     *
     * REMOTE_ADDR es la IP de la conexión TCP: el cliente no la elige. Se usa
     * tal cual salvo que sea de rango privado o loopback, lo que indica que hay
     * un proxy de confianza delante (p.ej. el nginx de Plesk en el mismo host);
     * solo entonces se toma la ÚLTIMA entrada de X-Forwarded-For, que es la que
     * añade ese proxy. La PRIMERA la escribe el cliente y era el agujero: se
     * podía rotar para tener intentos de login ilimitados (verificado en
     * producción, 2026-09-27). Por eso ya NO se confía en X-Forwarded-For ni en
     * X-Real-IP salvo tras un REMOTE_ADDR privado.
     */
    public static function clientIp(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $publica = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if ($publica === false && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $partes = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ultima = trim((string) end($partes));
            if (filter_var($ultima, FILTER_VALIDATE_IP) !== false) {
                return $ultima;
            }
        }
        return $remote !== '' ? $remote : 'unknown';
    }

    public static function rateKey(string $username): string
    {
        return self::clientIp() . ':' . strtolower(trim($username));
    }

    /**
     * Clave de rate limit por IP sola (sin usuario). Frena el "password
     * spraying" —una contraseña contra muchos usuarios desde la misma IP—, que
     * las claves ip:usuario no ven en conjunto porque cada usuario lleva su
     * propio contador. El sufijo vacío no colisiona con ninguna clave de usuario:
     * loginPost exige usuario no vacío antes de construir rateKey().
     */
    public static function rateKeyIp(): string
    {
        return self::clientIp() . ':';
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

    public static function rateFail(string $key, ?int $max = null): void
    {
        $now = self::nowMs();
        $windowMs = (int) self::cfg('login_window_ms', 15 * 60 * 1000);
        $lockMs = (int) self::cfg('login_lock_ms', 15 * 60 * 1000);
        $max = $max ?? (int) self::cfg('login_max_attempts', 6);

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
