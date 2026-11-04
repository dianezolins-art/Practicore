<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['email'], $_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

$db = get_db_connection();
$email = $_SESSION['email'];
$role = $_SESSION['role'];
$name = $_SESSION['name'] ?? 'User';
$school = $_SESSION['school'] ?? 'Cainta Campus';
$course = $_SESSION['course'] ?? '';
$noticeMessage = '';
$noticeType = 'success';
$affiliatedCompanies = [];

function feedEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db && ($_POST['action'] ?? '') === 'post_feed_notice' && $role === 'student') {
    $headline = $db->real_escape_string(trim($_POST['headline'] ?? ''));
    $preferredRole = $db->real_escape_string(trim($_POST['preferred_role'] ?? ''));
    $skills = $db->real_escape_string(trim($_POST['skills'] ?? ''));
    $availability = $db->real_escape_string(trim($_POST['availability'] ?? 'Immediate / Full-time'));
    $message = $db->real_escape_string(trim($_POST['message'] ?? ''));
    $safeName = $db->real_escape_string($name);
    $safeSchool = $db->real_escape_string($school);
    $safeCourse = $db->real_escape_string($course);
    $safeEmail = $db->real_escape_string($email);

    if ($headline && $preferredRole) {
        $db->query("DELETE FROM student_notices WHERE student_email='$safeEmail'");
        $db->query("INSERT INTO student_notices (student_name, student_email, school, course, headline, preferred_role, skills, availability, message)
                    VALUES ('$safeName', '$safeEmail', '$safeSchool', '$safeCourse', '$headline', '$preferredRole', '$skills', '$availability', '$message')");
        header('Location: feed.php?posted=1');
        exit();
    }
    $noticeMessage = 'Please fill in the headline and preferred role.';
    $noticeType = 'warning';
}

if (isset($_GET['posted'])) $noticeMessage = 'Your looking-for-OJT post is now live.';

$feedItems = [];
$directoryItems = [];
if ($db) {
    $directoryEmail = $db->real_escape_string($email);
    $safeSchool = $db->real_escape_string(strtolower(trim($school)));
    if ($role === 'student') {
        $studentAccountQ = $db->query("SELECT institution, campus, school FROM users WHERE email='$directoryEmail' AND role='student' LIMIT 1");
        $studentAccount = $studentAccountQ ? ($studentAccountQ->fetch_assoc() ?: []) : [];
        $studentInstitution = $studentAccount['institution'] ?? '';
        $studentCampus = practicore_account_campus($studentAccount['campus'] ?? '', $studentAccount['school'] ?? $school);
        $studentInstitutionKey = practicore_institution_key($studentInstitution, $studentAccount['school'] ?? $school);

        $partnerQ = $db->query("SELECT name, email, avatar, headline, bio, phone, location, school, institution, campus FROM companies WHERE approval_status='Approved' AND broadcast_to_students=1 ORDER BY name ASC");
        if ($partnerQ) {
            while ($partner = $partnerQ->fetch_assoc()) {
                $partnerInstitutionKey = practicore_institution_key($partner['institution'] ?? '', $partner['school'] ?? '');
                $partnerCampus = practicore_account_campus($partner['campus'] ?? '', $partner['school'] ?? '');

                if (practicore_company_visible_to_student($studentInstitutionKey, $studentCampus, $partnerInstitutionKey, $partnerCampus)) {
                    $affiliatedCompanies[] = $partner;
                }
            }
        }

        $directoryQ = $db->query("SELECT name, course, school, email, avatar FROM users WHERE role='student' AND email!='$directoryEmail' AND LOWER(TRIM(school))='$safeSchool' ORDER BY name ASC");
        if ($directoryQ) while ($person = $directoryQ->fetch_assoc()) {
            $person['directory_type'] = 'student';
            $directoryItems[] = $person;
        }
        foreach ($affiliatedCompanies as $partner) {
            $partner['directory_type'] = 'company';
            $directoryItems[] = $partner;
        }
        $directoryTitle = 'Students & partner companies';
        $searchTitle = 'Search partner companies';
        $searchPlaceholder = 'Search company name...';
        $searchDirectoryType = 'company';
    } elseif ($role === 'admin') {
        $directoryQ = $db->query("SELECT name, course, school, email, avatar FROM users WHERE role='student' AND LOWER(TRIM(school))='$safeSchool' ORDER BY name ASC");
        if ($directoryQ) while ($person = $directoryQ->fetch_assoc()) {
            $person['directory_type'] = 'student';
            $directoryItems[] = $person;
        }
        $partnersQ = $db->query("SELECT name, email, school, course, avatar FROM companies WHERE LOWER(TRIM(school))='$safeSchool' AND approval_status='Approved' ORDER BY name ASC");
        if ($partnersQ) while ($partner = $partnersQ->fetch_assoc()) {
            $partner['directory_type'] = 'company';
            $directoryItems[] = $partner;
        }
        $directoryTitle = 'Your students & affiliated companies';
        $searchTitle = 'Search your students';
        $searchPlaceholder = 'Search student name or course...';
        $searchDirectoryType = 'student';
    } else {
        $companyEmail = $db->real_escape_string($email);
        $partnerSchools = [];
        $schoolsQ = $db->query("SELECT DISTINCT school FROM jobs WHERE company_email='$companyEmail' AND TRIM(school)!='' ORDER BY school ASC");
        if ($schoolsQ) while ($partnerSchool = $schoolsQ->fetch_assoc()) {
            $partnerSchools[strtolower(trim($partnerSchool['school']))] = $partnerSchool['school'];
        }
        if ($school !== '') $partnerSchools[strtolower(trim($school))] = $school;
        foreach ($partnerSchools as $partnerSchool) {
            $directoryItems[] = [
                'name' => $partnerSchool,
                'school' => $partnerSchool,
                'directory_type' => 'school'
            ];
        }
        $directoryTitle = 'Your partner schools';
        $searchTitle = 'Search partner schools';
        $searchPlaceholder = 'Search school name...';
        $searchDirectoryType = 'school';
    }

    $jobs = $db->query("SELECT j.*, c.avatar as company_avatar FROM jobs j LEFT JOIN companies c ON j.company_email = c.email WHERE LOWER(TRIM(j.school))='$safeSchool' ORDER BY j.created_at DESC");
    if ($jobs) while ($item = $jobs->fetch_assoc()) {
        $item['feed_type'] = 'job';
        $item['avatar'] = $item['company_avatar'] ?? '';
        $feedItems[] = $item;
    }
    $notices = $db->query("SELECT sn.*, u.avatar as student_avatar FROM student_notices sn LEFT JOIN users u ON sn.student_email = u.email WHERE LOWER(TRIM(sn.school))='$safeSchool' ORDER BY sn.created_at DESC");
    if ($notices) while ($item = $notices->fetch_assoc()) {
        $item['feed_type'] = 'notice';
        $item['avatar'] = $item['student_avatar'] ?? '';
        $feedItems[] = $item;
    }
}
if (!isset($directoryTitle)) {
    $directoryTitle = $role === 'company' ? 'Your partner schools' : ($role === 'admin' ? 'Your students & affiliated companies' : 'Students & partner companies');
    $searchTitle = $role === 'company' ? 'Search partner schools' : ($role === 'admin' ? 'Search your students' : 'Search partner companies');
    $searchPlaceholder = $role === 'company' ? 'Search school name...' : ($role === 'admin' ? 'Search student name or course...' : 'Search company name...');
    $searchDirectoryType = $role === 'company' ? 'school' : ($role === 'admin' ? 'student' : 'company');
}
usort($feedItems, function ($a, $b) { return strtotime($b['created_at']) <=> strtotime($a['created_at']); });
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Community Feed — Practicore</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="style.css?v=20261029-responsive1">
</head>
<body>
    <div class="bg-ambient"><div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div></div>
    <nav class="navbar navbar-glass feed-navbar-shell sticky-top">
        <div class="container dashboard-navbar-container feed-navbar">
            <div class="feed-navbar-left">
                <a class="navbar-brand" href="feed.php" title="Community Feed"><img class="brand-logo" src="logo.svg?v=20261029" alt="Practicore" style="height: 48px; width: 201px;"></a>
            </div>
            <div class="feed-account-identity d-none d-md-block text-center">
                <div class="fw-bold small text-truncate"><?php echo feedEscape($name); ?></div>
                <div class="small text-muted text-truncate"><?php echo feedEscape(ucfirst($role)); ?> · <?php echo feedEscape($school); ?></div>
            </div>
            <div class="feed-account-actions">
                <a href="dashboard.php" class="btn btn-glass btn-glass-sm" title="Dashboard">
                    <i class="bi bi-speedometer2" aria-hidden="true"></i> Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="modal fade" id="feedDirectoryModal" tabindex="-1" aria-labelledby="feedDirectoryTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content glass-panel">
                <div class="modal-header border-secondary border-opacity-25">
                    <div>
                        <h5 class="modal-title fw-bold" id="feedDirectoryTitle"><?php echo feedEscape($directoryTitle); ?></h5>
                        <div class="small text-muted"><?php echo feedEscape($school); ?></div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="visually-hidden" for="feedDirectorySearch"><?php echo feedEscape($searchTitle); ?></label>
                    <div class="glass-input-group mb-3">
                        <i class="bi bi-search"></i>
                        <input type="search" class="glass-input" id="feedDirectorySearch" placeholder="<?php echo feedEscape($searchPlaceholder); ?>">
                    </div>
                    <div id="feedDirectoryList" class="d-grid gap-2">
                        <?php foreach ($directoryItems as $directoryItem): ?>
                            <?php
                                $itemType = $directoryItem['directory_type'];
                                $itemName = $directoryItem['name'] ?? '';
                                $itemSchool = $directoryItem['school'] ?? '';
                                $itemDetails = $itemType === 'student'
                                    ? (($directoryItem['course'] ?? 'Student') . ' · ' . $itemSchool)
                                    : ($itemType === 'company' ? ('Partner company · ' . $itemSchool) : 'Partner school');
                                $itemSearchText = strtolower($itemName . ' ' . $itemDetails . ' ' . $itemType);
                            ?>
                            <article class="feed-directory-item" data-directory-type="<?php echo feedEscape($itemType); ?>" data-directory-search="<?php echo feedEscape($itemSearchText); ?>">
                                <div class="feed-directory-avatar">
                                    <?php if (!empty($directoryItem['avatar'])): ?>
                                        <img src="<?php echo feedEscape($directoryItem['avatar']); ?>" alt="">
                                    <?php else: ?>
                                        <i class="bi <?php echo $itemType === 'student' ? 'bi-person-fill' : ($itemType === 'company' ? 'bi-building' : 'bi-mortarboard-fill'); ?>"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0 flex-grow-1">
                                    <div class="fw-bold text-white text-truncate"><?php echo feedEscape($itemName); ?></div>
                                    <div class="small text-muted text-truncate"><?php echo feedEscape($itemDetails); ?></div>
                                </div>
                                <?php if (($role === 'admin' || ($role === 'student' && $itemType === 'company')) && !empty($directoryItem['email'])): ?>
                                    <a class="btn btn-glass btn-glass-sm" href="dashboard.php?msg_email=<?php echo urlencode($directoryItem['email']); ?>&amp;msg_name=<?php echo urlencode($itemName); ?>" aria-label="Message <?php echo feedEscape($itemName); ?>" title="Message">
                                        <i class="bi bi-chat-dots-fill"></i>
                                    </a>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <div class="text-center text-muted small py-4 <?php echo $directoryItems ? 'd-none' : ''; ?>" id="feedDirectoryEmpty">
                            No matching people or partner schools found.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <main class="container my-4 my-md-5 feed-layout">
        <aside class="feed-sidebar">
            <div class="glass-card p-4">
                <span class="badge bg-primary bg-opacity-25 text-primary mb-2">YOUR CAMPUS</span>
                <h5 class="fw-bold mb-2">OJT Community Feed</h5>
                <p class="text-muted small mb-0">Latest company openings and students seeking an OJT placement at <?php echo feedEscape($school); ?>.</p>
            </div>
            <?php if ($role === 'student'): ?>
                <a href="#create-post" class="btn btn-gradient w-100 mt-3"><i class="bi bi-plus-circle me-1"></i> Create OJT Post</a>
            <?php endif; ?>
        </aside>

        <section class="feed-stream">
            <?php if ($noticeMessage): ?>
                <div class="alert alert-<?php echo $noticeType === 'warning' ? 'warning' : 'success'; ?> mb-3"><?php echo feedEscape($noticeMessage); ?></div>
            <?php endif; ?>

            <!-- Company / Opening Search (search icon + name & course filters) -->
            <div class="glass-card p-3 p-md-4 mb-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <i class="bi bi-search text-primary fs-5"></i>
                    <h6 class="fw-bold mb-0">Search Companies & Openings</h6>
                </div>
                <div class="row g-2">
                    <div class="col-md-7">
                        <div class="glass-input-group mb-0">
                            <i class="bi bi-building"></i>
                            <input type="text" id="feedCompanySearch" class="glass-input" placeholder="Search company or role..." onkeyup="filterFeedPosts()">
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="glass-input-group mb-0">
                            <i class="bi bi-funnel"></i>
                            <input type="text" id="feedCourseSearch" class="glass-input" placeholder="Filter by course (e.g. BSIT)" onkeyup="filterFeedPosts()">
                        </div>
                    </div>
                </div>
                <div class="small text-muted mt-2" id="feedSearchSummary"></div>
            </div>

            <?php if ($role === 'student'): ?>
                <div class="glass-card p-4 mb-4" id="create-post">
                    <div class="d-flex gap-3 align-items-start mb-3"><i class="bi bi-megaphone-fill text-primary fs-4"></i><div><h5 class="fw-bold mb-1">Looking for an OJT placement?</h5><p class="text-muted small mb-0">Post once and let accredited companies in your campus discover you.</p></div></div>
                    <form method="POST" action="feed.php" class="row g-3">
                        <input type="hidden" name="action" value="post_feed_notice">
                        <div class="col-md-7"><input class="glass-input" name="headline" placeholder="Headline, e.g. BSIT student seeking web-development OJT" required></div>
                        <div class="col-md-5"><input class="glass-input" name="preferred_role" placeholder="Preferred role" required></div>
                        <div class="col-md-6"><input class="glass-input" name="skills" placeholder="Skills, e.g. PHP, Figma, QA"></div>
                        <div class="col-md-6"><select class="glass-input" name="availability"><option>Immediate / Full-time</option><option>Part-time</option><option>Hybrid / Flexible</option><option>Starting Next Month</option></select></div>
                        <div class="col-12"><textarea class="glass-input" name="message" rows="2" placeholder="Add a short introduction (optional)"></textarea></div>
                        <div class="col-12 text-end"><button class="btn btn-gradient" type="submit"><i class="bi bi-send-fill me-1"></i> Publish Post</button></div>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($role === 'student' && $affiliatedCompanies): ?>
                <section class="glass-card p-4 mb-4" aria-labelledby="partner-companies-heading">
                    <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                        <div><span class="badge bg-success bg-opacity-25 text-success mb-2">SCHOOL PARTNERS</span><h5 class="fw-bold mb-0" id="partner-companies-heading">Affiliated Companies</h5></div>
                        <span class="badge bg-dark border border-secondary"><?php echo count($affiliatedCompanies); ?></span>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($affiliatedCompanies as $partner): ?>
                            <div class="col-12 feed-affiliated-company"
                                 data-search="<?php echo feedEscape(strtolower($partner['name'] . ' ' . ($partner['school'] ?? '') . ' ' . ($partner['headline'] ?? '') . ' ' . ($partner['bio'] ?? ''))); ?>">
                                <div class="d-flex align-items-start gap-3 p-3 rounded border border-secondary border-opacity-25 h-100">
                                    <?php if (!empty($partner['avatar'])): ?>
                                        <img src="<?php echo feedEscape($partner['avatar']); ?>" alt="<?php echo feedEscape($partner['name']); ?> profile photo" class="rounded-circle border border-success border-opacity-50" style="width:64px;height:64px;object-fit:cover;flex-shrink:0;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-success bg-opacity-25 d-flex align-items-center justify-content-center text-success fw-bold" style="width:64px;height:64px;flex-shrink:0;"><?php echo strtoupper(substr($partner['name'], 0, 1)); ?></div>
                                    <?php endif; ?>
                                    <div class="min-w-0 flex-grow-1">
                                        <div class="fw-bold text-white"><?php echo feedEscape($partner['name']); ?></div>
                                        <?php if (!empty($partner['headline'])): ?><div class="text-info small mb-2"><?php echo feedEscape($partner['headline']); ?></div><?php endif; ?>
                                        <?php if (!empty($partner['bio'])): ?><p class="text-muted small mb-2" style="white-space:pre-line;"><?php echo feedEscape($partner['bio']); ?></p><?php endif; ?>
                                        <div class="d-flex flex-wrap gap-3 small text-muted">
                                            <?php if (!empty($partner['location'])): ?><span><i class="bi bi-geo-alt me-1 text-info"></i><?php echo feedEscape($partner['location']); ?></span><?php endif; ?>
                                            <?php if (!empty($partner['phone'])): ?><a class="text-muted text-decoration-none" href="tel:<?php echo feedEscape(preg_replace('/[^0-9+]/', '', $partner['phone'])); ?>"><i class="bi bi-telephone me-1 text-info"></i><?php echo feedEscape($partner['phone']); ?></a><?php endif; ?>
                                            <?php if (!empty($partner['email'])): ?><a class="text-muted text-decoration-none" href="mailto:<?php echo feedEscape($partner['email']); ?>"><i class="bi bi-envelope me-1 text-info"></i><?php echo feedEscape($partner['email']); ?></a><?php endif; ?>
                                            <?php if (!empty($partner['school'])): ?><span><i class="bi bi-mortarboard me-1 text-info"></i><?php echo feedEscape($partner['school']); ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="d-flex align-items-center justify-content-between mb-3"><h4 class="fw-bold mb-0">Latest from <?php echo feedEscape($school); ?></h4><span class="badge bg-dark border border-secondary"><?php echo count($feedItems); ?> posts</span></div>
            <?php if (!$feedItems): ?>
                <div class="glass-card p-5 text-center"><i class="bi bi-newspaper text-primary fs-1"></i><h5 class="mt-3">No posts yet</h5><p class="text-muted mb-0">Check back soon for OJT openings and student updates.</p></div>
            <?php endif; ?>
            <?php foreach ($feedItems as $item): ?>
                <article class="glass-card p-4 mb-3 feed-post"
                    data-course="<?php echo feedEscape(strtolower($item['course'] ?? '')); ?>"
                    data-search="<?php echo feedEscape(strtolower(($item['feed_type'] === 'job' ? ($item['company_name'] ?? '') . ' ' . ($item['title'] ?? '') : ($item['student_name'] ?? '') . ' ' . ($item['headline'] ?? '')))); ?>">
                    <?php if ($item['feed_type'] === 'job'): ?>
                        <div class="d-flex justify-content-between gap-3">
                            <div class="d-flex gap-3 align-items-start">
                                <?php if (!empty($item['avatar'])): ?>
                                    <img src="<?php echo feedEscape($item['avatar']); ?>" class="rounded-circle border border-success border-opacity-50" style="width:48px;height:48px;object-fit:cover;" alt="Company Avatar">
                                <?php else: ?>
                                    <div class="rounded-circle bg-success bg-opacity-25 d-flex align-items-center justify-content-center text-success fw-bold" style="width:48px;height:48px;flex-shrink:0;">
                                        <?php echo strtoupper(substr($item['company_name'] ?? 'C', 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <span class="badge bg-success bg-opacity-25 text-success mb-2"><i class="bi bi-buildings me-1"></i> COMPANY OPENING</span>
                                    <h5 class="fw-bold mb-1"><?php echo feedEscape($item['title']); ?></h5>
                                    <div class="text-info small fw-semibold"><?php echo feedEscape($item['company_name']); ?></div>
                                </div>
                            </div>
                            <span class="text-muted small text-nowrap"><?php echo date('M j', strtotime($item['created_at'])); ?></span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3 small"><span class="badge bg-dark border"><?php echo feedEscape($item['course']); ?></span><span class="badge bg-dark border"><i class="bi bi-people me-1"></i><?php echo (int)$item['slots']; ?> slots</span><span class="badge bg-dark border text-success"><?php echo feedEscape($item['allowance']); ?></span></div>
                        <?php if (!empty($item['skills'])): ?><p class="text-muted small mt-3 mb-0"><strong class="text-white">Skills:</strong> <?php echo feedEscape($item['skills']); ?></p><?php endif; ?>
                    <?php else: ?>
                        <div class="d-flex justify-content-between gap-3">
                            <div class="d-flex gap-3 align-items-start">
                                <?php if (!empty($item['avatar'])): ?>
                                    <img src="<?php echo feedEscape($item['avatar']); ?>" class="rounded-circle border border-primary border-opacity-50" style="width:48px;height:48px;object-fit:cover;" alt="Student Avatar">
                                <?php else: ?>
                                    <div class="rounded-circle bg-primary bg-opacity-25 d-flex align-items-center justify-content-center text-primary fw-bold" style="width:48px;height:48px;flex-shrink:0;">
                                        <?php echo strtoupper(substr($item['student_name'] ?? 'S', 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <span class="badge bg-primary bg-opacity-25 text-primary mb-2"><i class="bi bi-person-workspace me-1"></i> LOOKING FOR OJT</span>
                                    <h5 class="fw-bold mb-1"><?php echo feedEscape($item['headline']); ?></h5>
                                    <div class="text-info small fw-semibold"><?php echo feedEscape($item['student_name']); ?> · <?php echo feedEscape($item['course']); ?></div>
                                </div>
                            </div>
                            <span class="text-muted small text-nowrap"><?php echo date('M j', strtotime($item['created_at'])); ?></span>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-3 small"><span class="badge bg-dark border"><i class="bi bi-briefcase me-1"></i><?php echo feedEscape($item['preferred_role']); ?></span><span class="badge bg-dark border"><i class="bi bi-clock me-1"></i><?php echo feedEscape($item['availability']); ?></span></div>
                        <?php if (!empty($item['skills'])): ?><p class="text-muted small mt-3 mb-1"><strong class="text-white">Skills:</strong> <?php echo feedEscape($item['skills']); ?></p><?php endif; ?>
                        <?php if (!empty($item['message'])): ?><p class="text-muted small fst-italic mb-0 mt-2">“<?php echo feedEscape($item['message']); ?>”</p><?php endif; ?>
                        <?php if ($role === 'company'): ?>
                            <div class="mt-3 pt-3 border-top border-secondary border-opacity-25">
                                <a href="dashboard.php?msg_email=<?php echo urlencode($item['student_email']); ?>&msg_name=<?php echo urlencode($item['student_name']); ?>" class="btn btn-glass btn-sm text-info">
                                    <i class="bi bi-chat-dots-fill me-1"></i> Message Student
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const feedDirectoryModal = document.getElementById('feedDirectoryModal');
        const feedDirectorySearch = document.getElementById('feedDirectorySearch');
        const feedDirectoryTitle = document.getElementById('feedDirectoryTitle');
        const feedDirectoryItems = Array.from(document.querySelectorAll('.feed-directory-item'));
        const feedDirectoryEmpty = document.getElementById('feedDirectoryEmpty');
        const directoryTitles = {
            friends: <?php echo json_encode($directoryTitle); ?>,
            search: <?php echo json_encode($searchTitle); ?>
        };
        const searchDirectoryType = <?php echo json_encode($searchDirectoryType); ?>;
        let directoryMode = 'friends';

        document.querySelectorAll('[data-directory-open]').forEach(button => {
            button.addEventListener('click', () => {
                const mode = button.dataset.directoryOpen;
                directoryMode = mode;
                feedDirectoryTitle.textContent = directoryTitles[mode] || directoryTitles.friends;
                feedDirectorySearch.value = '';
                filterDirectoryItems();
                bootstrap.Modal.getOrCreateInstance(feedDirectoryModal).show();
                if (mode === 'search') {
                    feedDirectoryModal.addEventListener('shown.bs.modal', () => feedDirectorySearch.focus(), { once: true });
                }
            });
        });

        function filterDirectoryItems() {
            const query = feedDirectorySearch.value.trim().toLowerCase();
            let visibleCount = 0;
            feedDirectoryItems.forEach(item => {
                const matchesType = directoryMode !== 'search' || item.dataset.directoryType === searchDirectoryType;
                const matches = matchesType && item.dataset.directorySearch.includes(query);
                item.classList.toggle('d-none', !matches);
                if (matches) visibleCount++;
            });
            feedDirectoryEmpty.classList.toggle('d-none', visibleCount > 0);
        }
        feedDirectorySearch.addEventListener('input', filterDirectoryItems);

        // Search job posts and broadcast affiliated companies together.
        function filterFeedPosts() {
            const nameQuery   = (document.getElementById('feedCompanySearch')?.value || '').trim().toLowerCase();
            const courseQuery = (document.getElementById('feedCourseSearch')?.value || '').trim().toLowerCase();
            let visible = 0;
            document.querySelectorAll('.feed-post, .feed-affiliated-company').forEach(post => {
                const isAffiliatedCompany = post.classList.contains('feed-affiliated-company');
                const haystack = post.dataset.search || '';
                const course   = post.dataset.course || '';
                const matchesName   = !nameQuery   || haystack.includes(nameQuery);
                const matchesCourse = !courseQuery || (isAffiliatedCompany ? Boolean(nameQuery && matchesName) : course.includes(courseQuery));
                const show = matchesName && matchesCourse;
                post.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            const summary = document.getElementById('feedSearchSummary');
            if (summary && (nameQuery || courseQuery)) {
                summary.textContent = visible + ' result(s) found.';
            } else if (summary) {
                summary.textContent = '';
            }
        }
    </script>
</body>
</html>
