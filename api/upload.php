<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Only POST method allowed']);
    exit;
}

try {
    $db = getDB();
    $categoryId = $_POST['category_id'] ?? 0;
    
    if (!$categoryId) {
        echo json_encode(['success' => false, 'message' => 'Category ID required']);
        exit;
    }
    
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== 0) {
        echo json_encode(['success' => false, 'message' => 'File upload failed']);
        exit;
    }
    
    $file = $_FILES['document'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = time() . '_' . uniqid() . '.' . $ext;
    $path = ORIGINAL_DIR . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }
    
    // Extract text based on file type
    $fullText = '';
    
    if ($ext == 'txt') {
        $fullText = file_get_contents($path);
    } elseif ($ext == 'docx') {
        $fullText = extractTextFromDOCX($path);
    } elseif ($ext == 'pdf') {
        $fullText = extractTextFromPDF($path);
    }
    
    // Convert to UTF-8 if needed
    if (!mb_check_encoding($fullText, 'UTF-8')) {
        $fullText = mb_convert_encoding($fullText, 'UTF-8', 'auto');
    }
    
    // Detect ALL types of gaps
    $gaps = extractAllGaps($fullText);
    
    // Save document
    // Template rule: the *first uploaded document* for a given category becomes the category template.
    // Subsequent uploads are treated as normal documents.
    $existingTemplateStmt = $db->prepare("SELECT COUNT(*) AS cnt FROM documents WHERE category_id = ? AND status = 'template'");
    $existingTemplateStmt->execute([$categoryId]);
    $hasTemplate = ((int)($existingTemplateStmt->fetch()['cnt'] ?? 0)) > 0;

    $status = $hasTemplate ? 'gap_detected' : 'template';

    $stmt = $db->prepare("INSERT INTO documents (category_id, original_filename, original_path, gaps_json, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([
        $categoryId,
        $file['name'],
        $path,
        json_encode($gaps),
        $status
    ]);

    $docId = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'document_id' => $docId,
        'gaps' => $gaps,
        'total_gaps' => count($gaps),
        'file_type' => $ext,
        'message' => count($gaps) . ' gap(s) detected from document'
    ]);
    
} catch(Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function extractTextFromDOCX($filename) {
    $text = '';
    $zip = new ZipArchive();
    if ($zip->open($filename) === true) {
        $content = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($content) {
            $text = strip_tags($content);
            $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        }
    }
    return $text;
}

function extractTextFromPDF($filename) {
    $text = '';
    
    // Try using pdftotext command (if installed)
    $output = shell_exec("pdftotext " . escapeshellarg($filename) . " - 2>/dev/null");
    if ($output && strlen($output) > 50) {
        return $output;
    }
    
    // Manual PDF text extraction
    if ($fp = fopen($filename, 'rb')) {
        $content = '';
        while (!feof($fp)) {
            $content .= fread($fp, 8192);
        }
        fclose($fp);
        
        // Extract text from between BT and ET
        preg_match_all('/BT(.*?)ET/s', $content, $textBlocks);
        foreach ($textBlocks[1] as $block) {
            preg_match_all('/\((.*?)\)/s', $block, $matches);
            foreach ($matches[1] as $match) {
                $clean = preg_replace('/\\\\[0-9]{3}/', '', $match);
                $clean = preg_replace('/\\\\\(/', '(', $clean);
                $clean = preg_replace('/\\\\\)/', ')', $clean);
                if (strlen($clean) > 2) {
                    $text .= $clean . ' ';
                }
            }
        }
        
        // Alternative: Look for TJ operators
        preg_match_all('/\[(.*?)\]TJ/s', $content, $tjMatches);
        foreach ($tjMatches[1] as $block) {
            preg_match_all('/\((.*?)\)/s', $block, $matches);
            foreach ($matches[1] as $match) {
                $text .= $match . ' ';
            }
        }
    }
    
    // Clean up
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function extractAllGaps($text) {
    $gaps = [];
    $seenNames = [];
    
    // Convert special characters
    $text = str_replace('…', '.', $text);
    $text = str_replace('_', '.', $text);
    
    // Find all dot sequences (3 or more dots)
    preg_match_all('/\.{3,}/', $text, $dotMatches);
    $dotCount = 0;
    foreach ($dotMatches[0] as $dots) {
        $dotCount++;
        // Get the label before the dots
        $label = getLabelBeforeDots($text, $dots);
        $fieldName = !empty($label) ? $label : 'Field_' . $dotCount;
        
        if (!in_array($fieldName, $seenNames)) {
            $seenNames[] = $fieldName;
            $gaps[] = [
                'name' => $fieldName,
                'type' => detectFieldType($fieldName),
                'required' => true,
                'placeholder' => $dots
            ];
        }
    }
    
    // Find underscore sequences
    preg_match_all('/_{3,}/', $text, $underscoreMatches);
    $underscoreCount = 0;
    foreach ($underscoreMatches[0] as $underscore) {
        $underscoreCount++;
        $label = getLabelBeforeDots($text, $underscore);
        $fieldName = !empty($label) ? $label : 'Field_' . $underscoreCount;
        
        if (!in_array($fieldName, $seenNames)) {
            $seenNames[] = $fieldName;
            $gaps[] = [
                'name' => $fieldName,
                'type' => detectFieldType($fieldName),
                'required' => true,
                'placeholder' => $underscore
            ];
        }
    }
    
    // Find bracket placeholders
    preg_match_all('/\[([^\]]+)\]/', $text, $bracketMatches);
    foreach ($bracketMatches[1] as $placeholder) {
        $fieldName = trim($placeholder);
        if (!in_array($fieldName, $seenNames)) {
            $seenNames[] = $fieldName;
            $gaps[] = [
                'name' => $fieldName,
                'type' => detectFieldType($fieldName),
                'required' => true,
                'placeholder' => '[' . $fieldName . ']'
            ];
        }
    }
    
    // Find specific Swahili keywords with missing values
    $swahiliKeywords = ['Mpangishaji', 'Wapangaji', 'Passport', 'Simu', 'Jina', 'Saini', 'Tarehe', 'Anuani', 'Wadhifa', 'NIDA', 'Sahihi', 'S.L.P', 'Jina kamili'];
    foreach ($swahiliKeywords as $keyword) {
        // Check if keyword exists in text and likely has a gap after it
        if (strpos($text, $keyword) !== false) {
            if (!in_array($keyword, $seenNames)) {
                $seenNames[] = $keyword;
                $gaps[] = [
                    'name' => $keyword,
                    'type' => ($keyword == 'Saini' || $keyword == 'Sahihi') ? 'signature' : 'text',
                    'required' => true,
                    'placeholder' => $keyword . ': _________'
                ];
            }
        }
    }
    
    return $gaps;
}

function getLabelBeforeDots($text, $dots) {
    $pos = strpos($text, $dots);
    if ($pos === false) return '';
    
    // Get 50 characters before the dots
    $start = max(0, $pos - 50);
    $before = substr($text, $start, $pos - $start);
    
    // Look for a colon or keyword at the end
    if (preg_match('/([A-Za-z\s]+):\s*$/', $before, $matches)) {
        return trim($matches[1]);
    }
    
    // Look for common Swahili keywords
    $keywords = ['Mpangishaji', 'Wapangaji', 'Passport', 'Simu', 'Jina', 'Saini', 'Tarehe', 'Anuani', 'Wadhifa', 'NIDA', 'Sahihi'];
    foreach ($keywords as $keyword) {
        if (strpos($before, $keyword) !== false) {
            return $keyword;
        }
    }
    
    // Get the last word before the dots
    preg_match('/([A-Za-z]+)\s*$/', $before, $wordMatch);
    if (!empty($wordMatch[1])) {
        return $wordMatch[1];
    }
    
    return '';
}

function detectFieldType($name) {
    $lower = strtolower($name);
    
    if (strpos($lower, 'tarehe') !== false || strpos($lower, 'date') !== false) {
        return 'date';
    }
    if (strpos($lower, 'saini') !== false || strpos($lower, 'sahihi') !== false || strpos($lower, 'signature') !== false) {
        return 'signature';
    }
    if (strpos($lower, 'simu') !== false || strpos($lower, 'phone') !== false) {
        return 'tel';
    }
    if (strpos($lower, 'shilingi') !== false || strpos($lower, 'kiasi') !== false || strpos($lower, 'amount') !== false) {
        return 'number';
    }
    if (strpos($lower, 'barua') !== false || strpos($lower, 'email') !== false) {
        return 'email';
    }
    
    return 'text';
}
?>