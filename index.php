<?php
require_once "php/security_headers.php";
require_once "php/rate_limit.php";
include "php/bd.php";

// Lógica de Sair (Logout)
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Processamento dos formulários de Login e Cadastro (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $popup_mode = trim($_POST['popup-mode'] ?? '0');
    
    // Validação de CSRF Token para formulários de autenticação
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $login_error = "Token de segurança (CSRF) inválido. Por favor, recarregue a página e tente novamente.";
    } elseif ($popup_mode == "0") {
        // Entrar com Rate Limiting (Máx 5 erros = bloqueio de 15 min / 900s)
        if (!check_rate_limit('login_attempt', 5, 900)) {
            $waitTime = get_rate_limit_wait_time('login_attempt', 900);
            $login_error = "Muitas tentativas incorretas. Por favor, aguarde $waitTime para tentar novamente.";
        } else {
            $email = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
            $senha = $_POST['senha'] ?? '';
            
            if (empty($email) || empty($senha)) {
                hit_rate_limit('login_attempt');
                $login_error = "Email e senha são obrigatórios!";
            } else {
                $sql = "SELECT * FROM usuario WHERE email = '$email' LIMIT 1";
                $result = mysqli_query($conn, $sql);
                
                if ($result && mysqli_num_rows($result) > 0) {
                    $user = mysqli_fetch_assoc($result);
                    if (password_verify($senha, $user['senha'])) {
                        reset_rate_limit('login_attempt');
                        session_regenerate_id(true);
                        $_SESSION['usuario'] = $user;
                        header("Location: index.php");
                        exit;
                    } else {
                        hit_rate_limit('login_attempt');
                        $login_error = "Email ou senha incorretos!";
                    }
                } else {
                    hit_rate_limit('login_attempt');
                    $login_error = "Email ou senha incorretos!";
                }
            }
        }
    } else {
        // Cadastrar com Rate Limiting (Máx 3 cadastros = bloqueio de 15 min / 900s)
        if (!check_rate_limit('register_attempt', 3, 900)) {
            $waitTime = get_rate_limit_wait_time('register_attempt', 900);
            $login_error = "Você realizou muitos cadastros recentemente. Aguarde $waitTime para tentar cadastrar novamente.";
        } else {
            $nome_usr_raw = trim($_POST['nome_usr'] ?? '');
            $nome_exb_raw = trim($_POST['nome_exb'] ?? '');
            $email_raw    = trim($_POST['email'] ?? '');
            $senha        = $_POST['senha'] ?? '';

            if (mb_strlen($nome_usr_raw) < 4 || mb_strlen($nome_usr_raw) > 20) {
                $login_error = "O nome de usuário deve ter entre 4 e 20 caracteres.";
            } elseif (mb_strlen($nome_exb_raw) < 2 || mb_strlen($nome_exb_raw) > 10) {
                $login_error = "O nome de exibição deve ter entre 2 e 10 caracteres.";
            } elseif (!filter_var($email_raw, FILTER_VALIDATE_EMAIL)) {
                $login_error = "Formato de e-mail inválido.";
            } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[\W_]).{8,32}$/', $senha)) {
                $login_error = "A senha deve ter entre 8 e 32 caracteres, uma maiúscula e um símbolo.";
            } else {
                $nome_usr = mysqli_real_escape_string($conn, $nome_usr_raw);
                $nome_exb = mysqli_real_escape_string($conn, $nome_exb_raw);
                $email    = mysqli_real_escape_string($conn, $email_raw);

                // Verificação prévia de duplicidade amigável
                $check_sql = "SELECT email, nome_de_usuario, nome_de_exibicao FROM usuario WHERE email = '$email' OR nome_de_usuario = '$nome_usr' OR nome_de_exibicao = '$nome_exb' LIMIT 1";
                $check_res = mysqli_query($conn, $check_sql);

                if ($check_res && mysqli_num_rows($check_res) > 0) {
                    hit_rate_limit('register_attempt');
                    $row_dup = mysqli_fetch_assoc($check_res);
                    if (strcasecmp($row_dup['email'] ?? '', $email_raw) === 0) {
                        $login_error = "Este e-mail já está cadastrado.";
                    } elseif (strcasecmp($row_dup['nome_de_usuario'] ?? '', $nome_usr_raw) === 0) {
                        $login_error = "Este nome de usuário já está em uso.";
                    } else {
                        $login_error = "Este nome de exibição já está em uso.";
                    }
                } else {
                    try {
                        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
                        $sql = "INSERT INTO usuario (email, senha, nome_de_exibicao, nome_de_usuario) VALUES ('$email', '$senha_hash', '$nome_exb', '$nome_usr')";
                        
                        if ($conn->query($sql) === TRUE) {
                            hit_rate_limit('register_attempt');
                            // Auto login depois de cadastrar
                            $new_user_id = $conn->insert_id;
                            $sql_fetch = "SELECT * FROM usuario WHERE id_usuario = $new_user_id";
                            $result = mysqli_query($conn, $sql_fetch);
                            session_regenerate_id(true);
                            $_SESSION['usuario'] = mysqli_fetch_assoc($result);
                            header("Location: index.php");
                            exit;
                        } else {
                            hit_rate_limit('register_attempt');
                            $login_error = "Erro ao cadastrar. Por favor, tente novamente.";
                        }
                    } catch (\Throwable $e) {
                        hit_rate_limit('register_attempt');
                        $login_error = "Não foi possível concluir o cadastro. Verifique os dados informados.";
                    }
                }
            }
        }
    }
}

