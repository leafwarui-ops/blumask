<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

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

// 3. Verificação de Rate Limit (máx 20 posts por hora)
if (!check_rate_limit('create_post', 20, 3600)) {
    $wait = get_rate_limit_wait_time('create_post', 3600);
    echo json_encode(["sucesso" => false, "mensagem" => "Limite de criação de posts excedido. Aguarde $wait."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);
$assunto_raw = trim($_POST['assunto'] ?? '');
$conteudo_raw = str_replace(["\r\n", "\r"], "\n", trim($_POST['conteudo'] ?? ''));

// 4. Validações
if ($id_comunidade <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID da comunidade inválido."]);
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
    echo json_encode(["sucesso" => false, "mensagem" => "Você não é membro desta comunidade."]);
    exit;
}

// 6. Verificar se comunidade existe
$sql_check_comunidade = "SELECT id_comunidade FROM comunidade WHERE id_comunidade = $id_comunidade LIMIT 1";
$resultado_comunidade = mysqli_query($conn, $sql_check_comunidade);

if (!$resultado_comunidade || mysqli_num_rows($resultado_comunidade) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comunidade não encontrada."]);
    exit;
}

// 7. Sanitização contra XSS
$assunto = htmlspecialchars($assunto_raw, ENT_QUOTES, 'UTF-8');
$conteudo = htmlspecialchars($conteudo_raw, ENT_QUOTES, 'UTF-8');

$assunto_esc = mysqli_real_escape_string($conn, $assunto);
$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);

// 8. Inserir o post
$data_post = date("Y-m-d H:i:s");
$sql_insert = "INSERT INTO post (id_comunidade, Data_post, conteudo, id_usuario, assunto)
               VALUES ($id_comunidade, '$data_post', '$conteudo_esc', $id_usuario, '$assunto_esc')";

if (mysqli_query($conn, $sql_insert)) {
    hit_rate_limit('create_post');
    $id_post = mysqli_insert_id($conn);
    
    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Post criado com sucesso!",
        "id_post" => $id_post
    ]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao criar post."]);
}
