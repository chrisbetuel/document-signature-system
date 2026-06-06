<?php
require_once '../includes/config.php';

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

if (!$document_id) {
    http_response_code(400);
    die('Document ID is required');
}

try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM documents WHERE id = ? AND status = 'template'");
    $stmt->execute([$document_id]);
    $document = $stmt->fetch();

    if (!$document) {
        http_response_code(404);
        die('Document not found');
    }

    $filePath = $document['original_path'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found');
    }

    $fullPath = realpath($filePath);

    // Open in Word via exec (Apache runs in user session)
    exec('start "" "' . $fullPath . '" 2>NUL');

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Opening in Word...</title>
<style>
body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#F5F5F5;color:#1A2A4A}
.msg{text-align:center;background:#fff;padding:40px;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,0.06);max-width:440px}
.msg .icon{font-size:48px;margin-bottom:12px}
.msg h2{font-size:18px;margin-bottom:8px}
.msg p{font-size:14px;color:#666;margin-bottom:20px}
.btn{display:inline-block;padding:10px 24px;background:#FFBF00;color:#1A2A4A;text-decoration:none;border-radius:8px;font-weight:600;border:none;cursor:pointer}
</style>
</head>
<body>
<div class="msg">
<div class="icon">&#128196;</div>
<h2>Template opened in Word</h2>
<p>The template <strong><?php echo htmlspecialchars($document['original_filename'] ?? 'document.docx', ENT_QUOTES, 'UTF-8'); ?></strong> has been opened in Microsoft Word.</p>
<a class="btn" href="javascript:window.close()">Close this tab</a>
</div>
</body>
</html>
<?php
} catch (Exception $e) {
    http_response_code(500);
    die('Error: ' . $e->getMessage());
}
