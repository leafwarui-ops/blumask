<?php
session_start();
include "bd.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Não autenticado."]);
    exit;
}

global $conn;
$id_usuario = intval($_SESSION['id_usuario']);

// traz as comunidades que o usuário administra ou das quais é membro
$sql = "SELECT c.id_comunidade, c.nome, c.imagem, mc.cargo
        FROM comunidade c
        INNER JOIN membro_comunidade mc ON mc.id_comunidade = c.id_comunidade
        WHERE mc.id_usuario = $id_usuario
        ORDER BY mc.data_entrada DESC";

$resultado = mysqli_query($conn, $sql);

if (!$resultado) {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao buscar comunidades: " . mysqli_error($conn)]);
    exit;
}

$comunidades = [];
while ($linha = mysqli_fetch_assoc($resultado)) {
    $linha['cargo'] = intval($linha['cargo']);
    $comunidades[] = $linha;
}

echo json_encode(["sucesso" => true, "comunidades" => $comunidades]);
