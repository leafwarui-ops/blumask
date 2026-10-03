<?php
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (is_string($requestPath) && preg_match('#/admin\.php$#i', $requestPath)) {
    $appRootPath = rtrim(dirname(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/admin.php'))), '/');
    $canonicalAdmin = ($appRootPath === '' ? '' : $appRootPath) . '/admin';
    $queryString = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));
    if ($queryString !== '') {
        $canonicalAdmin .= '?' . $queryString;
    }
    header('Location: ' . $canonicalAdmin, true, 301);
    exit;
}

require_once __DIR__ . '/php/security_headers.php';
require_once __DIR__ . '/php/bd.php';
require_once __DIR__ . '/php/admin_helpers.php';
require_once __DIR__ . '/php/admin_message_store.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

ensure_legacy_schema_compatibility($conn);
ensure_community_slug_column($conn);
ensure_admin_user($conn);

$adminMessage = '';
$activeTab = (string) ($_REQUEST['active_tab'] ?? 'users');
if (!in_array($activeTab, ['users', 'communities'], true)) {
    $activeTab = 'users';
}

if (!isset($_SESSION['usuario']) || empty($_SESSION['usuario']['id_usuario'])) {
    header('Location: ./');
    exit;
}

$currentUserId = (int) $_SESSION['usuario']['id_usuario'];
$currentAdminId = $currentUserId;
$currentUser = $_SESSION['usuario'];

if ((int) ($currentUser['is_admin'] ?? 0) !== 1) {
    header('Location: ./?acesso=negado');
    exit;
}

if (is_user_suspended($currentUser)) {
    session_destroy();
    header('Location: ./?admin=suspenso');
    exit;
}

