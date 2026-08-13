<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

define('CARGO_ADMINISTRADOR', 1);

// 1. Verificação de Autenticação
if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para criar uma comunidade."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

// 2. Verificação de CSRF Token
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança (CSRF) inválido. Recarregue a página e tente novamente."]);
    exit;
}

// 3. Verificação de Rate Limit (máx 3 comunidades por hora / 3600s)
if (!check_rate_limit('create_community', 3, 3600)) {
    $wait = get_rate_limit_wait_time('create_community', 3600);
    echo json_encode([
        "sucesso" => false, 
        "mensagem" => "Limite de criação excedido. Por favor, aguarde $wait para criar uma nova comunidade."
    ]);
    exit;
}

hit_rate_limit('create_community');

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$nome_raw = trim($_POST['nome'] ?? '');
$descricao_raw = str_replace(["\r\n", "\r"], "\n", trim($_POST['descricao'] ?? ''));

// 4. Validação do Nome da Comunidade (2 a 40 caracteres)
$len_nome = mb_strlen($nome_raw);
if ($len_nome < 2 || $len_nome > 40) {
    echo json_encode(["sucesso" => false, "mensagem" => "O nome da comunidade deve ter entre 2 e 40 caracteres."]);
    exit;
}

// 5. Validação da Descrição (máximo 200 caracteres)
if (mb_strlen($descricao_raw) > 200) {
    echo json_encode(["sucesso" => false, "mensagem" => "A descrição não pode ter mais de 200 caracteres."]);
    exit;
}

// Sanitização estrita contra XSS
$nome = htmlspecialchars($nome_raw, ENT_QUOTES, 'UTF-8');
$descricao = htmlspecialchars($descricao_raw, ENT_QUOTES, 'UTF-8');

$nome_esc = mysqli_real_escape_string($conn, $nome);
$descricao_esc = mysqli_real_escape_string($conn, $descricao);

// 6. Processamento de Upload da Imagem (máximo 30MB)
$imagem_path = null;
$max_file_size = 31457280; // 30MB
$extensoes_permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
    $size = $_FILES['imagem']['size'];
    $tmp_name = $_FILES['imagem']['tmp_name'];
    $name = $_FILES['imagem']['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if ($size > $max_file_size) {
        echo json_encode(["sucesso" => false, "mensagem" => "A imagem selecionada excede o limite máximo de 30MB."]);
        exit;
    }

    if (!in_array($ext, $extensoes_permitidas)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Formato de imagem inválido. Use JPG, PNG, GIF ou WEBP."]);
        exit;
    }

    // Validação de tipo real de imagem (impede web shells e polyglots)
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

    if (move_uploaded_file($tmp_name, $destino)) {
        $imagem_path = "uploads/comunidades/" . $nome_arquivo;
    } else {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao salvar a foto da comunidade no servidor."]);
        exit;
    }
}

$data_criacao = date("Y-m-d");
$imagem_sql = $imagem_path ? "'" . mysqli_real_escape_string($conn, $imagem_path) . "'" : "NULL";

mysqli_begin_transaction($conn);

try {
    $sql_comunidade = "INSERT INTO comunidade (data_criacao, descricao, nome, id_usuario, imagem)
                       VALUES ('$data_criacao', '$descricao_esc', '$nome_esc', $id_usuario, $imagem_sql)";

    if (!mysqli_query($conn, $sql_comunidade)) {
        throw new Exception(mysqli_error($conn));
    }

    $id_comunidade = mysqli_insert_id($conn);

    // O criador da comunidade se torna automaticamente administrador dela
    $sql_membro = "INSERT INTO membro_comunidade (id_usuario, id_comunidade, cargo, data_entrada)
                   VALUES ($id_usuario, $id_comunidade, " . CARGO_ADMINISTRADOR . ", '$data_criacao')";

    if (!mysqli_query($conn, $sql_membro)) {
        throw new Exception(mysqli_error($conn));
    }

    mysqli_commit($conn);

    echo json_encode([
        "sucesso" => true,
        "comunidade" => [
            "id_comunidade" => $id_comunidade,
            "nome" => $nome,
            "descricao" => $descricao,
            "imagem" => $imagem_path,
            "cargo" => CARGO_ADMINISTRADOR
        ]
    ]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao criar comunidade."]);
}
