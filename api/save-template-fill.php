<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$categoryId = (int)($input['category_id'] ?? 0);
$values = $input['values'] ?? [];
$signature = $input['signature'] ?? '';
$signerName = $input['signer_name'] ?? 'User';

if (!$categoryId) {
    echo json_encode(['success' => false, 'message' => 'Category ID required']);
    exit;
}

try {
    $db = getDB();

    // Get category fields_schema
    $stmt = $db->prepare("SELECT name, fields_schema FROM categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $category = $stmt->fetch();

    if (!$category) {
        echo json_encode(['success' => false, 'message' => 'Category not found']);
        exit;
    }

    // Build gaps_json from fields_schema or fallback to template gaps
    $fieldsSchema = json_decode($category['fields_schema'] ?? '[]', true);
    $gaps = [];

    if (is_array($fieldsSchema) && count($fieldsSchema) > 0) {
        foreach ($fieldsSchema as $f) {
            $name = is_string($f) ? $f : ($f['name'] ?? '');
            if ($name !== '') {
                $gaps[] = ['name' => $name, 'type' => 'text', 'required' => true, 'placeholder' => '[' . $name . ']'];
            }
        }
    }

    // If no fields_schema, try to get from template gaps
    if (empty($gaps)) {
        $tplStmt = $db->prepare("SELECT gaps_json FROM documents WHERE category_id = ? AND status = 'template' ORDER BY id DESC LIMIT 1");
        $tplStmt->execute([$categoryId]);
        $tplRow = $tplStmt->fetch();
        if ($tplRow && !empty($tplRow['gaps_json'])) {
            $gaps = json_decode($tplRow['gaps_json'], true);
            if (!is_array($gaps)) $gaps = [];
        }
    }

    $gapsJson = json_encode($gaps);
    $catName = $category['name'] ?? 'Unnamed';

    // Create a new document with status 'gap_detected'
    $stmt = $db->prepare("INSERT INTO documents (category_id, original_filename, original_path, gaps_json, status) VALUES (?, ?, NULL, ?, 'gap_detected')");
    $stmt->execute([
        $categoryId,
        'Filled - ' . $catName . ' - ' . date('Y-m-d H:i'),
        $gapsJson,
    ]);

    $documentId = (int)$db->lastInsertId();

    // Save filled values
    $stmt = $db->prepare("DELETE FROM filled_data WHERE document_id = ?");
    $stmt->execute([$documentId]);

    foreach ($values as $fieldName => $fieldValue) {
        if ($fieldValue !== '' && $fieldValue !== null) {
            $stmt = $db->prepare("INSERT INTO filled_data (document_id, field_name, field_value) VALUES (?, ?, ?)");
            $stmt->execute([$documentId, $fieldName, $fieldValue]);
        }
    }

    // Save signature if provided
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

        $stmt = $db->prepare("INSERT INTO signature_audit (document_id, signer_name, signature_image_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $documentId,
            $signerName,
            $signatureUrl,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    } else {
        $stmt = $db->prepare("INSERT INTO signature_audit (document_id, signer_name, signature_image_path, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $documentId,
            $signerName,
            null,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    }

    // Update status to signed
    $stmt = $db->prepare("UPDATE documents SET status = 'signed' WHERE id = ?");
    $stmt->execute([$documentId]);

    echo json_encode([
        'success' => true,
        'document_id' => $documentId,
        'category_name' => $catName,
        'message' => 'Document created and signed successfully'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
