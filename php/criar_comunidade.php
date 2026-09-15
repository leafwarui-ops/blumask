<?php
/**
 * ARQUIVO: criar_comunidade.php
 * DESCRIÇÃO: Processa a criação de uma nova comunidade no sistema
 * MÉTODO: POST (via AJAX)
 * RETORNA: JSON com sucesso/erro
 * SEGURANÇA: CSRF, Rate Limiting, Validação de entrada, Sanitização, Upload seguro
 */

// Inclui módulos de segurança (CSRF) e rate limiting (protege contra spam)
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
// Inclui arquivo de conexão com banco de dados
include __DIR__ . "/bd.php";

// Define o tipo de resposta como JSON para o cliente
header('Content-Type: application/json; charset=utf-8');

// Define constante para cargo de administrador (criador = admin automático)
define('CARGO_ADMINISTRADOR', 1);

// ============================================================================
// SEÇÃO 1: VERIFICAÇÕES DE SEGURANÇA E AUTENTICAÇÃO
// ============================================================================

// 1.1 - Verifica se usuário está logado (sessão ativa)
if (!isset($_SESSION['usuario'])) {
    // Se não está logado, retorna erro JSON
    echo json_encode(["sucesso" => false, "mensagem" => "Você precisa estar logado para criar uma comunidade."]);
    exit;
}

// 1.2 - Verifica se o método HTTP é POST (formulário foi enviado)
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    // Se veio via GET ou outro método, retorna erro
    echo json_encode(["sucesso" => false, "mensagem" => "Método inválido."]);
    exit;
}

// 1.3 - Verifica se o token CSRF é válido (proteção contra ataques CSRF)
if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    // Token inválido ou ausente: requisição suspeita
    echo json_encode(["sucesso" => false, "mensagem" => "Token de segurança (CSRF) inválido. Recarregue a página e tente novamente."]);
    exit;
}

// 1.4 - Verifica Rate Limit: máximo 3 comunidades por hora (3600 segundos)
// Proteção contra spam de criação de comunidades
if (!check_rate_limit('create_community', 3, 3600)) {
    // Calcula tempo de espera formatado
    $wait = get_rate_limit_wait_time('create_community', 3600);
    echo json_encode([
        "sucesso" => false, 
        "mensagem" => "Limite de criação excedido. Por favor, aguarde $wait para criar uma nova comunidade."
    ]);
    exit;
}

// Registra a tentativa de criação (conta para o rate limit)
hit_rate_limit('create_community');

// ============================================================================
// SEÇÃO 2: OBTENÇÃO E SANITIZAÇÃO DE PARÂMETROS
// ============================================================================

global $conn;

// Obtém ID do usuário logado (garantidamente um inteiro)
$id_usuario = intval($_SESSION['usuario']['id_usuario']);

// Obtém nome da comunidade e remove espaços extras de início/fim
$nome_raw = trim($_POST['nome'] ?? '');

// Obtém descrição e normaliza quebras de linha (remove \r)
$descricao_raw = str_replace(["\r\n", "\r"], "\n", trim($_POST['descricao'] ?? ''));

// ============================================================================
// SEÇÃO 3: VALIDAÇÕES DE COMPRIMENTO E CONTEÚDO
// ============================================================================

// 3.1 - Valida comprimento do nome (2 a 40 caracteres)
$len_nome = mb_strlen($nome_raw);
if ($len_nome < 2 || $len_nome > 40) {
    echo json_encode(["sucesso" => false, "mensagem" => "O nome da comunidade deve ter entre 2 e 40 caracteres."]);
    exit;
}

// 3.2 - Valida que o nome contém apenas letras, números e espaços (sem caracteres especiais)
// O regex \pL\pN\s = Unicode letters, unicode numbers, whitespace
if (!preg_match('/^[\pL\pN\s]+$/u', $nome_raw)) {
    echo json_encode(["sucesso" => false, "mensagem" => "O nome da comunidade deve conter apenas letras, números e espaços."]);
    exit;
}

// 3.3 - Valida comprimento da descrição (máximo 200 caracteres)
if (mb_strlen($descricao_raw) > 200) {
    echo json_encode(["sucesso" => false, "mensagem" => "A descrição não pode ter mais de 200 caracteres."]);
    exit;
}

// ============================================================================
// SEÇÃO 4: SANITIZAÇÃO CONTRA XSS
// ============================================================================

// Converte caracteres especiais em HTML entities para evitar XSS
$nome = htmlspecialchars($nome_raw, ENT_QUOTES, 'UTF-8');
$descricao = htmlspecialchars($descricao_raw, ENT_QUOTES, 'UTF-8');

