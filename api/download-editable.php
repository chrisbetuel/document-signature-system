<?php
require_once '../includes/config.php';

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

if (!$document_id) {
    die('Document ID required');
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        die('Document not found');
    }
    
    $filePath = $document['original_path'];
    
    if (!file_exists($filePath)) {
        die('File not found');
    }
    
    // For DOCX files, just serve the original
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if ($ext == 'docx') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="editable_' . $document['original_filename'] . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    } else {
        // For other formats, convert or serve as is
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="editable_' . $document['original_filename'] . '"');
        readfile($filePath);
        exit;
    }
    
} catch(Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>