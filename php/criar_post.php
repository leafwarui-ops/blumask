<?php
require_once __DIR__ . "/security_headers.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/activity_timestamps.php";
require_once __DIR__ . "/community_bans.php";
require_once __DIR__ . "/admin_helpers.php";

header('Content-Type: application/json; charset=utf-8');

function ensure_post_image_column(mysqli $conn): bool {
    $result = $conn->query("SHOW COLUMNS FROM post LIKE 'imagem'");
    if ($result && $result->num_rows > 0) {
        return true;
    }

    return $conn->query("ALTER TABLE post ADD COLUMN imagem VARCHAR(255) NULL AFTER assunto") !== false;
}

if (!ensure_post_image_column($conn)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar o campo de imagem do post."]);
    exit;
}
if (!ensure_post_public_id_column($conn)) {
    http_response_code(503);
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar o identificador do post."]);
    exit;
}

// 1. Verificação de Autenticação
if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para criar um post."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

require_same_origin_for_state_change();

// 2. Verificação de CSRF Token
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança (CSRF) inválido."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);
$community_token = (string) ($_POST['community_token'] ?? '');
$assunto_raw = trim($_POST['assunto'] ?? '');
$conteudo_raw = str_replace(["\r\n", "\r"], "\n", trim($_POST['conteudo'] ?? ''));

if ((int) ($_SESSION['blumask_current_community_id'] ?? 0) !== $id_comunidade || !verify_community_context_token($id_comunidade, $community_token)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Contexto da comunidade inválido. A ação foi bloqueada por segurança."]);
    exit;
}

if (is_site_admin($conn, $id_usuario)) {
    http_response_code(403);
    echo json_encode(["sucesso" => false, "mensagem" => "Administradores não podem criar posts."]);
    exit;
}

// 4. Validações
if ($id_comunidade <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID da comunidade inválido."]);
    exit;
}

if (is_user_banned_from_community($conn, $id_usuario, $id_comunidade)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você foi banido desta comunidade e não pode publicar."]);
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
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa seguir esta comunidade para publicar posts e comentar."]);
    exit;
}

// 6. Verificar se comunidade existe
$sql_check_comunidade = "SELECT id_comunidade FROM comunidade WHERE id_comunidade = $id_comunidade LIMIT 1";
$resultado_comunidade = mysqli_query($conn, $sql_check_comunidade);

if (!$resultado_comunidade || mysqli_num_rows($resultado_comunidade) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comunidade não encontrada."]);
    exit;
}

// 7. Dados são escapados na saída HTML, não antes de persistir.
$assunto = $assunto_raw;
$conteudo = $conteudo_raw;

$assunto_esc = mysqli_real_escape_string($conn, $assunto);
$conteudo_esc = mysqli_real_escape_string($conn, $conteudo);

$imagem_path = null;
$uploadMaxBytes = 2 * 1024 * 1024;
$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

if (isset($_FILES['imagem']) && is_array($_FILES['imagem']) && ($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao receber a imagem do post."]);
        exit;
    }

    if ((int) $_FILES['imagem']['size'] > $uploadMaxBytes) {
        echo json_encode(["sucesso" => false, "mensagem" => "A imagem do post deve ter no máximo 2 MB."]);
        exit;
    }

    $tmpName = $_FILES['imagem']['tmp_name'] ?? '';
    $mimeType = function_exists('mime_content_type') ? mime_content_type($tmpName) : null;
    $extension = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
    $validExtension = in_array($extension, ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'avif'], true);
    if (!$tmpName || !is_uploaded_file($tmpName) || (!in_array($mimeType, $allowedMimeTypes, true) && !$validExtension)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Formato de imagem inválido. Use JPG, JPEG, JFIF, PNG, GIF, WEBP ou AVIF."]);
        exit;
    }

    $uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'posts';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'jpg',
        };
    }

    $fileName = uniqid('post_', true) . '.' . $ext;
    $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpName, $destination)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível salvar a imagem do post."]);
        exit;
    }

    $imagem_path = 'uploads/posts/' . $fileName;
}

// 8. Inserir o post
if (!ensure_activity_timestamp_columns($conn)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível preparar a data do post."]);
    exit;
}

$postingLimit = consume_user_posting_limit($conn, $id_usuario, 'post', 60);
if ($postingLimit['error']) {
    http_response_code(503);
    echo json_encode(["sucesso" => false, "mensagem" => "Não foi possível validar o limite de publicação. Tente novamente."]);
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
        "mensagem" => "Você precisa esperar $wait segundo(s) antes de postar novamente."
    ]);
    exit;
}

$data_post = date("Y-m-d H:i:s");
$public_id = bin2hex(random_bytes(16));
$imagem_sql = $imagem_path ? ", imagem" : "";
$imagem_value = $imagem_path ? ", '" . mysqli_real_escape_string($conn, $imagem_path) . "'" : "";
$sql_insert = "INSERT INTO post (id_comunidade, Data_post, conteudo, id_usuario, assunto, public_id" . $imagem_sql . ")
               VALUES ($id_comunidade, '$data_post', '$conteudo_esc', $id_usuario, '$assunto_esc', '$public_id'" . $imagem_value . ")";

if (mysqli_query($conn, $sql_insert)) {
    $id_post = mysqli_insert_id($conn);

    try {
        if (!create_mention_notifications($conn, $assunto . "\n" . $conteudo, $id_usuario, $id_post)) {
            error_log('Falha ao criar notificações de menção do post ' . $id_post);
        }
    } catch (Throwable $e) {
        error_log('Falha ao criar notificações de menção do post ' . $id_post . ': ' . $e->getMessage());
    }

    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Post criado com sucesso!",
        "id_post" => $id_post,
        "public_id" => $public_id
    ]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao criar post."]);
}
