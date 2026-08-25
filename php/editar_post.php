<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para editar um post."]);
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

if (!check_rate_limit('edit_post', 20, 3600)) {
    $wait = get_rate_limit_wait_time('edit_post', 3600);
    echo json_encode(["sucesso" => false, "mensagem" => "Limite de edição de posts excedido. Aguarde $wait."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_post = intval($_POST['id_post'] ?? 0);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);
$assunto_raw = trim($_POST['assunto'] ?? '');
$conteudo_raw = str_replace(["\r\n", "\r"], "\n", trim($_POST['conteudo'] ?? ''));

if ($id_post <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID do post inválido."]);
    exit;
}

if ($id_comunidade <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID da comunidade inválido."]);
    exit;
}

if (mb_strlen($assunto_raw) < 3 || mb_strlen($assunto_raw) > 150) {
    echo json_encode(["sucesso" => false, "mensagem" => "O assunto deve ter entre 3 e 150 caracteres."]);
    exit;
}

if (mb_strlen($conteudo_raw) < 5 || mb_strlen($conteudo_raw) > 5000) {
    echo json_encode(["sucesso" => false, "mensagem" => "O conteúdo deve ter entre 5 e 5000 caracteres."]);
    exit;
}

$sql_post = "SELECT p.id_post, p.id_comunidade, p.id_usuario AS autor_id, c.id_usuario AS comunidade_dono
             FROM post p
             JOIN comunidade c ON p.id_comunidade = c.id_comunidade
             WHERE p.id_post = $id_post LIMIT 1";

$resultado_post = mysqli_query($conn, $sql_post);

if (!$resultado_post || mysqli_num_rows($resultado_post) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Post não encontrado."]);
    exit;
}

$post = mysqli_fetch_assoc($resultado_post);

if (intval($post['id_comunidade']) !== $id_comunidade) {
    echo json_encode(["sucesso" => false, "mensagem" => "O post não pertence a esta comunidade."]);
    exit;
}

$autor_id = intval($post['autor_id']);

if ($autor_id !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você só pode editar os seus próprios posts."]);
    exit;
}

$assunto = htmlspecialchars($assunto_raw, ENT_QUOTES, 'UTF-8');
$conteudo = htmlspecialchars($conteudo_raw, ENT_QUOTES, 'UTF-8');

$assunto_esc = mysqli_real_escape_string($conn, $assunto);
$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);
$data_edicao = date("Y-m-d H:i:s");

$sql_update = "UPDATE post
               SET assunto = '$assunto_esc', conteudo = '$conteudo_esc', Data_post = '$data_edicao'
               WHERE id_post = $id_post AND id_comunidade = $id_comunidade";

if (mysqli_query($conn, $sql_update)) {
    hit_rate_limit('edit_post');
    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Post atualizado com sucesso!",
        "id_post" => $id_post
    ]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao atualizar o post."]);
}
