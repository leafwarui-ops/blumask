<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

// 1. Verificação de Autenticação
if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para curtir."]);
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

// 3. Verificação de Rate Limit (máx 100 curtidas por minuto)
if (!check_rate_limit('like_post', 100, 60)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Muitas curtidas. Aguarde um momento."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_post = intval($_POST['id_post'] ?? 0);

// 4. Validação
if ($id_post <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID do post inválido."]);
    exit;
}

// 5. Verificar se o post existe
$sql_check_post = "SELECT id_post FROM post WHERE id_post = $id_post LIMIT 1";
$resultado_check = mysqli_query($conn, $sql_check_post);

if (!$resultado_check || mysqli_num_rows($resultado_check) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Post não encontrado."]);
    exit;
}

// 6. Verificar se o usuário já curtiu este post
$sql_check_curtida = "SELECT id_curtida FROM curtida 
                      WHERE id_usuario = $id_usuario AND id_post = $id_post LIMIT 1";
$resultado_curtida = mysqli_query($conn, $sql_check_curtida);

if ($resultado_curtida && mysqli_num_rows($resultado_curtida) > 0) {
    // Se já curtiu, remover a curtida
    $sql_delete = "DELETE FROM curtida WHERE id_usuario = $id_usuario AND id_post = $id_post";
    
    if (mysqli_query($conn, $sql_delete)) {
        hit_rate_limit('like_post');
        echo json_encode(["sucesso" => true, "mensagem" => "Curtida removida.", "curtiu" => false]);
    } else {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao remover curtida."]);
    }
} else {
    // Se não curtiu, adicionar a curtida
    $sql_insert = "INSERT INTO curtida (id_usuario, id_post) VALUES ($id_usuario, $id_post)";
    
    if (mysqli_query($conn, $sql_insert)) {
        hit_rate_limit('like_post');
        echo json_encode(["sucesso" => true, "mensagem" => "Post curtido!", "curtiu" => true]);
    } else {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao curtir post."]);
    }
}
