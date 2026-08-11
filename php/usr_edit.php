<?php
session_start();
include __DIR__ . "/bd.php";

if (!isset($_SESSION['usuario'])) {
  header("Location: ../index.php");
  exit;
}

$user = $_SESSION['usuario'];
$nomeUsuario = htmlspecialchars($user['nome_de_usuario'] ?? '');
$nomeExibicao = htmlspecialchars($user['nome_de_exibicao'] ?? '');
$email = htmlspecialchars($user['email'] ?? '');
$descricao = htmlspecialchars($user['descricao'] ?? '');

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $nomeUsuario = mysqli_real_escape_string($conn, trim($_POST['nome_usr']));
  $nomeExibicao = mysqli_real_escape_string($conn, trim($_POST['nome_exb']));
  $email = mysqli_real_escape_string($conn, trim($_POST['email']));
  $senha = $_POST['senha'];
  $descricao = mysqli_real_escape_string($conn, trim($_POST['descricao']));

  if (!empty($senha)) {
    if (!preg_match('/^(?=.*[A-Z])(?=.*[\W_]).{8,32}$/', $senha)) {
      $error_message = "A senha deve ter entre 8 e 32 caracteres, uma maiúscula e um símbolo.";
    } else {
      $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
      $sql_update = "UPDATE usuario SET nome_de_usuario='$nomeUsuario', nome_de_exibicao='$nomeExibicao', email='$email', senha='$senha_hash', descricao='$descricao' WHERE id_usuario=" . intval($user['id_usuario']);
      if ($conn->query($sql_update) === TRUE) {
        $_SESSION['usuario']['nome_de_usuario'] = $nomeUsuario;
        $_SESSION['usuario']['nome_de_exibicao'] = $nomeExibicao;
        $_SESSION['usuario']['email'] = $email;
        $_SESSION['usuario']['descricao'] = $descricao;
        header("Location: ../index.php");
        exit;
      } else {
        $error_message = "Erro ao atualizar o perfil: " . $conn->error;
      }
    }
  } else{
    $sql_update = "UPDATE usuario SET nome_de_usuario='$nomeUsuario', nome_de_exibicao='$nomeExibicao', email='$email', descricao='$descricao' WHERE id_usuario=" . intval($user['id_usuario']);
    if ($conn->query($sql_update) === TRUE) {
      $_SESSION['usuario']['nome_de_usuario'] = $nomeUsuario;
      $_SESSION['usuario']['nome_de_exibicao'] = $nomeExibicao;
      $_SESSION['usuario']['email'] = $email;
      $_SESSION['usuario']['descricao'] = $descricao;
      header("Location: ../index.php");
      exit;
    } else {
      $error_message = "Erro ao atualizar o perfil: " . $conn->error;
    }
  }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Editar Perfil - BluMask</title>
  <link rel="icon" type="image/webp" href="../style/blumaskWhiteLogo.webp">
  <link rel="stylesheet" href="../style/index_style.css">
  <link rel="stylesheet" href="../style/edit_style.css">
</head>
<body>
  <div class="page">
    <header class="topbar" style="display: flex; align-items: center; gap: 12px;">
      <img src="../style/blumaskWhiteLogo.webp" alt="BluMask Logo" style="height: 36px; width: auto; object-fit: contain;">
      <h1 style="margin: 0;">BluMask</h1>
    </header>

    <main class="layout layout-single">
      <div class="panel edit-panel">
        <div class="edit-panel-header">
          <button class="btn-edit-banner" title="Editar Capa">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
          </button>
        </div>

        <div class="edit-panel-body">
          <div class="edit-avatar-wrapper">
            <img src="https://i.pinimg.com/736x/87/46/76/874676100eb6085a8efb483c7ccfa89b.jpg" alt="" class="edit-avatar">
            <button class="btn-edit-avatar" title="Editar Foto">
              <svg viewBox="0 0 24 24"><path fill="currentColor" d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
            </button>
          </div>

          <form method="post" action="">
            <div class="edit-form-grid">
              <div class="edit-form-col">
                <div class="form-group">
                  <label for="nome_usr">Nome de usuario:</label>
                  <input id="nome_usr" name="nome_usr" type="text" value="<?= $nomeUsuario ?>">
                </div>
                <div class="form-group">
                  <label for="nome_exb">Nome de exibição:</label>
                  <input id="nome_exb" name="nome_exb" type="text" value="<?= $nomeExibicao ?>">
                </div>
                <div class="form-group">
                  <label for="email">Email:</label>
                  <input id="email" name="email" type="email" value="<?= $email ?>">
                </div>
                <div class="form-group">
                  <label for="senha">Senha:</label>
                  <input id="senha" name="senha" type="password" value="">
                </div>
              </div>

              <div class="edit-form-col">
                <div class="form-group h-100">
                  <label for="descricao">Descrição:</label>
                  <textarea id="descricao" name="descricao"><?= $descricao ?></textarea>
                </div>
              </div>
            </div>

            <div class="edit-actions">
              <button type="button" onclick="window.location.href='../index.php'" class="btn-voltar">Voltar</button>
            </div>

            <div class="edit-actions">
              <button type="submit" class="btn_salvar">Salvar</button>
            </div>
          </form>
        </div>
      </div>
    </main>

    <footer class="bottombar">
      <strong>Blumask</strong>
      <svg viewBox="0 0 24 24"><path fill="currentColor" d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm-2-12h4v2h-4zm0 4h4v2h-4z"/></svg>
    </footer>
  </div>
</body>
</html>