ensure_admin_message_schema($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_same_origin_request()) {
        $adminMessage = 'Origem da requisição inválida. Ação bloqueada por segurança.';
    } elseif (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $adminMessage = 'Token de segurança inválido. Tente novamente.';
    } else {
        $action = $_POST['action'] ?? '';
        $targetId = (int) ($_POST['user_id'] ?? 0);

        if ($action === 'delete_community') {
            $communityId = (int) ($_POST['community_id'] ?? 0);
            if ($communityId <= 0) {
                $adminMessage = 'Selecione uma comunidade válida para excluir.';
            } else {
                $communityCheck = $conn->query("SELECT id_comunidade, nome FROM comunidade WHERE id_comunidade = $communityId LIMIT 1");
                if (!$communityCheck || $communityCheck->num_rows === 0) {
                    $adminMessage = 'Comunidade não encontrada.';
                } else {
                    $communityData = $communityCheck->fetch_assoc();
                    $conn->begin_transaction();
                    try {
                        $posts = $conn->query("SELECT id_post FROM post WHERE id_comunidade = $communityId");
                        if ($posts) {
                            while ($post = $posts->fetch_assoc()) {
                                $postId = (int) ($post['id_post'] ?? 0);
                                if ($postId > 0) {
                                    $conn->query("DELETE FROM curtida WHERE id_post = $postId");
                                    $conn->query("DELETE FROM comentario WHERE id_post = $postId");
                                }
                            }
                        }

                        $conn->query("DELETE FROM post WHERE id_comunidade = $communityId");
                        $conn->query("DELETE FROM membro_comunidade WHERE id_comunidade = $communityId");
                        $conn->query("DELETE FROM banimento_comunidade WHERE id_comunidade = $communityId");
                        $conn->query("DELETE FROM comunidade WHERE id_comunidade = $communityId");
                        $conn->commit();
                        $adminMessage = 'Comunidade "' . htmlspecialchars((string) ($communityData['nome'] ?? 'Comunidade'), ENT_QUOTES, 'UTF-8') . '" excluída com sucesso.';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $adminMessage = 'Não foi possível excluir a comunidade.';
                    }
                }
            }
        } elseif ($targetId <= 0 || $targetId === $currentUserId) {
            $adminMessage = 'Selecione um usuário válido para executar a ação.';
        } else {
            $targetUser = $conn->query("SELECT * FROM usuario WHERE id_usuario = $targetId LIMIT 1");
            if (!$targetUser || $targetUser->num_rows === 0) {
                $adminMessage = 'Usuário não encontrado.';
            } else {
                $targetData = $targetUser->fetch_assoc();

                if ($action === 'suspend_user') {
                    $expiresAt = date('Y-m-d H:i:s', time() + 600);
                    $conn->query("UPDATE usuario SET suspenso_ate = '" . $conn->real_escape_string($expiresAt) . "' WHERE id_usuario = $targetId");
                    $adminMessage = 'Usuário suspenso por 10 minutos.';
                } elseif ($action === 'send_message') {
                    $messageText = trim((string) ($_POST['admin_message'] ?? ''));
                    if ((int) ($targetData['is_admin'] ?? 0) === 1) {
                        $adminMessage = 'Mensagens só podem ser enviadas a usuários comuns.';
                    } elseif ($messageText === '' || mb_strlen($messageText, 'UTF-8') > 2000) {
                        $adminMessage = 'A mensagem deve ter entre 1 e 2000 caracteres.';
                    } else {
                        $sendMessage = $conn->prepare("INSERT INTO mensagem_administrativa (id_destinatario, id_remetente, mensagem) VALUES (?, ?, ?)");
                        if ($sendMessage) {
                            $sendMessage->bind_param('iis', $targetId, $currentAdminId, $messageText);
                            if ($sendMessage->execute()) {
                                $adminMessage = 'Mensagem enviada para o usuário.';
                            } else {
                                $adminMessage = 'Não foi possível enviar a mensagem.';
                            }
                            $sendMessage->close();
                        } else {
                            $adminMessage = 'Não foi possível enviar a mensagem.';
                        }
                    }
                } elseif ($action === 'delete_user') {
                    if ((int) ($targetData['is_admin'] ?? 0) === 1) {
                        $adminMessage = 'Não é possível excluir a conta do administrador.';
                    } else {
                        $deleteFailureReason = null;
                        if (delete_user_account_data($conn, $targetId, $targetData, $deleteFailureReason)) {
                            $adminMessage = 'Conta, posts, comentários e dados vinculados excluídos com sucesso.';
                        } else {
                            $adminMessage = 'Não foi possível excluir a conta; nenhuma alteração foi mantida.';
                            if ($deleteFailureReason !== null && $deleteFailureReason !== '') {
                                $adminMessage .= ' Erro do banco: ' . $deleteFailureReason;
                            }
                        }
                    }
                } elseif ($action === 'unsuspend_user') {
                    if ((int) ($targetData['is_admin'] ?? 0) === 1) {
                        $adminMessage = 'Não é possível remover a suspensão do administrador.';
                    } else {
                        $conn->query("UPDATE usuario SET suspenso_ate = NULL WHERE id_usuario = $targetId");
                        $adminMessage = 'Suspensão removida com sucesso.';
                    }
                }
            }
        }
    }
}

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$activeUserFilter = "TRIM(COALESCE(nome_de_exibicao, '')) <> 'Usuário deletado'
    AND LEFT(COALESCE(nome_de_usuario, ''), 17) <> 'usuario_deletado_'";
$usersSql = "SELECT * FROM usuario WHERE $activeUserFilter ORDER BY is_admin DESC, nome_de_exibicao ASC";
if ($searchTerm !== '') {
    $term = $conn->real_escape_string('%' . $searchTerm . '%');
    $usersSql = "SELECT * FROM usuario
        WHERE $activeUserFilter
        AND (nome_de_exibicao LIKE '$term' OR nome_de_usuario LIKE '$term' OR email LIKE '$term')
        ORDER BY is_admin DESC, nome_de_exibicao ASC";
}

$usersResult = $conn->query($usersSql);
$users = [];
if ($usersResult) {
    while ($row = $usersResult->fetch_assoc()) {
        $users[] = $row;
    }
}

$usersSnapshot = array_map(static function ($user) {
    return [
        (int) ($user['id_usuario'] ?? 0),
        (string) ($user['nome_de_exibicao'] ?? ''),
        (string) ($user['nome_de_usuario'] ?? ''),
        (string) ($user['email'] ?? ''),
        (int) ($user['is_admin'] ?? 0),
        (string) ($user['suspenso_ate'] ?? ''),
        is_user_suspended($user)
    ];
}, $users);

