<?php
$conn = new mysqli('localhost', 'root', '', 'bd_blumask');
if ($conn->connect_error) {
    die('DB_ERROR: ' . $conn->connect_error);
}

$conn->query("ALTER TABLE usuario ADD COLUMN IF NOT EXISTS is_admin TINYINT(1) NOT NULL DEFAULT 0");
$conn->query("ALTER TABLE usuario ADD COLUMN IF NOT EXISTS suspenso_ate DATETIME NULL DEFAULT NULL");

$email = 'admin@blumask.com';
$username = 'admin';
$password = 'BluMask@Admin2026!';

$existing = $conn->query("SELECT * FROM usuario WHERE email = '" . $conn->real_escape_string($email) . "' LIMIT 1");
if (!$existing || $existing->num_rows === 0) {
    $existing = $conn->query("SELECT * FROM usuario WHERE nome_de_usuario = 'blumask_admin' AND is_admin = 1 LIMIT 1");
}

if ($existing && $existing->num_rows > 0) {
    $row = $existing->fetch_assoc();
    $updates = [];
    $handleOwner = $conn->query("SELECT id_usuario FROM usuario WHERE nome_de_usuario = 'admin' LIMIT 1");
    $handleOwnerRow = $handleOwner ? $handleOwner->fetch_assoc() : null;
    $canUseAdminHandle = !$handleOwnerRow || (int) $handleOwnerRow['id_usuario'] === (int) $row['id_usuario'];

    if ((int) ($row['is_admin'] ?? 0) !== 1) {
        $updates[] = 'is_admin = 1';
    }

    if (strtolower(trim((string) ($row['email'] ?? ''))) !== strtolower($email)) {
        $updates[] = "email = '" . $conn->real_escape_string($email) . "'";
    }

    if (trim((string) ($row['nome_de_exibicao'] ?? '')) !== 'admin') {
        $updates[] = "nome_de_exibicao = 'admin'";
    }

    if ($canUseAdminHandle && trim((string) ($row['nome_de_usuario'] ?? '')) !== $username) {
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
    $handleOwner = $conn->query("SELECT id_usuario FROM usuario WHERE nome_de_usuario = 'admin' LIMIT 1");
    if ($handleOwner && $handleOwner->num_rows > 0) {
        exit('ADMIN_HANDLE_TAKEN' . PHP_EOL);
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $sql = "INSERT INTO usuario (email, nome_de_exibicao, senha, nome_de_usuario, descricao, is_admin)
        VALUES ('" . $conn->real_escape_string($email) . "', 'admin', '" . $conn->real_escape_string($hashed) . "', '" . $conn->real_escape_string($username) . "', 'Conta administrativa do sistema BluMask.', 1)";
    $conn->query($sql);
    echo 'ADMIN_OK_NEW:' . $conn->insert_id . PHP_EOL;
}
