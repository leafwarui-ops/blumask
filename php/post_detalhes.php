<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

$id_post = intval($_GET['id_post'] ?? 0);

if ($id_post <= 0) {
    header("Location: ../index.php");
    exit;
}

global $conn;
$id_usuario = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;

$sql_post = "SELECT p.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil, c.nome AS nome_comunidade, c.id_comunidade,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) as total_curtidas,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario) as curtiu,
             (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) as total_comentarios
             FROM post p
             JOIN usuario u ON p.id_usuario = u.id_usuario
             JOIN comunidade c ON c.id_comunidade = p.id_comunidade
             WHERE p.id_post = $id_post LIMIT 1";

$resultado_post = mysqli_query($conn, $sql_post);

if (!$resultado_post || mysqli_num_rows($resultado_post) === 0) {
    header("Location: ../index.php");
    exit;
}

$post = mysqli_fetch_assoc($resultado_post);

function resolve_avatar_url($foto_perfil, $nome_exibicao) {
    $nome = trim((string) ($nome_exibicao ?? 'User'));
    $fallback = 'https://ui-avatars.com/api/?name=' . urlencode($nome) . '&background=random';

    if (empty($foto_perfil)) {
        return $fallback;
    }

    $path = trim((string) $foto_perfil);

    if (preg_match('#^(https?:)?//#i', $path) || preg_match('#^data:#i', $path)) {
        return htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
    }

    if (str_starts_with($path, '/')) {
        $relativePath = ltrim($path, '/');
        if (file_exists(__DIR__ . '/../' . $relativePath) && is_file(__DIR__ . '/../' . $relativePath)) {
            return htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
        }
        return $fallback;
    }

    $normalized = ltrim($path, './');
    $absolutePath = __DIR__ . '/../' . $normalized;
    if (file_exists($absolutePath) && is_file($absolutePath)) {
        return htmlspecialchars('../' . $normalized, ENT_QUOTES, 'UTF-8');
    }

    return $fallback;
}

$sql_comentarios = "SELECT c.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil
                    FROM comentario c
                    JOIN usuario u ON c.id_usuario = u.id_usuario
                    WHERE c.id_post = $id_post
                    ORDER BY c.data_comentario ASC, c.id_comentario ASC";

$resultado_comentarios = mysqli_query($conn, $sql_comentarios);
$comentarios = [];

