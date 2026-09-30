<?php
require_once __DIR__ . "/security_headers.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/activity_timestamps.php";
require_once __DIR__ . "/community_bans.php";
require_once __DIR__ . "/admin_helpers.php";

header('Content-Type: application/json; charset=utf-8');

// 1. Verificação de Autenticação
if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para criar um post."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

// 2. Verificação de CSRF Token
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança (CSRF) inválido."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);
$assunto_raw = trim($_POST['assunto'] ?? '');
$conteudo_raw = str_replace(["\r\n", "\r"], "\n", trim($_POST['conteudo'] ?? ''));

if (is_site_admin($conn, $id_usuario)) {
    http_response_code(403);
    echo json_encode(["sucesso" => false, "mensagem" => "Administradores não podem criar posts."]);
    exit;
}

// 4. Validações
if ($id_comunidade <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID da comunidade inválido."]);
    exit;
}

if (is_user_banned_from_community($conn, $id_usuario, $id_comunidade)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você foi banido desta comunidade e não pode publicar."]);
    exit;
}

if (mb_strlen($assunto_raw) < 3 || mb_strlen($assunto_raw) > 150) {
    echo json_encode(["sucesso" => false, "mensagem" => "Validação inválida."]);
    exit;
}

if (mb_strlen($conteudo_raw) < 5 || mb_strlen($conteudo_raw) > 5000) {
    echo json_encode(["sucesso" => false, "mensagem" => "Validação inválida."]);
    exit;
}

// 5. Verificar se o usuário é membro da comunidade
$sql_check_membro = "SELECT id_membro_comunidade FROM membro_comunidade 
                      WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1";
$resultado_check = mysqli_query($conn, $sql_check_membro);

if (!$resultado_check || mysqli_num_rows($resultado_check) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa seguir esta comunidade para publicar posts e comentar."]);
    exit;
}

// 6. Verificar se comunidade existe
$sql_check_comunidade = "SELECT id_comunidade FROM comunidade WHERE id_comunidade = $id_comunidade LIMIT 1";
$resultado_comunidade = mysqli_query($conn, $sql_check_comunidade);

if (!$resultado_comunidade || mysqli_num_rows($resultado_comunidade) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comunidade não encontrada."]);
    exit;
}

// 7. Dados são escapados na saída HTML, não antes de persistir.
$assunto = $assunto_raw;
$conteudo = $conteudo_raw;

$assunto_esc = mysqli_real_escape_string($conn, $assunto);
$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);

// 8. Inserir o post
if (!ensure_activity_timestamp_columns($conn)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar a data do post."]);
    exit;
}

$postingLimit = consume_user_posting_limit($conn, $id_usuario, 'post', 60);
if ($postingLimit['error']) {
    http_response_code(503);
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível validar o limite de publicação. Tente novamente."]);
    exit;
}
if (!$postingLimit['allowed']) {
    $wait = (int) $postingLimit['retry_after'];
    http_response_code(429);
    header('Retry-After: ' . $wait);
    echo json_encode(["sucesso" => false, "limite_atingido" => true]);
    exit;
}

$data_post = date("Y-m-d H:i:s");
$sql_insert = "INSERT INTO post (id_comunidade, Data_post, conteudo, id_usuario, assunto)
               VALUES ($id_comunidade, '$data_post', '$conteudo_esc', $id_usuario, '$assunto_esc')";

if (mysqli_query($conn, $sql_insert)) {
    $id_post = mysqli_insert_id($conn);
    
    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Post criado com sucesso!",
        "id_post" => $id_post
    ]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao criar post."]);
}
