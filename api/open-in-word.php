<?php
header('Content-Type: application/json');
require_once '../includes/config.php';

$templateDocId = isset($_GET['template_document_id']) ? intval($_GET['template_document_id']) : 0;
$categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

if (!$templateDocId || !$categoryId) {
    echo json_encode(['success' => false, 'message' => 'Template document ID and Category ID required']);
    exit;
}

try {
    $db = getDB();

    // Get template document
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ? AND category_id = ?");
    $stmt->execute([$templateDocId, $categoryId]);
    $template = $stmt->fetch();

    if (!$template) {
        echo json_encode(['success' => false, 'message' => 'Template not found']);
        exit;
    }

    $sourcePath = $template['original_path'];
    if (!file_exists($sourcePath)) {
        echo json_encode(['success' => false, 'message' => 'Template file not found on server']);
        exit;
    }

    // Copy template to filled directory
    $copyFilename = 'inline_' . time() . '_' . uniqid() . '.docx';
    $copyPath = FILLED_DIR . $copyFilename;

    if (!copy($sourcePath, $copyPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to create working copy']);
        exit;
    }

    // Create document record (status = 'editing' means the user is still working on it)
    $stmt = $db->prepare("INSERT INTO documents (category_id, original_filename, original_path, filled_path, gaps_json, status) VALUES (?, ?, ?, ?, '[]', 'editing')");
    $stmt->execute([
        $categoryId,
        $template['original_filename'],
        $sourcePath,
        $copyPath,
    ]);
    $docId = (int)$db->lastInsertId();

    // Open the copy in Word via exec (server = localhost)
    $realPath = realpath($copyPath);
    $escaped = escapeshellarg($realPath);
    $success = false;

    $commands = [
        'start "" ' . $escaped,
        'cmd /c start "" ' . $escaped,
        'powershell -NoProfile -Command "Start-Process \'' . addslashes($realPath) . '\'"',
    ];

    foreach ($commands as $cmd) {
        exec($cmd, $output, $code);
        if ($code === 0) {
            $success = true;
            break;
        }
    }

    echo json_encode([
        'success' => $success,
        'document_id' => $docId,
        'message' => $success
            ? 'Document opened in Word. Edit and press Ctrl+S to save directly to the system.'
            : 'Could not open Word automatically. Try downloading instead.',
        'download_url' => 'api/download.php?document_id=' . $docId,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
