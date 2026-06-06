<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

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

    $stmt = $db->prepare("SELECT signature_image_path, signer_name FROM signature_audit WHERE document_id = ? ORDER BY signed_at DESC LIMIT 1");
    $stmt->execute([$document_id]);
    $sigInfo = $stmt->fetch();

    if (!$sigInfo || !$sigInfo['signature_image_path']) {
        echo json_encode(['success' => false, 'message' => 'No signature found. Save a signature first.']);
        exit;
    }

    $sigFullPath = __DIR__ . '/../' . ltrim($sigInfo['signature_image_path'], '/');
    if (!file_exists($sigFullPath)) {
        echo json_encode(['success' => false, 'message' => 'Signature image file not found']);
        exit;
    }

    // Use filled_path or fall back to original_path
    $sourcePath = null;
    if ($document['filled_path'] && file_exists($document['filled_path'])) {
        $sourcePath = $document['filled_path'];
    } elseif ($document['original_path'] && file_exists($document['original_path'])) {
        $sourcePath = $document['original_path'];
    }

    if (!$sourcePath) {
        echo json_encode(['success' => false, 'message' => 'Source document not found']);
        exit;
    }

    $signerName = htmlspecialchars($sigInfo['signer_name'] ?? 'User', ENT_XML1, 'UTF-8');
    $signedDate = date('F j, Y \a\t g:i A');

    // Create a copy for placement (preserve the original filled file)
    $placementFilename = 'placement_' . $document_id . '_' . time() . '.docx';
    $placementPath = FILLED_DIR . $placementFilename;
    if (!copy($sourcePath, $placementPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create placement copy']);
        exit;
    }

    // Embed signature into the placement copy
    $zip = new ZipArchive();
    if ($zip->open($placementPath) !== true) {
        unlink($placementPath);
        echo json_encode(['success' => false, 'message' => 'Failed to open document']);
        exit;
    }

    // Add signature image to media folder
    $sigImageData = file_get_contents($sigFullPath);
    $zip->addFromString('word/media/signature.png', $sigImageData);

    // Update relationships
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    $rels = simplexml_load_string($relsXml);
    $maxRid = 0;
    foreach ($rels->Relationship as $rel) {
        $rid = (string)$rel['Id'];
        $num = intval(substr($rid, 3));
        if ($num > $maxRid) $maxRid = $num;
    }
    $ridStr = 'rId' . ($maxRid + 1);

    $newRel = $rels->addChild('Relationship', '');
    $newRel['Id'] = $ridStr;
    $newRel['Type'] = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';
    $newRel['Target'] = 'media/signature.png';
    $zip->addFromString('word/_rels/document.xml.rels', $rels->asXML());

    // Read and modify document.xml
    $docXml = $zip->getFromName('word/document.xml');
    if ($docXml === false) {
        $zip->close(); unlink($placementPath);
        echo json_encode(['success' => false, 'message' => 'Failed to read document XML']);
        exit;
    }

    $sigSize = getimagesize($sigFullPath);
    $sigWidthEmu = $sigSize[0] * 914400 / 96;
    $sigHeightEmu = $sigSize[1] * 914400 / 96;

    if ($sigWidthEmu > 1828800) { $r = 1828800 / $sigWidthEmu; $sigWidthEmu = 1828800; $sigHeightEmu *= $r; }
    if ($sigHeightEmu > 914400) { $r = 914400 / $sigHeightEmu; $sigHeightEmu = 914400; $sigWidthEmu *= $r; }

    $ts = time();

    $anchorX = 3000000;
    $anchorY = 500000;

    $sigXml = '<w:p xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml">
        <w:r>
            <w:drawing>
                <wp:anchor distT="0" distB="0" distL="0" distR="0" simplePos="0" relativeHeight="251658240" behindDoc="0" locked="0" layoutInCell="1" allowOverlap="1">
                    <wp:simplePos x="0" y="0"/>
                    <wp:positionH relativeFrom="page"><wp:posOffset>' . $anchorX . '</wp:posOffset></wp:positionH>
                    <wp:positionV relativeFrom="page"><wp:posOffset>' . $anchorY . '</wp:posOffset></wp:positionV>
                    <wp:extent cx="' . (int)$sigWidthEmu . '" cy="' . (int)$sigHeightEmu . '"/>
                    <wp:effectExtent l="0" t="0" r="0" b="0"/>
                    <wp:wrapNone/>
                    <wp:docPr id="' . $ts . '" name="Signature" descr="Drag this signature to your desired position"/>
                    <wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>
                    <a:graphic>
                        <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                            <pic:pic>
                                <pic:nvPicPr><pic:cNvPr id="0" name="Signature"/><pic:cNvPicPr/></pic:nvPicPr>
                                <pic:blipFill><a:blip r:embed="' . $ridStr . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>
                                <pic:spPr>
                                    <a:xfrm><a:off x="0" y="0"/><a:ext cx="' . (int)$sigWidthEmu . '" cy="' . (int)$sigHeightEmu . '"/></a:xfrm>
                                    <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                                </pic:spPr>
                            </pic:pic>
                        </a:graphicData>
                    </a:graphic>
                </wp:anchor>
            </w:drawing>
        </w:r>
    </w:p>';

    // Inject at the very top of the document body so the floating signature is visible immediately
    $bodyStart = strpos($docXml, '<w:body');
    $bodyEnd = strpos($docXml, '>', $bodyStart);
    $injectPos = $bodyEnd + 1;
    $docXml = substr_replace($docXml, $sigXml, $injectPos, 0);
    $zip->addFromString('word/document.xml', $docXml);
    $zip->close();

    // Update document record with placement path and status
    $stmt = $db->prepare("UPDATE documents SET filled_path = ?, status = 'signed' WHERE id = ?");
    $stmt->execute([$placementPath, $document_id]);

    // Open in Word via exec
    $realPath = realpath($placementPath);
    $escaped = escapeshellarg($realPath);
    $wordOpened = false;

    $commands = [
        'start "" ' . $escaped,
        'cmd /c start "" ' . $escaped,
        'powershell -NoProfile -Command "Start-Process \'' . addslashes($realPath) . '\'"',
    ];

    foreach ($commands as $cmd) {
        exec($cmd, $output, $code);
        if ($code === 0) { $wordOpened = true; break; }
    }

    echo json_encode([
        'success' => true,
        'word_opened' => $wordOpened,
        'document_id' => $document_id,
        'message' => $wordOpened
            ? 'Signature embedded and document opened in Word. Drag the signature to position it, then press Ctrl+S to save.'
            : 'Document is ready. Download and open manually.',
        'download_url' => 'api/download.php?document_id=' . $document_id,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
