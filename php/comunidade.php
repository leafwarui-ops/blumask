<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";
require_once __DIR__ . "/media.php";
require_once __DIR__ . "/community_bans.php";
require_once __DIR__ . "/admin_helpers.php";

ensure_community_slug_column($conn);
ensure_post_public_id_column($conn);
ensure_comment_image_column($conn);

// Obtém o ID da comunidade da URL. Aceita rota amigável /comunidade/slug ou o formato antigo ?id=
$id_comunidade = 0;
$requestedSlug = trim((string) ($_GET['slug'] ?? ''));

if ($requestedSlug !== '') {
    $slugEscaped = mysqli_real_escape_string($conn, $requestedSlug);
    $slugResult = mysqli_query($conn, "SELECT id_comunidade, nome, slug FROM comunidade WHERE slug = '$slugEscaped' LIMIT 1");
    if (!$slugResult || mysqli_num_rows($slugResult) === 0) {
        header("Location: ../");
        exit;
    }

    $communityBySlug = mysqli_fetch_assoc($slugResult);
    $id_comunidade = intval($communityBySlug['id_comunidade'] ?? 0);
    $canonicalSlug = trim((string) ($communityBySlug['slug'] ?? ''));
    if ($canonicalSlug !== '' && $requestedSlug !== $canonicalSlug) {
        header('Location: ../comunidade/' . rawurlencode($canonicalSlug), true, 301);
        exit;
    }
} else {
    $id_comunidade = intval($_GET['id'] ?? 0);
    if ($id_comunidade > 0) {
        $communityLookup = mysqli_query($conn, "SELECT id_comunidade, nome, slug FROM comunidade WHERE id_comunidade = $id_comunidade LIMIT 1");
        if ($communityLookup && mysqli_num_rows($communityLookup) > 0) {
            $communityRow = mysqli_fetch_assoc($communityLookup);
            $canonicalSlug = trim((string) ($communityRow['slug'] ?? ''));
            if ($canonicalSlug !== '') {
                header('Location: ../comunidade/' . rawurlencode($canonicalSlug), true, 301);
                exit;
            }
        }
    }
}

if ($id_comunidade <= 0) {
    header("Location: ../");
    exit;
}

global $conn;
$id_usuario = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
$is_site_admin_user = is_site_admin($conn, $id_usuario);

// Buscar dados da comunidade
$sql_comunidade = "SELECT c.*, u.nome_de_exibicao, u.nome_de_usuario
                   FROM comunidade c
                   LEFT JOIN usuario u ON c.id_usuario = u.id_usuario
                   WHERE c.id_comunidade = $id_comunidade LIMIT 1";

$resultado_comunidade = mysqli_query($conn, $sql_comunidade);

if (!$resultado_comunidade || mysqli_num_rows($resultado_comunidade) === 0) {
    header("Location: ../");
    exit;
}

$comunidade = mysqli_fetch_assoc($resultado_comunidade);
$_SESSION['blumask_current_community_id'] = $id_comunidade;
$communityContextToken = isset($_SESSION['usuario']) ? build_community_context_token($id_comunidade, (int) $_SESSION['usuario']['id_usuario']) : '';
$is_community_banned = is_user_banned_from_community($conn, $id_usuario, $id_comunidade);

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
             (SELECT mc.cargo FROM membro_comunidade mc WHERE mc.id_comunidade = p.id_comunidade AND mc.id_usuario = p.id_usuario LIMIT 1) as cargo_autor_comunidade,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) as total_curtidas,
             (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario) as curtiu,
             (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) as total_comentarios
             FROM post p
             JOIN usuario u ON p.id_usuario = u.id_usuario
             JOIN comunidade c ON c.id_comunidade = p.id_comunidade
             WHERE p.id_comunidade = $id_comunidade
             ORDER BY CASE WHEN p.id_post = c.id_post_fixado THEN 0 ELSE 1 END, p.Data_post DESC, p.id_post DESC";

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

function resolve_avatar_url($foto_perfil, $nome_exibicao) {
    $nome = trim((string) ($nome_exibicao ?? 'User'));
    return resolve_media_url($foto_perfil, generated_avatar_url($nome), '../');
}

