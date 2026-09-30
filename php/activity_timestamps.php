<?php

function ensure_user_posting_limit_schema($conn) {
    static $schemaReady = null;
    if ($schemaReady !== null) {
        return $schemaReady;
    }

    $schemaReady = (bool) mysqli_query($conn, "CREATE TABLE IF NOT EXISTS limite_publicacao_usuario (
        id_usuario INT NOT NULL,
        tipo_publicacao ENUM('post', 'comentario') NOT NULL,
        data_ultima_publicacao DATETIME NOT NULL,
        PRIMARY KEY (id_usuario, tipo_publicacao),
        CONSTRAINT fk_limite_publicacao_usuario FOREIGN KEY (id_usuario)
            REFERENCES usuario(id_usuario) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    return $schemaReady;
}

function consume_user_posting_limit($conn, $userId, $action, $windowSeconds = 60) {
    $userId = (int) $userId;
    $windowSeconds = max(1, min(86400, (int) $windowSeconds));
    if ($userId <= 0 || !in_array($action, ['post', 'comentario'], true)
        || !ensure_user_posting_limit_schema($conn)) {
        return ['allowed' => false, 'retry_after' => $windowSeconds, 'error' => true];
    }

    $statement = mysqli_prepare($conn, "INSERT INTO limite_publicacao_usuario
        (id_usuario, tipo_publicacao, data_ultima_publicacao)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE data_ultima_publicacao = IF(
            data_ultima_publicacao <= DATE_SUB(NOW(), INTERVAL $windowSeconds SECOND),
            NOW(), data_ultima_publicacao
        )");
    if (!$statement) {
        return ['allowed' => false, 'retry_after' => $windowSeconds, 'error' => true];
    }

    mysqli_stmt_bind_param($statement, 'is', $userId, $action);
    $executed = mysqli_stmt_execute($statement);
    $affectedRows = mysqli_stmt_affected_rows($statement);
    mysqli_stmt_close($statement);

    if (!$executed) {
        return ['allowed' => false, 'retry_after' => $windowSeconds, 'error' => true];
    }

    if ($affectedRows > 0) {
        return ['allowed' => true, 'retry_after' => 0, 'error' => false];
    }

    $waitQuery = mysqli_prepare($conn, "SELECT GREATEST(1, $windowSeconds - TIMESTAMPDIFF(SECOND, data_ultima_publicacao, NOW()))
        AS retry_after FROM limite_publicacao_usuario WHERE id_usuario = ? AND tipo_publicacao = ? LIMIT 1");
    if (!$waitQuery) {
        return ['allowed' => false, 'retry_after' => $windowSeconds, 'error' => true];
    }

    mysqli_stmt_bind_param($waitQuery, 'is', $userId, $action);
    mysqli_stmt_execute($waitQuery);
    $waitResult = mysqli_stmt_get_result($waitQuery);
    $waitRow = $waitResult ? mysqli_fetch_assoc($waitResult) : null;
    mysqli_stmt_close($waitQuery);

    return [
        'allowed' => false,
        'retry_after' => max(1, (int) ($waitRow['retry_after'] ?? $windowSeconds)),
        'error' => false
    ];
}

function ensure_activity_timestamp_columns($conn) {
    $columns = [
        ['post', 'Data_post'],
        ['comentario', 'data_comentario']
    ];

    foreach ($columns as [$table, $column]) {
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$result) {
            return false;
        }

        $definition = mysqli_fetch_assoc($result);
        if (!$definition) {
            return false;
        }

        if (strtolower($definition['Type']) === 'date'
            && !mysqli_query($conn, "ALTER TABLE `$table` MODIFY `$column` DATETIME NULL")) {
            return false;
        }
    }

    return true;
}