<?php
// Allow iframe preview endpoints to be fetched directly.
// Some browsers/clients may treat this as sensitive; keep it simple and rely on Apache auth rules.
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';


$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

if (!$document_id) {
    die('Document ID is required');
}

try {
    $db = getDB();
    
    // Get document with filled values
    $stmt = $db->prepare("
        SELECT d.*, c.name as category_name 
        FROM documents d 
        LEFT JOIN categories c ON d.category_id = c.id 
        WHERE d.id = ?
    ");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();
    
    if (!$document) {
        die('Document not found');
    }
    
    // Get filled values from database
    $stmt = $db->prepare("SELECT field_name, field_value FROM filled_data WHERE document_id = ?");
    $stmt->execute([$document_id]);
    $filledData = $stmt->fetchAll();
    
    $filledValues = [];
    foreach ($filledData as $item) {
        $filledValues[$item['field_name']] = $item['field_value'];
    }
    
    // Get signature
    $stmt = $db->prepare("SELECT signature_image_path, signer_name, signed_at, ip_address FROM signature_audit WHERE document_id = ? ORDER BY signed_at DESC LIMIT 1");
    $stmt->execute([$document_id]);
    $signature = $stmt->fetch();
    
    // Get the content to display
    $displayContent = '';
    $embeddedPreviewHtml = '';
    $fileToShowAbs = null; // absolute filesystem path
    $fileToShowRel = null; // relative to project root for URL usage
    $ext = null;

    // Prefer the filled document (uploaded/created by user)
    if (!empty($document['filled_path']) && file_exists($document['filled_path'])) {
        $fileToShowAbs = $document['filled_path'];
        $ext = strtolower(pathinfo($fileToShowAbs, PATHINFO_EXTENSION));
    } elseif (!empty($document['original_path']) && file_exists($document['original_path'])) {
        // Fallback to original document
        $fileToShowAbs = $document['original_path'];
        $ext = strtolower(pathinfo($fileToShowAbs, PATHINFO_EXTENSION));
    }

    // Convert absolute filesystem path to a relative URL path
    $fileRelPath = '';
    if ($fileToShowAbs) {
        $resolved = realpath($fileToShowAbs);
        if ($resolved !== false) {
            $normalized = str_replace('\\', '/', $resolved);
            $fileRelPath = str_replace(PROJECT_ROOT . '/', '', $normalized);
        }
    }

    // 1) Try to embed the document (keeps template/format for PDF; DOCX may not render, but we keep a fallback link)
    $pdfUrlForJs = ''; // set for PDF.js inline rendering
    if ($fileToShowAbs && $ext) {
        if ($ext === 'pdf') {
            $pdfUrlForJs = '/document-signature-system/' . $fileRelPath;
            // No iframe — PDF is rendered inline via PDF.js so stamps track document pages
        } elseif ($ext === 'docx') {
            $docxUrl = '/document-signature-system/' . $fileRelPath;
            // Attempt inline viewing (often still downloads in many browsers); provide a visible open/download link.
            $embeddedPreviewHtml = '
                <div style="margin-bottom:12px; font-size:13px; color:#666;">
                    DOCX inline preview may not be supported by your browser. Use the link below to open the document as-is.
                </div>
                <iframe src="' . htmlspecialchars($docxUrl) . '" style="width:100%; height:650px; border:1px dashed #E8E8E8; border-radius:8px;" title="DOCX Preview"></iframe>
                <div style="margin-top:12px;">
                    <a class="btn" style="margin:0; display:inline-block;" href="' . htmlspecialchars($docxUrl) . '">⬇️ Open DOCX</a>
                </div>
            ';
        } elseif ($ext === 'txt') {
            // For txt we use text extraction rendering below (so we can still show placeholders nicely)
        } else {
            // Unknown type: rely on text extraction if possible
        }
    }

    // 2) Build fallback text extraction (works for TXT; for DOCX it’s plain-text XML fallback)
    if (empty($embeddedPreviewHtml)) {
        // First try to use the filled document content for txt/docx
        if ($fileToShowAbs && $ext === 'docx') {
            $zip = new ZipArchive();
            if ($zip->open($fileToShowAbs) === true) {
                $xml = $zip->getFromName('word/document.xml');
                $zip->close();
                $displayContent = strip_tags($xml);
                $displayContent = html_entity_decode($displayContent, ENT_QUOTES, 'UTF-8');
            }
        } elseif ($fileToShowAbs && $ext === 'txt') {
            $displayContent = file_get_contents($fileToShowAbs);
        }

        // If we’re using original doc (not filled), replace placeholders with filled values
        if ($document['original_path'] && $fileToShowAbs === $document['original_path']) {
            $gaps = json_decode($document['gaps_json'], true);
            if ($gaps && is_array($gaps)) {
                foreach ($gaps as $gap) {
                    if (isset($gap['placeholder']) && !empty($gap['placeholder'])) {
                        $placeholder = (string)$gap['placeholder'];
                        $fieldName = isset($gap['name']) ? (string)$gap['name'] : '';
                        $value = '';

                        if ($fieldName && isset($filledValues[$fieldName]) && !empty($filledValues[$fieldName])) {
                            $value = (string)$filledValues[$fieldName];
                        } else {
                            $value = $placeholder;
                        }

                        $displayContent = str_replace($placeholder, $value, (string)$displayContent);
                    }
                }
            }

            // Replace underscore placeholders
            $displayContent = preg_replace('/_{3,}/', '[FILLED]', (string)$displayContent);
        }

        if (trim((string)$displayContent) === '') {
            // Prevent “only signature” look due to empty extraction
            $displayContent = '';
        }
    }

    // Identify signature placeholders from gaps_json
    $gaps = json_decode($document['gaps_json'], true) ?: [];
    $signaturePlaceholders = [];
    $hasSignatureGap = false;
    foreach ($gaps as $gap) {
        if (isset($gap['type']) && $gap['type'] === 'signature') {
            $hasSignatureGap = true;
            if (!empty($gap['placeholder'])) {
                $signaturePlaceholders[] = (string)$gap['placeholder'];
            }
        }
    }

    // Output as HTML
    header('Content-Type: text/html');
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($document['original_filename']); ?> - Signed Document</title>
        <style>
            body {
                font-family: 'Times New Roman', Times, serif;
                font-size: 12pt;
                line-height: 1.5;
                margin: 40px;
                color: #1A2A4A;
                background: white;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #1A2A4A;
                padding-bottom: 10px;
            }
            .header h1 {
                font-size: 18pt;
                color: #1A2A4A;
                margin-bottom: 10px;
            }
            .content {
                margin-bottom: 30px;
                white-space: pre-wrap;
                font-family: 'Times New Roman', Times, serif;
                font-size: 12pt;
                line-height: 1.6;
                background: white;
                padding: 20px;
                border: 1px solid #eee;
                min-height: 300px;
            }

            .sig-stage {
                position: relative;
                display: inline-block;
                width: 100%;
                overflow: hidden;
                user-select: none;
            }
            .sig-stamp {
                position: absolute;
                z-index: 10;
                cursor: grab;
                touch-action: none;
            }
            .sig-stamp:active { cursor: grabbing; }
            .sig-stamp:hover .sig-remove-btn,
            .sig-stamp:hover .sig-resize-handle,
            .sig-stamp:hover .sig-lock-btn { display: block; }
            .sig-stamp-rotator {
                pointer-events: none;
                transform-origin: center center;
            }
            .sig-stamp-content {
                pointer-events: none;
                display: inline-block;
                text-align: center;
            }
            .sig-stamp-label {
                font-size: 9px;
                color: #333;
                border-top: 1px solid #666;
                padding-top: 3px;
                margin-top: 1px;
                white-space: nowrap;
            }
            .sig-remove-btn {
                display: none;
                position: absolute;
                top: -10px;
                right: -10px;
                width: 20px;
                height: 20px;
                background: #e74c3c;
                color: #fff;
                border: 2px solid #fff;
                border-radius: 50%;
                cursor: pointer;
                font-size: 14px;
                line-height: 16px;
                text-align: center;
                z-index: 20;
                box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            }
            .sig-resize-handle {
                display: none;
                position: absolute;
                bottom: -6px;
                right: -6px;
                width: 14px;
                height: 14px;
                background: #FFBF00;
                border: 2px solid #1A2A4A;
                border-radius: 2px;
                cursor: nwse-resize;
                z-index: 20;
            }
            .sig-add-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 16px;
                margin: 0 6px;
                background: #2ecc71;
                color: #fff;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
            }
            .sig-add-btn:hover { background: #27ae60; }
            .sig-snap-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 16px;
                margin: 0 6px;
                background: #3498db;
                color: #fff;
                border: none;
                border-radius: 6px;
                cursor: pointer;
                font-size: 13px;
                font-weight: 500;
            }
            .sig-snap-btn:hover { background: #2980b9; }
            .sig-snap-btn.active { background: #e67e22; }
            .sig-lock-btn {
                display: none;
                position: absolute;
                top: -10px;
                left: -10px;
                width: 20px;
                height: 20px;
                background: #f39c12;
                color: #fff;
                border: 2px solid #fff;
                border-radius: 50%;
                cursor: pointer;
                font-size: 12px;
                line-height: 16px;
                text-align: center;
                z-index: 20;
                box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            }
            .sig-lock-btn.locked { background: #2ecc71; }
            .sig-snap-marker {
                position: absolute;
                width: 14px;
                height: 14px;
                margin-left: -7px;
                margin-top: -7px;
                background: rgba(52,152,219,0.25);
                border: 2px dashed #3498db;
                border-radius: 50%;
                z-index: 5;
                cursor: crosshair;
                pointer-events: auto;
            }
            .sig-snap-marker:hover {
                background: rgba(231,76,60,0.3);
                border-color: #e74c3c;
            }
            .sig-snap-marker::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                width: 4px;
                height: 4px;
                margin: -2px 0 0 -2px;
                background: #3498db;
                border-radius: 50%;
            }
            .pdf-page-canvas {
                display: block;
                margin: 0 auto 6px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.12);
                border-radius: 3px;
                background: #fff;
            }

            .footer {
                margin-top: 50px;
                text-align: center;
                font-size: 9pt;
                color: #999;
                padding-top: 20px;
                border-top: 1px solid #eee;
            }
            .btn {
                display: inline-block;
                padding: 10px 20px;
                margin: 10px;
                background: #FFBF00;
                color: #1A2A4A;
                text-decoration: none;
                border-radius: 6px;
                font-weight: 500;
                border: none;
                cursor: pointer;
                font-size: 14px;
            }
            .btn:hover {
                background: #E5AC00;
            }
            .toolbar {
                text-align: center;
                margin-bottom: 20px;
                padding: 15px;
                background: #f5f5f5;
                border-radius: 8px;
            }
            @media print {
                .toolbar, .no-print {
                    display: none;
                }
            }
        </style>
    </head>
    <body>
        <div class="toolbar no-print">
            <button onclick="window.print()" class="btn">🖨️ Print / Save as PDF</button>
            <button class="sig-add-btn" onclick="addSignature()">➕ Add Signature</button>
            <button class="sig-snap-btn" id="snapToggleBtn" onclick="toggleSnapMode()">📍 Set Snap Point</button>
            <a href="download-final-pdf.php?document_id=<?php echo $document_id; ?>" class="btn">⬇️ Download HTML</a>
            <a href="../documents.html" class="btn">← Back to Documents</a>
            <button class="btn" style="background:#DC3545;color:#fff;" onclick="deleteDocument(<?php echo $document_id; ?>)">🗑️ Delete</button>
        </div>
        
        <div class="header">
            <h1><?php echo htmlspecialchars($document['original_filename']); ?></h1>
            <p>Document ID: <?php echo $document_id; ?> | Finalized: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
        
        <?php
        // Build signature data for JavaScript (pass to JS via JSON)
        $signatureImageHtml = '';
        $sigName = '';
        $sigDate = '';
        if ($signature && !empty($signature)) {
            $sigName = htmlspecialchars($signature['signer_name'] ?? 'Unknown');
            $sigDate = date('F j, Y \a\t g:i A', strtotime($signature['signed_at'] ?? 'now'));
            $sigImagePath = $signature['signature_image_path'] ?? null;
            $fullImagePath = __DIR__ . '/../' . $sigImagePath;

            if ($sigImagePath && file_exists($fullImagePath)) {
                $imageUrl = '/document-signature-system/' . $sigImagePath;
                $signatureImageHtml = '<img src="' . $imageUrl . '" alt="Signature">';
            } else {
                $signatureImageHtml = '<div style="font-size:20px;font-family:\'Brush Script MT\',cursive;line-height:1.2;">' . $sigName . '</div>';
            }
        }

        // Signature data payload for JavaScript
        $sigDataJson = json_encode([
            'hasSignature' => !empty($signature) && !empty($signature['signer_name']),
            'imageHtml' => $signatureImageHtml,
            'signerName' => $sigName,
            'date' => $sigDate,
        ]);

        // For text content: replace signature placeholders with the actual signature image
        $replaceSignatureInText = false;
        if (empty($embeddedPreviewHtml) && !empty($displayContent) && !empty($signaturePlaceholders)) {
            $replaceSignatureInText = true;
            $sigBlock = '<span style="display:inline-block; vertical-align:middle;">'
                . str_replace('alt="Signature"', 'style="max-width:140px; max-height:50px; display:block;" alt="Signature"', $signatureImageHtml)
                . '</span>'
                . '<span style="font-size:10px; color:#555; margin-left:6px;">' . $sigName . ' &middot; ' . $sigDate . '</span>';
        }
        ?>

        <div class="content">
            <?php if (!empty($embeddedPreviewHtml) || $pdfUrlForJs): ?>
            <div id="sig-stage" class="sig-stage" data-doc-id="<?php echo $document_id; ?>"<?php if ($pdfUrlForJs): ?> data-pdf-url="<?php echo htmlspecialchars($pdfUrlForJs); ?>"<?php endif; ?>>
                <?php if (!$pdfUrlForJs) { echo $embeddedPreviewHtml; } ?>
            </div>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                var sigStage = document.getElementById('sig-stage');
                if (!sigStage) return;
                var docId = sigStage.getAttribute('data-doc-id');
                var pdfUrl = sigStage.getAttribute('data-pdf-url');

                // Toolbar for signature actions within the stage
                var toolbar = document.createElement('div');
                toolbar.style.cssText = 'text-align:center; padding:6px; background:#f8f8f8; border-radius:0 0 8px 8px; border:1px solid #e8e8e8; border-top:0;';
                toolbar.innerHTML = '<span style="font-size:12px;color:#888;">Drag to position · Resize from corner · Hover for controls</span>';
                sigStage.parentNode.insertBefore(toolbar, sigStage.nextSibling);

                // Signature data from PHP
                var sigData = <?php echo $sigDataJson; ?>;
                var stamps = [];
                var snapPoints = [];
                var snapMode = false;
                var SNAP_THRESHOLD = 35;

                // --- Snap Points ---
                function renderSnapMarkers() {
                    var old = sigStage.querySelectorAll('.sig-snap-marker');
                    old.forEach(function(m) { m.parentNode.removeChild(m); });
                    snapPoints.forEach(function(sp) {
                        var dot = document.createElement('div');
                        dot.className = 'sig-snap-marker';
                        dot.style.left = sp.x + 'px';
                        dot.style.top = sp.y + 'px';
                        dot.title = sp.label || 'Snap point';
                        dot.addEventListener('dblclick', function(e) {
                            e.stopPropagation();
                            snapPoints = snapPoints.filter(function(p) { return p !== sp; });
                            renderSnapMarkers();
                            saveSnapState();
                        });
                        sigStage.appendChild(dot);
                    });
                }

                function saveSnapState() {
                    try { localStorage.setItem('snapState_' + docId, JSON.stringify(snapPoints)); } catch(e) {}
                }
                function loadSnapState() {
                    try {
                        var raw = localStorage.getItem('snapState_' + docId);
                        if (raw) { snapPoints = JSON.parse(raw); renderSnapMarkers(); }
                    } catch(e) {}
                }

                window.toggleSnapMode = function() {
                    snapMode = !snapMode;
                    var btn = document.getElementById('snapToggleBtn');
                    if (btn) {
                        btn.textContent = snapMode ? '📍 Done Placing' : '📍 Set Snap Point';
                        btn.classList.toggle('active', snapMode);
                        sigStage.style.cursor = snapMode ? 'crosshair' : '';
                    }
                };

                // Click on stage in snap mode to place a snap point
                sigStage.addEventListener('click', function(e) {
                    if (!snapMode) return;
                    if (e.target.closest('.sig-stamp') || e.target.closest('.sig-snap-marker')) return;
                    var rect = sigStage.getBoundingClientRect();
                    snapPoints.push({ x: e.clientX - rect.left, y: e.clientY - rect.top, label: 'Snap ' + (snapPoints.length + 1) });
                    renderSnapMarkers();
                    saveSnapState();
                    toggleSnapMode();
                });

                function findNearestSnap(x, y) {
                    var best = null;
                    var bestDist = SNAP_THRESHOLD;
                    snapPoints.forEach(function(sp) {
                        var d = Math.sqrt((sp.x - x) * (sp.x - x) + (sp.y - y) * (sp.y - y));
                        if (d < bestDist) { bestDist = d; best = sp; }
                    });
                    return best;
                }

                // --- Stamp creation ---
                function createStamp(opt) {
                    opt = opt || {};
                    var id = opt.id || Date.now() + '_' + Math.random().toString(36).slice(2,6);
                    var left = opt.left !== undefined ? opt.left : 35;
                    var top_ = opt.top !== undefined ? opt.top : (sigStage.offsetHeight - 100);
                    var w = opt.width || 160;
                    var h = opt.height || 80;
                    var rot = opt.rotation || -2;
                    var locked = opt.locked || false;

                    var el = document.createElement('div');
                    el.className = 'sig-stamp';
                    el.setAttribute('data-id', id);
                    el.style.cssText = 'left:' + left + 'px; top:' + top_ + 'px; opacity:0.85;';

                    // Rotation goes on inner wrapper so left/top aren't affected
                    var rotator = document.createElement('div');
                    rotator.className = 'sig-stamp-rotator';
                    if (rot) rotator.style.transform = 'rotate(' + rot + 'deg)';

                    rotator.innerHTML =
                        '<div class="sig-stamp-content">' +
                            (sigData.imageHtml || ('<div style="font-size:20px;font-family:\'Brush Script MT\',cursive;line-height:1.2;">' + (sigData.signerName || 'Signed') + '</div>')) +
                            '<div class="sig-stamp-label">' + (sigData.signerName || '') + ' &middot; ' + (sigData.date || '') + '</div>' +
                        '</div>';

                    el.appendChild(rotator);
                    var controlsDiv = document.createElement('div');
                    controlsDiv.innerHTML =
                        '<div class="sig-lock-btn" onclick="toggleLock(this)">&#128274;</div>' +
                        '<div class="sig-remove-btn" onclick="removeSignature(this)">&times;</div>' +
                        '<div class="sig-resize-handle"></div>';
                    Array.from(controlsDiv.children).forEach(function(c) { el.appendChild(c); });

                    var img = el.querySelector('img');
                    if (img) { img.style.cssText = 'max-width:' + w + 'px; max-height:' + Math.round(h * 0.7) + 'px; display:block;'; }
                    var textSig = el.querySelector('.sig-stamp-content > div:first-child');
                    if (textSig && !img) { textSig.style.fontSize = Math.round(w / 10) + 'px'; }

                    el._width = w;
                    el._height = h;
                    el._rotation = rot;
                    el._locked = locked;

                    if (locked) { el.style.opacity = '0.7'; }

                    makeDraggable(el, sigStage);
                    makeResizable(el, sigStage);

                    sigStage.appendChild(el);
                    stamps.push({ id: id, el: el });
                    saveState();
                    return el;
                }

                // --- Lock / Unlock ---
                window.toggleLock = function(btn) {
                    var stamp = btn.parentNode;
                    stamp._locked = !stamp._locked;
                    btn.classList.toggle('locked', stamp._locked);
                    stamp.style.opacity = stamp._locked ? '0.7' : '0.85';
                    saveState();
                };

                // --- Drag with snap ---
                function makeDraggable(el, stage) {
                    var offsetX, offsetY, dragging = false;
                    var snapGuide = null;

                    el.addEventListener('mousedown', function(e) {
                        if (e.target.classList.contains('sig-remove-btn') || e.target.classList.contains('sig-resize-handle') || e.target.classList.contains('sig-lock-btn')) return;
                        if (el._locked) return;
                        e.preventDefault();
                        var stageRect = stage.getBoundingClientRect();
                        var curLeft = parseInt(el.style.left) || 0;
                        var curTop = parseInt(el.style.top) || 0;
                        offsetX = e.clientX - stageRect.left - curLeft;
                        offsetY = e.clientY - stageRect.top - curTop;
                        dragging = true;
                        el.style.cursor = 'grabbing';
                        el.style.zIndex = 20;

                        snapGuide = document.createElement('div');
                        snapGuide.style.cssText = 'position:absolute; left:0; top:0; width:100%; height:100%; pointer-events:none; z-index:4; border:2px dashed rgba(52,152,219,0.5); border-radius:4px; box-sizing:border-box;';
                        stage.appendChild(snapGuide);

                        document.addEventListener('mousemove', onMove);
                        document.addEventListener('mouseup', onUp);
                    });

                    function onMove(e) {
                        if (!dragging) return;
                        var stageRect = stage.getBoundingClientRect();
                        var newLeft = e.clientX - stageRect.left - offsetX;
                        var newTop = e.clientY - stageRect.top - offsetY;
                        var snapped = findNearestSnap(newLeft + 80, newTop + 40);
                        if (snapped) {
                            newLeft = snapped.x - 80;
                            newTop = snapped.y - 40;
                            if (snapGuide) { snapGuide.style.borderColor = 'rgba(46,204,113,0.8)'; }
                        } else {
                            if (snapGuide) { snapGuide.style.borderColor = 'rgba(52,152,219,0.5)'; }
                        }
                        el.style.left = newLeft + 'px';
                        el.style.top = newTop + 'px';
                        if (snapGuide) {
                            snapGuide.style.left = newLeft + 'px';
                            snapGuide.style.top = newTop + 'px';
                            snapGuide.style.width = (el._width || 160) + 'px';
                            snapGuide.style.height = (el._height || 80) + 'px';
                        }
                    }

                    function onUp() {
                        if (!dragging) return;
                        dragging = false;
                        el.style.cursor = 'grab';
                        el.style.zIndex = 10;
                        if (snapGuide) { snapGuide.parentNode.removeChild(snapGuide); snapGuide = null; }
                        document.removeEventListener('mousemove', onMove);
                        document.removeEventListener('mouseup', onUp);
                        saveState();
                    }
                }

                // --- Resize ---
                function makeResizable(el, stage) {
                    var handle = el.querySelector('.sig-resize-handle');
                    if (!handle) return;
                    var startX, startY, origW, origH, resizing = false;

                    handle.addEventListener('mousedown', function(e) {
                        if (el._locked) return;
                        e.preventDefault();
                        e.stopPropagation();
                        startX = e.clientX;
                        startY = e.clientY;
                        origW = el._width || 160;
                        origH = el._height || 80;
                        resizing = true;
                        el.style.zIndex = 20;
                        document.addEventListener('mousemove', onResize);
                        document.addEventListener('mouseup', onResizeEnd);
                    });

                    function onResize(e) {
                        if (!resizing) return;
                        var dw = e.clientX - startX;
                        var dh = e.clientY - startY;
                        var newW = Math.max(60, origW + dw);
                        var newH = Math.max(40, origH + dh);
                        el._width = newW;
                        el._height = newH;
                        var img = el.querySelector('img');
                        if (img) {
                            img.style.maxWidth = newW + 'px';
                            img.style.maxHeight = Math.round(newH * 0.7) + 'px';
                        }
                        var textSig = el.querySelector('.sig-stamp-content > div:first-child');
                        if (textSig && !img) { textSig.style.fontSize = Math.round(newW / 10) + 'px'; }
                    }

                    function onResizeEnd() {
                        if (!resizing) return;
                        resizing = false;
                        el.style.zIndex = 10;
                        document.removeEventListener('mousemove', onResize);
                        document.removeEventListener('mouseup', onResizeEnd);
                        saveState();
                    }
                }

                // --- State persistence ---
                function saveState() {
                    var state = [];
                    var items = sigStage.querySelectorAll('.sig-stamp');
                    items.forEach(function(el) {
                        state.push({
                            id: el.getAttribute('data-id') || '',
                            left: parseInt(el.style.left) || 0,
                            top: parseInt(el.style.top) || 0,
                            width: el._width || 160,
                            height: el._height || 80,
                            rotation: el._rotation || -2,
                            locked: el._locked || false
                        });
                    });
                    try { localStorage.setItem('sigState_' + docId, JSON.stringify(state)); } catch(e) {}
                }

                function loadState() {
                    try {
                        var raw = localStorage.getItem('sigState_' + docId);
                        if (!raw) return null;
                        return JSON.parse(raw);
                    } catch(e) { return null; }
                }

                // --- Global functions ---
                window.addSignature = function() {
                    var left = 35 + (stamps.length * 20);
                    var top = 60 + (stamps.length * 20);

                    // Snap to nearest snap point if available
                    if (snapPoints.length > 0) {
                        var sp = findNearestSnap(left + 80, top + 40);
                        if (sp) { left = sp.x - 80; top = sp.y - 40; }
                    }

                    var el = createStamp({ left: left, top: top });
                    el.style.opacity = '0.85';
                    return el;
                };

                window.removeSignature = function(btn) {
                    var stamp = btn.parentNode;
                    if (stamp) { stamp.parentNode.removeChild(stamp); }
                    stamps = stamps.filter(function(s) { return s.el !== stamp; });
                    saveState();
                };

                // --- Initialize ---
                function initStamps() {
                    loadSnapState();
                    var saved = loadState();
                    if (saved && saved.length > 0 && sigData.hasSignature) {
                        saved.forEach(function(s) { createStamp(s); });
                    } else if (sigData.hasSignature) {
                        var stageH = sigStage.offsetHeight;
                        createStamp({ left: 35, top: Math.max(60, stageH - 120) });
                    }
                }

                // If PDF URL is present, render PDF inline via PDF.js so stamps track document pages
                if (pdfUrl) {
                    pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                    var stageWidth = sigStage.clientWidth;
                    pdfjsLib.getDocument(pdfUrl).promise.then(function(pdf) {
                        var pagePromises = [];
                        for (var i = 1; i <= pdf.numPages; i++) {
                            pagePromises.push(pdf.getPage(i).then(function(page) {
                                var vp = page.getViewport({ scale: 1 });
                                var scale = stageWidth / vp.width;
                                var viewport = page.getViewport({ scale: scale });
                                var canvas = document.createElement('canvas');
                                canvas.className = 'pdf-page-canvas';
                                canvas.width = viewport.width;
                                canvas.height = viewport.height;
                                canvas.style.width = viewport.width + 'px';
                                canvas.style.height = viewport.height + 'px';
                                sigStage.appendChild(canvas);
                                return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
                            }));
                        }
                        return Promise.all(pagePromises);
                    }).then(function() {
                        initStamps();
                    }).catch(function(err) {
                        console.error('PDF render error:', err);
                        initStamps();
                    });
                } else {
                    initStamps();
                }

                // ESC key exits snap mode
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && snapMode) { toggleSnapMode(); }
                });
            });

            // Delete document function
            window.deleteDocument = function(id) {
                if (!confirm('Are you sure you want to delete this document? This action cannot be undone.')) return;
                var btn = event.target;
                btn.disabled = true;
                btn.textContent = '⏳ Deleting...';
                fetch('delete-document.php?document_id=' + id)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            window.location.href = '../documents.html';
                        } else {
                            alert('Failed to delete: ' + (data.error || 'Unknown error'));
                            btn.disabled = false;
                            btn.textContent = '🗑️ Delete';
                        }
                    })
                    .catch(function(err) {
                        alert('Error: ' + err.message);
                        btn.disabled = false;
                        btn.textContent = '🗑️ Delete';
                    });
            };
            </script>

            <?php elseif (!empty($displayContent) && trim((string)$displayContent) !== ''): ?>
                <?php
                $escapedContent = nl2br(htmlspecialchars((string)$displayContent));
                if ($replaceSignatureInText) {
                    foreach ($signaturePlaceholders as $p) {
                        $escapedP = nl2br(htmlspecialchars($p));
                        $escapedContent = str_replace($escapedP, $sigBlock, $escapedContent);
                    }
                }
                ?>
                <div style="font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.6; white-space:pre-wrap;">
                    <?php echo $escapedContent; ?>
                </div>
            <?php else: ?>
                <p>No document content available.</p>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            This document was digitally signed and verified. Generated by DocSign System.
        </div>
    </body>
    </html>
    <?php
    
} catch(Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>