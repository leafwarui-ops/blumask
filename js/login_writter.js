//objeto dos itens do menu
let MenuItens =[
            {"tag":"input","name":"popup-mode","id":"popup","type":"hidden","value":"0"},
            {"tag":"label","conteudo":"Email:"},
            {"tag":"input","name":"email","id":"email","type":"text","value":""},
            {"tag":"label","conteudo":"Senha:"},
            {"tag":"input","name":"senha","id":"senha","type":"password","value":""},
            {"tag":"input","name":"entrar","id":"entrar","type":"submit","value":"entrar"}
            ]


//função que adiciona os itens no menu popup
function escrever(objeto,elementos)
{
    for (const valor in elementos) {
        if (!Object.hasOwn(elementos, valor)) continue;
        
        const element = elementos[valor];
        
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
            {"tag":"input","name":"email","id":"email","type":"text","value":""},
            {"tag":"label","conteudo":"Email:"},
            {"tag":"input","name":"email","id":"email","type":"text","value":""},
            {"tag":"label","conteudo":"Senha:"},
            {"tag":"input","name":"senha","id":"senha","type":"password","value":""},
            {"tag":"input","name":"entrar","id":"entrar","type":"submit","value":"entrar"}
            ]
        }
        else
            {
                //place holder
                MenuItens =[
                {"tag":"input","name":"popup-mode","id":"popup","type":"hidden","value":"1"},
                {"tag":"label","conteudo":"Nome de exibição:"},
                {"tag":"input","name":"nome_exb","id":"nome_exibicao","type":"text","value":""},
                {"tag":"label","conteudo":"Nome de usuario:"},
                {"tag":"input","name":"nome_usr","id":"nome_usuario","type":"text","value":""},
                {"tag":"label","conteudo":"Email:"},
                {"tag":"input","name":"email","id":"email","type":"text","value":""},
                {"tag":"label","conteudo":"Senha:"},
                {"tag":"input","name":"senha","id":"senha","type":"password","value":""},
                {"tag":"input","name":"cadastrar","id":"cadastrar","type":"submit","value":"cadastrar"}
                ]
            }
        escrever(objeto,MenuItens);
    }