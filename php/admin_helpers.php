<?php
/**
 * Helpers para admin e controle de suspensão de usuários.
 */

function ensure_admin_schema($conn) {
    $columns = [
        ['usuario', 'is_admin', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['usuario', 'suspenso_ate', 'DATETIME NULL DEFAULT NULL'],
    ];

    foreach ($columns as [$table, $column, $definition]) {
        $exists = $conn->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $conn->real_escape_string($column) . "'");
        if ($exists && $exists->num_rows === 0) {
            $conn->query("ALTER TABLE `" . $table . "` ADD COLUMN `" . $column . "` " . $definition);
        }
    }
}

function is_user_suspended($user) {
    $suspensoAte = trim((string) ($user['suspenso_ate'] ?? ''));
    if ($suspensoAte === '') {
        return false;
    }

    $timestamp = strtotime($suspensoAte);
    if ($timestamp === false) {
        return false;
    }

    return $timestamp > time();
}

function ensure_admin_user($conn) {
    $adminEmail = 'admin@blumask.com';
    $adminDisplay = 'BluMask Admin';
    $adminUser = 'blumask_admin';
    $adminPassword = 'BluMask@Admin2026!';

    $escapedEmail = $conn->real_escape_string($adminEmail);
    $escapedUser = $conn->real_escape_string($adminUser);
    $search = $conn->query("SELECT * FROM usuario WHERE email = '$escapedEmail' OR nome_de_usuario = '$escapedUser' LIMIT 1");

    if ($search && $search->num_rows > 0) {
        $adminUserRow = $search->fetch_assoc();
        $updates = [];

        if ((int) ($adminUserRow['is_admin'] ?? 0) !== 1) {
            $updates[] = 'is_admin = 1';
        }

        if (strtolower(trim((string) ($adminUserRow['email'] ?? ''))) !== strtolower($adminEmail)) {
            $updates[] = "email = '" . $conn->real_escape_string($adminEmail) . "'";
        }

        if (trim((string) ($adminUserRow['nome_de_exibicao'] ?? '')) !== $adminDisplay) {
            $updates[] = "nome_de_exibicao = '" . $conn->real_escape_string($adminDisplay) . "'";
        }

        if (trim((string) ($adminUserRow['nome_de_usuario'] ?? '')) !== $adminUser) {
            $updates[] = "nome_de_usuario = '" . $conn->real_escape_string($adminUser) . "'";
        }

        if (!password_verify($adminPassword, (string) ($adminUserRow['senha'] ?? ''))) {
            $updates[] = "senha = '" . $conn->real_escape_string(password_hash($adminPassword, PASSWORD_DEFAULT)) . "'";
        }

        if (!empty($updates)) {
            $updateSql = "UPDATE usuario SET " . implode(', ', $updates) . " WHERE id_usuario = " . intval($adminUserRow['id_usuario']);
            $conn->query($updateSql);
        }

        return (int) $adminUserRow['id_usuario'];
    }

    $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
    $insertSql = "INSERT INTO usuario (email, nome_de_exibicao, senha, nome_de_usuario, descricao, is_admin)
        VALUES ('" . $conn->real_escape_string($adminEmail) . "', '" . $conn->real_escape_string($adminDisplay) . "', '" . $conn->real_escape_string($hashedPassword) . "', '" . $conn->real_escape_string($adminUser) . "', 'Conta administrativa do sistema BluMask.', 1)";
    $conn->query($insertSql);

    return $conn->insert_id;
}
?>
