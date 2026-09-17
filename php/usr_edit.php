<?php
require_once __DIR__ . "/security_headers.php";
require_once __DIR__ . "/rate_limit.php";
include __DIR__ . "/bd.php";

if (!isset($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit;
}

$user_id = intval($_SESSION['usuario']['id_usuario']);

// Puxa dados atualizados do banco de dados
$sql_user = "SELECT * FROM usuario WHERE id_usuario = $user_id LIMIT 1";
$res_user = $conn->query($sql_user);
if ($res_user && $res_user->num_rows > 0) {
    $user = $res_user->fetch_assoc();
    $_SESSION['usuario'] = $user;
} else {
    $user = $_SESSION['usuario'];
}

$nomeUsuario  = htmlspecialchars($user['nome_de_usuario'] ?? '', ENT_QUOTES, 'UTF-8');
$nomeExibicao = htmlspecialchars($user['nome_de_exibicao'] ?? '', ENT_QUOTES, 'UTF-8');
$email        = htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8');
$descricao    = htmlspecialchars($user['descricao'] ?? '', ENT_QUOTES, 'UTF-8');
$fotoPerfil   = $user['foto_perfil'] ?? '';
$bannerPath   = $user['banner'] ?? '';

$error_message   = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 0. Validação de CSRF Token
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_message = "Token de segurança (CSRF) inválido. Recarregue a página e tente novamente.";
    }
    // Exclusão da conta com confirmação por senha
    elseif (!empty($_POST['delete_account'])) {
        $senha_delete = $_POST['delete_account_senha'] ?? '';

        if (empty($senha_delete)) {
            $error_message = "Você precisa informar sua senha para confirmar a exclusão da conta.";
        } elseif (!password_verify($senha_delete, $user['senha'])) {
            $error_message = "Senha incorreta. A conta não foi excluída.";
        } else {
            $deleted_username = 'usuario_deletado_' . $user_id;
            $deleted_email = 'usuario_deletado_' . $user_id . '@deleted.local';
            $deleted_hash = password_hash('conta_deletada', PASSWORD_DEFAULT);

            $deleted_username_esc = $conn->real_escape_string($deleted_username);
            $deleted_email_esc = $conn->real_escape_string($deleted_email);
            $deleted_hash_esc = $conn->real_escape_string($deleted_hash);

            $sql_anonymize = "UPDATE usuario SET 
                nome_de_exibicao = 'Usuário deletado',
                nome_de_usuario = '$deleted_username_esc',
                email = '$deleted_email_esc',
                descricao = 'Conta removida pelo usuário.',
                senha = '$deleted_hash_esc',
                banner = NULL,
                foto_perfil = NULL,
                id_post_fixado = NULL
                WHERE id_usuario = $user_id";

            if ($conn->query($sql_anonymize) === TRUE) {
                session_destroy();
                header("Location: ../index.php?conta_excluida=1");
                exit;
            }

            $error_message = "Não foi possível excluir a conta no momento. Tente novamente.";
        }
    }
    // Checa Rate Limit de edição (máx 5 atualizações / 15 min = 900s)
    elseif (!check_rate_limit('usr_edit_attempt', 5, 900)) {
        $waitTime = get_rate_limit_wait_time('usr_edit_attempt', 900);
        $error_message = "Muitas solicitações de alteração seguidas. Por favor, aguarde $waitTime para tentar novamente.";
    } else {
        hit_rate_limit('usr_edit_attempt');
        $post_nome_usr  = trim($_POST['nome_usr'] ?? '');
        $post_nome_exb  = trim($_POST['nome_exb'] ?? '');
        $post_email     = trim($_POST['email'] ?? '');
        $post_descricao = str_replace(["\r\n", "\r"], "\n", trim($_POST['descricao'] ?? ''));
        $senha_atual    = $_POST['senha_atual'] ?? '';
        $nova_senha     = $_POST['nova_senha'] ?? '';

        // 1. Checa se alterou e-mail ou senha (que exigem confirmação da senha atual)
        $email_alterado = ($post_email !== $user['email']);
        $senha_alterada = !empty($nova_senha);

        if ($email_alterado || $senha_alterada) {
            if (empty($senha_atual)) {
                $error_message = "Você precisa informar sua senha atual para alterar o e-mail ou a senha.";
            } elseif (!password_verify($senha_atual, $user['senha'])) {
                $error_message = "Senha atual incorreta.";
            }
        }

        // 2. Validação do Nome de Usuário (4 a 20 caracteres)
        if (empty($error_message)) {
            if (mb_strlen($post_nome_usr) < 4 || mb_strlen($post_nome_usr) > 20) {
                $error_message = "O nome de usuário deve ter entre 4 e 20 caracteres.";
            } else {
                $esc_usr = mysqli_real_escape_string($conn, $post_nome_usr);
                $check_usr = $conn->query("SELECT id_usuario FROM usuario WHERE nome_de_usuario = '$esc_usr' AND id_usuario != $user_id");
                if ($check_usr && $check_usr->num_rows > 0) {
                    $error_message = "Este nome de usuário já está em uso por outra conta.";
                }
            }
        }

        // 3. Validação do Nome de Exibição (2 a 10 caracteres)
        if (empty($error_message)) {
            if (mb_strlen($post_nome_exb) < 2 || mb_strlen($post_nome_exb) > 10) {
                $error_message = "O nome de exibição deve ter entre 2 e 10 caracteres.";
            }
        }

        // 4. Validação do E-mail e Unicidade
        if (empty($error_message)) {
            if (!filter_var($post_email, FILTER_VALIDATE_EMAIL)) {
                $error_message = "Formato de e-mail inválido.";
            } else {
                $esc_email = mysqli_real_escape_string($conn, $post_email);
                $check_email = $conn->query("SELECT id_usuario FROM usuario WHERE email = '$esc_email' AND id_usuario != $user_id");
                if ($check_email && $check_email->num_rows > 0) {
                    $error_message = "Este e-mail já está cadastrado por outro usuário.";
                }
            }
        }

        // 5. Validação da Descrição/Bio (Máximo 200 caracteres)
        if (empty($error_message)) {
            if (mb_strlen($post_descricao) > 200) {
                $error_message = "A descrição não pode ter mais de 200 caracteres.";
            }
        }

        // 6. Validação da Nova Senha (se preenchida)
        $senha_final_hash = $user['senha'];
        if (empty($error_message) && !empty($nova_senha)) {
            if (!preg_match('/^(?=.*[A-Z])(?=.*[\W_]).{8,32}$/', $nova_senha)) {
                $error_message = "A nova senha deve ter entre 8 e 32 caracteres, uma letra maiúscula e um símbolo.";
            } else {
                $senha_final_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
            }
        }

        // Max file size: 30MB (31457280 bytes)
        $max_file_size = 31457280;
        $allowed_extensions = ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'avif'];

        // 7. Upload do Banner (se enviado)
        $uploaded_banner_path = $bannerPath;
        if (empty($error_message) && isset($_FILES['banner']) && $_FILES['banner']['error'] === UPLOAD_ERR_OK) {
            $b_size = $_FILES['banner']['size'];
            $b_tmp  = $_FILES['banner']['tmp_name'];
            $b_name = $_FILES['banner']['name'];
            $b_ext  = strtolower(pathinfo($b_name, PATHINFO_EXTENSION));

            if ($b_size > $max_file_size) {
                $error_message = "A imagem do banner excede o limite máximo de 30MB.";
            } elseif (!in_array($b_ext, $allowed_extensions)) {
                $error_message = "Formato de imagem de banner inválido. Use JPG, JPEG, JFIF, PNG, GIF, WEBP ou AVIF.";
            } elseif (@getimagesize($b_tmp) === false) {
                $error_message = "O arquivo enviado para o banner não é uma imagem válida.";
            } else {
                $target_dir = __DIR__ . '/../uploads/banners/';
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                $new_b_name = 'banner_' . $user_id . '_' . time() . '.' . $b_ext;
                $target_file = $target_dir . $new_b_name;

                if (move_uploaded_file($b_tmp, $target_file)) {
                    $uploaded_banner_path = 'uploads/banners/' . $new_b_name;
                } else {
                    $error_message = "Erro ao salvar a imagem do banner no servidor.";
                }
            }
        }

        // 8. Upload da Foto de Perfil (Avatar) (se enviada)
        $uploaded_avatar_path = $fotoPerfil;
        if (empty($error_message) && isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $a_size = $_FILES['avatar']['size'];
            $a_tmp  = $_FILES['avatar']['tmp_name'];
            $a_name = $_FILES['avatar']['name'];
            $a_ext  = strtolower(pathinfo($a_name, PATHINFO_EXTENSION));

            if ($a_size > $max_file_size) {
                $error_message = "A imagem de perfil excede o limite máximo de 30MB.";
            } elseif (!in_array($a_ext, $allowed_extensions)) {
                $error_message = "Formato de foto de perfil inválido. Use JPG, JPEG, JFIF, PNG, GIF, WEBP ou AVIF.";
            } elseif (@getimagesize($a_tmp) === false) {
                $error_message = "O arquivo enviado para foto de perfil não é uma imagem válida.";
            } else {
                $target_dir = __DIR__ . '/../uploads/avatars/';
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                $new_a_name = 'avatar_' . $user_id . '_' . time() . '.' . $a_ext;
                $target_file = $target_dir . $new_a_name;

                if (move_uploaded_file($a_tmp, $target_file)) {
                    $uploaded_avatar_path = 'uploads/avatars/' . $new_a_name;
                } else {
                    $error_message = "Erro ao salvar a foto de perfil no servidor.";
                }
            }
        }

        // 9. Atualização no Banco de Dados
        if (empty($error_message)) {
            $esc_usr   = mysqli_real_escape_string($conn, $post_nome_usr);
            $esc_exb   = mysqli_real_escape_string($conn, $post_nome_exb);
            $esc_email = mysqli_real_escape_string($conn, $post_email);
            $esc_desc  = mysqli_real_escape_string($conn, $post_descricao);
            $esc_ban   = mysqli_real_escape_string($conn, $uploaded_banner_path);
            $esc_ava   = mysqli_real_escape_string($conn, $uploaded_avatar_path);

            $sql_update = "UPDATE usuario SET 
                            nome_de_usuario = '$esc_usr',
                            nome_de_exibicao = '$esc_exb',
                            email = '$esc_email',
                            descricao = '$esc_desc',
                            senha = '$senha_final_hash',
                            banner = '$esc_ban',
                            foto_perfil = '$esc_ava'
                           WHERE id_usuario = $user_id";

            if ($conn->query($sql_update) === TRUE) {
                // Atualiza Sessão
                $_SESSION['usuario']['nome_de_usuario']  = $post_nome_usr;
                $_SESSION['usuario']['nome_de_exibicao'] = $post_nome_exb;
                $_SESSION['usuario']['email']            = $post_email;
                $_SESSION['usuario']['descricao']        = $post_descricao;
                $_SESSION['usuario']['senha']            = $senha_final_hash;
                $_SESSION['usuario']['banner']           = $uploaded_banner_path;
                $_SESSION['usuario']['foto_perfil']      = $uploaded_avatar_path;

                header("Location: ../index.php");
                exit;
            } else {
                $error_message = "Erro ao atualizar perfil no banco de dados.";
            }
        }
    }
}

