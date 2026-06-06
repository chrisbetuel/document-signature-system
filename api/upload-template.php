<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST method allowed']);
    exit;
}

try {
    $db = getDB();
    $categoryId = (int)($_POST['category_id'] ?? 0);

    if (!$categoryId) {
        echo json_encode(['success' => false, 'message' => 'Category ID required']);
        exit;
    }

    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== 0) {
        echo json_encode(['success' => false, 'message' => 'File upload failed']);
        exit;
    }

    $file = $_FILES['document'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    $allowed = ['docx'];
    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Only .docx files are allowed for templates']);
        exit;
    }

    $filename = time() . '_' . uniqid() . '.' . $ext;
    $path = ORIGINAL_DIR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO documents (category_id, original_filename, original_path, gaps_json, status) VALUES (?, ?, ?, '[]', 'template')");
    $stmt->execute([
        $categoryId,
        $file['name'],
        $path,
    ]);

    $docId = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'document_id' => $docId,
        'message' => 'Template uploaded successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
