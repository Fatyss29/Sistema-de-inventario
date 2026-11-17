<?php
require_once __DIR__ . '/app/bootstrap.php';
iniciar_sesion();
if (uid()) bitacora('LOGOUT');
$_SESSION = [];
session_destroy();
header('Location: ' . url('login.php' . (($_GET['razon'] ?? '') === 'timeout' ? '?razon=timeout' : '')));
exit;
