<?php
session_start();
$_SESSION['usuario'] = ['id_usuario' => 13];
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

$conn = new mysqli('localhost', 'root', '', 'bd_blumask');
if ($conn->connect_error) { die('DB_ERROR: ' . $conn->connect_error); }
$conn->query("UPDATE usuario SET suspenso_ate = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id_usuario = 13");

require __DIR__ . '/php/security_headers.php';
