<?php

declare(strict_types=1);

namespace App;

/**
 * Controladores del panel de administración (server-rendered).
 * Ports de app/login, app/dashboard/* y app/api/admin/* (formularios + PRG + CSRF).
 */
final class Admin
{
    private static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /** ¿Cambió el valor (con normalización, ignorando tipo)? */
    private static function changed(mixed $a, mixed $b): bool
    {
        $an = AdminRepo::normalize($a);
        $bn = AdminRepo::normalize($b);
        return ((string) ($an ?? "\x00")) !== ((string) ($bn ?? "\x00"));
    }

    /** @return list<int> IDs de autor enviados (dedup, positivos, ordenados). */
    private static function postAutoresIds(): array
    {
        $raw = $_POST['autoresIds'] ?? [];
        if (!is_array($raw)) $raw = [$raw];
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($v): int => (int) $v, $raw),
            static fn(int $n): bool => $n > 0
        )));
        sort($ids);
        return $ids;
    }

    private static function noticeFromQuery(): ?array
    {
        if (isset($_GET['saved'])) return ['type' => 'ok', 'msg' => 'Cambios guardados.'];
        if (isset($_GET['created'])) return ['type' => 'ok', 'msg' => 'Creado correctamente.'];
        if (isset($_GET['deleted'])) return ['type' => 'ok', 'msg' => 'Relación eliminada.'];
        if (isset($_GET['moved'])) return ['type' => 'ok', 'msg' => 'Variante reasignada.'];
        if (isset($_GET['split'])) return ['type' => 'ok', 'msg' => 'Variante separada en una nueva dedicatoria.'];
        if (isset($_GET['unified'])) return ['type' => 'ok', 'msg' => 'Variantes unificadas · ' . (int) $_GET['unified'] . ' marchas reescritas.'];
        if (isset($_GET['propuesta'])) {
            // Fuera de local hasta el admin propone (ver Admin::proposalMode), y
            // conviene decirle dónde acaba eso: la propuesta se revisa y se
            // aplica en local, que es la BD maestra.
            return ['type' => 'ok', 'msg' => Entorno::permiteEscrituraDirecta()
                ? 'Propuesta enviada. El administrador la revisará antes de aplicarla.'
                : 'Propuesta enviada. Se revisa y se aplica en el entorno local, que es el que manda sobre la base de datos.'];
        }
        if (isset($_GET['aplicada'])) return ['type' => 'ok', 'msg' => 'Propuesta aceptada y aplicada a la base de datos.'];
        if (isset($_GET['rechazada'])) return ['type' => 'info', 'msg' => 'Propuesta rechazada.'];
        if (isset($_GET['nochanges'])) return ['type' => 'info', 'msg' => 'No había cambios que guardar.'];
        if (isset($_GET['social'])) return ['type' => 'ok', 'msg' => 'Enlaces sociales actualizados.'];
        if (isset($_GET['err'])) return ['type' => 'error', 'msg' => 'Error: ' . preg_replace('/[^A-Z_]/', '', (string) $_GET['err'])];
        return null;
    }

    private static function isAdmin(array $session): bool
    {
        return Roles::isAdmin($session['rol'] ?? null);
    }

    /**
     * ¿Este envío se guarda como PROPUESTA en vez de escribir en la BD?
     *
     * Dos motivos independientes, y basta uno:
     *
     *   - el usuario es editor (siempre propone, en cualquier entorno);
     *   - el entorno no es local (PRE y PRO), y ahí no escribe NADIE en directo,
     *     tampoco el administrador: la BD maestra es la local y el .db remoto lo
     *     reemplaza entero scripts/sync_db_to_prod.php, así que una escritura
     *     hecha en PRE o PRO se perdería en el siguiente sync — o pisaría datos
     *     buenos. Encolarla como propuesta la conserva: el admin la baja con
     *     scripts/sync_propuestas_from_prod.php y la aplica en local, que es de
     *     donde sale el .db bueno. Ver docs/entornos.md.
     *
     * Solo cubre las entidades con propuesta (marcha, banda, autor). El resto de
     * pantallas del panel escriben directo y en PRE/PRO chocan con el fail-safe
     * de Db::assertWritable() (503 solo-lectura); por eso el layout avisa al
     * admin con la cinta de desincronización en todo el panel.
     */
    private static function proposalMode(array $session): bool
    {
        return !self::isAdmin($session) || !Entorno::permiteEscrituraDirecta();
    }

    /** Recoge del POST los campos de $editable presentes (para una propuesta). */
    private static function postDatos(array $editable): array
    {
        $d = [];
        foreach ($editable as $f) {
            if (array_key_exists($f, $_POST)) $d[$f] = $_POST[$f];
        }
        return $d;
    }

    /**
     * Encola una propuesta del editor (no toca la BD) y redirige al panel con
     * aviso de confirmación.
     *
     * @param array<string,mixed> $datos
     * @param list<int> $autoresIds
     */
    private static function enqueueProposal(array $session, string $entidad, string $accion, ?int $targetId, array $datos, array $autoresIds): never
    {
        PropuestaRepo::create($entidad, $accion, $targetId, $datos, $autoresIds, (string) ($session['user'] ?? ''));
        Http::redirect('/dashboard?propuesta=1', 302);
    }

    /**
     * Envío del editor en dos pasos: primero previsualiza (cómo quedará la
     * ficha), y solo al confirmar (accion=enviar) se crea la propuesta. Preserva
     * los datos entre pasos reenviándolos como campos ocultos en la confirmación.
     *
     * @param array<string,mixed> $datos
     * @param list<int> $autoresIds
     */
    private static function editorSubmit(array $session, string $entidad, string $accion, ?int $targetId, array $datos, array $autoresIds, string $formAction): never
    {
        if (($_POST['accion'] ?? '') === 'enviar') {
            self::enqueueProposal($session, $entidad, $accion, $targetId, $datos, $autoresIds); // redirige (never)
        }
        View::render('admin/propuesta_preview', [
            'session' => $session, 'entidad' => $entidad, 'accion' => $accion, 'targetId' => $targetId,
            'datos' => $datos, 'autoresIds' => array_values($autoresIds),
            'authors' => $entidad === 'marcha' ? Repo::autoresByIds($autoresIds) : [],
            'bandaNombre' => $entidad === 'marcha' ? self::bandaNombre($datos['BANDA_ESTRENO'] ?? null) : null,
            'formAction' => $formAction,
        ], ['title' => 'Previsualizar propuesta — Marchas de Cristo', 'noindex' => true]);
        exit;
    }

    // ── Login / logout ─────────────────────────────────────────────────────
    public static function loginForm(): void
    {
        Http::noStore();
        if (Auth::currentSession() !== null) Http::redirect('/dashboard', 302);
        View::render('admin/login', ['error' => null, 'username' => ''], ['title' => 'Acceso — Marchas de Cristo', 'noindex' => true]);
    }

    public static function loginPost(): void
    {
        Http::noStore();
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $fail = static function (string $msg, int $status) use ($username): void {
            http_response_code($status);
            View::render('admin/login', ['error' => $msg, 'username' => $username], ['title' => 'Acceso — Marchas de Cristo', 'noindex' => true]);
        };

        if ($username === '' || $password === '') { $fail('Introduce usuario y contraseña.', 400); return; }
        if (strlen($username) > 120 || strlen($password) > 512) { $fail('Credenciales no válidas.', 400); return; }

        $key = Auth::rateKey($username);
        $retry = Auth::rateRetryAfter($key);
        if ($retry > 0) { $fail("Demasiados intentos. Reinténtalo en {$retry}s.", 429); return; }

        $row = Db::one('SELECT usuario, clave FROM usuarios WHERE usuario = ? LIMIT 1', [trim($username)]);
        if ($row === null || !Auth::verifyPassword($password, (string) $row['clave'])) {
            Auth::rateFail($key);
            $fail('Usuario o contraseña incorrectos.', 401);
            return;
        }
        Auth::rateClear($key);

        if (Auth::isLegacyMd5((string) $row['clave'])) {
            Db::run('UPDATE usuarios SET clave = ? WHERE usuario = ?', [Auth::hashPassword($password), $row['usuario']]);
        }

        $ttl = (int) ($GLOBALS['config']['login_ttl_ms'] ?? 8 * 60 * 60 * 1000);
        $now = self::nowMs();
        $token = Auth::signSession([
            'user' => $row['usuario'],
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16)),
        ]);
        Auth::setSessionCookie($token);
        Http::redirect('/dashboard', 302);
    }

    public static function logout(): void
    {
        $session = Auth::currentSession();
        if ($session !== null && !Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) {
            Http::redirect('/dashboard', 302);
        }
        Auth::clearSessionCookie();
        Http::redirect('/login', 302);
    }

    // ── Dashboard ──────────────────────────────────────────────────────────
    public static function dashboard(): void
    {
        $session = Auth::requireAuth();
        $q = trim((string) ($_GET['q'] ?? ''));
        $qb = trim((string) ($_GET['qb'] ?? ''));
        $qd = trim((string) ($_GET['qd'] ?? ''));
        $marchas = [];
        $autores = [];
        $bandas = [];
        $discos = [];
        if ($q !== '') {
            $marchas = array_slice(Repo::searchMarchas('titulo=' . rawurlencode($q))['data'], 0, 15);
            $autores = array_slice(Repo::searchAutores('nombre=' . rawurlencode($q))['data'], 0, 15);
        }
        if ($qb !== '') {
            $bandas = array_slice(Repo::searchBandas('titulo=' . rawurlencode($qb))['data'], 0, 15);
        }
        // Los discos solo los edita el administrador (ver los controladores de
        // disco más abajo), así que ni se consultan para el rol editor.
        if ($qd !== '' && self::isAdmin($session)) {
            $discos = AdminRepo::discoCandidatosPorTexto($qd, 15);
        }
        $notice = self::noticeFromQuery();
        $pendientes = self::isAdmin($session) ? PropuestaRepo::countPendientes() : 0;
        View::render('admin/dashboard', compact('q', 'qb', 'qd', 'marchas', 'autores', 'bandas', 'discos', 'session', 'notice', 'pendientes'),
            ['title' => 'Panel de administración — Marchas de Cristo', 'noindex' => true]);
    }

    // ── Marcha: edición ──────────────────────────────────────────────────────
    public static function marchaEditForm(array $p): void
    {
        $session = Auth::requireCap('marcha.edit');
        $id = (string) $p['id'];
        $marcha = Repo::fetchMarchaRaw($id);
        if ($marcha === null) Http::notFound();
        // Enriquecer con nombre de banda para mostrarlo en el autocomplete del formulario.
        if (!empty($marcha['BANDA_ESTRENO'])) {
            $banda = Db::one('SELECT NOMBRE_BREVE FROM banda WHERE ID_BANDA = ?', [$marcha['BANDA_ESTRENO']]);
            $marcha['BANDA_NOMBRE'] = $banda !== null ? (string) $banda['NOMBRE_BREVE'] : '';
        }
        View::render('admin/marcha_form', [
            'mode' => 'edit', 'session' => $session, 'action' => "/dashboard/marcha/$id",
            'marcha' => $marcha, 'authors' => Repo::currentAutoresForMarcha($id),
            'proposalMode' => self::proposalMode($session),
            'notice' => self::noticeFromQuery(), 'error' => null,
        ], ['title' => "Editar marcha #$id — Marchas de Cristo", 'noindex' => true]);
    }

    public static function marchaEditPost(array $p): void
    {
        $session = Auth::requireCap('marcha.edit');
        $id = (string) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/marcha/$id?err=CSRF", 302);

        $current = Repo::fetchMarchaRaw($id);
        if ($current === null) Http::notFound();

        // Propuesta (editor siempre; cualquiera fuera de local): no escribe en la
        // BD, encola los campos del form. Ver self::proposalMode().
        if (self::proposalMode($session)) {
            $ids = self::postAutoresIds();
            if ($ids === []) Http::redirect("/dashboard/marcha/$id?err=AUTHORS_REQUIRED", 302);
            self::editorSubmit($session, 'marcha', 'edit', (int) $id, self::postDatos(AdminRepo::EDITABLE_MARCHA), $ids, "/dashboard/marcha/$id");
        }

        // Delta de campos editables.
        $keys = [];
        $values = [];
        foreach (AdminRepo::EDITABLE_MARCHA as $f) {
            $sub = $_POST[$f] ?? null;
            if (self::changed($current[$f] ?? null, $sub)) {
                $keys[] = $f;
                $values[] = AdminRepo::normalize($sub);
            }
        }
        // Delta de autores.
        $curIds = array_map('intval', array_column(Repo::currentAutoresForMarcha($id), 'ID_AUTOR'));
        sort($curIds);
        $subIds = self::postAutoresIds();
        $authorChanged = $curIds !== $subIds;

        if ($keys === [] && !$authorChanged) Http::redirect("/dashboard/marcha/$id?nochanges=1", 302);

        if ($keys !== []) {
            $r = AdminRepo::editMarcha((int) $id, $keys, $values);
            if ($r['code'] === 'NOT_FOUND') Http::notFound();
            if ($r['code'] !== 'UPDATED') Http::redirect("/dashboard/marcha/$id?err=" . $r['code'], 302);
        }
        if ($authorChanged) {
            if ($subIds === []) Http::redirect("/dashboard/marcha/$id?err=AUTHORS_REQUIRED", 302);
            $r = AdminRepo::editMarchaAutores((int) $id, $subIds);
            if ($r['code'] !== 'UPDATED') Http::redirect("/dashboard/marcha/$id?err=" . $r['code'], 302);
        }
        Http::redirect("/dashboard/marcha/$id?saved=1", 302);
    }

    // ── Marcha: alta ─────────────────────────────────────────────────────────
    public static function marchaAddForm(): void
    {
        $session = Auth::requireCap('marcha.add');
        View::render('admin/marcha_form', [
            'mode' => 'add', 'session' => $session, 'action' => '/dashboard/marcha/add',
            'marcha' => self::marchaPrefillDesdeQuery(), 'authors' => [],
            'proposalMode' => self::proposalMode($session),
            'volver' => self::volverSeguro($_GET['volver'] ?? null),
            'notice' => null, 'error' => null,
        ], ['title' => 'Añadir marcha — Marchas de Cristo', 'noindex' => true]);
    }

    /**
     * Valores con los que llega precargado el alta de marcha cuando se entra
     * desde otra pantalla del panel (hoy: una pista sin reconocer del
     * importador de discos). Es solo un borrador: se validan igual al enviar,
     * y el usuario los ve y los corrige antes de nada.
     *
     * @return array<string,mixed>
     */
    private static function marchaPrefillDesdeQuery(): array
    {
        $out = [];
        foreach (['TITULO', 'FECHA', 'TIPO', 'DEDICATORIA', 'LOCALIDAD', 'PROVINCIA', 'ESTILO'] as $campo) {
            $v = trim((string) ($_GET[$campo] ?? ''));
            if ($v !== '') $out[$campo] = $v;
        }
        $banda = (int) ($_GET['BANDA_ESTRENO'] ?? 0);
        if ($banda > 0) {
            $fila = Db::one('SELECT NOMBRE_BREVE FROM banda WHERE ID_BANDA = ?', [$banda]);
            if ($fila !== null) {
                $out['BANDA_ESTRENO'] = $banda;
                $out['BANDA_NOMBRE'] = (string) $fila['NOMBRE_BREVE'];
            }
        }
        return $out;
    }

    /**
     * Ruta de vuelta tras crear algo desde otra pantalla del panel. Solo se
     * admiten rutas internas del propio panel: un valor arbitrario aquí sería
     * un redirect abierto a un dominio de fuera.
     */
    private static function volverSeguro(mixed $raw): ?string
    {
        $v = trim((string) ($raw ?? ''));
        if ($v === '' || !str_starts_with($v, '/dashboard/')) return null;
        if (str_contains($v, "\n") || str_contains($v, "\r")) return null;
        return $v;
    }

    public static function marchaAddPost(): void
    {
        $session = Auth::requireCap('marcha.add');
        $fields = [];
        foreach (AdminRepo::INSERTABLE_MARCHA as $f) $fields[$f] = $_POST[$f] ?? '';
        $ids = self::postAutoresIds();

        $volver = self::volverSeguro($_POST['volver'] ?? null);
        $reRender = static function (string $err) use ($session, $fields, $ids, $volver): void {
            http_response_code(400);
            View::render('admin/marcha_form', [
                'mode' => 'add', 'session' => $session, 'action' => '/dashboard/marcha/add',
                'marcha' => $fields, 'authors' => Repo::autoresByIds($ids),
                'proposalMode' => self::proposalMode($session), 'volver' => $volver,
                'notice' => null, 'error' => $err,
            ], ['title' => 'Añadir marcha — Marchas de Cristo', 'noindex' => true]);
        };

        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) { $reRender('CSRF'); return; }

        // Propuesta en lugar de escritura directa: editor siempre, y cualquier
        // usuario fuera de local. Ver self::proposalMode().
        if (self::proposalMode($session)) {
            if ($ids === []) { $reRender('AUTHORS_REQUIRED'); return; }
            self::editorSubmit($session, 'marcha', 'add', null, $fields, $ids, '/dashboard/marcha/add');
        }

        $r = AdminRepo::addMarcha($fields, $ids);
        if (($r['code'] ?? '') === 'CREATED') {
            // Si se venía de otra pantalla (importador de pistas), se vuelve
            // allí en vez de a la ficha nueva: la tarea de origen sigue a medias.
            $destino = $volver !== null
                ? $volver . (str_contains($volver, '?') ? '&' : '?') . 'nueva=' . (int) $r['marchaId']
                : '/dashboard/marcha/' . $r['marchaId'] . '?created=1';
            Http::redirect($destino, 302);
        }
        $reRender($r['code'] ?? 'ERROR');
    }

    // ── Marcha: curación de estilo (CCTT / AM) ──────────────────────────────
    public static function estiloList(): void
    {
        $session = Auth::requireAdmin();
        $filters = [
            'estado' => (string) ($_GET['estado'] ?? 'pendiente'),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = Repo::marchasEstiloAdmin($filters, $page);
        $backParams = array_filter($filters, static fn(string $v): bool => $v !== '');
        if ($page > 1) $backParams['page'] = $page;
        View::render('admin/estilo_list', [
            'session' => $session, 'filters' => $filters, 'page' => $page,
            'result' => $result, 'counts' => Repo::marchaEstiloCounts(), 'backQs' => http_build_query($backParams),
            'notice' => self::noticeFromQuery(),
        ], ['title' => 'Estilo de marcha (CCTT/AM) — Marchas de Cristo', 'noindex' => true]);
    }

    /** Reconstruye de forma segura la query de filtros de /dashboard/estilos a partir de un string arbitrario. */
    private static function estiloBackQuery(string $raw): string
    {
        parse_str($raw, $parsed);
        $allowed = array_intersect_key($parsed, array_flip(['estado', 'q', 'page']));
        return http_build_query($allowed);
    }

    public static function estiloAssignPost(): void
    {
        $session = Auth::requireAdmin();
        $back = self::estiloBackQuery((string) ($_POST['ref'] ?? ''));
        $sep = $back !== '' ? '&' : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/estilos?$back{$sep}err=CSRF", 302);

        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $estilo = (string) ($_POST['estilo'] ?? '');
        $r = AdminRepo::assignEstiloVarios($ids, $estilo);
        if (($r['code'] ?? '') !== 'ASSIGNED') Http::redirect("/dashboard/estilos?$back{$sep}err=" . ($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/estilos?$back{$sep}asignadas=" . $r['count'], 302);
    }

    // ── Autor: edición ───────────────────────────────────────────────────────
    public static function autorEditForm(array $p): void
    {
        $session = Auth::requireCap('autor.edit');
        $id = (string) $p['id'];
        $autor = Repo::fetchAutorRaw($id);
        if ($autor === null) Http::notFound();
        View::render('admin/autor_form', [
            'mode' => 'edit', 'session' => $session, 'action' => "/dashboard/autor/$id",
            'autor' => $autor, 'proposalMode' => self::proposalMode($session),
            'notice' => self::noticeFromQuery(), 'error' => null,
        ], ['title' => "Editar compositor #$id — Marchas de Cristo", 'noindex' => true]);
    }

    public static function autorEditPost(array $p): void
    {
        $session = Auth::requireCap('autor.edit');
        $id = (string) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/autor/$id?err=CSRF", 302);

        $current = Repo::fetchAutorRaw($id);
        if ($current === null) Http::notFound();

        // Propuesta en lugar de escritura directa: editor siempre, y cualquier
        // usuario fuera de local. Ver self::proposalMode().
        if (self::proposalMode($session)) {
            self::editorSubmit($session, 'autor', 'edit', (int) $id, self::postDatos(AdminRepo::EDITABLE_AUTOR), [], "/dashboard/autor/$id");
        }

        $keys = [];
        $values = [];
        foreach (AdminRepo::EDITABLE_AUTOR as $f) {
            $sub = $_POST[$f] ?? null;
            if (self::changed($current[$f] ?? null, $sub)) {
                $keys[] = $f;
                $values[] = AdminRepo::normalize($sub);
            }
        }
        if ($keys === []) Http::redirect("/dashboard/autor/$id?nochanges=1", 302);

        $r = AdminRepo::editAutor((int) $id, $keys, $values);
        if ($r['code'] !== 'UPDATED') Http::redirect("/dashboard/autor/$id?err=" . $r['code'], 302);
        Http::redirect("/dashboard/autor/$id?saved=1", 302);
    }

    // ── Autor: alta ──────────────────────────────────────────────────────────
    public static function autorAddForm(): void
    {
        $session = Auth::requireCap('autor.add');
        // Prefill opcional desde ?nombre=Nombre Apellidos (p.ej. enlace "crear
        // compositor" desde la revisión de ingesta): el último token se asume
        // apellidos, el resto nombre. Es solo un punto de partida editable.
        $prefill = trim((string) ($_GET['nombre'] ?? ''));
        $autor = [];
        if ($prefill !== '') {
            $parts = preg_split('/\s+/', $prefill) ?: [];
            $autor['APELLIDOS'] = array_pop($parts);
            $autor['NOMBRE'] = implode(' ', $parts);
        }
        View::render('admin/autor_form', [
            'mode' => 'add', 'session' => $session, 'action' => '/dashboard/autor/add',
            'autor' => $autor, 'proposalMode' => self::proposalMode($session),
            'notice' => null, 'error' => null,
        ], ['title' => 'Añadir compositor — Marchas de Cristo', 'noindex' => true]);
    }

    public static function autorAddPost(): void
    {
        $session = Auth::requireCap('autor.add');
        $fields = [];
        foreach (AdminRepo::EDITABLE_AUTOR as $f) $fields[$f] = $_POST[$f] ?? '';

        $reRender = static function (string $err) use ($session, $fields): void {
            http_response_code(400);
            View::render('admin/autor_form', [
                'mode' => 'add', 'session' => $session, 'action' => '/dashboard/autor/add',
                'autor' => $fields, 'proposalMode' => self::proposalMode($session), 'notice' => null, 'error' => $err,
            ], ['title' => 'Añadir compositor — Marchas de Cristo', 'noindex' => true]);
        };

        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) { $reRender('CSRF'); return; }

        // Propuesta en lugar de escritura directa: editor siempre, y cualquier
        // usuario fuera de local. Ver self::proposalMode().
        if (self::proposalMode($session)) {
            self::editorSubmit($session, 'autor', 'add', null, $fields, [], '/dashboard/autor/add');
        }

        $r = AdminRepo::addAutor($fields);
        if (($r['code'] ?? '') === 'CREATED') Http::redirect('/dashboard/autor/' . $r['autorId'] . '?created=1', 302);
        $reRender($r['code'] ?? 'ERROR');
    }

    // ── Banda: edición + relaciones de linaje (banda_relacion) ───────────────
    public static function bandaEditForm(array $p): void
    {
        $session = Auth::requireCap('banda.edit');
        $id = (string) $p['id'];
        $banda = Repo::fetchBandaRaw($id);
        if ($banda === null) Http::notFound();
        $showLinaje = self::isAdmin($session); // el linaje es curación avanzada, solo admin
        View::render('admin/banda_form', [
            'session' => $session, 'banda' => $banda, 'action' => "/dashboard/banda/$id",
            'relaciones' => $showLinaje ? Repo::bandaRelaciones($id) : [],
            'tipos' => AdminRepo::RELACION_TIPOS,
            'showLinaje' => $showLinaje, 'proposalMode' => self::proposalMode($session),
            'enlaces' => $showLinaje ? EnlaceRepo::publicadosDe('banda', (int) $id) : [],
            'notice' => self::noticeFromQuery(), 'error' => null,
        ], ['title' => "Editar banda #$id — Marchas de Cristo", 'noindex' => true]);
    }

    public static function bandaEditPost(array $p): void
    {
        $session = Auth::requireCap('banda.edit');
        $id = (string) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/banda/$id?err=CSRF", 302);

        $current = Repo::fetchBandaRaw($id);
        if ($current === null) Http::notFound();

        // Propuesta en lugar de escritura directa (solo campos básicos): editor
        // siempre, y cualquier usuario fuera de local. Ver self::proposalMode().
        if (self::proposalMode($session)) {
            self::editorSubmit($session, 'banda', 'edit', (int) $id, self::postDatos(AdminRepo::EDITABLE_BANDA), [], "/dashboard/banda/$id");
        }

        $keys = [];
        $values = [];
        foreach (AdminRepo::EDITABLE_BANDA as $f) {
            $sub = $_POST[$f] ?? null;
            if (self::changed($current[$f] ?? null, $sub)) {
                $keys[] = $f;
                $values[] = AdminRepo::normalize($sub);
            }
        }
        if ($keys === []) Http::redirect("/dashboard/banda/$id?nochanges=1", 302);

        $r = AdminRepo::editBanda((int) $id, $keys, $values);
        if ($r['code'] !== 'UPDATED') Http::redirect("/dashboard/banda/$id?err=" . $r['code'], 302);
        Http::redirect("/dashboard/banda/$id?saved=1", 302);
    }

    // ── Banda: alta ──────────────────────────────────────────────────────────
    public static function bandaAddForm(): void
    {
        $session = Auth::requireCap('banda.add');
        View::render('admin/banda_add', [
            'session' => $session, 'banda' => [], 'action' => '/dashboard/banda/add',
            'proposalMode' => self::proposalMode($session), 'notice' => null, 'error' => null,
        ], ['title' => 'Añadir banda — Marchas de Cristo', 'noindex' => true]);
    }

    public static function bandaAddPost(): void
    {
        $session = Auth::requireCap('banda.add');
        $fields = [];
        foreach (AdminRepo::EDITABLE_BANDA as $f) $fields[$f] = $_POST[$f] ?? '';

        $reRender = static function (string $err) use ($session, $fields): void {
            http_response_code(400);
            View::render('admin/banda_add', [
                'session' => $session, 'banda' => $fields, 'action' => '/dashboard/banda/add',
                'proposalMode' => self::proposalMode($session), 'notice' => null, 'error' => $err,
            ], ['title' => 'Añadir banda — Marchas de Cristo', 'noindex' => true]);
        };

        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) { $reRender('CSRF'); return; }

        // Propuesta en lugar de escritura directa: editor siempre, y cualquier
        // usuario fuera de local. Ver self::proposalMode().
        if (self::proposalMode($session)) {
            if (trim((string) ($fields['NOMBRE_BREVE'] ?? '')) === '') { $reRender('NOMBRE_REQUERIDO'); return; }
            self::editorSubmit($session, 'banda', 'add', null, $fields, [], '/dashboard/banda/add');
        }

        $r = AdminRepo::addBanda($fields);
        if (($r['code'] ?? '') === 'CREATED') Http::redirect('/dashboard/banda/' . $r['bandaId'] . '?created=1', 302);
        $reRender($r['code'] ?? 'ERROR');
    }

    public static function bandaRelacionAddPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/banda/$id?err=CSRF", 302);

        $otra = (int) ($_POST['otraBanda'] ?? 0);
        // direccion=saliente → esta banda es ORIGEN (→ otra); entrante → esta es DESTINO.
        $entrante = ($_POST['direccion'] ?? 'saliente') === 'entrante';
        [$origen, $destino] = $entrante ? [$otra, $id] : [$id, $otra];

        $str = static fn(string $k): ?string => is_string($_POST[$k] ?? null) ? (string) $_POST[$k] : null;
        $r = AdminRepo::addRelacion($origen, $destino, (string) ($_POST['tipo'] ?? ''), $str('fecha_inicio'), $str('fecha_fin'), $str('nota'));
        if (($r['code'] ?? '') === 'CREATED') Http::redirect("/dashboard/banda/$id?created=1", 302);
        Http::redirect("/dashboard/banda/$id?err=" . ($r['code'] ?? 'ERROR'), 302);
    }

    public static function bandaRelacionDeletePost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $rel = (int) $p['rel'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/banda/$id?err=CSRF", 302);
        $r = AdminRepo::deleteRelacion($rel);
        if (($r['code'] ?? '') === 'DELETED') Http::redirect("/dashboard/banda/$id?deleted=1", 302);
        Http::redirect("/dashboard/banda/$id?err=" . ($r['code'] ?? 'ERROR'), 302);
    }

    // ── Temporada / contratos (N-04/N-05): alta manual ──────────────────────
    public static function temporadaAdmin(array $p): void
    {
        $session = Auth::requireAdmin();
        $anio = (string) $p['anio'];
        if (preg_match('/^\d{4}$/', $anio) !== 1) Http::notFound();

        // Igual que Pages::temporada(): la tabla `contrato` puede no estar
        // migrada aún en este host (mecanismo manual, como P-07). Sin este
        // fallback el panel da un 500 crudo en vez de avisar de qué falta.
        $notice = self::noticeFromQuery();
        try {
            $contratos = Repo::temporada($anio);
        } catch (\Throwable $e) {
            error_log('[dashboard/temporada] ' . $e->getMessage());
            $contratos = [];
            $notice = ['type' => 'error', 'msg' => 'La tabla contrato no existe todavía en este host — falta aplicar la migración 005_contrato.sql (migrate_ingest.php vía Plesk, con PHP 8.4 seleccionado).'];
        }

        View::render('admin/temporada', [
            'session' => $session, 'anio' => $anio,
            'contratos' => $contratos,
            'notice' => $notice,
        ], ['title' => "Temporada $anio — Marchas de Cristo", 'noindex' => true]);
    }

    public static function temporadaAddPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $anio = (string) $p['anio'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/temporada/$anio?err=CSRF", 302);

        $idBanda = (int) ($_POST['ID_BANDA'] ?? 0);
        $hermandad = (string) ($_POST['HERMANDAD'] ?? '');
        $titular = is_string($_POST['TITULAR'] ?? null) ? (string) $_POST['TITULAR'] : null;
        $fuente = is_string($_POST['FUENTE'] ?? null) ? (string) $_POST['FUENTE'] : null;
        $nota = is_string($_POST['NOTA'] ?? null) ? (string) $_POST['NOTA'] : null;

        try {
            $r = AdminRepo::addContrato($idBanda, $hermandad, $anio, $titular, $fuente, $nota);
        } catch (\Throwable $e) {
            error_log('[dashboard/temporada/add] ' . $e->getMessage());
            Http::redirect("/dashboard/temporada/$anio?err=TABLA_NO_MIGRADA", 302);
        }
        if (($r['code'] ?? '') === 'CREATED') Http::redirect("/dashboard/temporada/$anio?created=1", 302);
        Http::redirect("/dashboard/temporada/$anio?err=" . ($r['code'] ?? 'ERROR'), 302);
    }

    public static function temporadaDeletePost(array $p): void
    {
        $session = Auth::requireAdmin();
        $anio = (string) $p['anio'];
        $contrato = (int) $p['contrato'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/temporada/$anio?err=CSRF", 302);
        $r = AdminRepo::deleteContrato($contrato);
        if (($r['code'] ?? '') === 'DELETED') Http::redirect("/dashboard/temporada/$anio?deleted=1", 302);
        Http::redirect("/dashboard/temporada/$anio?err=" . ($r['code'] ?? 'ERROR'), 302);
    }

    /** Alta/edición/baja manual de los enlaces de streaming/RRSS musicales de una banda (pestaña Social). */
    public static function bandaSocialPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/banda/$id?err=CSRF", 302);

        foreach (EnlaceRepo::SERVICIOS as $servicio) {
            $url = $_POST[$servicio] ?? null;
            $r = AdminRepo::setEnlaceStreaming('banda', $id, $servicio, is_string($url) ? $url : null);
            if (($r['code'] ?? '') === 'BAD_REQUEST') Http::redirect("/dashboard/banda/$id?err=BAD_REQUEST", 302);
        }
        Http::redirect("/dashboard/banda/$id?social=1", 302);
    }

    // ── Autocomplete de bandas (JSON, para el selector de relaciones) ────────
    public static function bandaFastSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (Auth::currentSession() === null) {
            http_response_code(401);
            echo json_encode(['code' => 'AUTH_REQUIRED', 'data' => []]);
            return;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 3) { echo json_encode(['rowsReturned' => 0, 'data' => []]); return; }
        $data = Repo::bandaCandidatosPorTexto($q, 15);
        echo json_encode(['rowsReturned' => count($data), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Devuelve el estilo más frecuente (CCTT o AM) entre las marchas existentes de una banda. */
    public static function bandaEstiloSugerido(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (Auth::currentSession() === null) {
            http_response_code(401);
            echo json_encode(['code' => 'AUTH_REQUIRED', 'estilo' => null]);
            return;
        }
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['estilo' => null]); return; }
        $row = Db::one(
            "SELECT ESTILO FROM marcha WHERE BANDA_ESTRENO = ? AND ESTILO IN ('CCTT','AM')
             GROUP BY ESTILO ORDER BY COUNT(*) DESC LIMIT 1",
            [$id]
        );
        echo json_encode(['estilo' => $row !== null ? (string) $row['ESTILO'] : null]);
    }

    // ── Dedicatorias: curación de advocaciones (hubs N-01 / N-02) ────────────
    public static function dedicatoriasList(): void
    {
        $session = Auth::requireAdmin();
        $q = trim((string) ($_GET['q'] ?? ''));
        $soloPersonales = isset($_GET['personales']);
        View::render('admin/dedicatoria_list', [
            'session' => $session, 'q' => $q, 'soloPersonales' => $soloPersonales,
            'items' => Repo::dedicatoriasAdmin($q === '' ? null : $q, 300, $soloPersonales),
            'notice' => self::noticeFromQuery(),
        ], ['title' => 'Dedicatorias — curación · Marchas de Cristo', 'noindex' => true]);
    }

    public static function dedicatoriaEditForm(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (string) $p['id'];
        $dedic = Repo::fetchDedicatoriaAdmin($id);
        if ($dedic === null) Http::notFound();
        View::render('admin/dedicatoria_form', [
            'session' => $session, 'dedic' => $dedic,
            'notice' => self::noticeFromQuery(), 'error' => null,
        ], ['title' => 'Editar dedicatoria #' . $id . ' — Marchas de Cristo', 'noindex' => true]);
    }

    public static function dedicatoriaEditPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/dedicatoria/$id?err=CSRF", 302);
        $r = AdminRepo::renameDedicatoria(
            $id,
            (string) ($_POST['NOMBRE'] ?? ''),
            (string) ($_POST['LOCALIDAD'] ?? ''),
            is_string($_POST['PROVINCIA'] ?? null) ? (string) $_POST['PROVINCIA'] : null,
            isset($_POST['PERSONAL'])
        );
        if (($r['code'] ?? '') !== 'UPDATED') Http::redirect("/dashboard/dedicatoria/$id?err=" . ($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/dedicatoria/$id?saved=1", 302);
    }

    public static function dedicatoriaAliasMovePost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/dedicatoria/$id?err=CSRF", 302);
        $r = AdminRepo::moverAlias(
            (string) ($_POST['variante'] ?? ''),
            (string) ($_POST['localidad'] ?? ''),
            (int) ($_POST['destino'] ?? 0)
        );
        if (($r['code'] ?? '') !== 'MOVED') Http::redirect("/dashboard/dedicatoria/$id?err=" . ($r['code'] ?? 'ERROR'), 302);
        // Si el origen quedó vacío y se eliminó, no hay ficha a la que volver.
        if (Repo::fetchDedicatoriaAdmin((string) $id) === null) Http::redirect('/dashboard/dedicatorias?moved=1', 302);
        Http::redirect("/dashboard/dedicatoria/$id?moved=1", 302);
    }

    public static function dedicatoriaAliasSplitPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/dedicatoria/$id?err=CSRF", 302);
        $r = AdminRepo::separarAlias((string) ($_POST['variante'] ?? ''), (string) ($_POST['localidad'] ?? ''));
        if (($r['code'] ?? '') !== 'SPLIT') Http::redirect("/dashboard/dedicatoria/$id?err=" . ($r['code'] ?? 'ERROR'), 302);
        // Ir a la canónica recién creada para renombrarla.
        Http::redirect('/dashboard/dedicatoria/' . $r['idDedic'] . '?split=1', 302);
    }

    public static function dedicatoriaUnifyPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/dedicatoria/$id?err=CSRF", 302);
        $dedic = Repo::fetchDedicatoriaAdmin((string) $id);
        if ($dedic === null) Http::notFound();
        // El objetivo se pasa como índice en la lista renderizada (misma ordenación);
        // así evitamos codificar el par (variante, localidad) en el value del <select>.
        $idx = (int) ($_POST['objetivo'] ?? -1);
        if (!isset($dedic['variantes'][$idx])) Http::redirect("/dashboard/dedicatoria/$id?err=OBJETIVO_INVALIDO", 302);
        $v = $dedic['variantes'][$idx];
        $r = AdminRepo::unificarVariantes($id, (string) $v['VARIANTE'], (string) $v['LOCALIDAD']);
        if (($r['code'] ?? '') !== 'UNIFIED') Http::redirect("/dashboard/dedicatoria/$id?err=" . ($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/dedicatoria/$id?unified=" . $r['marchas'], 302);
    }

    // ── Ingesta (revisión de candidatos de YouTube) ─────────────────────────
    public static function ingestaList(): void
    {
        $session = Auth::requireAdmin();
        $filters = [
            'estado' => (string) ($_GET['estado'] ?? 'pendiente'),
            'banda' => (string) ($_GET['banda'] ?? ''),
            'clasificacion' => (string) ($_GET['clasificacion'] ?? ''),
            'disco' => (string) ($_GET['disco'] ?? ''),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = IngestaRepo::listCandidatos($filters, $page);
        $backParams = array_filter($filters, static fn(string $v): bool => $v !== '');
        if ($page > 1) $backParams['page'] = $page;
        View::render('admin/ingesta_list', [
            'session' => $session, 'filters' => $filters, 'page' => $page,
            'result' => $result, 'bandas' => IngestaRepo::bandasConCandidatos($filters['estado']),
            'discos' => IngestaRepo::discosConCandidatos($filters['estado'], $filters['banda']),
            'counts' => IngestaRepo::counts(), 'backQs' => http_build_query($backParams),
            'ultimoDescarte' => IngestaRepo::ultimoDescarte(),
            'vetos' => IngestaRepo::vetosDe($result['data']),
        ], ['title' => 'Ingesta de marchas — Marchas de Cristo', 'noindex' => true]);
    }

    /** Reconstruye de forma segura la query de filtros de /dashboard/ingesta a partir de un string arbitrario
     *  (viene de ?ref= al entrar al detalle, o de un campo oculto "ref" al volver de aceptar/descartar). */
    private static function ingestaBackQuery(string $raw): string
    {
        parse_str($raw, $parsed);
        $allowed = array_intersect_key($parsed, array_flip(['estado', 'banda', 'clasificacion', 'disco', 'page']));
        return http_build_query($allowed);
    }

    public static function ingestaDetail(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $cand = IngestaRepo::fetchCandidato($id);
        if ($cand === null) Http::notFound();
        $back = self::ingestaBackQuery((string) ($_GET['ref'] ?? ''));

        $autoresNombres = array_filter(array_map('trim', explode(',', (string) ($cand['P_AUTORES'] ?? ''))));
        $autoresAuto = [];
        $autoresSugeridos = [];
        foreach ($autoresNombres as $nombre) {
            $match = Repo::mejorAutorPorNombre($nombre);
            if ($match !== null && $match['score'] >= 0.8) {
                $autoresAuto[] = $match;
            } else {
                $autoresSugeridos[] = $nombre;
            }
        }

        // Estilo: manda el que propuso el descubridor (P_ESTILO) si viene; si
        // no, se deduce del resto de marchas de la banda, como hasta ahora.
        $bandaId = (int) ($cand['P_BANDA_ESTRENO'] ?? $cand['ID_BANDA'] ?? 0);
        $estiloSugerido = in_array($cand['P_ESTILO'] ?? null, ['CCTT', 'AM'], true) ? (string) $cand['P_ESTILO'] : null;
        if ($estiloSugerido === null && $bandaId > 0) {
            $eRow = Db::one(
                "SELECT ESTILO FROM marcha WHERE BANDA_ESTRENO = ? AND ESTILO IN ('CCTT','AM')
                 GROUP BY ESTILO ORDER BY COUNT(*) DESC LIMIT 1",
                [$bandaId]
            );
            $estiloSugerido = $eRow !== null ? (string) $eRow['ESTILO'] : null;
        }

        View::render('admin/ingesta_detail', [
            'session' => $session, 'cand' => $cand, 'back' => $back,
            'autoresAuto' => $autoresAuto, 'autoresSugeridos' => $autoresSugeridos,
            'estiloSugerido' => $estiloSugerido,
            'notice' => self::noticeFromQuery(), 'error' => null,
        ], ['title' => 'Revisar candidato #' . $id . ' — Marchas de Cristo', 'noindex' => true]);
    }

    public static function ingestaAceptar(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $back = self::ingestaBackQuery((string) ($_POST['ref'] ?? ''));
        $backSuffix = $back !== '' ? "&ref=$back" : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/ingesta/$id?err=CSRF$backSuffix", 302);

        $fields = [];
        foreach (AdminRepo::INSERTABLE_MARCHA as $f) $fields[$f] = $_POST[$f] ?? '';
        $ids = self::postAutoresIds();
        $guardarOrigen = isset($_POST['guardar_origen']);

        if ($ids === []) Http::redirect("/dashboard/ingesta/$id?err=AUTHORS_REQUIRED$backSuffix", 302);

        $r = AdminRepo::aceptarCandidato($id, $fields, $ids, $guardarOrigen);
        if (($r['code'] ?? '') === 'CREATED') {
            $sep = $back !== '' ? '&' : '';
            Http::redirect("/dashboard/ingesta?$back{$sep}aceptado=" . $r['marchaId'], 302);
        }
        Http::redirect("/dashboard/ingesta/$id?err=" . ($r['code'] ?? 'ERROR') . $backSuffix, 302);
    }

    public static function ingestaAsociar(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $back = self::ingestaBackQuery((string) ($_POST['ref'] ?? ''));
        $backSuffix = $back !== '' ? "&ref=$back" : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/ingesta/$id?err=CSRF$backSuffix", 302);

        $marchaId = (int) ($_POST['marcha_id'] ?? 0);
        if ($marchaId <= 0) Http::redirect("/dashboard/ingesta/$id?err=MARCHA_REQUIRED$backSuffix", 302);

        $guardarOrigen = isset($_POST['guardar_origen']);
        $r = AdminRepo::asociarCandidato($id, $marchaId, $guardarOrigen);
        if (($r['code'] ?? '') === 'ASSOCIATED') {
            $sep = $back !== '' ? '&' : '';
            Http::redirect("/dashboard/ingesta?$back{$sep}aceptado=" . $r['marchaId'], 302);
        }
        Http::redirect("/dashboard/ingesta/$id?err=" . ($r['code'] ?? 'ERROR') . $backSuffix, 302);
    }

    public static function ingestaDescartar(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $back = self::ingestaBackQuery((string) ($_POST['ref'] ?? ''));
        $backSuffix = $back !== '' ? "&ref=$back" : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/ingesta/$id?err=CSRF$backSuffix", 302);

        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        $r = AdminRepo::descartarCandidato($id, $motivo !== '' ? $motivo : null);
        if (($r['code'] ?? '') !== 'DISCARDED') Http::redirect("/dashboard/ingesta/$id?err=" . ($r['code'] ?? 'ERROR') . $backSuffix, 302);
        $sep = $back !== '' ? '&' : '';
        Http::redirect("/dashboard/ingesta?$back{$sep}descartado=1", 302);
    }

    /** Descarte masivo desde el listado (checkboxes + modal de confirmación), sin motivo. */
    public static function ingestaDescartarMultiple(): void
    {
        $session = Auth::requireAdmin();
        $back = self::ingestaBackQuery((string) ($_POST['ref'] ?? ''));
        $sep = $back !== '' ? '&' : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/ingesta?$back{$sep}err=CSRF", 302);

        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $r = AdminRepo::descartarVarios($ids);
        if (($r['code'] ?? '') !== 'DISCARDED') Http::redirect("/dashboard/ingesta?$back{$sep}err=" . ($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/ingesta?$back{$sep}descartados=" . $r['count'], 302);
    }

    /**
     * Deshace el último descarte (uno solo o el lote entero del descarte
     * masivo): los candidatos vuelven a "pendiente" y se levanta su veto.
     * Un solo paso — al deshacerlo, el botón desaparece del listado.
     */
    public static function ingestaDeshacerDescarte(): void
    {
        $session = Auth::requireAdmin();
        $back = self::ingestaBackQuery((string) ($_POST['ref'] ?? ''));
        $sep = $back !== '' ? '&' : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/ingesta?$back{$sep}err=CSRF", 302);

        $r = AdminRepo::deshacerUltimoDescarte();
        if (($r['code'] ?? '') !== 'UNDONE') Http::redirect("/dashboard/ingesta?$back{$sep}err=" . ($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/ingesta?$back{$sep}recuperados=" . $r['count'], 302);
    }

    // ── Enlaces de streaming: curación (Spotify / Apple / Deezer) ────────────
    public static function enlaceList(): void
    {
        $session = Auth::requireAdmin();
        $filters = [
            'estado' => (string) ($_GET['estado'] ?? 'pendiente'),
            'servicio' => (string) ($_GET['servicio'] ?? ''),
            'confianza' => (string) ($_GET['confianza'] ?? ''),
            'banda' => (string) ($_GET['banda'] ?? ''),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = EnlaceRepo::listCandidatos($filters, $page);
        $backParams = array_filter($filters, static fn(string $v): bool => $v !== '');
        if ($page > 1) $backParams['page'] = $page;
        View::render('admin/enlaces_list', [
            'session' => $session, 'filters' => $filters, 'page' => $page,
            'result' => $result, 'bandas' => EnlaceRepo::bandasConCandidatos(),
            'counts' => EnlaceRepo::counts(), 'backQs' => http_build_query($backParams),
        ], ['title' => 'Enlaces de streaming — Marchas de Cristo', 'noindex' => true]);
    }

    private static function enlaceBackQuery(string $raw): string
    {
        parse_str($raw, $parsed);
        $allowed = array_intersect_key($parsed, array_flip(['estado', 'servicio', 'confianza', 'banda', 'page']));
        return http_build_query($allowed);
    }

    public static function enlaceAprobar(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $back = self::enlaceBackQuery((string) ($_POST['ref'] ?? ''));
        $sep = $back !== '' ? '&' : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/enlaces?$back{$sep}err=CSRF", 302);

        $r = AdminRepo::aprobarEnlace($id);
        if (($r['code'] ?? '') !== 'APPROVED') Http::redirect("/dashboard/enlaces?$back{$sep}err=" . ($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/enlaces?$back{$sep}aprobado=1", 302);
    }

    public static function enlaceRechazar(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $back = self::enlaceBackQuery((string) ($_POST['ref'] ?? ''));
        $sep = $back !== '' ? '&' : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/enlaces?$back{$sep}err=CSRF", 302);

        $r = AdminRepo::rechazarEnlace($id);
        if (($r['code'] ?? '') !== 'REJECTED') Http::redirect("/dashboard/enlaces?$back{$sep}err=" . ($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/enlaces?$back{$sep}rechazado=1", 302);
    }

    public static function enlaceRechazarMultiple(): void
    {
        $session = Auth::requireAdmin();
        $back = self::enlaceBackQuery((string) ($_POST['ref'] ?? ''));
        $sep = $back !== '' ? '&' : '';
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/enlaces?$back{$sep}err=CSRF", 302);

        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $r = AdminRepo::rechazarEnlaces($ids);
        if (($r['code'] ?? '') !== 'REJECTED') Http::redirect("/dashboard/enlaces?$back{$sep}err=" . ($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/enlaces?$back{$sep}rechazados=" . $r['count'], 302);
    }

    // ── Autocomplete de dedicatorias (JSON, para el panel de ingesta) ────────
    public static function dedicatoriaFastSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (Auth::currentSession() === null) {
            http_response_code(401);
            echo json_encode(['code' => 'AUTH_REQUIRED', 'data' => []]);
            return;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $data = Repo::searchDedicatorias($q);
        echo json_encode(['rowsReturned' => count($data), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ── Autocomplete de autores (JSON) ───────────────────────────────────────
    public static function autorFastSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (Auth::currentSession() === null) {
            http_response_code(401);
            echo json_encode(['code' => 'AUTH_REQUIRED', 'data' => []]);
            return;
        }
        $nombre = trim((string) ($_GET['nombre'] ?? ''));
        if (mb_strlen($nombre) < 3) { echo json_encode(['rowsReturned' => 0, 'data' => []]); return; }
        $data = Repo::autorCandidatosPorTexto($nombre, 15);
        echo json_encode(['rowsReturned' => count($data), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Localidades y provincias únicas (marcha + banda) para el autocompletado del formulario. */
    /**
     * Municipios de una provincia que casan con ?q — para el selector en
     * cascada provincia→localidad (MunicipioRepo, catálogo cerrado). Sin
     * provincia (aún no elegida en el formulario) no hay nada que sugerir:
     * la localidad depende de ella, no al revés.
     */
    public static function municipioFastSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (Auth::currentSession() === null) {
            http_response_code(401);
            echo json_encode(['code' => 'AUTH_REQUIRED', 'data' => []]);
            return;
        }
        $provincia = trim((string) ($_GET['provincia'] ?? ''));
        if ($provincia === '' || !MunicipioRepo::esProvinciaValida($provincia)) {
            echo json_encode(['rowsReturned' => 0, 'data' => []]);
            return;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = MunicipioRepo::buscar($provincia, $q, 15);
        $data = array_map(static fn(array $r): string => (string) $r['NOMBRE'], $rows);
        echo json_encode(['rowsReturned' => count($data), 'data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Alta de un par (provincia, localidad) nuevo en el catálogo — solo admin
     * (el editor no escribe nunca en la BD; para él, la localidad que escriba
     * viaja tal cual dentro de su propuesta y el admin la da de alta al
     * revisarla, con este mismo endpoint). JSON, pensado para fetch() desde
     * el selector del formulario, sin recargar la página.
     */
    public static function municipioAddPost(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $session = Auth::requireAdmin();
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) {
            http_response_code(403);
            echo json_encode(['code' => 'CSRF']);
            return;
        }
        $r = MunicipioRepo::crear(
            trim((string) ($_POST['provincia'] ?? '')),
            trim((string) ($_POST['nombre'] ?? ''))
        );
        if ($r['code'] !== 'CREATED') {
            http_response_code(400);
        }
        echo json_encode($r);
    }

    /**
     * Detecta posibles duplicados de marcha por título (similitud > 80 %) dentro
     * del conjunto de marchas que comparten al menos un autor con los indicados.
     */
    public static function marchaCheckDuplicate(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (Auth::currentSession() === null) {
            http_response_code(401);
            echo json_encode(['code' => 'AUTH_REQUIRED', 'data' => []]);
            return;
        }
        $titulo = trim((string) ($_GET['titulo'] ?? ''));
        $rawIds = $_GET['autorIds'] ?? [];
        $autorIds = is_array($rawIds) ? array_map('intval', $rawIds) : [(int) $rawIds];
        $autorIds = array_filter($autorIds, static fn(int $id): bool => $id > 0);
        $excludeId = isset($_GET['excludeId']) ? (int) $_GET['excludeId'] : null;

        if ($titulo === '' || $autorIds === []) {
            echo json_encode(['rowsReturned' => 0, 'data' => []]);
            return;
        }

        $ph = implode(',', array_fill(0, count($autorIds), '?'));
        $candidatas = Db::all(
            "SELECT DISTINCT m.ID_MARCHA, m.TITULO
             FROM marcha m
             JOIN marcha_autor ma ON ma.ID_MARCHA = m.ID_MARCHA
             WHERE ma.ID_AUTOR IN ($ph)" . ($excludeId !== null ? " AND m.ID_MARCHA != $excludeId" : ''),
            array_values($autorIds)
        );

        $hits = [];
        foreach ($candidatas as $c) {
            $sim = Similarity::ratio($titulo, (string) $c['TITULO']);
            if ($sim >= 0.80) {
                $hits[] = ['ID_MARCHA' => (int) $c['ID_MARCHA'], 'TITULO' => $c['TITULO'], 'sim' => round($sim, 2)];
            }
        }
        usort($hits, static fn($a, $b) => $b['sim'] <=> $a['sim']);
        echo json_encode(['rowsReturned' => count($hits), 'data' => $hits], JSON_UNESCAPED_UNICODE);
    }

    // ── Gestión de usuarios (solo admin) ─────────────────────────────────────

    /**
     * Render del listado de usuarios. La contraseña recién generada ($nuevaClave)
     * se muestra una única vez y NUNCA viaja por la URL (no PRG en ese caso), para
     * no dejarla en el historial ni en logs de acceso.
     *
     * @param array<string,mixed> $extra
     */
    private static function renderUsuarios(array $session, array $extra = [], int $status = 200): void
    {
        if ($status !== 200) http_response_code($status);
        View::render('admin/usuarios_list', array_merge([
            'session' => $session,
            'usuarios' => UserRepo::all(),
            'roles' => Roles::ALL,
            'labels' => Roles::LABELS,
            'notice' => self::noticeFromQuery(),
            'nuevaClave' => null,
            'nuevoUsuario' => null,
            'error' => null,
        ], $extra), ['title' => 'Usuarios — Marchas de Cristo', 'noindex' => true]);
    }

    public static function usuariosList(): void
    {
        $session = Auth::requireAdmin();
        self::renderUsuarios($session);
    }

    public static function usuariosCrearPost(): void
    {
        $session = Auth::requireAdmin();
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) { self::renderUsuarios($session, ['error' => 'CSRF'], 400); return; }
        $r = UserRepo::create((string) ($_POST['usuario'] ?? ''));
        if (($r['code'] ?? '') === 'CREATED') {
            self::renderUsuarios($session, [
                'nuevoUsuario' => $r['usuario'], 'nuevaClave' => $r['clave'],
                'notice' => ['type' => 'ok', 'msg' => 'Usuario creado con rol Editor.'],
            ]);
            return;
        }
        self::renderUsuarios($session, ['error' => $r['code'] ?? 'ERROR'], 400);
    }

    public static function usuariosRolPost(array $p): void
    {
        $session = Auth::requireAdmin();
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect('/dashboard/usuarios?err=CSRF', 302);
        $r = UserRepo::changeRole((int) $p['id'], (string) ($_POST['rol'] ?? ''));
        $code = $r['code'] ?? 'ERROR';
        if ($code === 'UPDATED') Http::redirect('/dashboard/usuarios?saved=1', 302);
        if ($code === 'NO_CHANGE') Http::redirect('/dashboard/usuarios?nochanges=1', 302);
        Http::redirect('/dashboard/usuarios?err=' . $code, 302);
    }

    public static function usuariosResetPost(array $p): void
    {
        $session = Auth::requireAdmin();
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) { self::renderUsuarios($session, ['error' => 'CSRF'], 400); return; }
        $r = UserRepo::resetPassword((int) $p['id']);
        if (($r['code'] ?? '') === 'RESET') {
            self::renderUsuarios($session, [
                'nuevoUsuario' => $r['usuario'], 'nuevaClave' => $r['clave'],
                'notice' => ['type' => 'ok', 'msg' => 'Contraseña restablecida.'],
            ]);
            return;
        }
        self::renderUsuarios($session, ['error' => $r['code'] ?? 'ERROR'], 400);
    }

    // ── Propuestas de editores (revisión, solo admin) ────────────────────────

    /** Nombre breve de una banda por id (para el preview de BANDA_ESTRENO), o null. */
    private static function bandaNombre(mixed $id): ?string
    {
        $id = trim((string) $id);
        if ($id === '' || !ctype_digit($id)) return null;
        $b = Repo::fetchBandaRaw($id);
        return $b !== null ? (string) ($b['NOMBRE_BREVE'] ?? '') : null;
    }

    /** Conjunto de campos editables según entidad/acción de una propuesta. */
    private static function editableFor(string $entidad, string $accion): array
    {
        return match ($entidad) {
            'marcha' => $accion === 'add' ? AdminRepo::INSERTABLE_MARCHA : AdminRepo::EDITABLE_MARCHA,
            'autor' => AdminRepo::EDITABLE_AUTOR,
            'banda' => AdminRepo::EDITABLE_BANDA,
            default => [],
        };
    }

    /** Valores actuales en la BD local, para el diff (solo propuestas de edición). */
    private static function propuestaActual(array $prop): ?array
    {
        if (($prop['accion'] ?? '') !== 'edit' || $prop['target_id'] === null) return null;
        $id = (string) $prop['target_id'];
        return match ($prop['entidad'] ?? '') {
            'marcha' => Repo::fetchMarchaRaw($id),
            'autor' => Repo::fetchAutorRaw($id),
            'banda' => Repo::fetchBandaRaw($id),
            default => null,
        };
    }

    public static function propuestaList(): void
    {
        $session = Auth::requireAdmin();
        View::render('admin/propuesta_list', [
            'session' => $session,
            'items' => PropuestaRepo::pendientes(),
            'notice' => self::noticeFromQuery(),
        ], ['title' => 'Propuestas de editores — Marchas de Cristo', 'noindex' => true]);
    }

    public static function propuestaDetail(array $p): void
    {
        $session = Auth::requireAdmin();
        $prop = PropuestaRepo::fetchPendiente((string) $p['id']);
        if ($prop === null) Http::notFound();
        $authors = ($prop['entidad'] ?? '') === 'marcha'
            ? Repo::autoresByIds(array_map('intval', (array) ($prop['autoresIds'] ?? [])))
            : [];
        View::render('admin/propuesta_detail', [
            'session' => $session, 'prop' => $prop, 'authors' => $authors,
            'actual' => self::propuestaActual($prop),
            'bandaNombre' => ($prop['entidad'] ?? '') === 'marcha' ? self::bandaNombre(($prop['datos']['BANDA_ESTRENO'] ?? null)) : null,
            'editable' => self::editableFor((string) ($prop['entidad'] ?? ''), (string) ($prop['accion'] ?? '')),
            'notice' => self::noticeFromQuery(), 'error' => null,
        ], ['title' => 'Revisar propuesta — Marchas de Cristo', 'noindex' => true]);
    }

    public static function propuestaAceptar(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (string) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/propuesta/$id?err=CSRF", 302);
        $prop = PropuestaRepo::fetchPendiente($id);
        if ($prop === null) Http::notFound();

        // El admin puede ajustar los campos antes de aceptar (el form los reenvía).
        $editable = self::editableFor((string) $prop['entidad'], (string) $prop['accion']);
        $overrideDatos = self::postDatos($editable);
        $overrideAutores = ($prop['entidad'] ?? '') === 'marcha' ? self::postAutoresIds() : null;

        $r = PropuestaRepo::aplicar($id, (string) ($session['user'] ?? ''), $overrideDatos, $overrideAutores);
        if (in_array($r['code'] ?? '', ['CREATED', 'UPDATED'], true)) {
            self::notifPropuesta('aceptada', $prop);
            Http::redirect('/dashboard/propuestas?aplicada=1', 302);
        }
        Http::redirect("/dashboard/propuesta/$id?err=" . ($r['code'] ?? 'ERROR'), 302);
    }

    public static function propuestaRechazar(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (string) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/propuesta/$id?err=CSRF", 302);
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        $prop = PropuestaRepo::fetchPendiente($id);
        $r = PropuestaRepo::rechazar($id, (string) ($session['user'] ?? ''), $motivo !== '' ? $motivo : null);
        if (($r['code'] ?? '') !== 'REJECTED') Http::redirect("/dashboard/propuesta/$id?err=" . ($r['code'] ?? 'ERROR'), 302);
        if ($prop !== null) self::notifPropuesta('rechazada', $prop, $motivo !== '' ? $motivo : null);
        Http::redirect('/dashboard/propuestas?rechazada=1', 302);
    }

    /** Título legible de una propuesta para usar en el asunto/cuerpo del email. */
    private static function propuestaLabel(array $prop): string
    {
        $datos = (array) ($prop['datos'] ?? []);
        return match ($prop['entidad'] ?? '') {
            'marcha' => (string) ($datos['TITULO'] ?? 'marcha sin título'),
            'autor'  => trim(($datos['NOMBRE'] ?? '') . ' ' . ($datos['APELLIDOS'] ?? '')) ?: 'compositor sin nombre',
            'banda'  => (string) ($datos['NOMBRE_COMPLETO'] ?? $datos['NOMBRE_BREVE'] ?? 'banda sin nombre'),
            default  => 'propuesta',
        };
    }

    /**
     * Envía un email al editor que creó la propuesta notificando el resultado.
     * Es una llamada de «mejor esfuerzo»: si no hay email configurado o mail()
     * falla, se silencia el error para no interrumpir el flujo del admin.
     *
     * @param 'aceptada'|'rechazada' $accion
     * @param array<string,mixed>    $prop
     */
    private static function notifPropuesta(string $accion, array $prop, ?string $motivo = null): void
    {
        $autor = (string) ($prop['autor'] ?? '');
        $to = Mailer::editorEmail($autor);
        if ($to === '') return;

        $titulo   = self::propuestaLabel($prop);
        $entidad  = (string) ($prop['entidad'] ?? 'entidad');
        $siteUrl  = rtrim((string) ($GLOBALS['config']['site_url'] ?? 'https://marchasdecristo.com'), '/');
        $eTitulo  = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $eEntidad = htmlspecialchars(ucfirst($entidad), ENT_QUOTES, 'UTF-8');
        $eSiteUrl = htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8');
        $pie      = '<hr style="border:0;border-top:1px solid #dce0eb;margin:1.5rem 0">'
                  . '<p style="font-size:.8rem;color:#8890a1">Marchas de Cristo · '
                  . '<a href="' . $eSiteUrl . '" style="color:#3a4d9e">' . $eSiteUrl . '</a></p>';

        if ($accion === 'aceptada') {
            $subject = "Tu propuesta de {$entidad} ha sido aceptada — Marchas de Cristo";
            $html = '<!doctype html><html lang="es"><body style="font-family:sans-serif;color:#181b24;'
                . 'max-width:38rem;margin:2rem auto;padding:0 1rem;line-height:1.55">'
                . '<p style="font-size:.8rem;color:#8890a1;font-family:monospace;margin:0 0 1rem">MARCHAS DE CRISTO</p>'
                . '<h1 style="font-size:1.3rem;border-bottom:2px solid #181b24;padding-bottom:.4rem;margin:0 0 1rem">'
                . 'Propuesta aceptada</h1>'
                . '<p>Tu propuesta <strong>«' . $eTitulo . '»</strong> (' . $eEntidad . ') '
                . 'ha sido revisada y <strong>aceptada</strong>.</p>'
                . '<p>Los cambios ya están disponibles en el catálogo.</p>'
                . $pie . '</body></html>';
        } else {
            $subject = "Tu propuesta de {$entidad} no ha podido aplicarse — Marchas de Cristo";
            $motivoHtml = $motivo !== null
                ? '<p><strong>Motivo:</strong> ' . htmlspecialchars($motivo, ENT_QUOTES, 'UTF-8') . '</p>'
                : '';
            $html = '<!doctype html><html lang="es"><body style="font-family:sans-serif;color:#181b24;'
                . 'max-width:38rem;margin:2rem auto;padding:0 1rem;line-height:1.55">'
                . '<p style="font-size:.8rem;color:#8890a1;font-family:monospace;margin:0 0 1rem">MARCHAS DE CRISTO</p>'
                . '<h1 style="font-size:1.3rem;border-bottom:2px solid #181b24;padding-bottom:.4rem;margin:0 0 1rem">'
                . 'Propuesta no aplicada</h1>'
                . '<p>Tu propuesta <strong>«' . $eTitulo . '»</strong> (' . $eEntidad . ') '
                . 'ha sido revisada y no ha podido aplicarse en este momento.</p>'
                . $motivoHtml
                . '<p>Si tienes dudas, puedes ponerte en contacto con el administrador del catálogo.</p>'
                . $pie . '</body></html>';
        }

        Mailer::send($to, $subject, $html);
    }

    // ── Discos ──────────────────────────────────────────────────────────────
    //
    // Solo administrador: a diferencia de marcha/banda/autor, el disco no pasa
    // por la cola de propuestas (PropuestaRepo no conoce esta entidad) y además
    // escribe un fichero en el docroot al subir la portada.

    public static function discoAddForm(): void
    {
        $session = Auth::requireAdmin();
        self::renderDiscoAdd($session, [], null);
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $disco */
    private static function renderDiscoAdd(array $session, array $disco, ?string $error): void
    {
        if ($error !== null) http_response_code(400);
        View::render('admin/disco_add', [
            'session' => $session, 'disco' => $disco, 'error' => $error,
        ], ['title' => 'Añadir disco — Marchas de Cristo', 'noindex' => true]);
    }

    public static function discoAddPost(): void
    {
        $session = Auth::requireAdmin();
        $fields = [];
        foreach (AdminRepo::EDITABLE_DISCO as $f) $fields[$f] = $_POST[$f] ?? '';

        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) { self::renderDiscoAdd($session, $fields, 'CSRF'); return; }

        $r = AdminRepo::addDisco($fields);
        if (($r['code'] ?? '') !== 'CREATED') { self::renderDiscoAdd($session, $fields, $r['code'] ?? 'ERROR'); return; }

        $discoId = (int) $r['discoId'];
        // La portada se guarda DESPUÉS de crear el disco porque el nombre del
        // fichero es su ID. Si falla, el disco ya existe: se avisa en la
        // pantalla de edición en vez de deshacer el alta.
        $errPortada = null;
        if (($_FILES['portada']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $errPortada = Media::guardarPortada($_FILES['portada'], $discoId);
        }
        // Recién creado el disco, lo primero que se ofrece es importar el
        // tracklist desde el enlace del álbum: es el camino que rellena de una
        // vez las pistas, su orden y su duración. Desde ahí se puede seguir a
        // mano con un clic (la ficha del disco no cambia).
        Http::redirect("/dashboard/disco/$discoId/importar?created=1" . ($errPortada !== null ? '&err=' . rawurlencode($errPortada) : ''), 302);
    }

    public static function discoEditForm(array $p): void
    {
        $session = Auth::requireAdmin();
        self::renderDiscoForm($session, (int) $p['id']);
    }

    /** @param array<string,mixed> $session */
    private static function renderDiscoForm(array $session, int $id, ?string $error = null): void
    {
        $data = AdminRepo::discoConPistas($id);
        if ($data === null) { Http::notFound(); return; }
        $err = $error ?? (isset($_GET['err']) ? (string) $_GET['err'] : null);
        if ($err !== null) http_response_code(400);
        // Pestaña activa: los POST vuelven aquí (PRG) con ?tab=… para no
        // devolver al usuario a "Datos" tras tocar pistas o enlaces.
        $tab = (string) ($_GET['tab'] ?? 'datos');
        if (!in_array($tab, ['datos', 'pistas', 'streaming'], true)) $tab = 'datos';
        View::render('admin/disco_form', [
            'session' => $session,
            'disco' => $data['disco'],
            'pistas' => $data['pistas'],
            'portada' => Media::portadaExiste($id),
            'enlaces' => EnlaceRepo::publicadosDe('disco', $id),
            'tab' => $tab,
            'error' => $err,
            'notice' => isset($_GET['created'])
                ? 'Disco creado.'
                : (isset($_GET['social'])
                    ? 'Enlaces de streaming guardados.'
                    : (isset($_GET['ok']) ? (string) $_GET['ok'] : null)),
        ], ['title' => 'Disco #' . $id . ' — Marchas de Cristo', 'noindex' => true]);
    }

    public static function discoEditPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/disco/$id?err=CSRF", 302);

        $fields = [];
        foreach (AdminRepo::EDITABLE_DISCO as $f) {
            if (array_key_exists($f, $_POST)) $fields[$f] = $_POST[$f];
        }
        $r = AdminRepo::updateDisco($id, $fields);
        if (!in_array($r['code'] ?? '', ['UPDATED', 'INVALID_FIELDS'], true)) {
            Http::redirect("/dashboard/disco/$id?err=" . rawurlencode($r['code'] ?? 'ERROR'), 302);
        }

        if (($_FILES['portada']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $errPortada = Media::guardarPortada($_FILES['portada'], $id);
            if ($errPortada !== null) Http::redirect("/dashboard/disco/$id?err=" . rawurlencode($errPortada), 302);
        }
        Http::redirect("/dashboard/disco/$id?ok=" . rawurlencode('Cambios guardados.'), 302);
    }

    public static function discoPistaAddPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/disco/$id?err=CSRF&tab=pistas", 302);

        $r = AdminRepo::addPista(
            $id,
            (int) ($_POST['idMarcha'] ?? 0),
            (int) ($_POST['numero'] ?? 0),
            (int) ($_POST['nDisco'] ?? 1),
            self::parseDuracionMmSs((string) ($_POST['duracion'] ?? '')),
            self::parsePercusionPista((string) ($_POST['percusion'] ?? ''))
        );
        if (($r['code'] ?? '') !== 'CREATED') Http::redirect("/dashboard/disco/$id?tab=pistas&err=" . rawurlencode($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/disco/$id?tab=pistas&ok=" . rawurlencode('Pista añadida.'), 302);
    }

    /**
     * "mm:ss" o "h:mm:ss" -> segundos (R-02: duración por grabación, no por
     * obra). Tolerante: vacío o formato irreconocible devuelve null en vez de
     * fallar — el campo es opcional.
     */
    /**
     * Excepción de percusión de una pista concreta. Tres estados:
     *   ''  -> null : hereda el flag del disco (por defecto)
     *   '1' -> 1    : esta pista lleva intro aunque el disco no
     *   '0' -> 0    : esta pista NO lleva intro aunque el disco sí
     */
    private static function parsePercusionPista(string $s): ?int
    {
        $s = trim($s);
        if ($s === '' || $s === 'heredar') return null;
        return $s === '1' ? 1 : 0;
    }

    private static function parseDuracionMmSs(string $s): ?int
    {
        $s = trim($s);
        if ($s === '') return null;
        if (!preg_match('/^(?:(\d+):)?([0-5]?\d):([0-5]\d)$/', $s, $m)) return null;
        $h = $m[1] !== '' ? (int) $m[1] : 0;
        return $h * 3600 + (int) $m[2] * 60 + (int) $m[3];
    }

    public static function discoPistaEditPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/disco/$id?err=CSRF&tab=pistas", 302);

        $r = AdminRepo::updatePista(
            $id,
            (int) $p['dm'],
            (int) ($_POST['idMarcha'] ?? 0),
            (int) ($_POST['numero'] ?? 0),
            (int) ($_POST['nDisco'] ?? 1),
            self::parseDuracionMmSs((string) ($_POST['duracion'] ?? '')),
            self::parsePercusionPista((string) ($_POST['percusion'] ?? ''))
        );
        if (($r['code'] ?? '') !== 'UPDATED') Http::redirect("/dashboard/disco/$id?tab=pistas&err=" . rawurlencode($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/disco/$id?tab=pistas&ok=" . rawurlencode('Pista actualizada.'), 302);
    }

    public static function discoPistaDeletePost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/disco/$id?err=CSRF&tab=pistas", 302);

        $r = AdminRepo::deletePista($id, (int) $p['dm']);
        if (($r['code'] ?? '') !== 'DELETED') Http::redirect("/dashboard/disco/$id?tab=pistas&err=" . rawurlencode($r['code'] ?? 'ERROR'), 302);
        Http::redirect("/dashboard/disco/$id?tab=pistas&ok=" . rawurlencode('Pista eliminada.'), 302);
    }

    /**
     * Alta/edición/baja manual de los enlaces de streaming de un disco (álbum).
     * Mismo patrón que bandaSocialPost: un campo por servicio, vacío = borrar.
     */
    public static function discoSocialPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/disco/$id?err=CSRF&tab=streaming", 302);

        foreach (EnlaceRepo::SERVICIOS as $servicio) {
            $url = $_POST[$servicio] ?? null;
            $r = AdminRepo::setEnlaceStreaming('disco', $id, $servicio, is_string($url) ? $url : null);
            if (($r['code'] ?? '') === 'BAD_REQUEST') Http::redirect("/dashboard/disco/$id?err=BAD_REQUEST&tab=streaming", 302);
        }
        Http::redirect("/dashboard/disco/$id?social=1&tab=streaming", 302);
    }

    // ── Disco: alta asistida de pistas desde el enlace del álbum ────────────
    //
    // Flujo en tres pasos, sin estado en servidor (el plan viaja en el propio
    // formulario, que es lo que permite que funcione en un hosting compartido
    // sin sesiones de trabajo ni tablas temporales):
    //
    //   1. GET  …/importar            → pedir el enlace del álbum.
    //   2. POST …/importar            → leer el tracklist y proponer el plan.
    //   3. POST …/importar/confirmar  → escribir las pistas aprobadas.
    //
    // El alta manual (pestaña «Pistas») sigue intacta y es siempre alcanzable:
    // esto es un atajo, no un sustituto.

    public static function discoImportarForm(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $data = AdminRepo::discoConPistas($id);
        if ($data === null) { Http::notFound(); return; }

        // Si el disco ya tiene enlace de álbum guardado, se propone ese: lo
        // normal es que sea justo el que se quiere importar.
        $enlaces = EnlaceRepo::publicadosDe('disco', $id);
        $url = '';
        foreach (Tracklist::SERVICIOS as $servicio) {
            if (!empty($enlaces[$servicio])) { $url = (string) $enlaces[$servicio]; break; }
        }

        self::renderDiscoImportar($session, $data, 'url', [
            'url' => $url,
            'error' => isset($_GET['err']) ? (string) $_GET['err'] : null,
            'creado' => isset($_GET['created']),
        ]);
    }

    public static function discoImportarPost(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        $data = AdminRepo::discoConPistas($id);
        if ($data === null) { Http::notFound(); return; }

        $url = trim((string) ($_POST['url'] ?? ''));
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) {
            self::renderDiscoImportar($session, $data, 'url', ['url' => $url, 'error' => 'CSRF']);
            return;
        }

        $r = Tracklist::de($url);
        if ($r['error'] !== null) {
            self::renderDiscoImportar($session, $data, 'url', ['url' => $url, 'error' => $r['error']]);
            return;
        }

        self::renderDiscoImportar($session, $data, 'revision', [
            'url' => $url,
            'servicio' => $r['servicio'],
            'filas' => ImportadorPistas::analizar($id, $r['tracks']),
        ]);
    }

    public static function discoImportarConfirmar(array $p): void
    {
        $session = Auth::requireAdmin();
        $id = (int) $p['id'];
        if (!Auth::checkCsrf($_POST['_csrf'] ?? null, $session)) Http::redirect("/dashboard/disco/$id/importar?err=CSRF", 302);

        $filas = [];
        $crudas = $_POST['p'] ?? [];
        if (!is_array($crudas)) $crudas = [];
        foreach ($crudas as $fila) {
            if (!is_array($fila)) continue;
            if (empty($fila['add'])) continue;                 // desmarcada: no se añade
            $idMarcha = (int) ($fila['idMarcha'] ?? 0);
            if ($idMarcha <= 0) continue;                       // sin marcha: nada que enlazar
            $seg = (int) ($fila['seg'] ?? 0);
            $filas[] = [
                'idMarcha' => $idMarcha,
                'numero' => (int) ($fila['numero'] ?? 0),
                'volumen' => (int) ($fila['volumen'] ?? 1),
                'seg' => $seg > 0 ? $seg : null,
                'percusion' => self::parsePercusionPista((string) ($fila['percusion'] ?? '')),
                'titulo' => (string) ($fila['titulo'] ?? ''),
            ];
        }

        if ($filas === []) Http::redirect("/dashboard/disco/$id?tab=pistas&err=SIN_PISTAS_MARCADAS", 302);

        $r = ImportadorPistas::aplicar($id, $filas);

        // El enlace del álbum se guarda como enlace de streaming del disco: es
        // el mismo dato que pide la pestaña «Streaming» y de él viven después
        // fill_duraciones.php y la ficha pública. Solo si el usuario lo pidió.
        if (!empty($_POST['guardarEnlace'])) {
            $ref = Tracklist::parseUrl((string) ($_POST['url'] ?? ''));
            if ($ref !== null) {
                AdminRepo::setEnlaceStreaming('disco', $id, $ref['servicio'], (string) $_POST['url']);
            }
        }

        $msg = $r['anadidas'] === 1 ? '1 pista añadida.' : $r['anadidas'] . ' pistas añadidas.';
        if ($r['errores'] !== []) {
            $detalle = array_map(static fn(array $e): string => $e['titulo'] . ' (' . $e['code'] . ')', $r['errores']);
            $msg .= ' No se pudieron añadir: ' . implode('; ', array_slice($detalle, 0, 5));
            if (count($detalle) > 5) $msg .= ' y ' . (count($detalle) - 5) . ' más';
            $msg .= '.';
        }
        Http::redirect("/dashboard/disco/$id?tab=pistas&ok=" . rawurlencode($msg), 302);
    }

    /**
     * @param array<string,mixed> $session
     * @param array{disco:array<string,mixed>,pistas:list<array<string,mixed>>} $data
     * @param array<string,mixed> $extra
     */
    private static function renderDiscoImportar(array $session, array $data, string $fase, array $extra): void
    {
        if (!empty($extra['error'])) http_response_code(400);
        View::render('admin/disco_importar', array_merge([
            'session' => $session,
            'disco' => $data['disco'],
            'pistas' => $data['pistas'],
            'fase' => $fase,
            'url' => '',
            'servicio' => null,
            'filas' => [],
            'error' => null,
            'creado' => false,
        ], $extra), ['title' => 'Importar pistas · disco #' . (int) $data['disco']['ID_DISCO'] . ' — Marchas de Cristo', 'noindex' => true]);
    }

    /** Marchas que casan con ?q (ID exacto o trozos del título), para el buscador de pistas. */
    public static function marchaFastSearch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (Auth::currentSession() === null) {
            http_response_code(401);
            echo json_encode(['code' => 'AUTH_REQUIRED', 'data' => []]);
            return;
        }
        $q = trim((string) ($_GET['q'] ?? ''));
        // Con dos caracteres ya basta si son un ID ("7"); para texto se pide más.
        if ($q === '' || (mb_strlen($q) < 3 && !ctype_digit($q))) {
            echo json_encode(['rowsReturned' => 0, 'data' => []]);
            return;
        }
        $data = AdminRepo::marchaCandidatosPorTexto($q, 15);
        echo json_encode(['rowsReturned' => count($data), 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
