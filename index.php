<?php
// Redirige a la pantalla principal de cada rol
require_once __DIR__ . '/app/bootstrap.php';
$u = requiere_login();
header('Location: ' . url($u['rol'] === 'ADMIN' ? 'admin/comparativo.php' : 'supervisor/index.php'));
exit;
