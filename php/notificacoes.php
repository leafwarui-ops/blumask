<?php
require_once __DIR__ . '/security_headers.php';
include __DIR__ . '/bd.php';
require_once __DIR__ . '/admin_helpers.php';
require_once __DIR__ . '/media.php';

function resolve_notification_avatar_url($path, $nome) {
    $nome = trim((string) ($nome ?: 'Usuário'));
    $fallback = generated_avatar_url($nome);
    $value = trim((string) ($path ?? ''));

    if ($value === '') {
        return $fallback;
    }

    if (preg_match('#^(https?:)?//#i', $value) || preg_match('#^data:#i', $value)) {
        return $value;
    }

    $normalized = ltrim($value, './');
    $serverScriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDir = rtrim(dirname($serverScriptName), '/');
    if (preg_match('#/php$#', $scriptDir)) {
        $scriptDir = preg_replace('#/php$#', '', $scriptDir);
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (!is_file($absolutePath)) {
        return $fallback;
    }

    $rootPrefix = rtrim($scriptDir, '/');
    $resolved = $rootPrefix === '' ? '/' . $normalized : $rootPrefix . '/' . $normalized;
    return $resolved;
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario']['id_usuario'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'mensagem' => 'Sessão inválida.']);
    exit;
}

ensure_notification_schema($conn);

$usuarioId = (int) $_SESSION['usuario']['id_usuario'];
$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$acao = null;

if ($metodo === 'POST') {
    $acao = trim((string) ($_POST['action'] ?? ''));
} else {
    $acao = trim((string) ($_GET['action'] ?? 'list'));
}

if ($metodo === 'POST' && $acao === 'read') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'mensagem' => 'Token inválido.']);
        exit;
    }

    $conn->query("UPDATE notificacao SET lida = 1 WHERE id_usuario = $usuarioId AND lida = 0");
    echo json_encode(['ok' => true, 'unread_count' => 0]);
    exit;
}

$limit = max(1, min(20, intval($_GET['limit'] ?? 10)));

$lista = [];
$result = $conn->query("SELECT n.*, u.nome_de_exibicao AS nome_remetente, u.nome_de_usuario AS usuario_remetente, u.foto_perfil, p.assunto AS assunto_post
    FROM notificacao n
    LEFT JOIN usuario u ON u.id_usuario = n.id_remetente
    LEFT JOIN post p ON p.id_post = n.id_post
    WHERE n.id_usuario = $usuarioId
    ORDER BY n.criada_em DESC
    LIMIT $limit");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $nomeRemetente = trim((string) ($row['nome_remetente'] ?? '')) !== '' ? trim((string) $row['nome_remetente'] ?? '') : 'Alguém';
        $fotoPerfil = resolve_notification_avatar_url($row['foto_perfil'] ?? '', $nomeRemetente);

        $lista[] = [
            'id_notificacao' => (int) ($row['id_notificacao'] ?? 0),
            'id_post' => (int) ($row['id_post'] ?? 0),
            'id_comentario' => (int) ($row['id_comentario'] ?? 0),
            'nome_remetente' => $nomeRemetente,
            'usuario_remetente' => trim((string) ($row['usuario_remetente'] ?? '')),
            'foto_perfil' => $fotoPerfil,
            'mensagem' => trim((string) ($row['mensagem'] ?? '')),
            'tipo' => trim((string) ($row['tipo'] ?? 'comentario')),
            'lida' => (int) ($row['lida'] ?? 0),
            'criada_em' => (string) ($row['criada_em'] ?? ''),
        ];
    }
}

$unreadResult = $conn->query("SELECT COUNT(*) AS total FROM notificacao WHERE id_usuario = $usuarioId AND lida = 0");
$unreadCount = 0;
if ($unreadResult && $unreadResult->num_rows > 0) {
    $unreadRow = $unreadResult->fetch_assoc();
    $unreadCount = (int) ($unreadRow['total'] ?? 0);
}

echo json_encode(['ok' => true, 'notifications' => $lista, 'unread_count' => $unreadCount]);
