<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
require_once __DIR__ . "/bd.php";

header('Content-Type: application/json; charset=utf-8');

// 1. Rate Limiting (máx 60 buscas por minuto)
if (!check_rate_limit('search_query', 60, 60)) {
    $wait = get_rate_limit_wait_time('search_query', 60);
    echo json_encode([
        "sucesso" => false,
        "mensagem" => "Muitas buscas realizadas em curto intervalo. Aguarde $wait para tentar novamente.",
        "usuarios" => [],
        "comunidades" => []
    ]);
    exit;
}

hit_rate_limit('search_query');

global $conn;

// 2. Leitura e Sanitização dos Parâmetros GET
$termo_raw = trim($_GET['q'] ?? '');
$tipo_raw  = trim($_GET['tipo'] ?? 'todos');
$tipo      = in_array($tipo_raw, ['todos', 'usuarios', 'comunidades'], true) ? $tipo_raw : 'todos';

// Se a busca estiver vazia ou composta apenas por curingas/espaços, retorna listas vazias
if ($termo_raw === '' || preg_match('/^[%_\s]+$/', $termo_raw)) {
    echo json_encode([
        "sucesso" => true,
        "termo" => htmlspecialchars($termo_raw, ENT_QUOTES, 'UTF-8'),
        "total" => 0,
        "usuarios" => [],
        "comunidades" => []
    ]);
    exit;
}

// Limita o termo a no máximo 100 caracteres
if (mb_strlen($termo_raw) > 100) {
    $termo_raw = mb_substr($termo_raw, 0, 100);
}

// Escapar curingas do LIKE (% e _) para que a busca seja tratada de forma literal
$termo_like = addcslashes($termo_raw, '%_\\');
$termo_esc  = mysqli_real_escape_string($conn, $termo_like);

$usuarios = [];
$comunidades = [];


// 3. Busca de Usuários (se tipo for 'todos' ou 'usuarios')
if ($tipo === 'todos' || $tipo === 'usuarios') {
    $sql_usuarios = "SELECT id_usuario, nome_de_exibicao, nome_de_usuario, descricao, banner, foto_perfil
                     FROM usuario
                     WHERE nome_de_usuario LIKE '%$termo_esc%' 
                        OR nome_de_exibicao LIKE '%$termo_esc%'
                     ORDER BY 
                        CASE 
                            WHEN nome_de_usuario LIKE '$termo_esc%' THEN 1
                            WHEN nome_de_exibicao LIKE '$termo_esc%' THEN 2
                            ELSE 3
                        END,
                        nome_de_exibicao ASC
                     LIMIT 10";

    $res_usuarios = mysqli_query($conn, $sql_usuarios);

    if ($res_usuarios) {
        while ($row = mysqli_fetch_assoc($res_usuarios)) {
            $nome_exb = htmlspecialchars($row['nome_de_exibicao'] ?? '', ENT_QUOTES, 'UTF-8');
            $foto = $row['foto_perfil'] ? htmlspecialchars($row['foto_perfil'], ENT_QUOTES, 'UTF-8') : "https://ui-avatars.com/api/?name=" . urlencode($nome_exb ?: 'User') . "&background=random";
            $banner = $row['banner'] ? htmlspecialchars($row['banner'], ENT_QUOTES, 'UTF-8') : null;

            $usuarios[] = [
                "id_usuario"       => intval($row['id_usuario']),
                "nome_de_exibicao" => $nome_exb,
                "nome_de_usuario"  => htmlspecialchars($row['nome_de_usuario'] ?? '', ENT_QUOTES, 'UTF-8'),
                "descricao"        => htmlspecialchars($row['descricao'] ?? '', ENT_QUOTES, 'UTF-8'),
                "foto_perfil"      => $foto,
                "banner"           => $banner
            ];
        }
    }
}

// 4. Busca de Comunidades (se tipo for 'todos' ou 'comunidades')
if ($tipo === 'todos' || $tipo === 'comunidades') {
    $sql_comunidades = "SELECT c.id_comunidade, c.nome, c.descricao, c.imagem, c.data_criacao,
                               COUNT(mc.id_membro_comunidade) AS total_membros
                        FROM comunidade c
                        LEFT JOIN membro_comunidade mc ON mc.id_comunidade = c.id_comunidade
                        WHERE c.nome LIKE '%$termo_esc%' 
                           OR c.descricao LIKE '%$termo_esc%'
                        GROUP BY c.id_comunidade, c.nome, c.descricao, c.imagem, c.data_criacao
                        ORDER BY 
                            CASE 
                                WHEN c.nome LIKE '$termo_esc%' THEN 1
                                ELSE 2
                            END,
                            total_membros DESC,
                            c.nome ASC
                        LIMIT 10";

    $res_comunidades = mysqli_query($conn, $sql_comunidades);

    if ($res_comunidades) {
        while ($row = mysqli_fetch_assoc($res_comunidades)) {
            $nome_com = htmlspecialchars($row['nome'] ?? '', ENT_QUOTES, 'UTF-8');
            $imagem = $row['imagem'] ? htmlspecialchars($row['imagem'], ENT_QUOTES, 'UTF-8') : "https://ui-avatars.com/api/?name=" . urlencode($nome_com ?: 'Comunidade') . "&background=random";

            $comunidades[] = [
                "id_comunidade" => intval($row['id_comunidade']),
                "nome"          => $nome_com,
                "descricao"     => htmlspecialchars($row['descricao'] ?? '', ENT_QUOTES, 'UTF-8'),
                "imagem"        => $imagem,
                "data_criacao"  => $row['data_criacao'] ? htmlspecialchars($row['data_criacao'], ENT_QUOTES, 'UTF-8') : null,
                "total_membros" => intval($row['total_membros'] ?? 0)
            ];
        }
    }
}

$total = count($usuarios) + count($comunidades);

echo json_encode([
    "sucesso"     => true,
    "termo"       => htmlspecialchars($termo_raw, ENT_QUOTES, 'UTF-8'),
    "total"       => $total,
    "usuarios"    => $usuarios,
    "comunidades" => $comunidades
]);
