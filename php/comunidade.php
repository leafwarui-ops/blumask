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
</head>
<body>
    <!-- MODAL DE CONFIRMAÇÃO -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle">Confirmação</div>
            <div class="modal-message" id="modalMessage"></div>
            <div class="modal-actions">
                <button class="modal-btn modal-btn-cancel" onclick="fecharModal()">Cancelar</button>
                <button class="modal-btn modal-btn-confirm" id="modalConfirmBtn" onclick="executarAcao()">Confirmar</button>
            </div>
        </div>
    </div>
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
            <!-- BARRA DE BUSCA -->
            <div class="search-container">
                <div class="search-bar">
                    <span style="font-size: 24px;">🔍</span>
                    <input type="text" placeholder="Procurando por Algo?" id="searchInput">
                </div>
            </div>

            <!-- CONTEÚDO PRINCIPAL -->
            <div class="content-wrapper">
                <!-- CARD DA COMUNIDADE (ESQUERDA) -->
                <div class="comunidade-card">
                    <?php if (!empty($comunidade['imagem'])): ?>
                        <img src="../<?= htmlspecialchars($comunidade['imagem'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($comunidade['nome'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div style="width: 120px; height: 120px; background: #555; border-radius: 50%; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; color: white; border: 4px solid white;">
                            Sem imagem
                        </div>
                    <?php endif; ?>

                    <h2><?= htmlspecialchars($comunidade['nome'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="descricao"><?= htmlspecialchars($comunidade['descricao'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>

                    <?php if (!$eh_membro): ?>
                        <button class="btn-seguir" onclick="entrarComunidade(<?= $id_comunidade ?>)">Seguir +</button>
                    <?php else: ?>
                        <button class="btn-seguir ja-membro" onclick="event.preventDefault()">✓ Seguindo</button>
                        <?php if ($eh_admin): ?>
                            <div class="admin-actions">
                                <button class="btn-admin btn-admin-edit" onclick="abrirModalEditarComunidade(<?= $id_comunidade ?>)">✎ Editar Comunidade</button>
                                <button class="btn-admin btn-admin-delete" onclick="abrirModalExcluirComunidade(<?= $id_comunidade ?>)">🗑 Excluir Comunidade</button>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- POSTS (DIREITA) -->
                <div class="posts-section">
                    <h3>
                        Últimos posts
                        <?php if ($eh_membro): ?>
                            <button class="btn-novo-post" onclick="toggleFormNovoPost()">+ Novo Post</button>
                        <?php endif; ?>
                    </h3>

                    <?php if ($eh_membro): ?>
                        <!-- Área para criar novo post (apenas para membros) -->
                        <div class="form-novo-post" id="formContainer">
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
                                    <div class="post-header-info">
                                        <div class="post-user-info">
                                            <h4><?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></h4>
                                            <p>@<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                        <div class="post-date">
                                            <?= date('d/m/Y', strtotime($post['Data_post'])) ?>
                                        </div>
                                    </div>
                                    <?php if ($eh_admin): ?>
                                        <div class="post-actions-admin">
                                            <button class="btn-post-action btn-post-edit" onclick="abrirModalEditarPost(<?= $post['id_post'] ?>)">✎</button>
                                            <button class="btn-post-action" onclick="abrirModalExcluirPost(<?= $post['id_post'] ?>)">✕</button>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="post-content"><?= htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8') ?></div>

                                <div class="post-actions">
                                    <span class="post-action" onclick="curtirPost(<?= $post['id_post'] ?>, this)">
                                        <span><?php echo intval($post['curtiu']) === 1 ? '❤️' : '🤍'; ?></span>
                                        <span><?= intval($post['total_curtidas']) ?></span>
                                    </span>
                                    <span class="post-action">💬 Ler mais</span>
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
        let acaoAtual = null;

        // ===== MODAL FUNCTIONS =====
        function abrirModal(titulo, mensagem, temDanger = false) {
            document.getElementById('modalTitle').textContent = titulo;
            document.getElementById('modalMessage').textContent = mensagem;
            const btn = document.getElementById('modalConfirmBtn');
            if (temDanger) {
                btn.className = 'modal-btn modal-btn-danger';
            } else {
                btn.className = 'modal-btn modal-btn-confirm';
            }
            document.getElementById('confirmModal').classList.add('ativo');
        }

        function fecharModal() {
            document.getElementById('confirmModal').classList.remove('ativo');
            acaoAtual = null;
        }

        function executarAcao() {
            if (acaoAtual) {
                acaoAtual();
            }
            fecharModal();
        }

        // Fecha modal ao clicar fora
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                fecharModal();
            }
        });

        // ===== COMUNIDADE FUNCTIONS =====
        function abrirModalEditarComunidade(idComunidade) {
            abrirModal('Editar Comunidade', 'Esta funcionalidade será implementada em breve.');
        }

        function abrirModalExcluirComunidade(idComunidade) {
            abrirModal('Excluir Comunidade', 'Tem certeza que deseja excluir esta comunidade? Esta ação é irreversível e todos os posts serão perdidos.', true);
            acaoAtual = function() {
                excluirComunidade(idComunidade);
            };
        }

        function excluirComunidade(idComunidade) {
            fetch('../php/excluir_comunidade.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_comunidade=${idComunidade}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    window.location.href = '../index.php';
                } else {
                    console.error(data.mensagem);
                }
            })
            .catch(error => console.error('Erro:', error));
        }

        // ===== POST FUNCTIONS =====
        function abrirModalEditarPost(idPost) {
            abrirModal('Editar Post', 'Esta funcionalidade será implementada em breve.');
        }

        function abrirModalExcluirPost(idPost) {
            abrirModal('Excluir Post', 'Tem certeza que deseja excluir este post? Esta ação não pode ser desfeita.', true);
            acaoAtual = function() {
                excluirPost(idPost);
            };
        }

        function excluirPost(idPost) {
            fetch('../php/excluir_post.php', {
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
                    console.error(data.mensagem);
                }
            })
            .catch(error => console.error('Erro:', error));
        }

        // ===== COMUNIDADE ENTRY FUNCTION =====
        function toggleFormNovoPost() {
            const formContainer = document.getElementById('formContainer');
            formContainer.classList.toggle('ativo');
        }

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
                    location.reload();
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Seguir +';
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                btn.disabled = false;
                btn.textContent = 'Seguir +';
            });
        }

        // ===== POST LIKE FUNCTION =====
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
                }
            })
            .catch(error => console.error('Erro:', error));
        }

        // ===== NEW POST FORM =====
        const formNovoPost = document.getElementById('formNovoPost');
        if (formNovoPost) {
            formNovoPost.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const assunto = this.querySelector('input[name="assunto"]').value.trim();
                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();

                if (assunto.length < 3) {
                    return;
                }
                if (conteudo.length < 5) {
                    return;
                }
                
                const formData = new FormData(this);

                fetch('../php/criar_post.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso) {
                        location.reload();
                    }
                })
                .catch(error => console.error('Erro:', error));
            });
        }

        // ===== SEARCH FUNCTION =====
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                const query = e.target.value.toLowerCase();
                const posts = document.querySelectorAll('.post');
                
                posts.forEach(post => {
                    const content = post.textContent.toLowerCase();
                    post.style.display = content.includes(query) ? 'block' : 'none';
                });
            });
        }
    </script>
</body>
</html>
