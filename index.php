<?php
// Config
$google_client_id = getenv('GOOGLE_CLIENT_ID');
$google_client_secret = getenv('GOOGLE_CLIENT_SECRET');
$log_file = __DIR__ . '/app.log';

// Error handling
function handleError($errno, $errstr, $errfile, $errline) {
    $message = "Error [$errno] $errstr on line $errline in file $errfile";
    log_message($message, 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
    die();
}
set_error_handler('handleError');

// Logging function
function log_message($message, $level = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp][$level] $message\n", FILE_APPEND);
}

try {
    session_start();

    $request_uri = $_SERVER['REQUEST_URI'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        if ($request_uri === '/' || $request_uri === '/dashboard') {
            if (isset($_SESSION['user_id'])) {
                // Serve static HTML dashboard
                echo file_get_contents('dashboard.html');
            } else {
                // Redirect to login if not logged in
                header('Location: /index.html');
                exit;
            }
        }
    }

    if ($method === 'POST' && $request_uri === '/verify-google-token') {
        $input = json_decode(file_get_contents('php://input'), true);
        $token = $input['token'] ?? '';

        if ($token) {
            $google_response = file_get_contents("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
            $google_user = json_decode($google_response, true);

            if (isset($google_user['email']) && $google_user['aud'] === $google_client_id) {
                $_SESSION['user_id'] = $google_user['email'];  // Simplify session handling
                echo json_encode(['verified' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['verified' => false, 'message' => 'Invalid token']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Token required']);
        }
        exit;
    }

} catch (Exception $e) {
    log_message("Unhandled exception: " . $e->getMessage(), 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred']);
}
