<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

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

// Buscar autor do post. Não assume que a coluna id_comentario_fixado exista.
$res2 = mysqli_query($conn, "SELECT id_usuario FROM post WHERE id_post = $id_post LIMIT 1");
$post_row = $res2 && mysqli_num_rows($res2) > 0 ? mysqli_fetch_assoc($res2) : null;
$id_post_autor = $post_row ? intval($post_row['id_usuario']) : 0;
$current_pinned = 0;

// Verifica se a coluna id_comentario_fixado existe antes de consultá-la
$col_check = mysqli_query($conn, "SHOW COLUMNS FROM post LIKE 'id_comentario_fixado'");
if ($col_check && mysqli_num_rows($col_check) > 0) {
    $res3 = mysqli_query($conn, "SELECT id_comentario_fixado FROM post WHERE id_post = $id_post LIMIT 1");
    if ($res3 && mysqli_num_rows($res3) > 0) {
        $r3 = mysqli_fetch_assoc($res3);
        $current_pinned = intval($r3['id_comentario_fixado'] ?? 0);
    }
}

// Permissão: autor do comentário ou dono do post
if ($id_autor !== $id_usuario && $id_post_autor !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você não tem permissão para excluir este comentário."]);
    exit;
}

// Se comentário estiver fixado no post, desfixa antes de deletar
if ($current_pinned === $id_comentario) {
    mysqli_query($conn, "UPDATE post SET id_comentario_fixado = NULL WHERE id_post = $id_post");
}

$sql_delete = "DELETE FROM comentario WHERE id_comentario = $id_comentario";
$del_res = mysqli_query($conn, $sql_delete);
if ($del_res) {
    echo json_encode(["sucesso" => true, "mensagem" => "Comentário excluído com sucesso."]);
} else {
    $err = mysqli_error($conn);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao excluir comentário.", "debug" => $err, "sql" => $sql_delete]);
}
