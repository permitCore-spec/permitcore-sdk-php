<?php
declare(strict_types=1);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$capture = getenv('PERMITCORE_QA_HTTP_CAPTURE');
$requests = array_map(fn($line) => json_decode($line, true), file($capture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$nonce = $path === '/api/v1/nonce';
$index = count(array_filter($requests, fn($r) => ($r['path'] === '/api/v1/nonce') === $nonce));
file_put_contents($capture, json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'path' => $path]) . PHP_EOL, FILE_APPEND | LOCK_EX);
$case = json_decode(file_get_contents(getenv('PERMITCORE_QA_HTTP_CASE')), true, 512, JSON_THROW_ON_ERROR);
if (isset($case['delayMicroseconds'])) usleep($case['delayMicroseconds']);
$statuses = $nonce ? ($case['nonceStatuses'] ?? [200]) : $case['statuses'];
http_response_code($statuses[min($index, count($statuses)-1)]);
header('Content-Type: application/json');
if (isset($case['location'])) header('Location: ' . $case['location']);
echo $nonce ? ($case['nonceBody'] ?? '{"nonce":"qa-nonce"}') : $case['body'];
