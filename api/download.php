<?php
error_reporting(0);
require_once '../includes/config.php';

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

if (!$document_id) {
    die('Document ID is required');
}

try {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        die('Document not found');
    }
    
    // Get filled values
    $stmt = $db->prepare("SELECT field_name, field_value FROM filled_data WHERE document_id = ?");
    $stmt->execute([$document_id]);
    $filledData = $stmt->fetchAll();
    
    $filledValues = [];
    foreach ($filledData as $item) {
        $filledValues[$item['field_name']] = $item['field_value'];
    }
    
    // Get gaps
    $gaps = json_decode($document['gaps_json'], true);
    if (!$gaps) {
        $gaps = [];
    }
    
    // Create filled document
    $filePath = $document['original_path'];
    if (!file_exists($filePath)) {
        die('File not found');
    }
    
    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    
    if ($ext == 'txt') {
        $content = file_get_contents($filePath);
        
        // Replace placeholders with filled values
        foreach ($gaps as $gap) {
            $placeholder = $gap['placeholder'];
            $fieldName = $gap['name'];
            if (isset($filledValues[$fieldName]) && !empty($filledValues[$fieldName])) {
                $content = str_replace($placeholder, $filledValues[$fieldName], $content);
            }
        }
        
        // Create filled file
        $filledPath = FILLED_DIR . 'filled_' . $document_id . '_' . time() . '.txt';
        file_put_contents($filledPath, $content);
        
        // Download
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="filled_' . $document['original_filename'] . '"');
        header('Content-Length: ' . filesize($filledPath));
        readfile($filledPath);
        exit;
    }
    
    // For other file types, just download original
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $document['original_filename'] . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
    
} catch(Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>