<?php
function ensure_community_ban_schema(mysqli $conn): bool {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = (bool) $conn->query("CREATE TABLE IF NOT EXISTS banimento_comunidade (
        id_banimento INT NOT NULL AUTO_INCREMENT,
        id_comunidade INT NOT NULL,
        id_usuario INT NOT NULL,
        id_usuario_baniu INT NOT NULL,
        data_banimento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_banimento),
        UNIQUE KEY uq_banimento_comunidade_usuario (id_comunidade, id_usuario),
        KEY idx_banimento_usuario (id_usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    return $ready;
}

function is_user_banned_from_community(mysqli $conn, int $userId, int $communityId): bool {
    if ($userId <= 0 || $communityId <= 0 || !ensure_community_ban_schema($conn)) {
        return false;
    }

    $statement = $conn->prepare("SELECT 1 FROM banimento_comunidade WHERE id_usuario = ? AND id_comunidade = ? LIMIT 1");
    if (!$statement) {
        return false;
    }

    $statement->bind_param('ii', $userId, $communityId);
    $statement->execute();
    $statement->store_result();
    $isBanned = $statement->num_rows > 0;
    $statement->close();

    return $isBanned;
}
?>