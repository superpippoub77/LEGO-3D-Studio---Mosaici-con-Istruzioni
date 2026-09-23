<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Payload non valido']);
    exit;
}

$action = $payload['action'] ?? 'save';
$saveMode = $payload['saveMode'] ?? 'overwrite';
$project = $payload['project'] ?? null;

if (!is_array($project)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Progetto mancante']);
    exit;
}

function sanitize_project_name(string $name): string {
    $name = trim($name);
    if ($name === '') {
        $name = 'Progetto';
    }
    $name = preg_replace('/\.[^.]+$/', '', $name);
    $name = preg_replace('/[^A-Za-z0-9\- _àèìòùÀÈÌÒÙ]/u', '-', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name, " .-_\t\n\r\0\x0B");
    return $name !== '' ? $name : 'Progetto';
}

function project_dir(): string {
    return __DIR__ . DIRECTORY_SEPARATOR . 'saved_projects';
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

function project_path_for(string $safeName): string {
    return project_dir() . DIRECTORY_SEPARATOR . $safeName . '.json';
}

function project_relative_path_for(string $safeName): string {
    return 'saved_projects/' . $safeName . '.json';
}

function unique_copy_name(string $baseName): string {
    $index = 1;
    do {
        $candidate = $baseName . '-copia-' . $index;
        $index++;
    } while (file_exists(project_path_for($candidate)));
    return $candidate;
}

$projectName = sanitize_project_name((string)($project['name'] ?? 'Progetto'));
$basePath = project_path_for($projectName);
ensure_dir(project_dir());

if ($action === 'check') {
    echo json_encode([
        'success' => true,
        'exists' => file_exists($basePath),
        'projectName' => $projectName
    ]);
    exit;
}

if ($action !== 'save') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Azione non valida']);
    exit;
}

$targetName = $projectName;
$targetPath = $basePath;
$targetRelativePath = project_relative_path_for($projectName);

if (file_exists($basePath)) {
    if ($saveMode === 'copy') {
        $targetName = unique_copy_name($projectName);
        $targetPath = project_path_for($targetName);
        $targetRelativePath = project_relative_path_for($targetName);
    }
}

$project['name'] = $targetName;
$project['savedAt'] = gmdate('c');
$project['serverFile'] = basename($targetPath);

$json = json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Impossibile serializzare il progetto']);
    exit;
}

$result = file_put_contents($targetPath, $json . PHP_EOL, LOCK_EX);
if ($result === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Impossibile salvare il progetto']);
    exit;
}

echo json_encode([
    'success' => true,
    'savedName' => $targetName,
    'fileName' => basename($targetPath),
    'path' => $targetRelativePath,
    'overwrite' => $targetPath === $basePath
]);
