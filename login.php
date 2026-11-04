<?php
session_start();
require_once "db.php";
$errorMessage = "";
$successMessage = "";
$showResetSuggestion = false;
$failedEmail = "";

$db = get_db_connection();

// Handle Password Reset Submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $rEmail = trim($_POST['reset_email'] ?? '');
    $newPass = $_POST['new_password'] ?? '';
    $confPass = $_POST['confirm_password'] ?? '';

    if (empty($rEmail) || empty($newPass)) {
        $errorMessage = "Please enter your email and new password.";
    } elseif ($newPass !== $confPass) {
        $errorMessage = "New passwords do not match. Please verify.";
        $showResetSuggestion = true;
        $failedEmail = $rEmail;
    } elseif (strlen($newPass) < 8) {
        $errorMessage = "Password must be at least 8 characters long.";
        $showResetSuggestion = true;
        $failedEmail = $rEmail;
    } elseif ($db) {
        $safeEmail = normalize_email($rEmail);
        $accountTable = null;
        foreach (account_tables() as $table) {
            $lookup = $db->prepare("SELECT 1 FROM `$table` WHERE email = ? LIMIT 1");
            $lookup->bind_param('s', $safeEmail);
            $lookup->execute();
            $found = $lookup->get_result()->num_rows > 0;
            $lookup->close();
            if ($found) {
                $accountTable = $table;
                break;
            }
        }
        if ($accountTable) {
            $passwordHash = password_hash($newPass, PASSWORD_DEFAULT);
            $statement = $db->prepare("UPDATE `$accountTable` SET password=? WHERE email=?");
            $statement->bind_param('ss', $passwordHash, $safeEmail);
            $statement->execute();
            $statement->close();
            unset($_SESSION['login_attempts'][$rEmail]);
            $successMessage = "Password changed successfully! You may now sign in with your new password.";
        } else {
            $errorMessage = "No account found registered with that email address.";
        }
    }
}
// Handle Standard Login Submission
elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = normalize_email($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $authenticated = false;
    $needsVerification = false;
    $needsApproval = false;

    // 1. Try Database Authentication
    if ($db) {
        $row = null;
        foreach (account_tables() as $table) {
            $lookup = $db->prepare("SELECT * FROM `$table` WHERE email = ? LIMIT 1");
            $lookup->bind_param('s', $email);
            $lookup->execute();
            $candidate = $lookup->get_result()->fetch_assoc();
            $lookup->close();
            if ($candidate) {
                $row = $candidate;
                break;
            }
        }

        if ($row) {
            if (!password_verify($password, $row['password'])) {
                $authenticated = false;
            } elseif (empty($row['email_verified'])) {
                $errorMessage = "Please verify your email address first. Open the verification link we sent to your Gmail inbox.";
                $needsVerification = true;
            } elseif (($row['role'] ?? '') === 'company' && ($row['approval_status'] ?? 'Pending') !== 'Approved') {
                $errorMessage = "Your company account is awaiting approval from the coordinator for your selected school.";
                $needsApproval = true;
            } else {
                session_regenerate_id(true);
                $_SESSION['name'] = $row['name'];
                $_SESSION['email'] = $row['email'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['school'] = !empty($row['school']) ? $row['school'] : "Cainta Campus";
                $_SESSION['institution'] = $row['institution'] ?? '';
                $_SESSION['campus'] = $row['campus'] ?? '';
                $_SESSION['course'] = !empty($row['course']) ? $row['course'] : "BSIT";
                practicore_log_audit($db, $email, $row['role'], 'login_succeeded', 'Account sign-in');
                unset($_SESSION['login_attempts'][$email]);
                $authenticated = true;
            }
        }
    }

    if ($authenticated) {
        // All account roles start in the campus newsfeed.
        header("Location: feed.php");
        exit();
    } elseif ($needsVerification || $needsApproval) {
        // An unverified email is not a failed password attempt.
    } else {
        // Track failed login attempts for this email
        if (!empty($email)) {
            $_SESSION['login_attempts'][$email] = ($_SESSION['login_attempts'][$email] ?? 0) + 1;
            $currentAttempts = $_SESSION['login_attempts'][$email];
            if ($db) practicore_log_audit($db, $email, 'unknown', 'login_failed', 'Failed attempt ' . $currentAttempts);

            if ($currentAttempts >= 5) {
                $showResetSuggestion = true;
                $failedEmail = $email;
                $errorMessage = "Incorrect password. You have reached $currentAttempts failed login attempts.";
            } else {
                $remaining = 5 - $currentAttempts;
                $errorMessage = "Invalid email or password. ($currentAttempts/5 attempts used. $remaining remaining before password reset is required).";
            }
        } else {
            $errorMessage = "Please provide your registered email and password.";
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — Practicore</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <!-- Custom Glass CSS -->
    <link rel="stylesheet" href="style.css?v=20261029-responsive1">
    <style>
        /* Use the browser's single password-reveal control; make it visible on the dark input. */
        input[type="password"]::-ms-reveal { filter: invert(1); opacity: 1; }
        @media (max-width: 767.98px) { body { padding: 1rem !important; align-items:flex-start !important; } .container { max-width: 100% !important; padding:0 !important; } .glass-panel { padding:1.25rem !important; } }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 2rem 1rem;">

    <!-- Background Orbs -->
    <div class="bg-ambient">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
    </div>

    <div class="container" style="max-width: 480px;">
        <div class="text-center mb-4">
            <a href="index.html" class="navbar-brand justify-content-center mb-3">
                <img class="brand-logo" src="logo.svg?v=20261029" alt="Practicore" style="height: 44px; width: 184px;">
            </a>
            <p class="text-muted small">Centralized Internship Placement & Training Recommendation Platform</p>
        </div>

        <div class="glass-panel p-4 p-md-5">
            <h4 class="fw-bold text-white mb-3 text-center">Account Sign In</h4>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success py-2 px-3 small mb-3 bg-opacity-25 border-success text-success">
                    <i class="bi bi-check-circle-fill me-1"></i> <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger py-2 px-3 small mb-3 bg-opacity-25 border-danger text-danger">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <!-- 5 Failed Attempts Suggestion Banner -->
            <?php if ($showResetSuggestion): ?>
                <div class="alert alert-warning py-3 px-3 small mb-3 bg-opacity-25 border-warning text-warning">
                    <div class="fw-bold mb-1"><i class="bi bi-shield-exclamation me-1"></i> 5 Failed Password Attempts Detected!</div>
                    <div>It appears you are having difficulty with your password. We suggest changing or resetting your password now.</div>
                    <button type="button" class="btn btn-warning btn-sm text-dark mt-2 fw-semibold w-100" onclick="toggleResetForm()">
                        <i class="bi bi-key-fill me-1"></i> Change / Reset Password Now
                    </button>
                </div>
            <?php endif; ?>

            <!-- Standard Login Form -->
            <form method="POST" action="" id="loginForm" style="<?php echo $showResetSuggestion ? 'display:none;' : ''; ?>">
                <div class="mb-3">
                    <label class="form-label small text-muted">Email Address</label>
                    <div class="glass-input-group">
                        <i class="bi bi-envelope"></i>
                        <input type="email" name="email" class="glass-input" placeholder="name@domain.com" value="<?php echo htmlspecialchars($_POST['email'] ?? $failedEmail); ?>" required>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label small text-muted">Password</label>
                    <div class="glass-input-group">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" id="loginPassword" class="glass-input" placeholder="Enter your password" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-gradient w-100 py-2 mb-3">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Log In to Dashboard
                </button>

                <div class="d-flex justify-content-between align-items-center small text-muted">
                    <a href="javascript:void(0)" onclick="toggleResetForm()" class="text-info text-decoration-none">Forgot / Reset Password?</a>
                    <a href="register.php" class="text-white text-decoration-none fw-semibold">Register account</a>
                </div>
            </form>

            <!-- Password Reset Form (Active on 5 attempts or user request) -->
            <div id="resetPasswordSection" style="<?php echo $showResetSuggestion ? 'display:block;' : 'display:none;'; ?>">
                <div class="p-3 rounded bg-dark bg-opacity-50 border border-warning border-opacity-25 mb-3">
                    <h6 class="fw-bold text-white mb-2"><i class="bi bi-key-fill text-warning me-2"></i>Reset Your Password</h6>
                    <p class="text-muted small mb-3">Enter your registered email and choose a new password.</p>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="reset_password">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Registered Email</label>
                            <div class="glass-input-group">
                                <i class="bi bi-envelope"></i>
                                <input type="email" name="reset_email" class="glass-input" value="<?php echo htmlspecialchars($failedEmail ?: ($_POST['email'] ?? '')); ?>" placeholder="your-email@domain.com" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">New Password</label>
                            <div class="glass-input-group">
                                <i class="bi bi-lock"></i>
                                <input type="password" name="new_password" id="newPassword" class="glass-input" placeholder="Enter new password (min. 8 chars)" minlength="8" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Confirm New Password</label>
                            <div class="glass-input-group">
                                <i class="bi bi-shield-check"></i>
                                <input type="password" name="confirm_password" id="confirmPassword" class="glass-input" placeholder="Re-enter new password" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-warning text-dark fw-bold w-100 py-2 mb-2">
                            <i class="bi bi-check2-circle me-1"></i> Confirm & Update Password
                        </button>
                        <button type="button" class="btn btn-glass w-100 btn-sm" onclick="toggleResetForm()">
                            Cancel & Return to Login
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <div class="text-center mt-3">
            <a href="index.html" class="text-muted small text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i> Back to Homepage
            </a>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleResetForm() {
            const loginF = document.getElementById("loginForm");
            const resetF = document.getElementById("resetPasswordSection");
            if (loginF.style.display === "none") {
                loginF.style.display = "block";
                resetF.style.display = "none";
            } else {
                loginF.style.display = "none";
                resetF.style.display = "block";
            }
        }
    </script>
</body>
</html>
