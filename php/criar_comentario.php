<?php
require_once __DIR__ . "/security_headers.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/activity_timestamps.php";
require_once __DIR__ . "/community_bans.php";
require_once __DIR__ . "/admin_helpers.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para comentar."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

require_same_origin_for_state_change();

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança (CSRF) inválido."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_post = intval($_POST['id_post'] ?? 0);
$conteudo_raw = trim((string)($_POST['conteudo'] ?? ''));

if (is_site_admin($conn, $id_usuario)) {
    http_response_code(403);
    echo json_encode(["sucesso" => false, "mensagem" => "Administradores não podem comentar."]);
    exit;
}

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

if (is_user_banned_from_community($conn, $id_usuario, $id_comunidade)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você foi banido desta comunidade e não pode comentar."]);
    exit;
}

$sql_check_membro = "SELECT id_membro_comunidade FROM membro_comunidade WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1";
$resultado_membro = mysqli_query($conn, $sql_check_membro);

if (!$resultado_membro || mysqli_num_rows($resultado_membro) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa seguir esta comunidade para publicar posts e comentar."]);
    exit;
}

$conteudo = $conteudo_raw;
$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);

if (!ensure_activity_timestamp_columns($conn)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar a data do comentário."]);
    exit;
}

$postingLimit = consume_user_posting_limit($conn, $id_usuario, 'comentario', 60);
if ($postingLimit['error']) {
    http_response_code(503);
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível validar o limite de comentários. Tente novamente."]);
    exit;
}
if (!$postingLimit['allowed']) {
    $wait = (int) $postingLimit['retry_after'];
    http_response_code(429);
    header('Retry-After: ' . $wait);
    echo json_encode([
        "sucesso" => false,
        "limite_atingido" => true,
        "retry_after" => $wait,
        "mensagem" => "Você precisa esperar $wait segundo(s) antes de comentar novamente."
    ]);
    exit;
}

$data_comentario = date('Y-m-d H:i:s');

$sql_insert = "INSERT INTO comentario (id_usuario, id_post, conteudo, data_comentario) VALUES ($id_usuario, $id_post, '$conteudo_esc', '$data_comentario')";

if (mysqli_query($conn, $sql_insert)) {
    echo json_encode(["sucesso" => true, "mensagem" => "Comentário enviado com sucesso!"]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao cadastrar comentário."]);
}
