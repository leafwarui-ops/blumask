<?php
/**
 * ARQUIVO: security_headers.php
 * DESCRIÇÃO: Módulo centralizado de segurança da aplicação
 * Gerencia: Headers HTTP de segurança, Sessões seguras, Tokens CSRF (Cross-Site Request Forgery)
 * Este arquivo DEVE ser incluído no topo de todos os arquivos PHP que precisam de autenticação
 */

// ============================================================================
// SEÇÃO 1: Configuração Segura de Cookies de Sessão e Inicialização
// ============================================================================

// Verifica se uma sessão já foi iniciada para evitar inicializar duas vezes (erro comum)
if (session_status() === PHP_SESSION_NONE) {
    
    // Detecta se a conexão é segura (HTTPS), necessário para definir o cookie como "secure"
    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
                || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    // Configura os parâmetros de cookie da sessão com as melhores práticas de segurança
    session_set_cookie_params([
        'lifetime' => 0,            // Cookie expires when browser closes (não persiste em disco)
        'path' => '/',              // Cookie é válido em todo o domínio
        'domain' => '',             // Deixa vazio para usar o domínio atual
        'secure' => $isSecure,      // Cookie só é enviado em conexões HTTPS
        'httponly' => true,         // Impede acesso via JavaScript (proteção contra XSS)
        'samesite' => 'Lax'         // Valida requisições entre diferentes sites (proteção CSRF)
    ]);
    
    // Inicia a sessão PHP com os parâmetros de segurança configurados
    session_start();
}

// ============================================================================
// SEÇÃO 2: Definição de Headers HTTP de Segurança
// ============================================================================

// Verifica se os headers já foram enviados (não é possível enviar headers após conteúdo)
if (!headers_sent()) {
    
    // X-Frame-Options: Protege contra clickjacking (embutir site em iframe malicioso)
    // SAMEORIGIN = Permite iframe apenas do mesmo domínio
    header("X-Frame-Options: SAMEORIGIN");
    
    // X-Content-Type-Options: Força o navegador respeitar o content-type declarado
    // nosniff = Não tenta "adivinhar" o tipo de conteúdo (proteção contra políglotas)
    header("X-Content-Type-Options: nosniff");
    
    // Referrer-Policy: Controla quais informações sobre referência são enviadas para outros sites
    // strict-origin-when-cross-origin = Envia URL completa apenas para requests do mesmo site
    header("Referrer-Policy: strict-origin-when-cross-origin");
    
    // Permissions-Policy: Lista features do navegador que a página não usará
    // Desativa: câmera, microfone e geolocalização por segurança
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    
    // X-XSS-Protection: Ativa proteção contra XSS (obfuscating conteúdo suspeito)
    // Nota: Deprecated em navegadores modernos, mas mantido para compatibilidade
    header("X-XSS-Protection: 1; mode=block");
    
    // Content-Security-Policy: Define quais recursos podem ser executados na página
    // - default-src 'self': Permite recursos apenas do mesmo domínio
    // - script-src 'unsafe-inline': Permite JavaScript inline (necessário para o app)
    // - img-src 'self' data: https://ui-avatars.com: Permite imagens locais, base64 e avatares
    // - connect-src 'self': Fetch/AJAX apenas para o mesmo domínio
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://ui-avatars.com; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self';");
    
    // Remove o header X-Powered-By que revela informações sobre a stack tecnológica (segurança por obscuridade)
    if (function_exists('header_remove')) {
        header_remove("X-Powered-By");
    }
}

// ============================================================================
// SEÇÃO 3: Geração e Validação de Tokens CSRF (Cross-Site Request Forgery)
// ============================================================================

/**
 * Função: get_csrf_token()
 * DESCRIÇÃO: Retorna ou gera um novo token CSRF para a sessão do usuário
 * USO: Colocar em formulários ocultos para validar requisições POST
 * RETORNA: String hexadecimal de 64 caracteres (256 bits de entropia)
 */
function get_csrf_token() {
    // Verifica se a sessão não possui um token CSRF
    if (empty($_SESSION['csrf_token'])) {
        // Gera um token aleatório e converte para hexadecimal
        // random_bytes(32) = 256 bits de segurança criptográfica
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Função: verify_csrf_token()
 * DESCRIÇÃO: Valida se o token CSRF recebido na requisição é válido
 * PARÂMETROS:
 *   - $token (string): Token recebido do formulário do cliente
 * RETORNA: bool (true = token válido, false = inválido ou ausente)
 * OBJETIVO: Prevenir ataques CSRF onde sites maliciosos tentam fazer requisições em seu nome
 */
function verify_csrf_token($token) {
    // Verifica se há um token na sessão (deve ter sido gerado antes)
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    
    // Compara os tokens de forma segura (hash_equals previne timing attacks)
    // Timing attacks tentam adivinhar o token medindo quanto tempo leva para a comparação
    return hash_equals($_SESSION['csrf_token'], $token);
}
// ============================================================================
// FIM DO ARQUIVO security_headers.php
// ============================================================================
?>
