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

function ensure_notification_schema($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS notificacao (
        id_notificacao INT PRIMARY KEY AUTO_INCREMENT,
        id_usuario INT NOT NULL,
        id_remetente INT NULL,
        id_post INT NULL,
        id_comentario INT NULL,
        tipo VARCHAR(40) NOT NULL DEFAULT 'comentario',
        mensagem TEXT NOT NULL,
        lida TINYINT(1) NOT NULL DEFAULT 0,
        criada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_notificacao_comentario (id_usuario, id_post, id_comentario, tipo),
        KEY idx_notificacao_usuario_lida (id_usuario, lida, criada_em),
        KEY idx_notificacao_post (id_post),
        CONSTRAINT fk_notificacao_destinatario FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE,
        CONSTRAINT fk_notificacao_remetente FOREIGN KEY (id_remetente) REFERENCES usuario(id_usuario) ON DELETE SET NULL,
        CONSTRAINT fk_notificacao_post FOREIGN KEY (id_post) REFERENCES post(id_post) ON DELETE CASCADE,
        CONSTRAINT fk_notificacao_comentario FOREIGN KEY (id_comentario) REFERENCES comentario(id_comentario) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    return $conn->query($sql) !== false;
}

function create_post_comment_notification(mysqli $conn, int $destinatarioId, int $remetenteId, int $postId, int $comentarioId, ?string $mensagem = null): bool {
    if ($destinatarioId <= 0 || $remetenteId <= 0 || $postId <= 0 || $comentarioId <= 0 || $destinatarioId === $remetenteId) {
        return false;
    }

    ensure_notification_schema($conn);

    $textoMensagem = trim((string) ($mensagem ?? ''));
    if ($textoMensagem === '') {
        $textoMensagem = 'comentou no seu post.';
    }

    $tipo = 'comentario';
    $mensagemEscapada = $conn->real_escape_string($textoMensagem);

    $stmt = $conn->prepare("INSERT INTO notificacao (id_usuario, id_remetente, id_post, id_comentario, tipo, mensagem, lida, criada_em)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE mensagem = VALUES(mensagem), lida = 0, criada_em = NOW()");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('iiiiss', $destinatarioId, $remetenteId, $postId, $comentarioId, $tipo, $mensagemEscapada);
    $executado = $stmt->execute();
    $stmt->close();

    return $executado;
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

function delete_user_account_data(mysqli $conn, int $userId, array $user, ?string &$failureReason = null): bool {
    if ($userId <= 0) {
        return false;
    }

    require_once __DIR__ . '/profile_pins.php';
    require_once __DIR__ . '/community_bans.php';
    require_once __DIR__ . '/admin_message_store.php';

    try {
        ensure_profile_pin_tables($conn);
        if (!ensure_community_ban_schema($conn) || !ensure_admin_message_schema($conn)) {
            throw new RuntimeException('Não foi possível preparar os dados vinculados à conta.');
        }

        $hasColumn = static function (string $table, string $column) use ($conn): bool {
            $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            if (!$result) {
                throw new RuntimeException('Não foi possível verificar a estrutura do banco.');
            }
            return $result->num_rows > 0;
        };

        $hasLegacyUserPostPin = $hasColumn('usuario', 'id_post_fixado');
        $hasLegacyUserCommentPin = $hasColumn('usuario', 'id_comentario_fixado');
        $hasPostCommentPin = $hasColumn('post', 'id_comentario_fixado');
        $hasCommunityPostPin = $hasColumn('comunidade', 'id_post_fixado');

        $conn->begin_transaction();
        $run = static function (string $sql) use ($conn): void {
            if (!$conn->query($sql)) {
                throw new RuntimeException($conn->error ?: 'Falha ao remover dados da conta.');
            }
        };

        $run("DELETE pp FROM perfil_post_fixado pp
            LEFT JOIN post p ON p.id_post = pp.id_post
            WHERE pp.id_usuario = $userId OR p.id_usuario = $userId");
        $run("DELETE pc FROM perfil_comentario_fixado pc
            LEFT JOIN comentario c ON c.id_comentario = pc.id_comentario
            LEFT JOIN post p ON p.id_post = c.id_post
            WHERE pc.id_usuario = $userId OR c.id_usuario = $userId OR p.id_usuario = $userId");

        if ($hasLegacyUserPostPin) {
            $run("UPDATE usuario u LEFT JOIN post p ON p.id_post = u.id_post_fixado
                SET u.id_post_fixado = NULL
                WHERE u.id_usuario = $userId OR p.id_usuario = $userId");
        }

        if ($hasLegacyUserCommentPin) {
            $run("UPDATE usuario u
                LEFT JOIN comentario c ON c.id_comentario = u.id_comentario_fixado
                LEFT JOIN post p ON p.id_post = c.id_post
                SET u.id_comentario_fixado = NULL
                WHERE u.id_usuario = $userId OR c.id_usuario = $userId OR p.id_usuario = $userId");
        }

        if ($hasPostCommentPin) {
            $run("UPDATE post target_post
                LEFT JOIN comentario pinned_comment ON pinned_comment.id_comentario = target_post.id_comentario_fixado
                LEFT JOIN post comment_post ON comment_post.id_post = pinned_comment.id_post
                SET target_post.id_comentario_fixado = NULL
                WHERE target_post.id_usuario = $userId
                   OR pinned_comment.id_usuario = $userId
                   OR comment_post.id_usuario = $userId");
        }

        if ($hasCommunityPostPin) {
            $run("UPDATE comunidade c
                LEFT JOIN post p ON p.id_post = c.id_post_fixado
                SET c.id_post_fixado = NULL
                WHERE p.id_usuario = $userId");
        }
        $run("DELETE l FROM curtida l
            LEFT JOIN post p ON p.id_post = l.id_post
            LEFT JOIN comunidade c ON c.id_comunidade = p.id_comunidade
            WHERE l.id_usuario = $userId
               OR p.id_usuario = $userId
               OR c.id_usuario = $userId");
        $run("DELETE c FROM comentario c
            LEFT JOIN post p ON p.id_post = c.id_post
            LEFT JOIN comunidade comu ON comu.id_comunidade = p.id_comunidade
            WHERE c.id_usuario = $userId
               OR p.id_usuario = $userId
               OR comu.id_usuario = $userId");
        $run("DELETE p FROM post p
            LEFT JOIN comunidade comu ON comu.id_comunidade = p.id_comunidade
            WHERE p.id_usuario = $userId OR comu.id_usuario = $userId");
        $run("DELETE FROM membro_comunidade WHERE id_usuario = $userId OR id_comunidade IN (SELECT id_comunidade FROM comunidade WHERE id_usuario = $userId)");
        $run("DELETE FROM banimento_comunidade WHERE id_usuario = $userId OR id_usuario_baniu = $userId OR id_comunidade IN (SELECT id_comunidade FROM comunidade WHERE id_usuario = $userId)");
        $run("DELETE FROM notificacao WHERE id_usuario = $userId OR id_remetente = $userId");
        $run("DELETE FROM mensagem_administrativa WHERE id_destinatario = $userId OR id_remetente = $userId");
        $run("DELETE FROM comunidade WHERE id_usuario = $userId");
        $run("DELETE FROM usuario WHERE id_usuario = $userId AND is_admin = 0");

        if ($conn->affected_rows !== 1) {
            throw new RuntimeException('A conta não foi removida.');
        }

        $conn->commit();
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }
        error_log('Falha ao excluir conta ' . $userId . ': ' . $error->getMessage());
        $failureReason = $error->getMessage();
        return false;
    }

    $uploadDirectories = [
        ['uploads/avatars/', $user['foto_perfil'] ?? ''],
        ['uploads/banners/', $user['banner'] ?? '']
    ];
    foreach ($uploadDirectories as [$prefix, $relativePath]) {
        $relativePath = str_replace('\\', '/', trim((string) $relativePath));
        if (strpos($relativePath, $prefix) !== 0) {
            continue;
        }
        $basePath = realpath(dirname(__DIR__) . '/' . rtrim($prefix, '/'));
        $filePath = realpath(dirname(__DIR__) . '/' . $relativePath);
        if ($basePath && $filePath
            && strpos(strtolower($filePath), strtolower($basePath . DIRECTORY_SEPARATOR)) === 0
            && is_file($filePath)) {
            @unlink($filePath);
        }
    }

    return true;
}

function is_site_admin(mysqli $conn, int $userId): bool {
    if ($userId <= 0) {
        return false;
    }

    $statement = $conn->prepare("SELECT is_admin FROM usuario WHERE id_usuario = ? LIMIT 1");
    if (!$statement) {
        return false;
    }

    $statement->bind_param('i', $userId);
    $statement->execute();
    $result = $statement->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $statement->close();

    return (int) ($user['is_admin'] ?? 0) === 1;
}

function ensure_admin_user($conn) {
    $adminEmail = 'admin@blumask.com';
    $adminDisplay = 'admin';
    $adminUser = 'admin';
    $adminPassword = 'BluMask@Admin2026!';

    $escapedEmail = $conn->real_escape_string($adminEmail);
    $search = $conn->query("SELECT * FROM usuario WHERE email = '$escapedEmail' LIMIT 1");
    if (!$search || $search->num_rows === 0) {
        $search = $conn->query("SELECT * FROM usuario WHERE nome_de_usuario = 'blumask_admin' AND is_admin = 1 LIMIT 1");
    }

    if ($search && $search->num_rows > 0) {
        $adminUserRow = $search->fetch_assoc();
        $updates = [];
        $handleOwner = $conn->query("SELECT id_usuario FROM usuario WHERE nome_de_usuario = 'admin' LIMIT 1");
        $handleOwnerRow = $handleOwner ? $handleOwner->fetch_assoc() : null;
        $canUseAdminHandle = !$handleOwnerRow
            || (int) $handleOwnerRow['id_usuario'] === (int) $adminUserRow['id_usuario'];

        if ((int) ($adminUserRow['is_admin'] ?? 0) !== 1) {
            $updates[] = 'is_admin = 1';
        }

        if (strtolower(trim((string) ($adminUserRow['email'] ?? ''))) !== strtolower($adminEmail)) {
            $updates[] = "email = '" . $conn->real_escape_string($adminEmail) . "'";
        }

        if (trim((string) ($adminUserRow['nome_de_exibicao'] ?? '')) !== $adminDisplay) {
            $updates[] = "nome_de_exibicao = '" . $conn->real_escape_string($adminDisplay) . "'";
        }

        if ($canUseAdminHandle && trim((string) ($adminUserRow['nome_de_usuario'] ?? '')) !== $adminUser) {
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

    $handleOwner = $conn->query("SELECT id_usuario FROM usuario WHERE nome_de_usuario = 'admin' LIMIT 1");
    if ($handleOwner && $handleOwner->num_rows > 0) {
        return 0;
    }

    $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
    $insertSql = "INSERT INTO usuario (email, nome_de_exibicao, senha, nome_de_usuario, descricao, is_admin)
        VALUES ('" . $conn->real_escape_string($adminEmail) . "', '" . $conn->real_escape_string($adminDisplay) . "', '" . $conn->real_escape_string($hashedPassword) . "', '" . $conn->real_escape_string($adminUser) . "', 'Conta administrativa do sistema BluMask.', 1)";
    $conn->query($insertSql);

    return $conn->insert_id;
}
?>
