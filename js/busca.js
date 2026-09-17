/**
 * ARQUIVO: busca.js
 * DESCRIÇÃO: Sistema de busca dinâmica e interativa em tempo real
 * FUNCIONALIDADE:
 * - Pesquisa usuários e comunidades enquanto digita
 * - Suporte a filtros (Todos, Usuários, Comunidades)
 * - Navegação por teclado (arrow keys, enter, escape)
 * - Modais de preview de perfil
 * DEPENDÊNCIAS: API em php/pesquisar.php
 */

document.addEventListener("DOMContentLoaded", () => {
    // Detecta se o script está rodando dentro da pasta /php/ ou na raiz
    // Necessário para construir URLs corretas dos endpoints
    const currentPath = window.location.pathname || "";
    const isInsidePhpFolder = currentPath.includes("/php/") || currentPath.endsWith("/php");
    const searchEndpoint = isInsidePhpFolder ? "pesquisar.php" : "php/pesquisar.php";
    const userProfileEndpoint = isInsidePhpFolder ? "user_view.php" : "php/user_view.php";
    const communityEndpoint = isInsidePhpFolder ? "comunidade.php" : "php/comunidade.php";
    const profileEditEndpoint = isInsidePhpFolder ? "usr_edit.php" : "php/usr_edit.php";

    function normalizarUrlImagem(path) {
        if (!path) return "";
        const raw = String(path).trim();

        if (/^(https?:)?\/\//i.test(raw) || /^data:/i.test(raw)) {
            return raw;
        }

        let clean = raw.replace(/^\/+/, "").replace(/^\.\//, "").replace(/^\.\.\//, "");

        if (clean === "") return "";

        if (clean.startsWith("uploads/") || clean.startsWith("avatars/") || clean.startsWith("banners/") || clean.startsWith("style/") || clean.startsWith("php/") || clean.startsWith("js/")) {
            return isInsidePhpFolder ? `../${clean}` : clean;
        }

        if (clean.startsWith("../") || clean.startsWith("./")) {
            return clean;
        }

        return isInsidePhpFolder ? `../${clean}` : clean;
    }

    // ========================================================================
    // SEÇÃO 1: CAPTURA DE ELEMENTOS DO DOM
    // ========================================================================

    // Campo de entrada de busca
    const inputBusca = document.getElementById("input-busca");
    // Botão para limpar a busca
    const btnLimpar = document.getElementById("btn-limpar-busca");
    // Spinner de carregamento (exibido enquanto busca)
    const spinnerBusca = document.getElementById("busca-spinner");
    // Container que mostra os resultados
    const dropdownResultados = document.getElementById("busca-resultados-dropdown");
    // Container pai da barra de busca (para detectar cliques fora)
    const containerBusca = document.querySelector(".search-container");
    // Botões de filtro por tipo
    const tabsFiltro = document.querySelectorAll(".filter-tab-btn");

    // Modais para preview de usuário e comunidade
    const dialogUsuario = document.getElementById("dialog-ver-usuario");
    const dialogComunidade = document.getElementById("dialog-ver-comunidade");

    // Se não encontrar elemento crítico, encerra script
    if (!inputBusca || !dropdownResultados) return;

    // ========================================================================
    // SEÇÃO 2: VARIÁVEIS DE ESTADO
    // ========================================================================

    let tipoFiltro = "todos"; // 'todos' | 'usuarios' | 'comunidades'
    let debounceTimeout = null; // Timer para aguardar o usuário parar de digitar
    let abortController = null; // Controla requisições AJAX para cancelá-las
    let indexItemFocado = -1; // Índice do item atualmente focado via teclado
    let cacheResultados = { usuarios: [], comunidades: [] }; // Cache local dos resultados

    // ========================================================================
    // SEÇÃO 3: LISTENERS DOS FILTROS
    // ========================================================================

    // Percorre cada botão de filtro
    tabsFiltro.forEach(tab => {
        tab.addEventListener("click", () => {
            // Remove classe "active" de todos os filtros
            tabsFiltro.forEach(t => t.classList.remove("active"));
            // Adiciona "active" apenas no filtro clicado
            tab.classList.add("active");
            // Atualiza o tipo de filtro global
            tipoFiltro = tab.getAttribute("data-tipo") || "todos";

            // Se há texto no input, executa nova busca com o novo filtro
            const termo = inputBusca.value.trim();
            if (termo.length > 0) {
                executarBusca(termo);
            }
        });
    });

    // ========================================================================
    // SEÇÃO 4: LISTENERS DO INPUT DE BUSCA
    // ========================================================================

    // Listener para cada caractere digitado
    inputBusca.addEventListener("input", () => {
        const termo = inputBusca.value.trim();

        // Mostra/esconde botão limpar dependendo se há texto
        if (btnLimpar) {
            btnLimpar.classList.toggle("active", termo.length > 0);
        }

        // Se texto vazio, fecha dropdown
        if (termo.length === 0) {
            fecharDropdown();
            return;
        }

        // Debounce: aguarda 250ms após o usuário parar de digitar antes de fazer a busca
        // Evita requisições desnecessárias enquanto o usuário está digitando
        clearTimeout(debounceTimeout);
        debounceTimeout = setTimeout(() => {
            executarBusca(termo);
        }, 250);
    });

    // Quando o input recebe foco, reabre dropdown se há resultados
    inputBusca.addEventListener("focus", () => {
        const termo = inputBusca.value.trim();
        if (termo.length > 0 && dropdownResultados.innerHTML.trim() !== "") {
            dropdownResultados.classList.add("show");
        }
    });

    // ========================================================================
    // SEÇÃO 5: BOTÃO LIMPAR BUSCA
    // ========================================================================

    if (btnLimpar) {
        btnLimpar.addEventListener("click", () => {
            // Limpa o campo de entrada
            inputBusca.value = "";
            // Remove classe "active" do botão
            btnLimpar.classList.remove("active");
            // Fecha o dropdown de resultados
            fecharDropdown();
            // Retorna foco ao input (melhor UX)
            inputBusca.focus();
        });
    }

    // ========================================================================
    // SEÇÃO 6: FUNÇÃO PRINCIPAL - EXECUTAR BUSCA
    // ========================================================================

    /**
     * Função: executarBusca()
     * DESCRIÇÃO: Faz requisição AJAX ao servidor para buscar usuários e comunidades
     * PARÂMETROS:
     *   - termo (string): Termo de busca digitado pelo usuário
     */
    async function executarBusca(termo) {
        if (!termo || termo.length === 0) {
            fecharDropdown();
            return;
        }

        // Cancela requisição anterior se ainda estiver em progresso
        // Evita que resultados antigos sobrescrevam os novos
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();

        // Mostra spinner de carregamento
        mostrarSpinner(true);
        // Reseta indexação de itens focados por teclado
        indexItemFocado = -1;

        try {
            // Constrói URL com query parameters
            const url = `${searchEndpoint}?q=${encodeURIComponent(termo)}&tipo=${encodeURIComponent(tipoFiltro)}`;
            
            // Faz requisição AJAX
            const resposta = await fetch(url, {
                signal: abortController.signal // Permite cancelamento
            });

            // Converte resposta em JSON
            const dados = await resposta.json();
            // Esconde spinner
            mostrarSpinner(false);

            // Se a busca retornou erro
            if (!dados.sucesso) {
                renderizarMensagem(dados.mensagem || "Não foi possível realizar a busca.", "error");
                return;
            }

            // Cache os resultados localmente (para reutilizar se necessário)
            cacheResultados = {
                usuarios: dados.usuarios || [],
                comunidades: dados.comunidades || []
            };

            // Renderiza os resultados no dropdown
            renderizarResultados(dados, termo);
        } catch (erro) {
            // Se não foi uma tentativa de cancelamento (AbortError)
            if (erro.name !== "AbortError") {
                mostrarSpinner(false);
                renderizarMensagem("Erro de conexão ao buscar. Tente novamente.", "error");
            }
        }
    }

    // ========================================================================
    // SEÇÃO 7: RENDERIZAÇÃO DOS RESULTADOS
    // ========================================================================

    /**
     * Função: renderizarResultados()
     * DESCRIÇÃO: Monta o HTML do dropdown com os resultados de busca
     * PARÂMETROS:
     *   - dados (object): JSON retornado pelo servidor
     *   - termo (string): Termo buscado (para destacar nas correspondências)
     */
    function renderizarResultados(dados, termo) {
        // Limpa conteúdo anterior
        dropdownResultados.innerHTML = "";
        // Conta total de resultados
        const total = (dados.usuarios?.length || 0) + (dados.comunidades?.length || 0);

        // Se nenhum resultado encontrado
        if (total === 0) {
            renderizarMensagem(`Nenhum resultado encontrado para "<strong>${escapeHtml(termo)}</strong>".`);
            dropdownResultados.classList.add("show");
            return;
        }

        // ====================================================================
        // SEÇÃO 7A: RENDERIZAÇÃO DE USUÁRIOS
        // ====================================================================

        if (dados.usuarios && dados.usuarios.length > 0) {
            // Cria container para seção de usuários
            const secaoUsr = document.createElement("div");
            secaoUsr.className = "search-section search-section-usuarios";
            secaoUsr.innerHTML = `
                <div class="search-section-header" style="background:#edf2ff; color:#4b5f82; border-radius:12px;">
                    <span>Usuários</span>
                    <span class="search-section-count" style="background:rgba(255,255,255,0.5); color:#4b5f82;">${dados.usuarios.length}</span>
                </div>
                <ul class="search-items-list" id="lista-usuarios-busca"></ul>
            `;

            const listaUsr = secaoUsr.querySelector("#lista-usuarios-busca");
            
            // Percorre cada usuário encontrado
            dados.usuarios.forEach(user => {
                const li = document.createElement("li");
                li.className = "search-result-item";
                li.setAttribute("data-tipo", "usuario");
                li.setAttribute("data-id", user.id_usuario);

                const usuarioDeletado = !!user && (
                    user.nome_de_exibicao === "Usuário deletado" ||
                    String(user.nome_de_usuario || "").startsWith("usuario_deletado_")
                );

                // Destaca o termo buscado no nome e usuário
                const nomeExibicaoDestacado = destacarTermo(user.nome_de_exibicao, termo);
                const nomeUsuarioDestacado = destacarTermo(`@${user.nome_de_usuario}`, termo);
                // Limita descrição (snippet)
                const descSnippet = user.descricao ? escapeHtml(user.descricao) : "Sem descrição no perfil.";

                // Monta HTML do item
                const avatarUsuario = normalizarUrlImagem(user.foto_perfil || "");

                li.innerHTML = `
                    <img class="search-item-avatar" src="${escapeHtml(avatarUsuario)}" alt="${escapeHtml(user.nome_de_exibicao)}">
                    <div class="search-item-info">
                        <div class="search-item-title-row">
                            <span class="search-item-title">${nomeExibicaoDestacado}</span>
                            <span class="search-item-badge badge-user">Usuário</span>
                        </div>
                        <span class="search-item-subtitle">${nomeUsuarioDestacado}</span>
                        <span class="search-item-extra">${descSnippet}</span>
                    </div>
                `;

                if (usuarioDeletado) {
                    li.style.pointerEvents = "none";
                    li.style.opacity = "0.7";
                    li.style.cursor = "default";
                } else {
                    // Clica no item: navega para perfil do usuário
                    li.addEventListener("click", () => abrirPerfilUsuario(user));
                }
                listaUsr.appendChild(li);
            });

            dropdownResultados.appendChild(secaoUsr);
        }

        // ====================================================================
        // SEÇÃO 7B: RENDERIZAÇÃO DE COMUNIDADES
        // ====================================================================

        if (dados.comunidades && dados.comunidades.length > 0) {
            // Cria container para seção de comunidades
            const secaoComu = document.createElement("div");
            secaoComu.className = "search-section search-section-comunidades";
            secaoComu.innerHTML = `
                <div class="search-section-header" style="background:#6d99e4; color:#ffffff; border-radius:12px;">
                    <span>Comunidades</span>
                    <span class="search-section-count" style="background:rgba(255,255,255,0.22); color:#ffffff;">${dados.comunidades.length}</span>
                </div>
                <ul class="search-items-list" id="lista-comunidades-busca"></ul>
            `;

            const listaComu = secaoComu.querySelector("#lista-comunidades-busca");

            // Percorre cada comunidade encontrada
            dados.comunidades.forEach(comu => {
                const li = document.createElement("li");
                li.className = "search-result-item";
                li.setAttribute("data-tipo", "comunidade");
                li.setAttribute("data-id", comu.id_comunidade);

                // Destaca o termo no nome
                const nomeComuDestacado = destacarTermo(comu.nome, termo);
                const descSnippet = comu.descricao ? escapeHtml(comu.descricao) : "Sem descrição.";
                // Formata quantidade de membros
                const qtdMembros = comu.total_membros === 1 ? "1 membro" : `${comu.total_membros} membros`;

                // Monta HTML do item
                const avatarComu = normalizarUrlImagem(comu.imagem || "");

                li.innerHTML = `
                    <img class="search-item-avatar" src="${escapeHtml(avatarComu)}" alt="${escapeHtml(comu.nome)}">
                    <div class="search-item-info">
                        <div class="search-item-title-row">
                            <span class="search-item-title">${nomeComuDestacado}</span>
                            <span class="search-item-badge badge-comu">Comunidade</span>
                        </div>
                        <span class="search-item-subtitle">${descSnippet}</span>
                        <span class="search-item-extra">${qtdMembros}</span>
                    </div>
                `;

                // Clica no item: abre modal de preview
                li.addEventListener("click", () => abrirModalComunidade(comu));
                listaComu.appendChild(li);
            });

            dropdownResultados.appendChild(secaoComu);
        }

        // Mostra o dropdown
        dropdownResultados.classList.add("show");
    }

    // ========================================================================
    // SEÇÃO 8: RENDERIZAÇÃO DE MENSAGENS (VAZIO / ERRO)
    // ========================================================================

    /**
     * Função: renderizarMensagem()
     * DESCRIÇÃO: Exibe mensagem centralizada no dropdown (ex: sem resultados, erro)
     * PARÂMETROS:
     *   - msgHtml (string): Mensagem em HTML
     *   - classe (string): Classe CSS ('error' para erro, vazio para padrão)
     */
    function renderizarMensagem(msgHtml, classe = "") {
        dropdownResultados.innerHTML = `
            <div class="search-message ${classe}">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <span>${msgHtml}</span>
            </div>
        `;
        dropdownResultados.classList.add("show");
    }

    // ========================================================================
    // SEÇÃO 9: FUNÇÕES DE NAVEGAÇÃO E MODAIS
    // ========================================================================

    /**
     * Função: abrirPerfilUsuario()
     * DESCRIÇÃO: Navega para página de perfil do usuário
     * PARÂMETROS:
     *   - user (object): Dados do usuário
     */
    function abrirPerfilUsuario(user) {
        if (!user || !user.id_usuario) return;
        const usuarioDeletado = user.nome_de_exibicao === "Usuário deletado" || String(user.nome_de_usuario || "").startsWith("usuario_deletado_");
        if (usuarioDeletado) return;
        fecharDropdown();
        window.location.href = `${userProfileEndpoint}?id=${encodeURIComponent(user.id_usuario)}`;
    }

    /**
     * Função: abrirModalComunidade()
     * DESCRIÇÃO: Abre modal com preview das informações da comunidade
     * PARÂMETROS:
     *   - comu (object): Dados da comunidade
     */
    function abrirModalComunidade(comu) {
        if (!dialogComunidade) return;

        // Seleciona elementos do modal
        const elAvatar = dialogComunidade.querySelector(".comu-modal-avatar");
        const elTitle = dialogComunidade.querySelector(".comu-modal-title");
        const elMeta = dialogComunidade.querySelector(".comu-modal-meta");
        const elDesc = dialogComunidade.querySelector(".comu-modal-desc");
        const actions = dialogComunidade.querySelector(".comu-modal-actions");

        // Preenche dados no modal
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

        // Cria botão de acesso à comunidade se não existir
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
        // Abre o modal
        dialogComunidade.showModal();
    }

    // ========================================================================
    // SEÇÃO 10: FUNÇÕES DE GERENCIAMENTO DE UI
    // ========================================================================

    /**
     * Função: fecharDropdown()
     * DESCRIÇÃO: Fecha o dropdown de resultados
     */
    function fecharDropdown() {
        dropdownResultados.classList.remove("show");
        indexItemFocado = -1;
    }

    /**
     * Função: mostrarSpinner()
     * DESCRIÇÃO: Mostra ou esconde o spinner de carregamento
     * PARÂMETROS:
     *   - mostrar (boolean): true = mostrar, false = esconder
     */
    function mostrarSpinner(mostrar) {
        if (spinnerBusca) {
            spinnerBusca.classList.toggle("active", mostrar);
        }
    }

    // ========================================================================
    // SEÇÃO 11: FECHAR DROPDOWN AO CLICAR FORA
    // ========================================================================

    document.addEventListener("click", (event) => {
        // Se clicou fora da área de busca, fecha dropdown
        if (containerBusca && !containerBusca.contains(event.target)) {
            fecharDropdown();
        }
    });

    // ========================================================================
    // SEÇÃO 12: FECHAR MODAIS AO CLICAR NO BACKDROP
    // ========================================================================

    // Fecha modais quando clicar fora deles ou em botão "Fechar"
    [dialogUsuario, dialogComunidade].forEach(dialog => {
        if (!dialog) return;

        // Controla se o pointerdown começou dentro ou fora do modal
        let pointerDownInsideContent = false;
        let pointerDownOnBackdrop = false;

        document.addEventListener('pointerdown', (e) => {
            pointerDownOnBackdrop = (e.target === dialog);
            pointerDownInsideContent = (e.target.closest && e.target.closest('dialog') === dialog && e.target !== dialog);
        });

        window.addEventListener('pointerup', () => { 
            pointerDownInsideContent = false; 
            pointerDownOnBackdrop = false; 
        });

        // Fecha modal ao clicar no backdrop
        dialog.addEventListener("click", (event) => {
            if (event.target === dialog && (pointerDownOnBackdrop || !pointerDownInsideContent)) {
                dialog.close();
            }
        });

        // Fecha modal ao clicar em botões "Fechar"
        const btnFechar = dialog.querySelectorAll(".btn-modal-fechar");
        btnFechar.forEach(b => b.addEventListener("click", () => dialog.close()));
    });

    // ========================================================================
    // SEÇÃO 13: NAVEGAÇÃO POR TECLADO
    // ========================================================================

    /**
     * Teclado:
     * - ArrowDown: Navega até próximo item
     * - ArrowUp: Navega para item anterior
     * - Enter: Clica no item focado
     * - Escape: Fecha dropdown
     */
    inputBusca.addEventListener("keydown", (e) => {
        const itens = dropdownResultados.querySelectorAll(".search-result-item");
        
        // Se dropdown não está aberto ou não há itens, só processa Escape
        if (!dropdownResultados.classList.contains("show") || itens.length === 0) {
            if (e.key === "Escape") fecharDropdown();
            return;
        }

        if (e.key === "ArrowDown") {
            // Move foco para próximo item (circular)
            e.preventDefault();
            indexItemFocado = (indexItemFocado + 1) % itens.length;
            atualizarItemFocado(itens);
        } else if (e.key === "ArrowUp") {
            // Move foco para item anterior (circular)
            e.preventDefault();
            indexItemFocado = (indexItemFocado - 1 + itens.length) % itens.length;
            atualizarItemFocado(itens);
        } else if (e.key === "Enter") {
            // Clica no item focado
            e.preventDefault();
            if (indexItemFocado >= 0 && indexItemFocado < itens.length) {
                itens[indexItemFocado].click();
            }
        } else if (e.key === "Escape") {
            // Fecha dropdown
            fecharDropdown();
        }
    });

    /**
     * Função: atualizarItemFocado()
     * DESCRIÇÃO: Atualiza visual do item focado e scrolla para ele
     * PARÂMETROS:
     *   - itens (NodeList): Lista de elementos de resultado
     */
    function atualizarItemFocado(itens) {
        itens.forEach((item, idx) => {
            // Adiciona/remove classe "highlighted" do item correspondente
            item.classList.toggle("highlighted", idx === indexItemFocado);
            
            // Scrolla até o item focado se necessário
            if (idx === indexItemFocado) {
                item.scrollIntoView({ block: "nearest" });
            }
        });
    }

    // ========================================================================
    // SEÇÃO 14: FUNÇÕES UTILITÁRIAS
    // ========================================================================

    /**
     * Função: escapeHtml()
     * DESCRIÇÃO: Escapa caracteres especiais HTML para evitar XSS
     * PARÂMETROS:
     *   - texto (string): Texto a escapar
     * RETORNA: String com caracteres escapados
     */
    function escapeHtml(texto) {
        if (typeof texto !== 'string') return "";
        return texto.replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
    }

    /**
     * Função: destacarTermo()
     * DESCRIÇÃO: Destaca o termo buscado com tag <mark> no texto
     * PARÂMETROS:
     *   - textoOriginal (string): Texto onde destacar
     *   - termo (string): Termo a destacar
     * RETORNA: HTML com termo em <mark> tags
     */
    function destacarTermo(textoOriginal, termo) {
        if (!textoOriginal || typeof textoOriginal !== 'string') return "";
        if (!termo || typeof termo !== 'string') return escapeHtml(textoOriginal);

        // Escapa caracteres especiais do regex
        const termoEscapadoRegex = termo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        // Cria regex insensível a maiúsculas/minúsculas
        const regex = new RegExp(`(${termoEscapadoRegex})`, 'gi');

        // Divide o texto pelo termo
        const partes = textoOriginal.split(regex);
        
        // Monta HTML com <mark> tags nos termos encontrados
        return partes.map(parte => {
            if (parte.toLowerCase() === termo.toLowerCase()) {
                return `<mark class="search-highlight">${escapeHtml(parte)}</mark>`;
            }
            return escapeHtml(parte);
        }).join('');
    }

    /**
     * Função: formatarData()
     * DESCRIÇÃO: Converte data SQL (YYYY-MM-DD) para formato brasileiro (DD/MM/YYYY)
     * PARÂMETROS:
     *   - dataSql (string): Data em formato SQL
     * RETORNA: String com data formatada
     */
    function formatarData(dataSql) {
        if (!dataSql) return "";
        const partes = dataSql.split("-");
        if (partes.length === 3) {
            return `${partes[2]}/${partes[1]}/${partes[0]}`;
        }
        return dataSql;
    }
});
// ============================================================================
// FIM DO ARQUIVO busca.js
// ============================================================================
