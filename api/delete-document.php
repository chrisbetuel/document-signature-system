<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;
if (!$document_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Document ID is required']);
    exit;
}

try {
    $db = getDB();

    // Get document records to delete physical files
    $stmt = $db->prepare("SELECT original_path, filled_path FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $doc = $stmt->fetch();

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Document not found']);
        exit;
    }

    // Delete physical files
    $pathsToDelete = [];
    if (!empty($doc['original_path'])) $pathsToDelete[] = $doc['original_path'];
    if (!empty($doc['filled_path'])) $pathsToDelete[] = $doc['filled_path'];

    // Get signature files from audit table
    $stmt = $db->prepare("SELECT signature_image_path FROM signature_audit WHERE document_id = ?");
    $stmt->execute([$document_id]);
    while ($row = $stmt->fetch()) {
        if (!empty($row['signature_image_path'])) {
            $path = __DIR__ . '/../' . $row['signature_image_path'];
            $pathsToDelete[] = $path;
        }
    }

    foreach ($pathsToDelete as $path) {
        $normalized = str_replace('\\', '/', $path);
        if (file_exists($normalized)) {
            @unlink($normalized);
        }
    }

    // Delete from related tables
    $db->prepare("DELETE FROM filled_data WHERE document_id = ?")->execute([$document_id]);
    $db->prepare("DELETE FROM signature_audit WHERE document_id = ?")->execute([$document_id]);

    // Delete the document itself
    $db->prepare("DELETE FROM documents WHERE id = ?")->execute([$document_id]);

    echo json_encode(['success' => true, 'message' => 'Document deleted successfully']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
