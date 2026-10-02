<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/community_bans.php";
require_once __DIR__ . "/admin_helpers.php";
require_once __DIR__ . "/media.php";

header('Content-Type: application/json; charset=utf-8');

$id_usuario = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
$csrf = $_POST['csrf_token'] ?? '';
$id_comentario = intval($_POST['id_comentario'] ?? 0);
$conteudo = trim((string) ($_POST['conteudo'] ?? ''));

if ($id_usuario <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para editar comentários."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

require_same_origin_for_state_change();

if (!verify_csrf_token($csrf)) {
    http_response_code(403);
    echo json_encode(["sucesso" => false, "mensagem" => "Token CSRF inválido."]);
    exit;
}

if ($id_comentario <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comentário inválido."]);
    exit;
}

if (mb_strlen($conteudo) < 2) {
    echo json_encode(["sucesso" => false, "mensagem" => "O comentário deve ter no mínimo 2 caracteres."]);
    exit;
}

global $conn;

if (!ensure_comment_image_column($conn)) {
    http_response_code(503);
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar as imagens dos comentários."]);
    exit;
}

// Verifica autor do comentário
$res = mysqli_query($conn, "SELECT c.id_usuario, c.imagem, p.id_comunidade
    FROM comentario c INNER JOIN post p ON p.id_post = c.id_post
    WHERE c.id_comentario = $id_comentario LIMIT 1");
if (!$res || mysqli_num_rows($res) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comentário não encontrado."]);
    exit;
}
$row = mysqli_fetch_assoc($res);
$id_autor = intval($row['id_usuario']);

if (is_user_banned_from_community($conn, $id_usuario, intval($row['id_comunidade']))) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você foi banido desta comunidade e não pode editar comentários."]);
    exit;
}

if ($id_autor !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você só pode editar seus próprios comentários."]);
    exit;
}

$imagem_anterior = trim((string) ($row['imagem'] ?? ''));
$imagem_nova = $imagem_anterior;
$uploadedImage = $_FILES['imagem'] ?? null;
$hasUploadedImage = is_array($uploadedImage) && (int) ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

try {
    if ($hasUploadedImage) {
        $imagem_nova = store_uploaded_image($uploadedImage, 'comentarios', 'comentario');
    } elseif (($_POST['remover_imagem'] ?? '') === '1') {
        $imagem_nova = '';
    }
} catch (Throwable $e) {
    echo json_encode(["sucesso" => false, "mensagem" => $e->getMessage()]);
    exit;
}

$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);
$imagem_sql = $imagem_nova === '' ? 'NULL' : "'" . mysqli_real_escape_string($conn, $imagem_nova) . "'";
$sql = "UPDATE comentario SET conteudo = '$conteudo_esc', imagem = $imagem_sql WHERE id_comentario = $id_comentario";

try {
    $updated = mysqli_query($conn, $sql) !== false;
} catch (Throwable $e) {
    $updated = false;
}

if ($updated) {
    if ($imagem_anterior !== '' && $imagem_anterior !== $imagem_nova) {
        delete_uploaded_image($imagem_anterior, 'comentarios');
    }
    echo json_encode(["sucesso" => true, "mensagem" => "Comentário atualizado com sucesso."]);
} else {
    if ($imagem_nova !== '' && $imagem_nova !== $imagem_anterior) {
        delete_uploaded_image($imagem_nova, 'comentarios');
    }
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao atualizar comentário."]);
}
