<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

define('CARGO_ADMINISTRADOR', 1);

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para sair da comunidade."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança inválido."]);
    exit;
}

if (!check_rate_limit('leave_community', 10, 60)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Muitas tentativas. Aguarde um momento."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);

if ($id_comunidade <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID da comunidade inválido."]);
    exit;
}

$sql_comunidade = "SELECT id_usuario FROM comunidade WHERE id_comunidade = $id_comunidade LIMIT 1";
$resultado_comunidade = mysqli_query($conn, $sql_comunidade);

if (!$resultado_comunidade || mysqli_num_rows($resultado_comunidade) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comunidade não encontrada."]);
    exit;
}

$comunidade = mysqli_fetch_assoc($resultado_comunidade);

if (intval($comunidade['id_usuario']) === $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você não pode sair da comunidade porque é o dono dela."]);
    exit;
}

$sql_membro = "SELECT cargo FROM membro_comunidade WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1";
$resultado_membro = mysqli_query($conn, $sql_membro);

if (!$resultado_membro || mysqli_num_rows($resultado_membro) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você não é membro desta comunidade."]);
    exit;
}

$membro = mysqli_fetch_assoc($resultado_membro);
if (intval($membro['cargo']) === CARGO_ADMINISTRADOR) {
    echo json_encode(["sucesso" => false, "mensagem" => "Moderadores não podem sair da comunidade."]);
    exit;
}

$sql_delete = "DELETE FROM membro_comunidade WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1";

if (mysqli_query($conn, $sql_delete)) {
    hit_rate_limit('leave_community');
    echo json_encode(["sucesso" => true, "mensagem" => "Você saiu da comunidade."]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao sair da comunidade."]);
}
