<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario'])) {
    echo json_encode(["sucesso" => false, "mensagem" => "Não autenticado."]);
    exit;
}

// Proteção contra spams rápidos de polling (máx 30 requisições / minuto)
if (!check_rate_limit('fetch_communities', 30, 60)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Muitas requisições. Aguarde um momento."]);
    exit;
}

global $conn;
$id_usuario = intval($_SESSION['usuario']['id_usuario']);

// Traz as comunidades que o usuário administra ou das quais é membro
$sql = "SELECT c.id_comunidade, c.nome, c.imagem, mc.cargo
        FROM comunidade c
        INNER JOIN membro_comunidade mc ON mc.id_comunidade = c.id_comunidade
        WHERE mc.id_usuario = $id_usuario
        ORDER BY mc.data_entrada DESC";

$resultado = mysqli_query($conn, $sql);

if (!$resultado) {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao buscar comunidades."]);
    exit;
}

$comunidades = [];
while ($linha = mysqli_fetch_assoc($resultado)) {
    $comunidades[] = [
        "id_comunidade" => intval($linha['id_comunidade']),
        "nome" => htmlspecialchars($linha['nome'], ENT_QUOTES, 'UTF-8'),
        "imagem" => $linha['imagem'] ? htmlspecialchars($linha['imagem'], ENT_QUOTES, 'UTF-8') : null,
        "cargo" => intval($linha['cargo'])
    ];
}

hit_rate_limit('fetch_communities');
echo json_encode(["sucesso" => true, "comunidades" => $comunidades]);
