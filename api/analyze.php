<?php
header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

require_once '../includes/config.php';
require_once '../vendor/autoload.php';

use Smalot\PdfParser\Parser;

$document_id = isset($_GET['document_id']) ? intval($_GET['document_id']) : 0;

if (!$document_id) {
    echo json_encode(['success' => false, 'message' => 'Document ID is required']);
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
    
    // Get gaps from document
    $gaps = json_decode($document['gaps_json'], true);
    if (!$gaps) {
        $gaps = [];
    }
    
    // Get document content based on file type
    $filePath = $document['original_path'];
    $fullText = '';
    
    if (file_exists($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        if ($ext == 'txt') {
            $fullText = file_get_contents($filePath);
        } elseif ($ext == 'docx') {
            $fullText = extractTextFromDOCX($filePath);
        } elseif ($ext == 'pdf') {
            $fullText = extractTextFromPDF($filePath);
        }
    }
    
    // Split into sections that contain gaps
    $gapSections = extractSectionsWithGaps($fullText, $gaps);
    
    echo json_encode([
        'success' => true,
        'document_id' => $document_id,
        'document_name' => $document['original_filename'],
        'gaps' => $gaps,
        'gap_sections' => $gapSections,
        'total_gaps' => count($gaps),
        'total_sections' => count($gapSections)
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
    try {
        $parser = new Parser();
        $pdf = $parser->parseFile($filename);
        $text = $pdf->getText();
        return $text;
    } catch(Exception $e) {
        return '';
    }
}

function extractSectionsWithGaps($text, $gaps) {
    $sections = [];
    $lines = explode("\n", $text);
    $currentSection = '';
    $currentSectionGaps = [];
    $sectionCount = 0;
    
    foreach ($lines as $line) {
        $hasGap = false;
        $lineGaps = [];
        
        foreach ($gaps as $gap) {
            if (strpos($line, $gap['placeholder']) !== false) {
                $hasGap = true;
                $lineGaps[] = $gap;
            }
        }
        
        if ($hasGap) {
            $currentSection .= $line . "\n";
            foreach ($lineGaps as $gap) {
                $found = false;
                foreach ($currentSectionGaps as $existing) {
                    if ($existing['name'] == $gap['name']) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $currentSectionGaps[] = $gap;
                }
            }
        } elseif (!empty($currentSection)) {
            $sectionCount++;
            $sections[] = [
                'id' => $sectionCount,
                'title' => 'Section ' . $sectionCount,
                'content' => $currentSection,
                'gaps' => $currentSectionGaps,
                'gap_count' => count($currentSectionGaps)
            ];
            $currentSection = '';
            $currentSectionGaps = [];
        }
    }
    
    if (!empty($currentSection)) {
        $sectionCount++;
        $sections[] = [
            'id' => $sectionCount,
            'title' => 'Section ' . $sectionCount,
            'content' => $currentSection,
            'gaps' => $currentSectionGaps,
            'gap_count' => count($currentSectionGaps)
        ];
    }
    
    return array_values(array_filter($sections, function($section) {
        return $section['gap_count'] > 0;
    }));
}
?>