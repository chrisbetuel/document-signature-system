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
    $templateDocId = (int)($_POST['template_document_id'] ?? 0);

    if (!$categoryId || !$templateDocId) {
        echo json_encode(['success' => false, 'message' => 'Category ID and Template Document ID required']);
        exit;
    }

    if (!isset($_FILES['filled_document']) || $_FILES['filled_document']['error'] !== 0) {
        echo json_encode(['success' => false, 'message' => 'File upload failed']);
        exit;
    }

    // Get template document info
    $stmt = $db->prepare("SELECT original_filename, original_path FROM documents WHERE id = ? AND category_id = ?");
    $stmt->execute([$templateDocId, $categoryId]);
    $templateDoc = $stmt->fetch();

    if (!$templateDoc) {
        echo json_encode(['success' => false, 'message' => 'Template document not found']);
        exit;
    }

    // Save the filled file
    $file = $_FILES['filled_document'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext !== 'docx') {
        echo json_encode(['success' => false, 'message' => 'Only .docx files are accepted']);
        exit;
    }

    $filledFilename = 'filled_' . time() . '_' . uniqid() . '.' . $ext;
    $filledPath = FILLED_DIR . $filledFilename;

    if (!move_uploaded_file($file['tmp_name'], $filledPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save filled document']);
        exit;
    }

    // Create document record
    $stmt = $db->prepare("INSERT INTO documents (category_id, original_filename, original_path, filled_path, gaps_json, status) VALUES (?, ?, ?, ?, '[]', 'filled')");
    $stmt->execute([
        $categoryId,
        $templateDoc['original_filename'],
        $templateDoc['original_path'],
        $filledPath,
    ]);

    $docId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'document_id' => $docId,
        'message' => 'Filled document uploaded successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
