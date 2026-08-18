<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/bd.php";

$usuarios = [];
$result = $conn->query("SELECT id_usuario, nome_de_exibicao, nome_de_usuario, descricao, banner, foto_perfil, id_post_fixado FROM usuario ORDER BY nome_de_exibicao ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $usuarios[] = $row;
    }
}

$selectedUser = null;
if (!empty($usuarios)) {
    $requestedId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($requestedId > 0) {
        foreach ($usuarios as $user) {
            if (intval($user['id_usuario']) === $requestedId) {
                $selectedUser = $user;
                break;
            }
        }
    }

    if (!$selectedUser) {
        $selectedUser = $usuarios[0];
    }
}

$loggedUser = $_SESSION['usuario'] ?? null;
$profileUser = $selectedUser ?: $loggedUser;
$fixedPost = null;
$posts = [];
$communityList = [];

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

        $fixedPostId = intval($profileUser['id_post_fixado'] ?? 0);
        if ($fixedPostId > 0) {
            $fixedPostResult = $conn->query("SELECT * FROM post WHERE id_post = $fixedPostId AND id_usuario = $profileUserId LIMIT 1");
            if ($fixedPostResult && $fixedPostResult->num_rows > 0) {
                $fixedPost = $fixedPostResult->fetch_assoc();
            }
        }

        if (!$fixedPost) {
            $latestPostResult = $conn->query("SELECT * FROM post WHERE id_usuario = $profileUserId ORDER BY Data_post DESC, id_post DESC LIMIT 1");
            if ($latestPostResult && $latestPostResult->num_rows > 0) {
                $fixedPost = $latestPostResult->fetch_assoc();
            }
        }

        $postsQuery = "SELECT * FROM post WHERE id_usuario = $profileUserId ORDER BY Data_post DESC, id_post DESC";
        if ($fixedPost) {
            $postsQuery .= " LIMIT 10";
        }
        $postsResult = $conn->query($postsQuery);
        if ($postsResult) {
            while ($postRow = $postsResult->fetch_assoc()) {
                if ($fixedPost && intval($postRow['id_post']) === intval($fixedPost['id_post'])) {
                    continue;
                }
                $posts[] = $postRow;
            }
        }
    }
}

