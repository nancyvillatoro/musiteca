<?php
require_once __DIR__ . '/includes/auth.php';
cerrarSesion();
header('Location: login.php');
exit;
