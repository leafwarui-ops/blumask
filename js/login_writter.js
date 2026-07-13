//objeto dos itens do menu
let MenuItens =[
            {"tag":"label","conteudo":"Email:"},
            {"tag":"input","name":"email","id":"email","type":"text"},
            {"tag":"label","conteudo":"Senha:"},
            {"tag":"input","name":"senha","id":"senha","type":"password"}
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
                    const novo = document.createElement(element.tag);
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
            {"tag":"label","conteudo":"Email:"},
            {"tag":"input","name":"email","id":"email","type":"text"},
            {"tag":"label","conteudo":"Senha:"},
            {"tag":"input","name":"senha","id":"senha","type":"password"}
            ]
        }
        else
            {
                //place holder
                MenuItens =[
                {"tag":"label","conteudo":"Nome de exibição:"},
                {"tag":"input","name":"nome_exibi","id":"nome_exibicao","type":"text"},
                {"tag":"label","conteudo":"Nome de usuario:"},
                {"tag":"input","name":"nome_usu","id":"nome_usuario","type":"text"},
                {"tag":"label","conteudo":"Email:"},
                {"tag":"input","name":"email","id":"email","type":"text"},
                {"tag":"label","conteudo":"Senha:"},
                {"tag":"input","name":"senha","id":"senha","type":"password"}
                ]
            }
        escrever(objeto,MenuItens);
    }