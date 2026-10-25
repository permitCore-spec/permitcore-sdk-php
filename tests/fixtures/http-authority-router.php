<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input'), true);
file_put_contents(getenv('PERMITCORE_QA_HTTP_CAPTURE'), json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path, 'body' => $body]) . PHP_EOL, FILE_APPEND | LOCK_EX);
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/api/v1/nonce') {
    echo '{"nonce":"qa-nonce"}';
    return;
}
$case = json_decode(file_get_contents(getenv('PERMITCORE_QA_HTTP_CASE')), true, 512, JSON_THROW_ON_ERROR);
http_response_code($case['status']);
if ($case['truncatedBody'] ?? false) {
    header('Content-Length: ' . (strlen($case['body']) + 32));
    header('Connection: close');
}
echo $case['body'];
