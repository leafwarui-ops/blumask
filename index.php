<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BluMask</title>
    <link rel="stylesheet" href="style/index_style.css">
</head>
<body>
    <!--//forms do botão entrar -->
    

    <div class="page">

  <header class="topbar">
    <h1>BluMask</h1>
  </header>

  <main class="layout">

    <!-- LEFT PANEL: PROFILE -->
    <section class="panel profile-panel">
      <div class="panel-header">
        <div class="avatar">
          <svg viewBox="0 0 24 24"><path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z"/></svg>
        </div>
      </div>
      <div class="profile-body">
        <p>Ops! Você precisa fazer login para visualizar e customizar o seu perfil!</p>
        <button class="btn-entrar" id = "btn-entrar">entrar</button><!--//botão entrar que ativa a dialogue box-->
    <dialog id = "login-box"> <!-- dialog box propriamente dita-->
        <form id = "popup-form" action = "" method = "post">
            <div class="dialog-tabs">
                <button type = "button" id = "btn-entrar-dialog">entrar</button>
                <button type = "button" id = "btn-cadastrar-dialog">cadastrar</button>
            </div>
            <br>
            <div id = "pop-div">
            </div>
        </form>
    </dialog>
      </div>
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
    
    <script src="js/login_writter.js"></script>
<script>
    const mostrar = document.getElementById("btn-entrar");
    const login = document.getElementById("login-box");

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
</script>

</body>
</html>
<?php
 include "php\bd.php";
 
 // puxa a api de icon dos usuários
 $url = "https://ui-avatars.com/api/?background=random";
 $answer = file_get_contents($url);
 $dados = json_decode($answer, true);


if ($_SERVER["REQUEST_METHOD"] == "POST"){ 
    global $conn;
    $popup_mode = trim($_POST['popup-mode']);

if ($popup_mode == "0") {
 $email = mysqli_real_escape_string($conn,trim($_POST['email']));
 $senha = mysqli_real_escape_string($conn,$_POST['senha']);

 $sql = "SELECT * FROM usuario WHERE email = '$email' && senha = '$senha' LIMIT 1";
 $login_compare = mysqli_query($conn,$sql);
 $login_list = mysqli_fetch_assoc($login_compare);

    //verifica email e senha estão corretos
 if (($login_list['email'] == $email) && ($login_list['senha'] == $senha))
    {
       $logado = true;
       echo "fdp, tu logou";
       }
    else{
        echo "ta errado pai";
    }
}
else
{
    // manda as informações de cadastro do usuário para o banco de dados
    $nome_usr = mysqli_real_escape_string($conn,trim($_POST['nome_usr']));
    $nome_exb = mysqli_real_escape_string($conn,$_POST['nome_exb']);
    $email = mysqli_real_escape_string($conn,trim($_POST['email']));
    $senha = mysqli_real_escape_string($conn,$_POST['senha']);
    $sql = "INSERT INTO usuario (email,senha,nome_de_exibicao,nome_de_usuario) VALUES('$email','$senha','$nome_exb','$nome_usr')";
    
    // verifica se foi ou não
    if ($conn->query($sql) === true)
        {
            echo 'foi';
            $avatarUrl = "https://ui-avatars.com/api/?name=" . urlencode($nome_exb);
            echo "<img src='$avatarUrl' alt='Avatar'>";
        }
    else{
        echo 'num foi';
    }
}
}
?>