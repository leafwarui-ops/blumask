document.addEventListener("DOMContentLoaded", () => {
    const btnCriar = document.getElementById("btn-criar-comunidade");
    const dialogCriar = document.getElementById("criar-comunidade-box");

    // painel só existe para usuário logado; se não existir, não faz nada
    if (!btnCriar || !dialogCriar) return;

    const btnDescartar = document.getElementById("btn-descartar-comunidade");
    const formCriar = document.getElementById("form-criar-comunidade");
    const inputImagem = document.getElementById("input-imagem-comunidade");
    const previewImagem = document.getElementById("preview-imagem-comunidade");
    const listaComunidades = document.getElementById("communities-list");
    const erroMsg = document.getElementById("erro-criar-comunidade");

    btnCriar.addEventListener("click", () => {
        erroMsg.textContent = "";
        formCriar.reset();
        previewImagem.removeAttribute("src");
        dialogCriar.showModal();
    });

    btnDescartar.addEventListener("click", () => {
        dialogCriar.close();
    });

    // fecha ao clicar fora da dialog (mesmo padrão usado na dialog de login)
    dialogCriar.addEventListener("click", (event) => {
        const bordas = dialogCriar.getBoundingClientRect();
        if (
            event.clientX < bordas.left ||
            event.clientX > bordas.right ||
            event.clientY > bordas.bottom ||
            event.clientY < bordas.top
        ) {
            dialogCriar.close();
        }
    });

    inputImagem.addEventListener("change", () => {
        const arquivo = inputImagem.files[0];
        if (arquivo) {
            previewImagem.src = URL.createObjectURL(arquivo);
        }
    });

    formCriar.addEventListener("submit", async (event) => {
        event.preventDefault();
        erroMsg.textContent = "";

        const dados = new FormData(formCriar);

        try {
            const resposta = await fetch("../php/criar_comunidade.php", {
                method: "POST",
                body: dados
            });
            const json = await resposta.json();

            if (json.sucesso) {
                adicionarComunidadeNaLista(json.comunidade);
                dialogCriar.close();
            } else {
                erroMsg.textContent = json.mensagem || "Não foi possível criar a comunidade.";
            }
        } catch (erro) {
            erroMsg.textContent = "Erro de conexão. Tente novamente.";
        }
    });

    function adicionarComunidadeNaLista(comunidade) {
        const item = document.createElement("li");
        item.className = "community-item";

        const src = comunidade.imagem ? "../" + comunidade.imagem : "../img/comunidade-padrao.png";
        const cargoTag = comunidade.cargo === 1 ? '<span class="cargo-admin-tag">admin</span>' : "";

        item.innerHTML = `
            <img class="community-avatar" src="${src}" alt="">
            <a href="../comunidade.php?id=${comunidade.id_comunidade}">${comunidade.nome}</a>
            ${cargoTag}
        `;
        listaComunidades.prepend(item);
    }

    async function carregarComunidades() {
        try {
            const resposta = await fetch("../php/buscar_comunidades.php");
            const json = await resposta.json();

            if (json.sucesso) {
                listaComunidades.innerHTML = "";
                json.comunidades.forEach(adicionarComunidadeNaLista);
            }
        } catch (erro) {
            console.error("Erro ao carregar comunidades:", erro);
        }
    }

    carregarComunidades();
});