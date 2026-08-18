<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

// Se o usuário não está logado, redireciona para a página inicial
if (!isset($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit;
}

// Obtém o ID da comunidade da URL
$id_comunidade = intval($_GET['id'] ?? 0);

if ($id_comunidade <= 0) {
    header("Location: ../index.php");
    exit;
}

global $conn;
$id_usuario = intval($_SESSION['usuario']['id_usuario']);

// Buscar dados da comunidade
$sql_comunidade = "SELECT c.*, u.nome_de_exibicao, u.nome_de_usuario
                   FROM comunidade c
                   LEFT JOIN usuario u ON c.id_usuario = u.id_usuario
                   WHERE c.id_comunidade = $id_comunidade LIMIT 1";

$resultado_comunidade = mysqli_query($conn, $sql_comunidade);

if (!$resultado_comunidade || mysqli_num_rows($resultado_comunidade) === 0) {
    header("Location: ../index.php");
    exit;
}

$comunidade = mysqli_fetch_assoc($resultado_comunidade);

// Verificar se o usuário é membro da comunidade
$sql_membro = "SELECT cargo FROM membro_comunidade 
               WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1";
$resultado_membro = mysqli_query($conn, $sql_membro);
$eh_membro = false;
$cargo_usuario = null;

if ($resultado_membro && mysqli_num_rows($resultado_membro) > 0) {
    $eh_membro = true;
    $membro_info = mysqli_fetch_assoc($resultado_membro);
    $cargo_usuario = intval($membro_info['cargo']);
}

$eh_admin = $eh_membro && $cargo_usuario === 1;

// Buscar posts da comunidade
$sqlPosts = "SELECT p.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil, u.id_usuario,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) as total_curtidas,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario) as curtiu
             FROM post p
             JOIN usuario u ON p.id_usuario = u.id_usuario
             WHERE p.id_comunidade = $id_comunidade
             ORDER BY p.Data_post DESC";

$postsResult = mysqli_query($conn, $sqlPosts);
$posts = [];

if ($postsResult) {
    while ($post = mysqli_fetch_assoc($postsResult)) {
        $posts[] = $post;
    }
}

// Contar membros
$sql_count_membros = "SELECT COUNT(*) as total FROM membro_comunidade WHERE id_comunidade = $id_comunidade";
$resultado_count = mysqli_query($conn, $sql_count_membros);
$total_membros = 0;

if ($resultado_count) {
    $count_row = mysqli_fetch_assoc($resultado_count);
    $total_membros = intval($count_row['total']);
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($comunidade['nome'], ENT_QUOTES, 'UTF-8') ?> - BluMask</title>
    <link rel="icon" type="image/webp" href="../style/blumaskWhiteLogo.webp">
    <link rel="stylesheet" href="../style/index_style.css">
    <link rel="stylesheet" href="../style/comunidade_style.css?v=<?= time() ?>">
    <style>
        .comunidade-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px 20px;
            text-align: center;
            position: relative;
            border-radius: 0 0 15px 15px;
        }

        .comunidade-header img {
            max-width: 200px;
            width: 100%;
            height: auto;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .comunidade-info h1 {
            margin: 0;
            color: white;
            font-size: 2em;
            margin-bottom: 10px;
        }

        .comunidade-meta {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 15px;
            flex-wrap: wrap;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
        }

        .comunidade-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-entrar {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-entrar:hover {
            background-color: #45a049;
            transform: scale(1.05);
        }

        .btn-entrar:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
            transform: scale(1);
        }

        .posts-container {
            margin-top: 30px;
            padding: 0 20px 60px;
        }

        .post {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .post-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
        }

        .post-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            background: #ddd;
        }

        .post-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .post-user-info {
            flex: 1;
        }

        .post-user-info h4 {
            margin: 0;
            font-size: 14px;
        }

        .post-user-info p {
            margin: 0;
            font-size: 12px;
            color: #999;
        }

        .post-title {
            font-weight: bold;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .post-content {
            color: #333;
            line-height: 1.5;
            margin-bottom: 15px;
            word-break: break-word;
            white-space: pre-wrap;
        }

        .post-actions {
            display: flex;
            gap: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            font-size: 14px;
        }

        .post-action {
            cursor: pointer;
            color: #666;
            transition: color 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .post-action:hover {
            color: #667eea;
        }

        .posts-empty {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }

        .descricao-comunidade {
            color: rgba(255, 255, 255, 0.95);
            font-size: 16px;
            margin-bottom: 20px;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }

        .main-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .form-novo-post {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .form-novo-post h3 {
            margin-top: 0;
            color: #333;
        }

        .form-novo-post input,
        .form-novo-post textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        .form-novo-post textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-novo-post button {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.3s;
        }

        .form-novo-post button:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="page">
        <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px; background: white; border-bottom: 1px solid #ddd; position: sticky; top: 0; z-index: 100;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="../index.php" style="text-decoration: none; display: flex; align-items: center; gap: 8px; color: #333;">
                    <img src="../style/blumaskBlueLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
                    <h1 style="margin: 0; font-size: 20px;">BluMask</h1>
                </a>
            </div>
            <?php if (isset($_SESSION['usuario'])): ?>
                <a href="../index.php?logout=1" style="text-decoration: none; color: #ff4d4d; font-weight: bold; font-size: 14px;">Sair</a>
            <?php endif; ?>
        </header>

        <main>
            <div class="main-container">
                <!-- HEADER DA COMUNIDADE -->
                <div class="comunidade-header">
                    <?php if (!empty($comunidade['imagem'])): ?>
                        <img src="../<?= htmlspecialchars($comunidade['imagem'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($comunidade['nome'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div style="width: 200px; height: 150px; background: #555; border-radius: 10px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; color: white;">
                            Sem imagem
                        </div>
                    <?php endif; ?>

                    <div class="comunidade-info">
                        <h1><?= htmlspecialchars($comunidade['nome'], ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="descricao-comunidade"><?= htmlspecialchars($comunidade['descricao'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        
                        <div class="comunidade-meta">
                            <span>👥 <?= $total_membros ?> membro(s)</span>
                            <span>📅 <?= date('d/m/Y', strtotime($comunidade['data_criacao'])) ?></span>
                            <span>👤 Por <?= htmlspecialchars($comunidade['nome_de_exibicao'] ?? 'Desconhecido', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>

                        <?php if (!$eh_membro): ?>
                            <button class="btn-entrar" onclick="entrarComunidade(<?= $id_comunidade ?>)">
                                ✓ Entrar na Comunidade
                            </button>
                        <?php else: ?>
                            <div style="color: rgba(255, 255, 255, 0.9); font-weight: bold; margin-top: 10px;">
                                ✓ Você é membro desta comunidade
                                <?php if ($eh_admin): ?>
                                    <div style="font-size: 14px; margin-top: 5px;">(Administrador)</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- POSTS DA COMUNIDADE -->
                <div class="posts-container">
                    <h2 style="color: #333;">Posts da Comunidade</h2>

                    <?php if ($eh_membro): ?>
                        <!-- Área para criar novo post (apenas para membros) -->
                        <div class="form-novo-post">
                            <h3>Criar novo post</h3>
                            <form id="formNovoPost">
                                <input type="hidden" name="csrf_token" value="<?php echo isset($_SESSION['csrf_token']) ? htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                                <input type="hidden" name="id_comunidade" value="<?= $id_comunidade ?>">
                                
                                <input type="text" name="assunto" placeholder="Título do post" required>
                                
                                <textarea name="conteudo" placeholder="O que você quer compartilhar?" required></textarea>
                                
                                <button type="submit">Publicar</button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- LISTA DE POSTS -->
                    <?php if (count($posts) > 0): ?>
                        <?php foreach ($posts as $post): ?>
                            <div class="post">
                                <div class="post-header">
                                    <div class="post-avatar">
                                        <img src="<?= !empty($post['foto_perfil']) ? htmlspecialchars($post['foto_perfil'], ENT_QUOTES, 'UTF-8') : 'https://ui-avatars.com/api/?name=' . urlencode(htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8')) . '&background=random'; ?>" alt="Avatar">
                                    </div>
                                    <div class="post-user-info">
                                        <h4><?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></h4>
                                        <p>@<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div style="font-size: 12px; color: #999;">
                                        <?= date('d/m/Y H:i', strtotime($post['Data_post'])) ?>
                                    </div>
                                </div>

                                <div class="post-title"><?= htmlspecialchars($post['assunto'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="post-content"><?= htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8') ?></div>

                                <div class="post-actions">
                                    <span class="post-action" onclick="curtirPost(<?= $post['id_post'] ?>, this)">
                                        <span><?php echo intval($post['curtiu']) === 1 ? '❤️' : '🤍'; ?></span>
                                        <span><?= intval($post['total_curtidas']) ?> curtida(s)</span>
                                    </span>
                                    <span class="post-action">💬 Comentar (em breve)</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="posts-empty">
                            <p>Nenhum post nesta comunidade ainda.</p>
                            <?php if ($eh_membro): ?>
                                <p>Seja o primeiro a criar um post!</p>
                            <?php else: ?>
                                <p>Entre na comunidade para ver e criar posts.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        let csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

        function entrarComunidade(idComunidade) {
            const btn = event.target;
            btn.disabled = true;
            btn.textContent = 'Carregando...';

            fetch('../php/entrar_comunidade.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_comunidade=${idComunidade}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    alert(data.mensagem);
                    location.reload();
                } else {
                    alert(data.mensagem);
                    btn.disabled = false;
                    btn.textContent = '✓ Entrar na Comunidade';
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao entrar na comunidade.');
                btn.disabled = false;
                btn.textContent = '✓ Entrar na Comunidade';
            });
        }

        function curtirPost(idPost, elemento) {
            fetch('../php/curtir_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_post=${idPost}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    location.reload();
                } else {
                    alert(data.mensagem);
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao curtir post.');
            });
        }

        // Evento para criar novo post
        const formNovoPost = document.getElementById('formNovoPost');
        if (formNovoPost) {
            formNovoPost.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);

                fetch('../php/criar_post.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso) {
                        alert('Post criado com sucesso!');
                        location.reload();
                    } else {
                        alert(data.mensagem);
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao criar post.');
                });
            });
        }
    </script>
</body>
</html>
