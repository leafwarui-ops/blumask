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

        $id_usuario_logado = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
        $pinned_posts = [];
        $recent_posts = [];

        // 1. Post fixado do usuário do perfil
        $fixedPostId = intval($profileUser['id_post_fixado'] ?? 0);
        if ($fixedPostId > 0) {
            $sql_pinned = "SELECT 
                p.id_post,
                p.id_comunidade,
                p.Data_post,
                p.conteudo,
                p.assunto,
                c.nome AS nome_comunidade,
                c.imagem AS imagem_comunidade,
                (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) AS total_curtidas,
                (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) AS total_comentarios"
                . ($id_usuario_logado > 0 ? ", (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario_logado) AS curtiu" : ", 0 AS curtiu") . "
            FROM post p
            INNER JOIN comunidade c ON p.id_comunidade = c.id_comunidade
            WHERE p.id_post = $fixedPostId AND p.id_usuario = $profileUserId
            LIMIT 1";
            
            $res_pinned = mysqli_query($conn, $sql_pinned);
            if ($res_pinned) {
                while ($pin_row = mysqli_fetch_assoc($res_pinned)) {
                    $pinned_posts[] = $pin_row;
                }
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
            (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) AS total_curtidas,
            (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) AS total_comentarios"
            . ($id_usuario_logado > 0 ? ", (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario_logado) AS curtiu" : ", 0 AS curtiu") . "
        FROM post p
        INNER JOIN comunidade c ON p.id_comunidade = c.id_comunidade
        WHERE p.id_usuario = $profileUserId
        ORDER BY p.Data_post DESC, p.id_post DESC
        LIMIT 30";

        $res_recent = mysqli_query($conn, $sql_recent);
        if ($res_recent) {
            while ($rec_row = mysqli_fetch_assoc($res_recent)) {
                $recent_posts[] = $rec_row;
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
  <link rel="stylesheet" href="../style/index_style.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../style/comunidade_style.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../style/busca_style.css?v=<?= time() ?>">
  <link rel="stylesheet" href="../style/user_view_style.css?v=<?= time() ?>">
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
              <?php if (!empty($pinned_posts)): ?>
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
                      <div class="post-header">
                        <div class="post-avatar">
                          <a href="comunidade.php?id=<?= $id_comunidade ?>" title="Ver comunidade <?= $nome_comunidade ?>">
                            <img src="<?= $img_comunidade ?>" alt="<?= $nome_comunidade ?>">
                          </a>
                        </div>
                        <div class="post-header-info">
                          <div class="post-user-info">
                            <h4>
                              <a href="comunidade.php?id=<?= $id_comunidade ?>" class="post-community-name">
                                <?= $nome_comunidade ?>
                              </a>
                            </h4>
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
                        <a href="comunidade.php?id=<?= $id_comunidade ?>" class="post-action post-comment-action" title="Ver comentários na comunidade">
                          <span class="comment-icon">💬</span>
                          <span class="comment-count"><?= $total_comentarios ?> <?= $total_comentarios === 1 ? 'comentário' : 'comentários' ?></span>
                        </a>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="posts-empty-feed">
                  <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M12 2v10m0 0l3-3m-3 3L9 9m3 13a9 9 0 1 1 0-18 9 9 0 0 1 0 18z"/>
                  </svg>
                  <p>Nenhum post fixado.</p>
                  <span>Este usuário ainda não fixou nenhum post no perfil.</span>
                </div>
              <?php endif; ?>
            </div>

            <!-- Aba 2: Últimos Posts -->
            <div class="feed-tab-content" id="user-feed-recentes" style="display: none;">
              <?php if (!empty($recent_posts)): ?>
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
                      <div class="post-header">
                        <div class="post-avatar">
                          <a href="comunidade.php?id=<?= $id_comunidade ?>" title="Ver comunidade <?= $nome_comunidade ?>">
                            <img src="<?= $img_comunidade ?>" alt="<?= $nome_comunidade ?>">
                          </a>
                        </div>
                        <div class="post-header-info">
                          <div class="post-user-info">
                            <h4>
                              <a href="comunidade.php?id=<?= $id_comunidade ?>" class="post-community-name">
                                <?= $nome_comunidade ?>
                              </a>
                            </h4>
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
                        <a href="comunidade.php?id=<?= $id_comunidade ?>" class="post-action post-comment-action" title="Ver comentários na comunidade">
                          <span class="comment-icon">💬</span>
                          <span class="comment-count"><?= $total_comentarios ?> <?= $total_comentarios === 1 ? 'comentário' : 'comentários' ?></span>
                        </a>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="posts-empty-feed">
                  <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                  </svg>
                  <p>Nenhum post publicado.</p>
                  <span>Este usuário ainda não publicou nenhum post.</span>
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

  <script src="../js/busca.js?v=<?= time() ?>"></script>
  <script>
    const csrfToken = "<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>";

    // Função assíncrona para curtir/descurtir posts
    async function curtirPostRecente(idPost, btnElement) {
        const idUsuarioLogado = parseInt(document.body.dataset.idUsuario, 10) || 0;
        if (idUsuarioLogado <= 0) {
            alert("Você precisa estar logado para curtir posts.");
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
