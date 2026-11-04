<?php
// db.php - Database connection & auto-table initializations
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$dbname = getenv('DB_NAME') ?: 'practicore_db';

// Accounts remain in practicore_db, but are stored in a role-specific table.
function account_table_for_role($role) {
    if ($role === 'admin') return 'admins';
    if ($role === 'company') return 'companies';
    return 'users'; // Student accounts
}

function account_tables() {
    return ['users', 'admins', 'companies'];
}

function normalize_email($email) {
    return strtolower(trim($email));
}

/** Supported Practicore partner campuses (ICCT-style multi-campus schools). */
function practicore_campuses() {
    return [
        'Cainta Campus',
        'Pasig Campus',
        'Rizal Campus',
        'Quezon City Campus',
        'Manila Campus',
        'Antipolo Campus',
        'Main Campus',
        'Taytay Campus',
        'Other',
    ];
}

/** Normalize a school name without its campus suffix for institution-wide matching. */
function practicore_institution_key($institution, $legacySchool = '') {
    $value = trim((string)$institution);
    if ($value === '') {
        $value = trim((string)$legacySchool);
        $parts = preg_split('/\s+[—–]\s+/u', $value);
        if (count($parts) > 1) {
            $value = trim($parts[0]);
        }
    }

    $value = preg_replace(
        '/\s+(?:(?:cainta|pasig|rizal|quezon\s+city|manila|antipolo|taytay)\s+)?(?:(?:main|other)\s+)?campus\s*$/iu',
        '',
        $value
    );
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value));
}

/** Match institution names that differ only by a legal or organizational suffix. */
function practicore_same_institution($first, $second) {
    $firstKey = practicore_institution_key($first);
    $secondKey = practicore_institution_key($second);
    if ($firstKey === '' || $secondKey === '') {
        return false;
    }

    return $firstKey === $secondKey
        || strpos($firstKey, $secondKey . ' ') === 0
        || strpos($secondKey, $firstKey . ' ') === 0;
}

/** Return the selected campus, falling back to campus details in legacy school values. */
function practicore_account_campus($campus, $legacySchool = '') {
    $campus = trim((string)$campus);
    if ($campus !== '') {
        return $campus;
    }

    $school = trim((string)$legacySchool);
    $parts = preg_split('/\s+[—–]\s+/u', $school);
    if (count($parts) > 1 && trim($parts[1]) !== '') {
        return trim($parts[1]);
    }

    if (preg_match('/((?:(?:Cainta|Pasig|Rizal|Quezon\s+City|Manila|Antipolo|Taytay)\s+)?(?:(?:Main|Other)\s+)?Campus)$/iu', $school, $matches)) {
        return trim($matches[1]);
    }

    return 'Other';
}

/** Normalize a campus label for matching branch-scoped accounts. */
function practicore_campus_key($campus) {
    $value = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string)$campus), 'UTF-8')
        : strtolower(trim((string)$campus));
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    return trim(preg_replace('/\s+/u', ' ', $value));
}

/** Main-campus coordinators can see every branch belonging to their institution. */
function practicore_is_main_campus($campus, $institution = '', $legacySchool = '') {
    $campusLabel = practicore_campus_key($campus);
    if ($campusLabel === 'main campus' || preg_match('/\bmain campus$/u', $campusLabel)) {
        return true;
    }

    foreach ([$institution, $legacySchool] as $schoolLabel) {
        $schoolLabel = practicore_campus_key($schoolLabel);
        if (preg_match('/\bmain campus$/u', $schoolLabel)) {
            return true;
        }
    }
    return false;
}

/** A broadcast company is visible institution-wide only when its campus is Main Campus. */
function practicore_company_visible_to_student($studentInstitution, $studentCampus, $companyInstitution, $companyCampus) {
    if (!practicore_same_institution($studentInstitution, $companyInstitution)) {
        return false;
    }

    return practicore_campus_key($studentCampus) === practicore_campus_key($companyCampus)
        || practicore_is_main_campus($companyCampus, $companyInstitution);
}

