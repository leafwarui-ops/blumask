<?php

function resolve_media_url($path, $fallback = '', $web_prefix = '') {
    $value = trim((string) ($path ?? ''));

    if ($value === '') {
        return $fallback;
    }

    if (preg_match('#^(https?:)?//#i', $value) || preg_match('#^data:#i', $value)) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    $normalized = ltrim($value, './');
    $absolute_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);

    if (!is_file($absolute_path)) {
        return $fallback;
    }

    return htmlspecialchars($web_prefix . $normalized, ENT_QUOTES, 'UTF-8');
}

function generated_avatar_url($name) {
    return 'https://ui-avatars.com/api/?name=' . urlencode(trim((string) ($name ?: 'User'))) . '&background=random';
}
