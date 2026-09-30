<?php
$conn = new mysqli('localhost', 'root', '', 'bd_blumask');
if ($conn->connect_error) {
    die('DB_ERROR: ' . $conn->connect_error);
}

$conn->query("ALTER TABLE usuario ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE usuario ADD COLUMN IF NOT EXISTS suspenso_ate DATETIME NULL DEFAULT NULL");

$email = 'admin@blumask.com';
$username = 'blumask_admin';
$password = 'BluMask@Admin2026!';

$existing = $conn->query("SELECT * FROM usuario WHERE email = '" . $conn->real_escape_string($email) . "' OR nome_de_usuario = '" . $conn->real_escape_string($username) . "' LIMIT 1");

if ($existing && $existing->num_rows > 0) {
    $row = $existing->fetch_assoc();
    $updates = [];

    if ((int) ($row['is_admin'] ?? 0) !== 1) {
        $updates[] = 'is_admin = 1';
    }

    if (strtolower(trim((string) ($row['email'] ?? ''))) !== strtolower($email)) {
        $updates[] = "email = '" . $conn->real_escape_string($email) . "'";
    }

    if (trim((string) ($row['nome_de_exibicao'] ?? '')) !== 'BluMask Admin') {
        $updates[] = "nome_de_exibicao = '" . $conn->real_escape_string('BluMask Admin') . "'";
    }

    if (trim((string) ($row['nome_de_usuario'] ?? '')) !== $username) {
        $updates[] = "nome_de_usuario = '" . $conn->real_escape_string($username) . "'";
    }

    if (!password_verify($password, (string) ($row['senha'] ?? ''))) {
        $updates[] = "senha = '" . $conn->real_escape_string(password_hash($password, PASSWORD_DEFAULT)) . "'";
    }

    if (!empty($updates)) {
        $conn->query('UPDATE usuario SET ' . implode(', ', $updates) . ' WHERE id_usuario = ' . (int) $row['id_usuario']);
    }

    echo 'ADMIN_OK_EXISTING:' . (int) $row['id_usuario'] . PHP_EOL;
} else {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $sql = "INSERT INTO usuario (email, nome_de_exibicao, senha, nome_de_usuario, descricao, is_admin)
        VALUES ('" . $conn->real_escape_string($email) . "', '" . $conn->real_escape_string('BluMask Admin') . "', '" . $conn->real_escape_string($hashed) . "', '" . $conn->real_escape_string($username) . "', 'Conta administrativa do sistema BluMask.', 1)";
    $conn->query($sql);
    echo 'ADMIN_OK_NEW:' . $conn->insert_id . PHP_EOL;
}
