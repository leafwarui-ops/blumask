<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/media.php";
require_once __DIR__ . "/admin_helpers.php";

$id_post = intval($_GET['id_post'] ?? 0);

if ($id_post <= 0) {
    header("Location: ../index.php");
    exit;
}

global $conn;
$id_usuario = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
$is_site_admin_user = is_site_admin($conn, $id_usuario);

$sql_post = "SELECT p.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil, c.nome AS nome_comunidade, c.id_comunidade,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) as total_curtidas,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario) as curtiu,
             (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) as total_comentarios,
             p.id_comentario_fixado
             FROM post p
             JOIN usuario u ON p.id_usuario = u.id_usuario
             JOIN comunidade c ON c.id_comunidade = p.id_comunidade
             WHERE p.id_post = $id_post LIMIT 1";

try {
    $resultado_post = mysqli_query($conn, $sql_post);
} catch (mysqli_sql_exception $e) {
    // Possível que a coluna id_comentario_fixado não exista no banco.
    // Faz fallback para uma query sem essa coluna para evitar fatal error.
    $sql_post_alt = "SELECT p.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil, c.nome AS nome_comunidade, c.id_comunidade,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) as total_curtidas,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario) as curtiu,
             (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) as total_comentarios
             FROM post p
             JOIN usuario u ON p.id_usuario = u.id_usuario
             JOIN comunidade c ON c.id_comunidade = p.id_comunidade
             WHERE p.id_post = $id_post LIMIT 1";

    $resultado_post = mysqli_query($conn, $sql_post_alt);
}

if (!$resultado_post || mysqli_num_rows($resultado_post) === 0) {
    header("Location: ../index.php");
    exit;
}

$post = mysqli_fetch_assoc($resultado_post);
$id_comunidade = intval($post['id_comunidade'] ?? 0);
$eh_membro = false;

if ($id_usuario > 0 && $id_comunidade > 0) {
    $resultado_membro = mysqli_query($conn, "SELECT id_membro_comunidade FROM membro_comunidade WHERE id_usuario = $id_usuario AND id_comunidade = $id_comunidade LIMIT 1");
    $eh_membro = $resultado_membro && mysqli_num_rows($resultado_membro) > 0;
}

function resolve_avatar_url($foto_perfil, $nome_exibicao) {
    $nome = trim((string) ($nome_exibicao ?? 'User'));
    return resolve_media_url($foto_perfil, generated_avatar_url($nome), '../');
}

$id_comentario_fixado = intval($post['id_comentario_fixado'] ?? 0);

