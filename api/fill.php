<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST method allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$document_id = $input['document_id'] ?? 0;
$filledValues = $input['values'] ?? [];
$signatureData = $input['signature'] ?? '';

if (!$document_id) {
    echo json_encode(['success' => false, 'message' => 'Document ID required']);
    exit;
}

try {
    $db = getDB();
    
    // Get document
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }
    
    // Save signature if provided
    $signaturePath = null;
    if ($signatureData && $signatureData !== '✍️ [SIGNED]') {
        $signaturePath = SIGNATURE_DIR . 'sig_' . $document_id . '_' . time() . '.png';
        $signatureDataClean = str_replace('data:image/png;base64,', '', $signatureData);
        $signatureDataClean = str_replace(' ', '+', $signatureDataClean);
        file_put_contents($signaturePath, base64_decode($signatureDataClean));
    }
    
    // Delete existing filled data for this document
    $stmt = $db->prepare("DELETE FROM filled_data WHERE document_id = ?");
    $stmt->execute([$document_id]);
    
    // Save new filled data
    foreach ($filledValues as $fieldName => $fieldValue) {
        if ($fieldValue && $fieldValue !== '_______' && $fieldValue !== '[SIGNATURE NEEDED]') {
            $stmt = $db->prepare("INSERT INTO filled_data (document_id, field_name, field_value) VALUES (?, ?, ?)");
            $stmt->execute([$document_id, $fieldName, $fieldValue]);
        }
    }
    
    // Update document status
    $stmt = $db->prepare("UPDATE documents SET status = 'filled', filled_path = ? WHERE id = ?");
    $stmt->execute([$document['original_path'], $document_id]);
    
    // Log signature audit if signature was provided
    if ($signaturePath) {
        $signerName = $filledValues['Employee Name'] ?? $filledValues['Name'] ?? $filledValues['Signature'] ?? 'Unknown';
        $stmt = $db->prepare("INSERT INTO signature_audit (document_id, signer_name, signature_image_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $document_id,
            $signerName,
            $signaturePath,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Document saved successfully',
        'download_url' => "api/download.php?document_id={$document_id}"
    ]);
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>