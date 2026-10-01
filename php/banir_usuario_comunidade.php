<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
require_once __DIR__ . "/bd.php";
require_once __DIR__ . "/community_bans.php";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function community_ban_response(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$id_usuario = (int) ($_SESSION['usuario']['id_usuario'] ?? 0);
$id_comunidade = (int) ($_POST['id_comunidade'] ?? $_GET['id_comunidade'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['verificar'] ?? '') === '1') {
    community_ban_response([
        'sucesso' => true,
        'banido' => $id_usuario > 0 && is_user_banned_from_community($conn, $id_usuario, $id_comunidade)
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Método inválido.'], 405);
}

require_same_origin_for_state_change();

if ($id_usuario <= 0) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Você precisa estar logado para banir alguém.'], 401);
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Token de segurança (CSRF) inválido.'], 403);
}

if (!check_rate_limit('ban_community_user', 10, 60)) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Muitas tentativas. Aguarde um momento.'], 429);
}

$id_usuario_banido = (int) ($_POST['id_usuario'] ?? 0);
if ($id_comunidade <= 0 || $id_usuario_banido <= 0 || $id_usuario_banido === $id_usuario) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Dados inválidos para o banimento.'], 400);
}

if (!ensure_community_ban_schema($conn)) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Não foi possível preparar o registro de banimentos.'], 500);
}

$permission = $conn->prepare("SELECT c.id_usuario, mc.cargo
    FROM comunidade c
    LEFT JOIN membro_comunidade mc ON mc.id_comunidade = c.id_comunidade AND mc.id_usuario = ?
    WHERE c.id_comunidade = ? LIMIT 1");
$permission->bind_param('ii', $id_usuario, $id_comunidade);
$permission->execute();
$community = $permission->get_result()->fetch_assoc();
$permission->close();

if (!$community) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Comunidade não encontrada.'], 404);
}

$isOwner = (int) $community['id_usuario'] === $id_usuario;
$isAdmin = (int) ($community['cargo'] ?? 0) === 1;
if (!$isOwner && !$isAdmin) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Somente administradores da comunidade podem banir usuários.'], 403);
}

$target = $conn->prepare("SELECT u.id_usuario, mc.cargo
    FROM usuario u
    LEFT JOIN membro_comunidade mc ON mc.id_comunidade = ? AND mc.id_usuario = u.id_usuario
    WHERE u.id_usuario = ? LIMIT 1");
$target->bind_param('ii', $id_comunidade, $id_usuario_banido);
$target->execute();
$targetUser = $target->get_result()->fetch_assoc();
$target->close();

if (!$targetUser) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Usuário não encontrado.'], 404);
}

if ((int) $targetUser['id_usuario'] === (int) $community['id_usuario']) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'O dono da comunidade não pode ser banido.'], 403);
}

if ((int) ($targetUser['cargo'] ?? 0) === 1) {
    community_ban_response(['sucesso' => false, 'mensagem' => 'Administradores da comunidade não podem ser banidos por esta ação.'], 403);
}

$conn->begin_transaction();
try {
    $insert = $conn->prepare("INSERT INTO banimento_comunidade (id_comunidade, id_usuario, id_usuario_baniu)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE id_usuario_baniu = VALUES(id_usuario_baniu), data_banimento = CURRENT_TIMESTAMP");
    $insert->bind_param('iii', $id_comunidade, $id_usuario_banido, $id_usuario);
    if (!$insert->execute()) {
        throw new RuntimeException('Falha ao registrar o banimento.');
    }
    $insert->close();

    $removeMember = $conn->prepare("DELETE FROM membro_comunidade WHERE id_comunidade = ? AND id_usuario = ?");
    $removeMember->bind_param('ii', $id_comunidade, $id_usuario_banido);
    if (!$removeMember->execute()) {
        throw new RuntimeException('Falha ao remover o usuário da comunidade.');
    }
    $removeMember->close();

    $conn->commit();
    hit_rate_limit('ban_community_user');
    community_ban_response(['sucesso' => true, 'mensagem' => 'Usuário banido da comunidade.']);
} catch (Throwable $error) {
    $conn->rollback();
    community_ban_response(['sucesso' => false, 'mensagem' => 'Não foi possível banir o usuário.'], 500);
}
?>