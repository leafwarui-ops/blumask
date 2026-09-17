<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

$id_usuario = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
$csrf = $_POST['csrf_token'] ?? '';
$id_comentario = intval($_POST['id_comentario'] ?? 0);
$conteudo = trim((string) ($_POST['conteudo'] ?? ''));

if ($id_usuario <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para editar comentários."]);
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

if (mb_strlen($conteudo) < 2) {
    echo json_encode(["sucesso" => false, "mensagem" => "O comentário deve ter no mínimo 2 caracteres."]);
    exit;
}

global $conn;

// Verifica autor do comentário
$res = mysqli_query($conn, "SELECT id_usuario FROM comentario WHERE id_comentario = $id_comentario LIMIT 1");
if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comentário não encontrado."]);
    exit;
}
$row = mysqli_fetch_assoc($res);
$id_autor = intval($row['id_usuario']);

if ($id_autor !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você só pode editar seus próprios comentários."]);
    exit;
}

$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);
$sql = "UPDATE comentario SET conteudo = '$conteudo_esc' WHERE id_comentario = $id_comentario";
if (mysqli_query($conn, $sql)) {
    echo json_encode(["sucesso" => true, "mensagem" => "Comentário atualizado com sucesso."]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao atualizar comentário."]);
}
