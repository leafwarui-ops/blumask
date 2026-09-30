<?php
$conn = new mysqli('localhost', 'root', '', 'bd_blumask');
if ($conn->connect_error) {
    die('DB_ERROR: ' . $conn->connect_error);
}
$conn->query("UPDATE usuario SET suspenso_ate = NULL WHERE email = 'admin@blumask.com' OR nome_de_usuario = 'blumask_admin'");
echo "ADMIN_RESTORED\n";