if (($_GET['ajax'] ?? '') === 'users_refresh') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($usersSnapshot);
    exit;
}

$communitySearchTerm = trim((string) ($_GET['community_q'] ?? ''));
$communitiesSql = "SELECT c.id_comunidade, c.nome, c.slug, c.descricao, c.imagem, c.id_usuario, u.nome_de_exibicao AS criador_nome, u.nome_de_usuario AS criador_usuario, COUNT(mc.id_usuario) AS total_membros
    FROM comunidade c
    LEFT JOIN usuario u ON u.id_usuario = c.id_usuario
    LEFT JOIN membro_comunidade mc ON mc.id_comunidade = c.id_comunidade
    GROUP BY c.id_comunidade, c.nome, c.slug, c.descricao, c.imagem, c.id_usuario, u.nome_de_exibicao, u.nome_de_usuario";
if ($communitySearchTerm !== '') {
    $term = $conn->real_escape_string('%' . $communitySearchTerm . '%');
    $communitiesSql .= " HAVING c.nome LIKE '$term' OR c.descricao LIKE '$term' OR u.nome_de_exibicao LIKE '$term' OR u.nome_de_usuario LIKE '$term'";
}
$communitiesSql .= " ORDER BY c.nome ASC";

$communitiesResult = $conn->query($communitiesSql);
$communities = [];
if ($communitiesResult) {
    while ($row = $communitiesResult->fetch_assoc()) {
        $communities[] = $row;
    }
}

