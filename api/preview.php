<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/config.php';

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

if (!$document_id) {
    die('Document ID is required');
}

try {
    $db = getDB();
    
    // Check if document is signed - redirect to final PDF preview
    $stmt = $db->prepare("SELECT status FROM documents WHERE id = ?");
    $stmt->execute([$document_id]);
    $doc = $stmt->fetch();
    
    if ($doc && $doc['status'] == 'signed') {
        header('Location: preview-final.php?document_id=' . $document_id);
        exit;
    }
    
    // Get document details
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
    
    // Get filled values
    $stmt = $db->prepare("SELECT field_name, field_value FROM filled_data WHERE document_id = ?");
    $stmt->execute([$document_id]);
    $filledData = $stmt->fetchAll();
    
    $filledValues = [];
    foreach ($filledData as $item) {
        $filledValues[$item['field_name']] = $item['field_value'];
    }
    
    // Get gaps
    $gaps = json_decode($document['gaps_json'], true);
    if (!$gaps) {
        $gaps = [];
    }
    
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Document Preview - <?php echo htmlspecialchars($document['original_filename']); ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #F5F5F5;
                padding: 20px;
            }
            
            .preview-container {
                max-width: 900px;
                margin: 0 auto;
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            .preview-header {
                background: #1A2A4A;
                color: white;
                padding: 20px 30px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .preview-header h1 {
                font-size: 20px;
            }
            
            .document-info {
                background: #FAFAFA;
                padding: 15px 30px;
                border-bottom: 1px solid #E8E8E8;
                display: flex;
                gap: 30px;
                flex-wrap: wrap;
            }
            
            .info-item {
                display: flex;
                gap: 10px;
            }
            
            .info-label {
                font-weight: 600;
                color: #1A2A4A;
            }
            
            .info-value {
                color: #666;
            }
            
            .status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 12px;
                font-weight: 500;
            }
            
            .status-template { background: #E8E8E8; color: #666; }
            .status-gap_detected { background: #FFF3E0; color: #CC7B00; }
            .status-filled { background: #E8F5E9; color: #2E7D32; }
            .status-signed { background: #E3F2FD; color: #1565C0; }
            
            .preview-content {
                padding: 30px;
            }
            
            /* Document Viewer */
            .document-viewer {
                background: white;
                border: 1px solid #E8E8E8;
                border-radius: 8px;
                padding: 30px;
                margin-bottom: 30px;
                font-family: 'Courier New', monospace;
                font-size: 13px;
                line-height: 1.8;
                white-space: pre-wrap;
            }
            
            .field-filled {
                background: #E8F5E9;
                padding: 2px 6px;
                border-radius: 4px;
                font-weight: 500;
                display: inline-block;
            }
            
            .field-missing {
                background: #FFF3E0;
                padding: 2px 6px;
                border-radius: 4px;
                display: inline-block;
                border: 1px dashed #FFBF00;
            }
            
            /* Gaps Section */
            .gaps-section {
                background: #FFF3E0;
                border: 1px solid #FFBF00;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .gaps-section h3 {
                color: #CC7B00;
                margin-bottom: 15px;
            }
            
            .gap-list {
                list-style: none;
                padding: 0;
            }
            
            .gap-list li {
                padding: 10px;
                border-bottom: 1px solid #FFE0B2;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .gap-field {
                font-weight: 600;
            }
            
            .gap-placeholder {
                font-family: monospace;
                background: white;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 11px;
            }
            
            .gap-value {
                color: #2E7D32;
                font-weight: 500;
            }
            
            .gap-missing {
                color: #C62828;
            }
            
            /* Action Buttons */
            .action-buttons {
                display: flex;
                gap: 15px;
                margin-top: 20px;
                justify-content: center;
                flex-wrap: wrap;
            }
            
            .btn {
                padding: 12px 24px;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 500;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.3s;
            }
            
            .btn-primary {
                background: #FFBF00;
                color: #1A2A4A;
            }
            
            .btn-primary:hover {
                background: #E5AC00;
                transform: translateY(-2px);
            }
            
            .btn-secondary {
                background: #1A2A4A;
                color: white;
            }
            
            .btn-secondary:hover {
                background: #0F1A2E;
                transform: translateY(-2px);
            }
            
            .btn-success {
                background: #4CAF50;
                color: white;
            }
            .btn-danger {
                background: #DC3545;
                color: white;
            }
            .btn-danger:hover {
                background: #C82333;
                transform: translateY(-2px);
            }
            
            @media (max-width: 768px) {
                .preview-header {
                    flex-direction: column;
                    text-align: center;
                }
                .document-info {
                    flex-direction: column;
                    gap: 10px;
                }
                .gap-list li {
                    flex-direction: column;
                    align-items: flex-start;
                }
                .action-buttons {
                    flex-direction: column;
                }
                .btn {
                    justify-content: center;
                }
            }
        </style>
    </head>
    <body>
        <div class="preview-container">
            <div class="preview-header">
                <h1>📄 Document Preview</h1>
                <div class="action-buttons" style="margin-top: 0;">
                    <a href="download-editable.php?document_id=<?php echo $document_id; ?>" class="btn btn-success" download>
                        ⬇️ Download
                    </a>
                    <a href="../fill-gaps.html?id=<?php echo $document_id; ?>" class="btn btn-primary">
                        ✏️ Edit Document
                    </a>
                    <button onclick="shareWhatsApp()" class="btn btn-secondary">
                        💬 Share
                    </button>
                    <button onclick="deleteDocument(<?php echo $document_id; ?>)" class="btn btn-danger">
                        🗑️ Delete
                    </button>
                </div>
            </div>
            
            <div class="document-info">
                <div class="info-item">
                    <span class="info-label">Document:</span>
                    <span class="info-value"><?php echo htmlspecialchars($document['original_filename']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Category:</span>
                    <span class="info-value"><?php echo htmlspecialchars($document['category_name'] ?? 'Uncategorized'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Status:</span>
                    <span class="status-badge status-<?php echo $document['status']; ?>">
                        <?php echo ucfirst(str_replace('_', ' ', $document['status'])); ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created:</span>
                    <span class="info-value"><?php echo date('F j, Y g:i A', strtotime($document['created_at'])); ?></span>
                </div>
            </div>
            
            <div class="preview-content">
                <!-- Document Content Viewer -->
                <div class="document-viewer">
                    <?php
                    // Display document content with filled values
                    $filePath = $document['original_path'];
                    if (!empty($document['filled_path']) && file_exists($document['filled_path'])) {
                        // Prefer filled version if it exists
                        $filePath = $document['filled_path'];
                    } elseif (!empty($document['original_path']) && file_exists($document['original_path'])) {
                        $filePath = $document['original_path'];
                    }
                    if (file_exists($filePath)) {
                        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                        $content = '';
                        if ($ext == 'txt') {
                            $content = file_get_contents($filePath);
                        } elseif ($ext == 'docx') {
                            $zip = new ZipArchive();
                            if ($zip->open($filePath) === true) {
                                $xml = $zip->getFromName('word/document.xml');
                                $zip->close();
                                if ($xml !== false) {
                                    $content = strip_tags($xml);
                                    $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
                                }
                            }
                        } elseif ($ext == 'pdf') {
                            // For PDF, use PDF.js inline rendering (like preview-final)
                            $pdfRelPath = '';
                            $resolved = realpath($filePath);
                            if ($resolved !== false) {
                                $normalized = str_replace('\\', '/', $resolved);
                                $pdfRelPath = str_replace(PROJECT_ROOT . '/', '', $normalized);
                            }
                            $pdfUrl = '/document-signature-system/' . $pdfRelPath;
                            echo '<div id="preview-pdf-container" style="width:100%;"></div>';
                            echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>';
                            echo '<script>
                                (function() {
                                    var container = document.getElementById("preview-pdf-container");
                                    if (!container) return;
                                    var w = container.clientWidth;
                                    pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
                                    pdfjsLib.getDocument("' . $pdfUrl . '").promise.then(function(pdf) {
                                        var promises = [];
                                        for (var i = 1; i <= pdf.numPages; i++) {
                                            promises.push(pdf.getPage(i).then(function(page) {
                                                var vp = page.getViewport({ scale: 1 });
                                                var scale = w / vp.width;
                                                var viewport = page.getViewport({ scale: scale });
                                                var canvas = document.createElement("canvas");
                                                canvas.style.display = "block";
                                                canvas.style.margin = "0 auto 8px";
                                                canvas.style.boxShadow = "0 2px 8px rgba(0,0,0,0.12)";
                                                canvas.style.borderRadius = "3px";
                                                canvas.style.background = "#fff";
                                                canvas.width = viewport.width;
                                                canvas.height = viewport.height;
                                                canvas.style.width = viewport.width + "px";
                                                canvas.style.height = viewport.height + "px";
                                                container.appendChild(canvas);
                                                return page.render({ canvasContext: canvas.getContext("2d"), viewport: viewport }).promise;
                                            }));
                                        }
                                        return Promise.all(promises);
                                    }).catch(function(err) {
                                        container.innerHTML = "<p>Could not render PDF preview. <a href=\\"' . $pdfUrl . '\\" target=\\"_blank\\">Download PDF</a></p>";
                                    });
                                })();
                            </script>';
                        }

                        if ($ext !== 'pdf' && $content !== '') {
                            // Replace placeholders with filled values for text/docx
                            foreach ($gaps as $gap) {
                                $placeholder = $gap['placeholder'] ?? '';
                                $fieldName = $gap['name'] ?? '';
                                if ($placeholder === '') continue;
                                if ($fieldName && isset($filledValues[$fieldName]) && !empty($filledValues[$fieldName])) {
                                    $value = htmlspecialchars($filledValues[$fieldName]);
                                    $content = str_replace($placeholder, '<span class="field-filled">' . $value . '</span>', $content);
                                } else {
                                    $content = str_replace($placeholder, '<span class="field-missing">' . htmlspecialchars($placeholder) . '</span>', $content);
                                }
                            }
                            // Replace underscore placeholders
                            $content = preg_replace('/_{3,}/', '<span class="field-missing">______</span>', $content);
                            echo nl2br($content);
                        } elseif ($ext !== 'pdf') {
                            echo '<p>Could not extract content from this document. <a href="download-editable.php?document_id=' . $document_id . '">Download instead</a>.</p>';
                        }
                    } else {
                        echo '<p>Document file not found on server.</p>';
                    }
                    ?>
                </div>
                
                <!-- Gaps Summary -->
                <?php if (!empty($gaps)): ?>
                <div class="gaps-section">
                    <h3>⚠️ Gaps Summary</h3>
                    <ul class="gap-list">
                        <?php foreach ($gaps as $gap): ?>
                        <li>
                            <div>
                                <span class="gap-field">📝 <?php echo htmlspecialchars($gap['name']); ?></span>
                                <span class="gap-placeholder"><?php echo htmlspecialchars($gap['placeholder']); ?></span>
                            </div>
                            <?php if (isset($filledValues[$gap['name']]) && !empty($filledValues[$gap['name']])): ?>
                                <span class="gap-value">✓ Filled</span>
                            <?php else: ?>
                                <span class="gap-missing">❌ Not filled</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <?php if (empty($gaps) && empty($filledValues)): ?>
                <div style="text-align: center; padding: 20px; color: #8A8A8A;">
                    ✅ No gaps detected. Document is complete.
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <script>
            function shareWhatsApp() {
                const url = window.location.href;
                const text = `Document Preview: ${url}`;
                window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank');
            }

            function deleteDocument(id) {
                if (!confirm('Are you sure you want to delete this document? This action cannot be undone.')) return;
                const btn = event.target;
                btn.disabled = true;
                btn.textContent = '⏳ Deleting...';
                fetch('delete-document.php?document_id=' + id)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            window.location.href = '../documents.html';
                        } else {
                            alert('Failed to delete: ' + (data.error || 'Unknown error'));
                            btn.disabled = false;
                            btn.textContent = '🗑️ Delete';
                        }
                    })
                    .catch(err => {
                        alert('Error: ' + err.message);
                        btn.disabled = false;
                        btn.textContent = '🗑️ Delete';
                    });
            }
        </script>
    </body>
    </html>
    <?php
    
} catch(Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>