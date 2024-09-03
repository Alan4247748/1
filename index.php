<?php
// Config
$google_client_id = getenv('GOOGLE_CLIENT_ID');
$google_client_secret = getenv('GOOGLE_CLIENT_SECRET');
$db_path = __DIR__ . '/database.sqlite';
$log_file = __DIR__ . '/app.log';
$magic_link_expiration = 3600; // 1 hour in seconds

// Error handling
function handleError($errno, $errstr, $errfile, $errline) {
    $message = "Error [$errno] $errstr on line $errline in file $errfile";
    log_message($message, 'ERROR');
    http_response_code(500);
    echo json_encode(['error' => 'An internal error occurred']);
    die();
}
set_error_handler('handleError');

// Logging function with log levels
function log_message($message, $level = 'INFO') {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp][$level] $message\n", FILE_APPEND);
}

try {
    // Start session management
    session_start();

    // Initialize SQLite database
    $db = new SQLite3($db_path);
    $db->enableExceptions(true);

    $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, name TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS magic_links (token TEXT PRIMARY KEY, email TEXT, created_at INTEGER)");

    // Simple routing
    $request_uri = $_SERVER['REQUEST_URI'];
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        if ($request_uri === '/' || $request_uri === '/dashboard') {
            if ($loggedIn) {
                // Serve the dashboard content
                $dashboardContent = file_get_contents('dashboard.html');
                
                // Replace placeholders with user data
                $userId = $_SESSION['user_id'];
                $stmt = $db->prepare("SELECT name, email FROM users WHERE id = :user_id");
                $stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
                $result = $stmt->execute();
                $user = $result->fetchArray(SQLITE3_ASSOC);
                
                $dashboardContent = str_replace('id="user-name"></span>', 'id="user-name">' . htmlspecialchars($user['name']) . '</span>', $dashboardContent);
                $dashboardContent = str_replace('id="user-email"></span>', 'id="user-email">' . htmlspecialchars($user['email']) . '</span>', $dashboardContent);
                
                echo $dashboardContent;
            } else {
                // Redirect to login if not logged in
                header('Location: /login');
                exit;
            }
        }
        // ... (keep other GET routes)
    }

    if ($method === 'POST') {
        if ($request_uri === '/send-magic-link') {
            $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
            if ($email) {
                $token = bin2hex(random_bytes(32));
                $created_at = time();
                $stmt = $db->prepare("INSERT INTO magic_links (token, email, created_at) VALUES (:token, :email, :created_at)");
                $stmt->bindValue(':token', $token, SQLITE3_TEXT);
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt->bindValue(':created_at', $created_at, SQLITE3_INTEGER);
                $stmt->execute();
                
                $magic_link = "http://{$_SERVER['HTTP_HOST']}/verify-magic-link?token=$token";
                log_message("Magic link generated for email: $email");
                echo json_encode(['message' => 'Magic link sent', 'link' => $magic_link]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Valid email required']);
                log_message("Invalid email provided: " . ($_POST['email'] ?? 'not set'), 'WARNING');
            }
        }
        elseif ($request_uri === '/update-profile') {
            $name = $_POST['name'];
            $email = $_POST['email'];

            $stmt = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = 1");
            if ($stmt->execute([$name, $email])) {
                echo json_encode(['status' => 'success', 'message' => 'Profile updated']);
                log_message("Profile updated for user ID 1: $name, $email");
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Failed to update profile']);
                log_message("Failed to update profile for user ID 1", 'ERROR');
            }
            exit;
        }
        elseif ($request_uri === '/logout') {
            session_destroy();
            echo json_encode(['status' => 'success', 'message' => 'Logged out']);
            log_message("User logged out", 'INFO');
            exit;
        }
        elseif ($request_uri === '/verify-google-token') {
            $input = json_decode(file_get_contents('php://input'), true);
            $token = $input['token'] ?? '';

            if ($token) {
                $google_response = file_get_contents("https://oauth2.googleapis.com/tokeninfo?id_token=$token");
                $google_user = json_decode($google_response, true);

                if (isset($google_user['email']) && $google_user['aud'] === $google_client_id) {
                    $email = $google_user['email'];
                    $name = $google_user['name'] ?? explode('@', $email)[0];

                    $stmt = $db->prepare("INSERT OR REPLACE INTO users (email, name) VALUES (:email, :name)");
                    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
                    $stmt->execute();

                    log_message("User authenticated via Google: $email");
                    echo json_encode(['verified' => true, 'user' => ['email' => $email, 'name' => $name]]);
                } else {
                    http_response_code(400);
                    echo json_encode(['verified' => false, 'message' => 'Invalid token']);
                    log_message("Invalid Google token attempt", 'WARNING');
                }
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Token required']);
                log_message("Google token verification attempt without token", 'WARNING');
            }
        }
    }
    if ($method === 'GET') {
        // ... (keep other GET routes)
        
        if (strpos($request_uri, '/verify-magic-link') === 0) {
            $token = $_GET['token'] ?? '';
            $stmt = $db->prepare("SELECT email, created_at FROM magic_links WHERE token = :token");
            $stmt->bindValue(':token', $token, SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
    
            if ($row && (time() - $row['created_at'] < $magic_link_expiration)) {
                $email = $row['email'];
                $stmt = $db->prepare("INSERT OR REPLACE INTO users (email, name) VALUES (:email, :name)");
                $stmt->bindValue(':email', $email, SQLITE3_TEXT);
                $stmt->bindValue(':name', explode('@', $email)[0], SQLITE3_TEXT);
                $stmt->execute();
    
                $userId = $db->lastInsertRowID();
                $_SESSION['user_id'] = $userId;
    
                $stmt = $db->prepare("DELETE FROM magic_links WHERE token = :token");
                $stmt->bindValue(':token', $token, SQLITE3_TEXT);
                $stmt->execute();
    
                header('Location: /dashboard');
                exit;
            } else {
                echo 'Invalid or expired magic link';
            }
        }
    }