/**
 * Generate a lightweight, session-backed CAPTCHA question.
 * A simple arithmetic challenge keeps bots out without external dependencies.
 */
function practicore_captcha_question() {
    $a = random_int(2, 9);
    $b = random_int(2, 9);
    // Randomly choose an operator that always produces a non-negative result.
    if (random_int(0, 1) === 1) {
        $question = "$a + $b";
        $answer = $a + $b;
    } else {
        $high = max($a, $b);
        $low = min($a, $b);
        $question = "$high - $low";
        $answer = $high - $low;
    }
    $_SESSION['captcha_answer'] = $answer;
    $_SESSION['captcha_issued_at'] = time();
    return $question;
}

/** Validate a submitted CAPTCHA answer, expiring stale challenges. */
function practicore_captcha_verify($input) {
    if (!isset($_SESSION['captcha_answer'])) {
        return false;
    }
    // A challenge is only valid for 10 minutes.
    if (isset($_SESSION['captcha_issued_at']) && (time() - $_SESSION['captcha_issued_at']) > 600) {
        unset($_SESSION['captcha_answer'], $_SESSION['captcha_issued_at']);
        return false;
    }
    $expected = (int)$_SESSION['captcha_answer'];
    unset($_SESSION['captcha_answer'], $_SESSION['captcha_issued_at']);
    return trim((string)$input) !== '' && (int)trim((string)$input) === $expected;
}

/** Send a plain-text notification email so users do not have to log in to check alerts. */
function send_notification_email($toEmail, $subject, $body) {
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $headers = "From: Practicore Notifications <no-reply@practicore.local>\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($toEmail, $subject, $body, $headers);
}

function account_exists_for_email($db, $email) {
    $email = normalize_email($email);
    foreach (account_tables() as $table) {
        $statement = $db->prepare("SELECT 1 FROM `$table` WHERE email = ? LIMIT 1");
        $statement->bind_param('s', $email);
        $statement->execute();
        $found = $statement->get_result()->num_rows > 0;
        $statement->close();
        if ($found) return true;
    }
    return false;
}

function send_account_verification_email($email, $token) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
    $verifyUrl = "$scheme://$host$basePath/verify_email.php?token=" . rawurlencode($token);
    $subject = 'Verify your Practicore account';
    $message = "Welcome to Practicore!\r\n\r\nVerify your email address by opening this link within 24 hours:\r\n$verifyUrl\r\n\r\nIf you did not create this account, you can ignore this email.";
    $headers = "From: no-reply@practicore.local\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($email, $subject, $message, $headers);
}

