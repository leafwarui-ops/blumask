# Diretrizes de Segurança e Validação de Entradas do Projeto BluMask

Este arquivo estabelece os requisitos obrigatórios de segurança para qualquer agente ou desenvolvedor que crie ou modifique novos pontos de entrada (endpoints PHP, formulários, APIs ou uploads de arquivos) no repositório **BluMask**.

---

## 🔒 1. Rate Limiting (Obrigatório em Endpoints POST/Upload)
- **Toda ação receptora de dados** (Login, Cadastro, Edição de Perfil, Criar Comunidade, Criar Post) **DEVE** importar o módulo `php/rate_limit.php`.
- **Verificação no Backend:** Antes de processar qualquer entrada ou consulta pesada, execute `check_rate_limit($key, $maxAttempts, $decaySeconds)`.
- **Registro de Falha/Hit:** Use `hit_rate_limit($key)` quando a requisição for processada/falhar para contabilizar a tentativa.
- **Formatação de Alerta:** Ao estourar o limite, retorne erro amigável contendo `get_rate_limit_wait_time($key, $decaySeconds)`.

---

## 🛡️ 2. Validação e Sanitização de Entradas (Input Handling)
- **Strings e Campos de Texto:**
  - Todo campo de texto recebido via `$_POST` ou `$_GET` deve passar por `trim()` e sanitização com `mysqli_real_escape_string($conn, ...)` antes de ir para queries SQL.
  - Para exibição no HTML/JSON, dados gerados pelo usuário devem obrigatoriamente usar `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` contra **XSS**.
  - **Limites de Tamanho Estritos:** Validar comprimento de string no PHP com `mb_strlen()` (ex: Nome de Comunidade 2-150 chars, Bio max 200 chars).
- **Validação de E-mail:** Usar `filter_var($email, FILTER_VALIDATE_EMAIL)`.
- **IDs numéricos:** Passar por `intval()` ou Prepared Statements.

---

## 📷 3. Segurança no Envio de Arquivos (Uploads)
- **Tamanho Máximo:** Limite estrito de **30MB** (31.457.280 bytes), checado no PHP (`$_FILES['file']['size']`) e no JavaScript.
- **Extensões Permitidas:** Apenas imagens/animations seguras: `['jpg', 'jpeg', 'png', 'gif', 'webp']`.
- **Nomes Únicos de Arquivo:** Nunca salvar o arquivo com o nome original fornecido pelo cliente. Use sufixos com hash/timestamp únicos (ex: `comunidade_uniqid().ext` ou `avatar_ID_time().ext`).
- **Diretórios de Destino:** Garantir que pastas de destino em `uploads/` tenham permissões adequadas e verificação de existência (`is_dir` / `mkdir`).

---

## 🔑 4. Gerenciamento de Sessão e Autenticação
- **Sessão Unificada:** A sessão do usuário autenticado no sistema utiliza `$_SESSION['usuario']` (array associativo contendo os campos da tabela `usuario`).
- **Proteção de Acesso:** Endpoints privados devem verificar `if (!isset($_SESSION['usuario']))` e abortar/redirecionar se o usuário estiver deslogado.
- **Senhas:** NUNCA armazenar senhas em texto puro. Usar `password_hash($senha, PASSWORD_DEFAULT)` e `password_verify($senha, $hash)`.
