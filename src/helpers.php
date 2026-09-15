<?php

declare(strict_types=1);

function json_input(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return $_POST ?: [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function app_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    $here = __DIR__;
    // helpers.php lives in /src
    $root = dirname($here);
    return $root;
}

function app_base_path(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '';
    }

    return rtrim($dir, '/');
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = '/' . ltrim($path, '/');
    $base = app_base_path();

    if ($base !== '' && ($path === $base || str_starts_with($path, $base . '/'))) {
        $path = substr($path, strlen($base)) ?: '/';
    }

    $path = rtrim($path, '/') ?: '/';
    return $path;
}

function spa_dir(): string
{
    $candidates = [];
    if (!empty($_SERVER['SCRIPT_FILENAME'])) {
        $candidates[] = dirname((string) $_SERVER['SCRIPT_FILENAME']) . DIRECTORY_SEPARATOR . 'spa';
    }
    $candidates[] = app_root() . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'spa';
    $candidates[] = app_root() . DIRECTORY_SEPARATOR . 'spa';

    foreach ($candidates as $dir) {
        if (is_dir($dir)) {
            return $dir;
        }
    }

    return $candidates[0];
}

function spa_mime(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return match ($ext) {
        'js', 'mjs' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'json', 'map' => 'application/json; charset=utf-8',
        'html', 'htm' => 'text/html; charset=utf-8',
        default => 'application/octet-stream',
    };
}

function serve_spa_file(string $relative): bool
{
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || str_contains($relative, '..')) {
        return false;
    }

    $spa = spa_dir();
    $file = $spa . DIRECTORY_SEPARATOR . ltrim($relative, '/');
    if (!is_file($file)) {
        return false;
    }

    $realSpa = realpath($spa);
    $realFile = realpath($file);
    if (!$realSpa || !$realFile || !str_starts_with($realFile, $realSpa)) {
        return false;
    }

    header('Content-Type: ' . spa_mime($file));
    header('Cache-Control: public, max-age=31536000, immutable');
    readfile($file);
    return true;
}

function serve_spa(): void
{
    $index = spa_dir() . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Arayüz derlenmemiş. frontend klasöründe npm run build çalıştırın.';
        exit;
    }

    $base = app_base_path();
    $baseHref = ($base === '' ? '/' : $base . '/');
    $html = file_get_contents($index) ?: '';
    $inject = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">'
        . '<script>window.__APP_BASE__=' . json_encode($base, JSON_UNESCAPED_SLASHES) . ';</script>';

    if (preg_match('/<head[^>]*>/i', $html)) {
        $html = preg_replace('/<head[^>]*>/i', '$0' . $inject, $html, 1) ?? $html;
    } else {
        $html = $inject . $html;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    echo $html;
    exit;
}
