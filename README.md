# 🎭 BluMask - Rede Social & Plataforma de Comunidades

<p align="center">
  <img src="style/blumaskBlueLogo.webp" alt="BluMask Logo" width="120" />
</p>

<p align="center">
  <b>Conectando pessoas através de comunidades, publicações e perfis personalizados.</b>
</p>

---

## 📌 Sobre o Projeto

O **BluMask** é uma aplicação web completa desenvolvida em **PHP**, **MySQL**, **HTML5**, **CSS3** e **JavaScript Vanilla**. O projeto simula uma rede social moderna voltada para a criação e interação em comunidades, compartilhamento de posts e personalização de perfis de usuário.

A aplicação conta com um design limpo e fluido, sistema de autenticação seguro com senhas criptografadas, upload de mídias de alta capacidade (banners e avatares estáticos ou GIFs de até 30MB) e validações em tempo real.

---

## ✨ Funcionalidades Principais

* 🔒 **Autenticação & Segurança:**
  * Cadastro e Login de usuários integrados ao banco de dados MySQL.
  * Criptografia de senhas usando o algoritmo seguro `BCRYPT` (`password_hash` / `password_verify`).
  * Validação de senhas forte (8 a 32 caracteres, contendo letra maiúscula e símbolo).
  * Proteção contra *SQL Injection* e sanitização de dados de entrada.

* 👤 **Gestão de Perfil Avançada (`php/usr_edit.php`):**
  * Upload de foto de perfil (avatar) e capa (banner) com suporte a imagens e GIFs animados de até **30MB**.
  * Preview de imagem em tempo real antes de salvar.
  * Alteração de Nome de Usuário (único, 4 a 20 caracteres), Nome de Exibição (2 a 10 caracteres) e Bio / Descrição (até 200 caracteres com contador dinâmico).
  * Exigência da **Senha Atual** para autorizar e salvar qualquer modificação de dados.

* 🎨 **Interface Responsiva & UX Aprimorada:**
  * Layout em 3 colunas: Painel de Perfil, Feed Central e Lista de Comunidades.
  * Modal interativo de Login/Cadastro com troca de abas sem recarregar a página.
  * Feedback visual instantâneo em campos com erro e bloqueio/liberação inteligente do botão "Confirmar".
  * Ícone personalizado (`favicon`) na guia do navegador e logo estilizada na barra superior.

---

## 🛠️ Tecnologias Utilizadas

* **Back-End:** PHP 8+ (mysqli / PDO, Gerenciamento de Sessão)
* **Banco de Dados:** MySQL / MariaDB
* **Front-End:** HTML5 Semântico, CSS3 (Design System customizado), JavaScript Vanilla (ES6+, FileReader API, Validação DOM)
* **Ambiente de Desenvolvimento:** XAMPP (Apache Web Server & MySQL)

---

## 📂 Estrutura de Pastas

```text
blumask/
├── bd/
│   └── bd_blumask.sql          # Script SQL para criação do banco de dados e tabelas
├── css/ & style/
│   ├── index_style.css         # Estilos globais da página principal e componentes
│   ├── edit_style.css          # Estilos exclusivos da tela de edição de perfil
│   ├── blumaskBlueLogo.webp    # Logo azul do BluMask
│   └── blumaskWhiteLogo.webp   # Logo branca do BluMask
├── js/
│   └── login_writter.js        # Script de geração dinâmica dos formulários de entrada
├── php/
│   ├── bd.php                  # Conexão com o banco de dados MySQL
│   └── usr_edit.php            # Tela e script de edição de perfil
├── uploads/
│   ├── avatars/                # Diretório de fotos de perfil enviadas pelos usuários
│   └── banners/                # Diretório de imagens de capa enviadas pelos usuários
├── index.php                   # Página principal (Feed, Perfil e Autenticação)
└── README.md                   # Documentação do projeto
```

---

## 🚀 Como Executar o Projeto Localmente

### Pré-requisitos
* Ter o [XAMPP](https://www.apachefriends.org/pt_br/index.html) instalado (com Apache e MySQL ativos).

### Passo a Passo

1. **Clonar o Repositório:**
   Baixe ou clone este repositório diretamente na pasta `htdocs` do seu XAMPP:
   ```bash
   cd c:\xampp\htdocs
   git clone https://github.com/leafwarui-ops/blumask.git
   ```

2. **Iniciar os Serviços no XAMPP:**
   * Abra o **XAMPP Control Panel**.
   * Inicie o **Apache** e o **MySQL**.

3. **Configurar o Banco de Dados:**
   * Abra o phpMyAdmin no seu navegador: `http://localhost/phpmyadmin`.
   * Crie um banco de dados chamado `bd_blumask` (ou execute o script `bd/bd_blumask.sql`).
   * Importe o arquivo `bd/bd_blumask.sql` localizado dentro da pasta do projeto.

4. **Acessar a Aplicação:**
   Abra seu navegador e acesse:
   ```text
   http://localhost/blumask
   ```

---

## 🛡️ Estrutura do Banco de Dados (`bd_blumask.sql`)

* **`usuario`**: Armazena e-mail, nome de exibição, senha (hash BCRYPT), nome de usuário, bio/descrição, caminhos de banner e foto de perfil.
* **`comunidade`**: Comunidades criadas pelos usuários.
* **`post`**: Publicações associadas a usuários e comunidades.
* **`comentario`**: Comentários feitos nos posts.
* **`curtida`**: Curtidas e interações dos usuários nas publicações.
* **`membro_comunidade`**: Vínculo entre usuários e comunidades.

---

## 📝 Licença

Este projeto está sob desenvolvimento para fins de aprendizado e aprimoramento de habilidades em desenvolvimento web full stack.
