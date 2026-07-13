<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BluMask</title>
</head>
<body>
    <!--//forms do botão entrar -->
    <button id = "btn-entrar">entrar</button><!--//botão entrar que ativa a dialogue box-->
    <dialog id = "login-box"> <!-- dialog box propriamente dita-->
        <form id = "popup-form" action = "">
            <button type = "button" id = "btn-entrar-dialog">entrar</button>
            <button type = "button" id = "btn-cadastrar-dialog">cadastrar</button>
            <br>
            <div id = "pop-div">

            </div>
        </form>
    </dialog>


    <script src = "js/login_writter.js"></script>
<script>
    const mostrar = document.getElementById("btn-entrar"); //constante que puxa o botão "entrar" para que possam ser adicionada uma função a ele
    const login = document.getElementById("login-box"); // constante que puxa o dialog(pop up) para que possa ser manipulado pelo codigo

    const btn_entrar = document.getElementById("btn-entrar-dialog");//botão de entrar dentro do popup
    const btn_cadastrar = document.getElementById("btn-cadastrar-dialog");//botão de cadastrar dentro do popup
    const pop_content = document.getElementById("pop-div");//elementos dentro da div do popup
    let PopupMenu = 0;//variavel que define o estado do menu do popup
    
    // a linha a baixo define o que vai acontecer quando o botão "entrar" for pressionado pelo usuario
    mostrar.addEventListener("click", (event) => 
    {
        event.preventDefault();//previne o pop up de fechar sozinho
        login.showModal();//exibe o pop up
        pop_content.innerHTML = "";//apaga qualquer conteudo dentro do popup
        escrever(pop_content,MenuItens);//adiciona os conteudos requisitados dentro do popup
    }
    );

    btn_entrar.addEventListener("click", (event) =>
    {
        event.stopPropagation();//impede o click de interagir com o resto da pagina
        PopupMenu = 0;//define o estado do menu do popup
        pop_content.innerHTML = "";//apaga qualquer conteudo dentro do popup
        trocar(PopupMenu,pop_content);//troca o menu do popup
    }
    );
    btn_cadastrar.addEventListener("click",(event) =>
    {
        event.stopPropagation();//impede o click de interagir com o resto da pagina
        PopupMenu = 1;//define o estado do menu do popup
        pop_content.innerHTML = "";//apaga qualquer conteudo dentro do popup
        trocar(PopupMenu,pop_content);//troca o menu do popup
    });
    

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
