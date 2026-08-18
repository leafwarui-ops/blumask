/**
 * BluMask - Sistema de Busca Dinâmica e Interativa
 * Pesquisa em tempo real de usuários e comunidades com filtros e modais de detalhes.
 */

document.addEventListener("DOMContentLoaded", () => {
    const currentPath = window.location.pathname || "";
    const isInsidePhpFolder = currentPath.includes("/php/") || currentPath.endsWith("/php");
    const searchEndpoint = isInsidePhpFolder ? "pesquisar.php" : "php/pesquisar.php";
    const userProfileEndpoint = isInsidePhpFolder ? "user_view.php" : "php/user_view.php";
    const communityEndpoint = isInsidePhpFolder ? "comunidade.php" : "php/comunidade.php";
    const profileEditEndpoint = isInsidePhpFolder ? "usr_edit.php" : "php/usr_edit.php";

    const inputBusca = document.getElementById("input-busca");
    const btnLimpar = document.getElementById("btn-limpar-busca");
    const spinnerBusca = document.getElementById("busca-spinner");
    const dropdownResultados = document.getElementById("busca-resultados-dropdown");
    const containerBusca = document.querySelector(".search-container");
    const tabsFiltro = document.querySelectorAll(".filter-tab-btn");

    const dialogUsuario = document.getElementById("dialog-ver-usuario");
    const dialogComunidade = document.getElementById("dialog-ver-comunidade");

    if (!inputBusca || !dropdownResultados) return;

    let tipoFiltro = "todos"; // 'todos' | 'usuarios' | 'comunidades'
    let debounceTimeout = null;
    let abortController = null;
    let indexItemFocado = -1;
    let cacheResultados = { usuarios: [], comunidades: [] };

    // 1. Alternância de Filtros
    tabsFiltro.forEach(tab => {
        tab.addEventListener("click", () => {
            tabsFiltro.forEach(t => t.classList.remove("active"));
            tab.classList.add("active");
            tipoFiltro = tab.getAttribute("data-tipo") || "todos";

            const termo = inputBusca.value.trim();
            if (termo.length > 0) {
                executarBusca(termo);
            }
        });
    });

    // 2. Eventos de Digitação e Foco no Input
    inputBusca.addEventListener("input", () => {
        const termo = inputBusca.value.trim();

        if (btnLimpar) {
            btnLimpar.classList.toggle("active", termo.length > 0);
        }

        if (termo.length === 0) {
            fecharDropdown();
            return;
        }

        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => {
            executarBusca(termo);
        }, 250);
    });

    inputBusca.addEventListener("focus", () => {
        const termo = inputBusca.value.trim();
        if (termo.length > 0 && dropdownResultados.innerHTML.trim() !== "") {
            dropdownResultados.classList.add("show");
        }
    });

    // 3. Botão Limpar
    if (btnLimpar) {
        btnLimpar.addEventListener("click", () => {
            inputBusca.value = "";
            btnLimpar.classList.remove("active");
            fecharDropdown();
            inputBusca.focus();
        });
    }

    // 4. Execução da Requisição AJAX
    async function executarBusca(termo) {
        if (!termo || termo.length === 0) {
            fecharDropdown();
            return;
        }

        // Cancela requisição anterior em andamento
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        mostrarSpinner(true);
        indexItemFocado = -1;

        try {
            const url = `${searchEndpoint}?q=${encodeURIComponent(termo)}&tipo=${encodeURIComponent(tipoFiltro)}`;
            const resposta = await fetch(url, {
                signal: abortController.signal
            });

            const dados = await resposta.json();
            mostrarSpinner(false);

            if (!dados.sucesso) {
                renderizarMensagem(dados.mensagem || "Não foi possível realizar a busca.", "error");
                return;
            }

            cacheResultados = {
                usuarios: dados.usuarios || [],
                comunidades: dados.comunidades || []
            };

            renderizarResultados(dados, termo);
        } catch (erro) {
            if (erro.name !== "AbortError") {
                mostrarSpinner(false);
                renderizarMensagem("Erro de conexão ao buscar. Tente novamente.", "error");
            }
        }
    }

    // 5. Renderização dos Resultados no Dropdown
    function renderizarResultados(dados, termo) {
        dropdownResultados.innerHTML = "";
        const total = (dados.usuarios?.length || 0) + (dados.comunidades?.length || 0);

        if (total === 0) {
            renderizarMensagem(`Nenhum resultado encontrado para "<strong>${escapeHtml(termo)}</strong>".`);
            dropdownResultados.classList.add("show");
            return;
        }

        // Seção Usuários
        if (dados.usuarios && dados.usuarios.length > 0) {
            const secaoUsr = document.createElement("div");
            secaoUsr.className = "search-section";
            secaoUsr.innerHTML = `
                <div class="search-section-header">
                    <span>Usuários</span>
                    <span class="search-section-count">${dados.usuarios.length}</span>
                </div>
                <ul class="search-items-list" id="lista-usuarios-busca"></ul>
            `;

            const listaUsr = secaoUsr.querySelector("#lista-usuarios-busca");
            dados.usuarios.forEach(user => {
                const li = document.createElement("li");
                li.className = "search-result-item";
                li.setAttribute("data-tipo", "usuario");
                li.setAttribute("data-id", user.id_usuario);

                const nomeExibicaoDestacado = destacarTermo(user.nome_de_exibicao, termo);
                const nomeUsuarioDestacado = destacarTermo(`@${user.nome_de_usuario}`, termo);
                const descSnippet = user.descricao ? escapeHtml(user.descricao) : "Sem descrição no perfil.";

                li.innerHTML = `
                    <img class="search-item-avatar" src="${escapeHtml(user.foto_perfil)}" alt="${escapeHtml(user.nome_de_exibicao)}">
                    <div class="search-item-info">
                        <div class="search-item-title-row">
                            <span class="search-item-title">${nomeExibicaoDestacado}</span>
                            <span class="search-item-badge badge-user">Usuário</span>
                        </div>
                        <span class="search-item-subtitle">${nomeUsuarioDestacado}</span>
                        <span class="search-item-extra">${descSnippet}</span>
                    </div>
                `;

                li.addEventListener("click", () => abrirPerfilUsuario(user));
                listaUsr.appendChild(li);
            });

            dropdownResultados.appendChild(secaoUsr);
        }

        // Seção Comunidades
        if (dados.comunidades && dados.comunidades.length > 0) {
            const secaoComu = document.createElement("div");
            secaoComu.className = "search-section";
            secaoComu.innerHTML = `
                <div class="search-section-header">
                    <span>Comunidades</span>
                    <span class="search-section-count">${dados.comunidades.length}</span>
                </div>
                <ul class="search-items-list" id="lista-comunidades-busca"></ul>
            `;

            const listaComu = secaoComu.querySelector("#lista-comunidades-busca");
            dados.comunidades.forEach(comu => {
                const li = document.createElement("li");
                li.className = "search-result-item";
                li.setAttribute("data-tipo", "comunidade");
                li.setAttribute("data-id", comu.id_comunidade);

                const nomeComuDestacado = destacarTermo(comu.nome, termo);
                const descSnippet = comu.descricao ? escapeHtml(comu.descricao) : "Sem descrição.";
                const qtdMembros = comu.total_membros === 1 ? "1 membro" : `${comu.total_membros} membros`;

                li.innerHTML = `
                    <img class="search-item-avatar" src="${escapeHtml(comu.imagem)}" alt="${escapeHtml(comu.nome)}">
                    <div class="search-item-info">
                        <div class="search-item-title-row">
                            <span class="search-item-title">${nomeComuDestacado}</span>
                            <span class="search-item-badge badge-comu">Comunidade</span>
                        </div>
                        <span class="search-item-subtitle">${descSnippet}</span>
                        <span class="search-item-extra">${qtdMembros}</span>
                    </div>
                `;

                li.addEventListener("click", () => abrirModalComunidade(comu));
                listaComu.appendChild(li);
            });

            dropdownResultados.appendChild(secaoComu);
        }

        dropdownResultados.classList.add("show");
    }

    // 6. Mensagem de Estado (Vazio / Erro)
    function renderizarMensagem(msgHtml, classe = "") {
        dropdownResultados.innerHTML = `
            <div class="search-message ${classe}">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <span>${msgHtml}</span>
            </div>
        `;
        dropdownResultados.classList.add("show");
    }

    // 7. Modais de Visualização Detalhada
    function abrirPerfilUsuario(user) {
        if (!user || !user.id_usuario) return;
        fecharDropdown();
        window.location.href = `${userProfileEndpoint}?id=${encodeURIComponent(user.id_usuario)}`;
    }

    function abrirModalUsuario(user) {
        if (!dialogUsuario) return;

        const elHeader = dialogUsuario.querySelector(".user-modal-header");
        const elAvatar = dialogUsuario.querySelector(".user-modal-avatar img");
        const elNome = dialogUsuario.querySelector(".user-modal-body h3");
        const elHandle = dialogUsuario.querySelector(".user-handle");
        const elBio = dialogUsuario.querySelector(".user-bio");
        const actions = dialogUsuario.querySelector(".user-modal-actions");

        if (elHeader) {
            elHeader.style.backgroundImage = user.banner ? `url('${escapeHtml(user.banner)}')` : "none";
        }
        if (elAvatar) {
            elAvatar.src = user.foto_perfil || `https://ui-avatars.com/api/?name=${encodeURIComponent(user.nome_de_exibicao || 'User')}&background=random`;
        }
        if (elNome) {
            elNome.textContent = user.nome_de_exibicao || "Usuário";
        }
        if (elHandle) {
            elHandle.textContent = `@${user.nome_de_usuario || ''}`;
        }
        if (elBio) {
            elBio.textContent = user.descricao ? user.descricao : "Este usuário ainda não adicionou uma biografia.";
        }

        const currentUserId = parseInt(document.body.getAttribute("data-id-usuario"), 10) || 0;
        if (actions) {
            let btnEditar = actions.querySelector(".btn-modal-acao-editar");
            if (currentUserId > 0 && user.id_usuario === currentUserId) {
                if (!btnEditar) {
                    btnEditar = document.createElement("a");
                    btnEditar.className = "btn-modal-acao btn-modal-acao-editar";
                    btnEditar.href = profileEditEndpoint;
                    btnEditar.textContent = "Editar Perfil";
                    actions.prepend(btnEditar);
                }
            } else if (btnEditar) {
                btnEditar.remove();
            }
        }

        fecharDropdown();
        dialogUsuario.showModal();
    }

    function abrirModalComunidade(comu) {
        if (!dialogComunidade) return;

        const elAvatar = dialogComunidade.querySelector(".comu-modal-avatar");
        const elTitle = dialogComunidade.querySelector(".comu-modal-title");
        const elMeta = dialogComunidade.querySelector(".comu-modal-meta");
        const elDesc = dialogComunidade.querySelector(".comu-modal-desc");
        const actions = dialogComunidade.querySelector(".comu-modal-actions");

        if (elAvatar) {
            elAvatar.src = comu.imagem || `https://ui-avatars.com/api/?name=${encodeURIComponent(comu.nome || 'Comunidade')}&background=random`;
        }
        if (elTitle) {
            elTitle.textContent = comu.nome || "Comunidade";
        }
        if (elMeta) {
            const dataStr = comu.data_criacao ? `Criada em ${formatarData(comu.data_criacao)} • ` : "";
            const membrosStr = comu.total_membros === 1 ? "1 membro" : `${comu.total_membros} membros`;
            elMeta.textContent = `${dataStr}${membrosStr}`;
        }
        if (elDesc) {
            elDesc.textContent = comu.descricao ? comu.descricao : "Esta comunidade ainda não possui uma descrição detalhada.";
        }

        if (actions) {
            let btnAcessar = actions.querySelector(".btn-modal-acao-comunidade");
            if (!btnAcessar) {
                btnAcessar = document.createElement("a");
                btnAcessar.className = "btn-modal-acao btn-modal-acao-comunidade";
                btnAcessar.textContent = "Acessar";
                actions.prepend(btnAcessar);
            }
            btnAcessar.href = `${communityEndpoint}?id=${parseInt(comu.id_comunidade, 10) || 0}`;
        }

        fecharDropdown();
        dialogComunidade.showModal();
    }

    // 8. Fecha Modais e Dropdowns
    function fecharDropdown() {
        dropdownResultados.classList.remove("show");
        indexItemFocado = -1;
    }

    function mostrarSpinner(mostrar) {
        if (spinnerBusca) {
            spinnerBusca.classList.toggle("active", mostrar);
        }
    }

    // Fechar ao clicar fora da barra de busca e dropdown
    document.addEventListener("click", (event) => {
        if (containerBusca && !containerBusca.contains(event.target)) {
            fecharDropdown();
        }
    });

    // Fechar modais ao clicar no backdrop ou botões fechar
    [dialogUsuario, dialogComunidade].forEach(dialog => {
        if (!dialog) return;

        dialog.addEventListener("click", (event) => {
            const rect = dialog.getBoundingClientRect();
            if (
                event.clientX < rect.left ||
                event.clientX > rect.right ||
                event.clientY < rect.top ||
                event.clientY > rect.bottom
            ) {
                dialog.close();
            }
        });

        const btnFechar = dialog.querySelectorAll(".btn-modal-fechar");
        btnFechar.forEach(b => b.addEventListener("click", () => dialog.close()));
    });

    // 9. Navegação por Teclado (Up, Down, Enter, Escape)
    inputBusca.addEventListener("keydown", (e) => {
        const itens = dropdownResultados.querySelectorAll(".search-result-item");
        if (!dropdownResultados.classList.contains("show") || itens.length === 0) {
            if (e.key === "Escape") fecharDropdown();
            return;
        }

        if (e.key === "ArrowDown") {
            e.preventDefault();
            indexItemFocado = (indexItemFocado + 1) % itens.length;
            atualizarItemFocado(itens);
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            indexItemFocado = (indexItemFocado - 1 + itens.length) % itens.length;
            atualizarItemFocado(itens);
        } else if (e.key === "Enter") {
            e.preventDefault();
            if (indexItemFocado >= 0 && indexItemFocado < itens.length) {
                itens[indexItemFocado].click();
            }
        } else if (e.key === "Escape") {
            fecharDropdown();
        }
    });

    function atualizarItemFocado(itens) {
        itens.forEach((item, idx) => {
            item.classList.toggle("highlighted", idx === indexItemFocado);
            if (idx === indexItemFocado) {
                item.scrollIntoView({ block: "nearest" });
            }
        });
    }

    // 10. Funções Utilitárias de Texto e Sanitização
    function escapeHtml(texto) {
        if (typeof texto !== 'string') return "";
        return texto.replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
    }

    function destacarTermo(textoOriginal, termo) {
        if (!textoOriginal || typeof textoOriginal !== 'string') return "";
        if (!termo || typeof termo !== 'string') return escapeHtml(textoOriginal);

        const termoEscapadoRegex = termo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const regex = new RegExp(`(${termoEscapadoRegex})`, 'gi');

        const partes = textoOriginal.split(regex);
        return partes.map(parte => {
            if (parte.toLowerCase() === termo.toLowerCase()) {
                return `<mark class="search-highlight">${escapeHtml(parte)}</mark>`;
            }
            return escapeHtml(parte);
        }).join('');
    }

    function formatarData(dataSql) {
        if (!dataSql) return "";
        const partes = dataSql.split("-");
        if (partes.length === 3) {
            return `${partes[2]}/${partes[1]}/${partes[0]}`;
        }
        return dataSql;
    }
});
