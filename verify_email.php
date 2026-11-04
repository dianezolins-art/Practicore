<?php
session_start();
require_once "db.php";

$message = "The verification link is invalid or has expired.";
$verified = false;
$token = trim($_GET['token'] ?? '');
$db = get_db_connection();

if ($db && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenHash = hash('sha256', $token);
    foreach (account_tables() as $table) {
        $statement = $db->prepare("SELECT id FROM `$table` WHERE verification_token_hash = ? AND verification_expires_at >= NOW() LIMIT 1");
        $statement->bind_param('s', $tokenHash);
        $statement->execute();
        $result = $statement->get_result();
        $account = $result->fetch_assoc();
        $statement->close();

        if ($account) {
            $update = $db->prepare("UPDATE `$table` SET email_verified = 1, verification_token_hash = NULL, verification_expires_at = NULL WHERE id = ?");
            $update->bind_param('i', $account['id']);
            $update->execute();
            $update->close();
            $verified = true;
            $message = "Your email is verified. You may now log in to Practicore.";
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification — Practicore</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="style.css?v=20261029-responsive1">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 2rem 1rem;">
    <div class="bg-ambient"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
    <main class="container" style="max-width: 520px;">
        <div class="glass-panel p-4 p-md-5 text-center">
            <i class="bi <?php echo $verified ? 'bi-patch-check-fill text-success' : 'bi-exclamation-triangle-fill text-warning'; ?>" style="font-size: 3rem;"></i>
            <h1 class="h4 mt-3 mb-2"><?php echo $verified ? 'Email verified' : 'Verification unavailable'; ?></h1>
            <p class="text-muted mb-4"><?php echo htmlspecialchars($message); ?></p>
            <a href="login.php" class="btn btn-gradient">Go to Login</a>
        </div>
    </main>
</body>
</html>
