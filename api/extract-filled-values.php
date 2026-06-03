<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';

$document_id = $_POST['document_id'] ?? 0;

if (!$document_id) {
    echo json_encode(['success' => false, 'message' => 'Document ID required']);
    exit;
}

if (!isset($_FILES['filled_document']) || $_FILES['filled_document']['error'] !== 0) {
    echo json_encode(['success' => false, 'message' => 'File upload failed']);
    exit;
}

try {
    $db = getDB();
    
    // Get original document gaps
    $stmt = $db->prepare("SELECT gaps_json, original_filename FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }
    
    $gaps = json_decode($document['gaps_json'], true);
    if (!$gaps) {
        $gaps = [];
    }
    
    // Save the uploaded filled document
    $file = $_FILES['filled_document'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Create unique filename for filled document
    $filledFilename = 'filled_' . time() . '_' . $document_id . '.' . $ext;
    $filledPath = FILLED_DIR . $filledFilename;
    
    // Move uploaded file to filled directory
    if (!move_uploaded_file($file['tmp_name'], $filledPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save filled document']);
        exit;
    }
    
    // Update document record with filled document path
    $stmt = $db->prepare("UPDATE documents SET filled_path = ? WHERE id = ?");
    $stmt->execute([$filledPath, $document_id]);
    
    // Extract text from the filled document for gap detection
    $filledText = '';
    
    if ($ext == 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($filledPath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $filledText = strip_tags($xml);
            $filledText = html_entity_decode($filledText, ENT_QUOTES, 'UTF-8');
        }
    } elseif ($ext == 'pdf') {
        $output = shell_exec("pdftotext " . escapeshellarg($filledPath) . " - 2>/dev/null");
        if ($output) {
            $filledText = $output;
        }
    } elseif ($ext == 'txt') {
        $filledText = file_get_contents($filledPath);
    }
    
    // Extract values by comparing original placeholders with filled content
    $extractedValues = [];
    
    foreach ($gaps as $gap) {
        $fieldName = isset($gap['name']) ? $gap['name'] : '';
        $placeholder = isset($gap['placeholder']) ? $gap['placeholder'] : '';
        $type = isset($gap['type']) ? $gap['type'] : 'text';
        
        if (empty($fieldName)) continue;
        
        // Check if placeholder still exists in filled document
        if (!empty($placeholder) && strpos($filledText, $placeholder) !== false) {
            // Placeholder still exists - not filled
            $extractedValues[$fieldName] = '';
        } else {
            // Try to find what replaced the placeholder
            // For now, mark as filled
            $extractedValues[$fieldName] = '[FILLED]';
        }
    }
    
    echo json_encode([
        'success' => true,
        'values' => $extractedValues,
        'filled_document_path' => $filledPath,
        'message' => 'Document uploaded successfully. The filled document has been saved.'
    ]);
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>