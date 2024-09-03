<?php
require_once 'index.php';  // This will give us access to the database connection and other configurations
require PHPMailer\PHPMailer\PHPMailer;
require PHPMailer\PHPMailer\SMTP;
require PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Display login form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Das Filter</title>
        <link rel="stylesheet" href="dashboard-styles.css">
        <script src="https://unpkg.com/htmx.org@1.9.6"></script>
        <link rel="stylesheet" href="dashboard-styles.css">
    </head>
    <body>
        <div class="container">
            <h1>Login to Das Filter</h1>
            <form hx-post="/login" hx-target="#login-message">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required>
                <button type="submit">Send Magic Link</button>
            </form>
            <div id="login-message"></div>
            <hr>
            <button onclick="handleGoogleSignIn()">Sign in with Google</button>
        </div>

        <script src="https://accounts.google.com/gsi/client" async defer></script>
        <script>
        function handleGoogleSignIn() {
            google.accounts.id.initialize({
                client_id: '<?php echo $google_client_id; ?>',
                callback: handleGoogleCredential
            });
            google.accounts.id.prompt();
        }

        function handleGoogleCredential(response) {
            const token = response.credential;
            fetch('/verify-google-token', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ token: token }),
            })
            .then(response => response.json())
            .then(data => {
                if (data.verified) {
                    window.location.href = '/dashboard';
                } else {
                    document.getElementById('login-message').textContent = 'Google authentication failed. Please try again.';
                }
            });
        }
        </script>
    </body>
    </html>
    <?php
} if ($email) {
    $token = bin2hex(random_bytes(32));
    $created_at = time();
    $stmt = $db->prepare("INSERT INTO magic_links (token, email, created_at) VALUES (:token, :email, :created_at)");
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $stmt->bindValue(':email', $email, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', $created_at, SQLITE3_INTEGER);
    $stmt->execute();
    
    $magic_link = "http://{$_SERVER['HTTP_HOST']}/verify-magic-link?token=$token";
    
    $mail = new PHPMailer(true);

    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtppro.zoho.com'; // Replace with your SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   = 'info@dasfilter.co'; // Replace with your email
        $mail->Password   = 'N@WRPhG%X8ChG^Mm'; // Replace with your email password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        //Recipients
        $mail->setFrom('noreply@dasfilter.shop', 'Das Filter');
        $mail->addAddress($email);

        //Content
        $mail->isHTML(true);
        $mail->Subject = 'Your Das Filter Login Link';
        $mail->Body    = "Hello,<br><br>Click the link below to log in to your Das Filter account:<br><br><a href='$magic_link'>$magic_link</a><br><br>This link will expire in 1 hour.<br><br>Best regards,<br>Das Filter Team";

        $mail->send();
        echo "<p>Magic link sent! Please check your email.</p>";
    } catch (Exception $e) {
        echo "<p>Message could not be sent. Mailer Error: {$mail->ErrorInfo}</p>";
    }
} else {
    http_response_code(400);
    echo "<p>Invalid email address. Please try again.</p>";
}
?>