function resolve_community_image_url($path, $name) {
    return resolve_media_url($path, generated_avatar_url($name), '../');
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
    <link rel="stylesheet" href="../style/busca_style.css?v=<?= time() ?>">
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
                <input type="hidden" name="community_token" value="<?= htmlspecialchars($communityContextToken, ENT_QUOTES, 'UTF-8') ?>">

                <label for="editarAssunto">Título do post</label>
                <input type="text" id="editarAssunto" name="assunto" minlength="3" maxlength="150" placeholder="Título do post (mín. 3 caracteres)" required>

                <label for="editarConteudo">Conteúdo</label>
                <textarea id="editarConteudo" name="conteudo" minlength="5" maxlength="5000" placeholder="Conteúdo do post (mín. 5 caracteres)" required></textarea>

                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="fecharModal()">Cancelar</button>
                    <button type="submit" class="modal-btn modal-btn-confirm">Salvar alterações</button>
                </div>
            </form>

            <form id="formEditarComunidade" class="modal-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo isset($_SESSION['csrf_token']) ? htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                <input type="hidden" name="id_comunidade" value="<?= $id_comunidade ?>">
                <input type="hidden" name="community_token" value="<?= htmlspecialchars($communityContextToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="criar-comunidade-body" style="margin-bottom: 12px;">
                    <div class="criar-comunidade-campos">
                        <div>
                            <label for="editarNomeComunidade">Nome da comunidade</label>
                            <input type="text" id="editarNomeComunidade" name="nome" minlength="2" maxlength="40" placeholder="Nome da comunidade (mín. 2 caracteres)" required>
                        </div>

                        <div>
                            <label for="editarDescricaoComunidade">Descrição</label>
                            <textarea id="editarDescricaoComunidade" name="descricao" maxlength="200" rows="4"></textarea>
                        </div>
                    </div>

                    <div class="criar-comunidade-foto" style="align-items: center; justify-content: center;">
                        <span>Foto / Ícone:</span>
                        <label for="editarImagemComunidade" class="avatar-upload" title="Escolher imagem da comunidade" style="width:72px; height:72px; margin-top: 4px; display: flex; align-items: center; justify-content: center;">
                            <img id="preview-imagem-comunidade-editar" src="" alt="Preview da comunidade">
                            <svg class="avatar-placeholder-icon" viewBox="0 0 24 24"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>
                        </label>
                        <input type="file" id="editarImagemComunidade" name="imagem" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.jpg,.jpeg,.jfif,.png,.gif,.webp,.avif" hidden>
                    </div>
                </div>

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
    <div class="modal-overlay" id="loginCommunityModal">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="loginCommunityTitle">
            <div class="modal-header" id="loginCommunityTitle">Login necessário</div>
            <div class="modal-message">Para seguir esta comunidade, você precisa estar logado.</div>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-confirm" onclick="fecharPopupLoginComunidade()">Entendi</button>
                <button type="button" class="modal-btn modal-btn-confirm" onclick="abrirLoginComunidade()">Entrar</button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="followCommunityModal">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="followCommunityTitle">
            <div class="modal-header" id="followCommunityTitle">Atenção</div>
            <div class="modal-message">Você precisa seguir esta comunidade para publicar posts e comentar.</div>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-confirm" onclick="fecharPopupSeguirComunidade()">Entendi</button>
                <?php if (!isset($_SESSION['usuario'])): ?>
                    <button type="button" class="modal-btn modal-btn-confirm" onclick="abrirLoginComunidade()">Entrar</button>
                <?php elseif (!$eh_membro && !$is_site_admin_user && !$is_community_banned): ?>
                    <button type="button" class="modal-btn modal-btn-confirm" onclick="entrarComunidade(<?= $id_comunidade ?>, this)">Seguir comunidade</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal-overlay<?= $is_community_banned ? ' ativo' : '' ?>" id="communityBannedModal">
        <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="communityBannedTitle">
            <div class="modal-header" id="communityBannedTitle">Banido da comunidade</div>
            <div class="modal-message">Você foi banido desta comunidade. Não pode voltar a segui-la, publicar, comentar ou curtir nesta comunidade.</div>
            <div class="modal-actions">
                <button type="button" class="modal-btn modal-btn-confirm" onclick="sairDaComunidadeBanida()">Entendi</button>
            </div>
        </div>
    </div>

    <dialog id="login-box">
        <form id="popup-form" action="../" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '../', ENT_QUOTES, 'UTF-8') ?>">
            <div class="dialog-tabs">
                <button type="button" id="btn-entrar-dialog">entrar</button>
                <button type="button" id="btn-cadastrar-dialog">cadastrar</button>
            </div>
            <div id="pop-div"></div>
        </form>
    </dialog>

    <div class="page">
        <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px; background: #567fd9; border-bottom: 1px solid rgba(255,255,255,0.2); position: sticky; top: 0; z-index: 100;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="../" style="text-decoration: none; display: flex; align-items: center; gap: 8px; color: #fff;">
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
                    <a href="../?logout=1" class="topbar-logout">Sair</a>
                </div>
            <?php endif; ?>
        </header>

        <main>
            <!-- BARRA DE BUSCA -->
            <div class="search-container">
                <div class="search-bar-interactive">
                    <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke-width="2.5">
                        <circle cx="11" cy="11" r="7"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="input-busca" class="search-input" placeholder="Procurando por Algo? (usuários, comunidades...)" autocomplete="off">
                    <div class="search-actions">
                        <div class="search-spinner" id="busca-spinner" title="Buscando..."></div>
                        <button type="button" class="btn-clear-search" id="btn-limpar-busca" title="Limpar busca">&times;</button>
                    </div>
                </div>

                <div class="search-results-dropdown" id="busca-resultados-dropdown"></div>
            </div>

            <!-- CONTEÚDO PRINCIPAL -->
            <div class="content-wrapper">
                <!-- CARD DA COMUNIDADE (ESQUERDA) -->
                <div class="comunidade-card">
                    <button type="button" class="community-back-btn community-back-btn-card" title="Voltar para a página anterior" aria-label="Voltar para a página anterior" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '../'; } return false;">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18L9 12L15 6"/></svg>
                    </button>

                    <img src="<?= resolve_community_image_url($comunidade['imagem'] ?? null, $comunidade['nome']) ?>" alt="<?= htmlspecialchars($comunidade['nome'], ENT_QUOTES, 'UTF-8') ?>">

                    <h2><?= htmlspecialchars($comunidade['nome'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p class="descricao"><?= htmlspecialchars($comunidade['descricao'] ?? '', ENT_QUOTES, 'UTF-8', false) ?></p>

                    <?php if ($is_community_banned): ?>
                        <button class="btn-seguir ja-membro" type="button" disabled>Banido desta comunidade</button>
                    <?php elseif (!$eh_membro): ?>
                        <?php if (!$is_site_admin_user): ?>
                            <?php if (isset($_SESSION['usuario'])): ?>
                                <button type="button" class="btn-seguir" onclick="entrarComunidade(<?= $id_comunidade ?>, this)">Seguir +</button>
                            <?php else: ?>
                                <button class="btn-seguir" onclick="mostrarPopupLoginComunidade()">Seguir +</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!$is_community_owner && $cargo_usuario !== 1): ?>
                            <button type="button" class="btn-seguir ja-membro" onclick="sairComunidade(<?= $id_comunidade ?>, this)">Sair da comunidade</button>
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
                        <?php if ($eh_membro && !$is_site_admin_user): ?>
                            <button class="btn-novo-post" onclick="toggleFormNovoPost()">+ Novo Post</button>
                        <?php endif; ?>
                    </h3>

                    <?php if ($eh_membro && !$is_site_admin_user): ?>
                        <!-- Área para criar novo post (apenas para membros) -->
                        <div class="form-novo-post" id="formContainer">
                            <h3>Criar novo post</h3>
                            <form id="formNovoPost" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo isset($_SESSION['csrf_token']) ? htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                                <input type="hidden" name="id_comunidade" value="<?= $id_comunidade ?>">
                                <input type="hidden" name="community_token" value="<?= htmlspecialchars($communityContextToken, ENT_QUOTES, 'UTF-8') ?>">
                                
                                <input type="text" id="novo-post-assunto" name="assunto" placeholder="Título do post (mín. 3 caracteres)" minlength="3" maxlength="150" required>
                                
                                <textarea id="novo-post-conteudo" name="conteudo" placeholder="O que você quer compartilhar? (mín. 5 caracteres)" minlength="5" maxlength="5000" required></textarea>

                                <div class="novo-post-upload">
                                    <label class="novo-post-upload-btn" for="novo-post-imagem">Anexar imagem</label>
                                    <input type="file" id="novo-post-imagem" name="imagem" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.jpg,.jpeg,.jfif,.png,.gif,.webp,.avif" hidden>
                                    <div id="novo-post-preview" class="novo-post-preview" style="display: none;">
                                        <img id="novo-post-preview-img" src="" alt="Preview da imagem do post">
                                    </div>
                                </div>
                                
                                <div class="form-novo-post-actions">
                                    <button type="button" class="btn-descartar" onclick="descartarNovoPost()">Descartar</button>
                                    <button type="submit">Publicar</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- LISTA DE POSTS -->
                    <?php if (count($posts) > 0): ?>
                        <?php foreach ($posts as $post): ?>
                            <div class="post" data-post-id="<?= $post['id_post'] ?>" data-public-id="<?= htmlspecialchars($post['public_id'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="post-header">
                                    <?php $usuarioDeletado = trim((string) ($post['nome_de_exibicao'] ?? '')) === 'Usuário deletado' || stripos((string) ($post['nome_de_usuario'] ?? ''), 'usuario_deletado_') === 0; ?>
                                    <?php if ($usuarioDeletado): ?>
                                        <div class="post-author-link" aria-label="Usuário removido" style="cursor: default; pointer-events: none;">
                                            <div class="post-avatar">
                                                <img src="<?= resolve_avatar_url($post['foto_perfil'] ?? null, $post['nome_de_exibicao'] ?? 'User'); ?>" alt="Avatar" style="opacity:0.8;">
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <a href="../usuario/<?= rawurlencode(strtolower(trim((string) ($post['nome_de_usuario'] ?? '')))) ?>" class="post-author-link" onclick="event.stopPropagation();" aria-label="Ver perfil de <?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="post-avatar">
                                                <img src="<?= resolve_avatar_url($post['foto_perfil'] ?? null, $post['nome_de_exibicao'] ?? 'User'); ?>" alt="Avatar">
                                            </div>
                                        </a>
                                    <?php endif; ?>
                                    <div class="post-header-info">
                                        <?php if ($usuarioDeletado): ?>
                                            <div class="post-user-link" style="cursor: default; pointer-events: none;">
                                                <div class="post-user-info">
                                                    <h4><?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                    <p>@<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></p>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <a href="../usuario/<?= rawurlencode(strtolower(trim((string) ($post['nome_de_usuario'] ?? '')))) ?>" class="post-user-link" onclick="event.stopPropagation();">
                                                <div class="post-user-info">
                                                    <h4><?= htmlspecialchars($post['nome_de_exibicao'], ENT_QUOTES, 'UTF-8') ?></h4>
                                                    <p>@<?= htmlspecialchars($post['nome_de_usuario'], ENT_QUOTES, 'UTF-8') ?></p>
                                                </div>
                                            </a>
                                        <?php endif; ?>
                                        <div class="post-date">
                                            <?= date('d/m/Y', strtotime($post['Data_post'])) ?>
                                        </div>
                                    </div>
                                    <?php if ((int) $post['id_usuario'] === $id_usuario || $is_community_admin || $is_site_admin_user): ?>
                                        <div class="post-menu-wrapper">
                                            <button class="post-menu-toggle" type="button" aria-label="Opções do post" onclick="togglePostMenu(this)">⋯</button>
                                            <div class="post-menu-dropdown">
                                                <?php if ((int) $post['id_usuario'] === $id_usuario): ?>
                                                    <button class="post-menu-btn" type="button" onclick="abrirModalEditarPost(<?= $post['id_post'] ?>)">Editar post</button>
                                                <?php endif; ?>

                                                <?php if ((int) $post['id_usuario'] === $id_usuario || $is_community_admin || $is_site_admin_user): ?>
                                                    <button class="post-menu-btn danger" type="button" onclick="abrirModalExcluirPost(<?= $post['id_post'] ?>)">Excluir post</button>
                                                <?php endif; ?>

                                                <?php if ($is_community_admin): ?>
                                                    <button class="post-menu-btn" type="button" onclick="fixarPost(<?= $post['id_post'] ?>)">
                                                        <?= !empty($comunidade['id_post_fixado']) && (int) $post['id_post'] === (int) $comunidade['id_post_fixado'] ? 'Desfixar post' : 'Fixar post' ?>
                                                    </button>
                                                    <?php if ((int) $post['id_usuario'] !== $id_usuario
                                                        && (int) $post['id_usuario'] !== (int) $comunidade['id_usuario']
                                                        && (int) ($post['cargo_autor_comunidade'] ?? 0) !== 1): ?>
                                                        <button class="post-menu-btn danger" type="button" onclick='abrirModalBanirUsuario(<?= (int) $post['id_usuario'] ?>, <?= json_encode($post['nome_de_exibicao'] ?? 'este usuário', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>Banir da comunidade</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($comunidade['id_post_fixado']) && (int) $post['id_post'] === (int) $comunidade['id_post_fixado']): ?>
                                    <div class="post-pinned-badge">📌 Post fixado</div>
                                <?php endif; ?>
                                <div class="post-title"><?= htmlspecialchars($post['assunto'] ?? 'Sem assunto', ENT_QUOTES, 'UTF-8', false) ?></div>
                                <div class="post-content"><?= htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8', false) ?></div>

                                <?php if (!empty($post['imagem'])): ?>
                                    <?php $post_imagem_url = resolve_media_url($post['imagem'] ?? '', '', '../'); ?>
                                    <?php if ($post_imagem_url !== ''): ?>
                                        <div class="post-image-wrap">
                                            <img class="post-image" src="<?= $post_imagem_url ?>" alt="Imagem do post">
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <div class="post-actions">
                                    <span class="post-action" <?= $is_site_admin_user ? 'aria-disabled="true" style="cursor:default; opacity:0.8;"' : 'onclick="curtirPost(' . $post['id_post'] . ', this)"' ?>>
                                        <span><?php echo intval($post['curtiu']) === 1 ? '❤️' : '🤍'; ?></span>
                                        <span><?= intval($post['total_curtidas']) ?></span>
                                    </span>

                                    <button type="button" class="post-action comment-toggle" data-post-id="<?= $post['id_post'] ?>" aria-label="Abrir comentário" style="border: none; background: transparent; padding: 0; cursor: pointer;">
                                        <span>💬</span>
                                        <span><?= intval($post['total_comentarios']) ?></span>
                                    </button>
                                </div>

                                <?php if (!$is_site_admin_user): ?>
                                <div class="comment-form-wrap" id="comment-form-<?= $post['id_post'] ?>" style="display: none;">
                                    <form class="form-comentario" data-post-id="<?= $post['id_post'] ?>" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="id_post" value="<?= $post['id_post'] ?>">
                                        <textarea name="conteudo" rows="3" maxlength="2000" placeholder="Escreva um comentário... (mín. 2 caracteres)"></textarea>
                                        <div class="comment-image-tools">
                                            <label class="comment-image-button" for="comment-image-<?= (int) $post['id_post'] ?>">Anexar imagem</label>
                                            <input type="file" id="comment-image-<?= (int) $post['id_post'] ?>" name="imagem" class="comment-image-input" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.jpg,.jpeg,.jfif,.png,.gif,.webp,.avif" hidden>
                                            <span class="comment-image-filename" aria-live="polite"></span>
                                            <button type="button" class="comment-image-remove" hidden>Remover</button>
                                        </div>
                                        <img class="comment-image-preview" alt="Prévia da imagem do comentário" hidden>
                                        <div style="display:flex; gap:8px; margin-top:8px;">
                                            <button type="button" class="btn-descartar" onclick="descartarComentarioInline(<?= $post['id_post'] ?>)">Descartar</button>
                                            <button type="submit">Comentar</button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="posts-empty">
                            <p>Nenhum post nesta comunidade ainda.</p>
                            <?php if ($is_site_admin_user): ?>
                                <p>Administradores podem visualizar, mas não publicar ou comentar.</p>
                            <?php elseif ($eh_membro): ?>
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

    <script src="../js/busca.js?v=<?= time() ?>"></script>
    <script src="../js/login_writter.js?v=<?= time() ?>"></script>
    <?php if (isset($_SESSION['usuario'])): ?>
    <script src="../js/admin_messages.js?v=<?= time() ?>"></script>
    <?php endif; ?>
    <script src="../js/logout_confirm.js?v=<?= time() ?>"></script>
    <script>
        const postsMap = <?php echo json_encode($postsMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const ehMembro = <?= $eh_membro ? 'true' : 'false' ?>;
        const comunidadeBanido = <?= $is_community_banned ? 'true' : 'false' ?>;
        const communityContextToken = <?= json_encode($communityContextToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        let acaoAtual = null;

        function mostrarAvisoCooldown(form, segundos, acao = 'postar') {
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

        function sairDaComunidadeBanida() {
            window.location.href = '../';
        }

        if (<?= $id_usuario > 0 && !$is_community_banned ? 'true' : 'false' ?>) {
            const verificarBanimento = () => {
                if (document.hidden) return;
                fetch(`../php/banir_usuario_comunidade.php?verificar=1&id_comunidade=<?= $id_comunidade ?>`, { cache: 'no-store' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.banido) {
                            document.getElementById('communityBannedModal')?.classList.add('ativo');
                        }
                    })
                    .catch(() => {});
            };
            window.setInterval(verificarBanimento, 8000);
        }

        function mostrarPopupLoginComunidade() {
            const modal = document.getElementById('loginCommunityModal');
            if (!modal) return;
            modal.classList.add('ativo');
        }

        function fecharPopupLoginComunidade() {
            const modal = document.getElementById('loginCommunityModal');
            if (!modal) return;
            modal.classList.remove('ativo');
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

        const loginCommunityDialog = document.getElementById('login-box');
        const loginCommunityContent = document.getElementById('pop-div');
        const loginCommunityEnter = document.getElementById('btn-entrar-dialog');
        const loginCommunityRegister = document.getElementById('btn-cadastrar-dialog');

        function marcarAbaLoginComunidade(ativa) {
            loginCommunityEnter?.classList.toggle('active-tab', ativa === 'entrar');
            loginCommunityRegister?.classList.toggle('active-tab', ativa === 'cadastrar');
        }

        function abrirLoginComunidade(modo = 0) {
            if (!loginCommunityDialog || !loginCommunityContent) return;
            const modoCadastro = modo === 1 || modo === '1' || modo === 'cadastrar';
            loginCommunityContent.innerHTML = '';
            trocar(modoCadastro ? 1 : 0, loginCommunityContent);
            marcarAbaLoginComunidade(modoCadastro ? 'cadastrar' : 'entrar');
            document.querySelectorAll('.modal-overlay.ativo').forEach((modal) => modal.classList.remove('ativo'));
            if (!loginCommunityDialog.open) loginCommunityDialog.showModal();
        }

        loginCommunityEnter?.addEventListener('click', () => abrirLoginComunidade(0));
        loginCommunityRegister?.addEventListener('click', () => abrirLoginComunidade(1));

        loginCommunityDialog?.addEventListener('click', (event) => {
            if (event.target === loginCommunityDialog) loginCommunityDialog.close();
        });

        document.getElementById('loginCommunityModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                fecharPopupLoginComunidade();
            }
        });

        document.getElementById('followCommunityModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                fecharPopupSeguirComunidade();
            }
        });

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
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_comunidade=${idComunidade}&community_token=${encodeURIComponent(communityContextToken)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    window.location.href = '../';
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

        function abrirModalBanirUsuario(idUsuario, nomeUsuario) {
            abrirModal('Banir usuário', `Tem certeza que deseja banir ${nomeUsuario} desta comunidade? A pessoa será removida e não poderá voltar a participar.`, true);
            acaoAtual = function() {
                fetch('../php/banir_usuario_comunidade.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `csrf_token=${encodeURIComponent(csrfToken)}&id_comunidade=<?= $id_comunidade ?>&id_usuario=${encodeURIComponent(idUsuario)}`
                })
                .then(response => response.json())
                .then(data => {
                    abrirModal(data.sucesso ? 'Usuário banido' : 'Não foi possível banir', data.mensagem || 'Tente novamente.');
                    if (data.sucesso) acaoAtual = () => window.location.reload();
                })
                .catch(() => abrirModal('Erro', 'Não foi possível concluir o banimento. Tente novamente.'));
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
                            <button type="button" class="modal-btn modal-btn-confirm" onclick="abrirLoginComunidade()">Entrar</button>
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
                            <button type="button" class="modal-btn modal-btn-confirm" onclick="abrirLoginComunidade()">Entrar</button>
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

        // ===== COMUNIDADE ENTRY FUNCTION =====
        function toggleFormNovoPost() {
            const formContainer = document.getElementById('formContainer');
            formContainer.classList.toggle('ativo');
        }

        function descartarNovoPost() {
            const form = document.getElementById('formNovoPost');
            const formContainer = document.getElementById('formContainer');
            const inputImagemPost = document.getElementById('novo-post-imagem');
            const previewImagemPost = document.getElementById('novo-post-preview');
            const previewImagemPostImg = document.getElementById('novo-post-preview-img');
            if (form) form.reset();
            if (inputImagemPost) inputImagemPost.value = '';
            if (previewImagemPostImg) previewImagemPostImg.src = '';
            if (previewImagemPost) previewImagemPost.style.display = 'none';
            if (formContainer) formContainer.classList.remove('ativo');
        }

        function entrarComunidade(idComunidade, btn) {
            if (!btn) return;
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Carregando...';

            fetch('../php/entrar_comunidade.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_comunidade=${idComunidade}&community_token=${encodeURIComponent(communityContextToken)}`
            })
            .then(async response => ({ response, data: await response.json() }))
            .then(({ response, data }) => {
                if (data.sucesso) {
                    location.reload();
                } else {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    if (response.status === 401) {
                        mostrarPopupLoginComunidade();
                    } else {
                        alert(data.mensagem || 'Não foi possível seguir a comunidade. Tente novamente.');
                    }
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                btn.disabled = false;
                btn.textContent = originalText;
                alert('Não foi possível seguir a comunidade. Verifique sua conexão e tente novamente.');
            });
        }

        function sairComunidade(idComunidade, btn) {
            if (!btn) return;

            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Saindo...';

            fetch('../php/sair_comunidade.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_comunidade=${idComunidade}&community_token=${encodeURIComponent(communityContextToken)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    location.reload();
                } else {
                    btn.disabled = false;
                    btn.textContent = originalText;
                    alert(data.mensagem || 'Não foi possível sair da comunidade.');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                btn.disabled = false;
                btn.textContent = originalText;
                alert('Erro ao sair da comunidade. Tente novamente.');
            });
        }

        // ===== POST LIKE FUNCTION =====
        function curtirPost(idPost, elemento) {
            <?php if (!isset($_SESSION['usuario'])): ?>
                mostrarAvisoLoginCurtida();
                return;
            <?php endif; ?>

            <?php if ($is_site_admin_user): ?>
                alert('Administradores não podem curtir posts.');
                return;
            <?php endif; ?>

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
                } else if (data.mensagem) {
                    alert(data.mensagem);
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

                const publicId = this.dataset.publicId;
                if (publicId) {
                    window.location.href = `../post/${publicId}`;
                }
            });
        });

        document.querySelectorAll('.comment-toggle').forEach(button => {
            button.addEventListener('click', function() {
                <?php if (!isset($_SESSION['usuario'])): ?>
                    mostrarAvisoLoginComentario();
                    return;
                <?php endif; ?>

                <?php if (!$eh_membro): ?>
                    mostrarPopupSeguirComunidade();
                    return;
                <?php endif; ?>

                const postId = this.dataset.postId;
                const formWrap = document.getElementById(`comment-form-${postId}`);
                if (formWrap) {
                    formWrap.style.display = formWrap.style.display === 'none' ? 'block' : 'none';
                }
            });
        });

        function configurarImagemComentario(input) {
            const form = input.closest('form');
            const preview = form.querySelector('.comment-image-preview');
            const filename = form.querySelector('.comment-image-filename');
            const removeButton = form.querySelector('.comment-image-remove');

            const clearSelection = () => {
                input.value = '';
                preview.removeAttribute('src');
                preview.hidden = true;
                filename.textContent = '';
                removeButton.hidden = true;
            };

            input.addEventListener('change', () => {
                const file = input.files[0];
                if (!file) {
                    clearSelection();
                    return;
                }
                if (file.size > 2 * 1024 * 1024) {
                    alert('A imagem do comentário deve ter no máximo 2 MB.');
                    clearSelection();
                    return;
                }

                const reader = new FileReader();
                reader.addEventListener('load', () => {
                    if (input.files[0] !== file) return;
                    preview.src = reader.result;
                    preview.hidden = false;
                    filename.textContent = file.name;
                    removeButton.hidden = false;
                });
                reader.readAsDataURL(file);
            });

            removeButton.addEventListener('click', clearSelection);
            form.addEventListener('reset', () => window.setTimeout(clearSelection, 0));
        }

        document.querySelectorAll('.comment-image-input').forEach(configurarImagemComentario);

        document.querySelectorAll('.form-comentario').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (this.dataset.enviando === 'true') return;

                <?php if (!isset($_SESSION['usuario'])): ?>
                    mostrarAvisoLoginComentario();
                    return;
                <?php endif; ?>

                if (!ehMembro) {
                    mostrarPopupSeguirComunidade();
                    return;
                }

                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();
                const image = this.querySelector('input[name="imagem"]')?.files?.[0];
                if (conteudo.length < 2 && !image) {
                    alert('Escreva ao menos 2 caracteres ou anexe uma imagem.');
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

                fetch('../php/criar_comentario.php', {
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
        });

        document.querySelectorAll('#formNovoPost textarea[name="conteudo"], .form-comentario textarea[name="conteudo"]').forEach(textarea => {
            textarea.addEventListener('keydown', function(event) {
                if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;

                event.preventDefault();
                this.form?.requestSubmit();
            });
        });

        function descartarComentarioInline(idPost) {
            const formWrap = document.getElementById(`comment-form-${idPost}`);
            const form = formWrap ? formWrap.querySelector('.form-comentario') : null;
            if (form) form.reset();
            if (formWrap) formWrap.style.display = 'none';
        }

        // ===== NEW POST FORM =====
        const formNovoPost = document.getElementById('formNovoPost');
        const inputImagemPost = document.getElementById('novo-post-imagem');
        const previewImagemPost = document.getElementById('novo-post-preview');
        const previewImagemPostImg = document.getElementById('novo-post-preview-img');
        const maxPostImageSize = 2 * 1024 * 1024;

        if (inputImagemPost) {
            inputImagemPost.addEventListener('change', function() {
                const file = this.files && this.files[0];
                if (!file) {
                    if (previewImagemPost) previewImagemPost.style.display = 'none';
                    return;
                }

                if (file.size > maxPostImageSize) {
                    alert('A imagem do post deve ter no máximo 2 MB para não ficar muito pesada.');
                    this.value = '';
                    if (previewImagemPost) previewImagemPost.style.display = 'none';
                    return;
                }

                const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
                const allowedExtensions = ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'avif'];
                if ((!allowedTypes.includes(file.type) && !(file.name && allowedExtensions.some(ext => file.name.toLowerCase().endsWith('.' + ext)))) || !file.type) {
                    alert('Formato inválido. Use JPG, JPEG, JFIF, PNG, GIF, WEBP ou AVIF.');
                    this.value = '';
                    if (previewImagemPost) previewImagemPost.style.display = 'none';
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(event) {
                    if (previewImagemPostImg) {
                        previewImagemPostImg.src = event.target.result;
                    }
                    if (previewImagemPost) {
                        previewImagemPost.style.display = 'block';
                    }
                };
                reader.readAsDataURL(file);
            });
        }

        if (formNovoPost) {
            formNovoPost.addEventListener('submit', function(e) {
                e.preventDefault();
                if (this.dataset.enviando === 'true') return;

                <?php if (!$eh_membro): ?>
                    mostrarPopupSeguirComunidade();
                    return;
                <?php endif; ?>
                
                const assunto = this.querySelector('input[name="assunto"]').value.trim();
                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();

                if (assunto.length < 3) {
                    alert('O título do post deve ter no mínimo 3 caracteres.');
                    return;
                }
                if (conteudo.length < 5) {
                    alert('O conteúdo do post deve ter no mínimo 5 caracteres.');
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

                fetch('../php/criar_post.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.limite_atingido) {
                        mostrarAvisoCooldown(formAtual, Number(data.retry_after) || 60, 'postar');
                        return;
                    }
                    if (data.sucesso) {
                        location.reload();
                    } else if (data.mensagem && data.mensagem.toLowerCase().includes('seguir esta comunidade')) {
                        mostrarPopupSeguirComunidade();
                    } else {
                        alert(data.mensagem || 'Não foi possível publicar o post.');
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao criar post. Tente novamente.');
                })
                .finally(() => {
                    delete formAtual.dataset.enviando;
                    if (botaoEnviar) {
                        botaoEnviar.disabled = false;
                        botaoEnviar.textContent = textoOriginal;
                    }
                });
            });
        }

        const formEditarPost = document.getElementById('formEditarPost');
        if (formEditarPost) {
            formEditarPost.addEventListener('submit', function(e) {
                e.preventDefault();

                const assunto = this.querySelector('input[name="assunto"]').value.trim();
                const conteudo = this.querySelector('textarea[name="conteudo"]').value.trim();

                if (assunto.length < 3) {
                    document.getElementById('modalMessage').textContent = 'O título do post deve ter no mínimo 3 caracteres.';
                    return;
                }
                if (conteudo.length < 5) {
                    document.getElementById('modalMessage').textContent = 'O conteúdo do post deve ter no mínimo 5 caracteres.';
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

        const editarImagemInput = document.getElementById('editarImagemComunidade');
        const editarImagemPreview = document.getElementById('preview-imagem-comunidade-editar');
        const editarImagemUpload = editarImagemPreview?.closest('.avatar-upload');

        if (editarImagemInput && editarImagemPreview && editarImagemUpload) {
            editarImagemInput.addEventListener('change', function() {
                const file = this.files && this.files[0];
                if (!file) {
                    editarImagemPreview.src = '';
                    editarImagemUpload.classList.remove('has-image');
                    return;
                }

                if (file.size > 2 * 1024 * 1024) {
                    alert('A imagem da comunidade não pode passar de 2 MB.');
                    this.value = '';
                    editarImagemPreview.src = '';
                    editarImagemUpload.classList.remove('has-image');
                    return;
                }

                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
                if (!validTypes.includes(file.type) && !(file.name && /\.(jpg|jpeg|jfif|png|gif|webp|avif)$/i.test(file.name))) {
                    alert('Formato de imagem inválido. Use JPG, JPEG, JFIF, PNG, GIF, WEBP ou AVIF.');
                    this.value = '';
                    editarImagemPreview.src = '';
                    editarImagemUpload.classList.remove('has-image');
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(e) {
                    editarImagemPreview.src = e.target.result;
                    editarImagemUpload.classList.add('has-image');
                };
                reader.readAsDataURL(file);
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

        // Busca da comunidade usa o mesmo dropdown do index, conforme o layout padrão do site.

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
                    const postLink = item.public_id
                        ? `../post/${encodeURIComponent(item.public_id)}${targetCommentId ? `#comment-${encodeURIComponent(targetCommentId)}` : ''}`
                        : '../';
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
                    const response = await fetch('../php/notificacoes.php?action=list&limit=20', { credentials: 'same-origin' });
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
                    const response = await fetch('../php/notificacoes.php', {
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