if ($resultado_comentarios) {
    while ($comentario = mysqli_fetch_assoc($resultado_comentarios)) {
        $comentarios[] = $comentario;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post - BluMask</title>
    <link rel="icon" type="image/webp" href="../style/blumaskWhiteLogo.webp">
    <link rel="stylesheet" href="../style/index_style.css">
    <link rel="stylesheet" href="../style/comunidade_style.css?v=<?= time() ?>">
</head>
<body>
    <div class="page">
        <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px; background: #567fd9; border-bottom: 1px solid rgba(255,255,255,0.2); position: sticky; top: 0; z-index: 100;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="../index.php" style="text-decoration: none; display: flex; align-items: center; gap: 8px; color: #fff;">
                    <img src="../style/blumaskWhiteLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
                    <h1 style="margin: 0; font-size: 20px; color: #fff;">BluMask</h1>
                </a>
            </div>
            <a href="comunidade.php?id=<?= intval($post['id_comunidade']) ?>" style="text-decoration: none; color: #ffffff; font-weight: bold; font-size: 14px;">Voltar para a comunidade</a>
        </header>

        <main class="post-detail-page">
            <div class="post-detail-card">
                <div class="post-detail-header">
                    <button type="button" class="community-back-btn" title="Voltar para a página anterior" aria-label="Voltar para a página anterior" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '../index.php'; } return false;">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18L9 12L15 6"/></svg>
                    </button>
                    <a href="user_view.php?id=<?= intval($post['id_usuario']) ?>" class="post-avatar" aria-label="Ver perfil de <?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?>" style="display:inline-block; text-decoration:none;">
                        <img src="<?= resolve_avatar_url($post['foto_perfil'] ?? null, $post['nome_de_exibicao'] ?? 'User'); ?>" alt="Avatar">
                    </a>
                    <div class="post-header-info">
                        <div class="post-user-info">
                            <h4>
                                <a href="user_view.php?id=<?= intval($post['id_usuario']) ?>" style="text-decoration:none; color:inherit;">
                                    <?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </h4>
                            <p>
                                <a href="user_view.php?id=<?= intval($post['id_usuario']) ?>" style="text-decoration:none; color:inherit;">
                                    @<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </p>
                        </div>
                        <div class="post-date"><?= date('d/m/Y', strtotime($post['Data_post'])) ?></div>
                    </div>
                </div>

                <div class="post-detail-community">
                    <a href="comunidade.php?id=<?= intval($post['id_comunidade']) ?>" style="text-decoration:none; color:#2563eb; font-weight:700;">
                        <?= htmlspecialchars($post['nome_comunidade'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
                <h2 class="post-detail-title"><?= htmlspecialchars($post['assunto'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="post-content"><?= htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8') ?></div>

                <div class="post-actions post-detail-actions">
                    <span class="post-action" onclick="curtirPost(<?= $post['id_post'] ?>, this)">
                        <span><?php echo intval($post['curtiu']) === 1 ? '❤️' : '🤍'; ?></span>
                        <span><?= intval($post['total_curtidas']) ?></span>
                    </span>
                    <span class="post-action" aria-label="Comentários">
                        <span>💬</span>
                        <span><?= intval($post['total_comentarios']) ?></span>
                    </span>
                    <!-- 'Ir para a comunidade' agora acessível clicando no nome da comunidade acima -->
                </div>
            </div>

            <div class="comments-list">
                <h3>Comentários</h3>

                <div class="comment-detail-box">
                    <h3>Adicionar comentário</h3>
                    <form id="formComentarioDetalhe" class="form-comentario">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id_post" value="<?= $post['id_post'] ?>">
                        <textarea name="conteudo" minlength="2" maxlength="2000" placeholder="Digite seu comentário... (mín. 2 caracteres)" required></textarea>
                        <button type="submit">Enviar comentário</button>
                    </form>
                </div>

                <?php if (count($comentarios) > 0): ?>
                    <?php foreach ($comentarios as $comentario): ?>
                        <div class="comment-item">
                            <a href="user_view.php?id=<?= intval($comentario['id_usuario']) ?>" class="comment-avatar" aria-label="Ver perfil de <?= htmlspecialchars($comentario['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?>">
                                <img src="<?= resolve_avatar_url($comentario['foto_perfil'] ?? null, $comentario['nome_de_exibicao'] ?? 'User'); ?>" alt="Avatar do usuário">
                            </a>
                            <div class="comment-body">
                                <div class="comment-meta">
                                    <a href="user_view.php?id=<?= intval($comentario['id_usuario']) ?>" style="text-decoration:none; color:inherit;">
                                        <strong><?= htmlspecialchars($comentario['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    </a>
                                    <span>@<?= htmlspecialchars($comentario['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <time><?= date('d/m/Y', strtotime($comentario['data_comentario'])) ?></time>
                                </div>
                                <p><?= htmlspecialchars($comentario['conteudo'], ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="comments-empty">Nenhum comentário ainda. Seja o primeiro a responder este post.</div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        function mostrarAvisoLoginCurtida() {
            let modal = document.getElementById('likeLoginModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'likeLoginModal';
                modal.className = 'modal-overlay like-login-modal';
                modal.innerHTML = `
                    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="likeLoginTitle">
                        <div class="modal-header" id="likeLoginTitle">Login necessário</div>
                        <div class="modal-message">Você precisa estar logado para curtir posts.</div>
                        <div class="modal-actions">
                            <button type="button" class="modal-btn modal-btn-confirm" onclick="fecharAvisoLoginCurtida()">Entendi</button>
                        </div>
                    </div>`;
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) fecharAvisoLoginCurtida();
                });
                document.body.appendChild(modal);
            }
            modal.classList.add('ativo');
        }

        function fecharAvisoLoginCurtida() {
            document.getElementById('likeLoginModal')?.classList.remove('ativo');
        }

        function mostrarAvisoLoginComentario() {
            let modal = document.getElementById('commentLoginModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'commentLoginModal';
                modal.className = 'modal-overlay like-login-modal';
                modal.innerHTML = `
                    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="commentLoginTitle">
                        <div class="modal-header" id="commentLoginTitle">Login necessário</div>
                        <div class="modal-message">Você precisa estar logado para comentar posts.</div>
                        <div class="modal-actions">
                            <button type="button" class="modal-btn modal-btn-confirm" onclick="fecharAvisoLoginComentario()">Entendi</button>
                        </div>
                    </div>`;
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) fecharAvisoLoginComentario();
                });
                document.body.appendChild(modal);
            }
            modal.classList.add('ativo');
        }

        function fecharAvisoLoginComentario() {
            document.getElementById('commentLoginModal')?.classList.remove('ativo');
        }

        function curtirPost(idPost) {
            <?php if (!isset($_SESSION['usuario'])): ?>
                mostrarAvisoLoginCurtida();
                return;
            <?php endif; ?>

            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';

            fetch('curtir_post.php', {
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

        const formComentarioDetalhe = document.getElementById('formComentarioDetalhe');
        if (formComentarioDetalhe) {
            formComentarioDetalhe.addEventListener('submit', function(e) {
                e.preventDefault();

                <?php if (!isset($_SESSION['usuario'])): ?>
                    mostrarAvisoLoginComentario();
                    return;
                <?php endif; ?>

                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();
                if (conteudo.length < 2) {
                    alert('O comentário deve ter no mínimo 2 caracteres.');
                    return;
                }

                const formData = new FormData(this);
                fetch('criar_comentario.php', {
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
        }
    </script>
</body>
</html>
