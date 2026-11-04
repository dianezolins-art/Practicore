<?php
session_start();
require_once "db.php";

// Enforce authentication
if (!isset($_SESSION['email']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

$email = $_SESSION['email'];
$db = get_db_connection();

// Refresh user info from DB
$name = $_SESSION['name'] ?? 'User';
$role = $_SESSION['role'] ?? 'student';
$school = $_SESSION['school'] ?? 'Cainta Campus';
$institution = $_SESSION['institution'] ?? '';
$campus = $_SESSION['campus'] ?? '';
$course = $_SESSION['course'] ?? 'BSIT';
$avatar = '';
$bio = '';
$headline = '';
$phone = '';
$location = '';
$companyApprovalStatus = 'Pending';
$accountTable = account_table_for_role($role);

if ($db) {
    $safeEmail = $db->real_escape_string($email);
    $uQ = $db->query("SELECT * FROM `$accountTable` WHERE email='$safeEmail'");
    if (!$uQ || $uQ->num_rows === 0) {
        // The account may have been deleted/reset while this browser still had a session.
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit();
    }

    $uData = $uQ->fetch_assoc();
    if (empty($uData['email_verified'])) {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit();
    }

    $name = $uData['name'];
    $role = $uData['role'];
    $school = $uData['school'];
    $institution = $uData['institution'] ?? '';
    $campus = $uData['campus'] ?? '';
    $course = $uData['course'];
    $avatar = $uData['avatar'] ?? '';
    $bio = $uData['bio'] ?? '';
    $headline = $uData['headline'] ?? '';
    $phone = $uData['phone'] ?? '';
    $location = $uData['location'] ?? '';
    if ($role === 'company') $companyApprovalStatus = $uData['approval_status'] ?? 'Pending';
    $_SESSION['name'] = $name;
    $_SESSION['role'] = $role;
    $_SESSION['school'] = $school;
    $_SESSION['course'] = $course;
}

$name = htmlspecialchars($name);
$role = htmlspecialchars($role);
$school = htmlspecialchars($school);
$course = htmlspecialchars($course);
$rawSchool = html_entity_decode($school, ENT_QUOTES, 'UTF-8');
$adminInstitutionKey = practicore_institution_key($institution, $rawSchool);
$adminCampus = practicore_account_campus($campus, $rawSchool);
$adminCampusKey = practicore_campus_key($adminCampus);
$adminCanViewAllCampuses = practicore_is_main_campus($adminCampus, $institution, $rawSchool);
$schoolFilter = $db ? $db->real_escape_string(strtolower(trim(html_entity_decode($school, ENT_QUOTES, 'UTF-8')))) : '';

if (isset($_GET['download_student_document']) && $db) {
    $documentId = (int)$_GET['download_student_document'];
    $documentQuery = $db->prepare("SELECT file_path, label, student_email FROM student_documents WHERE id=? LIMIT 1");
    if (!$documentQuery) {
        http_response_code(500);
        exit('Unable to access the requested document.');
    }
    $documentQuery->bind_param('i', $documentId);
    $documentQuery->execute();
    $document = $documentQuery->get_result()->fetch_assoc();
    $documentQuery->close();

    $canAccessDocument = $document && $document['student_email'] === $email;
    if ($document && $role === 'admin') {
        $studentAccountQuery = $db->prepare("SELECT school, institution, campus FROM users WHERE email=? AND role='student' LIMIT 1");
        if (!$studentAccountQuery) {
            http_response_code(500);
            exit('Unable to verify school document access.');
        }
        $studentAccountQuery->bind_param('s', $document['student_email']);
        $studentAccountQuery->execute();
        $documentStudent = $studentAccountQuery->get_result()->fetch_assoc();
        $studentAccountQuery->close();
        if ($documentStudent) {
            $documentInstitution = practicore_institution_key($documentStudent['institution'] ?? '', $documentStudent['school'] ?? '');
            $documentCampus = practicore_account_campus($documentStudent['campus'] ?? '', $documentStudent['school'] ?? '');
            $sameInstitution = practicore_same_institution($documentInstitution, $adminInstitutionKey);
            $sameCampus = practicore_campus_key($documentCampus) === $adminCampusKey;
            $canAccessDocument = $sameInstitution && ($adminCanViewAllCampuses || $sameCampus);
        } else {
            $canAccessDocument = false;
        }
    } elseif ($document && $role === 'company' && $companyApprovalStatus === 'Approved') {
        $applicationId = (int)($_GET['application_id'] ?? 0);
        if ($applicationId > 0) {
            $companyName = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
            $applicationAccessQuery = $db->prepare("SELECT a.id FROM applications a
                INNER JOIN application_documents ad ON ad.application_id=a.id AND ad.student_document_id=?
                WHERE a.id=? AND (a.company_email=? OR (a.company_email='' AND a.company_name=?))
                LIMIT 1");
            if ($applicationAccessQuery) {
                $applicationAccessQuery->bind_param('iiss', $documentId, $applicationId, $email, $companyName);
                $applicationAccessQuery->execute();
                $canAccessDocument = $applicationAccessQuery->get_result()->num_rows > 0;
                $applicationAccessQuery->close();
            } else {
                http_response_code(500);
                exit('Unable to verify document access.');
            }
        }
    }

    if (!$canAccessDocument) {
        http_response_code(404);
        exit('Document not found.');
    }

    $documentPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $document['file_path']));
    $documentRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'student-documents');
    if (!$documentPath || !$documentRoot || strpos($documentPath, $documentRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($documentPath)) {
        http_response_code(404);
        exit('Document file is unavailable.');
    }
    $documentMime = (new finfo(FILEINFO_MIME_TYPE))->file($documentPath) ?: 'application/octet-stream';
    $documentFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($documentPath));
    header('Content-Type: ' . $documentMime);
    header('Content-Disposition: inline; filename="' . $documentFilename . '"');
    header('Content-Length: ' . filesize($documentPath));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($documentPath);
    exit;
}

$toastMessage = "";
$toastType = "success";

