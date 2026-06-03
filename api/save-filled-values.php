<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$document_id = $input['document_id'] ?? 0;
$values = $input['values'] ?? [];
$signature = $input['signature'] ?? '';
$signerName = $input['signer_name'] ?? 'User';

if (!$document_id) {
    echo json_encode(['success' => false, 'message' => 'Document ID required']);
    exit;
}

try {
    $db = getDB();
    
    // Get document info
    $stmt = $db->prepare("SELECT original_path, filled_path FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }
    
    // Save filled values to database
    $stmt = $db->prepare("DELETE FROM filled_data WHERE document_id = ?");
    $stmt->execute([$document_id]);
    
    foreach ($values as $fieldName => $fieldValue) {
        if ($fieldValue && $fieldValue !== '' && $fieldValue !== 'Not found' && $fieldValue !== '[FILLED]') {
            $stmt = $db->prepare("INSERT INTO filled_data (document_id, field_name, field_value) VALUES (?, ?, ?)");
            $stmt->execute([$document_id, $fieldName, $fieldValue]);
        }
    }
    
    // Save signature if provided
    $signaturePath = null;
    $signatureUrl = null;
    
    if ($signature && $signature !== '' && strpos($signature, 'data:image') === 0) {
        // Create signatures directory if not exists
        $sigDir = __DIR__ . '/../uploads/signatures/';
        if (!file_exists($sigDir)) {
            mkdir($sigDir, 0777, true);
        }
        
        $filename = 'sig_' . $document_id . '_' . time() . '.png';
        $signaturePath = $sigDir . $filename;
        $signatureUrl = 'uploads/signatures/' . $filename;
        
        // Remove the data URL prefix
        $signatureData = str_replace('data:image/png;base64,', '', $signature);
        $signatureData = str_replace(' ', '+', $signatureData);
        $imageData = base64_decode($signatureData);
        
        // Save the image file
        $bytesWritten = file_put_contents($signaturePath, $imageData);
        
        if ($bytesWritten === false) {
            echo json_encode(['success' => false, 'message' => 'Failed to save signature image']);
            exit;
        }
        
        // Log signature audit
        $stmt = $db->prepare("INSERT INTO signature_audit (document_id, signer_name, signature_image_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $document_id,
            $signerName,
            $signatureUrl,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    } else {
        // Still log signature even if no image (typed name)
        $stmt = $db->prepare("INSERT INTO signature_audit (document_id, signer_name, signature_image_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $document_id,
            $signerName,
            null,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    }
    
    // Update document status to signed
    $stmt = $db->prepare("UPDATE documents SET status = 'signed' WHERE id = ?");
    $stmt->execute([$document_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Document saved successfully with signature',
        'document_id' => $document_id,
        'signature_path' => $signatureUrl ?? null
    ]);
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>