<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$document_id = $input['document_id'] ?? 0;
$filledValues = $input['values'] ?? [];

if (!$document_id) {
    echo json_encode(['success' => false, 'message' => 'Document ID required']);
    exit;
}

try {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }
    
    $filePath = $document['original_path'];
    $content = '';
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if ($ext == 'txt') {
        $content = file_get_contents($filePath);
    } elseif ($ext == 'docx') {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            $content = strip_tags($xml);
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        }
    } elseif ($ext == 'pdf') {
        if ($fp = fopen($filePath, 'rb')) {
            $pdfContent = '';
            while (!feof($fp)) {
                $pdfContent .= fread($fp, 8192);
            }
            fclose($fp);
            preg_match_all('/\((.*?)\)/', $pdfContent, $matches);
            $content = implode(' ', $matches[1]);
        }
    }
    
    // Replace placeholders with filled values
    $gaps = json_decode($document['gaps_json'], true);
    if ($gaps) {
        foreach ($gaps as $gap) {
            $placeholder = $gap['placeholder'];
            $value = $filledValues[$gap['name']] ?? $placeholder;
            
            // Handle signature values
            if ($gap['type'] == 'signature' && $value && $value !== '✍️ [SIGNED]') {
                $value = '[SIGNATURE PROVIDED]';
            }
            
            $content = str_replace($placeholder, $value, $content);
        }
    }
    
    echo json_encode([
        'success' => true,
        'filled_content' => $content
    ]);
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>