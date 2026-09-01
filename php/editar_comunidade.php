<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para editar uma comunidade."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança (CSRF) inválido. Recarregue a página e tente novamente."]);
    exit;
}

if (!check_rate_limit('edit_community', 10, 3600)) {
    $wait = get_rate_limit_wait_time('edit_community', 3600);
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Limite de edição excedido. Aguarde $wait antes de editar novamente."
    ]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);
$nome_raw = trim($_POST['nome'] ?? '');
$descricao_raw = str_replace(["\r\n", "\r"], "\n", trim($_POST['descricao'] ?? ''));

if ($id_comunidade <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID da comunidade inválido."]);
    exit;
}

if (mb_strlen($nome_raw) < 2 || mb_strlen($nome_raw) > 40) {
    echo json_encode(["sucesso" => false, "mensagem" => "O nome da comunidade deve ter entre 2 e 40 caracteres."]);
    exit;
}

if (!preg_match('/^[\pL\pN\s]+$/u', $nome_raw)) {
    echo json_encode(["sucesso" => false, "mensagem" => "O nome da comunidade deve conter apenas letras, números e espaços."]);
    exit;
}

if (mb_strlen($descricao_raw) > 200) {
    echo json_encode(["sucesso" => false, "mensagem" => "A descrição da comunidade não pode ter mais de 200 caracteres."]);
    exit;
}

$sql_comunidade = "SELECT id_usuario, imagem FROM comunidade WHERE id_comunidade = $id_comunidade LIMIT 1";
$resultado_comunidade = mysqli_query($conn, $sql_comunidade);

if (!$resultado_comunidade || mysqli_num_rows($resultado_comunidade) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comunidade não encontrada."]);
    exit;
}

$comunidade = mysqli_fetch_assoc($resultado_comunidade);

if (intval($comunidade['id_usuario']) !== $id_usuario) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você só pode editar comunidades que você criou."]);
    exit;
}

$nome = htmlspecialchars($nome_raw, ENT_QUOTES, 'UTF-8');
$descricao = htmlspecialchars($descricao_raw, ENT_QUOTES, 'UTF-8');

$nome_esc = mysqli_real_escape_string($conn, $nome);
$descricao_esc = mysqli_real_escape_string($conn, $descricao);

$imagem_sql = ($comunidade['imagem'] !== null && $comunidade['imagem'] !== '')
    ? "imagem = '" . mysqli_real_escape_string($conn, $comunidade['imagem']) . "'"
    : "imagem = NULL";

if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
    $size = $_FILES['imagem']['size'];
    $tmp_name = $_FILES['imagem']['tmp_name'];
    $name = $_FILES['imagem']['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if ($size > 31457280) {
        echo json_encode(["sucesso" => false, "mensagem" => "A imagem selecionada excede o limite máximo de 30MB."]);
        exit;
    }

    if (!in_array($ext, $extensoes_permitidas, true)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Formato de imagem inválido. Use JPG, PNG, GIF ou WEBP."]);
        exit;
    }

    $image_info = @getimagesize($tmp_name);
    if ($image_info === false) {
        echo json_encode(["sucesso" => false, "mensagem" => "O arquivo enviado não é uma imagem válida."]);
        exit;
    }

    $pasta_destino = __DIR__ . "/../uploads/comunidades";
    if (!is_dir($pasta_destino)) {
        mkdir($pasta_destino, 0777, true);
    }

    $nome_arquivo = "comunidade_" . uniqid() . "_" . time() . "." . $ext;
    $destino = $pasta_destino . "/" . $nome_arquivo;

    if (!move_uploaded_file($tmp_name, $destino)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao salvar a nova imagem da comunidade."]);
        exit;
    }

    $imagem_sql = "imagem = '" . mysqli_real_escape_string($conn, "uploads/comunidades/" . $nome_arquivo) . "'";
}

$sql_update = "UPDATE comunidade
               SET nome = '$nome_esc', descricao = '$descricao_esc', $imagem_sql
               WHERE id_comunidade = $id_comunidade AND id_usuario = $id_usuario";

if (mysqli_query($conn, $sql_update)) {
    hit_rate_limit('edit_community');
    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Comunidade atualizada com sucesso!"
    ]);
} else {
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Erro ao atualizar a comunidade."
    ]);
}