$conn = @new mysqli($host, $user, $pass);
if ($conn && !$conn->connect_error) {
    // Ensure database exists
    $conn->query("CREATE DATABASE IF NOT EXISTS `$dbname`");
    $conn->select_db($dbname);

    // 1. Users table
    $conn->query("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(50) NOT NULL DEFAULT 'student',
        `school` VARCHAR(100) DEFAULT 'Cainta Campus',
        `institution` VARCHAR(100) DEFAULT '',
        `campus` VARCHAR(100) DEFAULT '',
        `course` VARCHAR(50) DEFAULT 'BSIT',
        `avatar` VARCHAR(255) DEFAULT '',
        `bio` TEXT,
        `headline` VARCHAR(150) DEFAULT '',
        `phone` VARCHAR(50) DEFAULT '',
        `location` VARCHAR(150) DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `avatar` VARCHAR(255) DEFAULT ''");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `bio` TEXT");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `headline` VARCHAR(150) DEFAULT ''");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(50) DEFAULT ''");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `location` VARCHAR(150) DEFAULT ''");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `school` VARCHAR(100) DEFAULT 'Cainta Campus'");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `institution` VARCHAR(100) DEFAULT ''");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `campus` VARCHAR(100) DEFAULT ''");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `course` VARCHAR(50) DEFAULT 'BSIT'");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `email_verified` TINYINT(1) NOT NULL DEFAULT 0");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `verification_token_hash` CHAR(64) DEFAULT NULL");
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `verification_expires_at` DATETIME DEFAULT NULL");
    // Institutional student ID number, shown on the admin roster.
    @$conn->query("ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `id_number` VARCHAR(50) DEFAULT ''");
    // Self-heal legacy schemas where users.id lost its AUTO_INCREMENT / PRIMARY KEY,
    // which would otherwise assign id 0 to new registrations.
    $idCheck = $conn->query("SHOW COLUMNS FROM `users` LIKE 'id'");
    if ($idCheck && ($idRow = $idCheck->fetch_assoc()) && stripos($idRow['Extra'], 'auto_increment') === false) {
        @$conn->query("ALTER TABLE `users` MODIFY `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY");
    }

    // Admin and company registrations are intentionally separate from student users.
    $conn->query("CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(50) NOT NULL DEFAULT 'admin',
        `school` VARCHAR(100) DEFAULT 'Cainta Campus',
        `institution` VARCHAR(100) DEFAULT '',
        `campus` VARCHAR(100) DEFAULT '',
        `course` VARCHAR(50) DEFAULT 'Academic Administration',
        `avatar` VARCHAR(255) DEFAULT '',
        `bio` TEXT,
        `headline` VARCHAR(150) DEFAULT '',
        `phone` VARCHAR(50) DEFAULT '',
        `location` VARCHAR(150) DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS `companies` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(50) NOT NULL DEFAULT 'company',
        `school` VARCHAR(100) DEFAULT 'Cainta Campus',
        `institution` VARCHAR(100) DEFAULT '',
        `campus` VARCHAR(100) DEFAULT '',
        `approval_status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
        `broadcast_to_students` TINYINT(1) NOT NULL DEFAULT 0,
        `course` VARCHAR(50) DEFAULT 'Corporate Host',
        `avatar` VARCHAR(255) DEFAULT '',
        `bio` TEXT,
        `headline` VARCHAR(150) DEFAULT '',
        `phone` VARCHAR(50) DEFAULT '',
        `location` VARCHAR(150) DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach (['admins', 'companies'] as $accountTable) {
        @$conn->query("ALTER TABLE `$accountTable` ADD COLUMN IF NOT EXISTS `institution` VARCHAR(100) DEFAULT ''");
        @$conn->query("ALTER TABLE `$accountTable` ADD COLUMN IF NOT EXISTS `campus` VARCHAR(100) DEFAULT ''");
        @$conn->query("ALTER TABLE `$accountTable` ADD COLUMN IF NOT EXISTS `headline` VARCHAR(150) DEFAULT ''");
        @$conn->query("ALTER TABLE `$accountTable` ADD COLUMN IF NOT EXISTS `phone` VARCHAR(50) DEFAULT ''");
        @$conn->query("ALTER TABLE `$accountTable` ADD COLUMN IF NOT EXISTS `location` VARCHAR(150) DEFAULT ''");
        @$conn->query("ALTER TABLE `$accountTable` ADD COLUMN IF NOT EXISTS `email_verified` TINYINT(1) NOT NULL DEFAULT 0");
        @$conn->query("ALTER TABLE `$accountTable` ADD COLUMN IF NOT EXISTS `verification_token_hash` CHAR(64) DEFAULT NULL");
        @$conn->query("ALTER TABLE `$accountTable` ADD COLUMN IF NOT EXISTS `verification_expires_at` DATETIME DEFAULT NULL");
    }
    // Preserve access for companies that existed before account-level approval was added.
    @$conn->query("ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `approval_status` VARCHAR(20) NOT NULL DEFAULT 'Approved'");
    @$conn->query("ALTER TABLE `companies` ADD COLUMN IF NOT EXISTS `broadcast_to_students` TINYINT(1) NOT NULL DEFAULT 0");

    // 2. Jobs table (Posted by Companies with customizable required documents)
    $conn->query("CREATE TABLE IF NOT EXISTS `jobs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `company_name` VARCHAR(100) NOT NULL,
        `company_email` VARCHAR(100) NOT NULL,
        `title` VARCHAR(150) NOT NULL,
        `description` TEXT,
        `school` VARCHAR(100) NOT NULL,
        `course` VARCHAR(50) NOT NULL,
        `allowance` VARCHAR(50) DEFAULT 'Unpaid',
        `slots` INT DEFAULT 1,
        `skills` VARCHAR(255) DEFAULT '',
        `required_documents` VARCHAR(255) DEFAULT 'Resume / CV, Parents Consent & Waiver',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("ALTER TABLE `jobs` ADD COLUMN IF NOT EXISTS `required_documents` VARCHAR(255) DEFAULT 'Resume / CV, Parents Consent & Waiver'");
    @$conn->query("ALTER TABLE `jobs` ADD COLUMN IF NOT EXISTS `deadline` DATE DEFAULT NULL");
    @$conn->query("ALTER TABLE `jobs` ADD COLUMN IF NOT EXISTS `description` TEXT");

    // 3. Applications table (with student attachments & notes)
    $conn->query("CREATE TABLE IF NOT EXISTS `applications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `job_id` INT DEFAULT 0,
        `student_name` VARCHAR(100) NOT NULL,
        `student_email` VARCHAR(100) NOT NULL,
        `student_school` VARCHAR(100) NOT NULL,
        `student_course` VARCHAR(50) NOT NULL,
        `company_name` VARCHAR(100) NOT NULL,
        `company_email` VARCHAR(100) DEFAULT '',
        `job_title` VARCHAR(150) NOT NULL,
        `status` VARCHAR(50) DEFAULT 'Pending Review',
        `attachment_name` VARCHAR(255) DEFAULT '',
        `student_notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("ALTER TABLE `applications` ADD COLUMN IF NOT EXISTS `attachment_name` VARCHAR(255) DEFAULT ''");
    @$conn->query("ALTER TABLE `applications` ADD COLUMN IF NOT EXISTS `student_notes` TEXT");
    @$conn->query("ALTER TABLE `applications` ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    $conn->query("CREATE TABLE IF NOT EXISTS `student_documents` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_email` VARCHAR(100) NOT NULL,
        `label` VARCHAR(100) NOT NULL,
        `file_path` VARCHAR(255) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_student_documents_email` (`student_email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS `application_documents` (
        `application_id` INT NOT NULL,
        `student_document_id` INT NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`application_id`, `student_document_id`),
        INDEX `idx_application_documents_document` (`student_document_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4. DTR Logs table (with company supervisor comments and approval)
    $conn->query("CREATE TABLE IF NOT EXISTS `dtr_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_email` VARCHAR(100) NOT NULL,
        `student_name` VARCHAR(100) DEFAULT '',
        `company_name` VARCHAR(100) DEFAULT '',
        `log_date` DATE NOT NULL,
        `hours` DECIMAL(4,1) NOT NULL DEFAULT 0.0,
        `task_description` TEXT,
        `supervisor_comment` TEXT,
        `status` VARCHAR(50) DEFAULT 'Pending Supervisor Sign',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    @$conn->query("ALTER TABLE `dtr_logs` ADD COLUMN IF NOT EXISTS `student_name` VARCHAR(100) DEFAULT ''");
    @$conn->query("ALTER TABLE `dtr_logs` ADD COLUMN IF NOT EXISTS `company_name` VARCHAR(100) DEFAULT ''");
    @$conn->query("ALTER TABLE `dtr_logs` ADD COLUMN IF NOT EXISTS `supervisor_comment` TEXT");

    // 5. Training / Guide Modules table (Posted and Edited by Admin)
    $conn->query("CREATE TABLE IF NOT EXISTS `modules` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `admin_name` VARCHAR(100) NOT NULL,
        `admin_email` VARCHAR(100) NOT NULL,
        `school` VARCHAR(100) NOT NULL,
        `course` VARCHAR(50) NOT NULL DEFAULT 'BSIT',
        `title` VARCHAR(150) NOT NULL,
        `hours` INT DEFAULT 10,
        `level` VARCHAR(50) DEFAULT 'All Levels',
        `description` TEXT,
        `guide_link` VARCHAR(255) DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Per-student study progress and earned module achievements.
    $conn->query("CREATE TABLE IF NOT EXISTS `module_progress` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `module_id` INT NOT NULL,
        `student_email` VARCHAR(100) NOT NULL,
        `current_page` INT NOT NULL DEFAULT 1,
        `pages_required` INT NOT NULL DEFAULT 0,
        `hours_earned` INT NOT NULL DEFAULT 0,
        `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
        `completed_at` DATETIME DEFAULT NULL,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_module_student` (`module_id`, `student_email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("ALTER TABLE `module_progress` ADD COLUMN IF NOT EXISTS `pages_required` INT NOT NULL DEFAULT 0 AFTER `current_page`");

    // 6. Clearance Records table (Interactive & Editable Official University Clearance)
    $conn->query("CREATE TABLE IF NOT EXISTS `clearances` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_email` VARCHAR(100) NOT NULL UNIQUE,
        `student_name` VARCHAR(100) NOT NULL,
        `student_school` VARCHAR(100) NOT NULL,
        `student_course` VARCHAR(50) NOT NULL,
        `moa_status` VARCHAR(50) DEFAULT 'Pending',
        `moa_file` VARCHAR(255) DEFAULT '',
        `endorsement_status` VARCHAR(50) DEFAULT 'Pending',
        `endorsement_file` VARCHAR(255) DEFAULT '',
        `waiver_status` VARCHAR(50) DEFAULT 'Pending',
        `waiver_file` VARCHAR(255) DEFAULT '',
        `medical_status` VARCHAR(50) DEFAULT 'Pending',
        `medical_file` VARCHAR(255) DEFAULT '',
        `is_completed` TINYINT(1) DEFAULT 0,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Use one approval label across every clearance requirement, including old records.
    @$conn->query("UPDATE `clearances` SET endorsement_status='Verified' WHERE endorsement_status='Released'");
    @$conn->query("UPDATE `clearances` SET waiver_status='Verified' WHERE waiver_status='Validated'");
    @$conn->query("UPDATE `clearances` SET medical_status='Verified' WHERE medical_status='Passed'");

    // 7. Direct Messages table (Student <-> Company <-> Admin cross-talk)
    $conn->query("CREATE TABLE IF NOT EXISTS `messages` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `sender_name` VARCHAR(100) NOT NULL,
        `sender_email` VARCHAR(100) NOT NULL,
        `sender_role` VARCHAR(50) NOT NULL,
        `receiver_email` VARCHAR(100) NOT NULL,
        `receiver_name` VARCHAR(100) DEFAULT '',
        `message` TEXT NOT NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `read_at` TIMESTAMP NULL DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Safe migration for existing tables
    $conn->query("ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `is_read` TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE `messages` ADD COLUMN IF NOT EXISTS `read_at` TIMESTAMP NULL DEFAULT NULL");

    // 7b. Stored notifications let the dashboard show the exact clearance action.
    $conn->query("CREATE TABLE IF NOT EXISTS `notifications` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `receiver_email` VARCHAR(100) NOT NULL,
        `type` VARCHAR(50) NOT NULL DEFAULT 'system',
        `message` VARCHAR(255) NOT NULL,
        `is_read` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_notifications_receiver_read` (`receiver_email`, `is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 8. Favorites & Reports table
    $conn->query("CREATE TABLE IF NOT EXISTS `favorites` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_email` VARCHAR(100) NOT NULL,
        `job_id` INT NOT NULL,
        `company_name` VARCHAR(100) NOT NULL,
        `job_title` VARCHAR(150) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS `favorite_companies` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_email` VARCHAR(100) NOT NULL,
        `company_email` VARCHAR(100) NOT NULL,
        `company_name` VARCHAR(100) NOT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_student_company_favorite` (`student_email`, `company_email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS `reports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_email` VARCHAR(100) NOT NULL,
        `company_name` VARCHAR(100) NOT NULL,
        `reason` TEXT NOT NULL,
        `status` VARCHAR(50) DEFAULT 'Pending Review',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 9. Company OJT Offers (Coordinator-approved listings students can search).
    // Only offers approved by a campus coordinator are visible to matching students.
    $conn->query("CREATE TABLE IF NOT EXISTS `company_offers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `company_name` VARCHAR(100) NOT NULL,
        `company_email` VARCHAR(100) DEFAULT '',
        `title` VARCHAR(150) NOT NULL,
        `description` TEXT,
        `school` VARCHAR(100) NOT NULL,
        `course` VARCHAR(50) NOT NULL DEFAULT 'BSIT',
        `slots` INT DEFAULT 1,
        `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
        `approved_by` VARCHAR(100) DEFAULT '',
        `approved_at` DATETIME DEFAULT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 10. Student "Looking for Company" Notices (Internship Interest Posting)
    $conn->query("CREATE TABLE IF NOT EXISTS `student_notices` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `student_name` VARCHAR(100) NOT NULL,
        `student_email` VARCHAR(100) NOT NULL,
        `school` VARCHAR(100) NOT NULL,
        `course` VARCHAR(50) NOT NULL,
        `headline` VARCHAR(150) NOT NULL,
        `preferred_role` VARCHAR(100) NOT NULL,
        `skills` VARCHAR(255) DEFAULT '',
        `availability` VARCHAR(100) DEFAULT 'Immediate / Full-time',
        `message` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS `company_training_recommendations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `company_email` VARCHAR(100) NOT NULL,
        `company_name` VARCHAR(100) NOT NULL,
        `school` VARCHAR(100) NOT NULL,
        `course` VARCHAR(50) NOT NULL DEFAULT 'All Courses',
        `title` VARCHAR(150) NOT NULL,
        `description` TEXT,
        `resource_url` VARCHAR(500) DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_training_school_course` (`school`, `course`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS `system_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `school` VARCHAR(100) NOT NULL,
        `setting_key` VARCHAR(100) NOT NULL,
        `setting_value` TEXT NOT NULL,
        `updated_by` VARCHAR(100) NOT NULL DEFAULT '',
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_school_setting` (`school`, `setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS `audit_logs` (
        `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
        `actor_email` VARCHAR(100) NOT NULL DEFAULT '',
        `actor_role` VARCHAR(50) NOT NULL DEFAULT '',
        `school` VARCHAR(100) NOT NULL DEFAULT '',
        `action` VARCHAR(100) NOT NULL,
        `details` TEXT,
        `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_audit_created_at` (`created_at`),
        INDEX `idx_audit_actor` (`actor_email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    @$conn->query("ALTER TABLE `audit_logs` ADD COLUMN IF NOT EXISTS `school` VARCHAR(100) NOT NULL DEFAULT '' AFTER `actor_role`");
}

function practicore_log_audit($conn, $actorEmail, $actorRole, $action, $details = '') {
    if (!$conn || $conn->connect_error) {
        return false;
    }

    $school = $_SESSION['school'] ?? '';
    $statement = $conn->prepare("INSERT INTO audit_logs (actor_email, actor_role, school, action, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$statement) {
        return false;
    }
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
    $statement->bind_param('ssssss', $actorEmail, $actorRole, $school, $action, $details, $ipAddress);
    $saved = $statement->execute();
    $statement->close();
    return $saved;
}

function get_db_connection() {
    global $conn;
    return ($conn && !$conn->connect_error) ? $conn : false;
}
?>
