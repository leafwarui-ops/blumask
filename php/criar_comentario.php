<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para comentar."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança (CSRF) inválido."]);
    exit;
}

if (!check_rate_limit('create_comment', 200, 3600)) {
    $wait = get_rate_limit_wait_time('create_comment', 3600);
    echo json_encode(["sucesso" => false, "mensagem" => "Limite de comentários excedido. Aguarde $wait."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_post = intval($_POST['id_post'] ?? 0);
$conteudo_raw = trim((string)($_POST['conteudo'] ?? ''));

if ($id_post <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID do post inválido."]);
    exit;
}

if (mb_strlen($conteudo_raw) < 2 || mb_strlen($conteudo_raw) > 2000) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comentário inválido."]);
    exit;
}

$sql_check_post = "SELECT p.id_post, p.id_comunidade FROM post p WHERE p.id_post = $id_post LIMIT 1";
$resultado_post = mysqli_query($conn, $sql_check_post);

if (!$resultado_post || mysqli_num_rows($resultado_post) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Post não encontrado."]);
    exit;
}

$post = mysqli_fetch_assoc($resultado_post);
$id_comunidade = intval($post['id_comunidade']);

$sql_check_membro = "SELECT id_membro_comunidade FROM membro_comunidade WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1";
$resultado_membro = mysqli_query($conn, $sql_check_membro);

if (!$resultado_membro || mysqli_num_rows($resultado_membro) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você não é membro desta comunidade."]);
    exit;
}

$conteudo = htmlspecialchars($conteudo_raw, ENT_QUOTES, 'UTF-8');
$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);
$data_comentario = date('Y-m-d');

$sql_insert = "INSERT INTO comentario (id_usuario, id_post, conteudo, data_comentario) VALUES ($id_usuario, $id_post, '$conteudo_esc', '$data_comentario')";

if (mysqli_query($conn, $sql_insert)) {
    hit_rate_limit('create_comment');
    echo json_encode(["sucesso" => true, "mensagem" => "Comentário enviado com sucesso!"]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao cadastrar comentário."]);
}
