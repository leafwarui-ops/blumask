//objeto dos itens do menu
let MenuItens =[
            {"tag":"input","name":"popup-mode","id":"popup","type":"hidden","value":"0"},
            {"tag":"label","conteudo":"Email:"},
            {"tag":"input","name":"email","id":"email","type":"email","value":"","placeholder":"seu@email.com","required":true},
            {"tag":"label","conteudo":"Senha:"},
            {"tag":"input","name":"senha","id":"senha","type":"password","value":"","placeholder":"Sua senha","required":true},
            {"tag":"input","name":"entrar","id":"entrar","type":"submit","value":"entrar"}
            ]


//função que adiciona os itens no menu popup
function escrever(objeto,elementos)
{
    for (const element of elementos) {
        if (!element) continue;
        
        switch(element.tag)
        {
            case "label":
                {
                    const novo = document.createElement(element.tag);//login
                    novo.textContent = element.conteudo;
                    objeto.appendChild(novo);
                    objeto.appendChild(document.createElement("br"));
                    break;
                }
            case "input":
                {
                    const novo = document.createElement(element.tag);
                    novo.name = element.name;
                    novo.id = element.id;
                    novo.type = element.type;
                    novo.value = element.value;
                    if(element.pattern) novo.pattern = element.pattern;
                    if(element.title) novo.title = element.title;
                    if(element.placeholder) novo.placeholder = element.placeholder;
                    if(element.minlength) novo.minLength = element.minlength;
                    if(element.maxlength) novo.maxLength = element.maxlength;
                    if(element.required) novo.required = element.required;
                    objeto.appendChild(novo);
                    objeto.appendChild(document.createElement("br"));
                    break;
                }
        }
        
    }
}

function trocar(popup,objeto)
    {
        if (popup == 0)
        {
            MenuItens =[
            {"tag":"input","name":"popup-mode","id":"popup","type":"hidden","value":"0"},
            {"tag":"label","conteudo":"Email:"},
            {"tag":"input","name":"email","id":"email","type":"email","value":"","placeholder":"seu@email.com","required":true},
            {"tag":"label","conteudo":"Senha:"},
            {"tag":"input","name":"senha","id":"senha","type":"password","value":"","placeholder":"Sua senha","required":true},
            {"tag":"input","name":"entrar","id":"entrar","type":"submit","value":"entrar"}
            ]
        }
        else
            {
                MenuItens =[
                {"tag":"input","name":"popup-mode","id":"popup","type":"hidden","value":"1"},
                {"tag":"label","conteudo":"Nome de exibição:"},
                {"tag":"input","name":"nome_exb","id":"nome_exibicao","type":"text","value":"","minlength":2,"maxlength":10,"placeholder":"Mínimo de 2 caracteres","required":true,"title":"O nome de exibição deve ter entre 2 e 10 caracteres."},
                {"tag":"label","conteudo":"Nome de usuário:"},
                {"tag":"input","name":"nome_usr","id":"nome_usuario","type":"text","value":"","minlength":4,"maxlength":20,"placeholder":"Mínimo de 4 caracteres","required":true,"title":"O nome de usuário deve ter entre 4 e 20 caracteres."},
                {"tag":"label","conteudo":"Email:"},
                {"tag":"input","name":"email","id":"email","type":"email","value":"","placeholder":"seu@email.com","required":true},
                {"tag":"label","conteudo":"Senha:"},
                {"tag":"input","name":"senha","id":"senha","type":"password","value":"","minlength":8,"maxlength":32,"placeholder":"Mínimo de 8 caracteres","required":true,"pattern":"^(?=.*[A-Z])(?=.*[\\W_]).{8,32}$","title":"A senha deve ter entre 8 e 32 caracteres, contendo pelo menos uma letra maiúscula e um símbolo/caractere especial."},
                {"tag":"input","name":"cadastrar","id":"cadastrar","type":"submit","value":"cadastrar"}
                ]
            }
        escrever(objeto,MenuItens);
    }