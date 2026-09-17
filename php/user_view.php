<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/bd.php";
require_once __DIR__ . "/media.php";
require_once __DIR__ . "/profile_pins.php";
ensure_profile_pin_tables($conn);

$usuarios = [];
$result = $conn->query("SELECT id_usuario, nome_de_exibicao, nome_de_usuario, descricao, banner, foto_perfil, id_post_fixado FROM usuario ORDER BY nome_de_exibicao ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }
}

$selectedUser = null;
$requestedId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$isDeletedUser = function ($user) {
    if (!$user) {
        return false;
    }

    $displayName = trim((string) ($user['nome_de_exibicao'] ?? ''));
    $username = trim((string) ($user['nome_de_usuario'] ?? ''));

    return $displayName === 'Usuário deletado' || stripos($username, 'usuario_deletado_') === 0;
};

if (!empty($usuarios)) {
    if ($requestedId > 0) {
        foreach ($usuarios as $user) {
            if (intval($user['id_usuario']) === $requestedId) {
                $selectedUser = $user;
                break;
            }
        }

        if (!$selectedUser || $isDeletedUser($selectedUser)) {
            header("Location: ../index.php");
            exit;
        }
    } else {
        header("Location: ../index.php");
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}

$loggedUser = $_SESSION['usuario'] ?? null;
$profileUser = $selectedUser ?: $loggedUser;
$fixedPost = null;
$posts = [];
$communityList = [];

$profile_comment_pin_column = mysqli_query($conn, "SHOW COLUMNS FROM usuario LIKE 'id_comentario_fixado'");
if ($profile_comment_pin_column && mysqli_num_rows($profile_comment_pin_column) === 0) {
  mysqli_query($conn, "ALTER TABLE usuario ADD COLUMN id_comentario_fixado INT NULL");
}

if ($profileUser) {
    $profileUserId = intval($profileUser['id_usuario'] ?? 0);
    $postCount = 0;
    $communityCount = 0;

    if ($profileUserId > 0) {
        $postCountResult = $conn->query("SELECT COUNT(*) AS total FROM post WHERE id_usuario = $profileUserId");
        if ($postCountResult) {
            $postRow = $postCountResult->fetch_assoc();
            $postCount = intval($postRow['total'] ?? 0);
        }

        $communityCountResult = $conn->query("SELECT COUNT(*) AS total FROM membro_comunidade WHERE id_usuario = $profileUserId");
        if ($communityCountResult) {
            $communityRow = $communityCountResult->fetch_assoc();
            $communityCount = intval($communityRow['total'] ?? 0);
        }

        $communityQuery = "SELECT c.id_comunidade, c.nome, c.imagem
                          FROM membro_comunidade mc
                          INNER JOIN comunidade c ON c.id_comunidade = mc.id_comunidade
                          WHERE mc.id_usuario = $profileUserId
                          ORDER BY c.nome ASC";
        $communityResult = $conn->query($communityQuery);
        if ($communityResult) {
            while ($communityRow = $communityResult->fetch_assoc()) {
                $communityList[] = $communityRow;
            }
        }

        $id_usuario_logado = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
        $pinned_posts = [];
        $pinned_comments = [];
        $recent_posts = [];
        $recent_comments = [];

        // 1. Posts fixados no perfil
        $sql_pinned = "SELECT 
                p.id_post,
                p.id_comunidade,
                p.Data_post,
                p.conteudo,
                p.assunto,
                c.nome AS nome_comunidade,
                c.imagem AS imagem_comunidade,
                u.nome_de_exibicao,
                u.nome_de_usuario,
                (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) AS total_curtidas,
                (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) AS total_comentarios"
                . ($id_usuario_logado > 0 ? ", (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario_logado) AS curtiu" : ", 0 AS curtiu") . "
            FROM perfil_post_fixado pf
            INNER JOIN post p ON p.id_post = pf.id_post
            INNER JOIN comunidade c ON p.id_comunidade = c.id_comunidade
            INNER JOIN usuario u ON u.id_usuario = p.id_usuario
            WHERE pf.id_usuario = $profileUserId AND p.id_usuario = $profileUserId
            ORDER BY pf.data_fixacao DESC";
            
            $res_pinned = mysqli_query($conn, $sql_pinned);
            if ($res_pinned) {
                while ($pin_row = mysqli_fetch_assoc($res_pinned)) {
                    $pinned_posts[] = $pin_row;
                }
            }
          $sql_pinned_comments = "SELECT
            c.id_comentario,
            c.id_post,
            c.id_usuario,
            c.conteudo,
            c.data_comentario,
            p.id_comunidade,
            p.assunto,
            p.id_usuario AS post_autor_id,
            u.nome_de_exibicao,
            u.nome_de_usuario,
            u.foto_perfil
          FROM perfil_comentario_fixado pf
          INNER JOIN comentario c ON c.id_comentario = pf.id_comentario
          INNER JOIN post p ON p.id_post = c.id_post
          INNER JOIN usuario u ON u.id_usuario = c.id_usuario
          WHERE pf.id_usuario = $profileUserId
          ORDER BY pf.data_fixacao DESC";

          $res_pinned_comments = mysqli_query($conn, $sql_pinned_comments);
          if ($res_pinned_comments) {
            while ($pin_comment_row = mysqli_fetch_assoc($res_pinned_comments)) {
              $pinned_comments[] = $pin_comment_row;
            }
          }

        // 2. Posts recentes deste usuário
        $sql_recent = "SELECT 
            p.id_post,
            p.id_comunidade,
            p.Data_post,
            p.conteudo,
            p.assunto,
            c.nome AS nome_comunidade,
            c.imagem AS imagem_comunidade,
            u.nome_de_exibicao,
            u.nome_de_usuario,
            (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) AS total_curtidas,
            (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) AS total_comentarios"
            . ($id_usuario_logado > 0 ? ", (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario_logado) AS curtiu" : ", 0 AS curtiu") . "
        FROM post p
        INNER JOIN comunidade c ON p.id_comunidade = c.id_comunidade
        INNER JOIN usuario u ON u.id_usuario = p.id_usuario
        WHERE p.id_usuario = $profileUserId
        ORDER BY p.Data_post DESC, p.id_post DESC
        LIMIT 30";

        $res_recent = mysqli_query($conn, $sql_recent);
        if ($res_recent) {
            while ($rec_row = mysqli_fetch_assoc($res_recent)) {
                $recent_posts[] = $rec_row;
            }
        }

        $sql_recent_comments = "SELECT
          c.id_comentario,
          c.id_post,
          c.conteudo,
          c.data_comentario,
          p.assunto,
          p.id_comunidade,
          u.nome_de_exibicao,
          u.nome_de_usuario,
          u.foto_perfil
        FROM comentario c
        INNER JOIN post p ON p.id_post = c.id_post
        INNER JOIN usuario u ON u.id_usuario = c.id_usuario
        WHERE c.id_usuario = $profileUserId
        ORDER BY c.data_comentario DESC, c.id_comentario DESC
        LIMIT 30";

        $res_recent_comments = mysqli_query($conn, $sql_recent_comments);
        if ($res_recent_comments) {
          while ($rec_comment_row = mysqli_fetch_assoc($res_recent_comments)) {
            $recent_comments[] = $rec_comment_row;
          }
        }
    }
}

function safeText($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function resolve_asset_url($path, $fallback = null) {
  return resolve_media_url($path, $fallback ?? '', '../');
}

function userAvatar($user) {
    $nome = $user['nome_de_exibicao'] ?? $user['nome_de_usuario'] ?? 'User';

    if (!empty($user['foto_perfil'])) {
        return resolve_asset_url($user['foto_perfil'], "https://ui-avatars.com/api/?name=" . urlencode($nome) . "&background=random");
    }

    return "https://ui-avatars.com/api/?name=" . urlencode($nome) . "&background=random";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Blumask</title>
  <link rel="icon" type="image/webp" href="../style/blumaskWhiteLogo.webp">
  <link rel="stylesheet" href="../style/index_style.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../style/comunidade_style.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../style/busca_style.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../style/user_view_style.css?v=<?= time() ?>">
</head>
<body data-id-usuario="<?= isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0 ?>">
  <div class="page">
    <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px; background: #567fd9;">
      <div style="display: flex; align-items: center; gap: 12px; cursor: pointer;" onclick="window.location.href='../index.php'">
        <img src="../style/blumaskWhiteLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
        <h1 style="margin: 0; font-size: 20px; color: #fff;">BluMask</h1>
      </div>
      <div class="topbar-actions" style="display: flex; align-items: center; gap: 12px;">
        <?php if ($loggedUser): ?>
          <?php
            $headerAvatar = !empty($loggedUser['foto_perfil'])
              ? resolve_asset_url($loggedUser['foto_perfil'], "https://ui-avatars.com/api/?name=" . urlencode(($loggedUser['nome_de_exibicao'] ?? $loggedUser['nome_de_usuario'] ?? 'User')) . "&background=random")
              : "https://ui-avatars.com/api/?name=" . urlencode(($loggedUser['nome_de_exibicao'] ?? $loggedUser['nome_de_usuario'] ?? 'User')) . "&background=random";
          ?>
          <button class="profile-avatar-button" type="button" onclick="window.location.href='../index.php'" title="Voltar para o início" aria-label="Voltar para o início">
            <img src="<?= $headerAvatar ?>" alt="Foto do perfil">
          </button>
          <a href="../index.php?logout=1" class="topbar-logout">Sair</a>
        <?php endif; ?>
      </div>
    </header>

    <main class="home-layout">
      <aside class="rounded-panel profile-panel">
        <?php if ($profileUser): ?>
          <?php
            $profileName = safeText($profileUser['nome_de_exibicao'] ?? '');
            $profileHandle = safeText($profileUser['nome_de_usuario'] ?? '');
            $profileBio = safeText($profileUser['descricao'] ?? '');
            $profileAvatar = !empty($profileUser['foto_perfil'])
              ? resolve_asset_url($profileUser['foto_perfil'], "https://ui-avatars.com/api/?name=" . urlencode(($profileUser['nome_de_exibicao'] ?? $profileUser['nome_de_usuario'] ?? 'User')) . "&background=random")
              : "https://ui-avatars.com/api/?name=" . urlencode(($profileUser['nome_de_exibicao'] ?? $profileUser['nome_de_usuario'] ?? 'User')) . "&background=random";
            $profileBannerUrl = '';
            if (!empty($profileUser['banner'])) {
              $bannerRaw = trim((string) $profileUser['banner']);
              $bannerNormalized = ltrim($bannerRaw, './');
              $bannerPath = __DIR__ . '/../' . $bannerNormalized;
              if ($bannerRaw !== '' && file_exists($bannerPath) && is_file($bannerPath)) {
                $profileBannerUrl = '../' . $bannerNormalized;
              }
            }
            $profileHeaderStyle = $profileBannerUrl !== ''
              ? "background-image: url('" . htmlspecialchars($profileBannerUrl, ENT_QUOTES, 'UTF-8') . "'); background-size: cover; background-position: center; background-repeat: no-repeat;"
              : "background: linear-gradient(135deg, #d9d2ec, #c3cbd5);";
          ?>
          <div class="profile-header" style="<?= $profileHeaderStyle ?>">
            <div class="profile-avatar-wrap">
              <img src="<?= $profileAvatar ?>" alt="perfil">
            </div>
          </div>
          <div class="profile-body">
            <h2 class="profile-name"><?= $profileName ?: 'Usuário' ?></h2>
            <div class="profile-handle">@<?= $profileHandle ?: 'usuario' ?></div>
            <?php if (!empty($profileBio)): ?>
              <p class="profile-bio"><?= $profileBio ?></p>
            <?php endif; ?>
            <div class="profile-stats">
              <div>
                <strong><?= $postCount ?></strong>
                <span>Posts</span>
              </div>
              <div>
                <strong><?= $communityCount ?></strong>
                <span>Comunidades</span>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="profile-header">
            <div class="profile-avatar-wrap" style="display:flex;align-items:center;justify-content:center;background:#4b4b4b;">
              <svg viewBox="0 0 24 24" width="40" height="40" fill="#d8d8d8"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>
            </div>
          </div>
          <div class="profile-body">
            <h2 class="profile-name">Perfil indisponível</h2>
            <p class="profile-bio">Nenhum usuário foi encontrado.</p>
          </div>
        <?php endif; ?>
      </aside>

      <section class="center-col">
        <div class="search-container">
          <div class="search-bar-interactive">
            <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke-width="2.5" aria-hidden="true">
              <circle cx="11" cy="11" r="7"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" id="input-busca" class="search-input" placeholder="Procurando por Algo? (usuários, comunidades...)" autocomplete="off">
            <div class="search-actions">
              <div class="search-spinner" id="busca-spinner" title="Buscando..."></div>
              <button type="button" class="btn-clear-search" id="btn-limpar-busca" title="Limpar busca">&times;</button>
            </div>
          </div>

          <div class="search-filter-tabs">
            <button type="button" class="filter-tab-btn active" data-tipo="todos">Todos</button>
            <button type="button" class="filter-tab-btn" data-tipo="usuarios">Usuários</button>
            <button type="button" class="filter-tab-btn" data-tipo="comunidades">Comunidades</button>
          </div>

          <div class="search-results-dropdown" id="busca-resultados-dropdown"></div>
        </div>

        <div class="panel last-post">
          <div class="panel-header feed-panel-header">
            <div class="feed-tabs">
              <button type="button" class="feed-tab-btn active" data-target="user-feed-fixados">
                Fixados
              </button>
              <button type="button" class="feed-tab-btn" data-target="user-feed-recentes">
                Últimos Posts
              </button>
            </div>
          </div>
          <div class="last-post-body">
            <!-- Aba 1: Fixados (Padrão) -->
            <div class="feed-tab-content active" id="user-feed-fixados">
              <?php if (!empty($pinned_posts) || !empty($pinned_comments)): ?>
                <div class="posts-feed">
                  <?php foreach ($pinned_posts as $post): ?>
                    <?php 
                      $id_post = intval($post['id_post']);
                      $id_comunidade = intval($post['id_comunidade']);
                      $nome_comunidade = htmlspecialchars($post['nome_comunidade'], ENT_QUOTES, 'UTF-8');
                      $data_post_ts = strtotime($post['Data_post']);
                      $data_formatada = $data_post_ts ? date('d/m/Y', $data_post_ts) : htmlspecialchars($post['Data_post'], ENT_QUOTES, 'UTF-8');
                      $assunto = !empty($post['assunto']) ? htmlspecialchars($post['assunto'], ENT_QUOTES, 'UTF-8') : '';
                      $conteudo = htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8');
                      $total_curtidas = intval($post['total_curtidas']);
                      $total_comentarios = intval($post['total_comentarios']);
                      $curtiu = intval($post['curtiu']) === 1;

                      $raw_img = $post['imagem_comunidade'] ?? '';
                      if (!empty($raw_img)) {
                          $img_comunidade = (strpos($raw_img, 'http') === 0 || strpos($raw_img, '../') === 0) 
                              ? htmlspecialchars($raw_img, ENT_QUOTES, 'UTF-8') 
                              : '../' . htmlspecialchars($raw_img, ENT_QUOTES, 'UTF-8');
                      } else {
                          $img_comunidade = "https://ui-avatars.com/api/?name=" . urlencode($post['nome_comunidade']) . "&background=2b17e0&color=fff";
                      }
                    ?>
                    <article class="post post-card-feed" data-post-id="<?= $id_post ?>">
                      <div class="feed-item-badge" style="display:inline-block; margin-bottom:10px; padding:4px 8px; border-radius:999px; background:#e8e2ff; color:#3b2d85; font-size:11px; font-weight:700; letter-spacing:0.03em; text-transform:uppercase;">
                        Post fixado
                      </div>
                      <div class="post-header">
                        <div class="post-avatar">
                          <a href="user_view.php?id=<?= $profileUserId ?>" title="Ver perfil de <?= $profileName ?>">
                            <img src="<?= $profileAvatar ?>" alt="<?= $profileName ?>">
                          </a>
                        </div>
                        <div class="post-header-info">
                          <div class="post-user-info">
                            <h4>
                              <a href="user_view.php?id=<?= $profileUserId ?>" class="post-community-name">
                                <?= $profileName ?>
                              </a>
                            </h4>
                            <div class="post-user-handle">@<?= $profileHandle ?: 'usuario' ?></div>
                          </div>
                          <div class="post-date">
                            <?= $data_formatada ?>
                          </div>
                        </div>
                      </div>

                      <?php if (!empty($assunto)): ?>
                        <div class="post-title"><?= $assunto ?></div>
                      <?php endif; ?>

                      <div class="post-content"><?= $conteudo ?></div>

                      <div class="post-actions">
                        <button type="button" class="post-action btn-curtir-action <?= $curtiu ? 'curtido' : '' ?>" onclick="curtirPostRecente(<?= $id_post ?>, this)" title="<?= $curtiu ? 'Descurtir post' : 'Curtir post' ?>">
                          <span class="like-icon"><?= $curtiu ? '❤️' : '🤍' ?></span>
                          <span class="like-count"><?= $total_curtidas ?></span>
                        </button>
                        <?php if ((int) ($_SESSION['usuario']['id_usuario'] ?? 0) === $profileUserId): ?>
                          <button type="button" class="post-action post-pin-action" onclick="event.stopPropagation(); desfixarPostPerfil(<?= $id_post ?>, this)" title="Desfixar do perfil">
                            <span>📌</span>
                            <span>Desfixar</span>
                          </button>
                        <?php endif; ?>
                        <a href="post_detalhes.php?id_post=<?= $id_post ?>" class="post-action post-comment-action" title="Ver post completo">
                          <span class="comment-icon">💬</span>
                          <span class="comment-count"><?= $total_comentarios ?> <?= $total_comentarios === 1 ? 'comentário' : 'comentários' ?></span>
                        </a>
                      </div>
                    </article>
                  <?php endforeach; ?>

                  <?php foreach ($pinned_comments as $comment): ?>
                    <?php
                      $comment_id = intval($comment['id_comentario']);
                      $comment_post_id = intval($comment['id_post']);
                      $comment_avatar = resolve_asset_url($comment['foto_perfil'] ?? null, "https://ui-avatars.com/api/?name=" . urlencode($comment['nome_de_exibicao'] ?? 'Usuário') . "&background=random");
                      $comment_author_name = htmlspecialchars($comment['nome_de_exibicao'] ?? 'Usuário', ENT_QUOTES, 'UTF-8');
                      $comment_author_handle = htmlspecialchars($comment['nome_de_usuario'] ?? '', ENT_QUOTES, 'UTF-8');
                      $comment_assunto = htmlspecialchars($comment['assunto'] ?? '', ENT_QUOTES, 'UTF-8');
                      $comment_conteudo = htmlspecialchars($comment['conteudo'], ENT_QUOTES, 'UTF-8');
                      $comment_date = date('d/m/Y', strtotime($comment['data_comentario']));
                    ?>
                    <article class="post post-card-feed comment-entry" data-post-id="<?= $comment_post_id ?>" data-comment-id="<?= $comment_id ?>">
                      <div class="feed-item-badge" style="display:inline-block; margin-bottom:10px; padding:4px 8px; border-radius:999px; background:#e0f2fe; color:#0f4c81; font-size:11px; font-weight:700; letter-spacing:0.03em; text-transform:uppercase;">
                        Comentário fixado
                      </div>
                      <div class="post-header">
                        <div class="post-avatar">
                          <a href="user_view.php?id=<?= intval($comment['id_usuario']) ?>" title="Ver perfil de <?= $comment_author_name ?>">
                            <img src="<?= $comment_avatar ?>" alt="<?= $comment_author_name ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%;">
                          </a>
                        </div>
                        <div class="post-header-info">
                          <div class="post-user-info">
                            <h4><a href="user_view.php?id=<?= intval($comment['id_usuario']) ?>" style="text-decoration:none; color:inherit;"><?= $comment_author_name ?></a></h4>
                            <?php if ($comment_author_handle !== ''): ?><div class="post-user-handle">@<?= $comment_author_handle ?></div><?php endif; ?>
                            <?php if ($comment_assunto !== ''): ?><div class="post-user-handle">Em: <?= $comment_assunto ?></div><?php endif; ?>
                          </div>
                          <div class="post-date"><?= $comment_date ?></div>
                        </div>
                      </div>
                      <div class="post-content"><?= nl2br($comment_conteudo) ?></div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="posts-empty-feed">
                  <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 2v10m0 0l3-3m-3 3L9 9m3 13a9 9 0 1 1 0-18 9 9 0 0 1 0 18z"/>
                  </svg>
                  <p>Nenhum post fixado.</p>
                  <span>Este usuário ainda não fixou nenhum item no perfil.</span>
                </div>
              <?php endif; ?>
            </div>

            <!-- Aba 2: Últimos Posts -->
            <div class="feed-tab-content" id="user-feed-recentes" style="display: none;">
              <?php if (!empty($recent_posts) || !empty($recent_comments)): ?>
                <div class="posts-feed">
                  <?php foreach ($recent_posts as $post): ?>
                    <?php 
                      $id_post = intval($post['id_post']);
                      $id_comunidade = intval($post['id_comunidade']);
                      $nome_comunidade = htmlspecialchars($post['nome_comunidade'], ENT_QUOTES, 'UTF-8');
                      $data_post_ts = strtotime($post['Data_post']);
                      $data_formatada = $data_post_ts ? date('d/m/Y', $data_post_ts) : htmlspecialchars($post['Data_post'], ENT_QUOTES, 'UTF-8');
                      $assunto = !empty($post['assunto']) ? htmlspecialchars($post['assunto'], ENT_QUOTES, 'UTF-8') : '';
                      $conteudo = htmlspecialchars($post['conteudo'], ENT_QUOTES, 'UTF-8');
                      $total_curtidas = intval($post['total_curtidas']);
                      $total_comentarios = intval($post['total_comentarios']);
                      $curtiu = intval($post['curtiu']) === 1;

                      $raw_img = $post['imagem_comunidade'] ?? '';
                      if (!empty($raw_img)) {
                          $img_comunidade = (strpos($raw_img, 'http') === 0 || strpos($raw_img, '../') === 0) 
                              ? htmlspecialchars($raw_img, ENT_QUOTES, 'UTF-8') 
                              : '../' . htmlspecialchars($raw_img, ENT_QUOTES, 'UTF-8');
                      } else {
                          $img_comunidade = "https://ui-avatars.com/api/?name=" . urlencode($post['nome_comunidade']) . "&background=2b17e0&color=fff";
                      }
                    ?>
                    <article class="post post-card-feed" data-post-id="<?= $id_post ?>">
                      <div class="feed-item-badge" style="display:inline-block; margin-bottom:10px; padding:4px 8px; border-radius:999px; background:#e8e2ff; color:#3b2d85; font-size:11px; font-weight:700; letter-spacing:0.03em; text-transform:uppercase;">
                        Post
                      </div>
                      <div class="post-header">
                        <div class="post-avatar">
                          <a href="user_view.php?id=<?= $profileUserId ?>" title="Ver perfil de <?= $profileName ?>">
                            <img src="<?= $profileAvatar ?>" alt="<?= $profileName ?>">
                          </a>
                        </div>
                        <div class="post-header-info">
                          <div class="post-user-info">
                            <h4>
                              <a href="user_view.php?id=<?= $profileUserId ?>" class="post-community-name">
                                <?= $profileName ?>
                              </a>
                            </h4>
                            <div class="post-user-handle">@<?= $profileHandle ?: 'usuario' ?></div>
                          </div>
                          <div class="post-date">
                            <?= $data_formatada ?>
                          </div>
                        </div>
                      </div>

                      <?php if (!empty($assunto)): ?>
                        <div class="post-title"><?= $assunto ?></div>
                      <?php endif; ?>

                      <div class="post-content"><?= $conteudo ?></div>

                      <div class="post-actions">
                        <button type="button" class="post-action btn-curtir-action <?= $curtiu ? 'curtido' : '' ?>" onclick="curtirPostRecente(<?= $id_post ?>, this)" title="<?= $curtiu ? 'Descurtir post' : 'Curtir post' ?>">
                          <span class="like-icon"><?= $curtiu ? '❤️' : '🤍' ?></span>
                          <span class="like-count"><?= $total_curtidas ?></span>
                        </button>
                        <a href="post_detalhes.php?id_post=<?= $id_post ?>" class="post-action post-comment-action" title="Ver post completo">
                          <span class="comment-icon">💬</span>
                          <span class="comment-count"><?= $total_comentarios ?> <?= $total_comentarios === 1 ? 'comentário' : 'comentários' ?></span>
                        </a>
                      </div>
                    </article>
                  <?php endforeach; ?>

                  <?php foreach ($recent_comments as $comment): ?>
                    <?php
                      $comment_id = intval($comment['id_comentario']);
                      $comment_post_id = intval($comment['id_post']);
                      $comment_avatar = resolve_asset_url($comment['foto_perfil'] ?? null, generated_avatar_url($comment['nome_de_exibicao'] ?? 'Usuário'));
                      $comment_author_name = htmlspecialchars($comment['nome_de_exibicao'] ?? 'Usuário', ENT_QUOTES, 'UTF-8');
                      $comment_author_handle = htmlspecialchars($comment['nome_de_usuario'] ?? '', ENT_QUOTES, 'UTF-8');
                      $comment_assunto = htmlspecialchars($comment['assunto'] ?? '', ENT_QUOTES, 'UTF-8');
                      $comment_conteudo = htmlspecialchars($comment['conteudo'] ?? '', ENT_QUOTES, 'UTF-8');
                      $comment_date = date('d/m/Y', strtotime($comment['data_comentario']));
                    ?>
                    <article class="post post-card-feed comment-entry" data-post-id="<?= $comment_post_id ?>" data-comment-id="<?= $comment_id ?>">
                      <div class="feed-item-badge" style="display:inline-block; margin-bottom:10px; padding:4px 8px; border-radius:999px; background:#e0f2fe; color:#0f4c81; font-size:11px; font-weight:700; letter-spacing:0.03em; text-transform:uppercase;">
                        Comentário
                      </div>
                      <div class="post-header">
                        <div class="post-avatar">
                          <a href="user_view.php?id=<?= $profileUserId ?>" title="Ver perfil de <?= $profileName ?>">
                            <img src="<?= $comment_avatar ?>" alt="<?= $comment_author_name ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%;">
                          </a>
                        </div>
                        <div class="post-header-info">
                          <div class="post-user-info">
                            <h4><a href="user_view.php?id=<?= $profileUserId ?>" style="text-decoration:none; color:inherit;"><?= $comment_author_name ?></a></h4>
                            <?php if ($comment_author_handle !== ''): ?><div class="post-user-handle">@<?= $comment_author_handle ?></div><?php endif; ?>
                            <?php if ($comment_assunto !== ''): ?><div class="post-user-handle">Em: <?= $comment_assunto ?></div><?php endif; ?>
                          </div>
                          <div class="post-date"><?= $comment_date ?></div>
                        </div>
                      </div>
                      <div class="post-content"><?= nl2br($comment_conteudo) ?></div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="posts-empty-feed">
                  <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                  </svg>
                  <p>Nenhum post ou comentário publicado.</p>
                  <span>Este usuário ainda não publicou posts nem comentários.</span>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

      <aside class="rounded-panel communities-panel">
        <div class="communities-header">Comunidades</div>
        <?php if (!empty($communityList)): ?>
          <div class="community-list">
            <?php foreach ($communityList as $community): ?>
              <?php
                $communityName = safeText($community['nome'] ?? 'Comunidade');
                $communityImage = !empty($community['imagem'])
                  ? resolve_asset_url($community['imagem'], "https://ui-avatars.com/api/?name=" . urlencode($community['nome'] ?? 'Comunidade') . "&background=random")
                  : "https://ui-avatars.com/api/?name=" . urlencode($community['nome'] ?? 'Comunidade') . "&background=random";
              ?>
              <a href="comunidade.php?id=<?= intval($community['id_comunidade']) ?>" class="community-item" title="Entrar na comunidade <?= $communityName ?>">
                <img src="<?= $communityImage ?>" alt="<?= $communityName ?>" class="community-avatar-mini">
                <span><?= $communityName ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="community-text">Nenhuma comunidade encontrada.</div>
        <?php endif; ?>
      </aside>
    </main>

    <footer class="bottombar" style="background: #e2e2e2; padding: 14px 32px; display: flex; align-items: center; gap: 10px;">
      <strong style="color: #1c1c1c; font-size: 0.95rem;">Blumask</strong>
      <span aria-label="Direitos autorais" style="font-size: 1rem; font-weight: 700; color: #1c1c1c;">©</span>
    </footer>
  </div>

  <script src="../js/busca.js?v=<?= time() ?>"></script>
  <script>
    const csrfToken = "<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>";

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

    // Função assíncrona para curtir/descurtir posts
    async function curtirPostRecente(idPost, btnElement) {
        const idUsuarioLogado = parseInt(document.body.dataset.idUsuario, 10) || 0;
        if (idUsuarioLogado <= 0) {
        mostrarAvisoLoginCurtida();
            return;
        }

        if (btnElement.disabled) return;
        btnElement.disabled = true;

        const iconSpan = btnElement.querySelector('.like-icon');
        const countSpan = btnElement.querySelector('.like-count');

        try {
            const response = await fetch('curtir_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_post=${idPost}`
            });

            const data = await response.json();

            if (data.sucesso) {
                if (data.curtiu) {
                    if (iconSpan) iconSpan.textContent = '❤️';
                    if (countSpan) countSpan.textContent = parseInt(countSpan.textContent || '0', 10) + 1;
                    btnElement.classList.add('curtido');
                    btnElement.title = 'Descurtir post';
                } else {
                    if (iconSpan) iconSpan.textContent = '🤍';
                    if (countSpan) countSpan.textContent = Math.max(0, parseInt(countSpan.textContent || '0', 10) - 1);
                    btnElement.classList.remove('curtido');
                    btnElement.title = 'Curtir post';
                }
            } else {
                if (data.mensagem) alert(data.mensagem);
            }
        } catch (err) {
            console.error('Erro ao curtir post:', err);
        } finally {
            btnElement.disabled = false;
        }
    }

    async function desfixarPostPerfil(idPost, btnElement) {
      if (!btnElement || btnElement.disabled) return;
      btnElement.disabled = true;

      try {
        const response = await fetch('fixar_post.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: `csrf_token=${encodeURIComponent(csrfToken)}&id_post=${idPost}&destino=perfil`
        });
        const data = await response.json();
        if (data.sucesso) {
          window.location.reload();
          return;
        }
        alert(data.mensagem || 'Não foi possível desfixar este post.');
      } catch (error) {
        console.error('Erro ao desfixar post:', error);
        alert('Erro ao desfixar este post.');
      } finally {
        btnElement.disabled = false;
      }
    }

    document.querySelectorAll('.post-card-feed').forEach((post) => {
        post.addEventListener('click', (event) => {
            const isInteractive = event.target.closest('button, a, input, textarea, select, .post-action');
            if (isInteractive) {
                return;
            }

            const postId = post.dataset.postId;
            const commentId = post.dataset.commentId;
            if (postId && commentId) {
              window.location.href = `post_detalhes.php?id_post=${postId}#comment-${commentId}`;
            } else if (postId) {
              window.location.href = `post_detalhes.php?id_post=${postId}`;
            }
        });
    });

    // Alternância entre abas Fixados e Últimos Posts
    document.querySelectorAll(".feed-tab-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
            const targetId = btn.getAttribute("data-target");
            document.querySelectorAll(".feed-tab-btn").forEach(b => b.classList.remove("active"));
            btn.classList.add("active");

            document.querySelectorAll(".feed-tab-content").forEach((content) => {
                if (content.id === targetId) {
                    content.classList.add("active");
                    content.style.display = "block";
                } else {
                    content.classList.remove("active");
                    content.style.display = "none";
                }
            });
        });
    });
  </script>
</body>
</html>