$csrfToken = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin — BluMask</title>
    <link rel="icon" type="image/webp" href="style/blumaskWhiteLogo.webp">
    <style>
        :root {
            --admin-bg: #b9d7ff;
            --panel: #ffffff;
            --panel-soft: #edf6ff;
            --border: #dbe5f4;
            --primary: #567fd9;
            --primary-dark: #2f5bb9;
            --danger: #d92027;
            --warning: #ffb703;
            --success: #2a8f5b;
            --text: #1d2a39;
            --muted: #64748b;
            --shadow: rgba(17, 24, 39, 0.12);
        }

        * { box-sizing: border-box; }
        html, body {
            min-height: 100%;
            height: 100%;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #b9d7ff 0%, #d9ecff 100%);
            color: var(--text);
        }

        .admin-shell {
            flex: 1 0 auto;
            max-width: 1200px;
            width: min(100%, 1200px);
            margin: 28px auto 0;
            padding: 24px;
        }

        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            background: var(--primary);
            border: 1px solid rgba(0,0,0,0.04);
            border-radius: 18px;
            padding: 18px 24px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.08);
            color: #ffffff;
        }

        .admin-header h1 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 800;
            color: #fff;
        }

        .admin-header .admin-links {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .admin-header .home-link,
        .admin-header .logout-link {
            text-decoration: none;
            color: var(--text);
            font-weight: 700;
            min-height: 42px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 9px 16px;
            border-radius: 10px;
            border: 1px solid rgba(17, 24, 39, 0.08);
            background: #ffffff;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
        }

        .admin-header .logout-link {
            background: #ef4444;
            color: #fff;
            border-color: transparent;
        }

        .admin-header .home-link:hover,
        .admin-header .logout-link:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(17,24,39,0.12);
        }

        .admin-panel {
            margin-top: 24px;
            background: rgba(255,255,255,0.92);
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: 0 8px 18px var(--shadow);
            padding: 22px;
        }

        .admin-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }

        .admin-tab {
            border: 1px solid var(--border);
            background: #f7fafe;
            color: var(--text);
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.18s ease;
        }

        .admin-tab.is-active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            box-shadow: 0 6px 12px rgba(86, 127, 217, 0.18);
        }

        .admin-tab-panel {
            display: none;
        }

        .admin-tab-panel.is-active {
            display: block;
        }

        .admin-table .community-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
        }

        .community-delete-btn {
            background: #d94343;
            color: #fff;
            border: none;
            border-radius: 9px;
            padding: 8px 12px;
            font-weight: 700;
            cursor: pointer;
            min-height: 38px;
        }

        .community-delete-btn:hover {
            background: #b92f2f;
        }

        .admin-search-form {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .admin-search-form input {
            flex: 1;
            min-width: 240px;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 1rem;
            background: #f8fbff;
        }

        .admin-search-form button {
            border: none;
            border-radius: 10px;
            background: var(--primary);
            color: white;
            font-weight: 700;
            padding: 11px 18px;
            cursor: pointer;
            box-shadow: 0 3px 8px rgba(46, 92, 180, 0.18);
            transition: background .18s ease, transform .18s ease;
        }

        .admin-search-form button:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .admin-message {
            margin-bottom: 20px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #eaf4ff;
            border: 1px solid #bfe0ff;
            color: #0f3c82;
            font-weight: 700;
        }

        .admin-table-wrapper {
            overflow-x: auto;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: #fff;
        }

        table.admin-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 820px;
        }

        .admin-table th,
        .admin-table td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            vertical-align: middle;
        }

        .admin-table th {
            background: #f4f9ff;
            color: var(--muted);
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .user-name {
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .user-name-link,
        .community-name-link {
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
        }

        .user-name-link:hover,
        .community-name-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .badge-admin {
            background: #eaf3ff;
            color: var(--primary-dark);
        }

        .badge-suspended {
            background: #fff4d6;
            color: #8a6200;
        }

        .status-active {
            color: var(--success);
            font-weight: 700;
        }

        .status-suspended {
            color: #b45309;
            font-weight: 700;
        }

        .button-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .action-btn {
            border: none;
            border-radius: 9px;
            min-height: 38px;
            padding: 8px 12px;
            font-weight: 700;
            cursor: pointer;
            color: white;
            transition: transform 0.18s ease, box-shadow 0.18s ease, background .18s ease;
            box-shadow: 0 2px 5px rgba(17, 24, 39, 0.12);
            white-space: nowrap;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 10px rgba(17, 24, 39, 0.16);
        }

        .suspend-btn {
            background: #f2b544;
            color: #2d1600;
        }

        .delete-btn {
            background: #d94343;
        }

        .message-btn {
            border: 1px solid #b9cbed;
            background: #edf4ff;
            color: #294c83;
        }

        .message-btn:hover {
            background: #dceaff;
        }

        .admin-modal {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(16, 29, 48, .58);
        }

        .admin-modal.open { display: flex; }

        .admin-modal-card {
            width: min(100%, 480px);
            padding: 24px;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 20px 56px rgba(10, 24, 42, .28);
        }

        .admin-modal-card h2 { margin: 0 0 6px; font-size: 1.2rem; }
        .admin-modal-recipient { margin: 0 0 16px; color: var(--muted); }
        .admin-modal-card textarea {
            width: 100%;
            min-height: 150px;
            resize: vertical;
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 9px;
            font: inherit;
        }
        .admin-modal-card textarea:focus {
            outline: 2px solid rgba(86, 127, 217, .25);
            border-color: var(--primary);
        }
        .admin-modal-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 14px;
        }
        .admin-modal-actions button {
            min-height: 40px;
            padding: 9px 15px;
            border: 0;
            border-radius: 9px;
            font-weight: 700;
            cursor: pointer;
        }
        .admin-modal-cancel { background: #edf2f7; color: #334155; }
        .admin-modal-submit { background: var(--primary); color: #fff; }

        .button-stack form { margin: 0; }

        .admin-header .home-link:hover { background: #f0f6ff; }
        .admin-header .logout-link:hover { background: #d93434; }

        .admin-modal-card textarea::placeholder { color: #8a96a6; }

        .admin-modal-counter {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: .8rem;
            text-align: right;
        }

        .disabled-label {
            color: var(--muted);
            font-weight: 600;
        }

        .admin-footer {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 14px 32px;
            background: #e2e2e2;
            margin-top: auto;
        }

        .admin-footer strong {
            color: #1c1c1c;
            font-size: .95rem;
        }

        .admin-footer svg {
            width: 18px;
            height: 18px;
        }

        @media (max-width: 768px) {
            .admin-header {
                flex-direction: column;
                align-items: stretch;
            }

            .admin-header .admin-links {
                width: 100%;
            }

            .admin-header .admin-links a {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div class="admin-shell">
        <header class="admin-header">
            <h1>Painel de Administração</h1>
            <div class="admin-links">
                <a class="home-link" href="./">Voltar</a>
                <a class="logout-link" href="./?logout=1">Sair</a>
            </div>
        </header>

        <section class="admin-panel">
            <div class="admin-tabs" role="tablist" aria-label="Abas do painel administrativo">
                <button type="button" class="admin-tab <?= $activeTab === 'users' ? 'is-active' : '' ?>" data-tab="users" role="tab" aria-selected="<?= $activeTab === 'users' ? 'true' : 'false' ?>">Usuários</button>
                <button type="button" class="admin-tab <?= $activeTab === 'communities' ? 'is-active' : '' ?>" data-tab="communities" role="tab" aria-selected="<?= $activeTab === 'communities' ? 'true' : 'false' ?>">Comunidades</button>
            </div>

            <?php if (!empty($adminMessage)): ?>
                <div class="admin-message"><?= htmlspecialchars($adminMessage, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div id="users-panel" class="admin-tab-panel <?= $activeTab === 'users' ? 'is-active' : '' ?>" role="tabpanel">
                <form method="get" class="admin-search-form">
                    <input type="hidden" name="active_tab" value="users">
                    <input type="search" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8') ?>" placeholder="Pesquisar usuário por nome, nickname ou e-mail" aria-label="Buscar usuário" />
                    <button type="submit">Buscar</button>
                </form>

                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Usuário</th>
                                <th>Nickname</th>
                                <th>E-mail</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="5">Nenhum usuário encontrado.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <?php
                                        $userId = (int) ($user['id_usuario'] ?? 0);
                                        $isAdminUser = (int) ($user['is_admin'] ?? 0) === 1;
                                        $isSuspended = is_user_suspended($user);
                                        $displayName = trim((string) ($user['nome_de_exibicao'] ?? 'Usuário'));
                                        $displayNameHtml = htmlspecialchars($displayName !== '' ? $displayName : 'Usuário', ENT_QUOTES, 'UTF-8');
                                        $userHandle = trim((string) ($user['nome_de_usuario'] ?? ''));
                                        $userEmail = htmlspecialchars(trim((string) ($user['email'] ?? '')), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="user-name">
                                                <a href="usuario/<?= rawurlencode(strtolower(trim((string) ($user['nome_de_usuario'] ?? '')))) ?>" class="user-name-link" title="Ver perfil de <?= $displayNameHtml ?>"><?= $displayNameHtml ?></a>
                                                <?php if ($isAdminUser): ?>
                                                    <span class="badge badge-admin">Admin</span>
                                                <?php elseif ($isSuspended): ?>
                                                    <span class="badge badge-suspended">Suspenso</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>@<?= $userHandle !== '' ? htmlspecialchars($userHandle, ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                        <td><?= $userEmail !== '' ? $userEmail : '—' ?></td>
                                        <td>
                                            <?php if ($isAdminUser): ?>
                                                <span class="status-active">Administrador</span>
                                            <?php elseif ($isSuspended): ?>
                                                <span class="status-suspended">Suspenso até <?= htmlspecialchars(date('d/m/Y H:i', strtotime($user['suspenso_ate'])), ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php else: ?>
                                                <span class="status-active">Ativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isAdminUser || $userId === $currentAdminId): ?>
                                                <span class="disabled-label">Ação indisponível</span>
                                            <?php else: ?>
                                                <div class="button-stack">
                                                    <button type="button" class="action-btn message-btn" data-user-id="<?= $userId ?>" data-user-name="<?= htmlspecialchars(trim((string) ($user['nome_de_exibicao'] ?? 'Usuário')), ENT_QUOTES, 'UTF-8') ?>">Mensagem</button>

                                                    <?php if ($isSuspended): ?>
                                                        <form method="post" class="confirm-user-action-form" data-action-type="unsuspend" data-user-name="<?= htmlspecialchars(trim((string) ($user['nome_de_exibicao'] ?? 'Usuário')), ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="unsuspend_user">
                                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                            <input type="hidden" name="active_tab" value="users">
                                                            <button type="submit" class="action-btn" style="background:#2a8f5b; color:#fff;">Remover suspensão</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="post" class="confirm-user-action-form" data-action-type="suspend" data-user-name="<?= htmlspecialchars(trim((string) ($user['nome_de_exibicao'] ?? 'Usuário')), ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="action" value="suspend_user">
                                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                            <input type="hidden" name="active_tab" value="users">
                                                            <button type="submit" class="action-btn suspend-btn">Suspender 10 min</button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <form method="post" class="confirm-user-action-form" data-action-type="delete" data-user-name="<?= htmlspecialchars(trim((string) ($user['nome_de_exibicao'] ?? 'Usuário')), ENT_QUOTES, 'UTF-8') ?>" style="display:inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="action" value="delete_user">
                                                        <input type="hidden" name="user_id" value="<?= $userId ?>">
                                                        <input type="hidden" name="active_tab" value="users">
                                                        <button type="submit" class="action-btn delete-btn">Excluir conta</button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="communities-panel" class="admin-tab-panel <?= $activeTab === 'communities' ? 'is-active' : '' ?>" role="tabpanel">
                <form method="get" class="admin-search-form">
                    <input type="hidden" name="active_tab" value="communities">
                    <input type="search" name="community_q" value="<?= htmlspecialchars($communitySearchTerm, ENT_QUOTES, 'UTF-8') ?>" placeholder="Pesquisar comunidade por nome ou criador" aria-label="Buscar comunidade" />
                    <button type="submit">Buscar</button>
                </form>

                <div class="admin-table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Comunidade</th>
                                <th>Criador</th>
                                <th>Membros</th>
                                <th>Descrição</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($communities)): ?>
                                <tr>
                                    <td colspan="5">Nenhuma comunidade encontrada.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($communities as $community): ?>
                                    <?php
                                        $communityId = (int) ($community['id_comunidade'] ?? 0);
                                        $communityName = htmlspecialchars(trim((string) ($community['nome'] ?? 'Comunidade')), ENT_QUOTES, 'UTF-8');
                                        $communitySlug = trim((string) ($community['slug'] ?? '')) !== '' ? trim((string) $community['slug']) : slugify_text((string) ($community['nome'] ?? 'comunidade'));
                                        $communityCreator = htmlspecialchars(trim((string) ($community['criador_nome'] ?? $community['criador_usuario'] ?? 'Sem criador')), ENT_QUOTES, 'UTF-8');
                                        $communityDesc = htmlspecialchars(trim((string) ($community['descricao'] ?? '')), ENT_QUOTES, 'UTF-8');
                                        $communityMembers = (int) ($community['total_membros'] ?? 0);
                                    ?>
                                    <tr>
                                        <td>
                                            <a href="comunidade/<?= rawurlencode($communitySlug) ?>" class="community-name-link" title="Abrir comunidade <?= $communityName ?>"><?= $communityName ?></a>
                                        </td>
                                        <td>
                                            <?php if (!empty($community['id_usuario'])): ?>
                                                <a href="usuario/<?= rawurlencode(strtolower(trim((string) ($community['criador_usuario'] ?? '')))) ?>" class="user-name-link" title="Ver perfil do criador"><?= $communityCreator ?></a>
                                            <?php else: ?>
                                                <?= $communityCreator ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $communityMembers ?></td>
                                        <td><?= $communityDesc !== '' ? $communityDesc : '—' ?></td>
                                        <td>
                                            <div class="community-actions">
                                                <button type="button" class="community-delete-btn" data-community-id="<?= $communityId ?>" data-community-name="<?= htmlspecialchars(trim((string) ($community['nome'] ?? 'Comunidade')), ENT_QUOTES, 'UTF-8') ?>">Excluir</button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </div>

    <footer class="admin-footer">
        <strong>BluMask</strong>
        <svg viewBox="0 0 24 24" fill="none" stroke="#1c1c1c" stroke-width="1.5" aria-label="Direitos autorais"><circle cx="12" cy="12" r="10"/><path d="M15 9.5a3.5 3.5 0 1 0 0 5"/></svg>
    </footer>

    <div class="admin-modal" id="adminMessageModal" aria-hidden="true">
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="adminMessageTitle">
            <h2 id="adminMessageTitle">Enviar mensagem</h2>
            <p class="admin-modal-recipient" id="adminMessageRecipient"></p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="user_id" id="adminMessageUserId" value="">
                <input type="hidden" name="active_tab" value="users">
                <textarea name="admin_message" id="adminMessageText" maxlength="2000" placeholder="Escreva sua mensagem..." required></textarea>
                <p class="admin-modal-counter"><span id="adminMessageCount">0</span>/2000</p>
                <div class="admin-modal-actions">
                    <button type="button" class="admin-modal-cancel" id="adminMessageCancel">Cancelar</button>
                    <button type="submit" class="admin-modal-submit">Enviar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-modal" id="communityDeleteModal" aria-hidden="true">
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="communityDeleteTitle">
            <h2 id="communityDeleteTitle">Excluir comunidade</h2>
            <p class="admin-modal-recipient" id="communityDeleteText">Tem certeza que deseja excluir esta comunidade? Esta ação removerá membros, posts e comentários relacionados.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="delete_community">
                <input type="hidden" name="community_id" id="communityDeleteId" value="">
                <input type="hidden" name="active_tab" value="communities">
                <div class="admin-modal-actions">
                    <button type="button" class="admin-modal-cancel" id="communityDeleteCancel">Cancelar</button>
                    <button type="submit" class="admin-modal-submit" style="background:#d94343;">Excluir</button>
                </div>
            </form>
        </div>
    </div>

    <div class="admin-modal" id="userActionModal" aria-hidden="true">
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="userActionTitle">
            <h2 id="userActionTitle">Confirmar ação</h2>
            <p class="admin-modal-recipient" id="userActionText"></p>
            <form method="post" id="userActionForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" id="userActionType" value="">
                <input type="hidden" name="user_id" id="userActionUserId" value="">
                <input type="hidden" name="active_tab" value="users">
                <div class="admin-modal-actions">
                    <button type="button" class="admin-modal-cancel" id="userActionCancel">Cancelar</button>
                    <button type="submit" class="admin-modal-submit" id="userActionSubmit">Confirmar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const messageModal = document.getElementById('adminMessageModal');
        const messageInput = document.getElementById('adminMessageText');
        const messageCount = document.getElementById('adminMessageCount');
        const communityDeleteModal = document.getElementById('communityDeleteModal');
        const communityDeleteId = document.getElementById('communityDeleteId');
        const communityDeleteText = document.getElementById('communityDeleteText');
        const userActionModal = document.getElementById('userActionModal');
        const userActionForm = document.getElementById('userActionForm');
        const userActionType = document.getElementById('userActionType');
        const userActionUserId = document.getElementById('userActionUserId');
        const userActionText = document.getElementById('userActionText');
        const userActionSubmit = document.getElementById('userActionSubmit');

        document.querySelectorAll('.message-btn').forEach((button) => {
            button.addEventListener('click', () => {
                document.getElementById('adminMessageUserId').value = button.dataset.userId;
                document.getElementById('adminMessageRecipient').textContent = `Para ${button.dataset.userName}`;
                messageInput.value = '';
                messageCount.textContent = '0';
                messageModal.classList.add('open');
                messageModal.setAttribute('aria-hidden', 'false');
                messageInput.focus();
            });
        });

        document.querySelectorAll('.confirm-user-action-form').forEach((form) => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                const actionType = form.dataset.actionType;
                const userName = form.dataset.userName || 'este usuário';
                const userId = form.querySelector('input[name="user_id"]').value;

                if (actionType === 'unsuspend') {
                    userActionType.value = 'unsuspend_user';
                    userActionText.textContent = `Tem certeza que deseja remover a suspensão do usuário "${userName}"?`;
                    userActionSubmit.textContent = 'Remover suspensão';
                    userActionSubmit.style.background = '#2a8f5b';
                    userActionSubmit.style.color = '#fff';
                } else if (actionType === 'delete') {
                    userActionType.value = 'delete_user';
                    userActionText.textContent = `Tem certeza que deseja excluir a conta de "${userName}"? Essa ação removerá o usuário e os dados relacionados.`;
                    userActionSubmit.textContent = 'Excluir conta';
                    userActionSubmit.style.background = '#d94343';
                    userActionSubmit.style.color = '#fff';
                } else {
                    userActionType.value = 'suspend_user';
                    userActionText.textContent = `Tem certeza que deseja suspender por 10 minutos o usuário "${userName}"?`;
                    userActionSubmit.textContent = 'Suspender';
                    userActionSubmit.style.background = '#f2b544';
                    userActionSubmit.style.color = '#2d1600';
                }
                userActionUserId.value = userId;
                userActionForm.action = 'admin';
                userActionModal.classList.add('open');
                userActionModal.setAttribute('aria-hidden', 'false');
            });
        });

        document.querySelectorAll('.community-delete-btn').forEach((button) => {
            button.addEventListener('click', () => {
                communityDeleteId.value = button.dataset.communityId;
                communityDeleteText.textContent = `Tem certeza que deseja excluir a comunidade "${button.dataset.communityName}"? Esta ação removerá membros, posts e comentários relacionados.`;
                communityDeleteModal.classList.add('open');
                communityDeleteModal.setAttribute('aria-hidden', 'false');
            });
        });

        messageInput.addEventListener('input', () => {
            messageCount.textContent = String(messageInput.value.length);
        });
        function closeAdminMessageModal() {
            messageModal.classList.remove('open');
            messageModal.setAttribute('aria-hidden', 'true');
        }
        function closeCommunityDeleteModal() {
            communityDeleteModal.classList.remove('open');
            communityDeleteModal.setAttribute('aria-hidden', 'true');
            communityDeleteId.value = '';
        }
        function closeUserActionModal() {
            userActionModal.classList.remove('open');
            userActionModal.setAttribute('aria-hidden', 'true');
            userActionType.value = '';
            userActionUserId.value = '';
        }
        document.getElementById('adminMessageCancel').addEventListener('click', closeAdminMessageModal);
        document.getElementById('communityDeleteCancel').addEventListener('click', closeCommunityDeleteModal);
        document.getElementById('userActionCancel').addEventListener('click', closeUserActionModal);
        messageModal.addEventListener('click', (event) => {
            if (event.target === messageModal) closeAdminMessageModal();
        });
        communityDeleteModal.addEventListener('click', (event) => {
            if (event.target === communityDeleteModal) closeCommunityDeleteModal();
        });
        userActionModal.addEventListener('click', (event) => {
            if (event.target === userActionModal) closeUserActionModal();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && messageModal.classList.contains('open')) closeAdminMessageModal();
            if (event.key === 'Escape' && communityDeleteModal.classList.contains('open')) closeCommunityDeleteModal();
            if (event.key === 'Escape' && userActionModal.classList.contains('open')) closeUserActionModal();
        });

        const adminTabs = document.querySelectorAll('.admin-tab');
        const adminPanels = document.querySelectorAll('.admin-tab-panel');

        function setActiveAdminTab(targetTab) {
            adminTabs.forEach((item) => {
                const isActive = item.dataset.tab === targetTab;
                item.classList.toggle('is-active', isActive);
                item.setAttribute('aria-selected', String(isActive));
            });
            adminPanels.forEach((panel) => {
                panel.classList.toggle('is-active', panel.id === `${targetTab}-panel`);
            });
            document.querySelectorAll('input[name="active_tab"]').forEach((input) => {
                input.value = targetTab;
            });
        }

        adminTabs.forEach((tabButton) => {
            tabButton.addEventListener('click', () => {
                setActiveAdminTab(tabButton.dataset.tab);
            });
        });

        let currentUsersSnapshot = <?= json_encode($usersSnapshot, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const refreshUsersUrl = new URL(window.location.href);
        refreshUsersUrl.searchParams.set('ajax', 'users_refresh');

        async function refreshUserListIfChanged() {
            if (document.hidden) return;
            if (messageModal.classList.contains('open') || document.activeElement?.closest('form')) return;

            try {
                const response = await fetch(refreshUsersUrl, { cache: 'no-store' });
                if (!response.ok) return;
                const latestUsers = await response.json();
                if (JSON.stringify(latestUsers) !== JSON.stringify(currentUsersSnapshot)) {
                    window.location.reload();
                }
            } catch (error) {
                console.error('Não foi possível atualizar a lista de usuários.');
            }
        }

        window.setInterval(refreshUserListIfChanged, 15000);
        window.addEventListener('pageshow', refreshUserListIfChanged);
    </script>
    <script src="js/admin_messages.js"></script>
    <script src="js/logout_confirm.js"></script>
</body>
</html>
