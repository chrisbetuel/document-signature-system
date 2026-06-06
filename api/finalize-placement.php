<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$documentId = (int)($input['document_id'] ?? 0);
$placement = $input['placement'] ?? null;

if (!$documentId || !$placement) {
    echo json_encode(['success' => false, 'message' => 'Document ID and placement required']);
    exit;
}

try {
    $db = getDB();

    // Get document
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$documentId]);
    $document = $stmt->fetch();

    if (!$document) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }

    // Get signature from audit
    $stmt = $db->prepare("SELECT signature_image_path, signer_name FROM signature_audit WHERE document_id = ? ORDER BY signed_at DESC LIMIT 1");
    $stmt->execute([$documentId]);
    $sigInfo = $stmt->fetch();

    // Use filled_path or fallback to original_path
    $sourcePath = null;
    if ($document['filled_path'] && file_exists($document['filled_path'])) {
        $sourcePath = $document['filled_path'];
    } elseif ($document['original_path'] && file_exists($document['original_path'])) {
        $sourcePath = $document['original_path'];
    }

    // Extract document content
    $displayContent = '';
    if ($sourcePath) {
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($ext == 'docx') {
            $zip = new ZipArchive();
            if ($zip->open($sourcePath) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                if ($xml !== false) {
                    $displayContent = strip_tags($xml);
                    $displayContent = html_entity_decode($displayContent, ENT_QUOTES, 'UTF-8');
                }
            }
        } elseif ($ext == 'txt') {
            $displayContent = file_get_contents($sourcePath);
        }
    }

    // Encode signature image
    $signatureImgData = '';
    $hasSignature = false;
    if ($sigInfo && $sigInfo['signature_image_path']) {
        $sigFullPath = __DIR__ . '/../' . $sigInfo['signature_image_path'];
        if (file_exists($sigFullPath)) {
            $signatureImgData = base64_encode(file_get_contents($sigFullPath));
            $hasSignature = true;
        }
    }

    $px = (int)($placement['x'] ?? 50);
    $py = (int)($placement['y'] ?? 50);
    $pw = (int)($placement['width'] ?? 180);
    $ph = (int)($placement['height'] ?? 60);

    // Save placement to DB
    $placementJson = json_encode([
        'x' => $px,
        'y' => $py,
        'width' => $pw,
        'height' => $ph,
        'signed_at' => date('Y-m-d H:i:s')
    ]);
    $stmt = $db->prepare("UPDATE documents SET signature_placement = ?, status = 'signed' WHERE id = ?");
    $stmt->execute([$placementJson, $documentId]);

    // Generate final HTML
    $finalFilename = 'final_' . $documentId . '_' . time() . '.html';
    $finalPath = OUTPUT_DIR . $finalFilename;
    if (!file_exists(OUTPUT_DIR)) {
        mkdir(OUTPUT_DIR, 0777, true);
    }

    $escapedContent = htmlspecialchars($displayContent);
    $signerName = htmlspecialchars($sigInfo['signer_name'] ?? 'User');

    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Signed Document - ' . htmlspecialchars($document['original_filename']) . '</title>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:"Times New Roman",Times,serif;font-size:12pt;line-height:1.6;padding:40px;background:#fff;color:#000;max-width:800px;margin:0 auto}
    .header{text-align:center;margin-bottom:30px;border-bottom:2px solid #1A2A4A;padding-bottom:10px}
    .content{white-space:pre-wrap;min-height:600px;position:relative}
    .footer{margin-top:50px;text-align:center;font-size:8pt;color:#999}
</style>
</head>
<body>
    <div class="header">
        <h1>' . htmlspecialchars($document['original_filename']) . '</h1>
        <p>Signed: ' . date('F j, Y g:i A') . '</p>
    </div>
    <div class="content">' . nl2br($escapedContent) .
        ($hasSignature ? '<img src="data:image/png;base64,' . $signatureImgData . '" alt="Signature" style="position:absolute;left:' . $px . 'px;top:' . $py . 'px;max-width:' . $pw . 'px;max-height:' . $ph . 'px;" />' : '') .
        '<div style="position:absolute;left:' . $px . 'px;top:' . ($py + $ph + 4) . 'px;font-size:9pt;color:#999;">Signed by ' . $signerName . ' on ' . date('F j, Y \a\t g:i A') . '</div>
    </div>
    <div class="footer">This document was digitally signed using DocSign System.</div>
</body>
</html>';

    file_put_contents($finalPath, $html);

    $finalUrl = 'output/' . $finalFilename;

    echo json_encode([
        'success' => true,
        'document_id' => $documentId,
        'final_url' => $finalUrl,
        'message' => 'Document finalized with signature'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
