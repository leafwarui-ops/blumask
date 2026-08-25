<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para fixar um post."]);
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

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);
$id_post = intval($_POST['id_post'] ?? 0);

if ($id_comunidade <= 0 || $id_post <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Dados inválidos."]);
    exit;
}

$sql_permissao = "SELECT c.id_usuario, mc.cargo
                  FROM comunidade c
                  LEFT JOIN membro_comunidade mc ON mc.id_usuario = $id_usuario AND mc.id_comunidade = $id_comunidade
                  WHERE c.id_comunidade = $id_comunidade
                  LIMIT 1";

$resultado_permissao = mysqli_query($conn, $sql_permissao);

if (!$resultado_permissao || mysqli_num_rows($resultado_permissao) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comunidade não encontrada."]);
    exit;
}

$permissao = mysqli_fetch_assoc($resultado_permissao);
$criador_id = intval($permissao['id_usuario']);
$cargo = intval($permissao['cargo'] ?? 0);

if ($criador_id !== $id_usuario && $cargo !== 1) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você não tem permissão para fixar posts desta comunidade."]);
    exit;
}

$sql_post = "SELECT id_post, id_comunidade FROM post WHERE id_post = $id_post AND id_comunidade = $id_comunidade LIMIT 1";
$resultado_post = mysqli_query($conn, $sql_post);

if (!$resultado_post || mysqli_num_rows($resultado_post) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Post não encontrado nesta comunidade."]);
    exit;
}

$sql_update = "UPDATE comunidade SET id_post_fixado = CASE WHEN id_post_fixado = $id_post THEN NULL ELSE $id_post END WHERE id_comunidade = $id_comunidade";

if (mysqli_query($conn, $sql_update)) {
    echo json_encode(["sucesso" => true, "mensagem" => "Post fixado com sucesso."]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao fixar o post."]);
}
