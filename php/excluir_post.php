<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

// 1. Verificação de Autenticação
if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

// 2. Verificação de CSRF Token
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança inválido."]);
    exit;
}

// 3. Verificação de Rate Limit
if (!check_rate_limit('delete_post', 20, 3600)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Limite de exclusões excedido."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_post = intval($_POST['id_post'] ?? 0);

// 4. Validação do ID
if ($id_post <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID inválido."]);
    exit;
}

// 5. Verificar se o post existe e buscar a comunidade
$sql_post = "SELECT p.id_post, p.id_comunidade, c.id_usuario as comunidade_dono
             FROM post p
             JOIN comunidade c ON p.id_comunidade = c.id_comunidade
             WHERE p.id_post = $id_post LIMIT 1";

$resultado = mysqli_query($conn, $sql_post);

if (!$resultado || mysqli_num_rows($resultado) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Post não encontrado."]);
    exit;
}

$post = mysqli_fetch_assoc($resultado);
$id_comunidade = intval($post['id_comunidade']);
$comunidade_dono = intval($post['comunidade_dono']);

// 6. Verificar se o usuário é admin da comunidade
if ($comunidade_dono !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você não tem permissão para excluir este post."]);
    exit;
}

// 7. Deletar o post e seus dados relacionados
mysqli_begin_transaction($conn);

try {
    // Excluir curtidas do post
    $sql_del_curtidas = "DELETE FROM curtida WHERE id_post = $id_post";
    if (!mysqli_query($conn, $sql_del_curtidas)) {
        throw new Exception("Erro ao excluir curtidas.");
    }

    // Excluir comentários do post
    $sql_del_comentarios = "DELETE FROM comentario WHERE id_post = $id_post";
    if (!mysqli_query($conn, $sql_del_comentarios)) {
        throw new Exception("Erro ao excluir comentários.");
    }

    // Excluir o post
    $sql_del_post = "DELETE FROM post WHERE id_post = $id_post";
    if (!mysqli_query($conn, $sql_del_post)) {
        throw new Exception("Erro ao excluir post.");
    }

    mysqli_commit($conn);
    hit_rate_limit('delete_post');

    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Post excluído com sucesso."
    ]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao excluir post."]);
}
