<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$documentId = (int)($input['document_id'] ?? 0);
$signature = $input['signature'] ?? '';
$signerName = $input['signer_name'] ?? 'User';

if (!$documentId) {
    echo json_encode(['success' => false, 'message' => 'Document ID required']);
    exit;
}

try {
    $db = getDB();

    // Check document exists
    $stmt = $db->prepare("SELECT id FROM documents WHERE id = ?");
    $stmt->execute([$documentId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }

    $signatureUrl = null;

    if ($signature && $signature !== '' && strpos($signature, 'data:image') === 0) {
        $sigDir = __DIR__ . '/../uploads/signatures/';
        if (!file_exists($sigDir)) {
            mkdir($sigDir, 0777, true);
        }

        $filename = 'sig_' . $documentId . '_' . time() . '.png';
        $signaturePath = $sigDir . $filename;
        $signatureUrl = 'uploads/signatures/' . $filename;

        $signatureData = str_replace('data:image/png;base64,', '', $signature);
        $signatureData = str_replace(' ', '+', $signatureData);
        $imageData = base64_decode($signatureData);

        file_put_contents($signaturePath, $imageData);
    }

    // Create signature audit record
    $stmt = $db->prepare("INSERT INTO signature_audit (document_id, signer_name, signature_image_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $documentId,
        $signerName,
        $signatureUrl,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);

    // Update status to signed
    $stmt = $db->prepare("UPDATE documents SET status = 'signed' WHERE id = ?");
    $stmt->execute([$documentId]);

    echo json_encode([
        'success' => true,
        'document_id' => $documentId,
        'message' => 'Document signed successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
