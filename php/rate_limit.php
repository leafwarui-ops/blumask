<?php
require_once __DIR__ . "/security_headers.php";

/**
 * Módulo Helper de Rate Limiting via IP e Sessão PHP
 */

function get_client_ip() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return preg_replace('/[^0-9a-fA-F:\.]/', '', $ip);
}

function get_rate_limit_file($key) {
    $ip = get_client_ip();
    $hash = md5($ip . '_' . $key);
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blumask_rl_' . $hash . '.json';
}

function get_rate_limit_data($key) {
    $file = get_rate_limit_file($key);
    if (file_exists($file)) {
        $content = @file_get_contents($file);
        if ($content !== false) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                return $data;
            }
        }
    }
    return $_SESSION['rate_limits'][$key] ?? null;
}

function save_rate_limit_data($key, $data) {
    $_SESSION['rate_limits'][$key] = $data;
    $file = get_rate_limit_file($key);
    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function remove_rate_limit_file($key) {
    $file = get_rate_limit_file($key);
    if (file_exists($file)) {
        @unlink($file);
    }
}

/**
 * Registra uma tentativa/hit para determinada chave.
 */
function hit_rate_limit($key) {
    $data = get_rate_limit_data($key);
    if (!$data || !is_array($data)) {
        $data = [
            'count' => 0,
            'first_hit' => time()
        ];
    }
    $data['count']++;
    save_rate_limit_data($key, $data);
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
    $data = get_rate_limit_data($key);
    if (!$data || !is_array($data)) {
        return true;
    }

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
    remove_rate_limit_file($key);
}

/**
 * Retorna mensagem formatada com o tempo restante de bloqueio.
 */
function get_rate_limit_wait_time($key, $decaySeconds) {
    $data = get_rate_limit_data($key);
    if (!$data || !is_array($data)) {
        return "0 segundos";
    }

    $elapsed = time() - $data['first_hit'];
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
