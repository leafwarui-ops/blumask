<?php
/**
 * ARQUIVO: rate_limit.php
 * DESCRIÇÃO: Módulo de Rate Limiting via IP e Sessão PHP
 * OBJETIVO: Proteger a aplicação contra ataques de força bruta (login, cadastro, spam)
 * FUNCIONA: Registrando um arquivo temporário com tentativas por IP do cliente
 * 
 * EXEMPLO DE USO:
 * 1. check_rate_limit('login_attempt', 5, 900) - Permite máx 5 tentativas em 15 min
 * 2. Se retorna FALSE, o usuário deve aguardar ou tentativa foi bloqueada
 * 3. Se retorna TRUE, executa a ação e marca com hit_rate_limit()
 */

/**
 * Função: get_client_ip()
 * DESCRIÇÃO: Obtém o endereço IP real do cliente
 * DETALHES: Trata proxies e servidores reversos para pegar IP verdadeiro
 * RETORNA: String com o IP do cliente (validado para evitar injection)
 */
function get_client_ip() {
    // $_SERVER['REMOTE_ADDR'] é o IP direto do cliente para conexões normais
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    
    // Remove caracteres inválidos mantendo apenas números, pontos e dois pontos (IPv6)
    // Isso protege contra injeção de código no nome do arquivo
    return preg_replace('/[^0-9a-fA-F:\.]/', '', $ip);
}

/**
 * Função: get_rate_limit_file()
 * DESCRIÇÃO: Gera o caminho do arquivo temporário onde armazena dados de rate limit
 * PARÂMETROS:
 *   - $key (string): Identificador único (ex: 'login_attempt', 'register_attempt')
 * RETORNA: String com o caminho absoluto do arquivo JSON temporário
 * DETALHES: Usa hash MD5 do IP + chave para criar nome de arquivo único
 */
function get_rate_limit_file($key) {
    // Obtém o IP do cliente
    $ip = get_client_ip();
    
    // Cria hash único combinando IP e tipo de ação (evita colisões)
    $hash = md5($ip . '_' . $key);
    
    // Retorna caminho no diretório temporário do sistema com nome único
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blumask_rl_' . $hash . '.json';
}

/**
 * Função: get_rate_limit_data()
 * DESCRIÇÃO: Recupera os dados armazenados de tentativas para uma chave específica
 * PARÂMETROS:
 *   - $key (string): Identificador da ação (ex: 'login_attempt')
 * RETORNA: Array associativo com ['count' => int, 'first_hit' => timestamp] ou null
 * LÓGICA: Tenta ler do arquivo primeiro, depois da sessão como fallback
 */
function get_rate_limit_data($key) {
    // Constrói caminho do arquivo temporário
    $file = get_rate_limit_file($key);
    
    // Tenta ler do arquivo se existir
    if (file_exists($file)) {
        // Lê o conteúdo do arquivo (com erro suppression para evitar warnings)
        $content = @file_get_contents($file);
        
        if ($content !== false) {
            // Decodifica JSON para obter array associativo
            $data = json_decode($content, true);
            
            // Se o parse foi bem-sucedido, retorna os dados
            if (is_array($data)) {
                return $data;
            }
        }
    }
    
    // Fallback: tenta obter da sessão se não estava no arquivo
    return $_SESSION['rate_limits'][$key] ?? null;
}

/**
 * Função: save_rate_limit_data()
 * DESCRIÇÃO: Salva os dados de tentativas em arquivo temporário e sessão
 * PARÂMETROS:
 *   - $key (string): Identificador da ação
 *   - $data (array): Array com ['count' => int, 'first_hit' => timestamp]
 * EFEITO: Persiste dados em arquivo (mais confiável que só sessão) e na sessão
 */
