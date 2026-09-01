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

$is_community_owner = (int) $comunidade['id_usuario'] === $id_usuario;
$eh_admin = $is_community_owner || ($eh_membro && $cargo_usuario === 1);
$is_community_admin = $eh_admin;

if ($is_community_owner && !$eh_membro) {
    $eh_membro = true;
    $cargo_usuario = 1;
}

// Buscar posts da comunidade
$sqlPosts = "SELECT p.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil, u.id_usuario,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) as total_curtidas,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario) as curtiu,
             (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) as total_comentarios
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

$postsMap = [];
foreach ($posts as $post) {
    $postsMap[(int) $post['id_post']] = [
        'assunto' => $post['assunto'] ?? '',
        'conteudo' => $post['conteudo'] ?? ''
    ];
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

            <form id="formEditarPost" class="modal-form">
                <input type="hidden" name="csrf_token" value="<?php echo isset($_SESSION['csrf_token']) ? htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                <input type="hidden" name="id_post" value="">
                <input type="hidden" name="id_comunidade" value="<?= $id_comunidade ?>">

                <label for="editarAssunto">Título do post</label>
                <input type="text" id="editarAssunto" name="assunto" maxlength="150" required>

                <label for="editarConteudo">Conteúdo</label>
                <textarea id="editarConteudo" name="conteudo" maxlength="5000" required></textarea>

                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="modal-btn modal-btn-confirm">Salvar alterações</button>
                </div>
            </form>

            <form id="formEditarComunidade" class="modal-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo isset($_SESSION['csrf_token']) ? htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                <input type="hidden" name="id_comunidade" value="<?= $id_comunidade ?>">

                <label for="editarNomeComunidade">Nome da comunidade</label>
                <input type="text" id="editarNomeComunidade" name="nome" maxlength="40" required>

                <label for="editarDescricaoComunidade">Descrição</label>
                <textarea id="editarDescricaoComunidade" name="descricao" maxlength="200" rows="4"></textarea>

                <label for="editarImagemComunidade">Nova imagem da comunidade</label>
                <input type="file" id="editarImagemComunidade" name="imagem" accept="image/jpeg,image/png,image/gif,image/webp">

                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="modal-btn modal-btn-confirm">Salvar alterações</button>
                </div>
            </form>

            <div class="modal-actions" id="confirmActions">
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
                        <?php if (!$is_community_owner && $cargo_usuario !== 1): ?>
                            <button class="btn-seguir ja-membro" onclick="sairComunidade(<?= $id_comunidade ?>)">Sair da comunidade</button>
                        <?php else: ?>
                            <button class="btn-seguir ja-membro" onclick="event.preventDefault()">✓ Seguindo</button>
                        <?php endif; ?>

                        <?php if ($is_community_owner): ?>
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
                            <div class="post" data-post-id="<?= $post['id_post'] ?>">
                                <div class="post-header">
                                    <a href="user_view.php?id=<?= (int) $post['id_usuario'] ?>" class="post-author-link" onclick="event.stopPropagation();" aria-label="Ver perfil de <?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="post-avatar">
                                            <img src="<?= !empty($post['foto_perfil']) ? htmlspecialchars($post['foto_perfil'], ENT_QUOTES, 'UTF-8') : 'https://ui-avatars.com/api/?name=' . urlencode(htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8')) . '&background=random'; ?>" alt="Avatar">
                                        </div>
                                    </a>
                                    <div class="post-header-info">
                                        <a href="user_view.php?id=<?= (int) $post['id_usuario'] ?>" class="post-user-link" onclick="event.stopPropagation();">
                                            <div class="post-user-info">
                                                <h4><?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                <p>@<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></p>
                                            </div>
                                        </a>
                                        <div class="post-date">
                                            <?= date('d/m/Y', strtotime($post['Data_post'])) ?>
                                        </div>
                                    </div>
                                    <?php if ((int) $post['id_usuario'] === $id_usuario || $is_community_admin): ?>
                                        <div class="post-menu-wrapper">
                                            <button class="post-menu-toggle" type="button" aria-label="Opções do post" onclick="togglePostMenu(this)">⋯</button>
                                            <div class="post-menu-dropdown">
                                                <?php if ((int) $post['id_usuario'] === $id_usuario): ?>
                                                    <button class="post-menu-btn" type="button" onclick="abrirModalEditarPost(<?= $post['id_post'] ?>)">Editar post</button>
                                                <?php endif; ?>

                                                <?php if ((int) $post['id_usuario'] === $id_usuario || $is_community_admin): ?>
                                                    <button class="post-menu-btn danger" type="button" onclick="abrirModalExcluirPost(<?= $post['id_post'] ?>)">Excluir post</button>
                                                <?php endif; ?>

                                                <?php if ($is_community_admin): ?>
                                                    <button class="post-menu-btn" type="button" onclick="fixarPost(<?= $post['id_post'] ?>)">
                                                        <?= !empty($comunidade['id_post_fixado']) && (int) $post['id_post'] === (int) $comunidade['id_post_fixado'] ? 'Desfixar post' : 'Fixar post' ?>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($comunidade['id_post_fixado']) && (int) $post['id_post'] === (int) $comunidade['id_post_fixado']): ?>
                                    <div class="post-pinned-badge">📌 Post fixado</div>
                                <?php endif; ?>
                                <div class="post-title"><?= htmlspecialchars($post['assunto'] ?? 'Sem assunto', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="post-content"><?= htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8') ?></div>

                                <div class="post-actions">
                                    <span class="post-action" onclick="curtirPost(<?= $post['id_post'] ?>, this)">
                                        <span><?php echo intval($post['curtiu']) === 1 ? '❤️' : '🤍'; ?></span>
                                        <span><?= intval($post['total_curtidas']) ?></span>
                                    </span>

                                    <button type="button" class="post-action comment-toggle" data-post-id="<?= $post['id_post'] ?>" aria-label="Comentar">
                                        <span>💬</span>
                                        <span><?= intval($post['total_comentarios']) ?></span>
                                    </button>

                                </div>

                                <div class="comment-form-wrap" id="comment-form-<?= $post['id_post'] ?>" style="display: none;">
                                    <form class="form-comentario" data-post-id="<?= $post['id_post'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="id_post" value="<?= $post['id_post'] ?>">
                                        <textarea name="conteudo" rows="3" placeholder="Escreva um comentário..." required></textarea>
                                        <button type="submit">Comentar</button>
                                    </form>
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
            <footer class="bottombar">
                <strong>Blumask</strong>
                <svg viewBox="0 0 24 24" fill="none" stroke="#1c1c1c" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M15 9.5a3.5 3.5 0 1 0 0 5"/></svg>
            </footer>
        </main>
    </div>

    <script>
        const postsMap = <?php echo json_encode($postsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        let csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        let acaoAtual = null;

        // ===== MODAL FUNCTIONS =====
        function abrirModal(titulo, mensagem, temDanger = false) {
            document.getElementById('modalTitle').textContent = titulo;
            document.getElementById('modalMessage').textContent = mensagem;
            document.getElementById('formEditarPost').style.display = 'none';
            document.getElementById('formEditarComunidade').style.display = 'none';
            document.getElementById('confirmActions').style.display = 'flex';
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
            document.getElementById('formEditarPost').style.display = 'none';
            document.getElementById('formEditarComunidade').style.display = 'none';
            document.getElementById('confirmActions').style.display = 'flex';
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
            const form = document.getElementById('formEditarComunidade');
            if (!form) return;

            const nomeAtual = <?= json_encode($comunidade['nome'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
            const descricaoAtual = <?= json_encode($comunidade['descricao'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            form.querySelector('[name="id_comunidade"]').value = idComunidade;
            form.querySelector('[name="nome"]').value = nomeAtual;
            form.querySelector('[name="descricao"]').value = descricaoAtual;
            form.querySelector('[name="imagem"]').value = '';

            document.getElementById('modalTitle').textContent = 'Editar Comunidade';
            document.getElementById('modalMessage').textContent = 'Atualize os dados da comunidade abaixo.';
            document.getElementById('confirmActions').style.display = 'none';
            document.getElementById('formEditarPost').style.display = 'none';
            form.style.display = 'block';
            document.getElementById('confirmModal').classList.add('ativo');
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
            const post = postsMap[idPost];
            const form = document.getElementById('formEditarPost');

            if (!post) {
                abrirModal('Editar Post', 'Não foi possível carregar este post.');
                return;
            }

            acaoAtual = null;
            document.getElementById('modalTitle').textContent = 'Editar Post';
            document.getElementById('modalMessage').textContent = 'Atualize os dados do post abaixo.';
            document.getElementById('confirmActions').style.display = 'none';
            document.getElementById('formEditarComunidade').style.display = 'none';
            form.style.display = 'block';
            form.querySelector('[name="id_post"]').value = idPost;
            form.querySelector('[name="assunto"]').value = post.assunto || '';
            form.querySelector('[name="conteudo"]').value = post.conteudo || '';
            document.getElementById('confirmModal').classList.add('ativo');
        }

        function abrirModalExcluirPost(idPost) {
            abrirModal('Excluir Post', 'Tem certeza que deseja excluir este post? Esta ação não pode ser desfeita.', true);
            acaoAtual = function() {
                excluirPost(idPost);
            };
        }

        function togglePostMenu(button) {
            const wrapper = button.closest('.post-menu-wrapper');
            if (!wrapper) return;

            const menu = wrapper.querySelector('.post-menu-dropdown');
            const isOpen = menu.classList.contains('open');

            document.querySelectorAll('.post-menu-dropdown').forEach(item => item.classList.remove('open'));
            if (!isOpen) {
                menu.classList.add('open');
            }
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('.post-menu-toggle') && !event.target.closest('.post-menu-dropdown')) {
                document.querySelectorAll('.post-menu-dropdown').forEach(item => item.classList.remove('open'));
            }
        });

        function fixarPost(idPost) {
            fetch('../php/fixar_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_post=${idPost}&id_comunidade=${<?= $id_comunidade ?>}`
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

        function sairComunidade(idComunidade) {
            const btn = event.target;
            if (!btn) return;

            btn.disabled = true;
            btn.textContent = 'Saindo...';

            fetch('../php/sair_comunidade.php', {
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
                    btn.textContent = 'Sair da comunidade';
                    alert(data.mensagem || 'Não foi possível sair da comunidade.');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                btn.disabled = false;
                btn.textContent = 'Sair da comunidade';
                alert('Erro ao sair da comunidade. Tente novamente.');
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

        document.querySelectorAll('.post').forEach(post => {
            post.addEventListener('click', function(event) {
                const isInteractive = event.target.closest('button, a, input, textarea, select, .post-action, .post-menu-wrapper, .post-menu-dropdown, .comment-toggle, .form-comentario');
                if (isInteractive) {
                    return;
                }

                const postId = this.dataset.postId;
                if (postId) {
                    window.location.href = `post_detalhes.php?id_post=${postId}`;
                }
            });
        });

        document.querySelectorAll('.comment-toggle').forEach(button => {
            button.addEventListener('click', function() {
                const postId = this.dataset.postId;
                const formWrap = document.getElementById(`comment-form-${postId}`);
                if (formWrap) {
                    formWrap.style.display = formWrap.style.display === 'none' ? 'block' : 'none';
                }
            });
        });

        document.querySelectorAll('.form-comentario').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();

                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();
                if (conteudo.length < 2) {
                    return;
                }

                const formData = new FormData(this);
                fetch('../php/criar_comentario.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso) {
                        location.reload();
                    } else {
                        alert(data.mensagem || 'Não foi possível enviar o comentário.');
                    }
                })
                .catch(error => {
                    console.error('Erro ao comentar:', error);
                    alert('Erro ao comentar. Tente novamente.');
                });
            });
        });

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

        const formEditarPost = document.getElementById('formEditarPost');
        if (formEditarPost) {
            formEditarPost.addEventListener('submit', function(e) {
                e.preventDefault();

                const assunto = this.querySelector('input[name="assunto"]').value.trim();
                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();

                if (assunto.length < 3 || conteudo.length < 5) {
                    return;
                }

                const formData = new FormData(this);
                formData.set('csrf_token', csrfToken);

                fetch('../php/editar_post.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso) {
                        fecharModal();
                        location.reload();
                    } else {
                        document.getElementById('modalMessage').textContent = data.mensagem || 'Não foi possível editar o post.';
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    document.getElementById('modalMessage').textContent = 'Erro ao editar o post.';
                });
            });
        }

        const formEditarComunidade = document.getElementById('formEditarComunidade');
        if (formEditarComunidade) {
            formEditarComunidade.addEventListener('submit', function(e) {
                e.preventDefault();

                const nome = this.querySelector('input[name="nome"]').value.trim();
                const descricao = this.querySelector('textarea[name="descricao"]').value.trim();

                if (nome.length < 2 || nome.length > 40) {
                    document.getElementById('modalMessage').textContent = 'O nome da comunidade deve ter entre 2 e 40 caracteres.';
                    return;
                }

                if (!/^[\p{L}\p{N}\s]+$/u.test(nome)) {
                    document.getElementById('modalMessage').textContent = 'O nome da comunidade deve conter apenas letras, números e espaços.';
                    return;
                }

                if (descricao.length > 200) {
                    document.getElementById('modalMessage').textContent = 'A descrição da comunidade não pode ter mais de 200 caracteres.';
                    return;
                }

                const formData = new FormData(this);
                formData.set('csrf_token', csrfToken);

                fetch('../php/editar_comunidade.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso) {
                        fecharModal();
                        location.reload();
                    } else {
                        document.getElementById('modalMessage').textContent = data.mensagem || 'Não foi possível editar a comunidade.';
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    document.getElementById('modalMessage').textContent = 'Erro ao editar a comunidade.';
                });
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
