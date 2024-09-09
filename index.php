<?php
// Config
$data_file = __DIR__ . '/users.txt';  // File to store user data

// Error handling
function handleError($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
    die();
}
set_error_handler('handleError');

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $email = $input['email'] ?? '';

    if ($name && $email) {
        $data = "Name: $name, Email: $email\n";
        file_put_contents($data_file, $data, FILE_APPEND);
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
    }
    exit;
}

// If not a POST request
http_response_code(405);
echo json_encode(['error' => 'Method Not Allowed']);