// Escapa a string para uso em query SQL (ainda que htmlspecialchars já tenha limpado XSS)
$nome_esc = mysqli_real_escape_string($conn, $nome);
$descricao_esc = mysqli_real_escape_string($conn, $descricao);

// ============================================================================
// SEÇÃO 5: PROCESSAMENTO DE UPLOAD DA IMAGEM
// ============================================================================

$imagem_path = null;
$extensoes_permitidas = ['jpg', 'jpeg', 'png', 'gif', 'webp']; // Formatos de imagem aceitos

// Verifica se um arquivo de imagem foi enviado e sem erros de upload
if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
    // Obtém informações do arquivo enviado
    $size = $_FILES['imagem']['size'];
    $tmp_name = $_FILES['imagem']['tmp_name'];
    $name = $_FILES['imagem']['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); // Extensão em minúsculas

    // Valida se a extensão está na lista de permitidas
    if (!in_array($ext, $extensoes_permitidas)) {
        echo json_encode(["sucesso" => false, "mensagem" => "Formato de imagem inválido. Use JPG, PNG, GIF ou WEBP."]);
        exit;
    }

    // Valida se o arquivo é realmente uma imagem (não é web shell ou polyglot)
    $image_info = @getimagesize($tmp_name);
    if ($image_info === false) {
        // getimagesize retorna false se não for imagem válida
        echo json_encode(["sucesso" => false, "mensagem" => "O arquivo enviado não é uma imagem válida."]);
        exit;
    }

    // Cria diretório de destino se não existir
    $pasta_destino = __DIR__ . "/../uploads/comunidades";
    if (!is_dir($pasta_destino)) {
        mkdir($pasta_destino, 0777, true);
    }

    // Gera nome único do arquivo para evitar colisões
    // Formato: comunidade_[id-único]_[timestamp].[extensão]
    $nome_arquivo = "comunidade_" . uniqid() . "_" . time() . "." . $ext;
    $destino = $pasta_destino . "/" . $nome_arquivo;

    // Move arquivo do upload temporário para o destino final
    if (move_uploaded_file($tmp_name, $destino)) {
        // Armazena caminho relativo (para usar em <img src>)
        $imagem_path = "uploads/comunidades/" . $nome_arquivo;
    } else {
        echo json_encode(["sucesso" => false, "mensagem" => "Erro ao salvar a foto da comunidade no servidor."]);
        exit;
    }
}

// ============================================================================
// SEÇÃO 6: INSERÇÃO NO BANCO DE DADOS COM TRANSAÇÃO
// ============================================================================

// Obtem data atual no formato YYYY-MM-DD
$data_criacao = date("Y-m-d");

// Prepara valor da imagem para a query (NULL se vazio, ou string escapada)
$imagem_sql = $imagem_path ? "'" . mysqli_real_escape_string($conn, $imagem_path) . "'" : "NULL";

// Inicia transação: garante que ambas as queries (comunidade + membro) sejam executadas ou nenhuma
mysqli_begin_transaction($conn);

try {
    // Query 1: Insere a nova comunidade na tabela
    $sql_comunidade = "INSERT INTO comunidade (data_criacao, descricao, nome, id_usuario, imagem)
                       VALUES ('$data_criacao', '$descricao_esc', '$nome_esc', $id_usuario, $imagem_sql)";

    // Executa a inserção
    if (!mysqli_query($conn, $sql_comunidade)) {
        // Se falhou, lança exceção para rollback
        throw new Exception(mysqli_error($conn));
    }

    // Obtém o ID auto-incrementado da comunidade criada
    $id_comunidade = mysqli_insert_id($conn);

    // Query 2: O criador se torna automaticamente administrador da comunidade
    $sql_membro = "INSERT INTO membro_comunidade (id_usuario, id_comunidade, cargo, data_entrada)
                   VALUES ($id_usuario, $id_comunidade, " . CARGO_ADMINISTRADOR . ", '$data_criacao')";

    // Executa a inserção do membro
    if (!mysqli_query($conn, $sql_membro)) {
        throw new Exception(mysqli_error($conn));
    }

    // Se chegou aqui, ambas queries executaram com sucesso
    // Confirma a transação (commit)
    mysqli_commit($conn);

    // Retorna resposta de sucesso com dados da comunidade criada
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
    // Se qualquer coisa falhou, desfaz as mudanças (rollback)
    mysqli_rollback($conn);
    // Retorna erro genérico (sem detalhar o erro interno por segurança)
    echo json_encode(["sucesso" => false, "mensagem" => "Erro ao criar comunidade."]);
}
// ============================================================================
// FIM DO ARQUIVO criar_comunidade.php
// ============================================================================
?>
