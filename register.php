<?php
session_start();
require_once "db.php";
$successMessage = "";
$errorMessage = "";

/**
 * Validates the Gmail address format accepted for registration.
 */
function validate_genuine_email($email) {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Invalid email address format.";
    }

    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return "Invalid email address structure.";
    }

    $local = strtolower($parts[0]);
    $domain = strtolower($parts[1]);

    // 1. Username length check
    if (strlen($local) < 3) {
        return "The email username is too short. Please use your genuine email.";
    }

    // 2. Reject obvious fake / keyboard mash usernames
    $fakeLocals = ['asdf', 'asdfgh', 'qwerty', '12345', '123456', 'test', 'tester', 'dummy', 'fake', 'admin123', 'sample', 'abcdef'];
    if (in_array($local, $fakeLocals)) {
        return "Please provide a genuine, authentic email address.";
    }

    // This project accepts personal Gmail accounts only.
    if ($domain !== 'gmail.com') {
        return "Please enter a valid Gmail address ending in @gmail.com.";
    }

    return true;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name'] ?? '');
    $email = normalize_email($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'student');
    // Institution + campus are combined so school-scoped dashboards match reliably.
    $institution = preg_replace('/\s+/', ' ', trim($_POST['school'] ?? ''));
    $campus = trim($_POST['campus'] ?? '');
    $school = $institution !== '' ? $institution : '';
    if ($campus !== '') {
        $school = trim($school . ' — ' . $campus);
    }
    $course = trim($_POST['course'] ?? '');
    $idNumber = preg_replace('/[^A-Za-z0-9\-]/', '', trim($_POST['id_number'] ?? ''));
    $password = $_POST['password'] ?? '';

    // Email authenticity first, then the remaining fields.
    $emailCheck = validate_genuine_email($email);
    if ($emailCheck !== true) {
        $errorMessage = $emailCheck;
    } elseif (empty($name)) {
        $errorMessage = "Please enter your full name or organization.";
    } elseif (empty($school)) {
        $errorMessage = "Please enter your designated school, college, or partner campus.";
    } elseif (empty($campus)) {
        $errorMessage = "Please select your campus so your school can identify you.";
    } elseif ($role === 'student' && empty($course)) {
        $errorMessage = "Please specify your degree course or academic program.";
    } elseif ($role === 'student' && empty($idNumber)) {
        $errorMessage = "Please enter your Student ID Number so your coordinator can identify you.";
    } elseif (!in_array($role, ['student', 'company', 'admin'], true)) {
        $errorMessage = "Please choose a valid account role.";
    } elseif (strlen($password) < 8) {
        $errorMessage = "Password must be at least 8 characters long.";
    } else {
        if (empty($course)) {
            $course = ($role === 'company') ? 'Corporate Host' : 'Academic Administration';
        }

        $db = get_db_connection();
        if ($db) {
            if (account_exists_for_email($db, $email)) {
                $errorMessage = "This Gmail address is already registered. Please use a different email address or log in to your existing account.";
            } else {
                $accountTable = account_table_for_role($role);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $verificationToken = bin2hex(random_bytes(32));
                $verificationHash = hash('sha256', $verificationToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 86400);

                if ($accountTable === 'users') {
                    $statement = $db->prepare("INSERT INTO `$accountTable` (name, email, role, password, school, institution, campus, course, id_number, email_verified, verification_token_hash, verification_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
                    $statement->bind_param('sssssssssss', $name, $email, $role, $passwordHash, $school, $institution, $campus, $course, $idNumber, $verificationHash, $expiresAt);
                } elseif ($accountTable === 'companies') {
                    $statement = $db->prepare("INSERT INTO `companies` (name, email, role, password, school, institution, campus, course, approval_status, broadcast_to_students, email_verified, verification_token_hash, verification_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 0, 0, ?, ?)");
                    $statement->bind_param('ssssssssss', $name, $email, $role, $passwordHash, $school, $institution, $campus, $course, $verificationHash, $expiresAt);
                } else {
                    $statement = $db->prepare("INSERT INTO `$accountTable` (name, email, role, password, school, institution, campus, course, email_verified, verification_token_hash, verification_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
                    $statement->bind_param('ssssssssss', $name, $email, $role, $passwordHash, $school, $institution, $campus, $course, $verificationHash, $expiresAt);
                }

                if ($statement->execute()) {
                    $_SESSION['school'] = $school;
                    practicore_log_audit($db, $email, $role, 'account_registered', $role . ' account registration');
                    if ($role === 'company') {
                        $adminLookup = $db->prepare("SELECT email FROM admins WHERE LOWER(TRIM(school))=LOWER(TRIM(?))");
                        $adminLookup->bind_param('s', $school);
                        $adminLookup->execute();
                        $adminResults = $adminLookup->get_result();
                        $notice = "$name requested to partner with $school. Review the company account in your Practicore dashboard.";
                        while ($admin = $adminResults->fetch_assoc()) {
                            $notification = $db->prepare("INSERT INTO notifications (receiver_email, type, message) VALUES (?, 'company', ?)");
                            $notification->bind_param('ss', $admin['email'], $notice);
                            $notification->execute();
                            $notification->close();
                            send_notification_email($admin['email'], 'Company partnership request', $notice);
                        }
                        $adminLookup->close();
                    }
                    $mailSent = send_account_verification_email($email, $verificationToken);
                    if ($mailSent) {
                        $successMessage = $role === 'company'
                            ? "Registration submitted. We sent a verification link to $email; your school coordinator has been notified. Log in after email verification and coordinator approval."
                            : "Registration successful! We sent a verification link to $email. Please confirm your Gmail before logging in.";
                    } else {
                        // Local mail transport is often unavailable; do not lock the user out.
                        $db->query("UPDATE `$accountTable` SET email_verified=1, verification_token_hash=NULL, verification_expires_at=NULL WHERE email='$email'");
                        $successMessage = $role === 'company'
                            ? "Registration submitted and your school coordinator has been notified. You can log in after coordinator approval."
                            : "Registration successful! You can now log in to your dashboard.";
                    }
                } else {
                    // Handle a simultaneous registration attempt with the same address.
                    $errorMessage = $statement->errno === 1062
                        ? "This Gmail address is already registered. Please use a different email address or log in to your existing account."
                        : "Registration failed. Please try again.";
                }
                $statement->close();
            }
        } else {
            $errorMessage = "Registration is unavailable because the database connection could not be established.";
        }
    }
}
$campusOptions = practicore_campuses();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Account — Practicore</title>
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
        @media (max-width: 767.98px) { body { padding:1rem !important; align-items:flex-start !important; } .container { max-width:100% !important; padding:0 !important; } .glass-panel { padding:1.25rem !important; } }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 2rem 1rem;">

    <!-- Background Orbs -->
    <div class="bg-ambient">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
    </div>

    <div class="container" style="max-width: 520px;">
        <div class="text-center mb-4">
            <a href="index.html" class="navbar-brand justify-content-center mb-3">
                <img class="brand-logo" src="logo.svg?v=20261029" alt="Practicore" style="height: 44px; width: 184px;">
            </a>
            <p class="text-muted small">Centralized Internship Placement & Training Recommendation Platform</p>
        </div>

        <div class="glass-panel p-4 p-md-5">
            <h4 class="fw-bold text-white mb-3 text-center">Create New Account</h4>

            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success py-2 px-3 small mb-3 bg-opacity-25 border-success text-success">
                    <i class="bi bi-check-circle-fill me-1"></i> <?php echo htmlspecialchars($successMessage); ?>
                    <div class="mt-2"><a href="login.php" class="btn btn-sm btn-gradient py-1 px-3">Go to Login</a></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger py-2 px-3 small mb-3 bg-opacity-25 border-danger text-danger">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label class="form-label small text-muted">Full Name / Organization</label>
                    <div class="glass-input-group">
                        <i class="bi bi-person"></i>
                        <input type="text" name="name" class="glass-input" placeholder="e.g. Juan Dela Cruz or TechCorp Inc." value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-muted">Account Role</label>
                    <div class="glass-input-group">
                        <i class="bi bi-person-badge"></i>
                        <select name="role" id="roleSelector" class="glass-input" onchange="adjustRoleFields(this.value)" required>
                            <option value="student" <?php echo (($_POST['role'] ?? '') === 'student') ? 'selected' : ''; ?>>Student Trainee (OJT)</option>
                            <option value="company" <?php echo (($_POST['role'] ?? '') === 'company') ? 'selected' : ''; ?>>Partner Host Company</option>
                            <option value="admin" <?php echo (($_POST['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>School Admin / Coordinator</option>
                        </select>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small text-muted">Gmail Address</label>
                    <div class="glass-input-group">
                        <i class="bi bi-envelope"></i>
                        <input type="email" name="email" class="glass-input" placeholder="name@gmail.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" pattern="[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@gmail\.com" title="Please use a valid @gmail.com address." required>
                    </div>
                    <div class="text-muted small mt-1" style="font-size:0.75rem;">
                        <i class="bi bi-shield-check text-info me-1"></i> Gmail addresses only. Duplicate or invalid addresses are rejected.
                    </div>
                </div>

                <!-- Designated School (User fills this in manually - NOT pre-filled!) -->
                <div class="mb-3" id="schoolFieldGroup">
                    <label class="form-label small text-muted" id="schoolFieldLabel">Designated School / Campus</label>
                    <div class="glass-input-group">
                        <i class="bi bi-buildings"></i>
                        <input type="text" name="school" id="schoolInput" class="glass-input" placeholder="Enter Your School (e.g. ICCT Colleges)" value="<?php echo htmlspecialchars($_POST['school'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- Campus Specification (needed for multi-campus schools like ICCT) -->
                <div class="mb-3" id="campusFieldGroup">
                    <label class="form-label small text-muted" id="campusFieldLabel">Campus / Branch</label>
                    <div class="glass-input-group">
                        <i class="bi bi-geo-alt"></i>
                        <select name="campus" id="campusInput" class="glass-input" required>
                            <option value="">Select your campus…</option>
                            <?php foreach ($campusOptions as $campus): ?>
                                <option value="<?php echo htmlspecialchars($campus); ?>" <?php echo (($_POST['campus'] ?? '') === $campus) ? 'selected' : ''; ?>><?php echo htmlspecialchars($campus); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="text-muted small mt-1" id="campusHelpText" style="font-size:0.75rem;">
                        <i class="bi bi-info-circle text-info me-1"></i> Schools like ICCT have many branches — pick the exact campus so your coordinator can find you.
                    </div>
                </div>

                <!-- Course / Field (User fills this in manually - NOT pre-filled!) -->
                <div class="mb-3" id="courseFieldGroup">
                    <label class="form-label small text-muted">Degree Program / Specialization</label>
                    <div class="glass-input-group">
                        <i class="bi bi-mortarboard"></i>
                        <input type="text" name="course" id="courseInput" class="glass-input" placeholder="Enter Your Course (e.g. BSIT, BSCS, CpE)" value="<?php echo htmlspecialchars($_POST['course'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Student ID Number (shown to the campus coordinator) -->
                <div class="mb-3" id="idNumberFieldGroup">
                    <label class="form-label small text-muted">Student ID Number</label>
                    <div class="glass-input-group">
                        <i class="bi bi-card-text"></i>
                        <input type="text" name="id_number" id="idNumberInput" class="glass-input" placeholder="e.g. 2023-00123" value="<?php echo htmlspecialchars($_POST['id_number'] ?? ''); ?>">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label small text-muted">Password</label>
                    <div class="glass-input-group">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" id="registerPassword" class="glass-input" placeholder="Create your password (min. 8 characters)" minlength="8" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-gradient w-100 py-2 mb-3">
                    <i class="bi bi-person-plus-fill me-1"></i> Register Account
                </button>

                <div class="text-center small text-muted">
                    Already registered? <a href="login.php" class="text-white text-decoration-none fw-semibold">Log in here</a>
                </div>
            </form>
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
        function adjustRoleFields(role) {
            const schoolLabel = document.getElementById("schoolFieldLabel");
            const schoolInput = document.getElementById("schoolInput");
            const courseGroup = document.getElementById("courseFieldGroup");
            const courseInput = document.getElementById("courseInput");
            const campusLabel = document.getElementById("campusFieldLabel");
            const campusHelpText = document.getElementById("campusHelpText");
            const idGroup = document.getElementById("idNumberFieldGroup");
            const idInput = document.getElementById("idNumberInput");

            if (role === "company") {
                schoolLabel.textContent = "Primary Target School Partner";
                schoolInput.placeholder = "e.g. ICCT Colleges Cainta, URS, RTU...";
                if (campusLabel) campusLabel.textContent = "Target Campus / Branch";
                courseGroup.style.display = "none";
                if (courseInput) courseInput.required = false;
                if (idGroup) idGroup.style.display = "none";
                if (idInput) idInput.required = false;
            } else if (role === "admin") {
                schoolLabel.textContent = "Coordinated School / University";
                schoolInput.placeholder = "e.g. ICCT Colleges";
                if (campusLabel) campusLabel.textContent = "Coordinated Campus / Branch";
                if (campusHelpText) campusHelpText.innerHTML = '<i class="bi bi-info-circle text-info me-1"></i> Students registered under this school will appear in your coordinator dashboard. Main Campus coordinators can see students from its branches.';
                courseGroup.style.display = "none";
                if (courseInput) courseInput.required = false;
                if (idGroup) idGroup.style.display = "none";
                if (idInput) idInput.required = false;
            } else {
                schoolLabel.textContent = "Designated School / University";
                schoolInput.placeholder = "e.g. ICCT Colleges";
                if (campusLabel) campusLabel.textContent = "Campus / Branch";
                if (campusHelpText) campusHelpText.innerHTML = '<i class="bi bi-info-circle text-info me-1"></i> Schools like ICCT have many branches — pick the exact campus so your coordinator can find you.';
                courseGroup.style.display = "block";
                if (courseInput) courseInput.required = true;
                if (idGroup) idGroup.style.display = "block";
                if (idInput) idInput.required = true;
            }
        }
        adjustRoleFields(document.getElementById("roleSelector").value);
    </script>
</body>
</html>
