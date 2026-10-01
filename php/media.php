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

function generated_avatar_data_uri($name): string {
    $displayName = trim((string) ($name ?: 'User'));
    $nameParts = preg_split('/\s+/u', $displayName, -1, PREG_SPLIT_NO_EMPTY) ?: ['U'];
    $initials = '';

    foreach (array_slice($nameParts, 0, 2) as $part) {
        $initial = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        $initials .= function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
    }

    $backgrounds = ['#356859', '#52796f', '#4c6b8a', '#8a5a44', '#8064a2'];
    $background = $backgrounds[hexdec(substr(hash('sha256', $displayName), 0, 2)) % count($backgrounds)];
    $safeInitials = htmlspecialchars($initials ?: 'U', ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" rx="100" fill="' . $background . '"/><text x="100" y="108" text-anchor="middle" dominant-baseline="middle" font-family="Arial,sans-serif" font-size="72" font-weight="700" fill="#fff">' . $safeInitials . '</text></svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
