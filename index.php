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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BluMask</title>
    <link rel="icon" type="image/webp" href="style/blumaskWhiteLogo.webp">
    <link rel="stylesheet" href="style/index_style.css">
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
        <div class="panel-header">
          <h2>Seu ultimo post</h2>
        </div>
        <div class="last-post-body">
          <span>Nada Ainda</span>
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
              <input type="text" name="nome" id="input-nome-comunidade" placeholder="Nome da comunidade" minlength="2" maxlength="40" required>
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
<script src="js/login_writter.js"></script>
<?php if (isset($_SESSION['usuario'])): ?>
<!-- Script para controle e carregamento do Painel de Comunidades -->
<script src="js/comunidade.js"></script>
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