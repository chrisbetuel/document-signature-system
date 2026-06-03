// DB-first categories API
// Supports:
//  - GET  : returns available categories from MySQL
//  - POST : creates a category in MySQL
//
// Response format (to keep frontend working):
//   { success: true, data: [ {id,name,description,fields:[...]} ] }

<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';

function respond(int $statusCode, bool $success, $data = null, string $error = ''): void {
    http_response_code($statusCode);
    $resp = ['success' => $success];
    if ($data !== null) $resp['data'] = $data;
    if ($error !== '') $resp['error'] = $error;
    echo json_encode($resp);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Convert DB row fields_schema into the old JSON structure expected by JS.
// DB columns (based on upload.php): fields_schema is a JSON array like: [{name:"Field 1", ...}, ...]
function normalizeCategory(array $row): array {
    $fieldsSchema = json_decode($row['fields_schema'] ?? '[]', true);
    $fields = [];
    if (is_array($fieldsSchema)) {
        foreach ($fieldsSchema as $f) {
            if (is_array($f) && isset($f['name']) && is_string($f['name']) && $f['name'] !== '') {
                $fields[] = $f;
                // If the field structure is used elsewhere, keep full; otherwise keep names only.
            } else {
                // If old schema stored strings, pass through.
                if (is_string($f)) $fields[] = $f;
            }
        }
    }

    // Frontend currently only needs name for the upload + fields to render templates (if used later).
    // Keep as strings if possible.
    $fieldsOut = [];
    foreach ($fields as $f) {
        if (is_string($f)) $fieldsOut[] = $f;
        else if (is_array($f) && isset($f['name'])) $fieldsOut[] = $f['name'];
    }

    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'description' => (string)($row['description'] ?? ''),
        'fields' => $fieldsOut,
    ];
}

if ($method === 'GET') {
    try {
        $db = getDB();
        // description may not exist depending on your schema; select fields_schema defensively.
        // Using SELECT * is simplest for compatibility.
        $rows = $db->query('SELECT * FROM categories')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[] = normalizeCategory($r);
        respond(200, true, $out);
    } catch (Throwable $e) {
        respond(500, false, null, $e->getMessage());
    }
}

if ($method === 'POST') {
    $categoryName = '';

    if (isset($_POST['category_name'])) {
        $categoryName = trim((string)$_POST['category_name']);
    }

    if ($categoryName === '') {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $categoryName = trim((string)($json['category_name'] ?? $json['name'] ?? ''));
        }
    }

    if ($categoryName === '') {
        respond(400, false, null, 'category_name is required');
    }

    $db = getDB();

    // Check duplicates case-insensitive
    $stmt = $db->prepare('SELECT id FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt->execute([$categoryName]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        respond(409, false, null, 'Category already exists');
    }

    // Insert with empty fields_schema by default.
    // Keep fields_schema as JSON array.
    $fieldsSchemaJson = json_encode([], JSON_UNESCAPED_UNICODE);

    // Try insert including description if column exists.
    try {
        $stmt = $db->prepare('INSERT INTO categories (name, description, fields_schema) VALUES (?, ?, ?)');
        $stmt->execute([$categoryName, '', $fieldsSchemaJson]);
    } catch (Throwable $e) {
        // Fallback if no description column exists
        $stmt = $db->prepare('INSERT INTO categories (name, fields_schema) VALUES (?, ?)');
        $stmt->execute([$categoryName, $fieldsSchemaJson]);
    }

    $id = $db->lastInsertId();

    respond(200, true, [
        'id' => (int)$id,
        'name' => $categoryName,
        'description' => '',
        'fields' => [],
    ], 'Category created successfully');
}

respond(405, false, null, 'Method not allowed');
?>
