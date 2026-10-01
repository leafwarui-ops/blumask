<?php
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/bd.php';
require_once __DIR__ . '/admin_message_store.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function admin_message_json(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$userId = (int) ($_SESSION['usuario']['id_usuario'] ?? 0);
if ($userId <= 0) {
    admin_message_json(['sucesso' => false, 'mensagem' => 'Não autenticado.'], 401);
}

if (!ensure_admin_message_schema($conn)) {
    admin_message_json(['sucesso' => false, 'mensagem' => 'Serviço indisponível.'], 503);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['acao'] ?? '') === 'proxima') {
    $statement = $conn->prepare("SELECT id_mensagem, mensagem, enviada_em
        FROM mensagem_administrativa
        WHERE id_destinatario = ? AND fechada_em IS NULL
        ORDER BY enviada_em ASC, id_mensagem ASC
        LIMIT 1");
    if (!$statement) {
        admin_message_json(['sucesso' => false, 'mensagem' => 'Serviço indisponível.'], 503);
    }

    $statement->bind_param('i', $userId);
    $statement->execute();
    $result = $statement->get_result();
    $message = $result ? $result->fetch_assoc() : null;
    $statement->close();

    admin_message_json([
        'sucesso' => true,
        'csrf_token' => get_csrf_token(),
        'mensagem' => $message ? [
            'id' => (int) $message['id_mensagem'],
            'texto' => $message['mensagem'],
            'enviada_em' => $message['enviada_em']
        ] : null
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['acao'] ?? '') !== 'fechar') {
    admin_message_json(['sucesso' => false, 'mensagem' => 'Método inválido.'], 405);
}

require_same_origin_for_state_change();

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    admin_message_json(['sucesso' => false, 'mensagem' => 'Token CSRF inválido.'], 403);
}

$messageId = (int) ($_POST['id_mensagem'] ?? 0);
if ($messageId <= 0) {
    admin_message_json(['sucesso' => false, 'mensagem' => 'Mensagem inválida.'], 400);
}

$statement = $conn->prepare("UPDATE mensagem_administrativa
    SET fechada_em = NOW()
    WHERE id_mensagem = ? AND id_destinatario = ? AND fechada_em IS NULL");
if (!$statement) {
    admin_message_json(['sucesso' => false, 'mensagem' => 'Não foi possível fechar a mensagem.'], 503);
}

$statement->bind_param('ii', $messageId, $userId);
$success = $statement->execute();
$statement->close();

admin_message_json(['sucesso' => $success]);
?>
