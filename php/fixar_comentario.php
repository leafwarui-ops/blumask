<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/profile_pins.php";

header('Content-Type: application/json; charset=utf-8');

$id_usuario = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
$csrf = $_POST['csrf_token'] ?? '';
$id_post = intval($_POST['id_post'] ?? 0);
$id_comentario = intval($_POST['id_comentario'] ?? 0);
$destino = ($_POST['destino'] ?? 'post') === 'perfil' ? 'perfil' : 'post';

if ($id_usuario <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para fixar comentários."]);
    exit;
}

if (!isset($_SESSION['csrf_token']) || $csrf !== $_SESSION['csrf_token']) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token CSRF inválido."]);
    exit;
}

if ($id_post <= 0 || $id_comentario <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos."]);
    exit;
}

global $conn;

if ($destino === 'perfil') {
    ensure_profile_pin_tables($conn);

    $res_comment = mysqli_query($conn, "SELECT id_comentario, id_post, id_usuario FROM comentario WHERE id_comentario = $id_comentario AND id_post = $id_post LIMIT 1");
    if (!$res_comment || mysqli_num_rows($res_comment) === 0) {
        echo json_encode(["sucesso" => false, "mensagem" => "Comentário não encontrado."]);
        exit;
    }

    $comment = mysqli_fetch_assoc($res_comment);
    if (intval($comment['id_usuario']) !== $id_usuario) {
        echo json_encode(["sucesso" => false, "mensagem" => "Você só pode fixar seus próprios comentários no perfil."]);
        exit;
    }

    $pin_exists = mysqli_query($conn, "SELECT 1 FROM perfil_comentario_fixado WHERE id_usuario = $id_usuario AND id_comentario = $id_comentario LIMIT 1");
    $is_pinned = $pin_exists && mysqli_num_rows($pin_exists) > 0;
    $sql_update = $is_pinned
        ? "DELETE FROM perfil_comentario_fixado WHERE id_usuario = $id_usuario AND id_comentario = $id_comentario"
        : "INSERT IGNORE INTO perfil_comentario_fixado (id_usuario, id_comentario) VALUES ($id_usuario, $id_comentario)";

    if (mysqli_query($conn, $sql_update)) {
        echo json_encode(["sucesso" => true, "mensagem" => $is_pinned ? "Comentário removido do perfil." : "Comentário fixado no perfil."]);
    } else {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao fixar o comentário no perfil."]);
    }
    exit;
}

// Verifica se post existe e autor
// Verifica se post existe e autor
$res = mysqli_query($conn, "SELECT id_usuario FROM post WHERE id_post = $id_post LIMIT 1");
if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Post não encontrado."]);
    exit;
}

$post = mysqli_fetch_assoc($res);
$id_post_autor = intval($post['id_usuario']);
$current_pinned = 0;

// Certifica-se de que a coluna id_comentario_fixado exista; se não, tenta criá-la
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM post LIKE 'id_comentario_fixado'");
if (!$col_check || mysqli_num_rows($col_check) === 0) {
    // Tenta adicionar a coluna (sem constraint FK para evitar erros em ambientes antigos)
    $alter_sql = "ALTER TABLE post ADD COLUMN id_comentario_fixado INT NULL";
    if (!mysqli_query($conn, $alter_sql)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Coluna id_comentario_fixado ausente e não foi possível criá-la automaticamente. Execute a migração no banco."]);
        exit;
    }
}

// Agora podemos ler o valor atual (se houver)
$res3 = mysqli_query($conn, "SELECT id_comentario_fixado FROM post WHERE id_post = $id_post LIMIT 1");
if ($res3 && mysqli_num_rows($res3) > 0) {
    $r3 = mysqli_fetch_assoc($res3);
    $current_pinned = intval($r3['id_comentario_fixado'] ?? 0);
}

if ($id_post_autor !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Apenas o autor do post pode fixar comentários."]);
    exit;
}

// Verificar se o comentário pertence ao post
$res2 = mysqli_query($conn, "SELECT id_comentario FROM comentario WHERE id_comentario = $id_comentario AND id_post = $id_post LIMIT 1");
if (!$res2 || mysqli_num_rows($res2) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comentário não pertence a este post."]);
    exit;
}

// Toggle: se já estiver fixado, desfixa; caso contrário, fixa
if ($current_pinned === $id_comentario) {
    $sql = "UPDATE post SET id_comentario_fixado = NULL WHERE id_post = $id_post";
} else {
    $sql = "UPDATE post SET id_comentario_fixado = $id_comentario WHERE id_post = $id_post";
}

if (mysqli_query($conn, $sql)) {
    echo json_encode(["sucesso" => true, "mensagem" => "Operação realizada com sucesso."]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao atualizar fixação do comentário."]);
}
