<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BluMask</title>
</head>
<body>
    <!--//forms do botão entrar -->
<form action="">
    <button id = "entrar">entrar</button><!--//botão entrar que ativa a dialogue box-->
    <dialog id = "login-box"> <!-- dialog box propriamente dita-->
        <form action = "">
            <label for="">Nome de exibição:</label><!-- aqui é onde esta o texto "Nome de exibição:" que aparece no pop up -->
            <br>
            <input type = "text" id = "nome_exibicao" name = "nome_exibi"><!-- nome de usuario que aparece para os outros usuarios -->
            <br>
            <label for="">Nome de usuario:</label><!-- aqui é onde esta o texto "Nome de usuario:" que aparece no pop up -->
            <br>
            <input type = "text" id = "nome_usuario" name = "nome_usu"><!-- nome de usuario internamente -->
            <br>
            <label for="">Senha:</label><!-- aqui é onde esta o texto "Senha:" que aparece no pop up -->
            <br>
            <input type = "password" id = "senha" name = "senha"><!-- senha do usuario -->
            <br>
        </form>
    </dialog>
</form>

<script>
    const mostrar = document.getElementById("entrar"); //constante que puxa o botão "entrar" para que possam ser adicionada uma função a ele
    const login = document.getElementById("login-box"); // constante que puxa o dialog(pop up) para que possa ser manipulado pelo codigo

    // a linha a baixo define o que vai acontecer quando o botão "entrar" for pressionado pelo usuario
    mostrar.addEventListener("click", (event) => 
    {
        event.preventDefault();//previne o pop up de fechar sozinho
        login.showModal();//exibe o pop up 
    }
    );
    login.addEventListener("click",(event) => {
        const bordas = login.getBoundingClientRect();//constante que recebe a posição das bordas do pop up "login" para ser usado depois
        
        // o if abaixo verifica se o usuario clicou fora do pop up, se sim ele executa uma função para fechar o mesmo
        if (
            event.clientX < bordas.left ||
            event.clientX > bordas.right ||
            event.clientY > bordas.bottom ||
            event.clientY < bordas.top
        )
        {
            login.close(); // fecha o pop up
        }

        });
</script>

</body>
</html>
