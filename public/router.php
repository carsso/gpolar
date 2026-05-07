<?php
// Dev server router: php -S localhost:8000 -t public/ public/router.php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Static files (CSS, JS, images…)
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// /trip/123
if (preg_match('#^/trip/(\d+)$#', $uri, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/trip.php';
    exit;
}

// /share/{token}
if (preg_match('#^/share/([a-f0-9]+)$#', $uri, $m)) {
    $_GET['token'] = $m[1];
    require __DIR__ . '/share.php';
    exit;
}

$map = [
    '/'             => 'index.php',
    '/login'        => 'login.php',
    '/logout'       => 'logout.php',
    '/api/comments' => 'api/comments.php',
    '/api/share'    => 'api/share.php',
];

if (isset($map[$uri])) {
    require __DIR__ . '/' . $map[$uri];
    exit;
}

http_response_code(404);
echo '404 Not Found';