// Avatar URL inicial
$avatarUrl = !empty($fotoPerfil) ? "../" . htmlspecialchars($fotoPerfil, ENT_QUOTES, 'UTF-8') : "https://ui-avatars.com/api/?name=" . urlencode($nomeExibicao) . "&background=random";
$bannerStyle = !empty($bannerPath) ? "background-image: url('../" . htmlspecialchars($bannerPath, ENT_QUOTES, 'UTF-8') . "'); background-size: cover; background-position: center;" : "";
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar Perfil - BluMask</title>
  <link rel="icon" type="image/webp" href="../style/blumaskWhiteLogo.webp">
  <link rel="stylesheet" href="../style/index_style.css">
  <link rel="stylesheet" href="../style/edit_style.css?v=<?= time() ?>">
</head>
<body>
  <div class="page">
    <!-- TOPBAR -->
    <header class="topbar" style="display: flex; justify-content: space-between; align-items: center; padding: 0 20px; min-height: 60px;">
      <a href="../index.php" style="display: flex; align-items: center; gap: 12px; text-decoration: none; color: inherit;">
        <img src="../style/blumaskWhiteLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
        <h1 style="margin: 0; font-size: 20px;">BluMask</h1>
      </a>
    </header>

    <!-- MAIN LAYOUT -->
    <main class="layout layout-single">
      <div class="panel edit-panel">
        
        <!-- PAINEL SUPERIOR (BANNER) -->
        <div class="edit-panel-header" id="banner-preview" style="<?= $bannerStyle ?>">
          <button type="button" class="btn-edit-banner" id="btn-trigger-banner" title="Editar Capa (máx 30MB)">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
          </button>
        </div>

        <!-- CORPO DO FORMULÁRIO -->
        <div class="edit-panel-body">
          
          <!-- FOTO DE PERFIL (AVATAR) -->
          <div class="edit-avatar-wrapper">
            <img src="<?= $avatarUrl ?>" alt="Avatar" id="avatar-preview" class="edit-avatar">
            <button type="button" class="btn-edit-avatar" id="btn-trigger-avatar" title="Editar Foto (máx 30MB)">
              <svg viewBox="0 0 24 24"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
            </button>
          </div>

          <!-- MENSAGEM DE ERRO DO SERVIDOR -->
          <?php if (!empty($error_message)): ?>
            <div style="background: rgba(217, 48, 37, 0.15); border: 1px solid #d93025; color: #d93025; padding: 12px; border-radius: 12px; font-weight: bold; margin-bottom: 20px; text-align: center;">
              <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>

          <form method="post" action="" enctype="multipart/form-data" id="edit-profile-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <!-- INPUTS ESCONDIDOS DE FILE -->
            <input type="file" name="banner" id="banner-input" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.jpg,.jpeg,.jfif,.png,.gif,.webp,.avif" style="display: none;">
            <input type="file" name="avatar" id="avatar-input" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,.jpg,.jpeg,.jfif,.png,.gif,.webp,.avif" style="display: none;">

            <div class="edit-form-grid">
              
              <!-- COLUNA ESQUERDA -->
              <div class="edit-form-col">
                
                <!-- Nome de usuário -->
                <div class="form-group">
                  <label for="nome_usr">Nome de usuario <span class="required-asterisk">*</span></label>
                  <input id="nome_usr" name="nome_usr" type="text" value="<?= $nomeUsuario ?>" minlength="4" maxlength="20" placeholder="Mínimo de 4 caracteres" required>
                  <span id="err-nome_usr" class="field-error"></span>
                </div>

                <!-- Nome de exibição -->
                <div class="form-group">
                  <label for="nome_exb">Nome de exibição <span class="required-asterisk">*</span></label>
                  <input id="nome_exb" name="nome_exb" type="text" value="<?= $nomeExibicao ?>" minlength="2" maxlength="10" placeholder="Mínimo de 2 caracteres" required>
                  <span id="err-nome_exb" class="field-error"></span>
                </div>

                <!-- Email -->
                <div class="form-group">
                  <label for="email">Email <span class="required-asterisk">*</span></label>
                  <input id="email" name="email" type="email" value="<?= $email ?>" required>
                  <span id="err-email" class="field-error"></span>
                </div>

                <!-- Senha Atual -->
                <div class="form-group">
                  <label for="senha_atual">Senha Atual <span id="label-senha-req" style="font-size: 0.8rem; font-weight: normal; color: #666;">(Apenas se alterar e-mail ou senha)</span></label>
                  <input id="senha_atual" name="senha_atual" type="password" placeholder="Preencha caso altere e-mail ou nova senha">
                  <span id="err-senha_atual" class="field-error"></span>
                </div>

                <!-- Nova Senha -->
                <div class="form-group">
                  <label for="nova_senha">Nova Senha <span style="font-size: 0.8rem; font-weight: normal; color: #666;">(Opcional)</span></label>
                  <input id="nova_senha" name="nova_senha" type="password" minlength="8" maxlength="32" placeholder="Mínimo de 8 caracteres (maiúscula e símbolo)">
                  <span id="err-nova_senha" class="field-error"></span>
                </div>

              </div>

              <!-- COLUNA DIREITA (DESCRIÇÃO / BIO) -->
              <div class="edit-form-col">
                <div class="form-group h-100">
                  <label for="descricao">Descrição / Bio:</label>
                  <textarea id="descricao" name="descricao" maxlength="200" placeholder="Escreva um resumo sobre você..."><?= $descricao ?></textarea>
                  <div class="char-counter"><span id="char-count">0</span>/200</div>
                  <span id="err-descricao" class="field-error"></span>
                </div>
              </div>

            </div>

            <!-- BOTÕES DE AÇÃO -->
            <div class="edit-actions">
              <button type="button" onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href = '../index.php'; }" class="btn-voltar">Voltar</button>
              <button type="submit" id="btn-confirmar" class="btn-confirmar" disabled>Confirmar</button>
            </div>

            <div class="delete-account-area">
              <button type="button" id="btn-delete-account" class="btn-delete-account">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M3 6h18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M10 11v6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 11v6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Excluir conta
              </button>
            </div>

          </form>
        </div>
      </div>
    </main>

    <div class="delete-modal" id="deleteAccountModal" aria-hidden="true">
      <div class="delete-modal-content" role="dialog" aria-modal="true" aria-labelledby="deleteAccountTitle">
        <h3 id="deleteAccountTitle">Excluir conta</h3>
        <p>Essa ação é permanente. Todos os seus posts, comentários e dados vinculados serão removidos.</p>

        <form method="post" action="" id="deleteAccountForm">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="delete_account" value="1">

          <label for="delete_account_senha">Digite sua senha para confirmar</label>
          <input id="delete_account_senha" name="delete_account_senha" type="password" placeholder="Sua senha" autocomplete="current-password" required>

          <div class="delete-modal-actions">
            <button type="button" class="btn-cancel-delete" id="btnCancelDelete">Cancelar</button>
            <button type="submit" class="btn-confirm-delete">Excluir conta</button>
          </div>
        </form>
      </div>
    </div>

    <!-- FOOTER -->
    <footer class="bottombar">
      <strong>Blumask</strong>
      <span aria-label="Direitos autorais" style="font-size: 1rem; font-weight: 700; color: #1c1c1c;">©</span>
    </footer>
  </div>

  <style>
    .delete-account-area {
      margin-top: 20px;
      display: flex;
      justify-content: flex-end;
    }

    .btn-delete-account {
      background: #ef4444; /* red-500 */
      color: #fff;
      border: none;
      border-radius: 12px;
      padding: 10px 18px;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
      box-shadow: 0 6px 18px rgba(239, 68, 68, 0.18);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-delete-account:hover {
      transform: translateY(-2px);
      background: #dc2626; /* red-600 */
      box-shadow: 0 10px 26px rgba(220, 38, 38, 0.22);
    }

    .btn-delete-account svg {
      width: 16px;
      height: 16px;
      opacity: 0.95;
    }

    .delete-modal {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.55);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 2000;
      padding: 20px;
    }

    .delete-modal.active {
      display: flex;
    }

    .delete-modal-content {
      width: min(100%, 420px);
      background: #fff;
      color: #1f1f1f;
      border-radius: 18px;
      padding: 24px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.25);
    }

    .delete-modal-content h3 {
      margin: 0 0 10px;
      font-size: 1.5rem;
    }

    .delete-modal-content p {
      margin: 0 0 18px;
      color: #4d4d4d;
      line-height: 1.5;
    }

    .delete-modal-content label {
      display: block;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .delete-modal-content input {
      width: 100%;
      padding: 12px 14px;
      border-radius: 10px;
      border: 1px solid #d0d5dd;
      background: #f9fafb;
      margin-bottom: 18px;
      box-sizing: border-box;
    }

    .delete-modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
    }

    .btn-cancel-delete,
    .btn-confirm-delete {
      border: none;
      border-radius: 10px;
      padding: 10px 16px;
      font-weight: 700;
      cursor: pointer;
    }

    .btn-cancel-delete {
      background: #eef2f7;
      color: #1f2937;
    }

    .btn-confirm-delete {
      background: #d93025;
      color: #fff;
    }
  </style>

  <!-- SCRIPT DE INTERAÇÃO E VALIDAÇÕES EM TEMPO REAL -->
  <script>
    document.addEventListener("DOMContentLoaded", function () {
      const form = document.getElementById("edit-profile-form");
      const btnConfirmar = document.getElementById("btn-confirmar");
      const btnDeleteAccount = document.getElementById("btn-delete-account");
      const deleteModal = document.getElementById("deleteAccountModal");
      const btnCancelDelete = document.getElementById("btnCancelDelete");
      const deletePasswordInput = document.getElementById("delete_account_senha");

      const inputNomeUsr   = document.getElementById("nome_usr");
      const inputNomeExb   = document.getElementById("nome_exb");
      const inputEmail     = document.getElementById("email");
      const inputSenhaAtu  = document.getElementById("senha_atual");
      const inputNovaSenha = document.getElementById("nova_senha");
      const textDescricao  = document.getElementById("descricao");
      const charCountSpan  = document.getElementById("char-count");

      const errNomeUsr   = document.getElementById("err-nome_usr");
      const errNomeExb   = document.getElementById("err-nome_exb");
      const errEmail     = document.getElementById("err-email");
      const errSenhaAtu  = document.getElementById("err-senha_atual");
      const errNovaSenha = document.getElementById("err-nova_senha");
      const errDescricao = document.getElementById("err-descricao");

      // Banner e Avatar elements
      const btnBanner = document.getElementById("btn-trigger-banner");
      const inputBanner = document.getElementById("banner-input");
      const bannerPreview = document.getElementById("banner-preview");

      const btnAvatar = document.getElementById("btn-trigger-avatar");
      const inputAvatar = document.getElementById("avatar-input");
      const avatarPreview = document.getElementById("avatar-preview");

      const MAX_FILE_SIZE = 31457280; // 30MB
      const allowedExtensions = ["jpg", "jpeg", "jfif", "png", "gif", "webp", "avif"];

      function isAllowedImageFile(file) {
        if (!file) return false;
        const fileName = file.name.toLowerCase();
        const extension = fileName.split(".").pop();
        const mime = file.type ? file.type.toLowerCase() : "";
        const allowedMimeTypes = [
          "image/jpeg",
          "image/png",
          "image/gif",
          "image/webp",
          "image/avif"
        ];

        return allowedMimeTypes.includes(mime) || allowedExtensions.includes(extension);
      }

      // Valores Iniciais para detectar se houve alteração na página
      const initialNomeUsr   = inputNomeUsr.value.trim();
      const initialNomeExb   = inputNomeExb.value.trim();
      const initialEmail     = inputEmail.value.trim();
      const initialDescricao = textDescricao.value.replace(/\r\n/g, "\n");

      // Acionadores dos campos File
      btnBanner.addEventListener("click", () => inputBanner.click());
      btnAvatar.addEventListener("click", () => inputAvatar.click());

      // Preview Banner
      inputBanner.addEventListener("change", function () {
        const file = this.files[0];
        if (file) {
          if (file.size > MAX_FILE_SIZE) {
            alert("O arquivo de banner selecionado excede o limite máximo de 30MB.");
            this.value = "";
            return;
          }
          if (!isAllowedImageFile(file)) {
            alert("Formato de banner inválido. Use JPG, JPEG, JFIF, PNG, GIF, WEBP ou AVIF.");
            this.value = "";
            return;
          }
          const reader = new FileReader();
          reader.onload = function (e) {
            bannerPreview.style.backgroundImage = `url('${e.target.result}')`;
            bannerPreview.style.backgroundSize = "cover";
            bannerPreview.style.backgroundPosition = "center";
          };
          reader.readAsDataURL(file);
        }
        validateForm();
      });

      // Preview Avatar
      inputAvatar.addEventListener("change", function () {
        const file = this.files[0];
        if (file) {
          if (file.size > MAX_FILE_SIZE) {
            alert("A foto de perfil selecionada excede o limite máximo de 30MB.");
            this.value = "";
            return;
          }
          if (!isAllowedImageFile(file)) {
            alert("Formato de foto de perfil inválido. Use JPG, JPEG, JFIF, PNG, GIF, WEBP ou AVIF.");
            this.value = "";
            return;
          }
          const reader = new FileReader();
          reader.onload = function (e) {
            avatarPreview.src = e.target.result;
          };
          reader.readAsDataURL(file);
        }
        validateForm();
      });

      // Contador de Caracteres da Bio (normalizando quebras de linha)
      function updateCharCount() {
        const valDesc = textDescricao.value.replace(/\r\n/g, "\n");
        const len = valDesc.length;
        charCountSpan.textContent = len;
        if (len > 200) {
          charCountSpan.parentElement.classList.add("limit-reached");
        } else {
          charCountSpan.parentElement.classList.remove("limit-reached");
        }
      }
      textDescricao.addEventListener("input", updateCharCount);
      updateCharCount(); // Inicializa

      // Validação em Tempo Real
      function validateForm() {
        let isValid = true;

        const currentNomeUsr   = inputNomeUsr.value.trim();
        const currentNomeExb   = inputNomeExb.value.trim();
        const currentEmail     = inputEmail.value.trim();
        const currentDescricao = textDescricao.value.replace(/\r\n/g, "\n");
        const hasFileBanner    = inputBanner.files.length > 0;
        const hasFileAvatar    = inputAvatar.files.length > 0;
        const hasNovaSenha     = inputNovaSenha.value.length > 0;

        // Detecta se o usuário alterou algum dado em relação ao estado inicial
        const hasChanges = (
          currentNomeUsr !== initialNomeUsr ||
          currentNomeExb !== initialNomeExb ||
          currentEmail !== initialEmail ||
          currentDescricao !== initialDescricao ||
          hasNovaSenha ||
          hasFileBanner ||
          hasFileAvatar
        );

        // 1. Nome de Usuario (4 a 20 chars)
        if (currentNomeUsr.length < 4 || currentNomeUsr.length > 20) {
          isValid = false;
          inputNomeUsr.classList.add("invalid");
          errNomeUsr.textContent = currentNomeUsr.length > 0 ? "O nome de usuário deve ter entre 4 e 20 caracteres." : "Campo obrigatório.";
        } else {
          inputNomeUsr.classList.remove("invalid");
          errNomeUsr.textContent = "";
        }

        // 2. Nome de Exibição (2 a 10 chars)
        if (currentNomeExb.length < 2 || currentNomeExb.length > 10) {
          isValid = false;
          inputNomeExb.classList.add("invalid");
          errNomeExb.textContent = currentNomeExb.length > 0 ? "O nome de exibição deve ter entre 2 e 10 caracteres." : "Campo obrigatório.";
        } else {
          inputNomeExb.classList.remove("invalid");
          errNomeExb.textContent = "";
        }

        // 3. Email
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(currentEmail)) {
          isValid = false;
          inputEmail.classList.add("invalid");
          errEmail.textContent = currentEmail.length > 0 ? "Formato de e-mail inválido." : "Campo obrigatório.";
        } else {
          inputEmail.classList.remove("invalid");
          errEmail.textContent = "";
        }

        // 4. Descrição (<= 200 chars)
        if (currentDescricao.length > 200) {
          isValid = false;
          textDescricao.classList.add("invalid");
          errDescricao.textContent = "A descrição não pode ter mais de 200 caracteres.";
        } else {
          textDescricao.classList.remove("invalid");
          errDescricao.textContent = "";
        }

        // 5. Senha Atual - EXIGIDA APENAS se alterou o e-mail ou a senha!
        const requiresPassword = (currentEmail !== initialEmail || hasNovaSenha);
        if (requiresPassword) {
          if (inputSenhaAtu.value.trim().length === 0) {
            isValid = false;
            inputSenhaAtu.classList.add("invalid");
            errSenhaAtu.textContent = "Senha atual é necessária para alterar e-mail ou nova senha.";
          } else {
            inputSenhaAtu.classList.remove("invalid");
            errSenhaAtu.textContent = "";
          }
        } else {
          inputSenhaAtu.classList.remove("invalid");
          errSenhaAtu.textContent = "";
        }

        // 6. Nova Senha (opcional, mas se preenchida deve bater com a regex 8-32, maiúscula, símbolo)
        if (hasNovaSenha) {
          const passRegex = /^(?=.*[A-Z])(?=.*[\W_]).{8,32}$/;
          const atendeRequisitos = passRegex.test(inputNovaSenha.value);
          if (!atendeRequisitos) {
            isValid = false;
            inputNovaSenha.classList.add("invalid");
            errNovaSenha.textContent = "8-32 caracteres, com maiúscula e símbolo.";
          } else {
            inputNovaSenha.classList.remove("invalid");
            errNovaSenha.textContent = "";
          }
        } else {
          inputNovaSenha.classList.remove("invalid");
          errNovaSenha.textContent = "";
        }

        // O botão Confirmar só fica ativo se HOUVER ALTERAÇÕES e TODOS OS CAMPOS forem válidos!
        btnConfirmar.disabled = !(isValid && hasChanges);
      }

      // Adiciona eventos aos campos
      const inputsToWatch = [inputNomeUsr, inputNomeExb, inputEmail, inputSenhaAtu, inputNovaSenha, textDescricao];
      inputsToWatch.forEach(input => {
        input.addEventListener("input", validateForm);
        input.addEventListener("keyup", validateForm);
        input.addEventListener("change", validateForm);
      });

      btnDeleteAccount.addEventListener("click", function () {
        deleteModal.classList.add("active");
        deletePasswordInput.focus();
      });

      btnCancelDelete.addEventListener("click", function () {
        deleteModal.classList.remove("active");
        deletePasswordInput.value = "";
      });

      deleteModal.addEventListener("click", function (event) {
        if (event.target === deleteModal) {
          deleteModal.classList.remove("active");
          deletePasswordInput.value = "";
        }
      });

      document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && deleteModal.classList.contains("active")) {
          deleteModal.classList.remove("active");
          deletePasswordInput.value = "";
        }
      });

      // Roda validação inicial
      validateForm();
    });
  </script>
</body>
</html>