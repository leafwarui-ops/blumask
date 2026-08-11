<?php
session_start();
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
    
    if ($popup_mode == "0") {
        // Entrar
        $email = mysqli_real_escape_string($conn, trim($_POST['email']));
        $senha = $_POST['senha'];
        
        $sql = "SELECT * FROM usuario WHERE email = '$email' LIMIT 1";
        $result = mysqli_query($conn, $sql);
        
        if ($result && mysqli_num_rows($result) > 0) {
            $user = mysqli_fetch_assoc($result);
            if (password_verify($senha, $user['senha'])) {
                $_SESSION['usuario'] = $user;
                header("Location: index.php");
                exit;
            } else {
                $login_error = "Email ou senha incorretos!";
            }
        } else {
            $login_error = "Email ou senha incorretos!";
        }
    } else {
        // Cadastrar
        $nome_usr = mysqli_real_escape_string($conn, trim($_POST['nome_usr']));
        $nome_exb = mysqli_real_escape_string($conn, trim($_POST['nome_exb']));
        $email = mysqli_real_escape_string($conn, trim($_POST['email']));
        $senha = mysqli_real_escape_string($conn, $_POST['senha']);
        
        if (!preg_match('/^(?=.*[A-Z])(?=.*[\W_]).{8,32}$/', $_POST['senha'])) {
            $login_error = "A senha deve ter entre 8 e 32 caracteres, uma maiúscula e um símbolo.";
        } else {
            $senha_hash = password_hash($_POST['senha'], PASSWORD_DEFAULT);
            $sql = "INSERT INTO usuario (email, senha, nome_de_exibicao, nome_de_usuario) VALUES ('$email', '$senha_hash', '$nome_exb', '$nome_usr')";
            
            if ($conn->query($sql) === TRUE) {
                // Auto login depois de cadastrar
                $new_user_id = $conn->insert_id;
                $sql_fetch = "SELECT * FROM usuario WHERE id_usuario = $new_user_id";
                $result = mysqli_query($conn, $sql_fetch);
                $_SESSION['usuario'] = mysqli_fetch_assoc($result);
                header("Location: index.php");
                exit;
            } else {
                $login_error = "Erro ao cadastrar. Verifique se o nome de usuário já existe.";
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
</head>
<body>

    <div class="page">

  <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px;">
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
          $nome_exibicao = htmlspecialchars($user['nome_de_exibicao']);
          $nome_usuario = htmlspecialchars($user['nome_de_usuario']);
          
          // Utiliza a foto de perfil salva no banco; caso não exista, gera um avatar dinâmico com as iniciais
          $avatarUrl = !empty($user['foto_perfil']) ? htmlspecialchars($user['foto_perfil']) : "https://ui-avatars.com/api/?name=" . urlencode($nome_exibicao) . "&background=random";
      ?>
      <div class="panel-header" style="height: 100px; position: relative;">
        <div class="avatar" style="position: absolute; bottom: -35px; left: 50%; transform: translateX(-50%); width: 70px; height: 70px; border-radius: 50%; border: 4px solid #fff; overflow: hidden; background: #333;">
          <img src="<?= $avatarUrl ?>" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;">
        </div>
      </div>
      <div class="profile-body" style="padding-top: 45px; text-align: center;">
        <h3 style="margin-bottom: 5px;"><?= $nome_exibicao ?></h3>
        <p style="font-size: 13px; color: #666; margin-bottom: 20px;">@<?= $nome_usuario ?></p>
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
        <button class="btn-entrar" id="btn-entrar">entrar</button>
        
        <!-- Modal de Autenticação (Login/Cadastro) -->
        <dialog id="login-box"> 
            <form id="popup-form" action="" method="post">
                <?php if (isset($login_error)) echo "<p style='color: red; font-size: 13px; margin-bottom: 15px; text-align: center; font-weight: bold;'>$login_error</p>"; ?>
                <div class="dialog-tabs">
                    <button type="button" id="btn-entrar-dialog">entrar</button>
                    <button type="button" id="btn-cadastrar-dialog">cadastrar</button>
                </div>
                <br>
                <div id="pop-div">
                </div>
            </form>
        </dialog>
      </div>
      <?php endif; ?>
    </section>

    <!-- CENTER COLUMN -->
    <section class="center-col">
      <div class="search-bar">
        <svg viewBox="0 0 24 24" fill="none" stroke="#1c1c1c" stroke-width="2.5"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span>Procurando por Algo?</span>
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
    </section>

  </main>

  <footer class="bottombar">
    <strong>Blumask</strong>
    <svg viewBox="0 0 24 24" fill="none" stroke="#1c1c1c" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M15 9.5a3.5 3.5 0 1 0 0 5"/></svg>
  </footer>

</div>
    
<!-- Script responsável por construir os inputs (Email/Senha/etc) dinamicamente -->
<script src="js/login_writter.js"></script>

<!-- Script para controle de exibição e alternância de abas do Modal de Autenticação -->
<script>
    const mostrar = document.getElementById("btn-entrar");
    const login = document.getElementById("login-box");

    if (mostrar && login) {
        const btn_entrar = document.getElementById("btn-entrar-dialog");
        const btn_cadastrar = document.getElementById("btn-cadastrar-dialog");
        const pop_content = document.getElementById("pop-div");
        let PopupMenu = 0;

        function marcarAba(ativa) {
            btn_entrar.classList.toggle("active-tab", ativa === "entrar");
            btn_cadastrar.classList.toggle("active-tab", ativa === "cadastrar");
        }

        mostrar.addEventListener("click", (event) => {
            event.preventDefault();
            login.showModal();
            PopupMenu = 0;
            pop_content.innerHTML = "";
            trocar(PopupMenu, pop_content);
            marcarAba("entrar");
        });

        btn_entrar.addEventListener("click", (event) => {
            event.stopPropagation();
            PopupMenu = 0;
            pop_content.innerHTML = "";
            trocar(PopupMenu, pop_content);
            marcarAba("entrar");
        });

        btn_cadastrar.addEventListener("click", (event) => {
            event.stopPropagation();
            PopupMenu = 1;
            pop_content.innerHTML = "";
            trocar(PopupMenu, pop_content);
            marcarAba("cadastrar");
        });

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
<!-- Script para reabrir automaticamente o Modal em caso de erro na autenticação -->
<script>
    if (login) {
        login.showModal();
        PopupMenu = <?= isset($popup_mode) ? json_encode($popup_mode) : '"0"' ?>;
        pop_content.innerHTML = "";
        trocar(PopupMenu, pop_content);
        marcarAba(PopupMenu == "0" ? "entrar" : "cadastrar");
    }
</script>
<?php endif; ?>

</body>
</html>