<?php
require_once __DIR__ . "/security_headers.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/activity_timestamps.php";
require_once __DIR__ . "/community_bans.php";
require_once __DIR__ . "/admin_helpers.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para comentar."]);
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

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_post = intval($_POST['id_post'] ?? 0);
$conteudo_raw = trim((string)($_POST['conteudo'] ?? ''));
$commentImageTmp = null;
$commentImageExtension = null;

if (is_site_admin($conn, $id_usuario)) {
    http_response_code(403);
    echo json_encode(["sucesso" => false, "mensagem" => "Administradores não podem comentar."]);
    exit;
}

if ($id_post <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID do post inválido."]);
    exit;
}

$sql_check_post = "SELECT p.id_post, p.id_comunidade, p.id_usuario AS post_autor_id FROM post p WHERE p.id_post = $id_post LIMIT 1";
$resultado_post = mysqli_query($conn, $sql_check_post);

if (!$resultado_post || mysqli_num_rows($resultado_post) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Post não encontrado."]);
    exit;
}

$post = mysqli_fetch_assoc($resultado_post);
$id_comunidade = intval($post['id_comunidade']);
$post_autor_id = intval($post['post_autor_id'] ?? 0);

if (is_user_banned_from_community($conn, $id_usuario, $id_comunidade)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você foi banido desta comunidade e não pode comentar."]);
    exit;
}

$sql_check_membro = "SELECT id_membro_comunidade FROM membro_comunidade WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1";
$resultado_membro = mysqli_query($conn, $sql_check_membro);

if (!$resultado_membro || mysqli_num_rows($resultado_membro) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa seguir esta comunidade para publicar posts e comentar."]);
    exit;
}

$commentImage = $_FILES['imagem'] ?? null;
$hasCommentImage = is_array($commentImage) && (int) ($commentImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
if ($hasCommentImage) {
    if ((int) ($commentImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível receber a imagem do comentário."]);
        exit;
    }

    if ((int) ($commentImage['size'] ?? 0) > 2 * 1024 * 1024) {
        echo json_encode(["sucesso" => false, "mensagem" => "A imagem do comentário deve ter no máximo 2 MB."]);
        exit;
    }

    $commentImageTmp = (string) ($commentImage['tmp_name'] ?? '');
    $imageInfo = $commentImageTmp !== '' && is_uploaded_file($commentImageTmp) ? @getimagesize($commentImageTmp) : false;
    $extensionsByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];
    $imageMime = is_array($imageInfo) ? strtolower((string) ($imageInfo['mime'] ?? '')) : '';
    if (!isset($extensionsByMime[$imageMime])) {
        echo json_encode(["sucesso" => false, "mensagem" => "Formato de imagem inválido. Use JPG, PNG, GIF, WEBP ou AVIF."]);
        exit;
    }

    $commentImageExtension = $extensionsByMime[$imageMime];
}

if ((mb_strlen($conteudo_raw) < 2 && !$hasCommentImage) || mb_strlen($conteudo_raw) > 2000) {
    echo json_encode(["sucesso" => false, "mensagem" => "Escreva ao menos 2 caracteres ou anexe uma imagem. O limite é 2000 caracteres."]);
    exit;
}

$conteudo = $conteudo_raw;
$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);

if (!ensure_activity_timestamp_columns($conn)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar a data do comentário."]);
    exit;
}

$postingLimit = consume_user_posting_limit($conn, $id_usuario, 'comentario', 60);
if ($postingLimit['error']) {
    http_response_code(503);
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível validar o limite de comentários. Tente novamente."]);
    exit;
}
if (!$postingLimit['allowed']) {
    $wait = (int) $postingLimit['retry_after'];
    http_response_code(429);
    header('Retry-After: ' . $wait);
    echo json_encode([
        "sucesso" => false,
        "limite_atingido" => true,
        "retry_after" => $wait,
        "mensagem" => "Você precisa esperar $wait segundo(s) antes de comentar novamente."
    ]);
    exit;
}

$data_comentario = date('Y-m-d H:i:s');
$imagem_path = null;

if ($hasCommentImage) {
    if (!ensure_comment_image_column($conn)) {
        http_response_code(503);
        echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar o envio de imagens."]);
        exit;
    }

    $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'comentarios';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível salvar a imagem do comentário."]);
        exit;
    }

    $imageFileName = 'comentario_' . bin2hex(random_bytes(16)) . '.' . $commentImageExtension;
    $imageDestination = $uploadDirectory . DIRECTORY_SEPARATOR . $imageFileName;
    if (!move_uploaded_file($commentImageTmp, $imageDestination)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível salvar a imagem do comentário."]);
        exit;
    }
    $imagem_path = 'uploads/comentarios/' . $imageFileName;
}

if (!ensure_comment_image_column($conn)) {
    http_response_code(503);
    if ($imagem_path !== null) {
        @unlink(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagem_path));
    }
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar os comentários."]);
    exit;
}

$imagem_sql = $imagem_path === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $imagem_path) . "'";
$sql_insert = "INSERT INTO comentario (id_usuario, id_post, conteudo, data_comentario, imagem) VALUES ($id_usuario, $id_post, '$conteudo_esc', '$data_comentario', $imagem_sql)";

$inserted = false;
try {
    $inserted = mysqli_query($conn, $sql_insert) !== false;
} catch (Throwable $e) {
    error_log('Falha ao salvar comentário: ' . $e->getMessage());
}

if ($inserted) {
    $id_comentario = (int) $conn->insert_id;

    if ($post_autor_id > 0 && $post_autor_id !== $id_usuario) {
        $nome_autor = trim((string) ($_SESSION['usuario']['nome_de_exibicao'] ?? ''));
        $nome_mensagem = $nome_autor !== '' ? $nome_autor : 'Alguém';
        $mensagem = $nome_mensagem . ' comentou no seu post.';
        try {
            create_post_comment_notification($conn, $post_autor_id, $id_usuario, $id_post, $id_comentario, $mensagem);
        } catch (Throwable $e) {
            error_log('Falha ao criar notificação de comentário: ' . $e->getMessage());
        }
    }

    echo json_encode(["sucesso" => true, "mensagem" => "Comentário enviado com sucesso!"]);
} else {
    if ($imagem_path !== null) {
        @unlink(dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagem_path));
    }
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao cadastrar comentário."]);
}
