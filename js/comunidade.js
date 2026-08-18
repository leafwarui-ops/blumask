document.addEventListener("DOMContentLoaded", () => {
    const btnCriar = document.getElementById("btn-criar-comunidade");
    const dialogCriar = document.getElementById("criar-comunidade-box");

    // Se o elemento não existir (ex: usuário deslogado), encerra
    if (!btnCriar || !dialogCriar) return;

    const btnDescartar = document.getElementById("btn-descartar-comunidade");
    const formCriar = document.getElementById("form-criar-comunidade");
    const inputNome = document.getElementById("input-nome-comunidade");
    const inputImagem = document.getElementById("input-imagem-comunidade");
    const previewImagem = document.getElementById("preview-imagem-comunidade");
    const listaComunidades = document.getElementById("communities-list");
    const erroMsg = document.getElementById("erro-criar-comunidade");

    const MAX_FILE_SIZE = 31457280; // 30MB

    btnCriar.addEventListener("click", () => {
        erroMsg.textContent = "";
        formCriar.reset();
        previewImagem.removeAttribute("src");
        dialogCriar.showModal();
    });

    btnDescartar.addEventListener("click", () => {
        dialogCriar.close();
    });

    // Fecha ao clicar fora da caixa da dialog
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

    // Preview de Imagem (com checagem de max 30MB)
    inputImagem.addEventListener("change", () => {
        const arquivo = inputImagem.files[0];
        if (arquivo) {
            if (arquivo.size > MAX_FILE_SIZE) {
                alert("A imagem selecionada excede o limite máximo de 30MB.");
                inputImagem.value = "";
                previewImagem.removeAttribute("src");
                return;
            }
            previewImagem.src = URL.createObjectURL(arquivo);
        }
    });

    // Submissão do Formulário de Comunidade
    formCriar.addEventListener("submit", async (event) => {
        event.preventDefault();
        erroMsg.textContent = "";

        const valNome = inputNome.value.trim();
        const nomeRegex = /^[\p{L}\p{N}\s]+$/u;

        if (valNome.length < 2 || valNome.length > 40) {
            erroMsg.textContent = "O nome da comunidade deve ter entre 2 e 40 caracteres.";
            return;
        }

        if (!nomeRegex.test(valNome)) {
            erroMsg.textContent = "O nome da comunidade deve conter apenas letras, números e espaços.";
            return;
        }

        const dados = new FormData(formCriar);

        try {
            const resposta = await fetch("php/criar_comunidade.php", {
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

        let src = "https://ui-avatars.com/api/?name=" + urlencode(comunidade.nome) + "&background=random";
        if (comunidade.imagem && typeof comunidade.imagem === 'string' && comunidade.imagem.startsWith("uploads/")) {
            src = htmlspecialchars(comunidade.imagem);
        }

        const cargoTag = comunidade.cargo === 1 ? '<span class="cargo-admin-tag">admin</span>' : "";
        const safeId = parseInt(comunidade.id_comunidade, 10) || 0;

        item.innerHTML = `
            <img class="community-avatar" src="${src}" alt="Avatar">
            <a href="php/comunidade.php?id=${safeId}">${htmlspecialchars(comunidade.nome)}</a>
            ${cargoTag}
        `;
        listaComunidades.prepend(item);
    }

    async function carregarComunidades() {
        try {
            const resposta = await fetch("php/buscar_comunidades.php");
            const json = await resposta.json();

            if (json.sucesso && Array.isArray(json.comunidades)) {
                listaComunidades.innerHTML = "";
                json.comunidades.forEach(adicionarComunidadeNaLista);
            }
        } catch (erro) {
            console.error("Erro ao carregar comunidades:", erro);
        }
    }

    // Funções auxiliares para sanitização no JS
    function htmlspecialchars(str) {
        if (typeof str !== 'string') return str;
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
    }

    function urlencode(str) {
        return encodeURIComponent(str);
    }

    carregarComunidades();
});
