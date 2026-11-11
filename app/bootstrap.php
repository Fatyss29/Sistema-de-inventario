<?php
// app/bootstrap.php
// Se incluye al inicio de TODAS las páginas y APIs:
//   require_once __DIR__ . '/../app/bootstrap.php';
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$GLOBALS['CFG'] = require APP_ROOT . '/config/config.php';
date_default_timezone_set(cfg('zona_horaria', 'America/Mexico_City'));

require_once __DIR__ . '/Inventario.php';
require_once __DIR__ . '/layout.php';

// ------------------------------------------------------------------ //
//  Configuración                                                      //
// ------------------------------------------------------------------ //
function cfg(string $key, $default = null)
{
    $v = $GLOBALS['CFG'];
    foreach (explode('.', $key) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) return $default;
        $v = $v[$k];
    }
    return $v;
}

/** URL base del sistema, p. ej. '/inventario' (sin diagonal final). */
function base_url(): string
{
    static $base = null;
    if ($base !== null) return $base;
    $cfg = cfg('base_url');
    if (is_string($cfg)) return $base = rtrim($cfg, '/');

    $doc  = str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $root = str_replace('\\', '/', (string)realpath(APP_ROOT));
    if ($doc !== '' && stripos($root, $doc) === 0) {
        $base = substr($root, strlen($doc));
    } else {
        $base = '';
    }
    return $base = rtrim($base, '/');
}

function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

// ------------------------------------------------------------------ //
//  Base de datos                                                      //
// ------------------------------------------------------------------ //
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = cfg('db');
    $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET SESSION group_concat_max_len = 100000");
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    } catch (PDOException $e) {
        http_response_code(500);
        die('Error de conexión a la base de datos. Revisa config/config.php');
    }
    return $pdo;
}

// ------------------------------------------------------------------ //
//  Sesión y seguridad                                                 //
// ------------------------------------------------------------------ //
function iniciar_sesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('INVFISICO');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_url() === '' ? '/' : base_url() . '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function usuario(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

function uid(): int
{
    return (int)($_SESSION['usuario']['id_usuario'] ?? 0);
}

function es_rol(string ...$roles): bool
{
    $u = usuario();
    return $u !== null && in_array($u['rol'], $roles, true);
}

/** Para páginas HTML: redirige a login si no hay sesión o rol. */
function requiere_login(array $roles = []): array
{
    iniciar_sesion();
    $u = usuario();
    if ($u === null || sesion_expirada()) {
        header('Location: ' . url('login.php' . ($u ? '?razon=timeout' : '')));
        exit;
    }
    $u = usuario();
    if ($roles && !in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        header('Location: ' . url('index.php'));
        exit;
    }
    $_SESSION['ultima_actividad'] = time();
    return usuario();
}

/** Para APIs JSON: responde 401/403 en lugar de redirigir. */
function api_requiere_login(array $roles = []): array
{
    iniciar_sesion();
    $u = usuario();
    if ($u === null || sesion_expirada()) json_error('Tu sesión expiró. Vuelve a iniciar sesión.', 401);
    $u = usuario();
    if ($roles && !in_array($u['rol'], $roles, true)) json_error('No tienes permiso para esta acción.', 403);
    $_SESSION['ultima_actividad'] = time();
    return $u;
}

/** Vuelve a leer rol/estado del usuario (si el admin lo desactivó, lo saca). */
function refrescar_usuario(): ?array
{
    $id = uid();
    if (!$id) return null;
    $st = db()->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
    $st->execute([$id]);
    $u = $st->fetch();
    if (!$u || (int)$u['activo'] !== 1 || $u['rol'] === 'CONTADOR') {
        $_SESSION = [];
        session_destroy();
        return null;
    }
    unset($u['contrasena']);
    return $_SESSION['usuario'] = $u;
}

function sesion_expirada(): bool
{
    if (refrescar_usuario() === null) return true;
    $min = (int)cfg('minutos_inactividad', 30);
    $ult = (int)($_SESSION['ultima_actividad'] ?? time());
    if ($min > 0 && (time() - $ult) > $min * 60) {
        $_SESSION = [];
        session_destroy();
        return true;
    }
    return false;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

/** Valida el token de formularios POST (campo csrf) o de fetch (header X-CSRF). */
function csrf_valido(): bool
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($t) && $t !== '' && hash_equals($_SESSION['csrf'] ?? '', $t);
}

function ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function bitacora(string $accion, string $detalle = '', ?int $idUsuario = null): void
{
    try {
        db()->prepare("INSERT INTO bitacora (id_usuario, accion, detalle, ip) VALUES (?,?,?,?)")
            ->execute([$idUsuario ?? (uid() ?: null), $accion, $detalle, ip()]);
    } catch (Throwable $e) {
        error_log('bitacora: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------------ //
//  Respuestas y utilidades                                            //
// ------------------------------------------------------------------ //
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg, int $status = 400, array $extra = []): void
{
    json_out(['ok' => false, 'error' => $msg] + $extra, $status);
}

function body_json(): array
{
    $d = json_decode((string)file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function redirigir(string $path, ?string $flash = null, string $tipo = 'success'): void
{
    if ($flash !== null) $_SESSION['flash'] = ['msg' => $flash, 'tipo' => $tipo];
    header('Location: ' . url($path));
    exit;
}

function flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/** Formatea cantidades: sin decimales si es entero, hasta 4 si no. */
function qty($n): string
{
    $n = (float)$n;
    if (abs($n - round($n)) < 0.00005) return number_format($n, 0, '.', ',');
    return rtrim(rtrim(number_format($n, 4, '.', ','), '0'), '.');
}

/** Formato de dinero: $1,234.56  /  -$1,234.56 */
function dinero($n, int $dec = 2): string
{
    $n = (float)$n;
    return ($n < -0.005 ? '-' : '') . '$' . number_format(abs($n), $dec, '.', ',');
}

function folio($id): string
{
    return cfg('prefijo_marbete', 'MB-') . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function fecha($dt, bool $conHora = true): string
{
    if (!$dt) return '';
    $t = strtotime((string)$dt);
    return $conHora ? date('d/m/Y H:i', $t) : date('d/m/Y', $t);
}

// ------------------------------------------------------------------ //
//  Inventario activo / seleccionado                                   //
// ------------------------------------------------------------------ //
function inventario_abierto(): ?array
{
    $r = db()->query("SELECT * FROM inventarios WHERE estado = 'ABIERTO' ORDER BY id_inventario DESC LIMIT 1")->fetch();
    return $r ?: null;
}

/**
 * Inventario que está consultando el supervisor/admin.
 * Puede elegir uno anterior con ?inv=ID; si no, el abierto o el más reciente.
 */
function inventario_seleccionado(): ?array
{
    if (isset($_GET['inv'])) $_SESSION['inv_sel'] = (int)$_GET['inv'];
    $id = (int)($_SESSION['inv_sel'] ?? 0);
    if ($id > 0) {
        $st = db()->prepare("SELECT * FROM inventarios WHERE id_inventario = ?");
        $st->execute([$id]);
        if ($r = $st->fetch()) return $r;
    }
    $r = inventario_abierto();
    if ($r) return $r;
    $r = db()->query("SELECT * FROM inventarios ORDER BY id_inventario DESC LIMIT 1")->fetch();
    return $r ?: null;
}

function inventarios_todos(): array
{
    return db()->query("SELECT id_inventario, nombre, estado FROM inventarios ORDER BY id_inventario DESC")->fetchAll();
}

/** Estado que ve el SUPERVISOR: no debe saber si los conteos coinciden. */
function estado_para(string $estado): string
{
    if (es_rol('ADMIN')) return $estado;
    return in_array($estado, ['COINCIDE', 'DIFERENCIA', 'VALIDADO', 'RECONTEO'], true) ? 'CONTADO' : $estado;
}

const ESTADOS_MARBETE = [
    'EN_CONTEO'  => ['En conteo',          'secondary'],
    'CONTADO'    => ['1 conteo',           'info'],
    'COINCIDE'   => ['Coincide',           'success'],
    'DIFERENCIA' => ['No coincide',        'danger'],
    'RECONTEO'   => ['Reconteo pedido',    'warning'],
    'VALIDADO'   => ['Conteo elegido', 'primary'],
    'CANCELADO'  => ['Cancelado',          'dark'],
];

function badge_estado(string $estado): string
{
    $estado = estado_para($estado);
    [$txt, $color] = ESTADOS_MARBETE[$estado] ?? [$estado, 'secondary'];
    if (!es_rol('ADMIN') && $estado === 'CONTADO') [$txt, $color] = ['Contado', 'info'];
    $tc = in_array($color, ['warning', 'info'], true) ? ' text-dark' : '';
    return '<span class="badge bg-' . $color . $tc . '">' . h($txt) . '</span>';
}

/**
 * ¿Puede este usuario ver el marbete? Admin: todos.
 * Supervisor: si su equipo lo contó o si el admin se lo asignó para el siguiente conteo.
 */
function puede_ver_marbete(array $u, array $m): bool
{
    if ($u['rol'] === 'ADMIN') return true;
    if ((int)$m['sup_reconteo'] === (int)$u['id_usuario']) return true;
    $st = db()->prepare("SELECT 1 FROM conteos WHERE id_marbete = ? AND id_supervisor = ? LIMIT 1");
    $st->execute([$m['id_marbete'], $u['id_usuario']]);
    return (bool)$st->fetchColumn();
}

/** Condición SQL (sobre alias m) para limitar marbetes al equipo del supervisor. */
function sql_marbetes_equipo(array $u, string $alias = 'm'): string
{
    if ($u['rol'] === 'ADMIN') return '1=1';
    $id = (int)$u['id_usuario'];
    return "({$alias}.sup_reconteo = {$id} OR EXISTS (SELECT 1 FROM conteos ce WHERE ce.id_marbete = {$alias}.id_marbete AND ce.id_supervisor = {$id}))";
}