function safeText($value) {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function userAvatar($user) {
    if (!empty($user['foto_perfil'])) {
        return htmlspecialchars($user['foto_perfil'], ENT_QUOTES, 'UTF-8');
    }

    $nome = $user['nome_de_exibicao'] ?? $user['nome_de_usuario'] ?? 'User';
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
  <link rel="stylesheet" href="../style/index_style.css">
  <link rel="stylesheet" href="../style/busca_style.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../style/user_view_style.css">
</head>
<body data-id-usuario="<?= isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0 ?>">
  <div class="page">
    <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px;">
      <div style="display: flex; align-items: center; gap: 12px; cursor: pointer;" onclick="window.location.href='../index.php'">
        <img src="../style/blumaskBlueLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
        <h1 style="margin: 0;">BluMask</h1>
      </div>
      <div class="topbar-actions" style="display: flex; align-items: center; gap: 12px;">
        <?php if ($loggedUser): ?>
          <?php
            $headerAvatar = !empty($loggedUser['foto_perfil'])
              ? htmlspecialchars($loggedUser['foto_perfil'], ENT_QUOTES, 'UTF-8')
              : "https://ui-avatars.com/api/?name=" . urlencode(($loggedUser['nome_de_exibicao'] ?? $loggedUser['nome_de_usuario'] ?? 'User')) . "&background=random";
          ?>
          <button class="profile-avatar-button" type="button" onclick="window.location.href='../index.php'" title="Voltar para o início" aria-label="Voltar para o início">
            <img src="<?= $headerAvatar ?>" alt="Foto do perfil">
          </button>
          <a href="../index.php?logout=1" style="text-decoration: none; color: #ff4d4d; font-weight: bold; font-size: 14px;">Sair</a>
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
            $profileAvatar = !empty($profileUser['foto_perfil']) ? htmlspecialchars($profileUser['foto_perfil'], ENT_QUOTES, 'UTF-8') : "https://ui-avatars.com/api/?name=" . urlencode(($profileUser['nome_de_exibicao'] ?? $profileUser['nome_de_usuario'] ?? 'User')) . "&background=random";
          ?>
          <div class="profile-header">
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

        <div class="feed">
          <div class="feed-header">Último Post</div>
          <div class="feed-list">
            <?php if ($fixedPost): ?>
              <?php
                $fixedTitle = safeText($fixedPost['assunto'] ?? 'Sem assunto');
                $fixedBody = safeText($fixedPost['conteudo'] ?? '');
                $fixedDate = !empty($fixedPost['Data_post']) ? date('d/m/Y', strtotime($fixedPost['Data_post'])) : '';
                $fixedAuthor = safeText($profileUser['nome_de_exibicao'] ?? $profileUser['nome_de_usuario'] ?? 'Usuário');
                $fixedAvatar = userAvatar($profileUser);
              ?>
              <article class="post-card">
                <div class="post-top">
                  <img class="mini-avatar" src="<?= $fixedAvatar ?>" alt="<?= $fixedAuthor ?>">
                  <div class="post-user"><?= $fixedAuthor ?></div>
                  <div class="post-date"><?= $fixedDate ?></div>
                </div>
                <h3 class="post-title"><?= $fixedTitle ?></h3>
                <p class="post-body"><?= $fixedBody ?: 'Nenhum conteúdo disponível neste post.' ?></p>
              </article>
            <?php else: ?>
              <article class="post-card">
                <div class="post-top">
                  <div class="post-user"><?= safeText($profileUser['nome_de_exibicao'] ?? 'Usuário') ?></div>
                </div>
                <p class="post-body">Este usuário ainda não publicou nenhum post.</p>
              </article>
            <?php endif; ?>

            <?php if (!empty($posts)): ?>
              <?php foreach ($posts as $post): ?>
                <?php
                  $postTitle = safeText($post['assunto'] ?? 'Sem assunto');
                  $postBody = safeText($post['conteudo'] ?? '');
                  $postDate = !empty($post['Data_post']) ? date('d/m/Y', strtotime($post['Data_post'])) : '';
                ?>
                <article class="post-card">
                  <div class="post-top">
                    <img class="mini-avatar" src="<?= $fixedAvatar ?? userAvatar($profileUser) ?>" alt="<?= safeText($profileUser['nome_de_exibicao'] ?? 'Usuário') ?>">
                    <div class="post-user"><?= safeText($profileUser['nome_de_exibicao'] ?? $profileUser['nome_de_usuario'] ?? 'Usuário') ?></div>
                    <div class="post-date"><?= $postDate ?></div>
                  </div>
                  <h3 class="post-title"><?= $postTitle ?></h3>
                  <p class="post-body"><?= $postBody ?: 'Sem conteúdo neste post.' ?></p>
                </article>
              <?php endforeach; ?>
            <?php elseif ($fixedPost): ?>
              <article class="post-card">
                <p class="post-body">Nenhum outro post deste usuário foi encontrado.</p>
              </article>
            <?php endif; ?>
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
                $communityImage = !empty($community['imagem']) ? htmlspecialchars($community['imagem'], ENT_QUOTES, 'UTF-8') : "https://ui-avatars.com/api/?name=" . urlencode($community['nome'] ?? 'Comunidade') . "&background=random";
              ?>
              <div class="community-item">
                <img src="<?= $communityImage ?>" alt="<?= $communityName ?>" class="community-avatar-mini">
                <span><?= $communityName ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="community-text">Nenhuma comunidade encontrada.</div>
        <?php endif; ?>
      </aside>
    </main>

    <footer class="bottombar" style="background: #e2e2e2; padding: 14px 32px; display: flex; align-items: center; gap: 10px;">
      <strong style="color: #1c1c1c; font-size: 0.95rem;">Blumask</strong>
      <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width: 18px; height: 18px;"><path d="M12 2a10 10 0 100 20 10 10 0 000-20zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92A3.98 3.98 0 0013 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26A1.95 1.95 0 0014 8.5c0-1.1-.9-2-2-2s-2 .9-2 2H8a4 4 0 118.5-3.5c1.74 0 3.3.89 4.18 2.25z"/></svg>
    </footer>
  </div>

  <script src="../js/busca.js"></script>
</body>
</html>
