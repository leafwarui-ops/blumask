<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Módulo Helper de Rate Limiting via Sessão PHP
 */

/**
 * Registra uma tentativa/hit para determinada chave.
 */
function hit_rate_limit($key) {
    if (!isset($_SESSION['rate_limits'][$key])) {
        $_SESSION['rate_limits'][$key] = [
            'count' => 0,
            'first_hit' => time()
        ];
    }
    $_SESSION['rate_limits'][$key]['count']++;
}

/**
 * Verifica se a ação ainda é permitida ou se atingiu o limite.
 *
 * @param string $key Nome da ação (ex: 'login', 'register', 'usr_edit')
 * @param int $maxAttempts Máximo de tentativas permitidas
 * @param int $decaySeconds Tempo da janela de bloqueio em segundos
 * @return bool True se permitido, False se bloqueado
 */
function check_rate_limit($key, $maxAttempts, $decaySeconds) {
    if (!isset($_SESSION['rate_limits'][$key])) {
        return true;
    }

    $data = $_SESSION['rate_limits'][$key];
    $elapsed = time() - $data['first_hit'];

    // Se a janela de tempo já passou, reseta a contagem
    if ($elapsed >= $decaySeconds) {
        reset_rate_limit($key);
        return true;
    }

    // Se ainda está no período e excedeu o limite
    if ($data['count'] >= $maxAttempts) {
        return false;
    }

    return true;
}

/**
 * Zera as contagens de uma chave (ex: ao logar com sucesso).
 */
function reset_rate_limit($key) {
    if (isset($_SESSION['rate_limits'][$key])) {
        unset($_SESSION['rate_limits'][$key]);
    }
}

/**
 * Retorna mensagem formatada com o tempo restante de bloqueio.
 */
function get_rate_limit_wait_time($key, $decaySeconds) {
    if (!isset($_SESSION['rate_limits'][$key])) {
        return "0 segundos";
    }

    $elapsed = time() - $_SESSION['rate_limits'][$key]['first_hit'];
    $remaining = $decaySeconds - $elapsed;

    if ($remaining <= 0) {
        reset_rate_limit($key);
        return "0 segundos";
    }

    if ($remaining >= 3600) {
        $hours = ceil($remaining / 3600);
        return "$hours hora(s)";
    } elseif ($remaining >= 60) {
        $minutes = ceil($remaining / 60);
        return "$minutes minuto(s)";
    } else {
        return "$remaining segundo(s)";
    }
}
