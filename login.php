<?php
require_once __DIR__ . '/app/bootstrap.php';
iniciar_sesion();

if (usuario()) {
    header('Location: ' . url('index.php'));
    exit;
}

$error = '';
$numEmp = '';
if (($_GET['razon'] ?? '') === 'timeout') $error = 'Tu sesión se cerró por inactividad. Vuelve a entrar.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = '';
    $numEmp = trim((string)($_POST['num_empleado'] ?? ''));
    $pass   = (string)($_POST['contrasena'] ?? '');

    // Freno simple contra intentos repetidos (por sesión)
    $_SESSION['intentos'] = (int)($_SESSION['intentos'] ?? 0);
    if ($_SESSION['intentos'] >= 8 && time() - (int)($_SESSION['intento_t'] ?? 0) < 120) {
        $error = 'Demasiados intentos. Espera 2 minutos.';
    } elseif ($numEmp === '' || $pass === '') {
        $error = 'Escribe tu número de empleado y contraseña.';
    } else {
        $st = db()->prepare("SELECT * FROM usuarios WHERE num_empleado = ? LIMIT 1");
        $st->execute([$numEmp]);
        $u = $st->fetch();
        if ($u && $u['rol'] === 'CONTADOR') {
            $error = 'Los contadores no entran al sistema: su supervisor registra sus conteos.';
        } elseif ($u && (int)$u['activo'] === 1 && password_verify($pass, $u['contrasena'])) {
            session_regenerate_id(true);
            unset($u['contrasena']);
            $_SESSION['usuario'] = $u;
            $_SESSION['ultima_actividad'] = time();
            $_SESSION['intentos'] = 0;
            db()->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id_usuario = ?")->execute([$u['id_usuario']]);
            bitacora('LOGIN', '', (int)$u['id_usuario']);
            header('Location: ' . url('index.php'));
            exit;
        }
        if (!$error) {
            $_SESSION['intentos']++;
            $_SESSION['intento_t'] = time();
            $error = ($u && (int)$u['activo'] !== 1) ? 'Tu usuario está desactivado.' : 'Número de empleado o contraseña incorrectos.';
            bitacora('LOGIN_FALLIDO', 'num_empleado ' . $numEmp, $u ? (int)$u['id_usuario'] : null);
        }
    }
}
layout_inicio('Iniciar sesión');
?>
<div class="row justify-content-center align-items-center login-zona">
  <div class="col-sm-9 col-md-6 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <div class="text-center mb-3">
          <img src="<?= h(url('assets/logo.svg')) ?>" alt="Logo" height="56">
          <h1 class="h4 mt-2 mb-0">Inventario físico</h1>
          <p class="text-muted small mb-0"><?= h(cfg('app_nombre')) ?></p>
        </div>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <div class="mb-3">
            <label class="form-label" for="num_empleado">Número de empleado</label>
            <input class="form-control form-control-lg" id="num_empleado" name="num_empleado" value="<?= h($numEmp) ?>" inputmode="numeric" autofocus required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="contrasena">Contraseña</label>
            <input class="form-control form-control-lg" type="password" id="contrasena" name="contrasena" required>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="ver" onclick="document.getElementById('contrasena').type=this.checked?'text':'password'">
            <label class="form-check-label" for="ver">Mostrar contraseña</label>
          </div>
          <button class="btn btn-primario btn-lg w-100">Entrar</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php layout_fin(); ?>