// -------------------------------------------------------------
// Consulta de Posts Recentes para o Feed
// -------------------------------------------------------------
$id_usuario_logado = isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0;
$usuario_post_fixado = null;
if ($id_usuario_logado > 0) {
    $res_usuario_fixado = mysqli_query($conn, "SELECT id_post_fixado FROM usuario WHERE id_usuario = $id_usuario_logado LIMIT 1");
    if ($res_usuario_fixado && mysqli_num_rows($res_usuario_fixado) > 0) {
        $usuario_fixado_row = mysqli_fetch_assoc($res_usuario_fixado);
        $usuario_post_fixado = intval($usuario_fixado_row['id_post_fixado'] ?? 0);
    }
}

$sql_recent_posts = "SELECT 
    p.id_post,
    p.id_comunidade,
    p.id_usuario AS autor_id,
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
ORDER BY p.Data_post DESC, p.id_post DESC
LIMIT 30";

$res_recent_posts = mysqli_query($conn, $sql_recent_posts);
$recent_posts = [];
if ($res_recent_posts) {
    while ($post_row = mysqli_fetch_assoc($res_recent_posts)) {
        $recent_posts[] = $post_row;
    }
}

// 2. Post Fixado pelo Próprio Usuário
$pinned_posts = [];
if ($id_usuario_logado > 0) {
    $sql_pinned_posts = "SELECT 
        p.id_post,
        p.id_comunidade,
        p.id_usuario AS autor_id,
        p.Data_post,
        p.conteudo,
        p.assunto,
        c.nome AS nome_comunidade,
        c.imagem AS imagem_comunidade,
        (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post) AS total_curtidas,
        (SELECT COUNT(*) FROM comentario WHERE id_post = p.id_post) AS total_comentarios,
        (SELECT COUNT(*) FROM curtida WHERE id_post = p.id_post AND id_usuario = $id_usuario_logado) AS curtiu
    FROM usuario u
    INNER JOIN post p ON p.id_post = u.id_post_fixado
    INNER JOIN comunidade c ON p.id_comunidade = c.id_comunidade
    WHERE u.id_usuario = $id_usuario_logado AND u.id_post_fixado IS NOT NULL
    LIMIT 1";

    $res_pinned_posts = mysqli_query($conn, $sql_pinned_posts);
    if ($res_pinned_posts) {
        while ($pin_row = mysqli_fetch_assoc($res_pinned_posts)) {
            $pinned_posts[] = $pin_row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BluMask</title>
    <link rel="icon" type="image/webp" href="style/blumaskWhiteLogo.webp">
    <link rel="stylesheet" href="style/index_style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="style/comunidade_style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="style/busca_style.css?v=<?= time() ?>">
</head>
<body data-id-usuario="<?= isset($_SESSION['usuario']) ? intval($_SESSION['usuario']['id_usuario']) : 0 ?>">

    <div class="page">

  <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px;">
    <div style="display: flex; align-items: center; gap: 12px;">
      <img src="style/blumaskBlueLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
      <h1 style="margin: 0;">BluMask</h1>
    </div>
    <?php if (isset($_SESSION['usuario'])): ?>
        <a href="?logout=1" style="text-decoration: none; color: #ff4d4d; font-weight: bold; font-size: 14px;">Sair</a>
    <?php endif; ?>
  </header>

  <main class="layout">

    <!-- LEFT PANEL: PROFILE -->
    <section class="panel profile-panel">
      <?php if (isset($_SESSION['usuario'])): ?>
      <?php 
          $user = $_SESSION['usuario'];
          $nome_exibicao = htmlspecialchars($user['nome_de_exibicao'], ENT_QUOTES, 'UTF-8');
          $nome_usuario  = htmlspecialchars($user['nome_de_usuario'], ENT_QUOTES, 'UTF-8');
          $descricao_usr = htmlspecialchars($user['descricao'] ?? '', ENT_QUOTES, 'UTF-8');
          $bannerUrl     = !empty($user['banner']) ? htmlspecialchars($user['banner'], ENT_QUOTES, 'UTF-8') : '';
          
          // Utiliza a foto de perfil salva no banco; caso não exista, gera um avatar dinâmico com as iniciais
          $avatarUrl = !empty($user['foto_perfil']) ? htmlspecialchars($user['foto_perfil'], ENT_QUOTES, 'UTF-8') : "https://ui-avatars.com/api/?name=" . urlencode($nome_exibicao) . "&background=random";
          $bannerStyle = !empty($bannerUrl) ? "background-image: url('$bannerUrl'); background-size: cover; background-position: center;" : "";
      ?>
      <div class="panel-header" style="height: 100px; position: relative; <?= $bannerStyle ?>">
        <div class="avatar" style="position: absolute; bottom: -35px; left: 50%; transform: translateX(-50%); width: 70px; height: 70px; border-radius: 50%; border: 4px solid #fff; overflow: hidden; background: #333;">
          <img src="<?= $avatarUrl ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;">
        </div>
      </div>
      <div class="profile-body" style="padding-top: 45px; text-align: center;">
        <h3 style="margin-bottom: 2px;"><?= $nome_exibicao ?></h3>
        <p style="font-size: 13px; color: #666; margin-bottom: 10px;">@<?= $nome_usuario ?></p>
        <?php if (!empty($descricao_usr)): ?>
          <p style="font-size: 12px; color: #444; margin-bottom: 15px; font-style: italic; font-weight: 500; word-break: break-word;"><?= $descricao_usr ?></p>
        <?php endif; ?>
        <button onclick="window.location.href='php/usr_edit.php'" class="btn-entrar" id="btn-editar-perfil" style="display: block; width: 100%; cursor: pointer;">Editar</button>
      </div>
      <?php else: ?>
      <div class="panel-header">
        <div class="avatar">
          <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>
        </div>
      </div>
      <div class="profile-body">
        <!-- Mensagem para usuários não autenticados -->
        <p>Ops! Você precisa fazer login para visualizar e customizar o seu perfil!</p>
        <button class="btn-entrar" id="btn-entrar">Entrar</button>
        
        <!-- Modal de Autenticação (Login/Cadastro) -->
        <dialog id="login-box"> 
            <form id="popup-form" action="" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="dialog-tabs">
                    <button type="button" id="btn-entrar-dialog">entrar</button>
                    <button type="button" id="btn-cadastrar-dialog">cadastrar</button>
                </div>
                <div id="pop-div">
                </div>
            </form>
        </dialog>
      </div>
      <?php endif; ?>
    </section>

    <!-- CENTER COLUMN -->
    <section class="center-col">
      <!-- Interactive Search Container -->
      <div class="search-container">
        <div class="search-bar-interactive">
          <svg class="search-icon-svg" viewBox="0 0 24 24" fill="none" stroke-width="2.5">
            <circle cx="11" cy="11" r="7"/>
            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="input-busca" class="search-input" placeholder="Procurando por Algo? (usuários, comunidades...)" autocomplete="off">
          <div class="search-actions">
            <div class="search-spinner" id="busca-spinner" title="Buscando..."></div>
            <button type="button" class="btn-clear-search" id="btn-limpar-busca" title="Limpar busca">&times;</button>
          </div>
        </div>

        <!-- Filtros Rápidos -->
        <div class="search-filter-tabs">
          <button type="button" class="filter-tab-btn active" data-tipo="todos">Todos</button>
          <button type="button" class="filter-tab-btn" data-tipo="usuarios">Usuários</button>
          <button type="button" class="filter-tab-btn" data-tipo="comunidades">Comunidades</button>
        </div>

        <!-- Dropdown de Resultados Dinâmicos -->
        <div class="search-results-dropdown" id="busca-resultados-dropdown"></div>
      </div>

      <div class="panel last-post">
        <div class="panel-header feed-panel-header">
          <div class="feed-tabs">
            <button type="button" class="feed-tab-btn active" data-target="feed-fixados">
              Fixados
            </button>
            <button type="button" class="feed-tab-btn" data-target="feed-recentes">
              Últimos Posts
            </button>
          </div>
        </div>
        <div class="last-post-body">
          <!-- Aba 1: Fixados (Padrão) -->
          <div class="feed-tab-content active" id="feed-fixados">
            <?php if (!empty($pinned_posts)): ?>
              <div class="posts-feed">
                <?php 
                  $authorsCache = [];
                  foreach ($pinned_posts as $post): ?>
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

                    $img_comunidade = !empty($post['imagem_comunidade'])
                      ? htmlspecialchars($post['imagem_comunidade'], ENT_QUOTES, 'UTF-8')
                      : "https://ui-avatars.com/api/?name=" . urlencode($post['nome_comunidade']) . "&background=2b17e0&color=fff";

                    // Obter informações do autor (avatar e nome) — cache simples
                    $autor_id = intval($post['autor_id'] ?? 0);
                    $authorName = $nome_comunidade;
                    $authorAvatar = $img_comunidade;
                    if ($autor_id > 0) {
                        if (!isset($authorsCache[$autor_id])) {
                            $resA = mysqli_query($conn, "SELECT nome_de_exibicao, nome_de_usuario, foto_perfil FROM usuario WHERE id_usuario = $autor_id LIMIT 1");
                            $authorsCache[$autor_id] = ($resA && mysqli_num_rows($resA) > 0) ? mysqli_fetch_assoc($resA) : null;
                        }
                        if (!empty($authorsCache[$autor_id])) {
                            $authorName = htmlspecialchars($authorsCache[$autor_id]['nome_de_exibicao'] ?? $authorsCache[$autor_id]['nome_de_usuario'] ?? 'Usuário', ENT_QUOTES, 'UTF-8');
                            $authorAvatar = !empty($authorsCache[$autor_id]['foto_perfil']) ? htmlspecialchars($authorsCache[$autor_id]['foto_perfil'], ENT_QUOTES, 'UTF-8') : "https://ui-avatars.com/api/?name=" . urlencode($authorName) . "&background=random";
                        }
                    }
                  ?>
                  <article class="post post-card-feed" data-post-id="<?= $id_post ?>" data-community-id="<?= $id_comunidade ?>">
                    <div class="post-header">
                      <div class="post-avatar">
                        <a href="php/user_view.php?id=<?= $autor_id ?>" title="Ver perfil de <?= $authorName ?>" onclick="event.stopPropagation();">
                          <img src="<?= $authorAvatar ?>" alt="<?= $authorName ?>">
                        </a>
                      </div>
                      <div class="post-header-info">
                        <div class="post-user-info">
                          <h4>
                            <a href="php/user_view.php?id=<?= $autor_id ?>" class="post-community-name" onclick="event.stopPropagation();">
                              <?= $authorName ?>
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
                      <button type="button" class="post-action btn-curtir-action <?= $curtiu ? 'curtido' : '' ?>" onclick="event.stopPropagation(); curtirPostRecente(<?= $id_post ?>, this)" title="<?= $curtiu ? 'Descurtir post' : 'Curtir post' ?>">
                        <span class="like-icon"><?= $curtiu ? '❤️' : '🤍' ?></span>
                        <span class="like-count"><?= $total_curtidas ?></span>
                      </button>

                      <?php if ($id_usuario_logado > 0 && intval($post['autor_id'] ?? 0) === $id_usuario_logado): ?>
                        <button type="button" class="post-action post-pin-action" onclick="event.stopPropagation(); fixarPostPerfil(<?= $id_post ?>, this)" title="Desfixar do perfil">
                          <span>📌</span>
                          <span>Desfixar</span>
                        </button>
                      <?php endif; ?>

                      <a href="php/comunidade.php?id=<?= $id_comunidade ?>" class="post-action post-comment-action" title="Ver comentários na comunidade" onclick="event.stopPropagation();">
                        <span class="comment-icon">💬</span>
                        <span class="comment-count"><?= $total_comentarios ?> <?= $total_comentarios === 1 ? 'comentário' : 'comentários' ?></span>
                      </a>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php elseif ($id_usuario_logado > 0): ?>
              <div class="posts-empty-feed">
                <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8">
                  <path d="M12 2v10m0 0l3-3m-3 3L9 9m3 13a9 9 0 1 1 0-18 9 9 0 0 1 0 18z"/>
                </svg>
                <p>Você ainda não fixou nenhum post.</p>
                <span>Fixe uma publicação no seu perfil para que ela apareça em destaque aqui!</span>
              </div>
            <?php else: ?>
              <div class="posts-empty-feed">
                <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8">
                  <path d="M12 2v10m0 0l3-3m-3 3L9 9m3 13a9 9 0 1 1 0-18 9 9 0 0 1 0 18z"/>
                </svg>
                <p>Nenhum post fixado.</p>
                <span>Faça login para visualizar e fixar seus posts aqui!</span>
              </div>
            <?php endif; ?>
          </div>

          <!-- Aba 2: Últimos Posts -->
          <div class="feed-tab-content" id="feed-recentes" style="display: none;">
            <?php if (!empty($recent_posts)): ?>
              <div class="posts-feed">
                <?php 
                  // Reutiliza o cache de autores
                  foreach ($recent_posts as $post): ?>
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

                    $img_comunidade = !empty($post['imagem_comunidade'])
                      ? htmlspecialchars($post['imagem_comunidade'], ENT_QUOTES, 'UTF-8')
                      : "https://ui-avatars.com/api/?name=" . urlencode($post['nome_comunidade']) . "&background=2b17e0&color=fff";

                    // Obter informações do autor (avatar e nome) — usa $authorsCache
                    $autor_id = intval($post['autor_id'] ?? 0);
                    $authorName = $nome_comunidade;
                    $authorAvatar = $img_comunidade;
                    if ($autor_id > 0) {
                        if (!isset($authorsCache[$autor_id])) {
                            $resA = mysqli_query($conn, "SELECT nome_de_exibicao, nome_de_usuario, foto_perfil FROM usuario WHERE id_usuario = $autor_id LIMIT 1");
                            $authorsCache[$autor_id] = ($resA && mysqli_num_rows($resA) > 0) ? mysqli_fetch_assoc($resA) : null;
                        }
                        if (!empty($authorsCache[$autor_id])) {
                            $authorName = htmlspecialchars($authorsCache[$autor_id]['nome_de_exibicao'] ?? $authorsCache[$autor_id]['nome_de_usuario'] ?? 'Usuário', ENT_QUOTES, 'UTF-8');
                            $authorAvatar = !empty($authorsCache[$autor_id]['foto_perfil']) ? htmlspecialchars($authorsCache[$autor_id]['foto_perfil'], ENT_QUOTES, 'UTF-8') : "https://ui-avatars.com/api/?name=" . urlencode($authorName) . "&background=random";
                        }
                    }
                  ?>
                  <article class="post post-card-feed" data-post-id="<?= $id_post ?>" data-community-id="<?= $id_comunidade ?>">
                    <div class="post-header">
                      <div class="post-avatar">
                        <a href="php/user_view.php?id=<?= $autor_id ?>" title="Ver perfil de <?= $authorName ?>" onclick="event.stopPropagation();">
                          <img src="<?= $authorAvatar ?>" alt="<?= $authorName ?>">
                        </a>
                      </div>
                      <div class="post-header-info">
                        <div class="post-user-info">
                          <h4>
                            <a href="php/user_view.php?id=<?= $autor_id ?>" class="post-community-name" onclick="event.stopPropagation();">
                              <?= $authorName ?>
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
                      <button type="button" class="post-action btn-curtir-action <?= $curtiu ? 'curtido' : '' ?>" onclick="event.stopPropagation(); curtirPostRecente(<?= $id_post ?>, this)" title="<?= $curtiu ? 'Descurtir post' : 'Curtir post' ?>">
                        <span class="like-icon"><?= $curtiu ? '❤️' : '🤍' ?></span>
                        <span class="like-count"><?= $total_curtidas ?></span>
                      </button>

                      <?php if ($id_usuario_logado > 0 && intval($post['autor_id'] ?? 0) === $id_usuario_logado): ?>
                        <button type="button" class="post-action post-pin-action" onclick="event.stopPropagation(); fixarPostPerfil(<?= $id_post ?>, this)" title="<?= intval($usuario_post_fixado) === $id_post ? 'Desfixar do perfil' : 'Fixar no perfil' ?>">
                          <span><?= intval($usuario_post_fixado) === $id_post ? '📌' : '📍' ?></span>
                          <span><?= intval($usuario_post_fixado) === $id_post ? 'Desfixar' : 'Fixar' ?></span>
                        </button>
                      <?php endif; ?>

                      <a href="php/comunidade.php?id=<?= $id_comunidade ?>" class="post-action post-comment-action" title="Ver comentários na comunidade" onclick="event.stopPropagation();">
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
                <p>Nenhum post recente ainda.</p>
                <span>Explore as comunidades ao lado e seja o primeiro a publicar!</span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </section>

    <!-- RIGHT PANEL: COMMUNITIES -->
    <section class="panel communities-panel">
      <div class="panel-header">
        <h2>Comunidades</h2>
      </div>

      <?php if (isset($_SESSION['usuario'])): ?>
      <div class="communities-actions">
        <button class="btn-criar-comunidade" id="btn-criar-comunidade">+ Criar Comunidade</button>
      </div>

      <ul class="communities-list" id="communities-list">
        <!-- Preenchida via JS a partir de php/buscar_comunidades.php -->
      </ul>

      <!-- Modal de Criação de Comunidade -->
      <dialog id="criar-comunidade-box">
        <form id="form-criar-comunidade" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <h2>Criar Comunidade</h2>

          <div class="criar-comunidade-body">
            <div class="criar-comunidade-campos">
              <input type="text" name="nome" id="input-nome-comunidade" placeholder="Nome da comunidade (mín. 2 caracteres)" minlength="2" maxlength="40" required>
              <textarea name="descricao" id="input-descricao-comunidade" placeholder="Breve descrição da comunidade..." maxlength="200"></textarea>
            </div>

            <div class="criar-comunidade-foto">
              <span>Foto / Ícone:</span>
              <label for="input-imagem-comunidade" class="avatar-upload" title="Escolher Imagem/GIF (máx 30MB)">
                <img id="preview-imagem-comunidade" src="" alt="Preview">
                <svg class="avatar-placeholder-icon" viewBox="0 0 24 24"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>
              </label>
              <input type="file" id="input-imagem-comunidade" name="imagem" accept="image/*,.gif" hidden>
            </div>
          </div>

          <div class="criar-comunidade-botoes">
            <button type="submit" class="btn-criar">Criar</button>
            <button type="button" class="btn-descartar" id="btn-descartar-comunidade">Descartar</button>
          </div>

          <p id="erro-criar-comunidade" class="erro-msg"></p>
        </form>
      </dialog>
      <?php else: ?>
      <p class="communities-login-hint">Faça login para criar ou participar de comunidades.</p>
      <?php endif; ?>
    </section>

  </main>

  <footer class="bottombar">
    <strong>Blumask</strong>
    <svg viewBox="0 0 24 24" fill="none" stroke="#1c1c1c" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M15 9.5a3.5 3.5 0 1 0 0 5"/></svg>
  </footer>

</div>
    
<!-- Modal de Pré-visualização de Perfil de Usuário -->
<dialog id="dialog-ver-usuario">
  <div class="user-modal-header">
    <div class="user-modal-avatar">
      <img src="" alt="Avatar">
    </div>
  </div>
  <div class="user-modal-body">
    <h3>Nome de Exibição</h3>
    <p class="user-handle">@usuario</p>
    <p class="user-bio">Descrição do perfil...</p>
    <div class="user-modal-actions">
      <button type="button" class="btn-modal-fechar">Fechar</button>
    </div>
  </div>
</dialog>

<!-- Modal de Pré-visualização de Comunidade -->
<dialog id="dialog-ver-comunidade">
  <div class="comu-modal-header">
    <img class="comu-modal-avatar" src="" alt="Ícone da Comunidade">
    <div>
      <h3 class="comu-modal-title">Nome da Comunidade</h3>
      <span class="comu-modal-meta">Criada em 01/01/2026 • 1 membro</span>
    </div>
  </div>
  <div class="comu-modal-desc">
    Descrição da comunidade...
  </div>
  <div class="comu-modal-actions">
    <button type="button" class="btn-modal-fechar">Fechar</button>
  </div>
</dialog>

<!-- Script de Busca em Tempo Real -->
<script src="js/busca.js?v=<?= time() ?>"></script>
<!-- Script responsável por construir os inputs (Email/Senha/etc) dinamicamente -->
<script src="js/login_writter.js?v=<?= time() ?>"></script>
<?php if (isset($_SESSION['usuario'])): ?>
<!-- Script para controle e carregamento do Painel de Comunidades -->
<script src="js/comunidade.js?v=<?= time() ?>"></script>
<?php endif; ?>

<!-- Script para controle de exibição e alternância de abas do Modal de Autenticação -->
<script>
    const mostrar = document.getElementById("btn-entrar");
    const login = document.getElementById("login-box");
    const btn_entrar = document.getElementById("btn-entrar-dialog");
    const btn_cadastrar = document.getElementById("btn-cadastrar-dialog");
    const pop_content = document.getElementById("pop-div");

    function marcarAba(ativa) {
        if (btn_entrar && btn_cadastrar) {
            btn_entrar.classList.toggle("active-tab", ativa === "entrar");
            btn_cadastrar.classList.toggle("active-tab", ativa === "cadastrar");
        }
    }

    function abrirModalAutenticacao(modo = 0, erroMsg = null) {
        if (!login || !pop_content) return;
        
        const modeNum = (modo === "cadastrar" || modo == 1 || modo === "1") ? 1 : 0;
        pop_content.innerHTML = "";

        if (erroMsg) {
            const pErr = document.createElement("p");
            pErr.id = "modal-error-msg";
            pErr.style.cssText = "color: #d93025; font-size: 13px; margin: 0 0 12px 0; text-align: center; font-weight: bold;";
            pErr.textContent = erroMsg;
            pop_content.appendChild(pErr);
        }

        trocar(modeNum, pop_content);
        marcarAba(modeNum === 0 ? "entrar" : "cadastrar");
        
        if (!login.open) {
            login.showModal();
        }
    }

    if (mostrar && login) {
        mostrar.addEventListener("click", (event) => {
            event.preventDefault();
            abrirModalAutenticacao(0);
        });
    }

    if (btn_entrar && btn_cadastrar && pop_content) {
        btn_entrar.addEventListener("click", (event) => {
            event.stopPropagation();
            abrirModalAutenticacao(0);
        });

        btn_cadastrar.addEventListener("click", (event) => {
            event.stopPropagation();
            abrirModalAutenticacao(1);
        });
    }

    if (login) {
        login.addEventListener("click", (event) => {
            const bordas = login.getBoundingClientRect();
            if (
                event.clientX < bordas.left ||
                event.clientX > bordas.right ||
                event.clientY > bordas.bottom ||
                event.clientY < bordas.top
            ) {
                login.close();
            }
        });
    }

    async function fixarPostPerfil(idPost, btnElement) {
        const idUsuarioLogado = parseInt(document.body.dataset.idUsuario, 10) || 0;
        if (idUsuarioLogado <= 0) {
            abrirModalAutenticacao(0, "Você precisa estar logado para fixar um post no perfil.");
            return;
        }

        if (!btnElement || btnElement.disabled) return;
        btnElement.disabled = true;

        const csrfToken = "<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>";

        try {
            const response = await fetch('php/fixar_post.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}&id_post=${idPost}`
            });

            const data = await response.json();
            if (data.sucesso) {
                window.location.reload();
                return;
            }

            alert(data.mensagem || 'Não foi possível fixar este post no perfil.');
        } catch (err) {
            console.error('Erro ao fixar post no perfil:', err);
            alert('Erro ao fixar este post no perfil.');
        } finally {
            if (btnElement) btnElement.disabled = false;
        }
    }

    document.querySelectorAll('.post-card-feed').forEach((postCard) => {
        postCard.addEventListener('click', function(event) {
            const isInteractive = event.target.closest('button, a, input, textarea, select, .post-action, .post-pin-action, .post-comment-action, .btn-curtir-action');
            if (isInteractive) return;

            const idPost = this.dataset.postId;
            const communityId = this.dataset.communityId;
            if (idPost && communityId) {
                window.location.href = `php/post_detalhes.php?id_post=${idPost}`;
            }
        });
    });

    // Função assíncrona para curtir/descurtir posts recentes dinamicamente
    async function curtirPostRecente(idPost, btnElement) {
        const idUsuarioLogado = parseInt(document.body.dataset.idUsuario, 10) || 0;
        if (idUsuarioLogado <= 0) {
            if (typeof abrirModalAutenticacao === 'function') {
                abrirModalAutenticacao(0, "Você precisa estar logado para curtir posts.");
            } else {
                alert("Você precisa estar logado para curtir posts.");
            }
            return;
        }

        if (btnElement.disabled) return;
        btnElement.disabled = true;

        const iconSpan = btnElement.querySelector('.like-icon');
        const countSpan = btnElement.querySelector('.like-count');
        const csrfToken = "<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>";

        try {
            const response = await fetch('php/curtir_post.php', {
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

    // Controle de alternância de abas (Fixados vs Último Post)
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

<?php if (isset($login_error)): ?>
<!-- Reabertura automática unificada em caso de erro de autenticação -->
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const popupMode = <?= isset($popup_mode) ? json_encode($popup_mode) : '"0"' ?>;
        const errorMsg  = <?= json_encode($login_error) ?>;
        abrirModalAutenticacao(popupMode, errorMsg);
    });
</script>
<?php endif; ?>

</body>
</html>