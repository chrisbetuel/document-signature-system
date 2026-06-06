<?php
require_once '../includes/config.php';

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

if (!$document_id) {
    http_response_code(400);
    die('Document ID required');
}

try {
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if (!$document) {
        http_response_code(404);
        die('Document not found');
    }

    $sourcePath = null;
    if ($document['filled_path'] && file_exists($document['filled_path'])) {
        $sourcePath = $document['filled_path'];
    } elseif ($document['original_path'] && file_exists($document['original_path'])) {
        $sourcePath = $document['original_path'];
    }

    if (!$sourcePath) {
        http_response_code(404);
        die('Source file not found');
    }

    $stmt = $db->prepare("SELECT signature_image_path, signer_name FROM signature_audit WHERE document_id = ? ORDER BY signed_at DESC LIMIT 1");
    $stmt->execute([$document_id]);
    $sigInfo = $stmt->fetch();

    if (!$sigInfo || !$sigInfo['signature_image_path']) {
        http_response_code(404);
        die('No signature found for this document');
    }

    $sigFullPath = __DIR__ . '/../' . ltrim($sigInfo['signature_image_path'], '/');
    if (!file_exists($sigFullPath)) {
        http_response_code(404);
        die('Signature image file not found');
    }

    $signerName = htmlspecialchars($sigInfo['signer_name'] ?? 'User', ENT_XML1, 'UTF-8');
    $signedDate = date('F j, Y \a\t g:i A');

    $tempPath = sys_get_temp_dir() . '/signed_' . $document_id . '_' . time() . '.docx';
    if (!copy($sourcePath, $tempPath)) {
        http_response_code(500);
        die('Failed to copy document');
    }

    $zip = new ZipArchive();
    if ($zip->open($tempPath) !== true) {
        http_response_code(500);
        die('Failed to open document as ZIP');
    }

    // Add signature image to media folder
    $sigImageData = file_get_contents($sigFullPath);
    $zip->addFromString('word/media/signature.png', $sigImageData);

    // Read and update relationships
    $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
    $rels = simplexml_load_string($relsXml);

    $maxRid = 0;
    foreach ($rels->Relationship as $rel) {
        $rid = (string)$rel['Id'];
        $num = intval(substr($rid, 3));
        if ($num > $maxRid) $maxRid = $num;
    }
    $newRid = $maxRid + 1;
    $ridStr = 'rId' . $newRid;

    $newRel = $rels->addChild('Relationship', '');
    $newRel['Id'] = $ridStr;
    $newRel['Type'] = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';
    $newRel['Target'] = 'media/signature.png';

    $zip->addFromString('word/_rels/document.xml.rels', $rels->asXML());

    // Read document.xml
    $docXml = $zip->getFromName('word/document.xml');
    if ($docXml === false) {
        http_response_code(500);
        die('Failed to read document XML');
    }

    // Calculate image size in EMU (English Metric Units)
    $sigSize = getimagesize($sigFullPath);
    $sigWidthPx = $sigSize[0];
    $sigHeightPx = $sigSize[1];
    $dpi = 96;
    $sigWidthEmu = $sigWidthPx * 914400 / $dpi;
    $sigHeightEmu = $sigHeightPx * 914400 / $dpi;

    // Cap at reasonable size
    if ($sigWidthEmu > 1828800) {
        $ratio = 1828800 / $sigWidthEmu;
        $sigWidthEmu = 1828800;
        $sigHeightEmu = $sigHeightEmu * $ratio;
    }
    if ($sigHeightEmu > 914400) {
        $ratio = 914400 / $sigHeightEmu;
        $sigHeightEmu = 914400;
        $sigWidthEmu = $sigWidthEmu * $ratio;
    }

    $ts = time();

    // Build the XML for a new paragraph containing the signature info + image
    $sigXml = '<w:p xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
        <w:r><w:br w:type="page"/></w:r>
        <w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="28"/><w:b/><w:color w:val="1A2A4A"/></w:rPr><w:t>Digital Signature</w:t></w:r>
        <w:r><w:br/></w:r>
        <w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/><w:color w:val="444444"/></w:rPr><w:t>Signed by: ' . $signerName . '</w:t></w:r>
        <w:r><w:br/></w:r>
        <w:r>
            <w:drawing>
                <wp:inline distT="0" distB="0" distL="0" distR="0">
                    <wp:extent cx="' . (int)$sigWidthEmu . '" cy="' . (int)$sigHeightEmu . '"/>
                    <wp:effectExtent l="0" t="0" r="0" b="0"/>
                    <wp:docPr id="' . $ts . '" name="Signature" descr="Digital Signature"/>
                    <wp:cNvGraphicFramePr>
                        <a:graphicFrameLocks noChangeAspect="1"/>
                    </wp:cNvGraphicFramePr>
                    <a:graphic>
                        <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                            <pic:pic>
                                <pic:nvPicPr>
                                    <pic:cNvPr id="0" name="Signature"/>
                                    <pic:cNvPicPr/>
                                </pic:nvPicPr>
                                <pic:blipFill>
                                    <a:blip r:embed="' . $ridStr . '"/>
                                    <a:stretch><a:fillRect/></a:stretch>
                                </pic:blipFill>
                                <pic:spPr>
                                    <a:xfrm>
                                        <a:off x="0" y="0"/>
                                        <a:ext cx="' . (int)$sigWidthEmu . '" cy="' . (int)$sigHeightEmu . '"/>
                                    </a:xfrm>
                                    <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                                </pic:spPr>
                            </pic:pic>
                        </a:graphicData>
                    </a:graphic>
                </wp:inline>
            </w:drawing>
        </w:r>
        <w:r><w:br/></w:r>
        <w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="20"/><w:color w:val="888888"/></w:rPr><w:t>Date: ' . $signedDate . '</w:t></w:r>
        <w:r><w:br/></w:r>
        <w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="18"/><w:i/><w:color w:val="AAAAAA"/></w:rPr><w:t>This document was digitally signed using DocSign System.</w:t></w:r>
    </w:p>';

    // Check for section properties and insert signature before them
    $injectBefore = '</w:body>';
    $sectPrEnd = strrpos($docXml, '</w:sectPr>');
    if ($sectPrEnd !== false) {
        $injectBefore = '</w:sectPr>';
    }

    $docXml = str_replace($injectBefore, $sigXml . $injectBefore, $docXml);

    if (!str_contains($docXml, $sigXml)) {
        http_response_code(500);
        die('Failed to inject signature into document XML');
    }

    $zip->addFromString('word/document.xml', $docXml);
    $zip->close();

    // Update document status
    $stmt = $db->prepare("UPDATE documents SET status = 'signed' WHERE id = ?");
    $stmt->execute([$document_id]);

    // Output the signed docx
    $filename = pathinfo($document['original_filename'] ?? 'document', PATHINFO_FILENAME) . '_signed.docx';
    $filename = preg_replace('/[^a-zA-Z0-9_\-\.\(\) ]/', '', $filename);

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tempPath));
    header('Cache-Control: no-cache');
    readfile($tempPath);
    unlink($tempPath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Error: ' . $e->getMessage());
}
