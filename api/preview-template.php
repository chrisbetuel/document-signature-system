<?php
require_once '../includes/config.php';

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

if (!$document_id) {
    http_response_code(400);
    die('Document ID is required');
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if (!$document) {
        http_response_code(404);
        die('Document not found');
    }

    $filePath = $document['original_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found');
    }

    $filename = basename($document['original_filename'] ?? 'document.docx');
    $filesize = filesize($filePath);

    ob_clean();
    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . $filesize);
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    readfile($filePath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Error: ' . $e->getMessage());
}
