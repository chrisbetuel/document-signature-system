<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(0);

require_once '../includes/config.php';

$categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

if (!$categoryId) {
    echo json_encode(['success' => false, 'message' => 'category_id is required']);
    exit;
}

try {
    $db = getDB();

    $stmt = $db->prepare(
        "SELECT original_filename, original_path
         FROM documents
         WHERE category_id = ? AND status = 'template'
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([$categoryId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc || empty($doc['original_path']) || !file_exists($doc['original_path'])) {
        echo json_encode(['success' => false, 'message' => 'Template file not found']);
        exit;
    }

    $path = $doc['original_path'];
    $filename = $doc['original_filename'] ?: basename($path);
    $size = filesize($path);

    // Stream file
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    header('Content-Length: ' . $size);

    // Disable output buffering to improve streaming reliability
    while (ob_get_level()) { ob_end_clean(); }
    readfile($path);
    exit;
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

