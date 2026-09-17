<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

$id_usuario = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
$csrf = $_POST['csrf_token'] ?? '';
$id_post = intval($_POST['id_post'] ?? 0);
$id_comentario = intval($_POST['id_comentario'] ?? 0);

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

// Verifica se post existe e autor
$res = mysqli_query($conn, "SELECT id_usuario, id_comentario_fixado FROM post WHERE id_post = $id_post LIMIT 1");
if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Post não encontrado."]);
    exit;
}
$post = mysqli_fetch_assoc($res);
$id_post_autor = intval($post['id_usuario']);
$current_pinned = intval($post['id_comentario_fixado'] ?? 0);

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
