<?php
// DB-first Category API
// - GET  : returns categories from MySQL table `categories`
// - POST : creates category row in MySQL table `categories`
//
// Response shape kept compatible with existing frontend:
//   { success: true, data: [ {id, name, description, fields: [...]} ] }

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/config.php';

function respond(int $statusCode, bool $success, $data = null, string $message = ''): void {
    http_response_code($statusCode);
    $resp = ['success' => $success];
    if ($data !== null) $resp['data'] = $data;
    if ($message !== '') $resp['message'] = $message;
    echo json_encode($resp);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function normalizeCategoryRow(array $row): array {
    $fieldsOut = [];
    $fieldsSchema = json_decode($row['fields_schema'] ?? '[]', true);

    if (is_array($fieldsSchema)) {
        foreach ($fieldsSchema as $f) {
            if (is_string($f)) {
                $fieldsOut[] = $f;
            } elseif (is_array($f) && isset($f['name']) && is_string($f['name'])) {
                $fieldsOut[] = $f['name'];
            }
        }
    }

    return [
        'id' => isset($row['id']) ? (int)$row['id'] : 0,
        'name' => (string)($row['name'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'fields' => $fieldsOut,
    ];
}

if ($method === 'GET') {
    try {
        $db = getDB();
        $rows = $db->query('SELECT * FROM categories ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map('normalizeCategoryRow', $rows);
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

    // Prevent duplicates (case-insensitive)
    $stmt = $db->prepare('SELECT id FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
    $stmt->execute([$categoryName]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        respond(409, false, null, 'Category already exists');
    }

    $fieldsSchemaJson = json_encode([], JSON_UNESCAPED_UNICODE);

    // Try insert with description column (if exists), otherwise insert without.
    try {
        $stmt = $db->prepare('INSERT INTO categories (name, description, fields_schema) VALUES (?, ?, ?)');
        $stmt->execute([$categoryName, '', $fieldsSchemaJson]);
    } catch (Throwable $e) {
        $stmt = $db->prepare('INSERT INTO categories (name, fields_schema) VALUES (?, ?)');
        $stmt->execute([$categoryName, $fieldsSchemaJson]);
    }

    $id = (int)$db->lastInsertId();
    respond(200, true, [
        'id' => $id,
        'name' => $categoryName,
        'description' => '',
        'fields' => [],
    ], 'Category created successfully');
}

// Sync categories from uploads/categories.json into DB on first run.
// This prevents existing JSON categories from breaking upload.
try {
    $storageFile = __DIR__ . '/../uploads/categories.json';
    if (file_exists($storageFile)) {
        $raw = file_get_contents($storageFile);
        $parsed = json_decode($raw, true);
        if (is_array($parsed) && count($parsed) > 0) {
            $db = getDB();
            foreach ($parsed as $c) {
                if (!is_array($c)) continue;
                $name = trim((string)($c['name'] ?? ''));
                if ($name === '') continue;

                $stmt = $db->prepare('SELECT id FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1');
                $stmt->execute([$name]);
                if ($stmt->fetch(PDO::FETCH_ASSOC)) continue;

                // If we only have simple field names from JSON, store them as [{name:"..."}, ...]
                $fields = $c['fields'] ?? [];
                $fieldsSchema = [];
                if (is_array($fields)) {
                    foreach ($fields as $f) {
                        if (is_string($f) && $f !== '') {
                            $fieldsSchema[] = ['name' => $f];
                        }
                    }
                }

                $fieldsSchemaJson = json_encode($fieldsSchema, JSON_UNESCAPED_UNICODE);

                try {
                    $stmt = $db->prepare('INSERT INTO categories (name, description, fields_schema) VALUES (?, ?, ?)');
                    $stmt->execute([$name, (string)($c['description'] ?? ''), $fieldsSchemaJson]);
                } catch (Throwable $e) {
                    $stmt = $db->prepare('INSERT INTO categories (name, fields_schema) VALUES (?, ?)');
                    $stmt->execute([$name, $fieldsSchemaJson]);
                }
            }
        }
    }
} catch (Throwable $e) {
    // ignore sync failures; upload will still work if DB already has categories.
}

respond(405, false, null, 'Method not allowed');



