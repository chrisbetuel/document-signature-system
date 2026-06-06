<?php
// Category-wise template API
// Returns category templates (fields schema) so templates.html can render them.

header('Content-Type: application/json; charset=utf-8');

require_once '../includes/config.php';

function respond(int $statusCode, bool $success, $data = null, string $error = ''): void {
    http_response_code($statusCode);
    $resp = ['success' => $success];
    if ($data !== null) $resp['data'] = $data;
    if ($error !== '') $resp['error'] = $error;
    echo json_encode($resp);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(405, false, null, 'Method not allowed');
    }

    $db = getDB();

    // Templates shown on templates.html:
    // - Prefer category.fields_schema (created by create-category.html)
    // - If missing/empty, fall back to the uploaded template document gaps_json for that category.

    $rows = $db->query('SELECT id, name, description, fields_schema FROM categories ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $categoryId = (int)($row['id'] ?? 0);

        $fieldsSchema = json_decode($row['fields_schema'] ?? '[]', true);
        $fields = [];

        if (is_array($fieldsSchema)) {
            foreach ($fieldsSchema as $f) {
                // stored as strings OR as {name:"..."}
                if (is_string($f)) {
                    if ($f !== '') $fields[] = $f;
                } elseif (is_array($f) && isset($f['name']) && is_string($f['name']) && $f['name'] !== '') {
                    $fields[] = $f['name'];
                }
            }
        }

        // Fallback: use uploaded template document gaps_json if schema is empty.
        if (empty($fields) && $categoryId) {
            $tplStmt = $db->prepare("SELECT gaps_json FROM documents WHERE category_id = ? AND status = 'template' ORDER BY id DESC LIMIT 1");
            $tplStmt->execute([$categoryId]);
            $tplRow = $tplStmt->fetch(PDO::FETCH_ASSOC);
            if ($tplRow && !empty($tplRow['gaps_json'])) {
                $gaps = json_decode($tplRow['gaps_json'], true);
                if (is_array($gaps)) {
                    foreach ($gaps as $g) {
                        if (is_array($g) && isset($g['name']) && is_string($g['name']) && $g['name'] !== '') {
                            $fields[] = $g['name'];
                        }
                    }
                }
            }
        }

        $templateDoc = null;

        if ($categoryId) {
            $tplStmt2 = $db->prepare("SELECT id, original_filename FROM documents WHERE category_id = ? AND status = 'template' ORDER BY id DESC LIMIT 1");
            $tplStmt2->execute([$categoryId]);
            $tplRow2 = $tplStmt2->fetch(PDO::FETCH_ASSOC);

            if ($tplRow2 && !empty($tplRow2['original_filename'])) {
                $tplId = (int)$tplRow2['id'];
                $templateDoc = [
                    'id' => $tplId,
                    'filename' => (string)$tplRow2['original_filename'],
                    'download_url' => 'api/download.php?document_id=' . $tplId,
                    'preview_url' => 'api/preview-template.php?document_id=' . $tplId,
                ];
            }
        }

        $docs = [];
        if ($categoryId) {
            $docStmt = $db->prepare("SELECT id, original_filename, status, created_at FROM documents WHERE category_id = ? ORDER BY id DESC");
            $docStmt->execute([$categoryId]);
            $docs = $docStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($docs as &$d) {
                $d['id'] = (int)$d['id'];
                $d['download_url'] = 'api/download.php?document_id=' . $d['id'];
                $d['preview_url'] = 'api/preview-template.php?document_id=' . $d['id'];
            }
            unset($d);
        }

        $out[] = [
            'category' => [
                'id' => (int)($row['id'] ?? 0),
                'name' => (string)($row['name'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
            ],
            'template' => $fields,
            'template_document' => $templateDoc,
            'documents' => $docs,
        ];
    }

    respond(200, true, $out);
} catch (Throwable $e) {
    respond(500, false, null, $e->getMessage());
}