/** Save an uploaded file and return its public relative path. */
function saveUploadedFile($fieldName, $folder, array $allowedTypes, &$errorMessage) {
    $errorMessage = '';
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        $errorMessage = 'Please choose a file to upload.';
        return '';
    }

    $file = $_FILES[$fieldName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessage = 'The selected file could not be uploaded. Please try again.';
        return '';
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        $errorMessage = 'The file must be 5 MB or smaller.';
        return '';
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($allowedTypes[$mimeType])) {
        $errorMessage = 'Please select a valid image or PDF file.';
        return '';
    }

    $uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $folder;
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        $errorMessage = 'Upload folder is unavailable. Please contact the administrator.';
        return '';
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    if (!move_uploaded_file($file['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) {
        $errorMessage = 'Unable to save the uploaded file. Please try again.';
        return '';
    }

    return 'uploads/' . $folder . '/' . $fileName;
}

/** Store a named, actionable notification for any dashboard role. */
function createDashboardNotification($db, $receiverEmail, $type, $message) {
    $cleanEmail = trim($receiverEmail);
    $receiverEmail = $db->real_escape_string($cleanEmail);
    $type = $db->real_escape_string($type);
    $message = $db->real_escape_string($message);
    if ($receiverEmail !== '' && $message !== '') {
        $db->query("INSERT INTO notifications (receiver_email, type, message) VALUES ('$receiverEmail', '$type', '$message')");
        // Also email the recipient so they do not have to log in just to check alerts.
        send_notification_email($cleanEmail, 'Practicore notification', $message . "\r\n\r\nOpen your Practicore dashboard to respond.");
    }
}

function notifyCampusAdmins($db, $schoolFilter, $type, $message) {
    $adminQuery = $db->query("SELECT email FROM admins WHERE LOWER(TRIM(school))='$schoolFilter'");
    if ($adminQuery) while ($admin = $adminQuery->fetch_assoc()) {
        createDashboardNotification($db, $admin['email'], $type, $message);
    }
}

$imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$documentTypes = $imageTypes + ['application/pdf' => 'pdf'];

// =========================================================================
// CONTROLLER: Handle All Real-Time Database Actions
// =========================================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $db) {
    $action = $_POST['action'];

    // 1. UPDATE PROFILE (Avatar & Bio)
    if ($action === 'update_profile') {
        $pName = $db->real_escape_string(trim($_POST['name'] ?? $name));
        $pBio = $db->real_escape_string(trim($_POST['bio'] ?? ''));
        $pHeadline = $db->real_escape_string(trim($_POST['headline'] ?? ''));
        $pPhone = $db->real_escape_string(trim($_POST['phone'] ?? ''));
        $pLocation = $db->real_escape_string(trim($_POST['location'] ?? ''));
        $profileCourse = $course;
        $profileInstitution = $institution;
        $profileCampus = $campus;
        $profileSchool = html_entity_decode($school, ENT_QUOTES, 'UTF-8');
        if ($role === 'student') {
            $profileInstitution = preg_replace('/\s+/', ' ', trim($_POST['institution'] ?? ''));
            $profileCampus = trim($_POST['campus'] ?? '');
            $profileCourse = trim($_POST['course'] ?? '');
            if ($profileInstitution === '' || !in_array($profileCampus, practicore_campuses(), true) || $profileCourse === '') {
                $toastMessage = "Please provide a valid school, campus, and course.";
                $toastType = "warning";
                header("Location: dashboard.php?msg=" . urlencode($toastMessage));
                exit();
            }
            $profileSchool = $profileInstitution . ' — ' . $profileCampus;
        }
        $uploadError = '';
        $newAvatar = '';
        if (isset($_FILES['avatar_file']) && $_FILES['avatar_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $newAvatar = saveUploadedFile('avatar_file', 'avatars', $imageTypes, $uploadError);
        }
        $pAvatar = $newAvatar ? $db->real_escape_string($newAvatar) : $db->real_escape_string($avatar);
        
        if ($role === 'student') {
            $profileCourseRaw = $profileCourse;
            $profileInstitutionRaw = $profileInstitution;
            $profileCampusRaw = $profileCampus;
            $profileSchoolRaw = $profileSchool;
            $safeProfileCourse = $db->real_escape_string($profileCourseRaw);
            $safeProfileInstitution = $db->real_escape_string($profileInstitutionRaw);
            $safeProfileCampus = $db->real_escape_string($profileCampusRaw);
            $safeProfileSchool = $db->real_escape_string($profileSchoolRaw);
            $db->query("UPDATE `$accountTable` SET name='$pName', bio='$pBio', headline='$pHeadline', phone='$pPhone', location='$pLocation', avatar='$pAvatar', course='$safeProfileCourse', institution='$safeProfileInstitution', campus='$safeProfileCampus', school='$safeProfileSchool' WHERE email='$email'");
            $_SESSION['school'] = $profileSchoolRaw;
            $_SESSION['institution'] = $profileInstitutionRaw;
            $_SESSION['campus'] = $profileCampusRaw;
            $_SESSION['course'] = $profileCourseRaw;
        } else {
            $db->query("UPDATE `$accountTable` SET name='$pName', bio='$pBio', headline='$pHeadline', phone='$pPhone', location='$pLocation', avatar='$pAvatar' WHERE email='$email'");
        }
        $_SESSION['name'] = $pName;
        practicore_log_audit($db, $email, $role, 'profile_updated', $email);
        $toastMessage = $uploadError && !$newAvatar ? "Profile updated, but $uploadError" : "Profile updated successfully!";
        header("Location: dashboard.php?msg=" . urlencode($toastMessage));
        exit();
    }

    if ($action === 'upload_student_document' && $role === 'student') {
        $documentLabel = trim($_POST['document_label'] ?? '');
        $allowedLabels = ['Resume / CV', 'Recommendation Letter', 'Application Form', 'Other Requirement'];
        $documentError = '';
        if (!in_array($documentLabel, $allowedLabels, true)) {
            $toastMessage = 'Select a valid document type.';
            $toastType = 'warning';
        } else {
            $storedPath = saveUploadedFile('student_document', 'student-documents', $documentTypes, $documentError);
            if ($storedPath !== '') {
                $safeLabel = $db->real_escape_string($documentLabel);
                $safePath = $db->real_escape_string($storedPath);
                $db->query("INSERT INTO student_documents (student_email, label, file_path) VALUES ('$email', '$safeLabel', '$safePath')");
                practicore_log_audit($db, $email, $role, 'student_document_uploaded', $documentLabel);
                $toastMessage = "$documentLabel uploaded to your document library.";
            } else {
                $toastMessage = $documentError;
                $toastType = 'warning';
            }
        }
    }

    if ($action === 'delete_student_document' && $role === 'student') {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $documentQ = $db->query("SELECT file_path, label FROM student_documents WHERE id=$documentId AND student_email='$email' LIMIT 1");
        $document = $documentQ ? $documentQ->fetch_assoc() : null;
        if ($document) {
            $attachedDocumentQ = $db->query("SELECT 1 FROM application_documents WHERE student_document_id=$documentId LIMIT 1");
            if ($attachedDocumentQ && $attachedDocumentQ->num_rows > 0) {
                $toastMessage = 'This document is attached to an application and cannot be removed from your library.';
                $toastType = 'warning';
                $document = null;
            }
        }
        if ($document) {
            $safePath = $db->real_escape_string($document['file_path']);
            if ($db->query("DELETE FROM student_documents WHERE id=$documentId AND student_email='$email'")) {
                $resolvedUpload = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $document['file_path']));
                $uploadRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'student-documents');
                if ($resolvedUpload && $uploadRoot && strpos($resolvedUpload, $uploadRoot . DIRECTORY_SEPARATOR) === 0 && is_file($resolvedUpload)) {
                    unlink($resolvedUpload);
                }
                practicore_log_audit($db, $email, $role, 'student_document_deleted', $document['label']);
                $toastMessage = 'Document removed from your library.';
            }
        }
    }

    if ($action === 'toggle_favorite_company' && $role === 'student') {
        $companyEmail = $db->real_escape_string(trim($_POST['company_email'] ?? ''));
        $existingFavoriteQ = $db->query("SELECT id FROM favorite_companies WHERE student_email='$email' AND company_email='$companyEmail' LIMIT 1");
        if ($existingFavoriteQ && $existingFavoriteQ->num_rows > 0) {
            $db->query("DELETE FROM favorite_companies WHERE student_email='$email' AND company_email='$companyEmail'");
            $toastMessage = 'Removed affiliated company from favorites.';
        } else {
            $companyQ = $db->query("SELECT name, school, institution, campus FROM companies WHERE email='$companyEmail' AND approval_status='Approved' AND broadcast_to_students=1 LIMIT 1");
            $favoriteCompany = $companyQ ? $companyQ->fetch_assoc() : null;
            if ($favoriteCompany) {
            $studentInstitutionKey = practicore_institution_key($institution, html_entity_decode($school, ENT_QUOTES, 'UTF-8'));
            $studentCampus = practicore_account_campus($campus, html_entity_decode($school, ENT_QUOTES, 'UTF-8'));
            $companyInstitutionKey = practicore_institution_key($favoriteCompany['institution'] ?? '', $favoriteCompany['school'] ?? '');
            $companyCampus = practicore_account_campus($favoriteCompany['campus'] ?? '', $favoriteCompany['school'] ?? '');
            if (practicore_company_visible_to_student($studentInstitutionKey, $studentCampus, $companyInstitutionKey, $companyCampus)) {
                    $companyName = $db->real_escape_string($favoriteCompany['name']);
                    $db->query("INSERT IGNORE INTO favorite_companies (student_email, company_email, company_name) VALUES ('$email', '$companyEmail', '$companyName')");
                    $toastMessage = 'Affiliated company saved to favorites.';
            } else {
                $toastMessage = 'This company is not available to your school or campus.';
                $toastType = 'warning';
            }
            }
        }
    }

    // 2. COMPANY: Post new OJT Opening with custom required documents
    if ($action === 'post_job' && $role === 'company' && $companyApprovalStatus === 'Approved') {
        $jTitle = $db->real_escape_string(trim($_POST['title'] ?? ''));
        $jDescription = $db->real_escape_string(trim($_POST['description'] ?? ''));
        $jSchool = $db->real_escape_string(trim($_POST['school'] ?? $school));
        $jCourse = $db->real_escape_string(trim($_POST['course'] ?? 'BSIT'));
        $jAllowance = $db->real_escape_string(trim($_POST['allowance'] ?? 'Unpaid'));
        $jSlots = (int)($_POST['slots'] ?? 1);
        $jSkills = $db->real_escape_string(trim($_POST['skills'] ?? ''));
        $jDocs = $db->real_escape_string(trim($_POST['required_documents'] ?? 'Resume / CV, Parents Consent & Waiver'));
        $jDeadline = trim($_POST['deadline'] ?? '');
        $deadlineSql = $jDeadline !== '' ? "'" . $db->real_escape_string($jDeadline) . "'" : "NULL";
        $deadlineDate = $jDeadline !== '' ? DateTime::createFromFormat('Y-m-d', $jDeadline) : false;
        $deadlineValid = $jDeadline === '' || ($deadlineDate && $deadlineDate->format('Y-m-d') === $jDeadline && $jDeadline >= date('Y-m-d'));

        if (!$deadlineValid) {
            $toastMessage = 'Please choose a valid deadline today or later.';
            $toastType = 'warning';
        } elseif (!empty($jTitle)) {
            $db->query("INSERT INTO jobs (company_name, company_email, title, description, school, course, allowance, slots, skills, required_documents, deadline) 
                        VALUES ('$name', '$email', '$jTitle', '$jDescription', '$jSchool', '$jCourse', '$jAllowance', $jSlots, '$jSkills', '$jDocs', $deadlineSql)");
            practicore_log_audit($db, $email, $role, 'job_posted', $jTitle . ' | deadline=' . ($jDeadline ?: 'none'));
            $toastMessage = "OJT Opening \"$jTitle\" successfully published with your required attachments!";
        }
    }

    if ($action === 'update_application_status' && $role === 'company' && $companyApprovalStatus === 'Approved') {
        $applicationId = (int)($_POST['application_id'] ?? 0);
        $newApplicationStatus = trim($_POST['application_status'] ?? '');
        $validStatuses = ['Shortlisted', 'Accepted & Endorsed', 'Rejected'];
        if ($applicationId > 0 && in_array($newApplicationStatus, $validStatuses, true)) {
            $safeCurrentCompanyName = $db->real_escape_string(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
            $applicationQ = $db->query("SELECT student_email, student_name, company_name, job_title, status FROM applications WHERE id=$applicationId AND (company_email='$email' OR (company_email='' AND company_name='$safeCurrentCompanyName')) LIMIT 1");
            $application = $applicationQ ? $applicationQ->fetch_assoc() : null;
            if ($application) {
                if (in_array($application['status'], ['Accepted & Endorsed', 'Rejected'], true)) {
                    $toastMessage = 'This application has a final decision and can no longer be changed.';
                    $toastType = 'warning';
                } else {
                    $safeStatus = $db->real_escape_string($newApplicationStatus);
                    $updated = $db->query("UPDATE applications SET status='$safeStatus' WHERE id=$applicationId AND (company_email='$email' OR (company_email='' AND company_name='$safeCurrentCompanyName')) AND status NOT IN ('Accepted & Endorsed', 'Rejected')");
                    if ($updated && $db->affected_rows === 1) {
                        $notice = $application['company_name'] . " updated your application for " . $application['job_title'] . " to: " . $newApplicationStatus . ".";
                        createDashboardNotification($db, $application['student_email'], 'application', $notice);
                        practicore_log_audit($db, $email, $role, 'application_status_changed', $application['student_email'] . ' | ' . $application['job_title'] . ' | ' . $newApplicationStatus);
                        $toastMessage = "Applicant status updated to $newApplicationStatus.";
                    } else {
                        $toastMessage = 'Unable to update this application status. Please try again.';
                        $toastType = 'warning';
                    }
                }
            } else {
                $toastMessage = "Applicant record not found for this company.";
                $toastType = "warning";
            }
        } else {
            $toastMessage = "Invalid applicant status update.";
            $toastType = "warning";
        }
    }

    // 3. STUDENT: Apply to Company with file attachment OR link & pitch notes
    if ($action === 'apply_job' && $role === 'student') {
        $jobId = (int)($_POST['job_id'] ?? 0);
        $jobTitle = $db->real_escape_string(trim($_POST['job_title'] ?? ''));
        $compName = $db->real_escape_string(trim($_POST['company_name'] ?? ''));
        $compEmail = $db->real_escape_string(trim($_POST['company_email'] ?? ''));
        $notes = $db->real_escape_string(trim($_POST['student_notes'] ?? ''));
        $attachmentMode = $_POST['attachment_mode'] ?? 'file'; // 'file' or 'link'
        $attachmentLink = $db->real_escape_string(trim($_POST['attachment_link'] ?? ''));
        $validBroadcastTarget = true;

        if ($jobId <= 0) {
            $targetQ = $db->query("SELECT name, email, school, institution, campus FROM companies WHERE email='$compEmail' AND approval_status='Approved' AND broadcast_to_students=1 LIMIT 1");
            $targetCompany = $targetQ ? $targetQ->fetch_assoc() : null;
            $studentInstitutionKey = practicore_institution_key($institution, html_entity_decode($school, ENT_QUOTES, 'UTF-8'));
            $studentCampus = practicore_account_campus($campus, html_entity_decode($school, ENT_QUOTES, 'UTF-8'));
            $companyInstitutionKey = $targetCompany ? practicore_institution_key($targetCompany['institution'] ?? '', $targetCompany['school'] ?? '') : '';
            $companyCampus = $targetCompany ? practicore_account_campus($targetCompany['campus'] ?? '', $targetCompany['school'] ?? '') : '';
            $validBroadcastTarget = $targetCompany
                && practicore_company_visible_to_student($studentInstitutionKey, $studentCampus, $companyInstitutionKey, $companyCampus);

            if ($validBroadcastTarget) {
                $compName = $db->real_escape_string($targetCompany['name']);
                $compEmail = $db->real_escape_string($targetCompany['email']);
                $jobTitle = $db->real_escape_string('General OJT Application');
                $jobId = 0;
            }
        } else {
            $jobQ = $db->query("SELECT j.id, j.title, j.company_name, j.company_email, j.school, j.course FROM jobs j INNER JOIN companies c ON c.email=j.company_email AND c.approval_status='Approved' WHERE j.id=$jobId AND (j.deadline IS NULL OR j.deadline >= CURRENT_DATE()) LIMIT 1");
            $postedJob = $jobQ ? $jobQ->fetch_assoc() : null;
            $studentSchoolRaw = html_entity_decode($school, ENT_QUOTES, 'UTF-8');
            $jobSchoolMatches = $postedJob && strtolower(trim($postedJob['school'])) === strtolower(trim($studentSchoolRaw));
            $jobCourseMatches = $postedJob && (
                strcasecmp(trim($postedJob['course']), trim($course)) === 0
                || in_array(strtolower(trim($postedJob['course'])), ['all tech courses', 'all courses'], true)
            );
            $validBroadcastTarget = $postedJob && $jobSchoolMatches && $jobCourseMatches;
            if ($validBroadcastTarget) {
                $jobId = (int)$postedJob['id'];
                $jobTitle = $db->real_escape_string($postedJob['title']);
                $compName = $db->real_escape_string($postedJob['company_name']);
                $compEmail = $db->real_escape_string($postedJob['company_email']);
            }
        }

        $selectedDocumentIds = [];
        $invalidSelectedDocument = false;
        $requestedDocumentIds = $_POST['student_document_ids'] ?? [];
        if (!is_array($requestedDocumentIds)) {
            $requestedDocumentIds = [];
            $invalidSelectedDocument = true;
        }
        foreach (array_unique(array_map('intval', $requestedDocumentIds)) as $requestedDocumentId) {
            if ($requestedDocumentId <= 0) {
                $invalidSelectedDocument = true;
                break;
            }
            $ownedDocumentQuery = $db->prepare("SELECT id FROM student_documents WHERE id=? AND student_email=? LIMIT 1");
            if (!$ownedDocumentQuery) {
                $invalidSelectedDocument = true;
                break;
            }
            $ownedDocumentQuery->bind_param('is', $requestedDocumentId, $email);
            $ownedDocumentQuery->execute();
            $isOwnedDocument = $ownedDocumentQuery->get_result()->num_rows > 0;
            $ownedDocumentQuery->close();
            if (!$isOwnedDocument) {
                $invalidSelectedDocument = true;
                break;
            }
            $selectedDocumentIds[] = $requestedDocumentId;
        }

        $uploadError = '';
        $attachName = '';

        if ($validBroadcastTarget && $attachmentMode === 'link') {
            // Using a link
            if (!empty($attachmentLink)) {
                $attachName = $attachmentLink;
            }
            // Link is optional — no error if empty
        } elseif ($validBroadcastTarget) {
            // File upload mode
            if (isset($_FILES['application_file']) && $_FILES['application_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadedAttachment = saveUploadedFile('application_file', 'applications', $documentTypes, $uploadError);
                $attachName = $db->real_escape_string($uploadedAttachment);
            }
            // File is optional — no error if not provided
        }

        $check = $db->query("SELECT id FROM applications WHERE student_email='$email' AND job_title='$jobTitle' AND company_name='$compName'");
        if (!$validBroadcastTarget) {
            $toastMessage = "This opening is no longer available or does not match your school and course.";
            $toastType = "warning";
        } elseif ($invalidSelectedDocument) {
            $toastMessage = "One or more selected library documents are unavailable. Please refresh and try again.";
            $toastType = "warning";
        } elseif ($uploadError) {
            $toastMessage = $uploadError;
            $toastType = "warning";
        } elseif ($check && $check->num_rows > 0) {
            $toastMessage = "You have already submitted an application for this position.";
            $toastType = "warning";
        } else {
            $db->begin_transaction();
            $applicationSaved = $db->query("INSERT INTO applications (job_id, student_name, student_email, student_school, student_course, company_name, company_email, job_title, status, attachment_name, student_notes) 
                        VALUES ($jobId, '$name', '$email', '$school', '$course', '$compName', '$compEmail', '$jobTitle', 'Pending Review', '$attachName', '$notes')");
            $applicationId = $applicationSaved ? (int)$db->insert_id : 0;
            $documentLinksSaved = $applicationSaved;
            if ($documentLinksSaved && $selectedDocumentIds) {
                $documentLinkStatement = $db->prepare("INSERT INTO application_documents (application_id, student_document_id) VALUES (?, ?)");
                if (!$documentLinkStatement) {
                    $documentLinksSaved = false;
                } else {
                    foreach ($selectedDocumentIds as $selectedDocumentId) {
                        $documentLinkStatement->bind_param('ii', $applicationId, $selectedDocumentId);
                        if (!$documentLinkStatement->execute()) {
                            $documentLinksSaved = false;
                            break;
                        }
                    }
                    $documentLinkStatement->close();
                }
            }
            if (!$documentLinksSaved) {
                $db->rollback();
                $toastMessage = 'Unable to save the application and its selected documents. Please try again.';
                $toastType = 'warning';
            } else {
                $db->commit();
                practicore_log_audit($db, $email, $role, 'application_submitted', $compName . ' | ' . $jobTitle);
                createDashboardNotification($db, $compEmail, 'application', html_entity_decode($name, ENT_QUOTES, 'UTF-8') . " submitted an application for $jobTitle.");
                $hasAttachments = $attachName !== '' || count($selectedDocumentIds) > 0;
                $toastMessage = "Application submitted to $compName!" . ($hasAttachments ? " Your selected attachments were included." : "");
            }
        }
    }

    // 4. STUDENT: Log Daily OJT Hours with Company Selection
    if ($action === 'log_dtr' && $role === 'student') {
        $lDate = $db->real_escape_string($_POST['log_date'] ?? date('Y-m-d'));
        $lHours = (float)($_POST['hours'] ?? 0);
        $lTask = $db->real_escape_string(trim($_POST['task_description'] ?? ''));
        $lCompany = $db->real_escape_string(trim($_POST['company_name'] ?? 'Host Company'));

        if ($lHours > 0 && !empty($lTask)) {
            $db->query("INSERT INTO dtr_logs (student_email, student_name, company_name, log_date, hours, task_description, status) 
                        VALUES ('$email', '$name', '$lCompany', '$lDate', $lHours, '$lTask', 'Pending Supervisor Sign')");
            practicore_log_audit($db, $email, $role, 'dtr_logged', $lCompany . ' | ' . $lHours . ' hours');
            $toastMessage = "Logged {$lHours} hours for $lDate at $lCompany. Pending company supervisor sign.";
        }
    }

    // 5. COMPANY: Review & Sign Trainee DTR with supervisor comment & name
    if ($action === 'sign_dtr' && $role === 'company' && $companyApprovalStatus === 'Approved') {
        $dtrId = (int)($_POST['dtr_id'] ?? 0);
        $sSupervisor = $db->real_escape_string(trim($_POST['supervisor_name'] ?? $name));
        $sComment = $db->real_escape_string(trim($_POST['supervisor_comment'] ?? 'Signed & Approved'));
        $requestedDtrStatus = $_POST['status'] ?? 'Approved by Supervisor';
        $sStatus = in_array($requestedDtrStatus, ['Approved by Supervisor', 'Revision Requested'], true)
            ? $db->real_escape_string($requestedDtrStatus)
            : 'Approved by Supervisor';

        $fullComment = !empty($sSupervisor) ? "[$sSupervisor]: $sComment" : $sComment;
        $safeCompanyName = $db->real_escape_string(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
        if ($db->query("UPDATE dtr_logs SET status='$sStatus', supervisor_comment='$fullComment' WHERE id=$dtrId AND company_name='$safeCompanyName'")) {
            practicore_log_audit($db, $email, $role, 'dtr_reviewed', 'DTR ' . $dtrId . ' | ' . $sStatus);
            $toastMessage = "Trainee DTR entry signed and supervisor feedback comment posted!";
        } else {
            $toastMessage = 'Unable to update this DTR entry.';
            $toastType = 'warning';
        }
    }

    // 6. STUDENT: Interactive Clearance Submission (file OR link)
    if ($action === 'submit_clearance_item' && $role === 'student') {
        $cType = $_POST['clearance_type'] ?? '';
        $clearanceMode = $_POST['clearance_mode'] ?? 'file'; // 'file' or 'link'
        $clearanceLink = $db->real_escape_string(trim($_POST['clearance_link'] ?? ''));
        $uploadError = '';
        $cFileName = '';

        if ($clearanceMode === 'link') {
            $cFileName = $clearanceLink;
        } else {
            if (isset($_FILES['clearance_file']) && $_FILES['clearance_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploadedClearance = saveUploadedFile('clearance_file', 'clearances', $documentTypes, $uploadError);
                $cFileName = $db->real_escape_string($uploadedClearance);
            }
        }

        if ($uploadError) {
            $toastMessage = $uploadError;
            $toastType = "warning";
        } elseif (empty($cFileName)) {
            $toastMessage = 'Please upload a file or provide a link.';
            $toastType = "warning";
        } else {

        // Ensure record exists
        $cCheck = $db->query("SELECT id FROM clearances WHERE student_email='$email'");
        if (!$cCheck || $cCheck->num_rows === 0) {
            $db->query("INSERT INTO clearances (student_email, student_name, student_school, student_course) VALUES ('$email', '$name', '$school', '$course')");
        }

        $clearanceLabels = ['moa'=>'MoA', 'endorsement'=>'Endorsement Letter', 'waiver'=>"Parents' Waiver", 'medical'=>'Medical Clearance'];
        if ($cType === 'moa') {
            $db->query("UPDATE clearances SET moa_status='Submitted', moa_file='$cFileName' WHERE student_email='$email'");
            $toastMessage = "Signed Memorandum of Agreement (MoA) submitted for coordinator review.";
        } elseif ($cType === 'endorsement') {
            $db->query("UPDATE clearances SET endorsement_status='Submitted', endorsement_file='$cFileName' WHERE student_email='$email'");
            $toastMessage = "Endorsement Letter request submitted to OJT coordinator.";
        } elseif ($cType === 'waiver') {
            $db->query("UPDATE clearances SET waiver_status='Submitted', waiver_file='$cFileName' WHERE student_email='$email'");
            $toastMessage = "Notarized Parents' Consent & Waiver submitted.";
        } elseif ($cType === 'medical') {
            $db->query("UPDATE clearances SET medical_status='Submitted', medical_file='$cFileName' WHERE student_email='$email'");
            $toastMessage = "Medical & Drug Test clearance submitted.";
        }
        if (isset($clearanceLabels[$cType])) {
            notifyCampusAdmins($db, $schoolFilter, 'document', html_entity_decode($name, ENT_QUOTES, 'UTF-8') . ' submitted a ' . $clearanceLabels[$cType] . ' for review.');
        }
        }
    }

    // 6b. STUDENT: Remove one uploaded clearance document so it can be submitted again.
    if ($action === 'remove_clearance_item' && $role === 'student') {
        $cType = $_POST['clearance_type'] ?? '';
        $clearanceFields = ['moa'=>['moa_status','moa_file'], 'endorsement'=>['endorsement_status','endorsement_file'], 'waiver'=>['waiver_status','waiver_file'], 'medical'=>['medical_status','medical_file']];
        if (isset($clearanceFields[$cType])) {
            [$statusField, $fileField] = $clearanceFields[$cType];
            $db->query("UPDATE clearances SET `$statusField`='Pending', `$fileField`='' WHERE student_email='$email'");
            $toastMessage = 'Uploaded document removed. You may submit a new file.';
        }
    }

    // 6c. STUDENT: Navigate a school module. Completion is automatic only on its final PDF page.
    if ($action === 'update_module_progress' && $role === 'student') {
        $moduleId = (int)($_POST['module_id'] ?? 0);
        $direction = $_POST['direction'] ?? 'next';
        $reportedPages = max(0, (int)($_POST['pages_required'] ?? 0));
        $moduleQ = $db->query("SELECT id, title, hours, admin_email FROM modules WHERE id=$moduleId AND LOWER(TRIM(school))='$schoolFilter' LIMIT 1");
        if ($moduleQ && $module = $moduleQ->fetch_assoc()) {
            $progressQ = $db->query("SELECT current_page, pages_required, hours_earned, is_completed FROM module_progress WHERE module_id=$moduleId AND student_email='$email' LIMIT 1");
            $progress = $progressQ && $progressQ->num_rows ? $progressQ->fetch_assoc() : ['current_page'=>1, 'pages_required'=>0, 'hours_earned'=>0, 'is_completed'=>0];
            $requiredPages = (int)$progress['pages_required'] ?: $reportedPages;
            $page = max(1, (int)$progress['current_page'] + ($direction === 'previous' ? -1 : 1));
            if ($requiredPages > 0) $page = min($page, $requiredPages);
            $earned = min((int)$module['hours'], (int)$progress['hours_earned'] + ($direction === 'next' ? 1 : 0));
            $completedNow = $requiredPages > 0 && $page >= $requiredPages && $direction === 'next' && !(int)$progress['is_completed'];
            $completedSql = $completedNow ? ', is_completed=1, completed_at=NOW()' : '';
            $db->query("INSERT INTO module_progress (module_id, student_email, current_page, pages_required, hours_earned, is_completed, completed_at) VALUES ($moduleId, '$email', $page, $requiredPages, $earned, " . ($completedNow ? '1, NOW()' : '0, NULL') . ") ON DUPLICATE KEY UPDATE current_page=$page, pages_required=IF(pages_required=0, $requiredPages, pages_required), hours_earned=$earned$completedSql");
            if ($completedNow) {
                createDashboardNotification($db, $module['admin_email'], 'module', html_entity_decode($name, ENT_QUOTES, 'UTF-8') . ' completed the module: ' . $module['title'] . '.');
                $toastMessage = 'Final page reviewed! Module complete — achievement earned.';
            } else {
                $toastMessage = $direction === 'next' ? "Study page reviewed. Module progress: $earned / {$module['hours']} hours." : 'Moved to the previous study page.';
            }
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok'=>true, 'page'=>$page, 'pages'=>$requiredPages, 'hours'=>$earned, 'completed'=>$completedNow, 'message'=>$toastMessage]);
                exit();
            }
        }
    }

    // 7. ADMIN: Editable & Touchable Clearance Status Manager
    if ($action === 'update_student_clearance' && $role === 'admin') {
        $sEmail = $db->real_escape_string($_POST['student_email'] ?? '');
        $moaSt = $db->real_escape_string($_POST['moa_status'] ?? 'Pending');
        $endSt = $db->real_escape_string($_POST['endorsement_status'] ?? 'Pending');
        $waivSt = $db->real_escape_string($_POST['waiver_status'] ?? 'Pending');
        $medSt = $db->real_escape_string($_POST['medical_status'] ?? 'Pending');

        $isComplete = ($moaSt === 'Verified' && $endSt === 'Verified' && $waivSt === 'Verified' && $medSt === 'Verified') ? 1 : 0;

        // Ensure record exists
        $cCheck = $db->query("SELECT id FROM clearances WHERE student_email='$sEmail'");
        if ($cCheck && $cCheck->num_rows > 0) {
            $db->query("UPDATE clearances SET moa_status='$moaSt', endorsement_status='$endSt', waiver_status='$waivSt', medical_status='$medSt', is_completed=$isComplete WHERE student_email='$sEmail'");
        } else {
            $sName = $db->real_escape_string($_POST['student_name'] ?? 'Student');
            $db->query("INSERT INTO clearances (student_email, student_name, student_school, student_course, moa_status, endorsement_status, waiver_status, medical_status, is_completed) 
                        VALUES ('$sEmail', '$sName', '$school', '$course', '$moaSt', '$endSt', '$waivSt', '$medSt', $isComplete)");
        }
        $toastMessage = "Clearance updated for $sEmail. Complete status: " . ($isComplete ? 'Officially Enrolled & Cleared!' : 'In Progress');
    }

    // 7b. ADMIN: Accept a single clearance item (per-document approval)
    if ($action === 'accept_clearance_item' && $role === 'admin') {
        $sEmail = $db->real_escape_string($_POST['student_email'] ?? '');
        $cField = $db->real_escape_string($_POST['clearance_field'] ?? ''); // moa_status, endorsement_status, etc.
        $acceptValues = [
            'moa_status'          => 'Verified',
            'endorsement_status'  => 'Verified',
            'waiver_status'       => 'Verified',
            'medical_status'      => 'Verified',
        ];
        if (isset($acceptValues[$cField])) {
            $newVal = $acceptValues[$cField];
            $db->query("UPDATE clearances SET `$cField`='$newVal' WHERE student_email='$sEmail'");
            // Check if all done
            $chk = $db->query("SELECT moa_status, endorsement_status, waiver_status, medical_status FROM clearances WHERE student_email='$sEmail'");
            if ($chk && $row = $chk->fetch_assoc()) {
                $allDone = ($row['moa_status']==='Verified' && $row['endorsement_status']==='Verified' && $row['waiver_status']==='Verified' && $row['medical_status']==='Verified') ? 1 : 0;
                $db->query("UPDATE clearances SET is_completed=$allDone WHERE student_email='$sEmail'");
            }
            $documentLabels = ['moa_status'=>'MoA', 'endorsement_status'=>'Endorsement Letter', 'waiver_status'=>"Parents' Waiver", 'medical_status'=>'Medical Clearance'];
            $noticeText = 'Your ' . $documentLabels[$cField] . ' was accepted by your coordinator.';
            createDashboardNotification($db, $sEmail, 'clearance', $noticeText);
            $toastMessage = "Document accepted for $sEmail.";
        }
    }

    // 7c. ADMIN: Decline / reset a single clearance item
    if ($action === 'decline_clearance_item' && $role === 'admin') {
        $sEmail = $db->real_escape_string($_POST['student_email'] ?? '');
        $cField = $db->real_escape_string($_POST['clearance_field'] ?? '');
        $declineFields = ['moa_status','endorsement_status','waiver_status','medical_status'];
        if (in_array($cField, $declineFields)) {
            $db->query("UPDATE clearances SET `$cField`='Declined', is_completed=0 WHERE student_email='$sEmail'");
            $documentLabels = ['moa_status'=>'MoA', 'endorsement_status'=>'Endorsement Letter', 'waiver_status'=>"Parents' Waiver", 'medical_status'=>'Medical Clearance'];
            $noticeText = 'Your ' . $documentLabels[$cField] . ' was declined. Please review and resubmit your clearance document.';
            createDashboardNotification($db, $sEmail, 'clearance', $noticeText);
            $toastMessage = "Document declined for $sEmail. Student will be notified to resubmit.";
        }
    }

    // 8. ADMIN: Post New Training / Guide Module (file upload OR link, link is optional)
    if ($action === 'post_module' && $role === 'admin') {
        $mTitle = $db->real_escape_string(trim($_POST['title'] ?? ''));
        $mCourse = $db->real_escape_string(trim($_POST['course'] ?? 'BSIT'));
        $mHours = (int)($_POST['hours'] ?? 10);
        $mLevel = $db->real_escape_string(trim($_POST['level'] ?? 'All Levels'));
        $mDesc = $db->real_escape_string(trim($_POST['description'] ?? ''));
        $mModuleMode = $_POST['module_guide_mode'] ?? 'link'; // 'file' or 'link'
        $mLink = $db->real_escape_string(trim($_POST['guide_link'] ?? ''));
        $mFile = '';

        // Ensure guide_file column exists
        @$db->query("ALTER TABLE modules ADD COLUMN IF NOT EXISTS guide_file VARCHAR(255) DEFAULT ''");

        if ($mModuleMode === 'file') {
            $uploadErr = '';
            $uploaded = saveUploadedFile('guide_file_upload', 'modules', $documentTypes, $uploadErr);
            if ($uploaded) $mFile = $db->real_escape_string($uploaded);
        }

        if (!empty($mTitle)) {
            $db->query("INSERT INTO modules (admin_name, admin_email, school, course, title, hours, level, description, guide_link, guide_file) 
                        VALUES ('$name', '$email', '$school', '$mCourse', '$mTitle', $mHours, '$mLevel', '$mDesc', '$mLink', '$mFile')");
            $toastMessage = "Guide / Study Module \"$mTitle\" published for $school students!";
        }
    }

    // 9. ADMIN: Edit Existing Training Module
    if ($action === 'edit_module' && $role === 'admin') {
        $mId = (int)($_POST['module_id'] ?? 0);
        $mTitle = $db->real_escape_string(trim($_POST['title'] ?? ''));
        $mCourse = $db->real_escape_string(trim($_POST['course'] ?? 'BSIT'));
        $mHours = (int)($_POST['hours'] ?? 10);
        $mLevel = $db->real_escape_string(trim($_POST['level'] ?? 'All Levels'));
        $mDesc = $db->real_escape_string(trim($_POST['description'] ?? ''));
        $mModuleMode = $_POST['module_guide_mode'] ?? 'link';
        $mLink = $db->real_escape_string(trim($_POST['guide_link'] ?? ''));

        // Ensure guide_file column exists
        @$db->query("ALTER TABLE modules ADD COLUMN IF NOT EXISTS guide_file VARCHAR(255) DEFAULT ''");

        // Fetch existing file path to keep if no new file uploaded
        $existingFile = '';
        $existingQ = $db->query("SELECT guide_file FROM modules WHERE id=$mId");
        if ($existingQ && $existingQ->num_rows > 0) {
            $existingFile = $existingQ->fetch_assoc()['guide_file'] ?? '';
        }
        $mFile = $db->real_escape_string($existingFile);

        if ($mModuleMode === 'file') {
            $uploadErr = '';
            if (isset($_FILES['guide_file_upload']) && $_FILES['guide_file_upload']['error'] !== UPLOAD_ERR_NO_FILE) {
                $uploaded = saveUploadedFile('guide_file_upload', 'modules', $documentTypes, $uploadErr);
                if ($uploaded) $mFile = $db->real_escape_string($uploaded);
            }
            $mLink = ''; // clear link when using file
        } else {
            $mFile = ''; // clear file when using link
        }

        $db->query("UPDATE modules SET title='$mTitle', course='$mCourse', hours=$mHours, level='$mLevel', description='$mDesc', guide_link='$mLink', guide_file='$mFile' WHERE id=$mId");
        $toastMessage = "Training Module \"$mTitle\" updated successfully!";
    }

    // 10. ADMIN: Delete Training Module
    if ($action === 'delete_module' && $role === 'admin') {
        $mId = (int)($_POST['module_id'] ?? 0);
        $db->query("DELETE FROM modules WHERE id=$mId");
        $toastMessage = "Module deleted.";
    }

    // 10b. COMPANY / ADMIN: Create or update a searchable OJT offer.
    if ($action === 'save_offer' && in_array($role, ['admin', 'company'], true) && ($role !== 'company' || $companyApprovalStatus === 'Approved')) {
        $offerId = (int)($_POST['offer_id'] ?? 0);
        $oTitle = $db->real_escape_string(trim($_POST['title'] ?? ''));
        $oDesc = $db->real_escape_string(trim($_POST['description'] ?? ''));
        $oCompany = $db->real_escape_string(trim($_POST['company_name'] ?? ($role === 'company' ? html_entity_decode($name, ENT_QUOTES, 'UTF-8') : '')));
        $oCourse = $db->real_escape_string(trim($_POST['course'] ?? 'BSIT'));
        $oSlots = max(1, (int)($_POST['slots'] ?? 1));
        // Company offers await coordinator approval; a coordinator's own offers are approved instantly.
        $oStatus = $role === 'admin' ? 'Approved' : 'Pending';

        if ($oTitle !== '' && $oCompany !== '') {
            if ($offerId > 0) {
                if ($role === 'admin') {
                    $db->query("UPDATE company_offers SET title='$oTitle', description='$oDesc', company_name='$oCompany', course='$oCourse', slots=$oSlots WHERE id=$offerId");
                } else {
                    $db->query("UPDATE company_offers SET title='$oTitle', description='$oDesc', course='$oCourse', slots=$oSlots, status='Pending', approved_by='', approved_at=NULL WHERE id=$offerId AND company_email='$email'");
                }
                $toastMessage = "OJT offer updated.";
            } else {
                $approvedBy = $role === 'admin' ? html_entity_decode($name, ENT_QUOTES, 'UTF-8') : '';
                $approvedAt = $role === 'admin' ? 'NOW()' : 'NULL';
                $db->query("INSERT INTO company_offers (company_name, company_email, title, description, school, course, slots, status, approved_by, approved_at)
                            VALUES ('$oCompany', '$email', '$oTitle', '$oDesc', '$school', '$oCourse', $oSlots, '$oStatus', '$approvedBy', $approvedAt)");
                $toastMessage = $role === 'admin'
                    ? "OJT offer \"$oTitle\" published for $oCourse students."
                    : "OJT offer \"$oTitle\" submitted to your campus coordinator for approval.";
            }
        } else {
            $toastMessage = "Please provide a company name and offer title.";
            $toastType = "warning";
        }
    }

    if ($action === 'save_training_recommendation' && $role === 'company' && $companyApprovalStatus === 'Approved') {
        $trainingTitle = trim($_POST['title'] ?? '');
        $trainingCourse = trim($_POST['course'] ?? 'All Courses');
        $trainingDescription = trim($_POST['description'] ?? '');
        $trainingUrl = trim($_POST['resource_url'] ?? '');
        $trainingUrlParts = $trainingUrl !== '' ? parse_url($trainingUrl) : [];
        $trainingUrlAllowed = $trainingUrl === ''
            || (filter_var($trainingUrl, FILTER_VALIDATE_URL)
                && isset($trainingUrlParts['scheme'])
                && in_array(strtolower($trainingUrlParts['scheme']), ['http', 'https'], true));
        if ($trainingTitle === '' || !$trainingUrlAllowed) {
            $toastMessage = 'Enter a recommendation title and a valid resource link.';
            $toastType = 'warning';
        } else {
            $trainingStatement = $db->prepare("INSERT INTO company_training_recommendations (company_email, company_name, school, course, title, description, resource_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $rawCompanyName = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
            $rawCompanySchool = html_entity_decode($school, ENT_QUOTES, 'UTF-8');
            $trainingStatement->bind_param('sssssss', $email, $rawCompanyName, $rawCompanySchool, $trainingCourse, $trainingTitle, $trainingDescription, $trainingUrl);
            if ($trainingStatement->execute()) {
                practicore_log_audit($db, $email, $role, 'training_recommendation_created', $trainingTitle);
                $toastMessage = 'Training recommendation published for your school.';
            }
            $trainingStatement->close();
        }
    }

    if ($action === 'delete_training_recommendation' && $role === 'company') {
        $recommendationId = (int)($_POST['recommendation_id'] ?? 0);
        $db->query("DELETE FROM company_training_recommendations WHERE id=$recommendationId AND company_email='$email'");
        $toastMessage = 'Your training recommendation was removed.';
    }

    if ($action === 'save_school_settings' && $role === 'admin') {
        $requiredHoursSetting = (int)($_POST['required_practicum_hours'] ?? 486);
        $companyStudentMessages = !empty($_POST['allow_company_student_messages']) ? '1' : '0';
        if ($requiredHoursSetting < 1 || $requiredHoursSetting > 2000) {
            $toastMessage = 'Required practicum hours must be between 1 and 2000.';
            $toastType = 'warning';
        } else {
            $settingsSchool = html_entity_decode($school, ENT_QUOTES, 'UTF-8');
            $settingsStatement = $db->prepare("INSERT INTO system_settings (school, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_by=VALUES(updated_by)");
            $settingKey = 'required_practicum_hours';
            $settingValue = (string)$requiredHoursSetting;
            $settingsStatement->bind_param('ssss', $settingsSchool, $settingKey, $settingValue, $email);
            $settingsStatement->execute();
            $settingKey = 'allow_company_student_messages';
            $settingValue = $companyStudentMessages;
            $settingsStatement->bind_param('ssss', $settingsSchool, $settingKey, $settingValue, $email);
            $settingsStatement->execute();
            $settingsStatement->close();
            practicore_log_audit($db, $email, $role, 'school_settings_updated', 'hours=' . $requiredHoursSetting . '; company_messages=' . $companyStudentMessages);
            $toastMessage = 'School settings saved.';
        }
    }

    // 10c. ADMIN: Approve or decline a company offer (controls student search visibility).
    if ($action === 'set_company_status' && $role === 'admin') {
        $companyId = (int)($_POST['company_id'] ?? 0);
        $newStatus = ($_POST['status'] ?? '') === 'Approved' ? 'Approved' : 'Pending';
        if ($companyId > 0) {
            $db->query("UPDATE companies SET approval_status='$newStatus', broadcast_to_students=IF('$newStatus'='Pending', 0, broadcast_to_students) WHERE id=$companyId AND LOWER(TRIM(school))='$schoolFilter'");
            if ($newStatus === 'Approved') {
                $companyQ = $db->query("SELECT email, name FROM companies WHERE id=$companyId AND LOWER(TRIM(school))='$schoolFilter'");
                if ($companyQ && $companyRow = $companyQ->fetch_assoc()) {
                    createDashboardNotification($db, $companyRow['email'], 'company', 'Your company account was approved by the school coordinator. You can now post offers for students.');
                }
                $toastMessage = 'Company account approved for ' . html_entity_decode($school, ENT_QUOTES, 'UTF-8') . ' students.';
            } else {
                $toastMessage = 'Company approval withdrawn and student broadcast disabled.';
            }
        }
    }

    if ($action === 'toggle_company_broadcast' && $role === 'admin') {
        $companyId = (int)($_POST['company_id'] ?? 0);
        $broadcast = (int)($_POST['broadcast'] ?? 0) === 1 ? 1 : 0;
        if ($companyId > 0) {
            $db->query("UPDATE companies SET broadcast_to_students=$broadcast WHERE id=$companyId AND approval_status='Approved' AND LOWER(TRIM(school))='$schoolFilter'");
            $toastMessage = $broadcast ? 'Company is now visible in student accounts.' : 'Company is no longer broadcasting to student accounts.';
        }
    }

    // ADMIN: Approve or decline a company offer (controls student search visibility).
    if ($action === 'set_offer_status' && $role === 'admin') {
        $offerId = (int)($_POST['offer_id'] ?? 0);
        $newStatus = ($_POST['status'] ?? '') === 'Approved' ? 'Approved' : 'Pending';
        if ($offerId > 0) {
            if ($newStatus === 'Approved') {
                $approvedBy = $db->real_escape_string(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
                $db->query("UPDATE company_offers SET status='Approved', approved_by='$approvedBy', approved_at=NOW() WHERE id=$offerId");
                // Let the company know their offer is now visible to matching students.
                $offerQ = $db->query("SELECT company_email, title FROM company_offers WHERE id=$offerId");
                if ($offerQ && $offerRow = $offerQ->fetch_assoc()) {
                    if (!empty($offerRow['company_email'])) {
                        createDashboardNotification($db, $offerRow['company_email'], 'application', 'Your OJT offer "' . $offerRow['title'] . '" was approved and is now visible to matching students.');
                    }
                }
                $toastMessage = "Offer approved — visible to " . ucfirst($school) . " students.";
            } else {
                $db->query("UPDATE company_offers SET status='Pending', approved_by='', approved_at=NULL WHERE id=$offerId");
                $toastMessage = "Offer approval withdrawn.";
            }
        }
    }

    // 10d. ADMIN / COMPANY: Delete an OJT offer.
    if ($action === 'delete_offer' && in_array($role, ['admin', 'company'], true)) {
        $offerId = (int)($_POST['offer_id'] ?? 0);
        if ($offerId > 0) {
            $ownerFilter = $role === 'admin' ? '' : " AND company_email='$email'";
            $db->query("DELETE FROM company_offers WHERE id=$offerId$ownerFilter");
            $toastMessage = "OJT offer deleted.";
        }
    }

    // 11. MESSAGES: Send Direct Message
    if ($action === 'send_message') {
        $recEmail = $db->real_escape_string(trim($_POST['receiver_email'] ?? ''));
        $recName = $db->real_escape_string(trim($_POST['receiver_name'] ?? 'Recipient'));
        $msg = $db->real_escape_string(trim($_POST['message'] ?? ''));
        $companyMayMessageStudent = true;
        if ($role === 'company') {
            $recipientStudentQ = $db->query("SELECT email, school FROM users WHERE email='$recEmail' AND role='student' LIMIT 1");
            $recipientStudent = $recipientStudentQ ? $recipientStudentQ->fetch_assoc() : null;
            if ($recipientStudent) {
                $recipientSchool = $db->real_escape_string(html_entity_decode($recipientStudent['school'], ENT_QUOTES, 'UTF-8'));
                $messagePermissionQ = $db->query("SELECT setting_value FROM system_settings WHERE school='$recipientSchool' AND setting_key='allow_company_student_messages' LIMIT 1");
                $messagePermission = $messagePermissionQ ? $messagePermissionQ->fetch_assoc() : null;
                $companyMayMessageStudent = !$messagePermission || $messagePermission['setting_value'] === '1';
            }
        }

        if (!$companyMayMessageStudent) {
            $toastMessage = 'Student messaging is disabled by this school coordinator.';
            $toastType = 'warning';
        } elseif (!empty($recEmail) && !empty($msg)) {
            $db->query("INSERT INTO messages (sender_name, sender_email, sender_role, receiver_email, receiver_name, message, is_read) 
                        VALUES ('$name', '$email', '$role', '$recEmail', '$recName', '$msg', 0)");
            $toastMessage = "Message delivered to $recName!";
        }
    }

    // 11b. MESSAGES: Mark all inbox messages as read
    if ($action === 'mark_messages_read') {
        $fromEmail = $db->real_escape_string(trim($_POST['sender_email'] ?? ''));
        $senderFilter = $fromEmail ? " AND sender_email='$fromEmail'" : '';
        $db->query("UPDATE messages SET is_read=1, read_at=NOW() WHERE receiver_email='$email' AND is_read=0$senderFilter");
        if (!empty($_POST['mark_notifications'])) {
            $_SESSION['notifications_read_at'] = date('Y-m-d H:i:s');
            $db->query("UPDATE notifications SET is_read=1 WHERE receiver_email='$email' AND is_read=0");
        }
        // Return silent (no toast needed)
    }

    // 12. STUDENT: Favorite / Bookmark Company
    if ($action === 'toggle_favorite' && $role === 'student') {
        $jId = (int)($_POST['job_id'] ?? 0);
        $cName = $db->real_escape_string($_POST['company_name'] ?? '');
        $jTitle = $db->real_escape_string($_POST['job_title'] ?? '');

        $favCheck = $db->query("SELECT id FROM favorites WHERE student_email='$email' AND job_id=$jId");
        if ($favCheck && $favCheck->num_rows > 0) {
            $db->query("DELETE FROM favorites WHERE student_email='$email' AND job_id=$jId");
            $toastMessage = "Removed from your favorite companies.";
        } else {
            $db->query("INSERT INTO favorites (student_email, job_id, company_name, job_title) VALUES ('$email', $jId, '$cName', '$jTitle')");
            $toastMessage = "Saved to your favorite companies!";
        }
    }

    // 13. STUDENT: Report Company
    if ($action === 'report_company' && $role === 'student') {
        $cName = $db->real_escape_string(trim($_POST['company_name'] ?? ''));
        $reason = $db->real_escape_string(trim($_POST['reason'] ?? ''));
        if (!empty($cName) && !empty($reason)) {
            $db->query("INSERT INTO reports (student_email, company_name, reason) VALUES ('$email', '$cName', '$reason')");
            $toastMessage = "Report submitted. School administration will review this listing.";
        }
    }

    // 14. STUDENT: Post "Looking for Company" Notice (System Proposal 4.1 & 5)
    if ($action === 'post_student_notice' && $role === 'student') {
        $nHeadline = $db->real_escape_string(trim($_POST['headline'] ?? ''));
        $nRole = $db->real_escape_string(trim($_POST['preferred_role'] ?? ''));
        $nSkills = $db->real_escape_string(trim($_POST['skills'] ?? ''));
        $nAvail = $db->real_escape_string(trim($_POST['availability'] ?? 'Immediate / Full-time'));
        $nMsg = $db->real_escape_string(trim($_POST['message'] ?? ''));

        if (!empty($nHeadline) && !empty($nRole)) {
            $db->query("DELETE FROM student_notices WHERE student_email='$email'");
            $db->query("INSERT INTO student_notices (student_name, student_email, school, course, headline, preferred_role, skills, availability, message) 
                        VALUES ('$name', '$email', '$school', '$course', '$nHeadline', '$nRole', '$nSkills', '$nAvail', '$nMsg')");
            $toastMessage = "Your \"Looking for Company\" notice is live and visible to partner companies!";
        }
    }

    // 15. STUDENT: Delete Notice
    if ($action === 'delete_student_notice' && $role === 'student') {
        $db->query("DELETE FROM student_notices WHERE student_email='$email'");
        $toastMessage = "Your availability notice has been removed.";
    }
}

// Redirect alert handler
if (isset($_GET['msg'])) {
    $toastMessage = htmlspecialchars($_GET['msg']);
}

// =========================================================================
// DATA FETCHING: Real-Time Dynamic Queries
// =========================================================================

// Direct-message threads. A thread is one conversation with one other person.
$inboxMessages = [];
$unreadMsgCount = 0;
$messageThreads = [];
$latestMessageId = 0;
if ($db) {
    $mQ = $db->query("SELECT * FROM messages WHERE receiver_email='$email' OR sender_email='$email' ORDER BY id DESC LIMIT 200");
    if ($mQ) {
        while ($row = $mQ->fetch_assoc()) {
            $latestMessageId = max($latestMessageId, (int)$row['id']);
            $isIncoming = $row['receiver_email'] === $email;
            $otherEmail = $isIncoming ? $row['sender_email'] : $row['receiver_email'];
            $otherName = $isIncoming ? $row['sender_name'] : $row['receiver_name'];
            if (!isset($messageThreads[$otherEmail])) {
                $messageThreads[$otherEmail] = ['email'=>$otherEmail, 'name'=>$otherName ?: 'Conversation', 'role'=>$isIncoming ? $row['sender_role'] : '', 'messages'=>[], 'unread'=>0, 'last_id'=>(int)$row['id']];
            }
            $messageThreads[$otherEmail]['messages'][] = $row;
            if ($isIncoming && empty($row['is_read'])) {
                $messageThreads[$otherEmail]['unread']++;
                $unreadMsgCount++;
            }
        }
    }
}

// Global: Potential message recipients (role-aware, school-filtered)
// Uses 3 separate PHP queries merged — avoids UNION compatibility issues
$allUsersList = [];
if ($db) {
    $sfFilter = strtolower(trim($school));
    if ($role === 'student') {
        // Students can message: their campus admin + all registered companies
        $r1 = $db->query("SELECT name, email, 'admin' as role, school FROM admins WHERE LOWER(TRIM(school))='$sfFilter'");
        if ($r1) while ($row = $r1->fetch_assoc()) $allUsersList[] = $row;
        $r2 = $db->query("SELECT name, email, 'company' as role, school FROM companies WHERE email != '$email' ORDER BY name ASC");
        if ($r2) while ($row = $r2->fetch_assoc()) $allUsersList[] = $row;
    } elseif ($role === 'admin') {
        // Admins can message: ALL students in their school + all companies
        $r1 = $db->query("SELECT name, email, role, school FROM users WHERE LOWER(TRIM(school))='$sfFilter' AND role='student' ORDER BY name ASC");
        if ($r1) while ($row = $r1->fetch_assoc()) $allUsersList[] = $row;
        $r2 = $db->query("SELECT name, email, 'company' as role, school FROM companies ORDER BY name ASC");
        if ($r2) while ($row = $r2->fetch_assoc()) $allUsersList[] = $row;
    } else {
        // Companies can message: all students + admins
        $r1 = $db->query("SELECT name, email, role, school FROM users WHERE email != '$email' ORDER BY name ASC");
        if ($r1) while ($row = $r1->fetch_assoc()) $allUsersList[] = $row;
        $r2 = $db->query("SELECT name, email, 'admin' as role, school FROM admins ORDER BY name ASC");
        if ($r2) while ($row = $r2->fetch_assoc()) $allUsersList[] = $row;
    }
    // Deduplicate by email just in case
    $seen = [];
    $allUsersList = array_filter($allUsersList, function($u) use (&$seen) {
        if (isset($seen[$u['email']])) return false;
        $seen[$u['email']] = true;
        return true;
    });
    $allUsersList = array_values($allUsersList);

    // Add profile photos to conversation contacts when available.
    $contactAvatars = [];
    foreach (account_tables() as $contactTable) {
        $aQ = $db->query("SELECT email, avatar FROM `$contactTable`");
        if ($aQ) while ($aRow = $aQ->fetch_assoc()) $contactAvatars[$aRow['email']] = $aRow['avatar'] ?? '';
    }
    foreach ($messageThreads as $threadEmail => &$thread) $thread['avatar'] = $contactAvatars[$threadEmail] ?? '';
    unset($thread);
}

// Notifications for current user. The badge is rendered only for events received
// after the user last marked notifications as read.
$notifications = [];
$notifCount = 0;
if ($db) {
    // Old status records are not new alerts. From this visit onward, only fresh updates show a bell badge.
    if (!isset($_SESSION['notifications_read_at'])) $_SESSION['notifications_read_at'] = date('Y-m-d H:i:s');
    $notificationsReadAt = $_SESSION['notifications_read_at'];
    $safeNotificationsReadAt = $db->real_escape_string($notificationsReadAt);
    // Each unread direct message identifies its sender instead of a vague counter.
    $messageNotifQ = $db->query("SELECT sender_name FROM messages WHERE receiver_email='$email' AND is_read=0 ORDER BY id DESC LIMIT 10");
    if ($messageNotifQ) while ($messageRow = $messageNotifQ->fetch_assoc()) {
        $notifications[] = ['icon'=>'bi-chat-dots-fill', 'color'=>'text-primary', 'text'=>htmlspecialchars($messageRow['sender_name']) . ' sent you a message.', 'type'=>'message'];
    }
    // Saved events are used by every role: student, admin, and company.
    $savedNotifQ = $db->query("SELECT type, message FROM notifications WHERE receiver_email='$email' AND is_read=0 ORDER BY id DESC LIMIT 10");
    if ($savedNotifQ) while ($nRow = $savedNotifQ->fetch_assoc()) {
        $isDeclined = stripos($nRow['message'], 'declined') !== false;
        $typeIcons = ['document'=>'bi-file-earmark-arrow-up-fill', 'application'=>'bi-person-plus-fill', 'module'=>'bi-trophy-fill', 'clearance'=>$isDeclined ? 'bi-x-circle-fill' : 'bi-patch-check-fill'];
        $typeColors = ['document'=>'text-warning', 'application'=>'text-info', 'module'=>'text-warning', 'clearance'=>$isDeclined ? 'text-danger' : 'text-success'];
        $notifications[] = ['icon'=>$typeIcons[$nRow['type']] ?? 'bi-bell-fill', 'color'=>$typeColors[$nRow['type']] ?? 'text-primary', 'text'=>htmlspecialchars($nRow['message']), 'type'=>$nRow['type']];
    }
    // Cap display at 10
    $notifications = array_slice($notifications, 0, 10);
    $notifCount = count($notifications);
}

// Student Specific Data
$studentJobs = [];
$studentApplications = [];
$appliedJobKeys = [];
$dtrLogs = [];
$myFavorites = [];
$totalRenderedHours = 0.0;
$requiredHours = 486;
$schoolSettings = ['allow_company_student_messages' => '1'];
$rawDashboardSchool = html_entity_decode($school, ENT_QUOTES, 'UTF-8');
$settingsSchoolFilter = $db ? $db->real_escape_string($rawDashboardSchool) : '';
$settingsQ = $db ? $db->query("SELECT setting_key, setting_value FROM system_settings WHERE school='$settingsSchoolFilter'") : false;
if ($settingsQ) {
    while ($setting = $settingsQ->fetch_assoc()) {
        $schoolSettings[$setting['setting_key']] = $setting['setting_value'];
    }
}
$requiredHours = max(1, min(2000, (int)($schoolSettings['required_practicum_hours'] ?? 486)));
$myClearance = [
    'moa_status'         => 'Pending',
    'endorsement_status' => 'Pending',
    'waiver_status'      => 'Pending',
    'medical_status'     => 'Pending',
    'moa_file'           => '',
    'endorsement_file'   => '',
    'waiver_file'        => '',
    'medical_file'       => '',
    'is_completed'       => 0
];
$adminModules = [];
$moduleProgress = [];
$studentAchievements = [];
$myNotice = null;
$availableCompanies = [];
$approvedOffers = [];
$affiliatedCompanies = [];
$studentDocuments = [];
$favoriteCompanies = [];
$favoriteJobs = [];
$companyTrainingRecommendations = [];

if ($role === 'student' && $db) {
    $studentInstitutionKey = practicore_institution_key($institution, html_entity_decode($school, ENT_QUOTES, 'UTF-8'));
    $studentCampus = practicore_account_campus($campus, html_entity_decode($school, ENT_QUOTES, 'UTF-8'));

    $broadcastQ = $db->query("SELECT name, email, school, institution, campus, headline, bio, avatar FROM companies WHERE approval_status='Approved' AND broadcast_to_students=1 ORDER BY name ASC");
    if ($broadcastQ) {
        while ($partner = $broadcastQ->fetch_assoc()) {
            $partnerInstitutionKey = practicore_institution_key($partner['institution'] ?? '', $partner['school'] ?? '');
            $partnerCampus = practicore_account_campus($partner['campus'] ?? '', $partner['school'] ?? '');
            if (practicore_company_visible_to_student($studentInstitutionKey, $studentCampus, $partnerInstitutionKey, $partnerCampus)) {
                $affiliatedCompanies[] = $partner;
            }
        }
    }

    // 1. Live Jobs
    $jQ = $db->query("SELECT * FROM jobs WHERE LOWER(TRIM(school))='$schoolFilter' AND (deadline IS NULL OR deadline >= CURRENT_DATE()) ORDER BY id DESC");
    if ($jQ) while ($r = $jQ->fetch_assoc()) $studentJobs[] = $r;

    // 2. Applications
    $aQ = $db->query("SELECT job_title, company_name, company_email, status, created_at FROM applications WHERE student_email='$email' ORDER BY id DESC");
    if ($aQ) while ($r = $aQ->fetch_assoc()) {
        $appliedJobKeys[$r['company_name'] . '|' . $r['job_title']] = $r['status'];
        $studentApplications[] = $r;
    }

    // 3. DTR Logs
    $dQ = $db->query("SELECT * FROM dtr_logs WHERE student_email='$email' ORDER BY log_date DESC");
    if ($dQ) {
        while ($r = $dQ->fetch_assoc()) {
            $dtrLogs[] = $r;
            $totalRenderedHours += (float)$r['hours'];
        }
    }

    // 4. Favorites
    $fQ = $db->query("SELECT job_id FROM favorites WHERE student_email='$email'");
    if ($fQ) while ($r = $fQ->fetch_assoc()) $myFavorites[$r['job_id']] = true;
    $favoriteCompanyQ = $db->query("SELECT company_email FROM favorite_companies WHERE student_email='$email'");
    $favoriteCompanyEmails = [];
    if ($favoriteCompanyQ) while ($r = $favoriteCompanyQ->fetch_assoc()) $favoriteCompanyEmails[$r['company_email']] = true;
    $favoriteJobsQ = $db->query("SELECT job_id, company_name, job_title FROM favorites WHERE student_email='$email' ORDER BY id DESC");
    if ($favoriteJobsQ) while ($favoriteJob = $favoriteJobsQ->fetch_assoc()) $favoriteJobs[] = $favoriteJob;
    $studentDocumentsQ = $db->query("SELECT id, label, file_path, created_at FROM student_documents WHERE student_email='$email' ORDER BY id DESC");
    if ($studentDocumentsQ) while ($document = $studentDocumentsQ->fetch_assoc()) $studentDocuments[] = $document;
    $favoriteCompaniesQ = $db->query("SELECT fc.company_email, fc.company_name FROM favorite_companies fc WHERE fc.student_email='$email' ORDER BY fc.created_at DESC");
    if ($favoriteCompaniesQ) while ($favoriteCompany = $favoriteCompaniesQ->fetch_assoc()) $favoriteCompanies[] = $favoriteCompany;

    // 5. Official Clearance
    $cQ = $db->query("SELECT * FROM clearances WHERE student_email='$email'");
    if ($cQ && $cQ->num_rows > 0) $myClearance = $cQ->fetch_assoc();

    // 6. Admin Modules for this School
    $mQ = $db->query("SELECT * FROM modules WHERE LOWER(TRIM(school))='$schoolFilter' ORDER BY id DESC");
    if ($mQ) while ($r = $mQ->fetch_assoc()) $adminModules[] = $r;
    $safeStudentCourse = $db->real_escape_string($course);
    $trainingQ = $db->query("SELECT r.*, c.institution AS company_institution, c.campus AS company_campus, c.school AS company_school FROM company_training_recommendations r INNER JOIN companies c ON c.email=r.company_email WHERE c.approval_status='Approved' AND (r.course='$safeStudentCourse' OR r.course='All Courses') ORDER BY r.id DESC");
    if ($trainingQ) {
        while ($recommendation = $trainingQ->fetch_assoc()) {
            $recommendationInstitution = practicore_institution_key($recommendation['company_institution'] ?? '', $recommendation['company_school'] ?? $recommendation['school']);
            $recommendationCampus = practicore_account_campus($recommendation['company_campus'] ?? '', $recommendation['company_school'] ?? $recommendation['school']);
            if (practicore_company_visible_to_student($studentInstitutionKey, $studentCampus, $recommendationInstitution, $recommendationCampus)) {
                $companyTrainingRecommendations[] = $recommendation;
            }
        }
    }

    $mpQ = $db->query("SELECT mp.*, m.title FROM module_progress mp INNER JOIN modules m ON m.id=mp.module_id WHERE mp.student_email='$email'");
    if ($mpQ) while ($r = $mpQ->fetch_assoc()) {
        $moduleProgress[(int)$r['module_id']] = $r;
        if ((int)$r['is_completed'] === 1) $studentAchievements[] = $r;
    }

    // 7. Student Looking for Company Notice
    $nQ = $db->query("SELECT * FROM student_notices WHERE student_email='$email'");
    if ($nQ && $nQ->num_rows > 0) $myNotice = $nQ->fetch_assoc();

    // 8. Available Host Companies (from jobs, applications, or registered company users)
    $compQ = $db->query("SELECT DISTINCT company_name FROM jobs WHERE LOWER(TRIM(school))='$schoolFilter' UNION SELECT DISTINCT company_name FROM applications WHERE student_email='$email' UNION SELECT name as company_name FROM companies WHERE LOWER(TRIM(school))='$schoolFilter' AND approval_status='Approved' AND broadcast_to_students=1");
    if ($compQ) {
        while ($r = $compQ->fetch_assoc()) {
            if (!empty($r['company_name'])) $availableCompanies[] = $r['company_name'];
        }
    }

    // 9. Coordinator-approved OJT offers matching this student's campus and course.
    if ($course !== '') {
        $safeCourse = $db->real_escape_string($course);
        $offerQ = $db->query("SELECT * FROM company_offers WHERE LOWER(TRIM(school))='$schoolFilter' AND status='Approved' AND (course='$safeCourse' OR course='All Tech Courses' OR course='All Courses') ORDER BY id DESC");
    } else {
        $offerQ = $db->query("SELECT * FROM company_offers WHERE LOWER(TRIM(school))='$schoolFilter' AND status='Approved' ORDER BY id DESC");
    }
    if ($offerQ) while ($r = $offerQ->fetch_assoc()) $approvedOffers[] = $r;
}

$remainingHours = max(0, $requiredHours - $totalRenderedHours);
$hourProgressPct = min(100, round(($totalRenderedHours / $requiredHours) * 100));

// Company Specific Data
$companyJobs = [];
$companyApplicants = [];
$companyDtrReviews = [];
$studentTalentPool = [];
$companyOwnTrainingRecommendations = [];

if ($role === 'company' && $db) {
    // 1. Posted jobs
    $cjQ = $db->query("SELECT * FROM jobs WHERE company_name='$name' OR company_email='$email' ORDER BY id DESC");
    if ($cjQ) while ($r = $cjQ->fetch_assoc()) $companyJobs[] = $r;

    // 2. Student applicants with attachments
    $caQ = $db->query("SELECT * FROM applications WHERE company_name='$name' OR company_email='$email' ORDER BY id DESC");
    if ($caQ) while ($r = $caQ->fetch_assoc()) $companyApplicants[] = $r;
    if ($companyApplicants) {
        $safeCompanyNameForDocuments = $db->real_escape_string(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
        $companyDocumentsQ = $db->query("SELECT ad.application_id, sd.id AS document_id, sd.label FROM application_documents ad
            INNER JOIN student_documents sd ON sd.id=ad.student_document_id
            INNER JOIN applications a ON a.id=ad.application_id
            WHERE a.company_email='$safeEmail' OR (a.company_email='' AND a.company_name='$safeCompanyNameForDocuments')
            ORDER BY sd.created_at DESC");
        if ($companyDocumentsQ) {
            $companyDocumentsByApplication = [];
            while ($companyDocument = $companyDocumentsQ->fetch_assoc()) {
                $companyDocumentsByApplication[(int)$companyDocument['application_id']][] = $companyDocument;
            }
            foreach ($companyApplicants as &$companyApplicant) {
                $companyApplicant['shared_documents'] = $companyDocumentsByApplication[(int)$companyApplicant['id']] ?? [];
            }
            unset($companyApplicant);
        }
    }

    // 3. Trainee DTRs for review (matches exact or partial company name for supervisor convenience)
    $cdQ = $db->query("SELECT * FROM dtr_logs WHERE company_name='$name' OR company_name LIKE '%$name%' OR '$name' LIKE CONCAT('%', company_name, '%') ORDER BY log_date DESC");
    if ($cdQ) while ($r = $cdQ->fetch_assoc()) $companyDtrReviews[] = $r;

    // 4. Student Talent Pool (Looking for Company notices for this designated school)
    $stpQ = $db->query("SELECT * FROM student_notices WHERE LOWER(TRIM(school))='$schoolFilter' ORDER BY id DESC");
    if ($stpQ) while ($r = $stpQ->fetch_assoc()) $studentTalentPool[] = $r;
    $companyRecommendationQ = $db->query("SELECT * FROM company_training_recommendations WHERE company_email='$email' ORDER BY id DESC");
    if ($companyRecommendationQ) while ($r = $companyRecommendationQ->fetch_assoc()) $companyOwnTrainingRecommendations[] = $r;
}

// Admin Specific Data
$adminStudents = [];
$adminClearanceList = [];
$adminModulesList = [];
$adminPartnerCompanies = [];
$adminStudentNotices = [];
$adminReportCounts = ['students'=>0, 'enrolled_cleared'=>0, 'clearance_in_progress'=>0, 'applications'=>0, 'accepted'=>0, 'rejected'=>0, 'postings'=>0, 'affiliations'=>0, 'training_modules'=>0, 'training_recommendations'=>0, 'training_completions'=>0];
$adminApplicationsForReport = [];
$adminAuditLogs = [];

if ($role === 'admin' && $db) {
    // Student Notices
    $asnQ = $db->query("SELECT * FROM student_notices WHERE LOWER(TRIM(school))='$schoolFilter' ORDER BY id DESC");
    if ($asnQ) while ($r = $asnQ->fetch_assoc()) $adminStudentNotices[] = $r;
    // Main-campus admins see every branch; branch admins only see their own campus.
    $asQ = $db->query("SELECT * FROM users WHERE role='student' ORDER BY id DESC");
    if ($asQ) {
        while ($r = $asQ->fetch_assoc()) {
            $studentInstitutionKey = practicore_institution_key($r['institution'] ?? '', $r['school'] ?? '');
            $studentCampus = practicore_account_campus($r['campus'] ?? '', $r['school'] ?? '');
            $sameInstitution = practicore_same_institution($studentInstitutionKey, $adminInstitutionKey);
            $sameCampus = practicore_campus_key($studentCampus) === $adminCampusKey;
            if ($sameInstitution && ($adminCanViewAllCampuses || $sameCampus)) {
                $r['campus'] = $studentCampus;
                $r['documents'] = [];
                $adminStudents[] = $r;
            }
        }
    }
    if ($adminStudents) {
        $adminStudentEmails = array_column($adminStudents, 'email');
        $adminEmailList = "'" . implode("','", array_map(function ($studentEmail) use ($db) {
            return $db->real_escape_string($studentEmail);
        }, $adminStudentEmails)) . "'";
        $adminDocumentsQ = $db->query("SELECT id, student_email, label, created_at FROM student_documents WHERE student_email IN ($adminEmailList) ORDER BY created_at DESC");
        if ($adminDocumentsQ) {
            $adminDocumentsByStudent = [];
            while ($adminDocument = $adminDocumentsQ->fetch_assoc()) {
                $adminDocumentsByStudent[$adminDocument['student_email']][] = $adminDocument;
            }
            foreach ($adminStudents as &$adminStudent) {
                $adminStudent['documents'] = $adminDocumentsByStudent[$adminStudent['email']] ?? [];
            }
            unset($adminStudent);
        }
    }

    // Clearances for every student in this institution, regardless of campus.
    // First collect all student emails from $adminStudents (already populated above)
    foreach ($adminStudents as $stu) {
        $stuEmail = $db->real_escape_string($stu['email']);
        // Look up clearance record for this student (may not exist yet)
        $cQ = $db->query("SELECT * FROM clearances WHERE student_email='$stuEmail' LIMIT 1");
        $cRow = ($cQ && $cQ->num_rows > 0) ? $cQ->fetch_assoc() : [];
        $adminClearanceList[] = [
            'name'               => $stu['name'],
            'email'              => $stu['email'],
            'course'             => $stu['course'],
            'avatar'             => $stu['avatar'] ?? '',
            'campus'             => $stu['campus'] ?? 'Other',
            'moa_status'         => $cRow['moa_status']        ?? 'Pending',
            'endorsement_status' => $cRow['endorsement_status'] ?? 'Pending',
            'waiver_status'      => $cRow['waiver_status']      ?? 'Pending',
            'medical_status'     => $cRow['medical_status']     ?? 'Pending',
            'moa_file'           => $cRow['moa_file']           ?? '',
            'endorsement_file'   => $cRow['endorsement_file']   ?? '',
            'waiver_file'        => $cRow['waiver_file']        ?? '',
            'medical_file'       => $cRow['medical_file']       ?? '',
            'is_completed'       => $cRow['is_completed']       ?? 0,
        ];
    }
    // Sort by name
    usort($adminClearanceList, fn($a, $b) => strcmp($a['name'], $b['name']));


    // Modules posted by this admin
    $amQ = $db->query("SELECT * FROM modules WHERE LOWER(TRIM(school))='$schoolFilter' ORDER BY id DESC");
    if ($amQ) while ($r = $amQ->fetch_assoc()) $adminModulesList[] = $r;

    // Partner companies
    $apQ = $db->query("SELECT DISTINCT company_name, company_email, COUNT(*) as post_count FROM jobs WHERE LOWER(TRIM(school))='$schoolFilter' GROUP BY company_name, company_email");
    if ($apQ) while ($r = $apQ->fetch_assoc()) $adminPartnerCompanies[] = $r;

    $adminReportCounts['students'] = count($adminStudents);
    foreach ($adminClearanceList as $clearanceRecord) {
        if ((int)$clearanceRecord['is_completed'] === 1) $adminReportCounts['enrolled_cleared']++;
        else $adminReportCounts['clearance_in_progress']++;
    }
    $adminReportCounts['training_modules'] = count($adminModulesList);
    $adminApplicationsQ = $db->query("SELECT student_email, student_name, student_school, student_course, company_name, job_title, status, created_at FROM applications ORDER BY id DESC");
    $adminEmailSet = [];
    foreach ($adminStudents as $adminStudent) $adminEmailSet[$adminStudent['email']] = true;
    if ($adminApplicationsQ) {
        while ($application = $adminApplicationsQ->fetch_assoc()) {
            if (!isset($adminEmailSet[$application['student_email']])) continue;
            $adminApplicationsForReport[] = $application;
            $adminReportCounts['applications']++;
            if ($application['status'] === 'Accepted & Endorsed') $adminReportCounts['accepted']++;
            if ($application['status'] === 'Rejected') $adminReportCounts['rejected']++;
        }
    }
    $postingQ = $db->query("SELECT COUNT(*) AS total FROM jobs WHERE LOWER(TRIM(school))='$schoolFilter' AND (deadline IS NULL OR deadline >= CURRENT_DATE())");
    if ($postingQ && $postingRow = $postingQ->fetch_assoc()) $adminReportCounts['postings'] = (int)$postingRow['total'];
    $affiliationQ = $db->query("SELECT COUNT(*) AS total FROM companies WHERE LOWER(TRIM(school))='$schoolFilter' AND approval_status='Approved' AND broadcast_to_students=1");
    if ($affiliationQ && $affiliationRow = $affiliationQ->fetch_assoc()) $adminReportCounts['affiliations'] = (int)$affiliationRow['total'];
    $recommendationQ = $db->query("SELECT COUNT(*) AS total FROM company_training_recommendations WHERE LOWER(TRIM(school))='$schoolFilter'");
    if ($recommendationQ && $recommendationRow = $recommendationQ->fetch_assoc()) $adminReportCounts['training_recommendations'] = (int)$recommendationRow['total'];
    $trainingUsageQ = $db->query("SELECT COUNT(*) AS total FROM module_progress mp INNER JOIN modules m ON m.id=mp.module_id WHERE LOWER(TRIM(m.school))='$schoolFilter' AND mp.is_completed=1");
    if ($trainingUsageQ && $trainingUsageRow = $trainingUsageQ->fetch_assoc()) $adminReportCounts['training_completions'] = (int)$trainingUsageRow['total'];
    $adminAuditQ = $db->query("SELECT actor_email, action, details, created_at FROM audit_logs WHERE LOWER(TRIM(school))='$schoolFilter' ORDER BY id DESC LIMIT 20");
    if ($adminAuditQ) while ($auditRow = $adminAuditQ->fetch_assoc()) $adminAuditLogs[] = $auditRow;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'poll_messages' && $db) {
    $afterMessageId = max(0, (int)($_POST['after_id'] ?? 0));
    $pollQ = $db->query("SELECT id, sender_name, sender_email, sender_role, receiver_name, receiver_email, message, is_read, created_at FROM messages WHERE (sender_email='$safeEmail' OR receiver_email='$safeEmail') AND id>$afterMessageId ORDER BY id ASC LIMIT 50");
    if (!$pollQ) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unable to refresh messages.']);
        exit;
    }
    $newMessages = [];
    while ($messageRow = $pollQ->fetch_assoc()) {
        $incoming = $messageRow['receiver_email'] === $email;
        $messageRow['incoming'] = $incoming;
        $messageRow['other_email'] = $incoming ? $messageRow['sender_email'] : $messageRow['receiver_email'];
        $messageRow['other_name'] = $incoming ? $messageRow['sender_name'] : $messageRow['receiver_name'];
        $newMessages[] = $messageRow;
    }
    $unreadQ = $db->query("SELECT COUNT(*) AS total FROM messages WHERE receiver_email='$safeEmail' AND is_read=0");
    $unreadRow = $unreadQ ? $unreadQ->fetch_assoc() : ['total' => 0];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['messages' => $newMessages, 'unread' => (int)$unreadRow['total']]);
    exit;
}

if (isset($_GET['export_admin_report']) && $role === 'admin' && $db) {
    if (!isset($_GET['report']) || $_GET['report'] !== 'applications') {
        http_response_code(400);
        exit('Unknown report type.');
    }
    practicore_log_audit($db, $email, $role, 'application_report_exported', $school);
    $pdfText = static function ($value) {
        $text = trim(preg_replace('/[\r\n\t]+/', ' ', (string)$value));
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            $text = $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $text);
        }
        return str_replace(["\\", "(", ")", "\0"], ["\\\\", "\\(", "\\)", ""], $text);
    };
    $drawText = static function ($x, $y, $text, $fontSize = 9, $bold = false, $color = '0 0 0') use ($pdfText) {
        $font = $bold ? 'F2' : 'F1';
        return "$color rg\nBT /$font $fontSize Tf 1 0 0 1 $x $y Tm (" . $pdfText($text) . ") Tj ET\n";
    };
    $drawRect = static function ($x, $y, $width, $height, $fill, $stroke = null) {
        $command = '';
        if ($fill !== null) $command .= $fill . " rg\n";
        if ($stroke !== null) $command .= $stroke . " RG\n";
        $command .= "$x $y $width $height re ";
        $command .= $fill !== null && $stroke !== null ? "B\n" : ($fill !== null ? "f\n" : "S\n");
        return $command;
    };
    $wrapCell = static function ($value, $maxCharacters) {
        $text = trim(preg_replace('/\s+/', ' ', (string)$value));
        if ($text === '') return [''];
        $words = preg_split('/\s+/', $text);
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            while (strlen($word) > $maxCharacters) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = substr($word, 0, $maxCharacters - 1) . '-';
                $word = substr($word, $maxCharacters - 1);
            }
            if ($line !== '' && strlen($line) + strlen($word) + 1 > $maxCharacters) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $line === '' ? $word : $line . ' ' . $word;
            }
        }
        if ($line !== '') $lines[] = $line;
        if (count($lines) > 2) {
            $lines = array_slice($lines, 0, 2);
            $lines[1] = rtrim(substr($lines[1], 0, max(0, $maxCharacters - 3))) . '...';
        }
        return $lines;
    };

    $columns = [
        ['Student Name', 'student_name', 78],
        ['Student Email', 'student_email', 105],
        ['Designated School', 'student_school', 100],
        ['Course', 'student_course', 48],
        ['Company', 'company_name', 83],
        ['Applied Position', 'job_title', 112],
        ['Status', 'status', 82],
        ['Applied At', 'created_at', 112],
    ];
    $pageContents = [];
    $pageNumber = 0;
    $startPage = static function ($isFirstPage) use (&$pageNumber, &$columns, &$pageContents, $drawText, $drawRect, $school, $adminReportCounts) {
        $pageNumber++;
        $content = '';
        $content .= $drawRect(0, 0, 792, 612, '1 1 1');
        $content .= $drawRect(0, 552, 792, 60, '0.04 0.32 0.48');
        $content .= $drawText(36, 579, 'PRACTICORE  |  SCHOOL REPORT', 9, true, '1 1 1');
        $content .= $drawText(36, 559, 'Student Applications', 17, true, '1 1 1');
        $content .= $drawText(36, 536, 'School / Campus: ' . html_entity_decode($school, ENT_QUOTES, 'UTF-8'), 9);
        $content .= $drawText(36, 521, 'Generated: ' . date('F j, Y  g:i A'), 8);
        if ($isFirstPage) {
            $summaryCards = [
                ['Applications', $adminReportCounts['applications']],
                ['Accepted', $adminReportCounts['accepted']],
                ['Rejected', $adminReportCounts['rejected']],
                ['Students', $adminReportCounts['students']],
            ];
            $cardX = 36;
            foreach ($summaryCards as $card) {
                $content .= $drawRect($cardX, 475, 168, 34, '0.91 0.96 0.98', '0.78 0.86 0.9');
                $content .= $drawText($cardX + 10, 491, (string)$card[1], 13, true);
                $content .= $drawText($cardX + 36, 491, $card[0], 8);
                $cardX += 180;
            }
            $tableTop = 457;
        } else {
            $tableTop = 505;
        }

        $headerHeight = 28;
        $x = 36;
        foreach ($columns as $column) {
            $content .= $drawRect($x, $tableTop - $headerHeight, $column[2], $headerHeight, '0.08 0.38 0.53', '1 1 1');
            $content .= $drawText($x + 5, $tableTop - 17, $column[0], 7.2, true);
            $x += $column[2];
        }
        $pageContents[$pageNumber] = $content;
        return $tableTop - $headerHeight;
    };

    $rowIndex = 0;
    $cursorY = $startPage(true);
    if (!$adminApplicationsForReport) {
        $pageContents[$pageNumber] .= $drawText(42, $cursorY - 24, 'No application records were found for this school.', 9);
    } else {
        foreach ($adminApplicationsForReport as $application) {
            $rowHeight = 36;
            if ($cursorY - $rowHeight < 42) {
                $cursorY = $startPage(false);
            }
            $rowFill = $rowIndex % 2 === 0 ? '0.96 0.98 0.99' : '1 1 1';
            $x = 36;
            foreach ($columns as $column) {
                $width = $column[2];
                $pageContents[$pageNumber] .= $drawRect($x, $cursorY - $rowHeight, $width, $rowHeight, $rowFill, '0.82 0.87 0.9');
                $maxCharacters = max(8, (int)floor(($width - 10) / 4.1));
                $cellValue = $application[$column[1]] ?? '';
                if ($column[1] === 'created_at' && $cellValue !== '') {
                    $cellValue = date('M d, Y', strtotime($cellValue));
                }
                $lines = $wrapCell($cellValue, $maxCharacters);
                foreach ($lines as $lineIndex => $line) {
                    $pageContents[$pageNumber] .= $drawText($x + 5, $cursorY - 14 - ($lineIndex * 10), $line, 7.2);
                }
                $x += $width;
            }
            $cursorY -= $rowHeight;
            $rowIndex++;
        }
    }

    foreach ($pageContents as $index => &$pageContent) {
        $pageContent .= $drawText(36, 22, 'Confidential - for school coordination use only', 7);
        $pageContent .= $drawText(700, 22, 'Page ' . $index . ' of ' . count($pageContents), 7);
    }
    unset($pageContent);

    $pdfObjects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $pageReferences = [];
    foreach ($pageContents as $pageContent) {
        $contentObjectId = count($pdfObjects) + 1;
        $pdfObjects[$contentObjectId] = '<< /Length ' . strlen($pageContent) . " >>\nstream\n" . $pageContent . "endstream";
        $pageObjectId = $contentObjectId + 1;
        $pdfObjects[$pageObjectId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 792 612] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
        $pageReferences[] = $pageObjectId . ' 0 R';
    }
    $pdfObjects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pageReferences) . ' >>';
    ksort($pdfObjects);
    $pdf = "%PDF-1.4\n";
    $objectOffsets = [0];
    foreach ($pdfObjects as $objectId => $objectBody) {
        $objectOffsets[$objectId] = strlen($pdf);
        $pdf .= $objectId . " 0 obj\n" . $objectBody . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $objectCount = count($pdfObjects) + 1;
    $pdf .= "xref\n0 $objectCount\n0000000000 65535 f \n";
    for ($objectId = 1; $objectId < $objectCount; $objectId++) {
        $pdf .= sprintf("%010d 00000 n \n", $objectOffsets[$objectId]);
    }
    $pdf .= "trailer\n<< /Size $objectCount /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="practicore-school-applications.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if (isset($_GET['export_applicants']) && $role === 'company' && $companyApprovalStatus === 'Approved' && $db) {
    $safeCurrentCompanyName = $db->real_escape_string(html_entity_decode($name, ENT_QUOTES, 'UTF-8'));
    $exportQ = $db->query("SELECT a.student_name, a.student_email, a.student_school, a.student_course, a.job_title, a.status, a.attachment_name, a.student_notes, a.created_at,
        (SELECT GROUP_CONCAT(sd.label ORDER BY sd.label SEPARATOR ', ')
         FROM application_documents ad
         INNER JOIN student_documents sd ON sd.id=ad.student_document_id
         WHERE ad.application_id=a.id) AS shared_documents
        FROM applications a
        WHERE a.company_email='$safeEmail' OR (a.company_email='' AND a.company_name='$safeCurrentCompanyName')
        ORDER BY a.id DESC");
    if (!$exportQ) {
        http_response_code(500);
        exit('Unable to export applicant list.');
    }

    $applicantRows = [];
    while ($applicant = $exportQ->fetch_assoc()) $applicantRows[] = $applicant;
    $pdfText = static function ($value) {
        $text = trim(preg_replace('/[\r\n\t]+/', ' ', (string)$value));
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
            $text = $converted !== false ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $text);
        }
        return str_replace(["\\", "(", ")", "\0"], ["\\\\", "\\(", "\\)", ""], $text);
    };
    $drawText = static function ($x, $y, $text, $fontSize = 8, $bold = false, $color = '0 0 0') use ($pdfText) {
        $font = $bold ? 'F2' : 'F1';
        return "$color rg\nBT /$font $fontSize Tf 1 0 0 1 $x $y Tm (" . $pdfText($text) . ") Tj ET\n";
    };
    $drawRect = static function ($x, $y, $width, $height, $fill, $stroke = null) {
        $command = $fill !== null ? $fill . " rg\n" : '';
        if ($stroke !== null) $command .= $stroke . " RG\n";
        $command .= "$x $y $width $height re ";
        $command .= $fill !== null && $stroke !== null ? "B\n" : ($fill !== null ? "f\n" : "S\n");
        return $command;
    };
    $wrapCell = static function ($value, $maxCharacters) {
        $text = trim(preg_replace('/\s+/', ' ', (string)$value));
        if ($text === '') return [''];
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', $text) as $word) {
            while (strlen($word) > $maxCharacters) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $lines[] = substr($word, 0, $maxCharacters - 1) . '-';
                $word = substr($word, $maxCharacters - 1);
            }
            if ($line !== '' && strlen($line) + strlen($word) + 1 > $maxCharacters) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $line === '' ? $word : $line . ' ' . $word;
            }
        }
        if ($line !== '') $lines[] = $line;
        if (count($lines) > 2) {
            $lines = array_slice($lines, 0, 2);
            $lines[1] = rtrim(substr($lines[1], 0, max(0, $maxCharacters - 3))) . '...';
        }
        return $lines;
    };
    $columns = [
        ['Student Name', 'student_name', 78],
        ['Email', 'student_email', 100],
        ['School', 'student_school', 95],
        ['Course', 'student_course', 48],
        ['Position', 'job_title', 100],
        ['Status', 'status', 82],
        ['Documents', 'documents', 95],
        ['Notes', 'student_notes', 120],
        ['Applied At', 'created_at', 78],
    ];
    $pages = [];
    $pageNumber = 0;
    $startPage = static function () use (&$pages, &$pageNumber, $drawText, $drawRect, $columns, $name, $school, $applicantRows) {
        $pageNumber++;
        $content = $drawRect(0, 0, 842, 595, '1 1 1');
        $content .= $drawRect(0, 535, 842, 60, '0.04 0.32 0.48');
        $content .= $drawText(36, 563, 'PRACTICORE  |  COMPANY REPORT', 9, true, '1 1 1');
        $content .= $drawText(36, 541, 'Student Applicants', 16, true, '1 1 1');
        $content .= $drawText(36, 518, 'Company: ' . html_entity_decode($name, ENT_QUOTES, 'UTF-8'), 8);
        $content .= $drawText(36, 504, 'Target School: ' . html_entity_decode($school, ENT_QUOTES, 'UTF-8'), 8);
        $content .= $drawText(36, 490, 'Generated: ' . date('F j, Y  g:i A') . '    Applicants: ' . count($applicantRows), 8);
        $headerTop = 470;
        $x = 36;
        foreach ($columns as $column) {
            $content .= $drawRect($x, $headerTop - 25, $column[2], 25, '0.08 0.38 0.53', '1 1 1');
            $content .= $drawText($x + 4, $headerTop - 16, $column[0], 6.8, true, '1 1 1');
            $x += $column[2];
        }
        $pages[$pageNumber] = $content;
        return $headerTop - 25;
    };

    $cursorY = $startPage();
    if (!$applicantRows) {
        $pages[$pageNumber] .= $drawText(42, $cursorY - 24, 'No student applications found.', 9);
    } else {
        foreach ($applicantRows as $rowIndex => $applicant) {
            if ($cursorY - 34 < 42) $cursorY = $startPage();
            $documentNames = trim(implode(', ', array_filter([
                $applicant['attachment_name'] ?? '',
                $applicant['shared_documents'] ?? '',
            ])));
            $row = $applicant;
            $row['documents'] = $documentNames !== '' ? $documentNames : 'None attached';
            $row['created_at'] = !empty($applicant['created_at']) ? date('M d, Y', strtotime($applicant['created_at'])) : '';
            $rowFill = $rowIndex % 2 === 0 ? '0.95 0.97 0.98' : '1 1 1';
            $x = 36;
            foreach ($columns as $column) {
                $width = $column[2];
                $pages[$pageNumber] .= $drawRect($x, $cursorY - 34, $width, 34, $rowFill, '0.82 0.87 0.9');
                $lines = $wrapCell($row[$column[1]] ?? '', max(8, (int)floor(($width - 8) / 4.2)));
                foreach ($lines as $lineIndex => $line) {
                    $pages[$pageNumber] .= $drawText($x + 4, $cursorY - 13 - ($lineIndex * 10), $line, 6.8);
                }
                $x += $width;
            }
            $cursorY -= 34;
        }
    }

    foreach ($pages as $pageIndex => &$pageContent) {
        $pageContent .= $drawText(36, 20, 'Confidential - for hiring purposes only', 7);
        $pageContent .= $drawText(746, 20, 'Page ' . $pageIndex . ' of ' . count($pages), 7);
    }
    unset($pageContent);

    $pdfObjects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $pageReferences = [];
    foreach ($pages as $pageContent) {
        $contentObjectId = count($pdfObjects) + 1;
        $pdfObjects[$contentObjectId] = '<< /Length ' . strlen($pageContent) . " >>\nstream\n" . $pageContent . "endstream";
        $pageObjectId = $contentObjectId + 1;
        $pdfObjects[$pageObjectId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
        $pageReferences[] = $pageObjectId . ' 0 R';
    }
    $pdfObjects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageReferences) . '] /Count ' . count($pageReferences) . ' >>';
    ksort($pdfObjects);
    $pdf = "%PDF-1.4\n";
    $objectOffsets = [0];
    foreach ($pdfObjects as $objectId => $objectBody) {
        $objectOffsets[$objectId] = strlen($pdf);
        $pdf .= $objectId . " 0 obj\n" . $objectBody . "\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $objectCount = count($pdfObjects) + 1;
    $pdf .= "xref\n0 $objectCount\n0000000000 65535 f \n";
    for ($objectId = 1; $objectId < $objectCount; $objectId++) $pdf .= sprintf("%010d 00000 n \n", $objectOffsets[$objectId]);
    $pdf .= "trailer\n<< /Size $objectCount /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="practicore-applicants.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucfirst($role); ?> Portal — Practicore</title>
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
        /* Keep native conversation buttons inside the dashboard glass system. */
        .conversation-thread-heading { background: rgba(255,255,255,.045) !important; color: inherit !important; border-radius: 10px !important; padding: .65rem !important; }
        .conversation-thread-heading:hover { background: rgba(99,102,241,.16) !important; }
        .conversation-thread { background: transparent; border: 0; padding: 0; }
        #profileModal .modal-dialog { max-width: 860px; }
        #profileModal .glass-card { min-width: 0; overflow: hidden; }
        #profileModal #profileResumeName, #profileModal #profileResumeHeadline, #profileModal .text-muted { overflow-wrap: anywhere; word-break: break-word; }
        #profileAvatarPreview { cursor: pointer; transition: transform .2s ease, box-shadow .2s ease; }
        #profileAvatarPreview:hover { transform: scale(1.04); box-shadow: 0 0 0 4px rgba(34,211,238,.25); }
        .module-pdf-page { min-height:420px; display:flex; flex-direction:column; align-items:center; justify-content:center; overflow:hidden; background:#111827; }
        .module-pdf-page canvas { display:block; max-width:100%; height:auto !important; box-shadow:0 8px 22px rgba(0,0,0,.35); }
        .achievement-certificate { position:relative; overflow:hidden; border:1px solid rgba(250,204,21,.55); background:linear-gradient(135deg,rgba(120,83,10,.42),rgba(30,41,59,.85)); box-shadow:inset 0 0 0 3px rgba(250,204,21,.12); }
        .achievement-certificate::after { content:'CERTIFIED'; position:absolute; right:-.7rem; bottom:.1rem; color:rgba(250,204,21,.13); font-size:1.45rem; font-weight:800; transform:rotate(-18deg); }
        @media (max-width: 991.98px) {
            .modal-dialog { margin: .75rem auto; max-width: calc(100% - 1.5rem) !important; }
            .modal-body { padding: 1rem !important; }
            .glass-card { overflow-wrap: anywhere; }
        }
        @media (max-width: 575.98px) {
            .navbar-glass { padding: .55rem 0; }
            .dashboard-navbar-container { flex-wrap: wrap; }
            .dashboard-navbar-controls { width: 100%; margin-left: 0; justify-content: space-between; }
            .dashboard-navbar-button-group { gap: .25rem; }
            .modal-header, .modal-footer { padding: .85rem 1rem; }
            .modal-title { font-size: 1.1rem; }
            .table-responsive { font-size: .78rem; }
            .btn { white-space: normal; }
            .scd-profile-card, .scd-profile-main { flex-wrap: wrap; }
            .notif-dropdown { position: fixed; top: 4.4rem; right: .75rem; left: .75rem; width: auto; max-height: calc(100vh - 5.25rem); overflow-y: auto; }
        }
    </style>
</head>
<body>

    <!-- Ambient Glowing Background Elements -->
    <div class="bg-ambient">
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
    </div>

    <!-- Glass Navbar with Official Logo -->
    <nav class="navbar navbar-glass sticky-top">
        <div class="container dashboard-navbar-container">
            <a class="navbar-brand" href="feed.php" title="Open community feed">
                <img class="brand-logo" src="logo.svg?v=20261029" alt="Practicore" style="height: 38px; width: 159px;">
            </a>

            <div class="d-flex align-items-center gap-2 dashboard-navbar-controls">
                <div class="dashboard-navbar-button-group">

                <!-- Notification Bell -->
                <div class="position-relative d-inline-block" id="notifBellWrap">
                    <button class="btn btn-glass btn-glass-sm position-relative" id="notifBellBtn" onclick="toggleNotifDropdown()" title="Notifications">
                        <i class="bi bi-bell-fill"></i>
                        <?php if ($notifCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-pulse" style="font-size:0.62rem;" id="notifUnreadBadge">
                                <?php echo min($notifCount, 99); ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <!-- Notification Dropdown -->
                    <div class="notif-dropdown glass-card" id="notifDropdown" style="display:none;">
                        <div class="notif-dropdown-header">
                            <span class="fw-bold text-white small"><i class="bi bi-bell-fill me-1 text-warning"></i>Notifications</span>
                            <?php if ($notifCount > 0): ?><span class="badge bg-danger" style="font-size:0.62rem;" id="notifUnreadHeaderBadge"><?php echo $notifCount; ?></span><?php endif; ?>
                        </div>
                        <?php if (empty($notifications)): ?>
                            <div class="notif-empty">
                                <i class="bi bi-check-circle text-success d-block mb-1" style="font-size:1.4rem;"></i>
                                <span class="text-muted small">All caught up!</span>
                            </div>
                        <?php else: ?>
                            <?php foreach ($notifications as $notif): ?>
                            <div class="notif-item notif-type-<?php echo $notif['type']; ?>"
                                 onclick="<?php echo $notif['type']==='message' ? 'closeNotifDropdown(); document.querySelector(\'[data-bs-target=\\\"#messagesModal\\\"]\').click();' : ''; ?>">
                                <i class="bi <?php echo $notif['icon']; ?> <?php echo $notif['color']; ?> me-2"></i>
                                <span class="small text-white"><?php echo $notif['text']; ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <div class="notif-footer">
                            <form method="POST" action="" class="d-inline">
                                <input type="hidden" name="action" value="mark_messages_read">
                                <input type="hidden" name="mark_notifications" value="1">
                                <button type="submit" class="btn btn-link text-muted p-0" style="font-size:0.72rem;">Mark all notifications as read</button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Direct Messages Badge (unread only) -->
                <button class="btn btn-glass btn-glass-sm position-relative" data-bs-toggle="modal" data-bs-target="#messagesModal" title="Direct Messages">
                    <i class="bi bi-chat-dots-fill"></i>
                    <?php if ($unreadMsgCount > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-pulse" style="font-size:0.65rem;" id="msgUnreadBadge">
                            <?php echo $unreadMsgCount; ?>
                        </span>
                    <?php endif; ?>
                </button>

                <!-- Profile Customization -->
                <button class="btn btn-glass btn-glass-sm p-1" data-bs-toggle="modal" data-bs-target="#profileModal" title="Edit Profile & Avatar">
                    <?php if (!empty($avatar)): ?>
                        <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Avatar" class="rounded-circle" style="width: 26px; height: 26px; object-fit: cover;">
                    <?php else: ?>
                        <i class="bi bi-person-circle fs-6 px-1"></i>
                    <?php endif; ?>
                </button>

                </div>

                <div class="d-none d-sm-block text-center dashboard-navbar-identity">
                    <div class="fw-bold text-white small text-truncate dashboard-navbar-name"><?php echo $name; ?></div>
                    <div class="badge bg-primary bg-opacity-25 text-primary text-uppercase" style="font-size:0.68rem;"><?php echo $role; ?> • <?php echo $school; ?></div>
                </div>

                <div class="dashboard-navbar-button-group">
                <a href="feed.php" class="btn btn-glass btn-glass-sm" title="Back to Newsfeed">
                    <i class="bi bi-house-door-fill me-1"></i> Home
                </a>
                <a href="logout.php" class="btn btn-glass btn-glass-sm text-danger" title="Logout">
                    <i class="bi bi-box-arrow-right me-1"></i> Logout
                </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        
        <!-- Welcome Hub Panel -->
        <div class="glass-panel p-4 p-md-5 mb-4">
            <div class="row align-items-center g-3">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <?php if (!empty($avatar)): ?>
                            <img src="<?php echo htmlspecialchars($avatar); ?>" alt="Avatar" class="rounded-circle border border-2 border-primary shadow-sm" style="width: 64px; height: 64px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-primary bg-opacity-25 text-primary d-flex align-items-center justify-content-center fw-bold fs-3 border border-primary border-opacity-50" style="width: 64px; height: 64px;">
                                <?php echo strtoupper(substr($name, 0, 2)); ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="badge bg-primary bg-opacity-25 text-primary text-uppercase px-3 py-1 rounded-pill">
                                    <?php echo $role; ?> Portal
                                </span>
                                <span class="badge bg-info bg-opacity-25 text-info px-3 py-1 rounded-pill">
                                    <i class="bi bi-geo-alt-fill me-1"></i> Designated: <?php echo $school; ?>
                                </span>
                                <?php if ($role === 'student'): ?>
                                    <span class="badge bg-success bg-opacity-25 text-success px-3 py-1 rounded-pill">
                                        <i class="bi bi-mortarboard-fill me-1"></i> Course: <?php echo $course; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h2 class="fw-bold text-white mb-0">Mabuhay, <?php echo $name; ?>! 👋</h2>
                        </div>
                    </div>
                    <?php if (!empty($bio)): ?>
                        <div class="text-info small fst-italic mb-2"><i class="bi bi-quote me-1"></i><?php echo htmlspecialchars($bio); ?></div>
                    <?php endif; ?>
                    <p class="text-muted mb-0 small">
                        <?php if ($role === 'student'): ?>
                            Your personalized OJT station for <strong><?php echo $school; ?></strong>. Apply to host companies, manage university clearance, and log verified hours.
                        <?php elseif ($role === 'company'): ?>
                            Welcome to your employer hub for <strong><?php echo $school; ?></strong>. Publish openings, review student attachments, and approve supervisor DTRs.
                        <?php else: ?>
                            Managing academic practicum placement, student clearance checklists, and training guides for <strong><?php echo $school; ?></strong>.
                        <?php endif; ?>
                    </p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="p-3 rounded bg-dark bg-opacity-50 d-inline-block text-start" style="min-width: 240px;">
                        <div class="small text-muted mb-1"><i class="bi bi-shield-check text-success me-1"></i> Campus Practicum Network</div>
                        <div class="fw-bold text-white"><?php echo $school; ?></div>
                        <div class="small text-muted" style="font-size: 0.78rem;">Connected with OJT Placement Office</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================
             1. STUDENT DASHBOARD VIEW
             ======================================================== -->
        <?php if ($role === 'student'): ?>
            <div class="row g-4">
                
                <!-- Left Column: Search & Real Postings from Designated School -->
                <div class="col-lg-8">
                    
                    <!-- Student Internship Availability / "Looking for Company" Notice (Proposal 4.1 & 5) -->
                    <?php if ($myNotice): ?>
                        <div class="glass-card p-4 mb-4 border border-primary border-opacity-50">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="badge bg-primary mb-1"><i class="bi bi-broadcast-pin me-1"></i> Active "Looking for Company" Notice</span>
                                    <h6 class="fw-bold text-white mb-0"><?php echo htmlspecialchars($myNotice['headline']); ?></h6>
                                </div>
                                <form method="POST" action="" onsubmit="return confirm('Remove your availability notice?');">
                                    <input type="hidden" name="action" value="delete_student_notice">
                                    <button type="submit" class="btn btn-sm btn-glass text-danger" title="Delete Notice">
                                        <i class="bi bi-trash3 me-1"></i> Remove
                                    </button>
                                </form>
                            </div>
                            <div class="small text-muted mb-2">
                                <span class="text-white"><i class="bi bi-briefcase me-1"></i> Target: <?php echo htmlspecialchars($myNotice['preferred_role']); ?></span>
                                <span class="mx-2">•</span>
                                <span class="text-info"><i class="bi bi-clock me-1"></i> <?php echo htmlspecialchars($myNotice['availability']); ?></span>
                            </div>
                            <div class="small text-muted mb-2">
                                <strong class="text-white">Skills:</strong> <?php echo htmlspecialchars($myNotice['skills']); ?>
                            </div>
                            <?php if (!empty($myNotice['message'])): ?>
                                <p class="small text-muted mb-0 fst-italic">"<?php echo htmlspecialchars($myNotice['message']); ?>"</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-3 rounded bg-dark bg-opacity-50 border border-primary border-opacity-25 d-flex flex-column flex-md-row justify-content-between align-items-center gap-3 mb-4">
                            <div>
                                <h6 class="text-white fw-bold mb-1"><i class="bi bi-megaphone-fill text-primary me-2"></i>Post "Looking for Company" Notice</h6>
                                <p class="text-muted small mb-0">Allow accredited partner employers in <?php echo $school; ?> to scout your profile and invite you to apply.</p>
                            </div>
                            <button class="btn btn-gradient btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#postNoticeModal">
                                <i class="bi bi-plus-circle me-1"></i> Post Availability Notice
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- Search within designated school -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-buildings-fill text-primary me-2"></i> OJT Openings for <?php echo $school; ?>
                            </h5>
                            <span class="badge bg-success bg-opacity-25 text-success"><?php echo count($studentJobs); ?> Active Posting(s)</span>
                        </div>

                        <!-- Live Search Input -->
                        <div class="glass-input-group mb-3">
                            <i class="bi bi-search"></i>
                            <input type="text" id="studentJobSearch" class="glass-input" placeholder="Filter by skill, role, or company..." onkeyup="filterStudentJobs()">
                        </div>

                        <!-- Real Internship Cards Posted for Designated School -->
                        <div class="d-flex flex-column gap-3" id="studentJobList">
                            
                            <?php if (empty($studentJobs)): ?>
                                <div class="p-4 rounded bg-dark bg-opacity-50 text-center border border-secondary border-opacity-25">
                                    <i class="bi bi-buildings text-muted fs-1 mb-2 d-block"></i>
                                    <h6 class="text-white fw-bold mb-1">No OJT Openings Posted Yet for <?php echo $school; ?></h6>
                                    <p class="text-muted small mb-0">
                                        When partner companies register and publish OJT vacancies for <?php echo $school; ?>, they will immediately appear here for you to apply!
                                    </p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($studentJobs as $job): 
                                    $jobKey = $job['company_name'] . '|' . $job['title'];
                                    $isApplied = isset($appliedJobKeys[$jobKey]);
                                    $appStatus = $isApplied ? $appliedJobKeys[$jobKey] : '';
                                    $isFav = isset($myFavorites[$job['id']]);
                                ?>
                                    <div class="p-3 rounded bg-dark bg-opacity-50 border border-secondary border-opacity-25 student-job-card" 
                                         data-title="<?php echo htmlspecialchars($job['title']); ?>" 
                                         data-company="<?php echo htmlspecialchars($job['company_name']); ?>" 
                                         data-skills="<?php echo htmlspecialchars($job['skills']); ?>">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="fw-bold text-white mb-1"><?php echo htmlspecialchars($job['title']); ?></h6>
                                                <div class="small text-muted"><i class="bi bi-building me-1"></i> <?php echo htmlspecialchars($job['company_name']); ?></div>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <!-- Favorite Button -->
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_favorite">
                                                    <input type="hidden" name="job_id" value="<?php echo (int)$job['id']; ?>">
                                                    <input type="hidden" name="company_name" value="<?php echo htmlspecialchars($job['company_name']); ?>">
                                                    <input type="hidden" name="job_title" value="<?php echo htmlspecialchars($job['title']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-glass text-warning" title="<?php echo $isFav ? 'Remove Favorite' : 'Save Favorite'; ?>">
                                                        <i class="bi <?php echo $isFav ? 'bi-star-fill' : 'bi-star'; ?>"></i>
                                                    </button>
                                                </form>

                                                <!-- Report Button -->
                                                <button class="btn btn-sm btn-glass text-muted" onclick="openReportModal('<?php echo htmlspecialchars($job['company_name']); ?>')" title="Report this listing">
                                                    <i class="bi bi-flag"></i>
                                                </button>

                                                <span class="badge bg-success text-white"><?php echo htmlspecialchars($job['allowance']); ?></span>
                                            </div>
                                        </div>
                                        <div class="small text-muted mb-2">
                                            <span class="badge bg-primary bg-opacity-25 text-primary me-1">Target: <?php echo htmlspecialchars($job['school']); ?></span>
                                            <span class="badge bg-info bg-opacity-25 text-info me-1">Course: <?php echo htmlspecialchars($job['course']); ?></span>
                                            <span class="badge bg-dark"><?php echo (int)$job['slots']; ?> Slot(s)</span>
                                            <?php if (!empty($job['deadline'])): ?><span class="badge bg-warning text-dark">Apply by <?php echo date('M d, Y', strtotime($job['deadline'])); ?></span><?php endif; ?>
                                        </div>
                                        <?php if (!empty($job['description'])): ?><p class="small text-muted mb-2"><?php echo nl2br(htmlspecialchars($job['description'])); ?></p><?php endif; ?>
                                        
                                        <!-- Custom Required Documents Specified by Company -->
                                        <div class="p-2 rounded bg-black bg-opacity-40 small mb-3 border border-secondary border-opacity-25">
                                            <strong class="text-info"><i class="bi bi-paperclip me-1"></i>Required Documents to Attach:</strong>
                                            <span class="text-white"><?php echo htmlspecialchars($job['required_documents'] ?? 'Resume / CV'); ?></span>
                                        </div>

                                        <?php if (!empty($job['skills'])): ?>
                                            <p class="small text-muted mb-3">
                                                <strong class="text-white">Required Skills:</strong> <?php echo htmlspecialchars($job['skills']); ?>
                                            </p>
                                        <?php endif; ?>

                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="small text-muted"><i class="bi bi-clock me-1"></i> Posted <?php echo date('M d, Y', strtotime($job['created_at'])); ?></span>
                                            
                                            <?php if ($isApplied): ?>
                                                <span class="badge bg-success py-2 px-3">
                                                    <i class="bi bi-check-circle-fill me-1"></i> <?php echo htmlspecialchars($appStatus); ?>
                                                </span>
                                            <?php else: ?>
                                                <button class="btn btn-gradient btn-sm" onclick="openApplyAttachmentModal(<?php echo (int)$job['id']; ?>, '<?php echo htmlspecialchars(addslashes($job['title'])); ?>', '<?php echo htmlspecialchars(addslashes($job['company_name'])); ?>', '<?php echo htmlspecialchars(addslashes($job['company_email'])); ?>', '<?php echo htmlspecialchars(addslashes($job['required_documents'] ?? 'Resume / CV')); ?>')">
                                                    <i class="bi bi-send-fill me-1"></i> Apply & Attach Documents
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0"><i class="bi bi-send-check-fill text-info me-2"></i>My Applications</h5>
                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo count($studentApplications); ?> Application(s)</span>
                        </div>
                        <?php if (empty($studentApplications)): ?>
                            <p class="small text-muted mb-0">Your submitted company applications and their status will appear here.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-glass small mb-0">
                                    <thead><tr><th>Company</th><th>Position</th><th>Status</th><th>Submitted</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($studentApplications as $studentApplication): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($studentApplication['company_name']); ?></td>
                                            <td><?php echo htmlspecialchars($studentApplication['job_title']); ?></td>
                                            <td><span class="badge <?php echo $studentApplication['status'] === 'Accepted & Endorsed' ? 'bg-success' : ($studentApplication['status'] === 'Rejected' ? 'bg-danger' : ($studentApplication['status'] === 'Shortlisted' ? 'bg-info text-dark' : 'bg-warning text-dark')); ?>"><?php echo htmlspecialchars($studentApplication['status']); ?></span></td>
                                            <td><?php echo date('M d, Y', strtotime($studentApplication['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Coordinator-Approved Company Offers (search by name & course) -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-search-heart text-info me-2"></i> Company Openings Offered to You
                            </h5>
                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo count($approvedOffers); ?> Offer(s) · <?php echo count($affiliatedCompanies); ?> Partner(s)</span>
                        </div>
                        <p class="text-muted small mb-3">Browse coordinator-approved openings and affiliated companies broadcasting to <strong><?php echo $school; ?></strong>. You can apply directly or message a broadcasting company.</p>

                        <!-- Filter search: company name + course -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-7">
                                <div class="glass-input-group mb-0">
                                    <i class="bi bi-building"></i>
                                    <input type="text" id="offerNameSearch" class="glass-input" placeholder="Search company or role..." onkeyup="filterStudentOffers()">
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="glass-input-group mb-0">
                                    <i class="bi bi-funnel"></i>
                                    <input type="text" id="offerCourseSearch" class="glass-input" placeholder="Filter by course (e.g. BSIT)" onkeyup="filterStudentOffers()">
                                </div>
                            </div>
                        </div>

                        <?php if (empty($approvedOffers) && empty($affiliatedCompanies)): ?>
                            <div class="p-3 rounded bg-dark bg-opacity-50 text-center text-muted small">
                                <i class="bi bi-hourglass-split me-1"></i> No approved offers or broadcast affiliated companies are available for your institution yet.
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2" id="studentOfferList">
                                <?php foreach ($approvedOffers as $of): ?>
                                    <div class="p-3 rounded bg-dark bg-opacity-50 border border-info border-opacity-25 student-offer-card"
                                         data-company="<?php echo htmlspecialchars(strtolower($of['company_name'])); ?>"
                                         data-title="<?php echo htmlspecialchars(strtolower($of['title'])); ?>"
                                         data-course="<?php echo htmlspecialchars(strtolower($of['course'])); ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="fw-bold text-white mb-1"><i class="bi bi-building-check text-info me-1"></i><?php echo htmlspecialchars($of['company_name']); ?></h6>
                                                <div class="text-info small fw-semibold"><?php echo htmlspecialchars($of['title']); ?></div>
                                            </div>
                                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo htmlspecialchars($of['course']); ?></span>
                                        </div>
                                        <?php if (!empty($of['description'])): ?>
                                            <p class="small text-muted mt-2 mb-1"><?php echo htmlspecialchars($of['description']); ?></p>
                                        <?php endif; ?>
                                        <div class="small text-muted"><i class="bi bi-people me-1"></i><?php echo (int)$of['slots']; ?> slot(s) available</div>
                                    </div>
                                <?php endforeach; ?>
                                <?php foreach ($affiliatedCompanies as $partner): ?>
                                    <div class="p-3 rounded bg-dark bg-opacity-50 border border-success border-opacity-25 student-offer-card affiliated-company-offer-card"
                                         data-company="<?php echo htmlspecialchars(strtolower($partner['name'])); ?>"
                                         data-title="<?php echo htmlspecialchars(strtolower($partner['headline'] ?? '')); ?>"
                                         data-course="all courses">
                                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-start gap-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <?php if (!empty($partner['avatar'])): ?>
                                                    <img src="<?php echo htmlspecialchars($partner['avatar']); ?>" alt="<?php echo htmlspecialchars($partner['name']); ?>" class="rounded-circle border border-success border-opacity-50" style="width:48px;height:48px;object-fit:cover;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-success bg-opacity-25 d-flex align-items-center justify-content-center text-success fw-bold flex-shrink-0" style="width:48px;height:48px;"><?php echo strtoupper(substr($partner['name'], 0, 1)); ?></div>
                                                <?php endif; ?>
                                                <div>
                                                    <h6 class="fw-bold text-white mb-1"><i class="bi bi-building-check text-success me-1"></i><?php echo htmlspecialchars($partner['name']); ?></h6>
                                                    <span class="badge bg-success bg-opacity-25 text-success">Affiliated Company</span>
                                                    <?php if (!empty($partner['headline'])): ?><div class="text-info small mt-1"><?php echo htmlspecialchars($partner['headline']); ?></div><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2">
                                                <form method="POST" action="">
                                                    <input type="hidden" name="action" value="toggle_favorite_company">
                                                    <input type="hidden" name="company_email" value="<?php echo htmlspecialchars($partner['email']); ?>">
                                                    <button type="submit" class="btn btn-glass btn-sm text-warning" title="<?php echo isset($favoriteCompanyEmails[$partner['email']]) ? 'Remove favorite' : 'Save favorite'; ?>">
                                                        <i class="bi <?php echo isset($favoriteCompanyEmails[$partner['email']]) ? 'bi-star-fill' : 'bi-star'; ?>"></i>
                                                    </button>
                                                </form>
                                                <?php if (isset($appliedJobKeys[$partner['name'] . '|General OJT Application'])): ?>
                                                    <span class="badge bg-info bg-opacity-25 text-info align-self-center"><?php echo htmlspecialchars($appliedJobKeys[$partner['name'] . '|General OJT Application']); ?></span>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-gradient btn-sm" onclick="openApplyAttachmentModal(0, 'General OJT Application', '<?php echo htmlspecialchars(addslashes($partner['name'])); ?>', '<?php echo htmlspecialchars(addslashes($partner['email'])); ?>', 'Resume / CV or portfolio (optional)')">
                                                        <i class="bi bi-send-fill me-1"></i> Apply
                                                    </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-glass btn-sm" data-bs-toggle="modal" data-bs-target="#messagesModal" onclick="openCompanyConversation('<?php echo htmlspecialchars(addslashes($partner['email'])); ?>', '<?php echo htmlspecialchars(addslashes($partner['name'])); ?>')">
                                                    <i class="bi bi-chat-dots-fill me-1"></i> Message
                                                </button>
                                            </div>
                                        </div>
                                        <?php if (!empty($partner['bio'])): ?>
                                            <p class="small text-muted mt-2 mb-0"><?php echo htmlspecialchars($partner['bio']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($favoriteCompanies) || !empty($favoriteJobs)): ?>
                                <div class="border-top border-secondary border-opacity-25 mt-3 pt-3">
                                    <h6 class="fw-semibold text-white mb-2"><i class="bi bi-star-fill text-warning me-1"></i>Saved Favorites</h6>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($favoriteJobs as $favoriteJob): ?>
                                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo htmlspecialchars($favoriteJob['company_name'] . ' — ' . $favoriteJob['job_title']); ?></span>
                                        <?php endforeach; ?>
                                        <?php foreach ($favoriteCompanies as $favoriteCompany): ?>
                                            <form method="POST" action="" class="d-inline-flex align-items-center gap-1">
                                                <input type="hidden" name="action" value="toggle_favorite_company">
                                                <input type="hidden" name="company_email" value="<?php echo htmlspecialchars($favoriteCompany['company_email']); ?>">
                                                <span class="badge bg-warning bg-opacity-25 text-warning"><?php echo htmlspecialchars($favoriteCompany['company_name']); ?></span>
                                                <button type="submit" class="btn btn-glass btn-sm text-danger" aria-label="Remove favorite company"><i class="bi bi-x-lg"></i></button>
                                            </form>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Daily Time Record (DTR) Logging with Company Supervisor Feedback -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0"><i class="bi bi-clock-history text-warning me-2"></i>Log Daily OJT Hours (DTR)</h5>
                            <span class="small text-muted">Supervisor Review & Comments</span>
                        </div>
                        
                        <form method="POST" action="" class="row g-2 mb-3">
                            <input type="hidden" name="action" value="log_dtr">
                            <div class="col-md-3">
                                <label class="small text-muted d-block">Log Date</label>
                                <input type="date" name="log_date" class="glass-input" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="small text-muted d-block">Hours</label>
                                <input type="number" name="hours" step="0.5" class="glass-input" placeholder="8.0" min="0.5" max="16" required>
                            </div>
                            <div class="col-md-4">
                                <label class="small text-muted d-block">Task Accomplishment</label>
                                <input type="text" name="task_description" class="glass-input" placeholder="e.g. Completed QA test cases" required>
                            </div>
                            <div class="col-md-3">
                                <label class="small text-muted d-block">Host Company</label>
                                <input type="text" name="company_name" list="companySuggestions" class="glass-input" placeholder="Select or type Company" required>
                                <datalist id="companySuggestions">
                                    <?php foreach ($availableCompanies as $cComp): ?>
                                        <option value="<?php echo htmlspecialchars($cComp); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-12 text-end mt-2">
                                <button type="submit" class="btn btn-gradient btn-sm px-4">Submit DTR to Company</button>
                            </div>
                        </form>

                        <div class="table-responsive dtr-table-responsive">
                            <table class="table table-glass small mb-0 dtr-table dtr-student-table">
                                <thead>
                                    <tr>
                                        <th class="dtr-date-heading">Date</th>
                                        <th>Rendered Hours</th>
                                        <th>Task Accomplishment</th>
                                        <th>Company</th>
                                        <th>Status & Supervisor Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dtrLogs)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">
                                                No daily hours logged yet. Use the form above to record your first OJT entry.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($dtrLogs as $log): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($log['log_date'])); ?></td>
                                                <td><strong class="text-white"><?php echo number_format($log['hours'], 1); ?> Hours</strong></td>
                                                <td><?php echo htmlspecialchars($log['task_description']); ?></td>
                                                <td><strong class="text-info"><?php echo htmlspecialchars($log['company_name'] ?? 'Host Company'); ?></strong></td>
                                                <td>
                                                    <?php if ($log['status'] === 'Approved by Supervisor'): ?>
                                                        <span class="badge bg-success mb-1">
                                                            <i class="bi bi-patch-check-fill me-1"></i> Approved by Supervisor
                                                        </span>
                                                    <?php elseif ($log['status'] === 'Revision Requested'): ?>
                                                        <span class="badge bg-danger mb-1">
                                                            <i class="bi bi-exclamation-octagon-fill me-1"></i> Revision Requested
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark mb-1">
                                                            <i class="bi bi-hourglass-split me-1"></i> Pending Supervisor Sign
                                                        </span>
                                                        <div class="text-muted" style="font-size:0.72rem;">Assigned: <?php echo htmlspecialchars($log['company_name'] ?? 'Host Company'); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($log['supervisor_comment'])): ?>
                                                        <div class="text-info small mt-1 p-2 rounded bg-dark bg-opacity-50 border border-info border-opacity-25">
                                                            <i class="bi bi-chat-quote-fill me-1"></i> <strong>Supervisor Feedback:</strong> "<?php echo htmlspecialchars($log['supervisor_comment']); ?>"
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0"><i class="bi bi-folder2-open text-info me-2"></i>My OJT Documents</h5>
                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo count($studentDocuments); ?> File(s)</span>
                        </div>
                        <form method="POST" action="" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
                            <input type="hidden" name="action" value="upload_student_document">
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Document Type</label>
                                <select name="document_label" class="glass-input" required>
                                    <option>Resume / CV</option>
                                    <option>Recommendation Letter</option>
                                    <option>Application Form</option>
                                    <option>Other Requirement</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Choose PDF or image (max 5 MB)</label>
                                <input type="file" name="student_document" class="glass-input" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                            </div>
                            <div class="col-md-2"><button type="submit" class="btn btn-gradient btn-sm w-100"><i class="bi bi-upload me-1"></i>Upload</button></div>
                        </form>
                        <?php if (empty($studentDocuments)): ?>
                            <p class="small text-muted mb-0">Save your resume, recommendation letter, application form, and other OJT files here for reuse.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($studentDocuments as $document): ?>
                                    <div class="d-flex justify-content-between align-items-center gap-2 p-2 rounded bg-dark bg-opacity-50">
                                        <div><a class="text-info text-decoration-none" href="dashboard.php?download_student_document=<?php echo (int)$document['id']; ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text me-1"></i><?php echo htmlspecialchars($document['label']); ?></a><span class="small text-muted ms-2"><?php echo date('M d, Y', strtotime($document['created_at'])); ?></span></div>
                                        <form method="POST" action="" onsubmit="return confirm('Remove this document from your library?');">
                                            <input type="hidden" name="action" value="delete_student_document">
                                            <input type="hidden" name="document_id" value="<?php echo (int)$document['id']; ?>">
                                            <button type="submit" class="btn btn-glass btn-sm text-danger" aria-label="Delete document"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0"><i class="bi bi-lightbulb-fill text-warning me-2"></i>Company Training Recommendations</h5>
                            <span class="badge bg-warning bg-opacity-25 text-warning"><?php echo count($companyTrainingRecommendations); ?> Recommendation(s)</span>
                        </div>
                        <?php if (empty($companyTrainingRecommendations)): ?>
                            <p class="small text-muted mb-0">No company training recommendations have been shared for your course yet.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($companyTrainingRecommendations as $recommendation): ?>
                                    <article class="p-3 rounded bg-dark bg-opacity-50 border border-warning border-opacity-25">
                                        <div class="d-flex justify-content-between gap-2"><h6 class="fw-bold text-white mb-1"><?php echo htmlspecialchars($recommendation['title']); ?></h6><span class="badge bg-warning bg-opacity-25 text-warning align-self-start"><?php echo htmlspecialchars($recommendation['course']); ?></span></div>
                                        <div class="small text-muted mb-2">Suggested by <?php echo htmlspecialchars($recommendation['company_name']); ?></div>
                                        <?php if (!empty($recommendation['description'])): ?><p class="small text-muted mb-2"><?php echo htmlspecialchars($recommendation['description']); ?></p><?php endif; ?>
                                        <?php if (!empty($recommendation['resource_url'])): ?><a href="<?php echo htmlspecialchars($recommendation['resource_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-glass btn-sm text-info"><i class="bi bi-box-arrow-up-right me-1"></i>Open Resource</a><?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Academic Guide Modules (Posted by Designated School Admin) -->
                    <?php
                        // Count only PDF-based modules visible to students
                        $pdfModuleCount = 0;
                        foreach ($adminModules as $_m) {
                            $f = $_m['guide_file'] ?? '';
                            if (!empty($f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf') $pdfModuleCount++;
                        }
                    ?>
                    <div class="glass-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-journal-bookmark-fill text-info me-2"></i> Guided Training Modules for <?php echo $school; ?>
                            </h5>
                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo $pdfModuleCount; ?> Module(s)</span>
                        </div>
                        <p class="text-muted small mb-3">Curated by your academic coordinator for advance study and OJT preparedness.</p>

                        <?php if ($pdfModuleCount === 0): ?>
                            <div class="p-3 rounded bg-dark bg-opacity-50 text-center text-muted small">
                                No specialized training modules published yet by your campus coordinator.
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($adminModules as $modIdx => $mod):
                                    $modGuideFile = $mod['guide_file'] ?? '';
                                    $modGuideLink = $mod['guide_link'] ?? '';
                                    $hasGuide = !empty($modGuideFile) || !empty($modGuideLink);
                                    $isPdfGuide = !empty($modGuideFile) && strtolower(pathinfo($modGuideFile, PATHINFO_EXTENSION)) === 'pdf';
                                    // Only show card to student if it is a PDF guide.
                                    if (!$isPdfGuide) continue;
                                    $modPreviewId = 'modPreview_' . (int)$mod['id'];
                                    $modProgress = $moduleProgress[(int)$mod['id']] ?? ['current_page'=>1, 'hours_earned'=>0, 'is_completed'=>0];
                                    $currentStudyPage = max(1, (int)$modProgress['current_page']);
                                    $earnedModuleHours = min((int)$mod['hours'], (int)$modProgress['hours_earned']);
                                    $moduleComplete = (int)$modProgress['is_completed'] === 1;
                                    $pagesRequired  = (int)($modProgress['pages_required'] ?? 0);
                                ?>
                                    <div class="col-md-6">
                                        <div class="p-3 rounded bg-dark bg-opacity-50 border border-secondary border-opacity-25 h-100 d-flex flex-column">
                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="fw-bold text-white mb-0"><?php echo htmlspecialchars($mod['title']); ?></h6>
                                                    <?php if ($moduleComplete): ?>
                                                        <span class="badge bg-success"><i class="bi bi-trophy-fill me-1"></i>Completed</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-primary"><?php echo (int)$mod['hours']; ?> Hours</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small text-muted mb-2">Level: <?php echo htmlspecialchars($mod['level']); ?> &bull; Course: <?php echo htmlspecialchars($mod['course']); ?></div>
                                                <p class="small text-muted mb-2"><?php echo htmlspecialchars($mod['description']); ?></p>
                                                <?php if ($earnedModuleHours > 0): ?>
                                                    <div class="progress mb-2" style="height:5px;">
                                                        <div class="progress-bar bg-info" style="width:<?php echo min(100, round($earnedModuleHours / max(1,(int)$mod['hours']) * 100)); ?>%"></div>
                                                    </div>
                                                    <div class="small text-muted"><?php echo $earnedModuleHours; ?> / <?php echo (int)$mod['hours']; ?> study hours &bull; Page <?php echo $currentStudyPage; ?><?php echo $pagesRequired > 0 ? ' of '.$pagesRequired : ''; ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <!-- Let's Get Started opens the study reader modal -->
                                            <button type="button" class="btn btn-gradient btn-sm w-100 mt-auto module-start-btn"
                                                data-pdf-src="<?php echo htmlspecialchars($modGuideFile); ?>"
                                                data-module-id="<?php echo (int)$mod['id']; ?>"
                                                data-current-page="<?php echo $currentStudyPage; ?>"
                                                data-pages-required="<?php echo $pagesRequired; ?>"
                                                data-hours="<?php echo (int)$mod['hours']; ?>"
                                                data-earned="<?php echo $earnedModuleHours; ?>"
                                                data-completed="<?php echo $moduleComplete ? '1' : '0'; ?>"
                                                data-title="<?php echo htmlspecialchars($mod['title'], ENT_QUOTES); ?>">
                                                <i class="bi bi-play-circle-fill me-1"></i>
                                                <?php echo $moduleComplete ? 'Review Module Again' : ($currentStudyPage > 1 ? 'Continue Reading' : "Let's Get Started"); ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Right Column: Student Clearance & Dynamic Hour Progress -->
                <div class="col-lg-4">
                    
                    <!-- Dynamic Placement Progress Widget (Calculated from user's DTR logs!) -->
                    <div class="glass-card p-4 mb-4">
                        <h6 class="fw-bold text-white mb-3"><i class="bi bi-pie-chart-fill text-info me-2"></i>OJT Hour Progress</h6>
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Completed Rendered Time</span>
                            <span class="text-white font-monospace"><?php echo number_format($totalRenderedHours, 1); ?> / <?php echo $requiredHours; ?> Hours</span>
                        </div>
                        <div class="progress-glass mb-3">
                            <div class="progress-bar-glow" style="width: <?php echo $hourProgressPct; ?>%;"></div>
                        </div>
                        <div class="small text-muted">
                            <i class="bi bi-info-circle text-info me-1"></i> You need <strong><?php echo number_format($remainingHours, 1); ?> hours</strong> to complete your <?php echo $course; ?> degree practicum requirement.
                        </div>
                    </div>

                    <!-- Student achievements earned by completing school training modules -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-white mb-0"><i class="bi bi-trophy-fill text-warning me-2"></i>Achievements</h6>
                            <span class="badge bg-warning text-dark"><?php echo count($studentAchievements); ?> Earned</span>
                        </div>
                        <?php if (empty($studentAchievements)): ?>
                            <p class="small text-muted mb-0">Complete a guided training module to earn your first achievement.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($studentAchievements as $achievement): ?>
                                    <div class="achievement-certificate p-3 rounded-3">
                                        <div class="text-warning text-uppercase fw-bold" style="font-size:.62rem;letter-spacing:.12em;">Certificate of Completion</div>
                                        <div class="small fw-semibold text-white mt-1"><i class="bi bi-patch-check-fill text-warning me-1"></i><?php echo htmlspecialchars($achievement['title']); ?></div>
                                        <div class="text-muted" style="font-size:.72rem;">Awarded to <?php echo $name; ?> • <?php echo date('M d, Y', strtotime($achievement['completed_at'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Official University Clearance - TOUCHABLE & EDITABLE (Image 2) -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold text-white mb-0"><i class="bi bi-file-earmark-check-fill text-success me-2"></i>Official University Clearance</h6>
                            <span class="badge bg-info bg-opacity-25 text-info" style="font-size:0.7rem;">Click to Submit</span>
                        </div>
                        <p class="text-muted small mb-3">Click any requirement to submit or upload your clearance documents.</p>

                        <div class="list-group list-group-flush small">
                            
                            <?php
                            // Helper: render clearance item row
                            $clearItems = [
                                ['type'=>'moa',         'label'=>'Memorandum of Agreement (MoA)',   'sub'=>'Signed by '.$school.' Coordinator',    'status'=>$myClearance['moa_status'],         'file'=>$myClearance['moa_file']         ?? '', 'accepted'=>'Verified'],
                                ['type'=>'endorsement', 'label'=>'Endorsement Letter',               'sub'=>'University Practicum Endorsement',      'status'=>$myClearance['endorsement_status'], 'file'=>$myClearance['endorsement_file'] ?? '', 'accepted'=>'Verified'],
                                ['type'=>'waiver',      'label'=>"Parents' Consent & Waiver",       'sub'=>'Notarized copy',                        'status'=>$myClearance['waiver_status'],      'file'=>$myClearance['waiver_file']      ?? '', 'accepted'=>'Verified'],
                                ['type'=>'medical',     'label'=>'Medical & Drug Test Clearance',    'sub'=>'Fit to work certification',              'status'=>$myClearance['medical_status'],     'file'=>$myClearance['medical_file']     ?? '', 'accepted'=>'Verified'],
                            ];
                            foreach ($clearItems as $ci):
                                $isAccepted = ($ci['status'] === $ci['accepted']);
                                $isSubmitted = ($ci['status'] === 'Submitted');
                                $isDeclined  = ($ci['status'] === 'Declined');
                                $badgeClass  = $isAccepted ? 'bg-info text-dark' : ($isSubmitted || $ci['status'] === 'Pending' ? 'bg-warning text-dark' : ($isDeclined ? 'bg-danger' : 'bg-secondary'));
                                $hasFile     = !empty($ci['file']);
                                $isLink      = $hasFile && str_starts_with($ci['file'], 'http');
                                $isPdf       = $hasFile && strtolower(pathinfo($ci['file'], PATHINFO_EXTENSION)) === 'pdf';
                            ?>
                            <div class="border-bottom border-secondary border-opacity-25 clearance-document-item">
                                <!-- Clickable row -->
                                <div class="py-2 d-flex justify-content-between align-items-center cursor-pointer clearance-upload-item"
                                     onclick="openClearanceUploadModal('<?php echo $ci['type']; ?>', '<?php echo htmlspecialchars(addslashes($ci['label'])); ?>', '<?php echo htmlspecialchars(addslashes($ci['sub'])); ?>', '<?php echo htmlspecialchars(addslashes($ci['file'])); ?>')">
                                    <div>
                                        <div class="text-white fw-semibold"><?php echo htmlspecialchars($ci['label']); ?></div>
                                        <div class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars($ci['sub']); ?></div>
                                        <?php if ($hasFile): ?><div class="text-info" style="font-size:0.7rem; margin-top:2px;"><i class="bi bi-eye me-1"></i>Tap to view submitted document</div><?php endif; ?>
                                    </div>
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($ci['status']); ?></span>
                                </div>
                                <?php if (false && $hasFile && ($isSubmitted || $isAccepted)): ?>
                                <!-- Inline file preview (collapsed, expandable) -->
                                <div class="clearance-file-preview-strip" id="clearPreview_<?php echo $ci['type']; ?>">
                                    <div class="d-flex justify-content-between align-items-center px-2 py-1" style="background:rgba(0,0,0,0.25);">
                                        <span class="text-muted" style="font-size:0.7rem;"><i class="bi bi-eye me-1"></i>Submitted document</span>
                                        <div class="d-flex gap-2">
                                            <a href="<?php echo htmlspecialchars($ci['file']); ?>" target="_blank" class="text-info" style="font-size:0.7rem;"><i class="bi bi-box-arrow-up-right"></i></a>
                                            <button class="btn p-0 border-0 text-muted" style="font-size:0.7rem;" onclick="toggleClearancePreview('<?php echo $ci['type']; ?>')"><i class="bi bi-chevron-up" id="clearChevron_<?php echo $ci['type']; ?>"></i></button>
                                        </div>
                                    </div>
                                    <div id="clearPreviewBody_<?php echo $ci['type']; ?>" style="max-height:0; overflow:hidden; padding:0;">
                                        <?php if ($isPdf || $isLink): ?>
                                            <?php
                                                $embedUrl = $ci['file'];
                                                if ($isLink && preg_match('/\/file\/d\/([^\/]+)/', $ci['file'], $gdm)) {
                                                    $embedUrl = 'https://drive.google.com/file/d/' . $gdm[1] . '/preview';
                                                }
                                            ?>
                                            <iframe src="<?php echo htmlspecialchars($embedUrl); ?><?php echo ($isPdf && !$isLink) ? '#toolbar=0&navpanes=0&view=FitH' : ''; ?>" style="width:100%;height:160px;border:none;border-radius:6px;" title="Document"></iframe>
                                        <?php else: ?>
                                            <img src="<?php echo htmlspecialchars($ci['file']); ?>" style="max-width:100%;max-height:160px;object-fit:contain;display:block;margin:0 auto;border-radius:6px;" alt="Document">
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                        </div>

                        <?php if ($myClearance['is_completed'] == 1): ?>
                            <div class="alert alert-success py-2 px-3 small mt-3 mb-0 bg-opacity-25 border-success text-success text-center">
                                <i class="bi bi-patch-check-fill me-1"></i> Officially Enrolled & Cleared for Practicum!
                            </div>
                        <?php else: ?>
                            <div class="small text-muted mt-3">
                                <i class="bi bi-info-circle me-1"></i> Once all 4 items are approved by your coordinator, your official enrollment badge activates.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Direct Message Coordinator Shortcut -->
                    <div class="glass-card p-4">
                        <h6 class="fw-bold text-white mb-2"><i class="bi bi-chat-dots-fill text-primary me-2"></i>Coordinator Assistance</h6>
                        <p class="text-muted small mb-3">Send a direct message regarding your MoA or company endorsements.</p>
                        <button class="btn btn-glass btn-sm w-100" data-bs-toggle="modal" data-bs-target="#messagesModal">
                            <i class="bi bi-envelope-plus me-1"></i> Message Campus Coordinator
                        </button>
                    </div>

                </div>

            </div>

        <!-- ========================================================
             2. PARTNER COMPANY DASHBOARD VIEW
             ======================================================== -->
        <?php elseif ($role === 'company'): ?>
            <div class="row g-4">
                
                <!-- Left Column: Manage Job Openings & Post New with Custom Requirements -->
                <div class="col-lg-8">
                    
                    <!-- Post New OJT Vacancy Form with Custom Attachment Selection -->
                    <div class="glass-card p-4 mb-4">
                        <h5 class="fw-bold text-white mb-3">
                            <i class="bi bi-plus-circle-fill text-info me-2"></i> Publish OJT Opening for <?php echo $school; ?>
                        </h5>
                        <p class="text-muted small mb-3">
                            Positions posted here will be immediately dispatched to registered student trainees at <?php echo $school; ?>.
                        </p>
                        
                        <form method="POST" action="" class="row g-3">
                            <input type="hidden" name="action" value="post_job">
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Internship Role Title</label>
                                <input type="text" name="title" class="glass-input" placeholder="e.g. Junior Backend PHP Developer" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Target School Campus</label>
                                <input type="text" name="school" class="glass-input" value="<?php echo $school; ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Job Description</label>
                                <textarea name="description" class="glass-input" rows="3" placeholder="Describe the responsibilities, learning experience, and requirements..." required></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Target Course</label>
                                <select name="course" class="glass-input">
                                    <option value="BSIT">BS Information Technology (BSIT)</option>
                                    <option value="BSCS">BS Computer Science (BSCS)</option>
                                    <option value="CpE">BS Computer Engineering (CpE)</option>
                                    <option value="MMA">Multimedia Arts</option>
                                    <option value="All Tech Courses">All Tech Programs</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Monthly Allowance</label>
                                <input type="text" name="allowance" class="glass-input" placeholder="e.g. ₱8,500 / month" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Slots Available</label>
                                <input type="number" name="slots" class="glass-input" value="3" min="1" max="20" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Application Deadline (optional)</label>
                                <input type="date" name="deadline" class="glass-input" min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Required Competencies / Tech Stack</label>
                                <input type="text" name="skills" class="glass-input" placeholder="e.g. PHP, MySQL, REST API, Git" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Specify Required Documents (Student must attach these when applying)</label>
                                <input type="text" name="required_documents" class="glass-input" value="Resume / CV, Parents Consent & Waiver, Portfolio Link" required>
                            </div>
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-gradient">
                                    <i class="bi bi-broadcast me-1"></i> Broadcast to <?php echo $school; ?> Students
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0"><i class="bi bi-lightbulb-fill text-warning me-2"></i>Share Training Recommendations</h5>
                            <span class="badge bg-warning bg-opacity-25 text-warning"><?php echo count($companyOwnTrainingRecommendations); ?> Shared</span>
                        </div>
                        <p class="small text-muted">Suggest skills, learning guides, or resources to students at <?php echo $school; ?>.</p>
                        <form method="POST" action="" class="row g-2">
                            <input type="hidden" name="action" value="save_training_recommendation">
                            <div class="col-md-6"><label class="form-label small text-muted">Recommendation Title</label><input type="text" name="title" class="glass-input" maxlength="150" required></div>
                            <div class="col-md-6">
                                <label class="form-label small text-muted">Target Course</label>
                                <select name="course" class="glass-input"><option>All Courses</option><option>BSIT</option><option>BSCS</option><option>CpE</option><option>MMA</option></select>
                            </div>
                            <div class="col-12"><label class="form-label small text-muted">Description / Skill Recommendation</label><textarea name="description" class="glass-input" rows="2"></textarea></div>
                            <div class="col-md-9"><label class="form-label small text-muted">Resource Link (optional)</label><input type="url" name="resource_url" class="glass-input" placeholder="https://..."></div>
                            <div class="col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-gradient btn-sm w-100">Share Recommendation</button></div>
                        </form>
                        <?php if (!empty($companyOwnTrainingRecommendations)): ?>
                            <div class="border-top border-secondary border-opacity-25 mt-3 pt-3 d-flex flex-column gap-2">
                                <?php foreach ($companyOwnTrainingRecommendations as $companyRecommendation): ?>
                                    <div class="d-flex justify-content-between align-items-center gap-2"><span class="small text-white"><?php echo htmlspecialchars($companyRecommendation['title']); ?> <span class="text-muted">· <?php echo htmlspecialchars($companyRecommendation['course']); ?></span></span><form method="POST" action=""><input type="hidden" name="action" value="delete_training_recommendation"><input type="hidden" name="recommendation_id" value="<?php echo (int)$companyRecommendation['id']; ?>"><button type="submit" class="btn btn-glass btn-sm text-danger" aria-label="Remove recommendation"><i class="bi bi-trash"></i></button></form></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Screen Real Student Applicants with Submitted Attachments -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h6 class="fw-bold text-white mb-0 flex-grow-1" style="font-size:0.95rem; min-width:180px;">
                                <i class="bi bi-people-fill text-primary me-2"></i> Student Applicants from <?php echo $school; ?>
                            </h6>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-info bg-opacity-25 text-info text-nowrap"><?php echo count($companyApplicants); ?> Applicant(s)</span>
                                <?php if (!empty($companyApplicants)): ?>
                                    <a class="btn btn-glass btn-sm text-nowrap" style="font-size:0.72rem; padding:0.3rem 0.45rem;" href="dashboard.php?export_applicants=1" download><i class="bi bi-file-earmark-pdf me-1"></i>Applicants PDF</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-glass small mb-0">
                                <thead>
                                    <tr>
                                        <th>Student Name</th>
                                        <th>Applied Position</th>
                                        <th>Attached Document / Link</th>
                                        <th>Applicant Notes</th>
                                        <th>Hiring Decision</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($companyApplicants)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">
                                                No student applicants yet. When students from your target schools apply to your postings, they will appear here.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($companyApplicants as $app): ?>
                                            <tr>
                                                <td>
                                                    <strong class="text-white"><?php echo htmlspecialchars($app['student_name']); ?></strong>
                                                    <div class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars($app['student_email']); ?> • <?php echo htmlspecialchars($app['student_course']); ?></div>
                                                </td>
                                                <td><span class="badge bg-primary bg-opacity-25 text-primary"><?php echo htmlspecialchars($app['job_title']); ?></span></td>
                                                <td>
                                                    <?php if (!empty($app['attachment_name'])): ?>
                                                        <?php if (strpos($app['attachment_name'], 'uploads/') === 0): ?>
                                                            <a href="<?php echo htmlspecialchars($app['attachment_name']); ?>" target="_blank" class="badge bg-dark border border-secondary text-info text-decoration-none">
                                                                <i class="bi bi-file-earmark-arrow-down me-1"></i> Open uploaded file
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="badge bg-dark border border-secondary text-info">
                                                                <i class="bi bi-file-earmark-arrow-down me-1"></i> <?php echo htmlspecialchars($app['attachment_name']); ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($app['shared_documents'])): ?>
                                                        <div class="d-flex flex-column align-items-start gap-1 mt-1">
                                                            <?php foreach ($app['shared_documents'] as $sharedDocument): ?>
                                                                <a href="dashboard.php?download_student_document=<?php echo (int)$sharedDocument['document_id']; ?>&amp;application_id=<?php echo (int)$app['id']; ?>" target="_blank" rel="noopener" class="badge bg-dark border border-info text-info text-decoration-none">
                                                                    <i class="bi bi-file-earmark-arrow-down me-1"></i><?php echo htmlspecialchars($sharedDocument['label']); ?>
                                                                </a>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (empty($app['attachment_name']) && empty($app['shared_documents'])): ?>
                                                        <span class="text-muted">None attached</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="small text-muted" style="max-width:200px;">
                                                        <?php echo htmlspecialchars($app['student_notes'] ?? 'No cover notes provided'); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small mb-2"><span class="badge <?php echo $app['status'] === 'Accepted & Endorsed' ? 'bg-success' : ($app['status'] === 'Rejected' ? 'bg-danger' : ($app['status'] === 'Shortlisted' ? 'bg-info text-dark' : 'bg-warning text-dark')); ?>"><?php echo htmlspecialchars($app['status']); ?></span></div>
                                                    <?php if (!in_array($app['status'], ['Accepted & Endorsed', 'Rejected'], true)): ?>
                                                        <form method="POST" action="" class="d-flex flex-wrap gap-1">
                                                            <input type="hidden" name="action" value="update_application_status">
                                                            <input type="hidden" name="application_id" value="<?php echo (int)$app['id']; ?>">
                                                            <button type="submit" name="application_status" value="Shortlisted" class="btn btn-sm btn-glass text-info" title="Shortlist applicant"><i class="bi bi-star me-1"></i>Shortlist</button>
                                                            <button type="submit" name="application_status" value="Accepted & Endorsed" class="btn btn-sm btn-glass text-success" title="Accept applicant"><i class="bi bi-check-lg me-1"></i>Accept</button>
                                                            <button type="submit" name="application_status" value="Rejected" class="btn btn-sm btn-glass text-danger" title="Reject applicant" onclick="return confirm('Reject this application?')"><i class="bi bi-x-lg me-1"></i>Reject</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Student Talent Pool — Looking for Company Notices (Proposal 4.1 & 5) -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-mortarboard-fill text-info me-2"></i> Student Talent Pool (Looking for Company in <?php echo $school; ?>)
                            </h5>
                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo count($studentTalentPool); ?> Available Trainee(s)</span>
                        </div>
                        <p class="text-muted small mb-3">Students from your partner institution who published availability notices seeking OJT placement.</p>

                        <?php if (empty($studentTalentPool)): ?>
                            <div class="p-3 rounded bg-dark bg-opacity-50 text-center text-muted small">
                                No students have posted availability notices for <?php echo $school; ?> yet.
                            </div>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($studentTalentPool as $st): ?>
                                    <div class="col-md-6">
                                        <div class="p-3 rounded bg-dark bg-opacity-50 border border-secondary border-opacity-25 h-100 d-flex flex-column justify-content-between">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-start mb-1">
                                                    <strong class="text-white"><?php echo htmlspecialchars($st['student_name']); ?></strong>
                                                    <span class="badge bg-primary bg-opacity-25 text-primary"><?php echo htmlspecialchars($st['course']); ?></span>
                                                </div>
                                                <div class="text-info small fw-semibold mb-2"><?php echo htmlspecialchars($st['headline']); ?></div>
                                                <div class="small text-muted mb-1"><i class="bi bi-briefcase me-1"></i> <strong>Target:</strong> <?php echo htmlspecialchars($st['preferred_role']); ?></div>
                                                <div class="small text-muted mb-1"><i class="bi bi-clock me-1"></i> <strong>Availability:</strong> <?php echo htmlspecialchars($st['availability']); ?></div>
                                                <div class="small text-muted mb-2"><i class="bi bi-tools me-1"></i> <strong>Skills:</strong> <?php echo htmlspecialchars($st['skills']); ?></div>
                                                <?php if (!empty($st['message'])): ?>
                                                    <p class="small text-muted fst-italic mb-3">"<?php echo htmlspecialchars($st['message']); ?>"</p>
                                                <?php endif; ?>
                                            </div>
                                            <button class="btn btn-glass btn-sm w-100 text-info" data-bs-toggle="modal" data-bs-target="#messagesModal" onclick="replyToUser('<?php echo htmlspecialchars(addslashes($st['student_email'])); ?>', '<?php echo htmlspecialchars(addslashes($st['student_name'])); ?>')">
                                                <i class="bi bi-chat-dots-fill me-1"></i> Scout / Direct Message Student
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Review & Sign Trainee DTR with Company Supervisor Feedback -->
                    <div class="glass-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-clock-history text-warning me-2"></i> Trainee Daily Time Records (DTR) for Review
                            </h5>
                            <span class="badge bg-warning text-dark"><?php echo count($companyDtrReviews); ?> Record(s)</span>
                        </div>
                        <p class="text-muted small mb-3">Review daily hours logged by student trainees assigned to your company. Add comments and approve entries.</p>

                        <div class="table-responsive dtr-table-responsive">
                            <table class="table table-glass small mb-0 dtr-table dtr-company-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Trainee</th>
                                        <th>Hours & Task</th>
                                        <th>Supervisor Feedback</th>
                                        <th>Sign / Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($companyDtrReviews)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">
                                                No DTR entries submitted for your company yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($companyDtrReviews as $dtr): ?>
                                            <tr>
                                                <td><?php echo date('M d, Y', strtotime($dtr['log_date'])); ?></td>
                                                <td>
                                                    <strong class="text-white"><?php echo htmlspecialchars($dtr['student_name'] ?? $dtr['student_email']); ?></strong>
                                                    <div class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars($dtr['student_email']); ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo number_format($dtr['hours'], 1); ?> Hours</span>
                                                    <div class="text-muted small mt-1"><?php echo htmlspecialchars($dtr['task_description']); ?></div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($dtr['supervisor_comment'])): ?>
                                                        <div class="text-info small fst-italic">"<?php echo htmlspecialchars($dtr['supervisor_comment']); ?>"</div>
                                                    <?php else: ?>
                                                        <span class="text-muted">No comment yet</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button class="btn btn-sm btn-glass text-warning" onclick="openDtrReviewModal(<?php echo (int)$dtr['id']; ?>, '<?php echo htmlspecialchars(addslashes($dtr['student_name'] ?? 'Student')); ?>', '<?php echo number_format($dtr['hours'], 1); ?>', '<?php echo htmlspecialchars(addslashes($dtr['task_description'])); ?>')">
                                                        <i class="bi bi-pen me-1"></i> Sign / Comment
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Active Vacancies & Company Status -->
                <div class="col-lg-4">
                    
                    <!-- Active Postings by this Company -->
                    <div class="glass-card p-4 mb-4">
                        <h6 class="fw-bold text-white mb-3"><i class="bi bi-briefcase-fill text-info me-2"></i>Your Published Vacancies</h6>
                        <?php if (empty($companyJobs)): ?>
                            <p class="small text-muted mb-0">No active openings published yet. Use the form to broadcast your first vacancy.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush small">
                                <?php foreach ($companyJobs as $cj): ?>
                                    <div class="py-2 border-bottom border-secondary border-opacity-25">
                                        <div class="fw-bold text-white"><?php echo htmlspecialchars($cj['title']); ?></div>
                                        <div class="text-muted small"><?php echo htmlspecialchars($cj['school']); ?> • <?php echo htmlspecialchars($cj['allowance']); ?></div>
                                        <?php if (!empty($cj['description'])): ?><div class="small text-muted"><?php echo htmlspecialchars($cj['description']); ?></div><?php endif; ?>
                                        <div class="text-info small" style="font-size:0.75rem;">Docs: <?php echo htmlspecialchars($cj['required_documents']); ?></div>
                                        <?php if (!empty($cj['deadline'])): ?><div class="text-warning small">Deadline: <?php echo date('M d, Y', strtotime($cj['deadline'])); ?></div><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="glass-card p-4">
                        <h6 class="fw-bold text-white mb-3"><i class="bi bi-patch-check-fill text-success me-2"></i>Institutional MoA Status</h6>
                        <div class="p-3 rounded bg-dark bg-opacity-50 mb-3">
                            <div class="small text-muted mb-1">Partner Institution</div>
                            <div class="fw-bold text-white mb-2"><?php echo $school; ?></div>
                            <div class="d-flex justify-content-between small text-muted">
                                <span>Accreditation:</span>
                                <span class="text-success font-monospace">Active Partner</span>
                            </div>
                        </div>
                        <p class="small text-muted mb-0">
                            Your organization is authorized to legally host student trainees and assign practicum ratings.
                        </p>
                    </div>

                </div>

            </div>

        <!-- ========================================================
             3. UNIVERSITY COORDINATOR / ADMIN DASHBOARD VIEW
             ======================================================== -->
        <?php else: ?>
            <div class="row g-4">
                
                <!-- Left Column: Coordinated Placement, Clearances & Module Editor -->
                <div class="col-lg-8">
                    
                    <!-- Official Student University Clearance Manager (Image 2 - TOUCHABLE & EDITABLE) -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-file-earmark-check-fill text-success me-2"></i> Official University Clearance Manager (All Campuses)
                            </h5>
                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo count($adminClearanceList); ?> Trainee(s)</span>
                        </div>
                        <p class="text-muted small mb-3">Review, touch, and edit clearance statuses for MoA, Endorsement Letter, Parents' Waiver, and Medical Certification.</p>

                        <div class="table-responsive admin-clearance-table-responsive">
                            <table class="table table-glass small mb-0 admin-clearance-table">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>MoA</th>
                                        <th>Endorsement</th>
                                        <th>Parents Waiver</th>
                                        <th>Medical</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($adminClearanceList)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-3">
                                                No registered students in <?php echo $school; ?> yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($adminClearanceList as $ac): ?>
                                            <tr>
                                                <td>
                                                    <strong class="text-white"><?php echo htmlspecialchars($ac['name']); ?></strong>
                                                    <div class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars($ac['email']); ?></div>
                                                    <div class="text-muted" style="font-size:0.75rem;"><?php echo htmlspecialchars($ac['campus']); ?></div>
                                                    <?php if ($ac['is_completed'] == 1): ?>
                                                        <span class="badge bg-success" style="font-size:0.65rem;"><i class="bi bi-check-all"></i> Enrolled & Cleared</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><span class="badge <?php echo ($ac['moa_status'] ?? 'Pending') === 'Verified' ? 'bg-info text-dark' : (($ac['moa_status'] ?? 'Pending') === 'Pending' ? 'bg-warning text-dark' : 'bg-secondary'); ?>"><?php echo htmlspecialchars($ac['moa_status'] ?? 'Pending'); ?></span></td>
                                                <td><span class="badge <?php echo ($ac['endorsement_status'] ?? 'Pending') === 'Verified' ? 'bg-info text-dark' : (($ac['endorsement_status'] ?? 'Pending') === 'Pending' ? 'bg-warning text-dark' : 'bg-secondary'); ?>"><?php echo htmlspecialchars($ac['endorsement_status'] ?? 'Pending'); ?></span></td>
                                                <td><span class="badge <?php echo ($ac['waiver_status'] ?? 'Pending') === 'Verified' ? 'bg-info text-dark' : (($ac['waiver_status'] ?? 'Pending') === 'Pending' ? 'bg-warning text-dark' : 'bg-secondary'); ?>"><?php echo htmlspecialchars($ac['waiver_status'] ?? 'Pending'); ?></span></td>
                                                <td><span class="badge <?php echo ($ac['medical_status'] ?? 'Pending') === 'Verified' ? 'bg-info text-dark' : (($ac['medical_status'] ?? 'Pending') === 'Pending' ? 'bg-warning text-dark' : 'bg-secondary'); ?>"><?php echo htmlspecialchars($ac['medical_status'] ?? 'Pending'); ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-glass text-warning" onclick="openAdminClearanceEditor('<?php echo htmlspecialchars(addslashes($ac['email'])); ?>', '<?php echo htmlspecialchars(addslashes($ac['name'])); ?>', '<?php echo htmlspecialchars(addslashes($ac['moa_status'] ?? 'Pending')); ?>', '<?php echo htmlspecialchars(addslashes($ac['endorsement_status'] ?? 'Pending')); ?>', '<?php echo htmlspecialchars(addslashes($ac['waiver_status'] ?? 'Pending')); ?>', '<?php echo htmlspecialchars(addslashes($ac['medical_status'] ?? 'Pending')); ?>')">
                                                        <i class="bi bi-pencil-square me-1"></i> Edit Status
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Training & Guide Modules (POST & EDIT by Coordinator) -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-mortarboard-fill text-warning me-2"></i> Publish Training & Study Guides for <?php echo $school; ?>
                            </h5>
                            <button class="btn btn-gradient btn-sm" onclick="openModuleCreateModal()">
                                <i class="bi bi-plus-lg me-1 dashboard-action-icon"></i> Post New Guide Module
                            </button>
                        </div>
                        <p class="text-muted small mb-3">Provide curriculum guidelines and advanced study resources for students prior to or during deployment.</p>

                        <div class="table-responsive">
                            <table class="table table-glass small mb-0">
                                <thead>
                                    <tr>
                                        <th>Module Title</th>
                                        <th>Target Course</th>
                                        <th>Duration</th>
                                        <th>Level</th>
                                        <th>Manage Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($adminModulesList)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-3">
                                                No study modules posted yet. Click "Post New Guide Module" to add guides for your students.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($adminModulesList as $mod): ?>
                                            <tr>
                                                <td>
                                                    <strong class="text-white"><?php echo htmlspecialchars($mod['title']); ?></strong>
                                                    <div class="text-muted small"><?php echo htmlspecialchars($mod['description']); ?></div>
                                                </td>
                                                <td><span class="badge bg-primary bg-opacity-25 text-primary"><?php echo htmlspecialchars($mod['course']); ?></span></td>
                                                <td><?php echo (int)$mod['hours']; ?> Hours</td>
                                                <td><span class="badge bg-dark border"><?php echo htmlspecialchars($mod['level']); ?></span></td>
                                                <td>
                                                    <div class="d-flex gap-1">
                                                        <button class="btn btn-sm btn-glass text-info" onclick="openModuleEditModal(<?php echo (int)$mod['id']; ?>, '<?php echo htmlspecialchars(addslashes($mod['title'])); ?>', '<?php echo htmlspecialchars(addslashes($mod['course'])); ?>', <?php echo (int)$mod['hours']; ?>, '<?php echo htmlspecialchars(addslashes($mod['level'])); ?>', '<?php echo htmlspecialchars(addslashes($mod['description'])); ?>', '<?php echo htmlspecialchars(addslashes($mod['guide_link'])); ?>', '<?php echo htmlspecialchars(addslashes($mod['guide_file'] ?? '')); ?>')">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <form method="POST" action="" onsubmit="return confirm('Delete this module?');" class="d-inline">
                                                            <input type="hidden" name="action" value="delete_module">
                                                            <input type="hidden" name="module_id" value="<?php echo (int)$mod['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-glass text-danger">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Registered Student Trainees in this Campus -->
                    <div class="glass-card p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-people text-primary me-2"></i> All Registered Students for this Institution
                            </h5>
                            <span class="badge bg-info bg-opacity-25 text-info"><?php echo count($adminStudents); ?> Trainee(s)</span>
                        </div>
                        <p class="text-muted small mb-3">Students from every campus with a matching institution name are listed here.</p>

                        <!-- Filter search: by name/email and by course -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-7">
                                <div class="glass-input-group mb-0">
                                    <i class="bi bi-search"></i>
                                    <input type="text" id="adminStudentNameFilter" class="glass-input" placeholder="Search student by name, ID, or email..." onkeyup="filterAdminStudents()">
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="glass-input-group mb-0">
                                    <i class="bi bi-mortarboard"></i>
                                    <input type="text" id="adminStudentCourseFilter" class="glass-input" placeholder="Filter by course (e.g. BSIT)" onkeyup="filterAdminStudents()">
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-glass small mb-0">
                                <thead>
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Course</th>
                                        <th>Campus</th>
                                        <th>Joined Date</th>
                                        <th>OJT Documents</th>
                                    </tr>
                                </thead>
                                <tbody id="adminStudentTableBody">
                                    <?php if (empty($adminStudents)): ?>
                                        <tr><td colspan="7" class="text-center text-muted py-3">No students registered for this institution yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($adminStudents as $st): ?>
                                            <tr class="admin-student-row"
                                                data-name="<?php echo htmlspecialchars(strtolower($st['name'])); ?>"
                                                data-email="<?php echo htmlspecialchars(strtolower($st['email'])); ?>"
                                                data-id="<?php echo htmlspecialchars(strtolower($st['id_number'] ?? '')); ?>"
                                                data-course="<?php echo htmlspecialchars(strtolower($st['course'])); ?>">
                                                <td>
                                                    <?php if (!empty($st['id_number'])): ?>
                                                        <span class="badge bg-dark border border-secondary text-info font-monospace"><i class="bi bi-card-text me-1"></i><?php echo htmlspecialchars($st['id_number']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Not provided</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong class="text-white"><?php echo htmlspecialchars($st['name']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($st['email']); ?></td>
                                                <td><span class="badge bg-primary bg-opacity-25 text-primary"><?php echo htmlspecialchars($st['course']); ?></span></td>
                                                <td><?php echo htmlspecialchars($st['campus']); ?></td>
                                                <td><?php echo date('M d, Y', strtotime($st['created_at'])); ?></td>
                                                <td>
                                                    <?php if (empty($st['documents'])): ?>
                                                        <span class="small text-muted">No documents</span>
                                                    <?php else: ?>
                                                        <details>
                                                            <summary class="text-info small"><?php echo count($st['documents']); ?> file(s)</summary>
                                                            <div class="d-flex flex-column gap-1 mt-2">
                                                                <?php foreach ($st['documents'] as $studentDocument): ?>
                                                                    <a class="small text-info text-decoration-none" href="dashboard.php?download_student_document=<?php echo (int)$studentDocument['id']; ?>" target="_blank" rel="noopener">
                                                                        <i class="bi bi-file-earmark-arrow-down me-1"></i><?php echo htmlspecialchars($studentDocument['label']); ?>
                                                                    </a>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </details>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Registered company accounts awaiting school coordinator review -->
                    <div class="glass-card p-4 mt-4">
                        <h5 class="fw-bold text-white mb-2"><i class="bi bi-building-check text-info me-2"></i>Company Partnership Requests</h5>
                        <p class="text-muted small mb-3">Approve companies that selected your school, then choose which approved partners are broadcast to student accounts.</p>
                        <?php
                            $schoolCompanies = [];
                            $companyRequestQ = $db->query("SELECT id, name, email, approval_status, broadcast_to_students, created_at FROM companies WHERE LOWER(TRIM(school))='$schoolFilter' ORDER BY (approval_status='Pending') DESC, id DESC");
                            if ($companyRequestQ) while ($r = $companyRequestQ->fetch_assoc()) $schoolCompanies[] = $r;
                        ?>
                        <div class="table-responsive">
                            <table class="table table-glass small mb-0">
                                <thead><tr><th>Company</th><th>Requested</th><th>Approval</th><th>Student Broadcast</th></tr></thead>
                                <tbody>
                                    <?php if (empty($schoolCompanies)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No company partnership requests for this school yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($schoolCompanies as $partner): ?>
                                            <tr>
                                                <td><strong class="text-white"><?php echo htmlspecialchars($partner['name']); ?></strong><div class="text-muted"><?php echo htmlspecialchars($partner['email']); ?></div></td>
                                                <td><?php echo date('M d, Y', strtotime($partner['created_at'])); ?></td>
                                                <td>
                                                    <form method="POST" action="" class="d-flex align-items-center gap-2">
                                                        <input type="hidden" name="action" value="set_company_status">
                                                        <input type="hidden" name="company_id" value="<?php echo (int)$partner['id']; ?>">
                                                        <input type="hidden" name="status" value="<?php echo $partner['approval_status'] === 'Approved' ? 'Pending' : 'Approved'; ?>">
                                                        <?php if ($partner['approval_status'] === 'Approved'): ?>
                                                            <span class="badge bg-success">Approved</span>
                                                            <button type="submit" class="btn btn-sm btn-glass text-warning" title="Withdraw approval"><i class="bi bi-x-circle"></i></button>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark">Pending</span>
                                                            <button type="submit" class="btn btn-sm btn-glass text-success" title="Approve company"><i class="bi bi-check2-circle"></i></button>
                                                        <?php endif; ?>
                                                    </form>
                                                </td>
                                                <td>
                                                    <?php if ($partner['approval_status'] === 'Approved'): ?>
                                                        <form method="POST" action="">
                                                            <input type="hidden" name="action" value="toggle_company_broadcast">
                                                            <input type="hidden" name="company_id" value="<?php echo (int)$partner['id']; ?>">
                                                            <input type="hidden" name="broadcast" value="<?php echo (int)!$partner['broadcast_to_students']; ?>">
                                                            <button type="submit" class="btn btn-sm <?php echo $partner['broadcast_to_students'] ? 'btn-success' : 'btn-glass text-muted'; ?>">
                                                                <i class="bi <?php echo $partner['broadcast_to_students'] ? 'bi-broadcast' : 'bi-broadcast-pin'; ?> me-1"></i><?php echo $partner['broadcast_to_students'] ? 'Broadcasting' : 'Not Broadcasting'; ?>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted">Approve first</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Manage & Offer Companies to Students (Coordinator approval controls student search) -->
                    <div class="glass-card p-4 mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <h5 class="fw-bold text-white mb-0">
                                <i class="bi bi-buildings-fill text-info me-2"></i> Offer Companies to Students
                            </h5>
                            <button class="btn btn-gradient btn-sm" onclick="openOfferModal()">
                                <i class="bi bi-plus-lg me-1 dashboard-action-icon"></i> Add & Offer Company
                            </button>
                        </div>
                        <p class="text-muted small mb-3">
                            Only offers you <strong class="text-success">Approve</strong> become visible in the student search, filtered by their course. This lets you offer IT openings to IT students only, and so on.
                        </p>

                        <?php
                            // Fetch every offer for this campus so the coordinator can approve or edit.
                            $adminOffers = [];
                            $aoQ = $db->query("SELECT * FROM company_offers WHERE LOWER(TRIM(school))='$schoolFilter' ORDER BY (status='Approved') DESC, id DESC");
                            if ($aoQ) while ($r = $aoQ->fetch_assoc()) $adminOffers[] = $r;
                        ?>
                        <div class="table-responsive">
                            <table class="table table-glass small mb-0">
                                <thead>
                                    <tr>
                                        <th>Company</th>
                                        <th>OJT Offer</th>
                                        <th>Course</th>
                                        <th>Slots</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($adminOffers)): ?>
                                        <tr><td colspan="6" class="text-center text-muted py-3">No companies offered yet. Click "Add & Offer Company" to create one.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($adminOffers as $of): ?>
                                            <tr>
                                                <td><strong class="text-white"><?php echo htmlspecialchars($of['company_name']); ?></strong></td>
                                                <td>
                                                    <div class="text-white"><?php echo htmlspecialchars($of['title']); ?></div>
                                                    <?php if (!empty($of['description'])): ?><div class="text-muted" style="font-size:0.72rem;"><?php echo htmlspecialchars($of['description']); ?></div><?php endif; ?>
                                                </td>
                                                <td><span class="badge bg-primary bg-opacity-25 text-primary"><?php echo htmlspecialchars($of['course']); ?></span></td>
                                                <td><?php echo (int)$of['slots']; ?></td>
                                                <td>
                                                    <?php if ($of['status'] === 'Approved'): ?>
                                                        <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i>Approved</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="d-flex gap-1 flex-wrap">
                                                        <form method="POST" action="" class="d-inline">
                                                            <input type="hidden" name="action" value="set_offer_status">
                                                            <input type="hidden" name="offer_id" value="<?php echo (int)$of['id']; ?>">
                                                            <input type="hidden" name="status" value="<?php echo $of['status'] === 'Approved' ? 'Pending' : 'Approved'; ?>">
                                                            <?php if ($of['status'] === 'Approved'): ?>
                                                                <button type="submit" class="btn btn-sm btn-glass text-warning" title="Withdraw approval (hide from students)"><i class="bi bi-x-circle"></i></button>
                                                            <?php else: ?>
                                                                <button type="submit" class="btn btn-sm btn-glass text-success" title="Approve & show to matching students"><i class="bi bi-check2-circle"></i></button>
                                                            <?php endif; ?>
                                                        </form>
                                                        <button class="btn btn-sm btn-glass text-info" title="Edit offer"
                                                            onclick="openOfferModal(<?php echo (int)$of['id']; ?>, '<?php echo htmlspecialchars(addslashes($of['company_name'])); ?>', '<?php echo htmlspecialchars(addslashes($of['title'])); ?>', '<?php echo htmlspecialchars(addslashes($of['description'])); ?>', '<?php echo htmlspecialchars(addslashes($of['course'])); ?>', <?php echo (int)$of['slots']; ?>)">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('Delete this OJT offer?');">
                                                            <input type="hidden" name="action" value="delete_offer">
                                                            <input type="hidden" name="offer_id" value="<?php echo (int)$of['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-glass text-danger" title="Delete offer"><i class="bi bi-trash"></i></button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- Right Column: Host Companies Partnered & Completed Trainees -->
                <div class="col-lg-4">

                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-white mb-0"><i class="bi bi-bar-chart-fill text-info me-2"></i>School Reports & Analytics</h6>
                            <a class="btn btn-glass btn-sm text-nowrap" href="dashboard.php?export_admin_report=1&amp;report=applications" download><i class="bi bi-file-earmark-pdf me-1"></i>Applications PDF</a>
                        </div>
                        <div class="row g-2">
                            <?php foreach ([
                                'Students' => $adminReportCounts['students'],
                                'Enrolled & Cleared' => $adminReportCounts['enrolled_cleared'],
                                'Clearance In Progress' => $adminReportCounts['clearance_in_progress'],
                                'Applications' => $adminReportCounts['applications'],
                                'Accepted' => $adminReportCounts['accepted'],
                                'Rejected' => $adminReportCounts['rejected'],
                                'Active Openings' => $adminReportCounts['postings'],
                                'Broadcast Partners' => $adminReportCounts['affiliations'],
                                'Training Modules' => $adminReportCounts['training_modules'],
                                'Training Completions' => $adminReportCounts['training_completions'],
                                'Company Recommendations' => $adminReportCounts['training_recommendations'],
                            ] as $reportLabel => $reportValue): ?>
                                <div class="col-6"><div class="p-2 rounded bg-dark bg-opacity-50 h-100"><div class="h5 fw-bold text-info mb-0"><?php echo (int)$reportValue; ?></div><div class="small text-muted"><?php echo htmlspecialchars($reportLabel); ?></div></div></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="glass-card p-4 mb-4">
                        <h6 class="fw-bold text-white mb-3"><i class="bi bi-sliders text-warning me-2"></i>School System Settings</h6>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="save_school_settings">
                            <div class="mb-3">
                                <label class="form-label small text-muted">Required OJT Practicum Hours</label>
                                <input type="number" name="required_practicum_hours" class="glass-input" min="1" max="2000" value="<?php echo (int)$requiredHours; ?>" required>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="allow_company_student_messages" value="1" id="allowCompanyStudentMessages" <?php echo ($schoolSettings['allow_company_student_messages'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label small text-muted" for="allowCompanyStudentMessages">Allow companies to message students</label>
                            </div>
                            <button type="submit" class="btn btn-gradient btn-sm w-100">Save School Settings</button>
                        </form>

                    </div>

                    <div class="glass-card p-4 mb-4">
                        <button class="btn btn-link text-decoration-none text-start text-white fw-bold p-0 w-100 d-flex align-items-center justify-content-between" type="button" data-bs-toggle="collapse" data-bs-target="#adminAuditActivity" aria-expanded="false" aria-controls="adminAuditActivity">
                            <span><i class="bi bi-shield-lock-fill text-danger me-2"></i>Recent Audit Activity</span>
                            <i class="bi bi-chevron-down small" aria-hidden="true"></i>
                        </button>
                        <div class="collapse" id="adminAuditActivity">
                            <div class="pt-3">
                                <?php if (empty($adminAuditLogs)): ?>
                                    <p class="small text-muted mb-0">No recent school account activity has been logged yet.</p>
                                <?php else: ?>
                                    <div class="d-flex flex-column gap-2">
                                        <?php foreach ($adminAuditLogs as $auditLog): ?>
                                            <div class="border-bottom border-secondary border-opacity-25 pb-2"><div class="small fw-semibold text-white"><?php echo htmlspecialchars(str_replace('_', ' ', $auditLog['action'])); ?></div><div class="small text-muted"><?php echo htmlspecialchars($auditLog['actor_email']); ?> · <?php echo date('M d, g:i a', strtotime($auditLog['created_at'])); ?></div><?php if (!empty($auditLog['details'])): ?><div class="small text-muted text-break"><?php echo htmlspecialchars($auditLog['details']); ?></div><?php endif; ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Enrolled & Cleared Practicum Roster (Rich Cards) -->
                    <div class="glass-card p-4 mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-white mb-0"><i class="bi bi-patch-check-fill text-success me-2"></i>Enrolled & Cleared Practicum Roster</h6>
                            <?php
                                $completedCount = 0;
                                $anySubmitted = 0;
                                foreach ($adminClearanceList as $ac) {
                                    if ($ac['is_completed'] == 1) $completedCount++;
                                    if (!empty($ac['moa_file']) || !empty($ac['endorsement_file']) || !empty($ac['waiver_file']) || !empty($ac['medical_file'])) $anySubmitted++;
                                }
                            ?>
                            <span class="badge bg-success font-monospace"><?php echo $completedCount; ?> / <?php echo count($adminClearanceList); ?></span>
                        </div>

                        <?php if (empty($adminClearanceList)): ?>
                            <p class="small text-muted mb-0">No registered students for this institution yet.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($adminClearanceList as $ac):
                                    // Count submitted docs
                                    $submittedDocs = 0;
                                    if (!empty($ac['moa_file'])) $submittedDocs++;
                                    if (!empty($ac['endorsement_file'])) $submittedDocs++;
                                    if (!empty($ac['waiver_file'])) $submittedDocs++;
                                    if (!empty($ac['medical_file'])) $submittedDocs++;

                                    $avatarSrc = !empty($ac['avatar']) ? htmlspecialchars($ac['avatar']) : '';
                                    $initials = strtoupper(substr($ac['name'], 0, 1));
                                ?>
                                <button type="button"
                                    class="roster-student-card <?php echo $ac['is_completed'] ? 'cleared' : ($submittedDocs > 0 ? 'has-docs' : ''); ?>"
                                    onclick="openStudentClearanceDetail(
                                        '<?php echo htmlspecialchars(addslashes($ac['email'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['name'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['course'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['campus'])); ?>',
                                        '<?php echo $avatarSrc; ?>',
                                        <?php echo $submittedDocs; ?>,
                                        '<?php echo htmlspecialchars(addslashes($ac['moa_status'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['endorsement_status'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['waiver_status'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['medical_status'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['moa_file'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['endorsement_file'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['waiver_file'])); ?>',
                                        '<?php echo htmlspecialchars(addslashes($ac['medical_file'])); ?>',
                                        <?php echo (int)$ac['is_completed']; ?>
                                    )">
                                    <div class="roster-avatar">
                                        <?php if ($avatarSrc): ?>
                                            <img src="<?php echo $avatarSrc; ?>" alt="<?php echo htmlspecialchars($ac['name']); ?>">
                                        <?php else: ?>
                                            <span class="roster-avatar-initials"><?php echo $initials; ?></span>
                                        <?php endif; ?>
                                        <?php if ($ac['is_completed']): ?>
                                            <span class="roster-cleared-dot" title="Enrolled & Cleared"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="roster-info">
                                        <div class="roster-name"><?php echo htmlspecialchars($ac['name']); ?></div>
                                        <div class="roster-course"><?php echo htmlspecialchars($ac['course']); ?> · <?php echo htmlspecialchars($ac['campus']); ?></div>
                                    </div>
                                    <div class="roster-doc-count">
                                        <span class="roster-doc-badge <?php echo $submittedDocs === 4 ? 'full' : ($submittedDocs > 0 ? 'partial' : ''); ?>">
                                            <?php echo $submittedDocs; ?>/4
                                        </span>
                                        <i class="bi bi-chevron-right roster-chevron"></i>
                                    </div>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="glass-card p-4 mb-4">
                        <h6 class="fw-bold text-white mb-3"><i class="bi bi-buildings text-primary me-2"></i>Partner Companies in <?php echo $school; ?></h6>
                        <?php if (empty($adminPartnerCompanies)): ?>
                            <p class="small text-muted mb-0">No partner companies have published openings for <?php echo $school; ?> yet.</p>
                        <?php else: ?>
                            <div class="list-group list-group-flush small">
                                <?php foreach ($adminPartnerCompanies as $pc): ?>
                                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-secondary border-opacity-25">
                                        <div>
                                            <div class="text-white fw-semibold"><?php echo htmlspecialchars($pc['company_name']); ?></div>
                                            <div class="text-muted" style="font-size:0.75rem;"><?php echo (int)$pc['post_count']; ?> Vacancy Posting(s)</div>
                                        </div>
                                        <span class="badge bg-success">MoA Active</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            </div>
        <?php endif; ?>

    </div>

    <!-- ========================================================
         MODALS: PROFILE, CLEARANCES, MESSAGING & ATTACHMENTS
         ======================================================== -->

    <!-- Profile Customization Modal -->
    <div class="modal fade modal-glass" id="profileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-person-gear me-2"></i>Edit My Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="modal-body p-4">
                        <div class="row g-4">
                        <aside class="col-md-4">
                            <div class="glass-card p-3 text-center h-100">
                                <div id="profileAvatarPreview" class="rounded-circle overflow-hidden bg-primary d-inline-flex align-items-center justify-content-center text-white fw-bold mb-3" style="width:112px;height:112px;font-size:2rem;" onclick="document.getElementById('avatarFile').click()" title="Click to change profile photo">
                                    <?php if (!empty($avatar)): ?><img src="<?php echo htmlspecialchars($avatar); ?>" alt="Profile photo" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><?php echo htmlspecialchars(strtoupper(substr($name, 0, 1))); ?><?php endif; ?>
                                </div>
                                <h6 class="text-white fw-bold mb-1" id="profileResumeName"><?php echo $name; ?></h6>
                                <div class="text-info small mb-2" id="profileResumeHeadline"><?php echo htmlspecialchars($headline ?: ucfirst($role) . ' • ' . $course); ?></div>
                                <div class="small text-muted text-start border-top border-secondary border-opacity-25 pt-3"><div class="mb-2"><i class="bi bi-envelope me-2 text-info"></i><?php echo htmlspecialchars($email); ?></div><div class="mb-2"><i class="bi bi-geo-alt me-2 text-info"></i><span id="profileResumeLocation"><?php echo htmlspecialchars($location ?: $school); ?></span></div><div><i class="bi bi-telephone me-2 text-info"></i><span id="profileResumePhone"><?php echo htmlspecialchars($phone ?: 'Add phone number'); ?></span></div></div>
                            </div>
                        </aside>
                        <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Full Name</label>
                            <input type="text" name="name" id="profileNameInput" class="glass-input" value="<?php echo $name; ?>" required oninput="syncProfilePreview()">
                        </div>
                        <div class="mb-3 d-none">
                            <label class="form-label small text-muted">Profile Picture</label>
                            <div class="file-upload-control">
                                <input type="file" name="avatar_file" id="avatarFile" accept="image/jpeg,image/png,image/webp" data-file-input onchange="previewProfileAvatar(this)">
                                <label for="avatarFile" class="file-upload-label"><i class="bi bi-image"></i><span class="file-upload-button">Choose picture</span><span class="file-upload-name">No file selected</span></label>
                            </div>
                            <div class="form-text text-muted">Choose a JPG, PNG, or WEBP image (maximum 5 MB).</div>
                        </div>
                        <p class="small text-muted mb-3"><i class="bi bi-camera-fill text-info me-1"></i>Click your profile photo to upload or change it.</p>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Professional Headline / Role</label>
                            <input type="text" name="headline" id="profileHeadlineInput" class="glass-input" value="<?php echo htmlspecialchars($headline); ?>" placeholder="e.g. BSIT Student • Web Development Intern" oninput="syncProfilePreview()">
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-sm-6"><label class="form-label small text-muted">Phone Number</label><input type="tel" name="phone" id="profilePhoneInput" class="glass-input" value="<?php echo htmlspecialchars($phone); ?>" placeholder="09xx xxx xxxx" oninput="syncProfilePreview()"></div>
                            <div class="col-sm-6"><label class="form-label small text-muted">Location</label><input type="text" name="location" id="profileLocationInput" class="glass-input" value="<?php echo htmlspecialchars($location); ?>" placeholder="City, Province" oninput="syncProfilePreview()"></div>
                        </div>
                        <?php if ($role === 'student'): ?>
                            <div class="row g-2 mb-3">
                                <div class="col-12"><label class="form-label small text-muted">Designated Institution</label><input type="text" name="institution" class="glass-input" value="<?php echo htmlspecialchars($institution); ?>" required></div>
                                <div class="col-sm-7">
                                    <label class="form-label small text-muted">Campus</label>
                                    <select name="campus" class="glass-input" required>
                                        <?php foreach (practicore_campuses() as $profileCampusOption): ?>
                                            <option value="<?php echo htmlspecialchars($profileCampusOption); ?>" <?php echo practicore_campus_key($campus) === practicore_campus_key($profileCampusOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($profileCampusOption); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-5"><label class="form-label small text-muted">Course / Program</label><input type="text" name="course" class="glass-input" value="<?php echo htmlspecialchars($course); ?>" required></div>
                            </div>
                        <?php else: ?>
                            <div class="mb-3"><label class="form-label small text-muted">Designated Campus</label><input type="text" class="glass-input" value="<?php echo $school; ?>" disabled></div>
                        <?php endif; ?>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Biography / About Me</label>
                            <textarea name="bio" class="glass-input" rows="3" placeholder="Tell colleagues or employers about your skills, focus, and interests..."><?php echo htmlspecialchars($bio); ?></textarea>
                        </div>
                        </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm">Save Profile Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Direct Messages Modal (Cross-Talk between Student, Company & Admin) -->
    <div class="modal fade modal-glass" id="messagesModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-chat-left-text-fill text-primary me-2"></i>Direct Messages</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="message-workspace">
                        <!-- Compose New Message -->
                        <div class="message-thread-list">
                            <details><summary class="fw-bold text-white mb-3 cursor-pointer"><i class="bi bi-pencil-square me-1"></i>New Conversation</summary>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="send_message">
                                <div class="mb-3">
                                    <label class="form-label small text-muted">Recipient (Select User)</label>
                                    <select name="receiver_email" id="msgRecipientSelect" class="glass-input" onchange="updateRecipientName(this)" required>
                                        <option value="">Select Recipient...</option>
                                        <?php foreach ($allUsersList as $usr): ?>
                                            <option value="<?php echo htmlspecialchars($usr['email']); ?>" data-name="<?php echo htmlspecialchars($usr['name']); ?>">
                                                <?php echo htmlspecialchars($usr['name']); ?> (<?php echo ucfirst($usr['role']); ?> • <?php echo htmlspecialchars($usr['school']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="receiver_name" id="msgReceiverName">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small text-muted">Message Content</label>
                                    <textarea name="message" class="glass-input" rows="4" placeholder="Write your message regarding OJT requirements, MoA, or interview schedules..." required></textarea>
                                </div>
                                <button type="submit" class="btn btn-gradient btn-sm w-100">
                                    <i class="bi bi-send-fill me-1"></i> Transmit Message
                                </button>
                            </form>
                            </details>
                        </div>

                        <!-- Conversation threads -->
                        <div class="message-conversation">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold text-white mb-0"><i class="bi bi-chat-square-text-fill text-info me-1"></i>Conversations</h6>
                                <?php if ($unreadMsgCount > 0): ?>
                                    <span class="badge bg-danger rounded-pill notif-pulse"><?php echo $unreadMsgCount; ?> unread</span>
                                <?php endif; ?>
                            </div>
                            <?php if (empty($messageThreads)): ?>
                                <p class="small text-muted">No conversations yet. Start one from the form on the left.</p>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-2" style="max-height:430px; overflow-y:auto;" id="inboxList">
                                    <?php foreach ($messageThreads as $thread): ?>
                                        <section class="conversation-thread" data-sender="<?php echo htmlspecialchars($thread['email']); ?>">
                                        <button type="button" class="conversation-thread-heading w-100 border-0" onclick="toggleConversationThread(this)"><span class="d-flex align-items-center gap-2 text-start"><span class="rounded-circle overflow-hidden d-inline-flex align-items-center justify-content-center bg-primary text-white" style="width:34px;height:34px;flex:0 0 34px;"><?php if (!empty($thread['avatar'])): ?><img src="<?php echo htmlspecialchars($thread['avatar']); ?>" alt="" style="width:100%;height:100%;object-fit:cover;"><?php else: ?><?php echo htmlspecialchars(strtoupper(substr($thread['name'], 0, 1))); ?><?php endif; ?></span><span><strong class="text-white small d-block"><?php echo htmlspecialchars($thread['name']); ?></strong><span class="text-muted" style="font-size:.68rem;">Tap to open conversation</span></span></span><?php if ($thread['unread']): ?><span class="badge bg-danger rounded-pill"><?php echo $thread['unread']; ?> new</span><?php endif; ?></button>
                                        <div class="conversation-thread-body d-none">
                                        <?php foreach (array_reverse($thread['messages']) as $inMsg):
                                        $isOutgoing = $inMsg['sender_email'] === $email;
                                        $isUnread = !$isOutgoing && empty($inMsg['is_read']);
                                    ?>
                                        <div class="msg-inbox-item chat-bubble <?php echo $isOutgoing ? 'outgoing' : 'incoming'; ?> <?php echo $isUnread ? 'unread' : 'read'; ?>">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <strong class="text-white small"><?php echo $isOutgoing ? 'You' : htmlspecialchars($inMsg['sender_name']); ?></strong>
                                                <div class="d-flex align-items-center gap-1">
                                                    <?php if ($isOutgoing): ?>
                                                        <span class="msg-seen-dot <?php echo !empty($inMsg['is_read']) ? 'text-info' : 'text-muted'; ?>" title="<?php echo !empty($inMsg['is_read']) ? 'Seen' : 'Sent'; ?>"><i class="bi bi-check2-all"></i> <?php echo !empty($inMsg['is_read']) ? 'Seen' : 'Sent'; ?></span>
                                                    <?php elseif ($isUnread): ?>
                                                        <span class="badge bg-primary" style="font-size:0.58rem;">UNREAD</span>
                                                    <?php else: ?>
                                                        <span class="msg-seen-dot" title="Seen"><i class="bi bi-check2-all text-info"></i></span>
                                                    <?php endif; ?>
                                                    <?php if (!$isOutgoing): ?><span class="badge bg-primary bg-opacity-25 text-primary text-uppercase" style="font-size:0.62rem;"><?php echo htmlspecialchars($inMsg['sender_role']); ?></span><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="small <?php echo $isUnread ? 'text-white' : 'text-muted'; ?> mb-1"><?php echo htmlspecialchars($inMsg['message']); ?></div>
                                            <div class="d-flex justify-content-between align-items-center text-dim" style="font-size:0.7rem;">
                                                <span><?php echo date('M d, g:i a', strtotime($inMsg['created_at'])); ?></span>
                                                <span><?php echo $isOutgoing ? '' : 'Open this thread to reply'; ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <form method="POST" action="" class="message-reply-form"><input type="hidden" name="action" value="send_message"><input type="hidden" name="receiver_email" value="<?php echo htmlspecialchars($thread['email']); ?>"><input type="hidden" name="receiver_name" value="<?php echo htmlspecialchars($thread['name']); ?>"><textarea name="message" class="glass-input" rows="2" placeholder="Reply to <?php echo htmlspecialchars($thread['name']); ?>..." required></textarea><button type="submit" class="btn btn-gradient btn-sm"><i class="bi bi-send-fill"></i></button></form>
                                    </div>
                                    </section>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Student: Apply with Custom Attachments Modal -->
    <div class="modal fade modal-glass" id="applyAttachmentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold text-white" id="modalApplyJobTitle">Apply for OJT</h5>
                        <p class="text-muted small mb-0" id="modalApplyCompName">Company</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data" id="applyForm">
                    <input type="hidden" name="action" value="apply_job">
                    <input type="hidden" name="job_id" id="modalApplyJobId">
                    <input type="hidden" name="job_title" id="modalApplyJobTitleInput">
                    <input type="hidden" name="company_name" id="modalApplyCompNameInput">
                    <input type="hidden" name="company_email" id="modalApplyCompEmailInput">
                    <input type="hidden" name="attachment_mode" id="applyAttachMode" value="file">

                    <div class="modal-body p-4">
                        <div class="alert alert-info py-2 px-3 small mb-3 bg-opacity-10 border-info text-info">
                            <i class="bi bi-info-circle me-1"></i> <strong id="modalApplyReqDocs">Requirements demanded by company: Resume</strong>
                        </div>

                        <!-- Attachment Mode Toggle -->
                        <div class="attachment-mode-toggle mb-3">
                            <button type="button" class="attach-tab-btn active" id="applyTabFile" onclick="switchApplyTab('file')">
                                <i class="bi bi-upload me-1"></i> Upload File
                            </button>
                            <button type="button" class="attach-tab-btn" id="applyTabLink" onclick="switchApplyTab('link')">
                                <i class="bi bi-link-45deg me-1"></i> Paste Link
                            </button>
                        </div>

                        <!-- File Upload Panel -->
                        <div id="applyFilePanelWrap" class="mb-3">
                            <label class="form-label small text-muted">Attach Requirements <span class="text-muted" style="font-size:0.78rem;">(optional)</span></label>
                            <div class="file-upload-control" id="applyFileUploadCtrl">
                                <input type="file" name="application_file" id="applicationFile" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-input data-preview-target="applyFilePreview">
                                <label for="applicationFile" class="file-upload-label">
                                    <i class="bi bi-paperclip"></i>
                                    <span class="file-upload-button">Choose file</span>
                                    <span class="file-upload-name">No file selected</span>
                                </label>
                            </div>
                            <div class="form-text text-muted">Image or PDF (max 5 MB). Leave blank to apply without an attachment.</div>
                            <?php if (!empty($studentDocuments)): ?>
                                <div class="mt-3">
                                    <label class="form-label small text-muted" for="applicationLibraryDocuments">Or attach files from My OJT Documents</label>
                                    <select name="student_document_ids[]" id="applicationLibraryDocuments" class="glass-input" multiple size="<?php echo min(4, count($studentDocuments)); ?>">
                                        <?php foreach ($studentDocuments as $libraryDocument): ?>
                                            <option value="<?php echo (int)$libraryDocument['id']; ?>"><?php echo htmlspecialchars($libraryDocument['label'] . ' · ' . date('M d, Y', strtotime($libraryDocument['created_at']))); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text text-muted">Only the files you select here will be shared with this company for this application.</div>
                                </div>
                            <?php else: ?>
                                <p class="form-text text-muted mt-2">Upload documents in My OJT Documents first to reuse them here.</p>
                            <?php endif; ?>

                            <!-- File Preview Container -->
                            <div id="applyFilePreview" class="file-preview-container d-none mt-3">
                                <div class="file-preview-header">
                                    <span class="file-preview-label"><i class="bi bi-eye me-1"></i> Document Preview</span>
                                    <button type="button" class="file-preview-clear" onclick="clearFilePreview('applicationFile','applyFilePreview')">
                                        <i class="bi bi-x-circle"></i> Remove
                                    </button>
                                </div>
                                <div class="file-preview-body" id="applyFilePreviewBody"></div>
                            </div>
                        </div>

                        <!-- Link Panel -->
                        <div id="applyLinkPanelWrap" class="mb-3 d-none">
                            <label class="form-label small text-muted">Paste Document / Drive Link <span class="text-muted" style="font-size:0.78rem;">(optional)</span></label>
                            <div class="glass-input-group">
                                <i class="bi bi-link-45deg"></i>
                                <input type="url" name="attachment_link" id="applyAttachLink" class="glass-input" placeholder="https://drive.google.com/... or any public link">
                            </div>
                            <div class="form-text text-muted">Paste a Google Drive, OneDrive, Dropbox, or any public document link.</div>

                            <!-- Link Preview -->
                            <div id="applyLinkPreview" class="file-preview-container d-none mt-3">
                                <div class="file-preview-header">
                                    <span class="file-preview-label"><i class="bi bi-box-arrow-up-right me-1"></i> Link Preview</span>
                                </div>
                                <div class="link-preview-body">
                                    <iframe id="applyLinkIframe" src="" class="link-preview-iframe" allowfullscreen></iframe>
                                </div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small text-muted">Brief Pitch / Note to Employer</label>
                            <textarea name="student_notes" class="glass-input" rows="3" placeholder="Tell the company why you're a great fit for their team..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm">
                            <i class="bi bi-send-fill me-1"></i> Transmit Application
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Student: Submit Clearance Requirement Modal -->
    <div class="modal fade modal-glass" id="clearanceUploadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold text-white" id="clearanceModalTitle">Submit Clearance</h5>
                        <p class="text-muted small mb-0" id="clearanceModalSub">University Practicum Requirement</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data" id="clearanceForm">
                    <input type="hidden" name="action" value="submit_clearance_item">
                    <input type="hidden" name="clearance_type" id="clearanceTypeInput">
                    <input type="hidden" name="clearance_mode" id="clearanceAttachMode" value="file">
                    <div class="modal-body p-4">

                        <div id="clearExistingDocument" class="glass-card p-3 mb-3 d-none">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                                <div><div class="small text-white fw-semibold"><i class="bi bi-paperclip text-info me-1"></i>Current submitted document</div><a id="clearExistingFileLink" href="#" target="_blank" class="small text-info">View uploaded file</a></div>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeCurrentClearance()"><i class="bi bi-trash3 me-1"></i>Remove</button>
                            </div>
                            <iframe id="clearExistingIframe" src="" title="Current submitted document" style="width:100%;height:180px;border:0;border-radius:8px;margin-top:12px;background:#fff;"></iframe>
                            <div class="small text-muted mt-2">Choose a new file below and submit to replace it.</div>
                        </div>

                        <!-- Attachment Mode Toggle -->
                        <div class="attachment-mode-toggle mb-3">
                            <button type="button" class="attach-tab-btn active" id="clearTabFile" onclick="switchClearanceTab('file')">
                                <i class="bi bi-upload me-1"></i> Upload File
                            </button>
                            <button type="button" class="attach-tab-btn" id="clearTabLink" onclick="switchClearanceTab('link')">
                                <i class="bi bi-link-45deg me-1"></i> Paste Link
                            </button>
                        </div>

                        <!-- File Upload Panel -->
                        <div id="clearFilePanelWrap" class="mb-3">
                            <label class="form-label small text-muted">Attach Signed Document</label>
                            <div class="file-upload-control">
                                <input type="file" name="clearance_file" id="clearanceFile" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-input data-preview-target="clearFilePreview">
                                <label for="clearanceFile" class="file-upload-label">
                                    <i class="bi bi-cloud-arrow-up"></i>
                                    <span class="file-upload-button">Choose file</span>
                                    <span class="file-upload-name">No file selected</span>
                                </label>
                            </div>
                            <div class="form-text text-muted">Image or PDF (max 5 MB).</div>

                            <!-- File Preview Container -->
                            <div id="clearFilePreview" class="file-preview-container d-none mt-3">
                                <div class="file-preview-header">
                                    <span class="file-preview-label"><i class="bi bi-eye me-1"></i> Document Preview</span>
                                    <button type="button" class="file-preview-clear" onclick="clearFilePreview('clearanceFile','clearFilePreview')">
                                        <i class="bi bi-x-circle"></i> Remove
                                    </button>
                                </div>
                                <div class="file-preview-body" id="clearFilePreviewBody"></div>
                            </div>
                        </div>

                        <!-- Link Panel -->
                        <div id="clearLinkPanelWrap" class="mb-3 d-none">
                            <label class="form-label small text-muted">Paste Document / Drive Link</label>
                            <div class="glass-input-group">
                                <i class="bi bi-link-45deg"></i>
                                <input type="url" name="clearance_link" id="clearAttachLink" class="glass-input" placeholder="https://drive.google.com/... or any public link">
                            </div>
                            <div class="form-text text-muted">Paste a Google Drive, OneDrive, Dropbox, or any public document link.</div>

                            <!-- Link Preview -->
                            <div id="clearLinkPreview" class="file-preview-container d-none mt-3">
                                <div class="file-preview-header">
                                    <span class="file-preview-label"><i class="bi bi-box-arrow-up-right me-1"></i> Link Preview</span>
                                </div>
                                <div class="link-preview-body">
                                    <iframe id="clearLinkIframe" src="" class="link-preview-iframe" allowfullscreen></iframe>
                                </div>
                            </div>
                        </div>

                        <p class="small text-muted mb-0">
                            <i class="bi bi-info-circle me-1"></i> Your submitted clearance document will be queued for approval by your campus academic coordinator.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm">
                            <i class="bi bi-cloud-upload me-1"></i> Submit for Review
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Admin: Editable Student Clearance Modal (Image 2) -->
    <div class="modal fade modal-glass" id="adminClearanceModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold text-white">Edit University Clearance</h5>
                        <p class="text-muted small mb-0" id="admClearanceStudentName">Student Name</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_student_clearance">
                    <input type="hidden" name="student_email" id="admClearanceEmail">
                    <input type="hidden" name="student_name" id="admClearanceName">

                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small text-muted">1. Memorandum of Agreement (MoA)</label>
                            <select name="moa_status" id="admMoaSelect" class="glass-input">
                                <option value="Pending">Pending</option>
                                <option value="Submitted">Submitted</option>
                                <option value="Verified">Verified (Approved)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">2. Endorsement Letter</label>
                            <select name="endorsement_status" id="admEndorsementSelect" class="glass-input">
                                <option value="Pending">Pending</option>
                                <option value="Submitted">Submitted</option>
                                <option value="Verified">Verified (Approved)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">3. Parents' Consent & Waiver</label>
                            <select name="waiver_status" id="admWaiverSelect" class="glass-input">
                                <option value="Pending">Pending</option>
                                <option value="Submitted">Submitted</option>
                                <option value="Verified">Verified (Approved)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">4. Medical & Drug Test Clearance</label>
                            <select name="medical_status" id="admMedicalSelect" class="glass-input">
                                <option value="Pending">Pending</option>
                                <option value="Submitted">Submitted</option>
                                <option value="Verified">Verified (Approved)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm">Save Clearance Statuses</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Admin: Student Clearance Detail Modal -->
    <div class="modal fade modal-glass" id="studentClearanceDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold text-white"><i class="bi bi-person-vcard-fill text-info me-2"></i>Student Clearance Review</h5>
                        <p class="text-muted small mb-0">Review submitted documents and manage clearance status</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">

                    <!-- Student Profile Card -->
                    <div class="scd-profile-card mb-4">
                        <div class="scd-avatar-wrap" id="scdAvatarWrap">
                            <!-- filled by JS -->
                        </div>
                        <div class="scd-profile-info">
                            <div class="scd-student-name" id="scdStudentName">Student</div>
                            <div class="scd-student-meta" id="scdStudentMeta">Course</div>
                            <div class="scd-doc-tally" id="scdDocTally">
                                <span id="scdDocCount" class="scd-doc-num">0</span>
                                <span class="scd-doc-label">/4 documents submitted</span>
                            </div>
                        </div>
                        <div class="scd-profile-actions">
                            <button type="button" class="btn btn-gradient btn-sm" id="scdMsgBtn" onclick="openScdMessage()">
                                <i class="bi bi-chat-dots-fill me-1"></i> Message
                            </button>
                        </div>
                    </div>

                    <!-- Clearance Items -->
                    <div class="d-flex flex-column gap-3" id="scdDocList">
                        <!-- filled by JS -->
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin: Create Training Module Modal -->
    <div class="modal fade modal-glass" id="createModuleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-mortarboard-fill text-warning me-2"></i>Post New Guide Module</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="post_module">
                    <input type="hidden" name="module_guide_mode" id="createModuleGuideMode" value="link">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Module Title</label>
                            <input type="text" name="title" class="glass-input" placeholder="e.g. Workplace Ethics & Corporate Communication" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small text-muted">Target Course</label>
                                <select name="course" class="glass-input">
                                    <option value="BSIT">BS Information Technology (BSIT)</option>
                                    <option value="BSCS">BS Computer Science (BSCS)</option>
                                    <option value="CpE">BS Computer Engineering (CpE)</option>
                                    <option value="MMA">Multimedia Arts</option>
                                    <option value="All Courses">All Campus Programs</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted">Hours Equivalent</label>
                                <input type="number" name="hours" class="glass-input" value="15" min="1" max="100" required>
                            </div>
                        </div>

                        <!-- Guide / Syllabus: File OR Link toggle -->
                        <div class="mb-1">
                            <label class="form-label small text-muted">Study Guide / Syllabus</label>
                        </div>
                        <div class="attachment-mode-toggle mb-3">
                            <button type="button" class="attach-tab-btn" id="createModTabLink" onclick="switchModuleTab('create','link')">
                                <i class="bi bi-link-45deg me-1"></i> Paste Link <span class="text-muted" style="font-size:0.75rem;">(optional)</span>
                            </button>
                            <button type="button" class="attach-tab-btn active" id="createModTabFile" onclick="switchModuleTab('create','file')">
                                <i class="bi bi-upload me-1"></i> Upload File <span class="text-muted" style="font-size:0.75rem;">(optional)</span>
                            </button>
                        </div>

                        <!-- Link panel -->
                        <div id="createModLinkWrap" class="mb-3 d-none">
                            <div class="glass-input-group">
                                <i class="bi bi-link-45deg"></i>
                                <input type="url" name="guide_link" id="createModLink" class="glass-input" placeholder="https://drive.google.com/... or any public URL">
                            </div>
                            <div class="form-text text-muted">Google Drive, OneDrive, SlideShare, or any embeddable link.</div>
                        </div>

                        <!-- File upload panel -->
                        <div id="createModFileWrap" class="mb-3">
                            <div class="file-upload-control">
                                <input type="file" name="guide_file_upload" id="createModFile" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-input data-preview-target="createModPreview">
                                <label for="createModFile" class="file-upload-label">
                                    <i class="bi bi-file-earmark-arrow-up"></i>
                                    <span class="file-upload-button">Choose PDF or image</span>
                                    <span class="file-upload-name">No file selected</span>
                                </label>
                            </div>
                            <div class="form-text text-muted">PDF, JPG, PNG, or WEBP (max 5 MB). Leave blank to skip.</div>
                            <!-- Preview -->
                            <div id="createModPreview" class="file-preview-container d-none mt-3">
                                <div class="file-preview-header">
                                    <span class="file-preview-label"><i class="bi bi-eye me-1"></i> Preview</span>
                                    <button type="button" class="file-preview-clear" onclick="clearFilePreview('createModFile','createModPreview')"><i class="bi bi-x-circle"></i> Remove</button>
                                </div>
                                <div class="file-preview-body" id="createModPreviewBody"></div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small text-muted">Module Synopsis & Learning Outcomes</label>
                            <textarea name="description" class="glass-input" rows="3" placeholder="Describe the topics and skills students will gain from this module..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm"><i class="bi bi-send me-1"></i>Publish Guide Module</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Admin: Edit Training Module Modal -->
    <div class="modal fade modal-glass" id="editModuleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-pencil-square text-info me-2"></i>Edit Guide Module</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_module">
                    <input type="hidden" name="module_id" id="editModuleId">
                    <input type="hidden" name="module_guide_mode" id="editModuleGuideMode" value="link">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Module Title</label>
                            <input type="text" name="title" id="editModuleTitle" class="glass-input" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small text-muted">Target Course</label>
                                <select name="course" id="editModuleCourse" class="glass-input">
                                    <option value="BSIT">BS Information Technology (BSIT)</option>
                                    <option value="BSCS">BS Computer Science (BSCS)</option>
                                    <option value="CpE">BS Computer Engineering (CpE)</option>
                                    <option value="MMA">Multimedia Arts</option>
                                    <option value="All Courses">All Campus Programs</option>
                                </select>
                            </div>
                            <div class="col-3">
                                <label class="form-label small text-muted">Hours</label>
                                <input type="number" name="hours" id="editModuleHours" class="glass-input" min="1" max="100" required>
                            </div>
                            <div class="col-3">
                                <label class="form-label small text-muted">Level</label>
                                <select name="level" id="editModuleLevel" class="glass-input">
                                    <option value="Beginner">Beginner</option>
                                    <option value="Intermediate">Intermediate</option>
                                    <option value="Advanced">Advanced</option>
                                    <option value="All Levels">All Levels</option>
                                </select>
                            </div>
                        </div>

                        <!-- Guide toggle -->
                        <div class="mb-1">
                            <label class="form-label small text-muted">Study Guide / Syllabus</label>
                        </div>
                        <div class="attachment-mode-toggle mb-3">
                            <button type="button" class="attach-tab-btn active" id="editModTabLink" onclick="switchModuleTab('edit','link')">
                                <i class="bi bi-link-45deg me-1"></i> Paste Link <span class="text-muted" style="font-size:0.75rem;">(optional)</span>
                            </button>
                            <button type="button" class="attach-tab-btn" id="editModTabFile" onclick="switchModuleTab('edit','file')">
                                <i class="bi bi-upload me-1"></i> Upload File <span class="text-muted" style="font-size:0.75rem;">(optional)</span>
                            </button>
                        </div>

                        <!-- Link panel -->
                        <div id="editModLinkWrap" class="mb-3">
                            <div class="glass-input-group">
                                <i class="bi bi-link-45deg"></i>
                                <input type="url" name="guide_link" id="editModuleLink" class="glass-input" placeholder="https://drive.google.com/... or any public URL">
                            </div>
                            <div class="form-text text-muted">Google Drive, OneDrive, SlideShare, or any embeddable link.</div>
                        </div>

                        <!-- File upload panel -->
                        <div id="editModFileWrap" class="mb-3 d-none">
                            <!-- Existing file indicator -->
                            <div id="editModExistingFileInfo" class="small text-info mb-2 d-none">
                                <i class="bi bi-file-earmark-check me-1"></i>
                                <span id="editModExistingFileName">Current file</span>
                                <span class="text-muted"> — upload new file to replace it</span>
                            </div>
                            <div class="file-upload-control">
                                <input type="file" name="guide_file_upload" id="editModFile" accept="image/jpeg,image/png,image/webp,application/pdf" data-file-input data-preview-target="editModPreview">
                                <label for="editModFile" class="file-upload-label">
                                    <i class="bi bi-file-earmark-arrow-up"></i>
                                    <span class="file-upload-button">Choose PDF or image</span>
                                    <span class="file-upload-name">No file selected</span>
                                </label>
                            </div>
                            <div class="form-text text-muted">PDF, JPG, PNG, or WEBP (max 5 MB). Leave blank to keep existing file.</div>
                            <!-- Preview -->
                            <div id="editModPreview" class="file-preview-container d-none mt-3">
                                <div class="file-preview-header">
                                    <span class="file-preview-label"><i class="bi bi-eye me-1"></i> Preview</span>
                                    <button type="button" class="file-preview-clear" onclick="clearFilePreview('editModFile','editModPreview')"><i class="bi bi-x-circle"></i> Remove</button>
                                </div>
                                <div class="file-preview-body" id="editModPreviewBody"></div>
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label small text-muted">Description</label>
                            <textarea name="description" id="editModuleDesc" class="glass-input" rows="3" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm">Save Module Updates</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Company: Sign & Comment DTR Modal -->
    <div class="modal fade modal-glass" id="signDtrModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold text-white">Supervisor DTR Sign & Feedback</h5>
                        <p class="text-muted small mb-0" id="signDtrStudentInfo">Trainee</p>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="sign_dtr">
                    <input type="hidden" name="dtr_id" id="signDtrId">
                    <div class="modal-body p-4">
                        <div class="p-3 rounded bg-dark bg-opacity-50 mb-3">
                            <div class="small text-muted">Logged Task:</div>
                            <div class="text-white fw-semibold" id="signDtrTask">Task</div>
                            <div class="small text-info mt-1" id="signDtrHours">Hours</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Supervisor Name & Designation</label>
                            <input type="text" name="supervisor_name" class="glass-input" value="<?php echo htmlspecialchars($name); ?> Supervisor" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Supervisor Feedback / Comments</label>
                            <textarea name="supervisor_comment" class="glass-input" rows="3" placeholder="e.g. Excellent work ethic, demonstrated strong technical skills and punctual attendance." required></textarea>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Status</label>
                            <select name="status" class="glass-input">
                                <option value="Approved by Supervisor">Approved by Supervisor</option>
                                <option value="Revision Requested">Revision Requested</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm">Confirm Sign & Post Feedback</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Student: Post "Looking for Company" Notice Modal (System Proposal 4.1 & 5) -->
    <div class="modal fade modal-glass" id="postNoticeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-megaphone-fill text-primary me-2"></i>Post "Looking for Company" Notice</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="post_student_notice">
                    <div class="modal-body p-4">
                        <p class="text-muted small mb-3">Your notice will be broadcasted directly to accredited host companies in <?php echo $school; ?>.</p>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Notice Headline</label>
                            <input type="text" name="headline" class="glass-input" placeholder="e.g. 4th Year BSIT Student Seeking Web Dev / QA Practicum" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small text-muted">Preferred Role</label>
                                <input type="text" name="preferred_role" class="glass-input" placeholder="e.g. Web Developer / QA" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted">Availability</label>
                                <select name="availability" class="glass-input">
                                    <option value="Immediate / Full-time (40 hrs/wk)">Immediate / Full-time (40 hrs/wk)</option>
                                    <option value="Part-time (20 hrs/wk)">Part-time (20 hrs/wk)</option>
                                    <option value="Hybrid / Flexible">Hybrid / Flexible</option>
                                    <option value="Starting Next Month">Starting Next Month</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Core Skills & Tools</label>
                            <input type="text" name="skills" class="glass-input" placeholder="e.g. PHP, HTML/CSS, MySQL, JavaScript, Git" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Summary / Pitch to Host Employers</label>
                            <textarea name="message" class="glass-input" rows="3" placeholder="Briefly highlight your work ethic, project experience, or career goals..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm">Broadcast Notice to Companies</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Student: Report Company Modal -->
    <div class="modal fade modal-glass" id="reportCompanyModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-flag-fill text-danger me-2"></i>Report Listing</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="report_company">
                    <input type="hidden" name="company_name" id="reportCompName">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Reporting Company</label>
                            <input type="text" id="reportCompNameDisplay" class="glass-input" disabled>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Reason for Report</label>
                            <textarea name="reason" class="glass-input" rows="3" placeholder="Describe any non-compliance with school MoA, safety concerns, or misrepresentation..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger btn-sm">Submit Incident Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Admin/Company: Create or Edit an OJT Offer -->
    <div class="modal fade modal-glass" id="offerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-white"><i class="bi bi-building-add text-info me-2"></i>Offer Company to Students</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_offer">
                    <input type="hidden" name="offer_id" id="offerIdInput" value="0">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Company Name</label>
                            <input type="text" name="company_name" id="offerCompanyInput" class="glass-input" placeholder="e.g. CloudScale Solutions Inc." required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">OJT Role Title</label>
                            <input type="text" name="title" id="offerTitleInput" class="glass-input" placeholder="e.g. Junior Web Developer Intern" required>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-7">
                                <label class="form-label small text-muted">Offer to Course</label>
                                <select name="course" id="offerCourseInput" class="glass-input">
                                    <option value="BSIT">BS Information Technology (BSIT)</option>
                                    <option value="BSCS">BS Computer Science (BSCS)</option>
                                    <option value="CpE">BS Computer Engineering (CpE)</option>
                                    <option value="MMA">Multimedia Arts</option>
                                    <option value="All Tech Courses">All Tech Programs</option>
                                    <option value="All Courses">All Campus Programs</option>
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label small text-muted">Slots</label>
                                <input type="number" name="slots" id="offerSlotsInput" class="glass-input" value="1" min="1" max="50">
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Short Description</label>
                            <textarea name="description" id="offerDescInput" class="glass-input" rows="3" placeholder="Describe the role so students know what to expect..."></textarea>
                        </div>
                        <p class="small text-muted mb-0"><i class="bi bi-info-circle text-info me-1"></i> Approved offers appear in the student's company search, filtered by course.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-glass btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gradient btn-sm"><i class="bi bi-save me-1"></i>Save Offer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════
         MODULE STUDY READER MODAL (PDF Pager — no refresh)
         ═══════════════════════════════════════════════════════ -->
    <div class="modal fade" id="moduleStudyModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-fullscreen modal-dialog-centered" style="margin:0;max-width:100vw;">
            <div class="modal-content" style="background:linear-gradient(135deg,#0d1117 0%,#0f172a 100%);border:none;">

                <!-- Reader Header -->
                <div class="modal-header px-3 px-sm-4 py-3" style="background:rgba(255,255,255,.04);border-bottom:1px solid rgba(255,255,255,.08); flex-wrap: nowrap;">
                    <div class="d-flex align-items-center gap-2 gap-sm-3 flex-fill min-w-0" style="overflow: hidden;">
                        <i class="bi bi-journal-bookmark-fill text-info fs-5 flex-shrink-0"></i>
                        <div class="min-w-0 flex-fill">
                            <div id="studyReaderTitle" class="fw-bold text-white text-truncate" style="font-size:1rem;"></div>
                            <div id="studyReaderMeta" class="small text-muted"></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-glass btn-sm ms-2 ms-sm-3 flex-shrink-0" id="closeStudyReaderBtn">
                        <i class="bi bi-x-lg"></i><span class="d-none d-sm-inline ms-1">Close</span>
                    </button>
                </div>

                <!-- Progress bar -->
                <div style="height:3px;background:rgba(255,255,255,.06);">
                    <div id="studyReaderProgress" style="height:100%;background:linear-gradient(90deg,#6366f1,#22d3ee);width:0%;transition:width .4s ease;"></div>
                </div>

                <!-- Page Canvas area -->
                <div class="modal-body d-flex flex-column align-items-center justify-content-start overflow-auto py-4" id="studyReaderBody" style="flex:1;">
                    <div id="studyPageCard" style="width:100%;max-width:860px;">
                        <!-- Page number chip -->
                        <div class="d-flex justify-content-between align-items-center mb-3 px-1">
                            <span id="studyPageLabel" class="badge" style="background:rgba(99,102,241,.25);color:#a5b4fc;font-size:.82rem;padding:.45em .9em;"><i class="bi bi-file-earmark-text me-1"></i>Page 1</span>
                            <span id="studyHoursLabel" class="small text-info"></span>
                        </div>
                        <!-- Canvas card -->
                        <div style="background:#1e2738;border:1px solid rgba(99,102,241,.3);border-radius:12px;overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.5);">
                            <canvas id="studyReaderCanvas" style="display:block;width:100%;height:auto;"></canvas>
                            <div id="studyReaderLoading" class="text-center text-muted py-5" style="font-size:.9rem;">
                                <div class="spinner-border spinner-border-sm text-info me-2" role="status"></div>
                                Loading page…
                            </div>
                        </div>
                        <!-- Completed banner (hidden until done) -->
                        <div id="studyCompletedBanner" class="d-none mt-4 p-4 rounded-3 text-center" style="background:linear-gradient(135deg,rgba(16,185,129,.18),rgba(6,182,212,.10));border:1px solid rgba(16,185,129,.35);">
                            <i class="bi bi-trophy-fill text-warning fs-2 d-block mb-2"></i>
                            <div class="fw-bold text-white mb-1">Module Complete!</div>
                            <div class="small text-muted">You have reviewed all pages and earned your study hours.</div>
                        </div>
                    </div>
                </div>

                <!-- Navigation footer -->
                <div class="modal-footer px-4 py-3 justify-content-between" style="background:rgba(255,255,255,.04);border-top:1px solid rgba(255,255,255,.08);">
                    <button id="studyPrevBtn" class="btn btn-glass" disabled>
                        <i class="bi bi-arrow-left me-1"></i>Previous
                    </button>
                    <div class="text-center">
                        <div id="studyPageCounter" class="small text-muted">Page 1 of ?</div>
                        <div id="studySubmitting" class="d-none small text-info"><span class="spinner-border spinner-border-sm me-1"></span>Saving…</div>
                    </div>
                    <button id="studyNextBtn" class="btn btn-gradient">
                        <i class="bi bi-arrow-right me-1"></i>Next Page <span class="badge bg-info bg-opacity-25 text-info ms-1">+1 hr</span>
                    </button>
                </div>

            </div>
        </div>
    </div>
    <!-- END MODULE STUDY READER MODAL -->

    <!-- Toast Notification Container -->
    <div class="toast-container-custom" id="toastContainer"></div>

    <!-- Bootstrap JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>

    <script>
        // ═══════════════════════════════════════════════════════════
        //  MODULE STUDY READER  — fullscreen modal, no page refresh
        // ═══════════════════════════════════════════════════════════
        (function () {
            if (!window.pdfjsLib) return;
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

            // Reader state
            let _pdf        = null;
            let _modId      = 0;
            let _curPage    = 1;
            let _totalPages = 0;
            let _pagesReq   = 0; // set by server on first load
            let _hours      = 0;
            let _earned     = 0;
            let _completed  = false;
            let _submitting = false;

            const modal      = document.getElementById('moduleStudyModal');
            const bsModal    = new bootstrap.Modal(modal, {backdrop:'static', keyboard:false});
            const canvas     = document.getElementById('studyReaderCanvas');
            const loading    = document.getElementById('studyReaderLoading');
            const prevBtn    = document.getElementById('studyPrevBtn');
            const nextBtn    = document.getElementById('studyNextBtn');
            const pageLabel  = document.getElementById('studyPageLabel');
            const hoursLbl   = document.getElementById('studyHoursLabel');
            const counter    = document.getElementById('studyPageCounter');
            const progressBar= document.getElementById('studyReaderProgress');
            const title      = document.getElementById('studyReaderTitle');
            const meta       = document.getElementById('studyReaderMeta');
            const submitting = document.getElementById('studySubmitting');
            const completedBanner = document.getElementById('studyCompletedBanner');
            const body       = document.getElementById('studyReaderBody');

            // ── Render a page onto the canvas ──
            async function renderPage(pageNo) {
                if (!_pdf) return;
                loading.classList.remove('d-none');
                canvas.style.display = 'none';
                try {
                    const page    = await _pdf.getPage(pageNo);
                    const base    = page.getViewport({scale: 1});
                    const maxW    = Math.min(860, (body.clientWidth || window.innerWidth) - 32);
                    
                    // Multiply scale by devicePixelRatio for crisp rendering on high-DPI screens
                    const dpr     = window.devicePixelRatio || 1;
                    const scale   = maxW / base.width;
                    const vp      = page.getViewport({scale: scale * dpr});
                    
                    canvas.width  = vp.width;
                    canvas.height = vp.height;
                    canvas.style.width = maxW + 'px';
                    canvas.style.height = (vp.height / dpr) + 'px';
                    
                    await page.render({canvasContext: canvas.getContext('2d'), viewport: vp}).promise;
                    canvas.style.display = 'block';
                } finally {
                    loading.classList.add('d-none');
                }
                updateUI();
                // Scroll reader body to top so user always sees the new page from the top
                body.scrollTo({top: 0, behavior: 'instant'});
            }

            // ── Sync all UI labels ──
            function updateUI() {
                pageLabel.innerHTML  = `<i class="bi bi-file-earmark-text me-1"></i>Page ${_curPage} of ${_totalPages}`;
                counter.textContent  = `Page ${_curPage} of ${_totalPages}`;
                hoursLbl.textContent = `${_earned} / ${_hours} study hours`;
                const pct = _totalPages > 0 ? (_curPage / _totalPages) * 100 : 0;
                progressBar.style.width = pct + '%';
                prevBtn.disabled = (_curPage <= 1) || _submitting;
                
                // Allow "Next" unless we are on the very last page
                nextBtn.disabled = (_curPage >= _totalPages) || _submitting;
                
                if (_curPage >= _totalPages && _completed) {
                    nextBtn.innerHTML = '<i class="bi bi-trophy-fill me-1"></i>Completed!';
                    nextBtn.classList.remove('btn-gradient');
                    nextBtn.classList.add('btn-success');
                } else {
                    nextBtn.innerHTML = _completed
                        ? `<i class="bi bi-arrow-right me-1"></i>Next Page`
                        : `<i class="bi bi-arrow-right me-1"></i>Next Page <span class="badge bg-info bg-opacity-25 text-info ms-1">+1 hr</span>`;
                    nextBtn.classList.add('btn-gradient');
                    nextBtn.classList.remove('btn-success');
                }

                if (_completed) {
                    completedBanner.classList.remove('d-none');
                } else {
                    completedBanner.classList.add('d-none');
                }
            }

            // ── Submit progress to server ──
            async function submitProgress(direction) {
                if (_submitting) return;
                _submitting = true;
                submitting.classList.remove('d-none');
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                try {
                    const fd = new FormData();
                    fd.append('action', 'update_module_progress');
                    fd.append('module_id', _modId);
                    fd.append('direction', direction);
                    fd.append('ajax', '1');
                    fd.append('pages_required', _pagesReq || _totalPages);
                    const res = await fetch('', {method:'POST', body:fd});
                    const data = await res.json();
                    if (data.ok) {
                        _curPage   = Math.min(Math.max(1, data.page), _totalPages);
                        _earned    = data.earned !== undefined ? data.earned : _earned;
                        _pagesReq  = _pagesReq || _totalPages;
                        if (data.completed) {
                            _completed = true;
                            showToast('🏆 Module Complete!', data.message, 'success');
                            // Update the card button in the background
                            const cardBtn = document.querySelector(`.module-start-btn[data-module-id="${_modId}"]`);
                            if (cardBtn) {
                                cardBtn.dataset.completed = '1';
                                cardBtn.dataset.earned = _earned;
                                cardBtn.innerHTML = '<i class="bi bi-trophy-fill me-1"></i>Review Module Again';
                                // Update badge on card
                                const card = cardBtn.closest('.d-flex.flex-column');
                                const badgeEl = card?.querySelector('.badge.bg-primary');
                                if (badgeEl) { badgeEl.className = 'badge bg-success'; badgeEl.innerHTML = '<i class="bi bi-trophy-fill me-1"></i>Completed'; }
                            }
                        }
                        await renderPage(_curPage);
                    }
                } catch(e) { console.error(e); }
                finally {
                    _submitting = false;
                    submitting.classList.add('d-none');
                    updateUI();
                }
            }

            // ── Open the reader ──
            async function openReader(btn) {
                const pdfSrc    = btn.dataset.pdfSrc;
                _modId          = btn.dataset.moduleId;
                _curPage        = Math.max(1, Number(btn.dataset.currentPage) || 1);
                _pagesReq       = Number(btn.dataset.pagesRequired) || 0;
                _hours          = Number(btn.dataset.hours) || 0;
                _earned         = Number(btn.dataset.earned) || 0;
                _completed      = btn.dataset.completed === '1';
                _submitting     = false;
                title.textContent = btn.dataset.title || 'Study Module';
                meta.textContent  = `${_hours} Hour Module`;
                completedBanner.classList.add('d-none');
                // Show modal first so canvas has real dimensions
                bsModal.show();
                // Load PDF
                loading.classList.remove('d-none');
                canvas.style.display = 'none';
                try {
                    _pdf        = await pdfjsLib.getDocument(pdfSrc).promise;
                    _totalPages = _pdf.numPages;
                    if (!_pagesReq || _pagesReq < 1) _pagesReq = _totalPages;
                    _curPage    = Math.min(_curPage, _totalPages);
                    await renderPage(_curPage);
                } catch(e) {
                    loading.textContent = 'Unable to load PDF.';
                }
            }

            // ── Wire up start buttons ──
            document.querySelectorAll('.module-start-btn').forEach(btn => {
                btn.addEventListener('click', () => openReader(btn));
            });

            // ── Navigation ──
            prevBtn.addEventListener('click', async () => {
                if (_curPage <= 1 || _submitting) return;
                await submitProgress('previous');
            });
            nextBtn.addEventListener('click', async () => {
                if (_curPage >= _totalPages || _submitting) return;
                await submitProgress('next');
            });

            // ── Close btn ──
            document.getElementById('closeStudyReaderBtn').addEventListener('click', () => {
                bsModal.hide();
                _pdf = null;
            });

        })();

        function toggleNotifDropdown() {
            const dd = document.getElementById('notifDropdown');
            if (!dd) return;
            dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
        }
        function closeNotifDropdown() {
            const dd = document.getElementById('notifDropdown');
            if (dd) dd.style.display = 'none';
        }
        // Close when clicking outside
        document.addEventListener('click', function(e) {
            const wrap = document.getElementById('notifBellWrap');
            if (wrap && !wrap.contains(e.target)) closeNotifDropdown();
        });

        // ─── Mark messages as read when modal opens ────────────────────────
        function markMessagesReadOnOpen(senderEmail = '') {
            // Visually clear badges immediately
            const badge = document.getElementById('msgUnreadBadge');
            if (!senderEmail && badge) badge.style.display = 'none';
            // Mark items as read visually
            let cleared = 0;
            document.querySelectorAll('.msg-inbox-item.unread').forEach(el => {
                if (senderEmail && el.closest('.conversation-thread')?.dataset.sender !== senderEmail) return;
                el.classList.remove('unread');
                el.classList.add('read');
                cleared++;
            });
            if (senderEmail) {
                document.querySelector('.conversation-thread[data-sender="' + CSS.escape(senderEmail) + '"] .conversation-thread-heading .badge')?.remove();
                const currentBadge = document.getElementById('msgUnreadBadge');
                const nextCount = Math.max(0, Number(currentBadge?.textContent || 0) - cleared);
                [currentBadge].forEach(badgeEl => {
                    if (!badgeEl) return;
                    if (nextCount) badgeEl.textContent = nextCount;
                    else badgeEl.remove();
                });
                [document.getElementById('notifUnreadBadge'), document.getElementById('notifUnreadHeaderBadge')].forEach(badgeEl => {
                    if (!badgeEl) return;
                    const notificationCount = Math.max(0, Number(badgeEl.textContent || 0) - cleared);
                    if (notificationCount) badgeEl.textContent = notificationCount;
                    else badgeEl.remove();
                });
            }
            // Submit mark-read to server via fetch (no page reload)
            const fd = new FormData();
            fd.append('action', 'mark_messages_read');
            if (senderEmail) fd.append('sender_email', senderEmail);
            fetch('', { method: 'POST', body: fd }).catch(() => {});
        }

        function toggleConversationThread(button) {
            const selected = button.closest('.conversation-thread');
            const body = selected?.querySelector('.conversation-thread-body');
            if (!body) return;
            document.querySelectorAll('.conversation-thread-body').forEach(panel => {
                if (panel !== body) panel.classList.add('d-none');
            });
            body.classList.toggle('d-none');
        }

        function previewProfileAvatar(input) {
            if (!input.files || !input.files[0]) return;
            const reader = new FileReader();
            reader.onload = event => document.getElementById('profileAvatarPreview').innerHTML = '<img src="' + event.target.result + '" alt="Profile preview" style="width:100%;height:100%;object-fit:cover;">';
            reader.readAsDataURL(input.files[0]);
        }

        function syncProfilePreview() {
            const headline = document.getElementById('profileHeadlineInput')?.value.trim();
            const name = document.getElementById('profileNameInput')?.value.trim();
            const phone = document.getElementById('profilePhoneInput')?.value.trim();
            const location = document.getElementById('profileLocationInput')?.value.trim();
            if (headline) document.getElementById('profileResumeHeadline').textContent = headline;
            if (name) document.getElementById('profileResumeName').textContent = name;
            document.getElementById('profileResumePhone').textContent = phone || 'Add phone number';
            document.getElementById('profileResumeLocation').textContent = location || <?php echo json_encode(html_entity_decode($school, ENT_QUOTES, 'UTF-8')); ?>;
        }

        // Auto-mark read when messages modal is shown
        document.addEventListener('DOMContentLoaded', function() {
            const msgModal = document.getElementById('messagesModal');
            document.querySelectorAll('.conversation-thread').forEach(thread => {
                thread.addEventListener('click', () => markMessagesReadOnOpen(thread.dataset.sender || ''));
            });
        });

        // Toggle inline clearance file preview strip
        function toggleClearancePreview(type) {
            const body    = document.getElementById('clearPreviewBody_' + type);
            const chevron = document.getElementById('clearChevron_' + type);
            if (!body) return;
            const isOpen = body.style.maxHeight !== '0px' && body.style.maxHeight !== '';
            if (isOpen) {
                body.style.maxHeight = '0px';
                body.style.padding   = '0';
                if (chevron) chevron.className = 'bi bi-chevron-down';
            } else {
                body.style.maxHeight = '180px';
                body.style.padding   = '4px';
                if (chevron) chevron.className = 'bi bi-chevron-up';
            }
        }

        function handleClearanceItemClick(type, hasFile, title, sub) {
            if (hasFile) {
                toggleClearancePreview(type);
            } else {
                openClearanceUploadModal(type, title, sub);
            }
        }

        // Filter jobs
        function filterStudentJobs() {
            const query = (document.getElementById("studentJobSearch").value || "").toLowerCase();
            const cards = document.querySelectorAll(".student-job-card");
            cards.forEach(card => {
                const title = (card.getAttribute("data-title") || "").toLowerCase();
                const company = (card.getAttribute("data-company") || "").toLowerCase();
                const skills = (card.getAttribute("data-skills") || "").toLowerCase();
                if (!query || title.includes(query) || company.includes(query) || skills.includes(query)) {
                    card.style.display = "block";
                } else {
                    card.style.display = "none";
                }
            });
        }

        // Apply with attachments
        function openApplyAttachmentModal(jobId, jobTitle, compName, compEmail, reqDocs) {
            document.getElementById("modalApplyJobId").value = jobId;
            document.getElementById("modalApplyJobTitleInput").value = jobTitle;
            document.getElementById("modalApplyCompNameInput").value = compName;
            document.getElementById("modalApplyCompEmailInput").value = compEmail;
            document.getElementById("modalApplyJobTitle").textContent = "Apply for " + jobTitle;
            document.getElementById("modalApplyCompName").textContent = compName;
            document.getElementById("modalApplyReqDocs").textContent = "Requirements demanded by company: " + reqDocs;

            new bootstrap.Modal(document.getElementById("applyAttachmentModal")).show();
        }

        // Student clearance upload
        function openClearanceUploadModal(type, title, sub, existingFile = '') {
            document.getElementById("clearanceTypeInput").value = type;
            document.getElementById("clearanceModalTitle").textContent = "Submit " + title;
            document.getElementById("clearanceModalSub").textContent = sub;
            const existingPanel = document.getElementById('clearExistingDocument');
            const existingLink = document.getElementById('clearExistingFileLink');
            const existingIframe = document.getElementById('clearExistingIframe');
            existingPanel.classList.toggle('d-none', !existingFile);
            existingLink.href = existingFile || '#';
            existingIframe.src = existingFile || '';
            // Reset form state
            switchClearanceTab('file');
            const fileInput = document.getElementById("clearanceFile");
            fileInput.value = "";
            updateFileUploadName(fileInput);
            clearFilePreview('clearanceFile', 'clearFilePreview');
            document.getElementById("clearAttachLink").value = "";
            hideLinkPreview('clearLinkPreview');
            new bootstrap.Modal(document.getElementById("clearanceUploadModal")).show();
        }

        function removeCurrentClearance() {
            if (!confirm('Remove this uploaded document? You can submit a new one afterwards.')) return;
            const form = document.getElementById('clearanceForm');
            form.querySelector('input[name="action"]').value = 'remove_clearance_item';
            form.submit();
        }

        function updateFileUploadName(input) {
            const ctrl = input.closest(".file-upload-control");
            if (!ctrl) return;
            const nameElement = ctrl.querySelector(".file-upload-name");
            if (nameElement) nameElement.textContent = input.files.length ? input.files[0].name : "No file selected";
        }

        // ─── Attachment Tab Switchers ───────────────────────────
        function switchApplyTab(mode) {
            document.getElementById('applyAttachMode').value = mode;
            const tabFile = document.getElementById('applyTabFile');
            const tabLink = document.getElementById('applyTabLink');
            const fileWrap = document.getElementById('applyFilePanelWrap');
            const linkWrap = document.getElementById('applyLinkPanelWrap');
            if (mode === 'file') {
                tabFile.classList.add('active'); tabLink.classList.remove('active');
                fileWrap.classList.remove('d-none'); linkWrap.classList.add('d-none');
            } else {
                tabLink.classList.add('active'); tabFile.classList.remove('active');
                linkWrap.classList.remove('d-none'); fileWrap.classList.add('d-none');
            }
        }

        function switchClearanceTab(mode) {
            document.getElementById('clearanceAttachMode').value = mode;
            const tabFile = document.getElementById('clearTabFile');
            const tabLink = document.getElementById('clearTabLink');
            const fileWrap = document.getElementById('clearFilePanelWrap');
            const linkWrap = document.getElementById('clearLinkPanelWrap');
            if (mode === 'file') {
                tabFile.classList.add('active'); tabLink.classList.remove('active');
                fileWrap.classList.remove('d-none'); linkWrap.classList.add('d-none');
            } else {
                tabLink.classList.add('active'); tabFile.classList.remove('active');
                linkWrap.classList.remove('d-none'); fileWrap.classList.add('d-none');
            }
        }

        // ─── File Preview Logic ──────────────────────────────────
        function renderFilePreview(file, previewContainerId, previewBodyId) {
            const container = document.getElementById(previewContainerId);
            const body = document.getElementById(previewBodyId);
            if (!file || !container || !body) return;

            container.classList.remove('d-none');
            body.innerHTML = '';

            const url = URL.createObjectURL(file);
            if (file.type === 'application/pdf') {
                // Embed PDF viewer - shows pages natively
                const iframe = document.createElement('iframe');
                iframe.src = url + '#toolbar=1&navpanes=1&scrollbar=1&view=FitH';
                iframe.className = 'file-preview-iframe';
                iframe.title = 'PDF Preview';
                body.appendChild(iframe);
            } else if (file.type.startsWith('image/')) {
                const img = document.createElement('img');
                img.src = url;
                img.className = 'file-preview-img';
                img.alt = 'Preview';
                body.appendChild(img);
            }
        }

        function clearFilePreview(inputId, previewContainerId) {
            const input = document.getElementById(inputId);
            const container = document.getElementById(previewContainerId);
            if (input) { try { input.value = ''; } catch(e) {} }
            if (container) {
                container.classList.add('d-none');
                const body = container.querySelector('.file-preview-body');
                if (body) body.innerHTML = '';
            }
            if (input) updateFileUploadName(input);
        }

        function hideLinkPreview(previewContainerId) {
            const container = document.getElementById(previewContainerId);
            if (container) container.classList.add('d-none');
        }

        // ─── Link Preview Logic ──────────────────────────────────
        function setupLinkPreview(inputId, previewContainerId, iframeId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            let debounceTimer;
            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    const url = input.value.trim();
                    const container = document.getElementById(previewContainerId);
                    const iframe = document.getElementById(iframeId);
                    if (url && container && iframe) {
                        // Convert Google Drive view links to embeddable format
                        let embedUrl = url;
                        const gdMatch = url.match(/\/file\/d\/([^/]+)/);
                        if (gdMatch) embedUrl = 'https://drive.google.com/file/d/' + gdMatch[1] + '/preview';
                        const gdViewMatch = url.match(/id=([^&]+)/);
                        if (gdViewMatch && url.includes('drive.google.com') && !gdMatch) {
                            embedUrl = 'https://drive.google.com/file/d/' + gdViewMatch[1] + '/preview';
                        }
                        iframe.src = embedUrl;
                        container.classList.remove('d-none');
                    } else if (container) {
                        container.classList.add('d-none');
                    }
                }, 600);
            });
        }

        // ─── Wire up all file inputs ─────────────────────────────
        document.querySelectorAll("[data-file-input]").forEach(input => {
            input.addEventListener("change", () => {
                updateFileUploadName(input);
                const previewTarget = input.getAttribute('data-preview-target');
                if (previewTarget && input.files.length) {
                    const bodyId = previewTarget.replace('Preview', 'PreviewBody');
                    renderFilePreview(input.files[0], previewTarget, bodyId);
                }
            });
        });

        // Wire link previews
        setupLinkPreview('applyAttachLink', 'applyLinkPreview', 'applyLinkIframe');
        setupLinkPreview('clearAttachLink', 'clearLinkPreview', 'clearLinkIframe');

        // Reset apply modal when closed
        document.getElementById('applyAttachmentModal').addEventListener('hidden.bs.modal', function() {
            switchApplyTab('file');
            clearFilePreview('applicationFile', 'applyFilePreview');
            document.getElementById('applyAttachLink').value = '';
            hideLinkPreview('applyLinkPreview');
        });

        // Admin clearance editor
        function openAdminClearanceEditor(email, name, moa, end, waiver, med) {
            document.getElementById("admClearanceEmail").value = email;
            document.getElementById("admClearanceName").value = name;
            document.getElementById("admClearanceStudentName").textContent = name + " (" + email + ")";
            document.getElementById("admMoaSelect").value = moa;
            document.getElementById("admEndorsementSelect").value = end;
            document.getElementById("admWaiverSelect").value = waiver;
            document.getElementById("admMedicalSelect").value = med;
            new bootstrap.Modal(document.getElementById("adminClearanceModal")).show();
        }

        // ─── Student Clearance Detail Modal ────────────────────────────────────
        let _scdCurrentEmail = '';
        let _scdCurrentName  = '';
        let _scdCurrentMoa   = '';
        let _scdCurrentEnd   = '';
        let _scdCurrentWaiver= '';
        let _scdCurrentMed   = '';

        const SCD_DOCS = [
            { label: 'Memorandum of Agreement (MoA)',  field: 'moa_status',         fileKey: 'moaFile',         acceptVal: 'Verified'  },
            { label: 'Endorsement Letter',              field: 'endorsement_status',  fileKey: 'endorsementFile', acceptVal: 'Verified'  },
            { label: "Parents' Consent & Waiver",       field: 'waiver_status',       fileKey: 'waiverFile',      acceptVal: 'Verified' },
            { label: 'Medical & Drug Test Clearance',   field: 'medical_status',      fileKey: 'medicalFile',     acceptVal: 'Verified'    },
        ];

        function openStudentClearanceDetail(email, name, course, campus, avatar, docCount, moaSt, endSt, waiverSt, medSt, moaFile, endFile, waiverFile, medFile, isCompleted) {
            _scdCurrentEmail  = email;
            _scdCurrentName   = name;
            _scdCurrentMoa    = moaSt;
            _scdCurrentEnd    = endSt;
            _scdCurrentWaiver = waiverSt;
            _scdCurrentMed    = medSt;

            // ── Profile card ──────────────────────────────────────────────────
            const avatarWrap = document.getElementById('scdAvatarWrap');
            if (avatar && avatar.trim()) {
                avatarWrap.innerHTML = `<img src="${avatar}" class="scd-avatar-img" alt="${name}">` +
                    (isCompleted ? '<span class="scd-cleared-badge" title="Enrolled & Cleared"><i class="bi bi-patch-check-fill"></i></span>' : '');
            } else {
                const initials = name.trim().charAt(0).toUpperCase();
                avatarWrap.innerHTML = `<div class="scd-avatar-initials">${initials}</div>` +
                    (isCompleted ? '<span class="scd-cleared-badge" title="Enrolled & Cleared"><i class="bi bi-patch-check-fill"></i></span>' : '');
            }
            document.getElementById('scdStudentName').textContent = name;
            document.getElementById('scdStudentMeta').textContent = course + ' • ' + campus + ' • ' + email;
            document.getElementById('scdDocCount').textContent = docCount;

            // ── Documents list ────────────────────────────────────────────────
            const files    = [moaFile, endFile, waiverFile, medFile];
            const statuses = [moaSt,  endSt,   waiverSt,   medSt  ];
            const docList  = document.getElementById('scdDocList');
            docList.innerHTML = '';

            SCD_DOCS.forEach((doc, i) => {
                const file   = files[i];
                const status = statuses[i];
                const hasFile = file && file.trim();

                // Determine status style
                const isAccepted = (status === doc.acceptVal);
                const isDeclined = (status === 'Declined');
                let statusBadge  = `<span class="badge ${(!status || status === 'Pending') ? 'bg-warning text-dark' : 'bg-secondary'}">${status || 'Pending'}</span>`;
                if (isAccepted) statusBadge = `<span class="badge bg-info text-dark"><i class="bi bi-check-lg me-1"></i>${status}</span>`;
                else if (isDeclined) statusBadge = `<span class="badge bg-danger"><i class="bi bi-x-lg me-1"></i>Declined</span>`;
                else if (status === 'Submitted') statusBadge = `<span class="badge bg-warning text-dark"><i class="bi bi-clock me-1"></i>Submitted</span>`;

                // Build preview
                let previewHtml = '';
                if (hasFile) {
                    const ext = file.split('.').pop().toLowerCase();
                    const isPdf = ext === 'pdf';
                    // Check if it's an external link (http)
                    const isLink = file.startsWith('http');
                    let embedSrc = file;
                    if (isLink) {
                        // Try to convert Google Drive link
                        const gdMatch = file.match(/\/file\/d\/([^/]+)/);
                        if (gdMatch) embedSrc = 'https://drive.google.com/file/d/' + gdMatch[1] + '/preview';
                    }
                    if (isPdf || isLink) {
                        previewHtml = `<div class="scd-doc-preview mt-2">
                            <iframe src="${embedSrc}${isPdf && !isLink ? '#toolbar=0&navpanes=0&view=FitH' : ''}" class="scd-doc-iframe" title="Document"></iframe>
                        </div>`;
                    } else {
                        previewHtml = `<div class="scd-doc-preview mt-2">
                            <img src="${file}" class="scd-doc-img" alt="Document">
                        </div>`;
                    }
                }

                // Build action buttons (only when file is submitted but not yet accepted/declined)
                let actionBtns = '';
                if (hasFile && !isAccepted) {
                    actionBtns = `
                        <form method="POST" action="" class="d-inline" onsubmit="return confirmAccept()">
                            <input type="hidden" name="action" value="accept_clearance_item">
                            <input type="hidden" name="student_email" value="${email}">
                            <input type="hidden" name="clearance_field" value="${doc.field}">
                            <button type="submit" class="btn btn-sm scd-accept-btn"><i class="bi bi-check-circle-fill me-1"></i>Accept</button>
                        </form>
                        <form method="POST" action="" class="d-inline" onsubmit="return confirmDecline()">
                            <input type="hidden" name="action" value="decline_clearance_item">
                            <input type="hidden" name="student_email" value="${email}">
                            <input type="hidden" name="clearance_field" value="${doc.field}">
                            <button type="submit" class="btn btn-sm scd-decline-btn"><i class="bi bi-x-circle-fill me-1"></i>Decline</button>
                        </form>`;
                } else if (isAccepted) {
                    actionBtns = `<span class="small text-success"><i class="bi bi-check-all me-1"></i>Accepted</span>`;
                }

                const card = document.createElement('div');
                card.className = 'scd-doc-card' + (isAccepted ? ' accepted' : '') + (isDeclined ? ' declined' : '') + (!hasFile ? ' no-file' : '');
                card.innerHTML = `
                    <div class="scd-doc-card-header">
                        <div class="scd-doc-card-title">
                            <i class="bi bi-file-earmark-text me-2 ${isAccepted ? 'text-success' : isDeclined ? 'text-danger' : hasFile ? 'text-warning' : 'text-muted'}"></i>
                            <span>${doc.label}</span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            ${statusBadge}
                            ${hasFile ? `<a href="${file}" target="_blank" class="btn btn-glass btn-sm py-0 px-2" title="Open in new tab"><i class="bi bi-box-arrow-up-right"></i></a>` : ''}
                        </div>
                    </div>
                    ${!hasFile ? '<div class="scd-no-file-msg"><i class="bi bi-hourglass me-1"></i>No document submitted yet.</div>' : previewHtml}
                    ${actionBtns ? '<div class="scd-action-row">' + actionBtns + '</div>' : ''}
                `;
                docList.appendChild(card);
            });

            new bootstrap.Modal(document.getElementById('studentClearanceDetailModal')).show();
        }

        function confirmAccept()  { return confirm('Accept this document? This will update the clearance status.'); }
        function confirmDecline() { return confirm('Decline this document? The student will need to resubmit.'); }

        function openScdMessage() {
            // Pre-fill message modal with student
            const recvEmail = document.getElementById('msgReceiverEmail');
            const recvName  = document.getElementById('msgReceiverName');
            if (recvEmail) recvEmail.value = _scdCurrentEmail;
            if (recvName)  recvName.value  = _scdCurrentName;
            // Close detail modal, open message modal
            bootstrap.Modal.getInstance(document.getElementById('studentClearanceDetailModal'))?.hide();
            new bootstrap.Modal(document.getElementById('messagesModal')).show();
        }

        function openModuleCreateModal() {
            // Reset create modal state
            switchModuleTab('create', 'file');
            clearFilePreview('createModFile', 'createModPreview');
            document.getElementById('createModLink').value = '';
            new bootstrap.Modal(document.getElementById("createModuleModal")).show();
        }

        function openModuleEditModal(id, title, course, hours, level, desc, link, guideFile) {
            document.getElementById("editModuleId").value = id;
            document.getElementById("editModuleTitle").value = title;
            // Set course select
            const courseSelect = document.getElementById("editModuleCourse");
            if (courseSelect) {
                // Try to match existing option; if not found keep current
                const opt = Array.from(courseSelect.options).find(o => o.value === course);
                if (opt) courseSelect.value = course;
                else { const custom = new Option(course, course); courseSelect.add(custom); courseSelect.value = course; }
            }
            document.getElementById("editModuleHours").value = hours;
            // Set level select
            const levelSelect = document.getElementById("editModuleLevel");
            if (levelSelect) levelSelect.value = level || 'All Levels';
            document.getElementById("editModuleDesc").value = desc;

            // Determine which tab to show based on existing data
            if (guideFile && guideFile.trim()) {
                // Has existing file — switch to file tab, show existing file info
                switchModuleTab('edit', 'file');
                const existInfo = document.getElementById('editModExistingFileInfo');
                const existName = document.getElementById('editModExistingFileName');
                if (existInfo && existName) {
                    existName.textContent = guideFile.split('/').pop();
                    existInfo.classList.remove('d-none');
                }
            } else {
                switchModuleTab('edit', 'link');
                document.getElementById("editModuleLink").value = link || '';
                document.getElementById('editModExistingFileInfo').classList.add('d-none');
            }
            clearFilePreview('editModFile', 'editModPreview');
            new bootstrap.Modal(document.getElementById("editModuleModal")).show();
        }

        // Module guide tab switcher
        function switchModuleTab(modalPrefix, mode) {
            const modeInput = document.getElementById(modalPrefix + 'ModuleGuideMode');
            const tabFile   = document.getElementById(modalPrefix + 'ModTabFile');
            const tabLink   = document.getElementById(modalPrefix + 'ModTabLink');
            const fileWrap  = document.getElementById(modalPrefix + 'ModFileWrap');
            const linkWrap  = document.getElementById(modalPrefix + 'ModLinkWrap');
            if (modeInput) modeInput.value = mode;
            if (mode === 'file') {
                if (tabFile)  tabFile.classList.add('active');
                if (tabLink)  tabLink.classList.remove('active');
                if (fileWrap) fileWrap.classList.remove('d-none');
                if (linkWrap) linkWrap.classList.add('d-none');
            } else {
                if (tabLink)  tabLink.classList.add('active');
                if (tabFile)  tabFile.classList.remove('active');
                if (linkWrap) linkWrap.classList.remove('d-none');
                if (fileWrap) fileWrap.classList.add('d-none');
            }
        }

        // Toggle inline module preview on student card
        function toggleModulePreview(containerId, btn) {
            const container = document.getElementById(containerId);
            const toggleBtn = document.getElementById(containerId + '_btn');
            if (!container) return;
            const isHidden = container.classList.contains('d-none');
            if (isHidden) {
                container.classList.remove('d-none');
                if (toggleBtn) toggleBtn.innerHTML = '<i class="bi bi-eye-slash me-1"></i> Hide Preview';
                // Smooth scroll to preview
                container.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                container.classList.add('d-none');
                if (toggleBtn) toggleBtn.innerHTML = '<i class="bi bi-eye me-1"></i> View Guide';
            }
        }

        // Company sign DTR modal
        function openDtrReviewModal(id, student, hours, task) {
            document.getElementById("signDtrId").value = id;
            document.getElementById("signDtrStudentInfo").textContent = "Trainee: " + student;
            document.getElementById("signDtrTask").textContent = task;
            document.getElementById("signDtrHours").textContent = hours + " Rendered Hours";
            new bootstrap.Modal(document.getElementById("signDtrModal")).show();
        }

        // Report company modal
        function openReportModal(companyName) {
            document.getElementById("reportCompName").value = companyName;
            document.getElementById("reportCompNameDisplay").value = companyName;
            new bootstrap.Modal(document.getElementById("reportCompanyModal")).show();
        }

        // Offer Company modal (create or edit)
        function openOfferModal(id = 0, company = '', title = '', desc = '', course = 'BSIT', slots = 1) {
            document.getElementById('offerIdInput').value = id;
            document.getElementById('offerCompanyInput').value = company;
            document.getElementById('offerTitleInput').value = title;
            document.getElementById('offerDescInput').value = desc;
            const courseSelect = document.getElementById('offerCourseInput');
            if (courseSelect) {
                const opt = Array.from(courseSelect.options).find(o => o.value === course);
                if (opt) courseSelect.value = course;
                else { courseSelect.add(new Option(course, course)); courseSelect.value = course; }
            }
            document.getElementById('offerSlotsInput').value = slots;
            new bootstrap.Modal(document.getElementById('offerModal')).show();
        }

        // Student: filter approved company offers by name/role and course
        function filterStudentOffers() {
            const nameQuery   = (document.getElementById('offerNameSearch')?.value || '').trim().toLowerCase();
            const courseQuery = (document.getElementById('offerCourseSearch')?.value || '').trim().toLowerCase();
            document.querySelectorAll('.student-offer-card').forEach(card => {
                const haystack = [(card.dataset.company || ''), (card.dataset.title || '')].join(' ');
                const matchesName   = !nameQuery   || haystack.includes(nameQuery);
                const cardCourse = card.dataset.course || '';
                const matchesCourse = !courseQuery || cardCourse.includes(courseQuery) || cardCourse.includes('all courses');
                card.style.display = (matchesName && matchesCourse) ? '' : 'none';
            });
        }

        // Admin student table filter: by name/ID/email plus course
        function filterAdminStudents() {
            const nameQuery   = (document.getElementById('adminStudentNameFilter')?.value || '').trim().toLowerCase();
            const courseQuery = (document.getElementById('adminStudentCourseFilter')?.value || '').trim().toLowerCase();
            document.querySelectorAll('.admin-student-row').forEach(row => {
                const haystack = [(row.dataset.name || ''), (row.dataset.email || ''), (row.dataset.id || '')].join(' ');
                const matchesName   = !nameQuery   || haystack.includes(nameQuery);
                const matchesCourse = !courseQuery || (row.dataset.course || '').includes(courseQuery);
                row.style.display = (matchesName && matchesCourse) ? '' : 'none';
            });
        }

        // Direct Messaging helper
        function updateRecipientName(select) {
            const opt = select.options[select.selectedIndex];
            document.getElementById("msgReceiverName").value = opt.getAttribute("data-name") || "";
        }

        function replyToUser(email, name) {
            const select = document.getElementById("msgRecipientSelect");
            select.value = email;
            document.getElementById("msgReceiverName").value = name;
        }

        function openCompanyConversation(email, name) {
            replyToUser(email, name);
            const compose = document.querySelector('#messagesModal .message-thread-list details');
            if (compose) compose.open = true;
        }

        // Toast Helper
        function showToast(title, message, type = "success") {
            const container = document.getElementById("toastContainer");
            if (!container) return;

            let iconClass = "bi-check-circle-fill text-success";
            if (type === "warning") iconClass = "bi-exclamation-triangle-fill text-warning";
            if (type === "info") iconClass = "bi-info-circle-fill text-info";

            const toast = document.createElement("div");
            toast.className = "toast-glass";
            toast.innerHTML = `
                <i class="bi ${iconClass} fs-4"></i>
                <div style="flex: 1;">
                    <div class="fw-bold small text-white">${title}</div>
                    <div class="text-muted small" style="font-size: 0.82rem;">${message}</div>
                </div>
                <button type="button" class="btn-close btn-close-white btn-sm" onclick="this.parentElement.remove()"></button>
            `;
            container.appendChild(toast);

            setTimeout(() => {
                toast.style.transition = "opacity 0.4s ease, transform 0.4s ease";
                toast.style.opacity = "0";
                toast.style.transform = "translateX(50px)";
                setTimeout(() => toast.remove(), 400);
            }, 4000);
        }

        <?php if (!empty($toastMessage)): ?>
            showToast("System Update", <?php echo json_encode($toastMessage); ?>, <?php echo json_encode($toastType); ?>);
        <?php endif; ?>
    </script>
    <script>
        // Auto-open messages modal if requested via URL (e.g., from feed.php)
        document.addEventListener('DOMContentLoaded', () => {
            const params = new URLSearchParams(window.location.search);
            const openTarget = params.get('open');
            if (openTarget === 'notifications') {
                toggleNotifDropdown();
            } else if (openTarget === 'messages' || openTarget === 'profile') {
                const modalId = openTarget === 'messages' ? 'messagesModal' : 'profileModal';
                const modalElement = document.getElementById(modalId);
                if (modalElement) new bootstrap.Modal(modalElement).show();
            }
            const msgEmail = params.get('msg_email');
            const msgName = params.get('msg_name');
            if (msgEmail && msgName && typeof replyToUser === 'function') {
                replyToUser(msgEmail, msgName);
                const msgModalEl = document.getElementById('messagesModal');
                if (msgModalEl) {
                    const bsModal = new bootstrap.Modal(msgModalEl);
                    bsModal.show();
                }
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });

        let lastPolledMessageId = <?php echo (int)$latestMessageId; ?>;
        const currentMessageEmail = <?php echo json_encode($email); ?>;

        async function pollDashboardMessages() {
            if (document.visibilityState !== 'visible') return;
            const formData = new FormData();
            formData.append('action', 'poll_messages');
            formData.append('after_id', String(lastPolledMessageId));
            try {
                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                if (!response.ok) throw new Error('Message refresh failed with HTTP ' + response.status);
                const payload = await response.json();
                if (!Array.isArray(payload.messages)) throw new Error(payload.error || 'Invalid message refresh response');
                payload.messages.forEach(message => {
                    lastPolledMessageId = Math.max(lastPolledMessageId, Number(message.id) || 0);
                    let inbox = document.getElementById('inboxList');
                    if (!inbox) {
                        const conversationPane = document.querySelector('.message-conversation');
                        if (!conversationPane) return;
                        conversationPane.querySelector('p')?.remove();
                        inbox = document.createElement('div');
                        inbox.id = 'inboxList';
                        inbox.className = 'd-flex flex-column gap-2';
                        inbox.style.cssText = 'max-height:430px;overflow-y:auto';
                        conversationPane.appendChild(inbox);
                    }
                    const otherEmail = message.other_email;
                    let thread = Array.from(inbox.querySelectorAll('.conversation-thread')).find(item => item.dataset.sender === otherEmail);
                    if (!thread) {
                        thread = document.createElement('section');
                        thread.className = 'conversation-thread';
                        thread.dataset.sender = otherEmail;
                        const heading = document.createElement('button');
                        heading.type = 'button';
                        heading.className = 'conversation-thread-heading w-100 border-0';
                        heading.addEventListener('click', () => toggleConversationThread(heading));
                        const identity = document.createElement('span');
                        identity.className = 'd-flex align-items-center gap-2 text-start';
                        const avatar = document.createElement('span');
                        avatar.className = 'rounded-circle overflow-hidden d-inline-flex align-items-center justify-content-center bg-primary text-white';
                        avatar.style.cssText = 'width:34px;height:34px;flex:0 0 34px';
                        avatar.textContent = (message.other_name || 'U').trim().charAt(0).toUpperCase();
                        const title = document.createElement('strong');
                        title.className = 'text-white small d-block';
                        title.textContent = message.other_name || 'Conversation';
                        identity.append(avatar, title);
                        heading.append(identity);
                        const body = document.createElement('div');
                        body.className = 'conversation-thread-body';
                        const replyForm = document.createElement('form');
                        replyForm.method = 'POST';
                        replyForm.className = 'message-reply-form';
                        replyForm.innerHTML = '<input type="hidden" name="action" value="send_message"><textarea name="message" class="glass-input" rows="2" required></textarea><button type="submit" class="btn btn-gradient btn-sm"><i class="bi bi-send-fill"></i></button>';
                        const receiverInput = document.createElement('input');
                        receiverInput.type = 'hidden';
                        receiverInput.name = 'receiver_email';
                        receiverInput.value = otherEmail;
                        const receiverNameInput = document.createElement('input');
                        receiverNameInput.type = 'hidden';
                        receiverNameInput.name = 'receiver_name';
                        receiverNameInput.value = message.other_name || 'Conversation';
                        replyForm.prepend(receiverNameInput);
                        replyForm.prepend(receiverInput);
                        body.append(replyForm);
                        thread.append(heading, body);
                        inbox.prepend(thread);
                    }

                    const threadBody = thread.querySelector('.conversation-thread-body');
                    const bubble = document.createElement('div');
                    const incoming = message.incoming === true;
                    bubble.className = 'msg-inbox-item chat-bubble ' + (incoming ? 'incoming unread' : 'outgoing read');
                    const sender = document.createElement('strong');
                    sender.className = 'text-white small d-block mb-1';
                    sender.textContent = incoming ? (message.sender_name || message.other_name) : 'You';
                    const content = document.createElement('div');
                    content.className = 'small text-white mb-1';
                    content.textContent = message.message || '';
                    const timestamp = document.createElement('div');
                    timestamp.className = 'text-dim';
                    timestamp.style.fontSize = '0.7rem';
                    timestamp.textContent = new Date(message.created_at.replace(' ', 'T')).toLocaleString();
                    bubble.append(sender, content, timestamp);
                    threadBody.insertBefore(bubble, threadBody.firstChild);
                    if (incoming && document.getElementById('messagesModal')?.classList.contains('show') && !threadBody.classList.contains('d-none')) {
                        markMessagesReadOnOpen(otherEmail);
                    }
                });
                const unreadCount = Number(payload.unread) || 0;
                let messageBadge = document.getElementById('msgUnreadBadge');
                const messageButton = document.querySelector('[data-bs-target="#messagesModal"]');
                if (unreadCount && !messageBadge && messageButton) {
                    messageBadge = document.createElement('span');
                    messageBadge.id = 'msgUnreadBadge';
                    messageBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-pulse';
                    messageButton.appendChild(messageBadge);
                }
                if (messageBadge) {
                    messageBadge.textContent = String(unreadCount);
                    messageBadge.style.display = unreadCount ? '' : 'none';
                }
            } catch (error) {
                console.error('Unable to refresh Practicore messages.', error);
            }
        }

        window.setInterval(pollDashboardMessages, 8000);
    </script>
</body>
</html>
