<?php
session_start();
include "bd.php";

header('Content-Type: application/json; charset=utf-8');

// cargo: 1 = administrador, 0 = membro comum
// (ajuste esses valores se você já tiver outra convenção definida em outro lugar)
define('CARGO_ADMINISTRADOR', 1);

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para criar uma comunidade."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['id_usuario']);
$nome = trim($_POST['nome'] ?? '');
$descricao = trim($_POST['descricao'] ?? '');

if ($nome === '') {
    echo json_encode(["sucesso" => false, "mensagem" => "O nome da comunidade é obrigatório."]);
    exit;
}

$nome_esc = mysqli_real_escape_string($conn, $nome);
$descricao_esc = mysqli_real_escape_string($conn, $descricao);

// upload da foto da comunidade (opcional)
$imagem_path = null;
if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
    $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (in_array($ext, $extensoes_permitidas)) {
        $pasta_destino = __DIR__ . "/../uploads/comunidades";
        if (!is_dir($pasta_destino)) {
            mkdir($pasta_destino, 0755, true);
        }

        $nome_arquivo = "comunidade_" . uniqid() . "." . $ext;
        $destino = $pasta_destino . "/" . $nome_arquivo;

        if (move_uploaded_file($_FILES['imagem']['tmp_name'], $destino)) {
            $imagem_path = "uploads/comunidades/" . $nome_arquivo;
        }
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

    // o criador da comunidade se torna automaticamente administrador dela
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
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao criar comunidade: " . $e->getMessage()]);
}
