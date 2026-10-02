<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/community_bans.php";
require_once __DIR__ . "/media.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para editar um post."]);
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

if (!check_rate_limit('edit_post', 20, 3600)) {
    $wait = get_rate_limit_wait_time('edit_post', 3600);
    echo json_encode(["sucesso" => false, "mensagem" => "Limite de edição de posts excedido. Aguarde $wait."]);
    exit;
}

global $conn;

$imageColumn = mysqli_query($conn, "SHOW COLUMNS FROM post LIKE 'imagem'");
if (!$imageColumn || (mysqli_num_rows($imageColumn) === 0 && !mysqli_query($conn, "ALTER TABLE post ADD COLUMN imagem VARCHAR(255) NULL AFTER assunto"))) {
    http_response_code(503);
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar as imagens dos posts."]);
    exit;
}

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_post = normalize_positive_id($_POST['id_post'] ?? 0, 0);
$id_comunidade = normalize_positive_id($_POST['id_comunidade'] ?? 0, 0);
$community_token = (string) ($_POST['community_token'] ?? '');
$assunto_raw = trim($_POST['assunto'] ?? '');
$conteudo_raw = str_replace(["\r\n", "\r"], "\n", trim($_POST['conteudo'] ?? ''));

if ((int) ($_SESSION['blumask_current_community_id'] ?? 0) !== $id_comunidade || !verify_community_context_token($id_comunidade, $community_token)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Contexto da comunidade inválido. A ação foi bloqueada por segurança."]);
    exit;
}

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
             , p.imagem
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

if (is_user_banned_from_community($conn, $id_usuario, $id_comunidade)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você foi banido desta comunidade e não pode editar posts."]);
    exit;
}

$autor_id = intval($post['autor_id']);

if ($autor_id !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você só pode editar os seus próprios posts."]);
    exit;
}

$assunto = $assunto_raw;
$conteudo = $conteudo_raw;

$assunto_esc = mysqli_real_escape_string($conn, $assunto);
$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);
$data_edicao = date("Y-m-d H:i:s");
$imagem_anterior = trim((string) ($post['imagem'] ?? ''));
$imagem_nova = $imagem_anterior;
$uploadedImage = $_FILES['imagem'] ?? null;
$hasUploadedImage = is_array($uploadedImage) && (int) ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

try {
    if ($hasUploadedImage) {
        $imagem_nova = store_uploaded_image($uploadedImage, 'posts', 'post');
    } elseif (($_POST['remover_imagem'] ?? '') === '1') {
        $imagem_nova = '';
    }
} catch (Throwable $e) {
    echo json_encode(["sucesso" => false, "mensagem" => $e->getMessage()]);
    exit;
}

$imagem_sql = $imagem_nova === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $imagem_nova) . "'";

$sql_update = "UPDATE post
               SET assunto = '$assunto_esc', conteudo = '$conteudo_esc', imagem = $imagem_sql, Data_post = '$data_edicao'
               WHERE id_post = $id_post AND id_comunidade = $id_comunidade";

try {
    $updated = mysqli_query($conn, $sql_update) !== false;
} catch (Throwable $e) {
    $updated = false;
}

if ($updated) {
    if ($imagem_anterior !== '' && $imagem_anterior !== $imagem_nova) {
        delete_uploaded_image($imagem_anterior, 'posts');
    }
    hit_rate_limit('edit_post');
    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Post atualizado com sucesso!",
        "id_post" => $id_post
    ]);
} else {
    if ($imagem_nova !== '' && $imagem_nova !== $imagem_anterior) {
        delete_uploaded_image($imagem_nova, 'posts');
    }
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao atualizar o post."]);
}