function save_rate_limit_data($key, $data) {
    // Armazena na sessão para acesso rápido
    $_SESSION['rate_limits'][$key] = $data;
    
    // Obtém caminho do arquivo temporário
    $file = get_rate_limit_file($key);
    
    // Escreve dados JSON no arquivo com lock exclusivo (evita corrupção)
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

/**
 * Função: remove_rate_limit_file()
 * DESCRIÇÃO: Remove o arquivo temporário de rate limit
 * PARÂMETROS:
 *   - $key (string): Identificador da ação
 * USO: Chamado quando o rate limit é resetado (ex: login bem-sucedido)
 */
function remove_rate_limit_file($key) {
    $file = get_rate_limit_file($key);
    
    // Tenta deletar o arquivo se existir
    if (file_exists($file)) {
        @unlink($file);
    }
}

/**
 * Função: hit_rate_limit()
 * DESCRIÇÃO: Registra uma tentativa/hit para determinada chave
 * PARÂMETROS:
 *   - $key (string): Identificador da ação (ex: 'login_attempt')
 * EFEITO: Incrementa o contador de tentativas e persiste no arquivo
 * QUANDO USAR: Imediatamente após uma ação bem-sucedida ou falha que contar contra o limite
 */
function hit_rate_limit($key) {
    // Obtém os dados atuais de rate limit (ou cria vazios se não existem)
    $data = get_rate_limit_data($key);
    
    if (!$data || !is_array($data)) {
        // Primeira vez que essa ação é tentada: inicia contagem
        $data = [
            'count' => 0,           // Número de tentativas
            'first_hit' => time()   // Timestamp da primeira tentativa
        ];
    }
    
    // Incrementa o contador de tentativas
    $data['count']++;
    
    // Salva os dados atualizados (em arquivo e sessão)
    save_rate_limit_data($key, $data);
}

/**
 * Função: check_rate_limit()
 * DESCRIÇÃO: Verifica se uma ação ainda é permitida ou se foi bloqueada
 * PARÂMETROS:
 *   - $key (string): Nome da ação como 'login', 'register', 'usr_edit')
 *   - $maxAttempts (int): Número máximo de tentativas permitidas
 *   - $decaySeconds (int): Janela de tempo em segundos (ex: 900 = 15 minutos)
 * RETORNA: bool
 *   - TRUE: Ação ainda é permitida (não atingiu o limite)
 *   - FALSE: Ação bloqueada (limite excedido dentro da janela de tempo)
 * 
 * EXEMPLO: check_rate_limit('login', 5, 900)
 * = Permite máximo 5 tentativas em 900 segundos (15 minutos)
 */
function check_rate_limit($key, $maxAttempts, $decaySeconds) {
    // Obtém dados armazenados
    $data = get_rate_limit_data($key);
    
    if (!$data || !is_array($data)) {
        // Nenhuma tentativa anterior: permite a ação
        return true;
    }

    // Calcula quanto tempo passou desde a primeira tentativa (em segundos)
    $elapsed = time() - $data['first_hit'];

    // Se a janela de tempo já passou, reseta e permite a ação
    // Exemplo: Se passaram mais de 900s (15min) desde a primeira tentativa
    if ($elapsed >= $decaySeconds) {
        reset_rate_limit($key);
        return true;
    }

    // Ainda está dentro da janela de tempo, verifica se excedeu o limite
    // Se as tentativas >= máximo permitido, nega a ação
    if ($data['count'] >= $maxAttempts) {
        return false;
    }

    // Ainda não atingiu o limite e está dentro da janela: permite
    return true;
}

/**
 * Função: reset_rate_limit()
 * DESCRIÇÃO: Limpa / reseta as contagens de uma chave específica
 * PARÂMETROS:
 *   - $key (string): Identificador da ação a resetar
 * QUANDO USAR:
 *   - Login bem-sucedido (remove penalidade de tentativas anteriores)
 *   - Após desbloquear (passar janela de tempo)
 *   - Para limpar estado após ação bem-sucedida
 */
function reset_rate_limit($key) {
    // Remove da sessão
    if (isset($_SESSION['rate_limits'][$key])) {
        unset($_SESSION['rate_limits'][$key]);
    }
    
    // Remove arquivo temporário do sistema
    remove_rate_limit_file($key);
}

/**
 * Função: get_rate_limit_wait_time()
 * DESCRIÇÃO: Calcula e formata quanto tempo falta para desbloqueio
 * PARÂMETROS:
 *   - $key (string): Identificador da ação
 *   - $decaySeconds (int): Janela de tempo da política de rate limit
 * RETORNA: String em português formatada (ex: "5 minuto(s)", "30 segundo(s)")
 * USO: Mostrar mensagem amigável ao usuário de quanto tempo deve esperar
 */
function get_rate_limit_wait_time($key, $decaySeconds) {
    // Obtém dados armazenados
    $data = get_rate_limit_data($key);
    
    if (!$data || !is_array($data)) {
        // Sem dados: tempo de espera é zero
        return "0 segundos";
    }

    // Calcula tempo que já passou
    $elapsed = time() - $data['first_hit'];
    
    // Calcula tempo restante até desbloqueio
    $remaining = $decaySeconds - $elapsed;

    // Se já passou a janela, reseta e retorna zero
    if ($remaining <= 0) {
        reset_rate_limit($key);
        return "0 segundos";
    }

    // Formata a resposta de forma legível em português
    if ($remaining >= 3600) {
        // Mais de 1 hora: mostra em horas
        $hours = ceil($remaining / 3600);
        return "$hours hora(s)";
    } elseif ($remaining >= 60) {
        // Mais de 1 minuto: mostra em minutos
        $minutes = ceil($remaining / 60);
        return "$minutes minuto(s)";
    } else {
        // Menos de 1 minuto: mostra em segundos
        return "$remaining segundo(s)";
    }
}
// ============================================================================
// FIM DO ARQUIVO rate_limit.php
// ============================================================================
?>
