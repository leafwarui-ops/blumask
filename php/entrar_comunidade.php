<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/community_bans.php";
require_once __DIR__ . "/admin_helpers.php";

header('Content-Type: application/json; charset=utf-8');

define('CARGO_ADMINISTRADOR', 1);
define('CARGO_MEMBRO', 0);

// 1. Verificação de Autenticação
if (!isset($_SESSION['usuario'])) {
    http_response_code(401);
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para entrar em uma comunidade."]);
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

// 3. Verificação de Rate Limit (máx 10 entradas por minuto)
if (!check_rate_limit('join_community', 10, 60)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Muitas requisições. Aguarde um momento."]);
    exit;
}

global $conn;

$id_usuario = intval($_SESSION['usuario']['id_usuario']);
$id_comunidade = intval($_POST['id_comunidade'] ?? 0);
$community_token = (string) ($_POST['community_token'] ?? '');

if ((int) ($_SESSION['blumask_current_community_id'] ?? 0) !== $id_comunidade || !verify_community_context_token($id_comunidade, $community_token)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Contexto da comunidade inválido. A ação foi bloqueada por segurança."]);
    exit;
}

if (is_site_admin($conn, $id_usuario)) {
    http_response_code(403);
    echo json_encode(["sucesso" => false, "mensagem" => "Administradores não podem seguir comunidades."]);
    exit;
}

// 4. Validação do ID da Comunidade
if ($id_comunidade <= 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "ID da comunidade inválido."]);
    exit;
}

// 5. Verificar se a comunidade existe
$sql_verif = "SELECT id_comunidade FROM comunidade WHERE id_comunidade = $id_comunidade LIMIT 1";
$resultado = mysqli_query($conn, $sql_verif);

if (!$resultado || mysqli_num_rows($resultado) === 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Comunidade não encontrada."]);
    exit;
}

if (is_user_banned_from_community($conn, $id_usuario, $id_comunidade)) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você foi banido desta comunidade e não pode voltar a participar."]);
    exit;
}

// 6. Verificar se o usuário já é membro da comunidade
$sql_check = "SELECT id_membro_comunidade FROM membro_comunidade 
              WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1";
$resultado_check = mysqli_query($conn, $sql_check);

if ($resultado_check && mysqli_num_rows($resultado_check) > 0) {
    echo json_encode(["sucesso" => false, "mensagem" => "Você já é membro desta comunidade."]);
    exit;
}

// 7. Adicionar o usuário como membro da comunidade
$data_entrada = date("Y-m-d");
$sql_insert = "INSERT INTO membro_comunidade (id_usuario, id_comunidade, cargo, data_entrada)
               VALUES ($id_usuario, $id_comunidade, " . CARGO_MEMBRO . ", '$data_entrada')";

if (mysqli_query($conn, $sql_insert)) {
    hit_rate_limit('join_community');
    echo json_encode([
        "sucesso" => true,
        "mensagem" => "Você agora é membro desta comunidade!"
    ]);
} else {
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao entrar na comunidade."]);
}
