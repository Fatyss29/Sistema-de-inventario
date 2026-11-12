<?php
// app/layout.php  — encabezado y pie comunes de todas las pantallas
declare(strict_types=1);

function layout_inicio(string $titulo, string $activo = '', array $opc = []): void
{
    $u = usuario();
    $menu = [];      // enlaces directos
    $contar = [];    // submenú "Contar"
    if ($u && in_array($u['rol'], ['SUPERVISOR', 'ADMIN'], true)) {
        $contar['pt']  = ['Contar PT', 'contador/pt.php'];
        $contar['mp']  = ['Contar MP', 'contador/mp.php'];
        $contar['mis'] = ['Conteos del día', 'contador/mis_conteos.php'];
        $esAdm = $u['rol'] === 'ADMIN';
        $menu['sup']         = ['Avance', 'supervisor/index.php'];
        $menu['asignar']     = ['2do conteo', $esAdm ? 'admin/segundo_conteo.php' : 'supervisor/asignar.php'];
        $menu['marbetes']    = ['Marbetes', 'supervisor/marbetes.php'];
        $menu['usuariosdia'] = ['Por usuario', 'supervisor/por_usuario.php'];
        $menu['real']        = ['Reales', 'supervisor/real.php'];
        if ($esAdm) {
            $menu['comp']   = ['Real vs Epicor', 'admin/comparativo.php'];
            $menu['inv']    = ['Inventarios', 'admin/inventarios.php'];
            $menu['epicor'] = ['Epicor', 'admin/epicor.php'];
            $menu['users']  = ['Usuarios y equipos', 'admin/usuarios.php'];
        }
    }
    $compacto = !empty($opc['compacto']);
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf" content="<?= h(csrf_token()) ?>">
  <meta name="base" content="<?= h(base_url()) ?>">
  <meta name="prefijo" content="<?= h(rtrim((string)cfg('prefijo_marbete', 'MB-'), '-')) ?>">
  <title><?= h($titulo) ?> · Inventario</title>
  <link rel="icon" type="image/svg+xml" href="<?= h(url('assets/logo.svg')) ?>">
  <link rel="stylesheet" href="<?= h(url('assets/vendor/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= h(url('assets/css/app.css')) ?>">
  <script src="<?= h(url('assets/js/app.js')) ?>"></script>
</head>
<body class="<?= $compacto ? 'modo-escaner' : '' ?>">
<?php if ($u): ?>
<nav class="navbar navbar-expand-xl navbar-dark barra">
  <div class="container-fluid">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h(url('index.php')) ?>">
      <img src="<?= h(url('assets/logo-blanco.svg')) ?>" alt="" height="28"> Inventario
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menú">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($menu as $k => [$txt, $href]): ?>
          <li class="nav-item"><a class="nav-link<?= $k === $activo ? ' active' : '' ?>" href="<?= h(url($href)) ?>"><?= h($txt) ?></a></li>
        <?php endforeach; ?>
        <?php if ($contar): ?>
          <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle<?= isset($contar[$activo]) ? ' active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">Contar</a>
            <ul class="dropdown-menu">
              <?php foreach ($contar as $k => [$txt, $href]): ?><li><a class="dropdown-item" href="<?= h(url($href)) ?>"><?= h($txt) ?></a></li><?php endforeach; ?>
            </ul>
          </li>
        <?php endif; ?>
      </ul>
      <span class="navbar-text me-3 small">
        <?= h($u['nombre']) ?> · <span class="badge bg-light text-dark"><?= h(ucfirst(strtolower($u['rol']))) ?></span>
      </span>
      <a class="btn btn-sm btn-outline-light" href="<?= h(url('logout.php')) ?>">Salir</a>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="<?= $compacto ? 'container-fluid px-2 px-md-3' : 'container-xl' ?> py-3">
<?php
    if ($f = flash()) {
        echo '<div class="alert alert-' . h($f['tipo']) . ' alert-dismissible fade show">' . h($f['msg'])
           . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
}

function layout_fin(array $scripts = []): void
{
    ?>
</main>
<div id="toasts" class="toast-zona"></div>
<script src="<?= h(url('assets/vendor/bootstrap.bundle.min.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= h(url($s)) ?>?v=1"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Selector de inventario para supervisor/admin (conserva los demás filtros). */
function selector_inventario(?array $inv): void
{
    $todos = inventarios_todos();
    if (!$todos) {
        echo '<div class="alert alert-warning">Todavía no hay inventarios. '
           . (es_rol('ADMIN') ? '<a href="' . h(url('admin/inventarios.php')) . '">Crea uno aquí</a>.' : 'Pide al administrador que cree uno.')
           . '</div>';
        return;
    }
    ?>
    <form method="get" class="d-flex align-items-center gap-2 mb-3 selector-inv">
      <label class="small text-muted mb-0" for="inv">Inventario</label>
      <select name="inv" id="inv" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <?php foreach ($todos as $i): ?>
          <option value="<?= (int)$i['id_inventario'] ?>" <?= $inv && (int)$inv['id_inventario'] === (int)$i['id_inventario'] ? 'selected' : '' ?>>
            <?= h($i['nombre']) ?><?= $i['estado'] === 'ABIERTO' ? ' (abierto)' : ' (cerrado)' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php
}


/**
 * Selector del contador que va a contar (pantallas PT / MP).
 * El supervisor solo ve a su equipo; el admin ve a todos los contadores.
 */
function selector_contador(array $u, string $tipo): void
{
    $lista = Inv::equipo($u['rol'] === 'SUPERVISOR' ? (int)$u['id_usuario'] : null, $tipo);
    $inv = inventario_abierto();
    $pend = 0;
    if ($inv && $u['rol'] === 'SUPERVISOR') {
        $st = db()->prepare(
            "SELECT COUNT(*) FROM marbetes m WHERE m.id_inventario = ? AND m.sup_reconteo = ? AND m.tipo = ? AND m.estado <> 'CANCELADO'
               AND (SELECT COUNT(*) FROM conteos c WHERE c.id_marbete = m.id_marbete AND c.estado = 'CERRADO') <= m.sup_asignado_conteo"
        );
        $st->execute([$inv['id_inventario'], $u['id_usuario'], $tipo]);
        $pend = (int)$st->fetchColumn();
    }
    ?>
    <div class="panel-contador mb-3" id="panelContador" data-tipo="<?= h($tipo) ?>">
      <label class="fw-semibold mb-0" for="contadorSel">¿Quién va a contar?</label>
      <select id="contadorSel" class="form-select form-select-lg">
        <option value="">— Elige al contador —</option>
        <?php foreach ($lista as $c): ?>
          <option value="<?= (int)$c['id_usuario'] ?>" data-nombre="<?= h($c['nombre']) ?>"><?= h($c['nombre']) ?> (#<?= h($c['num_empleado']) ?>)<?= $u['rol'] === 'ADMIN' && $c['supervisor'] ? ' · ' . h($c['supervisor']) : '' ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$lista): ?>
        <span class="text-danger small"><?= $u['rol'] === 'SUPERVISOR' ? 'No tienes contadores de ' . h($tipo) . ' asignados. Pide al administrador que te los asigne.' : 'No hay contadores dados de alta.' ?></span>
      <?php endif; ?>
      <?php if ($pend): ?>
        <a class="btn btn-sm btn-danger" href="<?= h(url('supervisor/asignar.php')) ?>"><?= $pend ?> marbete(s) de 2do conteo pendientes de tu equipo</a>
      <?php endif; ?>
    </div>
    <?php
}
