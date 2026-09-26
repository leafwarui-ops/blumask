<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/profile_pins.php";

header('Content-Type: application/json; charset=utf-8');

$id_usuario = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
$csrf = $_POST['csrf_token'] ?? '';
$id_comentario = intval($_POST['id_comentario'] ?? 0);

if ($id_usuario <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para excluir comentários."]);
    exit;
}

if (!isset($_SESSION['csrf_token']) || $csrf !== $_SESSION['csrf_token']) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token CSRF inválido."]);
    exit;
}

if ($id_comentario <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comentário inválido."]);
    exit;
}

global $conn;

// Buscar comentário e post relacionado
$res = mysqli_query($conn, "SELECT id_usuario, id_post FROM comentario WHERE id_comentario = $id_comentario LIMIT 1");
if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comentário não encontrado."]);
    exit;
}
$row = mysqli_fetch_assoc($res);
$id_autor = intval($row['id_usuario']);
$id_post = intval($row['id_post']);

// Buscar autor do post.
$res2 = mysqli_query($conn, "SELECT id_usuario FROM post WHERE id_post = $id_post LIMIT 1");
$post_row = $res2 && mysqli_num_rows($res2) > 0 ? mysqli_fetch_assoc($res2) : null;
$id_post_autor = $post_row ? intval($post_row['id_usuario']) : 0;

// Permissão: autor do comentário ou dono do post
if ($id_autor !== $id_usuario && $id_post_autor !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você não tem permissão para excluir este comentário."]);
    exit;
}

ensure_profile_pin_tables($conn);
mysqli_begin_transaction($conn);

try {
    if (!mysqli_query($conn, "DELETE FROM perfil_comentario_fixado WHERE id_comentario = $id_comentario")) {
        throw new Exception("Erro ao remover fixação do perfil.");
    }

    $post_pin_column = mysqli_query($conn, "SHOW COLUMNS FROM post LIKE 'id_comentario_fixado'");
    if (!$post_pin_column) {
        throw new Exception("Erro ao verificar comentário fixado no post.");
    }
    if (mysqli_num_rows($post_pin_column) > 0
        && !mysqli_query($conn, "UPDATE post SET id_comentario_fixado = NULL WHERE id_comentario_fixado = $id_comentario")) {
        throw new Exception("Erro ao desfixar comentário do post.");
    }

    $user_pin_column = mysqli_query($conn, "SHOW COLUMNS FROM usuario LIKE 'id_comentario_fixado'");
    if (!$user_pin_column) {
        throw new Exception("Erro ao verificar comentários fixados no perfil.");
    }
    if (mysqli_num_rows($user_pin_column) > 0
        && !mysqli_query($conn, "UPDATE usuario SET id_comentario_fixado = NULL WHERE id_comentario_fixado = $id_comentario")) {
        throw new Exception("Erro ao limpar fixação antiga do perfil.");
    }

    if (!mysqli_query($conn, "DELETE FROM comentario WHERE id_comentario = $id_comentario")) {
        throw new Exception("Erro ao excluir comentário.");
    }

    mysqli_commit($conn);
    echo json_encode(["sucesso" => true, "mensagem" => "Comentário excluído com sucesso."]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao excluir comentário."]);
}
