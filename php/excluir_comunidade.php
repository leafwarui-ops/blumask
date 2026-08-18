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
if (!check_rate_limit('delete_community', 5, 3600)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Limite de exclusões excedido."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);

// 4. Validação do ID
if ($id_comunidade <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID inválido."]);
    exit;
}

// 5. Verificar se o usuário é o criador (admin) da comunidade
$sql_check = "SELECT id_usuario FROM comunidade WHERE id_comunidade = $id_comunidade LIMIT 1";
$resultado = mysqli_query($conn, $sql_check);

if (!$resultado || mysqli_num_rows($resultado) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comunidade não encontrada."]);
    exit;
}

$comunidade = mysqli_fetch_assoc($resultado);

if (intval($comunidade['id_usuario']) !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você não tem permissão para excluir esta comunidade."]);
    exit;
}

// 6. Iniciar transação para excluir comunidade e seus dados
mysqli_begin_transaction($conn);

try {
    // Buscar todos os posts da comunidade para excluir arquivos associados (se houver)
    $sql_posts = "SELECT id_post FROM post WHERE id_comunidade = $id_comunidade";
    $resultado_posts = mysqli_query($conn, $sql_posts);

    if ($resultado_posts) {
        while ($post = mysqli_fetch_assoc($resultado_posts)) {
            $id_post = intval($post['id_post']);
            // Excluir curtidas do post
            mysqli_query($conn, "DELETE FROM curtida WHERE id_post = $id_post");
            // Excluir comentários do post
            mysqli_query($conn, "DELETE FROM comentario WHERE id_post = $id_post");
        }
    }

    // Excluir posts
    $sql_del_posts = "DELETE FROM post WHERE id_comunidade = $id_comunidade";
    if (!mysqli_query($conn, $sql_del_posts)) {
        throw new Exception("Erro ao excluir posts.");
    }

    // Excluir membros
    $sql_del_membros = "DELETE FROM membro_comunidade WHERE id_comunidade = $id_comunidade";
    if (!mysqli_query($conn, $sql_del_membros)) {
        throw new Exception("Erro ao excluir membros.");
    }

    // Excluir comunidade
    $sql_del_comunidade = "DELETE FROM comunidade WHERE id_comunidade = $id_comunidade";
    if (!mysqli_query($conn, $sql_del_comunidade)) {
        throw new Exception("Erro ao excluir comunidade.");
    }

    mysqli_commit($conn);
    hit_rate_limit('delete_community');

    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Comunidade excluída com sucesso."
    ]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao excluir comunidade."]);
}