$sql_comentarios = "SELECT c.*, u.nome_de_exibicao, u.nome_de_usuario, u.foto_perfil
                    FROM comentario c
                    JOIN usuario u ON c.id_usuario = u.id_usuario
                    WHERE c.id_post = $id_post
                    ORDER BY CASE WHEN c.id_comentario = $id_comentario_fixado THEN 0 ELSE 1 END, c.data_comentario DESC, c.id_comentario DESC";

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
    <div class="modal-overlay" id="followCommunityModal">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="followCommunityTitle">
            <div class="modal-header" id="followCommunityTitle">Atenção</div>
            <div class="modal-message">Você precisa seguir esta comunidade para publicar posts e comentar.</div>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-confirm" onclick="fecharPopupSeguirComunidade()">Entendi</button>
            </div>
        </div>
    </div>

    <dialog id="login-box">
        <form id="popup-form" action="../index.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '../index.php', ENT_QUOTES, 'UTF-8') ?>">
            <div class="dialog-tabs">
                <button type="button" id="btn-entrar-dialog">entrar</button>
                <button type="button" id="btn-cadastrar-dialog">cadastrar</button>
            </div>
            <div id="pop-div"></div>
        </form>
    </dialog>

    <?php $is_post_owner = $id_usuario > 0 && (int) $post['id_usuario'] === $id_usuario; ?>
    <?php if ($is_post_owner): ?>
        <div class="modal-overlay" id="postActionModal">
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="postActionTitle">
                <div class="modal-header" id="postActionTitle">Editar post</div>
                <div class="modal-message" id="postActionMessage"></div>
                <form id="postEditForm" class="modal-form">
                    <input type="hidden" name="id_post" value="<?= $id_post ?>">
                    <input type="hidden" name="id_comunidade" value="<?= $id_comunidade ?>">
                    <label for="postEditSubject">Título do post</label>
                    <input type="text" id="postEditSubject" name="assunto" minlength="3" maxlength="150" required>
                    <label for="postEditContent">Conteúdo</label>
                    <textarea id="postEditContent" name="conteudo" minlength="5" maxlength="5000" required></textarea>
                    <div class="modal-actions">
                        <button type="button" class="modal-btn modal-btn-cancel" onclick="fecharPostActionModal()">Cancelar</button>
                        <button type="submit" class="modal-btn modal-btn-confirm">Salvar alterações</button>
                    </div>
                </form>
                <div class="modal-actions" id="postDeleteActions" style="display:none;">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="fecharPostActionModal()">Cancelar</button>
                    <button type="button" class="modal-btn modal-btn-danger" onclick="confirmarExclusaoPost()">Excluir post</button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="page">
        <!-- Modal de confirmação para exclusão de comentário -->
        <div class="modal-overlay" id="confirmCommentModal">
            <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="confirmCommentTitle">
                <div class="modal-header" id="confirmCommentTitle">Confirmação</div>
                <div class="modal-message" id="confirmCommentMessage">Tem certeza que deseja excluir este comentário? Esta ação não pode ser desfeita.</div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" id="confirmCommentCancel">Cancelar</button>
                    <button type="button" class="modal-btn modal-btn-danger" id="confirmCommentOk">Confirmar</button>
                </div>
            </div>
        </div>
        <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px; background: #567fd9; border-bottom: 1px solid rgba(255,255,255,0.2); position: sticky; top: 0; z-index: 100;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="../index.php" style="text-decoration: none; display: flex; align-items: center; gap: 8px; color: #fff;">
                    <img src="../style/blumaskWhiteLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
                    <h1 style="margin: 0; font-size: 20px; color: #fff;">BluMask</h1>
                </a>
            </div>
            <?php if (isset($_SESSION['usuario'])): ?>
                <?php
                    $userUnreadNotifications = 0;
                    $userUnreadResult = mysqli_query($conn, "SELECT COUNT(*) AS total FROM notificacao WHERE id_usuario = " . intval($_SESSION['usuario']['id_usuario']) . " AND lida = 0 LIMIT 1");
                    if ($userUnreadResult && $userUnreadResult->num_rows > 0) {
                        $userUnreadRow = mysqli_fetch_assoc($userUnreadResult);
                        $userUnreadNotifications = (int) ($userUnreadRow['total'] ?? 0);
                    }
                ?>
                <div class="topbar-right" style="display:flex; align-items:center; gap:12px; position:relative;">
                    <div class="notification-wrapper">
                        <button type="button" id="notificationBellButton" class="notification-bell" aria-label="Notificações" aria-expanded="false">
                            <span aria-hidden="true">🔔</span>
                            <span id="notificationBadge" class="notification-badge" style="display: <?= $userUnreadNotifications > 0 ? 'flex' : 'none'; ?>;"><?= $userUnreadNotifications > 99 ? '99+' : $userUnreadNotifications ?></span>
                        </button>
                        <div id="notificationMenu" class="notification-menu" style="display:none;" role="menu" aria-live="polite">
                            <div class="notification-header">Notificações</div>
                            <div id="notificationList" class="notification-list"></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </header>

        <main class="post-detail-page">
            <div class="post-detail-card">
                <?php $postAutorDeletado = trim((string) ($post['nome_de_exibicao'] ?? '')) === 'Usuário deletado' || stripos((string) ($post['nome_de_usuario'] ?? ''), 'usuario_deletado_') === 0; ?>
                <div class="post-detail-header">
                    <button type="button" class="community-back-btn" title="Voltar para a página anterior" aria-label="Voltar para a página anterior" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '../index.php'; } return false;">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18L9 12L15 6"/></svg>
                    </button>
                    <?php if ($postAutorDeletado): ?>
                        <span class="post-avatar" aria-label="Usuário removido" style="display:inline-block; text-decoration:none; cursor:default; opacity:0.8;">
                            <img src="<?= resolve_avatar_url($post['foto_perfil'] ?? null, $post['nome_de_exibicao'] ?? 'User'); ?>" alt="Avatar">
                        </span>
                    <?php else: ?>
                        <a href="user_view.php?id=<?= intval($post['id_usuario']) ?>" class="post-avatar" aria-label="Ver perfil de <?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?>" style="display:inline-block; text-decoration:none;">
                            <img src="<?= resolve_avatar_url($post['foto_perfil'] ?? null, $post['nome_de_exibicao'] ?? 'User'); ?>" alt="Avatar">
                        </a>
                    <?php endif; ?>
                    <div class="post-header-info">
                        <div class="post-user-info">
                            <h4>
                                <?php if ($postAutorDeletado): ?>
                                    <span style="text-decoration:none; color:inherit; cursor:default;"><?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <a href="user_view.php?id=<?= intval($post['id_usuario']) ?>" style="text-decoration:none; color:inherit;">
                                        <?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php endif; ?>
                            </h4>
                            <p>
                                <?php if ($postAutorDeletado): ?>
                                    <span style="text-decoration:none; color:inherit; cursor:default;">@<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <a href="user_view.php?id=<?= intval($post['id_usuario']) ?>" style="text-decoration:none; color:inherit;">
                                        @<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="post-date"><?= date('d/m/Y', strtotime($post['Data_post'])) ?></div>
                    </div>
                    <?php if ($is_post_owner || $is_site_admin_user): ?>
                        <div class="post-menu-wrapper">
                            <button class="post-menu-toggle" type="button" aria-label="Opções do post" onclick="togglePostDetailMenu(this)">⋯</button>
                            <div class="post-menu-dropdown">
                                <?php if ($is_post_owner): ?>
                                    <button class="post-menu-btn" type="button" onclick="abrirEdicaoPost()">Editar post</button>
                                <?php endif; ?>
                                <button class="post-menu-btn danger" type="button" onclick="abrirExclusaoPost()">Excluir post</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="post-detail-community">
                    <a href="comunidade.php?id=<?= intval($post['id_comunidade']) ?>" style="text-decoration:none; color:#2563eb; font-weight:700;">
                        <?= htmlspecialchars($post['nome_comunidade'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
                <h2 class="post-detail-title"><?= htmlspecialchars($post['assunto'], ENT_QUOTES, 'UTF-8', false) ?></h2>
                <div class="post-content"><?= htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8', false) ?></div>

                <?php if (!empty($post['imagem'])): ?>
                    <?php $post_imagem_url = resolve_media_url($post['imagem'] ?? '', '', '../'); ?>
                    <?php if ($post_imagem_url !== ''): ?>
                        <div class="post-image-wrap">
                            <img class="post-image" src="<?= $post_imagem_url ?>" alt="Imagem do post">
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

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

                <?php if (!$is_site_admin_user): ?>
                <div class="comment-detail-box">
                    <h3>Adicionar comentário</h3>
                    <form id="formComentarioDetalhe" class="form-comentario">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="id_post" value="<?= $post['id_post'] ?>">
                        <textarea name="conteudo" minlength="2" maxlength="2000" placeholder="Digite seu comentário... (mín. 2 caracteres)" required></textarea>
                        <div style="display:flex; gap:8px; margin-top:8px;">
                            <button type="button" class="btn-descartar" id="btnDescartarComentarioDetalhe">Descartar</button>
                            <button type="submit">Comentar</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if (count($comentarios) > 0): ?>
                    <?php foreach ($comentarios as $comentario): ?>
                        <?php $comentarioAutorDeletado = trim((string) ($comentario['nome_de_exibicao'] ?? '')) === 'Usuário deletado' || stripos((string) ($comentario['nome_de_usuario'] ?? ''), 'usuario_deletado_') === 0; ?>
                        <div class="comment-item" id="comment-<?= intval($comentario['id_comentario']) ?>">
                            <?php if ($comentarioAutorDeletado): ?>
                                <span class="comment-avatar" aria-label="Usuário removido" style="display:inline-block; cursor:default; opacity:0.8;">
                                    <img src="<?= resolve_avatar_url($comentario['foto_perfil'] ?? null, $comentario['nome_de_exibicao'] ?? 'User'); ?>" alt="Avatar do usuário">
                                </span>
                            <?php else: ?>
                                <a href="user_view.php?id=<?= intval($comentario['id_usuario']) ?>" class="comment-avatar" aria-label="Ver perfil de <?= htmlspecialchars($comentario['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?>">
                                    <img src="<?= resolve_avatar_url($comentario['foto_perfil'] ?? null, $comentario['nome_de_exibicao'] ?? 'User'); ?>" alt="Avatar do usuário">
                                </a>
                            <?php endif; ?>
                                            <div class="comment-body">
                                <div class="comment-meta">
                                    <?php if ($comentarioAutorDeletado): ?>
                                        <span style="text-decoration:none; color:inherit; cursor:default;">
                                            <strong><?= htmlspecialchars($comentario['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </span>
                                    <?php else: ?>
                                        <a href="user_view.php?id=<?= intval($comentario['id_usuario']) ?>" style="text-decoration:none; color:inherit;">
                                            <strong><?= htmlspecialchars($comentario['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </a>
                                    <?php endif; ?>
                                    <span>@<?= htmlspecialchars($comentario['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <time><?= date('d/m/Y', strtotime($comentario['data_comentario'])) ?></time>
                                </div>
                                <div class="comment-actions-and-content">
                                    <p class="comment-content" data-comentario-id="<?= $comentario['id_comentario'] ?>"><?= htmlspecialchars($comentario['conteudo'], ENT_QUOTES, 'UTF-8', false) ?></p>

                                    <?php if (!empty($post['id_comentario_fixado']) && (int) $post['id_comentario_fixado'] === (int) $comentario['id_comentario']): ?>
                                        <div class="comment-pinned-badge">📌 Comentário fixado</div>
                                    <?php endif; ?>

                                    <?php $is_post_owner = (int) $post['id_usuario'] === $id_usuario; ?>
                                    <?php $is_comentario_autor = (int) $comentario['id_usuario'] === $id_usuario; ?>

                                    <?php if ($is_comentario_autor || $is_post_owner || $is_site_admin_user): ?>
                                        <div class="post-menu-wrapper" style="margin-left:8px;">
                                            <button class="post-menu-toggle" type="button" aria-label="Opções do comentário" onclick="toggleCommentMenu(this)">⋯</button>
                                            <div class="post-menu-dropdown">
                                                <?php if ($is_comentario_autor): ?>
                                                    <button class="post-menu-btn" type="button" onclick="abrirEditorComentario(<?= $comentario['id_comentario'] ?>)">Editar</button>
                                                <?php endif; ?>

                                                <?php if ($is_comentario_autor || $is_post_owner || $is_site_admin_user): ?>
                                                    <button class="post-menu-btn danger" type="button" onclick="excluirComentario(<?= $comentario['id_comentario'] ?>)">Excluir</button>
                                                <?php endif; ?>

                                                <?php if ($is_post_owner): ?>
                                                    <button class="post-menu-btn" type="button" onclick="fixarComentario(<?= $post['id_post'] ?>, <?= $comentario['id_comentario'] ?>)">
                                                        <?= !empty($post['id_comentario_fixado']) && (int) $post['id_comentario_fixado'] === (int) $comentario['id_comentario'] ? 'Desfixar comentário' : 'Fixar comentário' ?>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="comments-empty">
                        Nenhum comentário ainda.
                        <?= $is_site_admin_user ? 'Administradores não podem comentar.' : 'Seja o primeiro a responder este post.' ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
        <footer class="bottombar">
            <strong>Blumask</strong>
            <svg viewBox="0 0 24 24" fill="none" stroke="#1c1c1c" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M15 9.5a3.5 3.5 0 1 0 0 5"/></svg>
        </footer>
    </div>

    <script src="../js/login_writter.js?v=<?= time() ?>"></script>
    <?php if (isset($_SESSION['usuario'])): ?>
    <script src="../js/admin_messages.js?v=<?= time() ?>"></script>
    <?php endif; ?>
    <script>
        const loginPostDialog = document.getElementById('login-box');
        const loginPostContent = document.getElementById('pop-div');
        const loginPostEnter = document.getElementById('btn-entrar-dialog');
        const loginPostRegister = document.getElementById('btn-cadastrar-dialog');

        function mostrarAvisoCooldown(form, segundos, acao = 'comentar') {
            if (!form) return;
            const totalSegundos = Math.max(1, Number(segundos) || 1);
            const aviso = form.querySelector('.rate-limit-warning') || document.createElement('div');
            aviso.className = 'rate-limit-warning';
            aviso.style.cssText = 'display:block; color:#b42318; background:#fee4e2; border:1px solid #fca5a5; border-radius:8px; padding:10px 12px; margin-bottom:10px; font-weight:700; line-height:1.4;';

            const deadline = Date.now() + totalSegundos * 1000;
            const atualizarTexto = () => {
                const restante = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
                aviso.textContent = `Você precisa esperar ${restante} segundo(s) antes de ${acao} novamente.`;
                if (restante <= 0) {
                    aviso.remove();
                    if (form._cooldownInterval) {
                        clearInterval(form._cooldownInterval);
                        form._cooldownInterval = null;
                    }
                }
            };

            if (form._cooldownInterval) {
                clearInterval(form._cooldownInterval);
            }

            form.prepend(aviso);
            atualizarTexto();
            form._cooldownInterval = setInterval(atualizarTexto, 1000);
        }

        function marcarAbaLoginPost(ativa) {
            loginPostEnter?.classList.toggle('active-tab', ativa === 'entrar');
            loginPostRegister?.classList.toggle('active-tab', ativa === 'cadastrar');
        }

        function abrirLoginPost(modo = 0) {
            if (!loginPostDialog || !loginPostContent) return;
            const modoCadastro = modo === 1 || modo === '1' || modo === 'cadastrar';
            loginPostContent.innerHTML = '';
            trocar(modoCadastro ? 1 : 0, loginPostContent);
            marcarAbaLoginPost(modoCadastro ? 'cadastrar' : 'entrar');
            document.querySelectorAll('.modal-overlay.ativo').forEach((modal) => modal.classList.remove('ativo'));
            if (!loginPostDialog.open) loginPostDialog.showModal();
        }

        loginPostEnter?.addEventListener('click', () => abrirLoginPost(0));
        loginPostRegister?.addEventListener('click', () => abrirLoginPost(1));

        loginPostDialog?.addEventListener('click', (event) => {
            if (event.target === loginPostDialog) loginPostDialog.close();
        });

        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash;
            if (!hash || !hash.startsWith('#comment-')) return;

            const target = document.getElementById(hash.slice(1));
            if (!target) return;

            setTimeout(() => {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.classList.add('comment-highlight');
                setTimeout(() => target.classList.remove('comment-highlight'), 2200);
            }, 150);
        });

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
                            <button type="button" class="modal-btn modal-btn-confirm" onclick="abrirLoginPost()">Entrar</button>
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
                            <button type="button" class="modal-btn modal-btn-confirm" onclick="abrirLoginPost()">Entrar</button>
                        </div>
                    </div>`;
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) fecharAvisoLoginComentario();
                });
                document.body.appendChild(modal);
            }
            modal.classList.add('ativo');
        }

        function mostrarPopupSeguirComunidade() {
            const modal = document.getElementById('followCommunityModal');
            if (!modal) return;
            modal.classList.add('ativo');
        }

        function fecharPopupSeguirComunidade() {
            const modal = document.getElementById('followCommunityModal');
            if (!modal) return;
            modal.classList.remove('ativo');
        }

        document.getElementById('followCommunityModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                fecharPopupSeguirComunidade();
            }
        });

        function fecharAvisoLoginComentario() {
            document.getElementById('commentLoginModal')?.classList.remove('ativo');
        }

        function togglePostDetailMenu(button) {
            const wrapper = button.closest('.post-menu-wrapper');
            if (!wrapper) return;
            const menu = wrapper.querySelector('.post-menu-dropdown');
            const isOpen = menu.classList.contains('open');
            document.querySelectorAll('.post-menu-dropdown').forEach((item) => item.classList.remove('open'));
            if (!isOpen) menu.classList.add('open');
        }

        document.addEventListener('click', (event) => {
            if (!event.target.closest('.post-menu-toggle') && !event.target.closest('.post-menu-dropdown')) {
                document.querySelectorAll('.post-menu-dropdown').forEach((item) => item.classList.remove('open'));
            }
        });

        function fecharPostActionModal() {
            document.getElementById('postActionModal')?.classList.remove('ativo');
            const deleteActions = document.getElementById('postDeleteActions');
            if (deleteActions) deleteActions.style.display = 'none';
        }

        function abrirEdicaoPost() {
            const modal = document.getElementById('postActionModal');
            const form = document.getElementById('postEditForm');
            if (!modal || !form) return;
            document.getElementById('postActionTitle').textContent = 'Editar post';
            document.getElementById('postActionMessage').textContent = 'Atualize os dados do post abaixo.';
            form.style.display = 'block';
            document.getElementById('postDeleteActions').style.display = 'none';
            form.querySelector('[name="assunto"]').value = <?= json_encode($post['assunto'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            form.querySelector('[name="conteudo"]').value = <?= json_encode($post['conteudo'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
            modal.classList.add('ativo');
        }

        function abrirExclusaoPost() {
            const modal = document.getElementById('postActionModal');
            if (!modal) return;
            document.getElementById('postActionTitle').textContent = 'Excluir post';
            document.getElementById('postActionMessage').textContent = 'Tem certeza que deseja excluir este post? Esta ação não pode ser desfeita.';
            document.getElementById('postEditForm').style.display = 'none';
            document.getElementById('postDeleteActions').style.display = 'flex';
            modal.classList.add('ativo');
        }

        function confirmarExclusaoPost() {
            const formData = new URLSearchParams();
            formData.set('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
            formData.set('id_post', '<?= $id_post ?>');
            fetch('excluir_post.php', { method: 'POST', body: formData })
                .then((response) => response.json())
                .then((data) => {
                    if (data.sucesso) window.location.href = 'comunidade.php?id=<?= $id_comunidade ?>';
                    else alert(data.mensagem || 'Não foi possível excluir o post.');
                })
                .catch(() => alert('Erro ao excluir o post.'));
        }

        document.getElementById('postEditForm')?.addEventListener('submit', (event) => {
            event.preventDefault();
            const formData = new FormData(event.currentTarget);
            formData.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
            fetch('editar_post.php', { method: 'POST', body: formData })
                .then((response) => response.json())
                .then((data) => {
                    if (data.sucesso) window.location.reload();
                    else alert(data.mensagem || 'Não foi possível editar o post.');
                })
                .catch(() => alert('Erro ao editar o post.'));
        });

        function toggleCommentMenu(button) {
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

        function abrirEditorComentario(idComentario) {
            const p = document.querySelector('.comment-content[data-comentario-id="' + idComentario + '"]');
            if (!p) return;
            const original = p.textContent;
            const textarea = document.createElement('textarea');
            textarea.value = original;
            textarea.rows = 3;
            textarea.style.width = '100%';

            const saveBtn = document.createElement('button');
            saveBtn.textContent = 'Salvar';
            saveBtn.type = 'button';
            saveBtn.className = 'modal-btn modal-btn-confirm';

            const cancelBtn = document.createElement('button');
            cancelBtn.textContent = 'Cancelar';
            cancelBtn.type = 'button';
            cancelBtn.className = 'modal-btn modal-btn-cancel';

            const container = document.createElement('div');
            container.className = 'inline-comment-editor';
            container.appendChild(textarea);
            const actions = document.createElement('div');
            actions.style.marginTop = '6px';
            actions.appendChild(cancelBtn);
            actions.appendChild(saveBtn);
            container.appendChild(actions);

            p.style.display = 'none';
            p.parentNode.insertBefore(container, p.nextSibling);

            cancelBtn.addEventListener('click', function() {
                container.remove();
                p.style.display = '';
            });

            saveBtn.addEventListener('click', function() {
                const novo = textarea.value.trim();
                if (novo.length < 2) {
                    alert('O comentário deve ter no mínimo 2 caracteres.');
                    return;
                }

                const fd = new FormData();
                fd.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
                fd.append('id_comentario', idComentario);
                fd.append('conteudo', novo);

                fetch('editar_comentario.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(data => {
                        if (data.sucesso) {
                            location.reload();
                        } else {
                            alert(data.mensagem || 'Não foi possível editar o comentário.');
                        }
                    })
                    .catch(err => {
                        console.error(err);
                        alert('Erro ao editar comentário.');
                    });
            });
        }

        function excluirComentario(idComentario) {
            // Abre modal de confirmação
            showConfirm('Tem certeza que deseja excluir este comentário?', function() {
                const fd = new FormData();
                fd.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
                fd.append('id_comentario', idComentario);

                fetch('excluir_comentario.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                        .then(data => {
                            if (data.sucesso) {
                                location.reload();
                            } else {
                                alert(data.mensagem || 'Não foi possível excluir o comentário.');
                            }
                        })
                    .catch(err => {
                        console.error(err);
                        showMessage('Erro ao excluir comentário.');
                    });
            });
        }

        function showConfirm(message, onConfirm) {
            const modal = document.getElementById('confirmCommentModal');
            const msg = document.getElementById('confirmCommentMessage');
            const btnOk = document.getElementById('confirmCommentOk');
            const btnCancel = document.getElementById('confirmCommentCancel');
            if (!modal || !msg || !btnOk || !btnCancel) {
                if (confirm(message)) onConfirm();
                return;
            }
            msg.textContent = message;
            modal.classList.add('ativo');

            function cleanup() {
                modal.classList.remove('ativo');
                btnOk.removeEventListener('click', onOk);
                btnCancel.removeEventListener('click', onCancel);
            }

            function onOk() { cleanup(); onConfirm(); }
            function onCancel() { cleanup(); }

            btnOk.addEventListener('click', onOk);
            btnCancel.addEventListener('click', onCancel);
        }

        function showMessage(message) {
            alert(message);
        }

        function fixarComentario(idPost, idComentario) {
            const fd = new FormData();
            fd.append('csrf_token', document.querySelector('input[name="csrf_token"]')?.value || '');
            fd.append('id_post', idPost);
            fd.append('id_comentario', idComentario);
            fd.append('destino', 'post');

            fetch('fixar_comentario.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.sucesso) {
                        location.reload();
                    } else {
                        alert(data.mensagem || 'Não foi possível atualizar a fixação.');
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Erro ao atualizar fixação.');
                });
        }

        function curtirPost(idPost) {
            <?php if (!isset($_SESSION['usuario'])): ?>
                mostrarAvisoLoginCurtida();
                return;
            <?php endif; ?>

            <?php if ($is_site_admin_user): ?>
                alert('Administradores não podem curtir posts.');
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
                } else if (data.mensagem) {
                    alert(data.mensagem);
                }
            })
            .catch(error => console.error('Erro:', error));
        }

        const formComentarioDetalhe = document.getElementById('formComentarioDetalhe');
        if (formComentarioDetalhe) {
            if (window.location.hash === '#formComentarioDetalhe') {
                setTimeout(() => {
                    const campoComentario = formComentarioDetalhe.querySelector('textarea[name="conteudo"]');
                    if (campoComentario) {
                        campoComentario.focus();
                        campoComentario.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }, 80);
            }

            formComentarioDetalhe.addEventListener('submit', function(e) {
                e.preventDefault();
                if (this.dataset.enviando === 'true') return;

                <?php if (!isset($_SESSION['usuario'])): ?>
                    mostrarAvisoLoginComentario();
                    return;
                <?php endif; ?>

                <?php if (!$eh_membro): ?>
                    mostrarPopupSeguirComunidade();
                    return;
                <?php endif; ?>

                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();
                if (conteudo.length < 2) {
                    alert('O comentário deve ter no mínimo 2 caracteres.');
                    return;
                }

                const formData = new FormData(this);
                const formAtual = this;
                const botaoEnviar = formAtual.querySelector('button[type="submit"]');
                const textoOriginal = botaoEnviar?.textContent;
                formAtual.dataset.enviando = 'true';
                if (botaoEnviar) {
                    botaoEnviar.disabled = true;
                    botaoEnviar.textContent = 'Enviando...';
                }

                fetch('criar_comentario.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.limite_atingido) {
                        mostrarAvisoCooldown(formAtual, Number(data.retry_after) || 60, 'comentar');
                        return;
                    }
                    if (data.sucesso) {
                        location.reload();
                    } else if (data.mensagem && data.mensagem.toLowerCase().includes('seguir esta comunidade')) {
                        mostrarPopupSeguirComunidade();
                    } else {
                        alert(data.mensagem || 'Não foi possível enviar o comentário.');
                    }
                })
                .catch(error => {
                    console.error('Erro ao comentar:', error);
                    alert('Erro ao comentar. Tente novamente.');
                })
                .finally(() => {
                    delete formAtual.dataset.enviando;
                    if (botaoEnviar) {
                        botaoEnviar.disabled = false;
                        botaoEnviar.textContent = textoOriginal;
                    }
                });
            });

            const campoComentario = formComentarioDetalhe.querySelector('textarea[name="conteudo"]');
            campoComentario?.addEventListener('keydown', function(event) {
                if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;

                event.preventDefault();
                formComentarioDetalhe.requestSubmit();
            });
        }

        const btnDescartarComentarioDetalhe = document.getElementById('btnDescartarComentarioDetalhe');
        if (btnDescartarComentarioDetalhe) {
            btnDescartarComentarioDetalhe.addEventListener('click', function() {
                const form = document.getElementById('formComentarioDetalhe');
                if (form) form.reset();
            });
        }

        (function() {
            const bellButton = document.getElementById('notificationBellButton');
            const notificationMenu = document.getElementById('notificationMenu');
            const notificationList = document.getElementById('notificationList');
            const badge = document.getElementById('notificationBadge');
            const csrfToken = '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>';

            if (!bellButton || !notificationMenu || !notificationList || !badge) {
                return;
            }

            const escapeHtml = (value = '') => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            const setBadge = (count) => {
                const total = Number(count) || 0;
                if (total <= 0) {
                    badge.textContent = '';
                    badge.style.display = 'none';
                    badge.setAttribute('aria-hidden', 'true');
                    return;
                }
                badge.textContent = total > 99 ? '99+' : String(total);
                badge.style.display = 'flex';
                badge.setAttribute('aria-hidden', 'false');
            };

            const renderNotifications = (items = []) => {
                if (!items.length) {
                    notificationList.innerHTML = '<div class="notification-empty">Nenhuma notificação ainda.</div>';
                    return;
                }

                notificationList.innerHTML = items.map((item) => {
                    const author = escapeHtml(item.nome_remetente || 'Alguém');
                    const avatarUrl = escapeHtml(item.foto_perfil || '');
                    const message = escapeHtml(item.mensagem || 'Nova notificação.');
                    const targetCommentId = Number(item.id_comentario) || 0;
                    const postLink = item.id_post
                        ? `post_detalhes.php?id_post=${encodeURIComponent(item.id_post)}${targetCommentId ? `#comment-${encodeURIComponent(targetCommentId)}` : ''}`
                        : '../index.php';
                    const initial = (String(item.nome_remetente || 'A').trim().charAt(0) || 'A').toUpperCase();
                    const avatarMarkup = avatarUrl
                        ? `<img src="${avatarUrl}" alt="${author}">`
                        : `<span>${escapeHtml(initial)}</span>`;

                    return `
                        <a href="${postLink}" class="notification-item ${item.lida ? 'is-read' : 'is-unread'}">
                            <div class="notification-item-avatar">${avatarMarkup}</div>
                            <div class="notification-item-content">
                                <strong>${author}</strong>
                                <span>${message}</span>
                            </div>
                        </a>
                    `;
                }).join('');
            };

            const loadNotifications = async () => {
                try {
                    const response = await fetch('notificacoes.php?action=list&limit=20', { credentials: 'same-origin' });
                    if (!response.ok) {
                        return;
                    }
                    const data = await response.json();
                    if (!data || !data.ok) {
                        return;
                    }
                    renderNotifications(data.notifications || []);
                    setBadge(data.unread_count || 0);
                } catch (error) {
                    console.error('Erro ao carregar notificações:', error);
                }
            };

            const markNotificationsAsRead = async () => {
                try {
                    const response = await fetch('notificacoes.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({ action: 'read', csrf_token: csrfToken }).toString()
                    });

                    if (response.ok) {
                        await loadNotifications();
                    }
                } catch (error) {
                    console.error('Erro ao marcar notificações como lidas:', error);
                }
            };

            bellButton.addEventListener('click', async () => {
                const isOpen = notificationMenu.style.display === 'block';
                notificationMenu.style.display = isOpen ? 'none' : 'block';
                bellButton.setAttribute('aria-expanded', String(!isOpen));

                if (!isOpen) {
                    await markNotificationsAsRead();
                }
            });

            document.addEventListener('click', (event) => {
                if (!bellButton.contains(event.target) && !notificationMenu.contains(event.target)) {
                    notificationMenu.style.display = 'none';
                    bellButton.setAttribute('aria-expanded', 'false');
                }
            });

            loadNotifications();
            window.setInterval(loadNotifications, 30000);
        })();
    </script>
</body>
</html>
