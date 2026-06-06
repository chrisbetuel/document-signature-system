<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$documentId = (int)($input['document_id'] ?? 0);
$status = $input['status'] ?? '';

if (!$documentId || !$status) {
    echo json_encode(['success' => false, 'message' => 'Document ID and status required']);
    exit;
}

$allowed = ['editing', 'filled', 'signed'];
if (!in_array($status, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->prepare("UPDATE documents SET status = ? WHERE id = ?");
    $stmt->execute([$status, $documentId]);
    echo json_encode(['success' => true, 'message' => 'Status updated']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
