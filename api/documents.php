<?php
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
    $db = getDB();

    // Try to return the structure expected by js/documents.js.
    // Expected keys used by frontend:
    //  - id
    //  - original_filename
    //  - category_name
    //  - status
    //  - signer_name
    //  - signed_at

    // Build a query that uses common column names.
    // If your schema differs, edit the column aliases below.
    $sql = "
        SELECT 
            d.id AS id,
            d.original_filename AS original_filename,
            c.name AS category_name,
            d.status AS status,
            NULL AS signer_name,
            d.created_at AS signed_at
        FROM documents d
        LEFT JOIN categories c ON c.id = d.category_id
        ORDER BY d.id DESC
    ";

    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    respond(200, true, $rows);
} catch (Throwable $e) {
    respond(500, false, null, $e->getMessage());
}

