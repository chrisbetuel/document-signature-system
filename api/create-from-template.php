<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$categoryId = (int)($input['category_id'] ?? 0);
$templateDocId = (int)($input['template_document_id'] ?? 0);

if (!$categoryId || !$templateDocId) {
    echo json_encode(['success' => false, 'message' => 'Category ID and Template Document ID required']);
    exit;
}

try {
    $db = getDB();

    // Get template document info
    $stmt = $db->prepare("SELECT original_filename, original_path FROM documents WHERE id = ? AND category_id = ?");
    $stmt->execute([$templateDocId, $categoryId]);
    $templateDoc = $stmt->fetch();

    if (!$templateDoc) {
        echo json_encode(['success' => false, 'message' => 'Template document not found']);
        exit;
    }

    // Create document record linked to the template
    $stmt = $db->prepare("INSERT INTO documents (category_id, original_filename, original_path, gaps_json, status) VALUES (?, ?, ?, '[]', 'filled')");
    $stmt->execute([
        $categoryId,
        $templateDoc['original_filename'],
        $templateDoc['original_path'],
    ]);

    $docId = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'document_id' => $docId,
        'message' => 'Document created from template'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
