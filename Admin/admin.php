<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to users

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check content type
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// Include database config
require_once __DIR__ . '/../forms/db_config.php';

if (!isset($mysqli) && isset($conn)) {
    $mysqli = $conn;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isMultipart = stripos($contentType, 'multipart/form-data') !== false;
$input = $isMultipart ? $_POST : json_decode(file_get_contents('php://input'), true);

// Set JSON response header
header('Content-Type: application/json');

$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
$postMaxBytes = ini_size_to_bytes(ini_get('post_max_size'));
if ($isMultipart && $contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_POST) && empty($_FILES)) {
    echo json_encode([
        'success' => false,
        'message' => 'Upload is larger than the server limit of ' . format_upload_size($postMaxBytes) . '.'
    ]);
    exit;
}

if (!$input || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

function default_project_folders()
{
    return [
        'DESIGN PHASE',
        'CONSTRUCTION PHASE',
        'FINAL RELEASING DOCUMENTS',
        'OTHERS'
    ];
}

function default_project_folder_children()
{
    return [
        'design_phase' => [
            'DESIGN CONTRACT',
            'DESIGN PRESENTATION - SKETCHUP',
            'DESIGN ESTIMATE & BOQ',
            'DRAWING DETAILS - CAD & PDF'
        ],
        'construction_phase' => [
            'CONSTRUCTION CONTRACT',
            'BUILDING PERMIT DOCUMENTS',
            'GROUND BREAKING'
        ],
        'final_releasing_documents' => [
            'PROJECT TURN-OVER',
            'OCCUPANCY PERMIT'
        ],
        'others' => [
            'RENDER IMAGES',
            'VIDEOS'
        ]
    ];
}

function ensure_admin_table($mysqli)
{
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(120) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(120) DEFAULT 'Administrator',
            profile_image VARCHAR(500) NULL,
            role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee',
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            last_login_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_admins_email (email),
            KEY idx_admins_role (role),
            KEY idx_admins_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception('Unable to prepare admin table: ' . $mysqli->error);
    }

    $roleColumn = $mysqli->query("SHOW COLUMNS FROM admins LIKE 'role'");
    if ($roleColumn && $roleColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE admins ADD COLUMN role ENUM('admin', 'employee') NOT NULL DEFAULT 'employee' AFTER full_name")) {
            throw new Exception('Unable to add account role: ' . $mysqli->error);
        }
        $mysqli->query("ALTER TABLE admins ADD KEY idx_admins_role (role)");
    }

    $profileImageColumn = $mysqli->query("SHOW COLUMNS FROM admins LIKE 'profile_image'");
    if ($profileImageColumn && $profileImageColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE admins ADD COLUMN profile_image VARCHAR(500) NULL AFTER full_name")) {
            throw new Exception('Unable to add profile image field: ' . $mysqli->error);
        }
    }

    seed_account($mysqli, 'admin@rjmarchibuild.com', 'admin123', 'Administrator', 'admin');
    seed_account($mysqli, 'employee@rjmarchibuild.com', 'employee123', 'Employee 1', 'employee');
    seed_account($mysqli, 'employee2@rjmarchibuild.com', 'employee223', 'Employee 2', 'employee');
    seed_account($mysqli, 'employee3@rjmarchibuild.com', 'employee323', 'Employee 3', 'employee');
}

function seed_account($mysqli, $email, $password, $fullName, $role)
{
    $stmt = $mysqli->prepare("SELECT id FROM admins WHERE email = ? LIMIT 1");
    if (!$stmt) {
        throw new Exception('Unable to check account: ' . $mysqli->error);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->fetch_assoc();
    $stmt->close();

    if ($exists) {
        return;
    }

    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare("INSERT INTO admins (email, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, 'active')");

    if (!$stmt) {
        throw new Exception('Unable to create account: ' . $mysqli->error);
    }

    $stmt->bind_param("ssss", $email, $passwordHash, $fullName, $role);
    if (!$stmt->execute()) {
        throw new Exception('Unable to save account: ' . $stmt->error);
    }
    $stmt->close();
}

function current_account_role()
{
    return $_SESSION['admin_role'] ?? 'employee';
}

function require_login()
{
    if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

function require_admin_role()
{
    require_login();

    if (current_account_role() !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Admin access required']);
        exit;
    }
}

function ensure_project_files_table($mysqli)
{
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS project_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NULL,
            project_name VARCHAR(180) NOT NULL,
            drawing_detail VARCHAR(180) NOT NULL,
            file_type VARCHAR(20) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            version_code VARCHAR(6) NULL,
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            uploaded_by INT NULL,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_project_files_project_id (project_id),
            KEY idx_project_files_project (project_name),
            KEY idx_project_files_type (file_type),
            KEY idx_project_files_uploaded_at (uploaded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception('Unable to prepare project files table: ' . $mysqli->error);
    }

    $projectIdColumn = $mysqli->query("SHOW COLUMNS FROM project_files LIKE 'project_id'");
    if ($projectIdColumn && $projectIdColumn->num_rows === 0) {
        $mysqli->query("ALTER TABLE project_files ADD COLUMN project_id INT NULL AFTER id");
        $mysqli->query("ALTER TABLE project_files ADD KEY idx_project_files_project_id (project_id)");
    }

    $versionColumn = $mysqli->query("SHOW COLUMNS FROM project_files LIKE 'version_code'");
    if ($versionColumn && $versionColumn->num_rows === 0) {
        $mysqli->query("ALTER TABLE project_files ADD COLUMN version_code VARCHAR(6) NULL AFTER file_path");
    }

    $fileTypeColumn = $mysqli->query("SHOW COLUMNS FROM project_files LIKE 'file_type'");
    if ($fileTypeColumn && ($fileType = $fileTypeColumn->fetch_assoc()) && stripos($fileType['Type'], 'enum') !== false) {
        $mysqli->query("ALTER TABLE project_files MODIFY COLUMN file_type VARCHAR(20) NOT NULL");
    }
}

function ensure_projects_table($mysqli)
{
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(180) NOT NULL UNIQUE,
            client_name VARCHAR(180) NULL,
            location VARCHAR(220) NULL,
            project_phase VARCHAR(40) NULL,
            project_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            billing_only TINYINT(1) NOT NULL DEFAULT 0,
            billing_hidden TINYINT(1) NOT NULL DEFAULT 0,
            description TEXT NULL,
            cover_path VARCHAR(500) NULL,
            folder_names TEXT NULL,
            folder_children TEXT NULL,
            sort_order INT DEFAULT 0,
            status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_projects_status (status),
            KEY idx_projects_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception('Unable to prepare projects table: ' . $mysqli->error);
    }

    $clientColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'client_name'");
    if ($clientColumn && $clientColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN client_name VARCHAR(180) NULL AFTER name")) {
            throw new Exception('Unable to add project client field: ' . $mysqli->error);
        }
    }

    $locationColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'location'");
    if ($locationColumn && $locationColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN location VARCHAR(220) NULL AFTER client_name")) {
            throw new Exception('Unable to add project location field: ' . $mysqli->error);
        }
    }

    $phaseColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'project_phase'");
    if ($phaseColumn && $phaseColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN project_phase VARCHAR(40) NULL AFTER location")) {
            throw new Exception('Unable to add project phase field: ' . $mysqli->error);
        }
    }

    $costColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'project_cost'");
    if ($costColumn && $costColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN project_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER project_phase")) {
            throw new Exception('Unable to add project cost field: ' . $mysqli->error);
        }
    }

    $billingOnlyColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'billing_only'");
    if ($billingOnlyColumn && $billingOnlyColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN billing_only TINYINT(1) NOT NULL DEFAULT 0 AFTER project_cost")) {
            throw new Exception('Unable to add billing-only project field: ' . $mysqli->error);
        }
    }

    $billingHiddenColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'billing_hidden'");
    if ($billingHiddenColumn && $billingHiddenColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN billing_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_only")) {
            throw new Exception('Unable to add billing visibility project field: ' . $mysqli->error);
        }
    }

    $coverColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'cover_path'");
    if ($coverColumn && $coverColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN cover_path VARCHAR(500) NULL AFTER name")) {
            throw new Exception('Unable to add project cover field: ' . $mysqli->error);
        }
    }

    $descriptionColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'description'");
    if ($descriptionColumn && $descriptionColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN description TEXT NULL AFTER name")) {
            throw new Exception('Unable to add project description field: ' . $mysqli->error);
        }
    }

    $sortColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'sort_order'");
    if ($sortColumn && $sortColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN sort_order INT DEFAULT 0 AFTER cover_path")) {
            throw new Exception('Unable to add sort_order field: ' . $mysqli->error);
        }
        if (!$mysqli->query("ALTER TABLE projects ADD KEY idx_projects_sort (sort_order)")) {
            throw new Exception('Unable to add sort index: ' . $mysqli->error);
        }
    }

    $foldersColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'folder_names'");
    if ($foldersColumn && $foldersColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN folder_names TEXT NULL AFTER cover_path")) {
            throw new Exception('Unable to add project folders field: ' . $mysqli->error);
        }
    }

    $folderChildrenColumn = $mysqli->query("SHOW COLUMNS FROM projects LIKE 'folder_children'");
    if ($folderChildrenColumn && $folderChildrenColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE projects ADD COLUMN folder_children TEXT NULL AFTER folder_names")) {
            throw new Exception('Unable to add project subfolders field: ' . $mysqli->error);
        }
    }
}

function ensure_finance_records_table($mysqli)
{
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS finance_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_type VARCHAR(80) NOT NULL,
            expense_date DATE NOT NULL,
            description VARCHAR(180) NOT NULL,
            project_name VARCHAR(180) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            received_by VARCHAR(160) NOT NULL,
            remark ENUM('Released', 'Unreleased') NOT NULL DEFAULT 'Unreleased',
            receipt_path VARCHAR(500) NULL,
            receipt_name VARCHAR(180) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_finance_records_date (expense_date),
            KEY idx_finance_records_project (project_name),
            KEY idx_finance_records_remark (remark)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception('Unable to prepare finance records table: ' . $mysqli->error);
    }

    $receiptPathColumn = $mysqli->query("SHOW COLUMNS FROM finance_records LIKE 'receipt_path'");
    if ($receiptPathColumn && $receiptPathColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE finance_records ADD COLUMN receipt_path VARCHAR(500) NULL AFTER remark")) {
            throw new Exception('Unable to add finance receipt path field: ' . $mysqli->error);
        }
    }

    $receiptNameColumn = $mysqli->query("SHOW COLUMNS FROM finance_records LIKE 'receipt_name'");
    if ($receiptNameColumn && $receiptNameColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE finance_records ADD COLUMN receipt_name VARCHAR(180) NULL AFTER receipt_path")) {
            throw new Exception('Unable to add finance receipt name field: ' . $mysqli->error);
        }
    }
}

function ensure_leave_requests_table($mysqli)
{
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS leave_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            requester_id INT NULL,
            employee_name VARCHAR(120) NOT NULL,
            employee_email VARCHAR(120) NOT NULL,
            employee_role VARCHAR(40) NOT NULL DEFAULT 'employee',
            leave_type VARCHAR(80) NOT NULL,
            from_date DATE NOT NULL,
            to_date DATE NOT NULL,
            day_count INT NOT NULL DEFAULT 1,
            reason VARCHAR(240) NOT NULL,
            status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_leave_requests_requester (requester_id),
            KEY idx_leave_requests_status (status),
            KEY idx_leave_requests_from_date (from_date),
            KEY idx_leave_requests_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception('Unable to prepare leave requests table: ' . $mysqli->error);
    }
}

function ensure_overtime_requests_table($mysqli)
{
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS overtime_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            requester_id INT NULL,
            employee_name VARCHAR(120) NOT NULL,
            employee_email VARCHAR(120) NOT NULL,
            employee_role VARCHAR(40) NOT NULL DEFAULT 'employee',
            overtime_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            hour_count DECIMAL(6,2) NOT NULL DEFAULT 0,
            reason VARCHAR(240) NOT NULL,
            status ENUM('pending', 'approved', 'declined') NOT NULL DEFAULT 'pending',
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_overtime_requests_requester (requester_id),
            KEY idx_overtime_requests_status (status),
            KEY idx_overtime_requests_date (overtime_date),
            KEY idx_overtime_requests_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception('Unable to prepare overtime requests table: ' . $mysqli->error);
    }
}

function map_leave_request_row($row)
{
    return [
        'id' => (int) $row['id'],
        'requesterId' => isset($row['requester_id']) ? (int) $row['requester_id'] : null,
        'employee' => $row['employee_name'],
        'requesterEmail' => $row['employee_email'],
        'role' => $row['employee_role'],
        'type' => $row['leave_type'],
        'from' => $row['from_date'],
        'to' => $row['to_date'],
        'days' => (int) $row['day_count'],
        'reason' => $row['reason'],
        'status' => $row['status'],
        'submittedAt' => substr($row['created_at'], 0, 10),
        'reviewedAt' => $row['reviewed_at'] ? substr($row['reviewed_at'], 0, 10) : '',
        'reviewedBy' => $row['reviewed_by'] ? (int) $row['reviewed_by'] : null
    ];
}

function map_overtime_request_row($row)
{
    return [
        'id' => (int) $row['id'],
        'requesterId' => isset($row['requester_id']) ? (int) $row['requester_id'] : null,
        'employee' => $row['employee_name'],
        'requesterEmail' => $row['employee_email'],
        'role' => $row['employee_role'],
        'date' => $row['overtime_date'],
        'start' => substr($row['start_time'], 0, 5),
        'end' => substr($row['end_time'], 0, 5),
        'hours' => (float) $row['hour_count'],
        'reason' => $row['reason'],
        'status' => $row['status'],
        'submittedAt' => substr($row['created_at'], 0, 10),
        'reviewedAt' => $row['reviewed_at'] ? substr($row['reviewed_at'], 0, 10) : '',
        'reviewedBy' => $row['reviewed_by'] ? (int) $row['reviewed_by'] : null
    ];
}

function ensure_client_billing_projects_table($mysqli)
{
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS client_billing_projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source_project_id INT NULL,
            name VARCHAR(180) NOT NULL,
            client_name VARCHAR(180) NOT NULL,
            location VARCHAR(220) NOT NULL,
            project_phase VARCHAR(40) NOT NULL,
            project_status VARCHAR(20) NOT NULL DEFAULT 'active',
            project_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_client_billing_name (name),
            UNIQUE KEY uq_client_billing_source_project (source_project_id),
            KEY idx_client_billing_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception('Unable to prepare client billing projects table: ' . $mysqli->error);
    }

    $projectStatusColumn = $mysqli->query("SHOW COLUMNS FROM client_billing_projects LIKE 'project_status'");
    if ($projectStatusColumn && $projectStatusColumn->num_rows === 0) {
        if (!$mysqli->query("ALTER TABLE client_billing_projects ADD COLUMN project_status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER project_phase")) {
            throw new Exception('Unable to add billing project status field: ' . $mysqli->error);
        }
    }

    ensure_projects_table($mysqli);
    $migrateSql = "
        INSERT IGNORE INTO client_billing_projects (source_project_id, name, client_name, location, project_phase, project_cost, created_by, created_at, updated_at)
        SELECT id,
               name,
               COALESCE(NULLIF(client_name, ''), 'Not set'),
               COALESCE(NULLIF(location, ''), 'Not set'),
               COALESCE(NULLIF(project_phase, ''), 'Design'),
               COALESCE(NULLIF(project_cost, 0), 0),
               created_by,
               created_at,
               updated_at
        FROM projects
        WHERE status = 'active' AND billing_only = 1 AND COALESCE(billing_hidden, 0) = 0
    ";

    if (!$mysqli->query($migrateSql)) {
        throw new Exception('Unable to migrate client billing projects: ' . $mysqli->error);
    }
}

function sanitize_upload_name($value)
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
    $value = trim($value, '.-');
    return $value !== '' ? $value : 'file';
}

function ini_size_to_bytes($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower($value[strlen($value) - 1]);
    $bytes = (float) $value;

    switch ($unit) {
        case 'g':
            $bytes *= 1024;
            // no break
        case 'm':
            $bytes *= 1024;
            // no break
        case 'k':
            $bytes *= 1024;
            break;
    }

    return (int) $bytes;
}

function format_upload_size($bytes)
{
    $bytes = (int) $bytes;
    if ($bytes >= 1024 * 1024 * 1024) {
        return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1), '0'), '.') . ' GB';
    }

    return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MB';
}

function project_file_upload_limit()
{
    return 250 * 1024 * 1024;
}

function upload_error_message($errorCode, $maxBytes)
{
    $serverLimit = min_nonzero([
        ini_size_to_bytes(ini_get('upload_max_filesize')),
        ini_size_to_bytes(ini_get('post_max_size'))
    ]);
    $limitText = format_upload_size($serverLimit ?: $maxBytes);

    switch ((int) $errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File is larger than the server upload limit of ' . $limitText . '.';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'Please choose a file to upload.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server could not save the uploaded file. Please check the upload folder permissions.';
        case UPLOAD_ERR_EXTENSION:
            return 'Server blocked this file type during upload.';
        default:
            return 'Please choose a valid file to upload.';
    }
}

function min_nonzero($values)
{
    $positive = array_values(array_filter(array_map('intval', $values), function ($value) {
        return $value > 0;
    }));

    return $positive ? min($positive) : 0;
}

function delete_admin_upload_if_safe($relativePath, $allowedFolder)
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return;
    }

    $absolutePath = realpath(__DIR__ . '/' . ltrim($relativePath, '/\\'));
    $uploadsRoot = realpath(__DIR__ . '/' . trim($allowedFolder, '/\\'));

    if (!$absolutePath || !$uploadsRoot || !is_file($absolutePath)) {
        return;
    }

    $uploadsRoot = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strpos($absolutePath, $uploadsRoot) === 0) {
        unlink($absolutePath);
    }
}

$action = $input['action'];

// Login action
if ($action === 'login') {
    $email = isset($input['email']) ? trim($input['email']) : '';
    $password = isset($input['password']) ? $input['password'] : '';
    $remember_me = isset($input['remember_me']) ? $input['remember_me'] : false;

    // Validate input
    if (empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'Please provide email and password']);
        exit;
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit;
    }

    try {
        ensure_admin_table($mysqli);

        $stmt = $mysqli->prepare("SELECT id, email, password_hash, full_name, profile_image, role FROM admins WHERE email = ? AND status = 'active' LIMIT 1");
        if (!$stmt) {
            throw new Exception('Login query failed: ' . $mysqli->error);
        }

        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result ? $result->fetch_assoc() : null;
        $stmt->close();
    } catch (Exception $e) {
        error_log('Admin login error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Login is temporarily unavailable']);
        exit;
    }

    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);

        // Set session variables
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_name'] = $admin['full_name'];
        $_SESSION['admin_profile_image'] = $admin['profile_image'] ? '../' . $admin['profile_image'] : '';
        $_SESSION['admin_role'] = $admin['role'];
        $_SESSION['admin_login_time'] = time();

        $updateStmt = $mysqli->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?");
        if ($updateStmt) {
            $adminId = (int) $admin['id'];
            $updateStmt->bind_param("i", $adminId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        // Remember me functionality (set cookie for 30 days)
        if ($remember_me) {
            setcookie(
                'admin_remember',
                base64_encode($admin['email'] . '|' . time()),
                time() + (30 * 24 * 60 * 60),
                '/',
                '',
                false,
                true // httpOnly
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => 'New folder/dashboard.html'
        ]);
    } else {
        // Log failed attempt (optional security measure)
        error_log("Failed login attempt for email: $email");

        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password'
        ]);
    }
}

// Logout action
elseif ($action === 'logout') {
    // Destroy session
    $_SESSION = [];
    session_destroy();

    // Clear remember me cookie
    if (isset($_COOKIE['admin_remember'])) {
        setcookie('admin_remember', '', time() - 3600, '/');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Logged out successfully'
    ]);
}

// Check auth status
elseif ($action === 'check_auth') {
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
        echo json_encode([
            'success' => true,
            'authenticated' => true,
            'email' => $_SESSION['admin_email'],
            'name' => $_SESSION['admin_name'] ?? 'Administrator',
            'profile_image' => $_SESSION['admin_profile_image'] ?? '',
            'role' => current_account_role(),
            'permissions' => current_account_role() === 'admin'
                ? ['dashboard', 'messages', 'search', 'filter', 'view', 'delete', 'project_files', 'cad', 'pdf', 'settings', 'users']
                : ['dashboard', 'project_files', 'project_name', 'cad', 'pdf']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'authenticated' => false
        ]);
    }
}

elseif ($action === 'update_account_profile') {
    require_login();

    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $accountId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;

    if ($accountId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }

    if ($name === '' || $email === '') {
        echo json_encode(['success' => false, 'message' => 'Name and email are required.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        exit;
    }

    try {
        ensure_admin_table($mysqli);

        $duplicateStmt = $mysqli->prepare("SELECT id FROM admins WHERE email = ? AND id <> ? LIMIT 1");
        if (!$duplicateStmt) {
            throw new Exception('Unable to check email: ' . $mysqli->error);
        }

        $duplicateStmt->bind_param("si", $email, $accountId);
        $duplicateStmt->execute();
        $duplicateResult = $duplicateStmt->get_result();
        $emailExists = $duplicateResult && $duplicateResult->num_rows > 0;
        $duplicateStmt->close();

        if ($emailExists) {
            echo json_encode(['success' => false, 'message' => 'That email is already used by another account.']);
            exit;
        }

        $profileImagePath = null;
        $oldProfileImage = '';

        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'message' => 'Please choose a valid profile image.']);
                exit;
            }

            $file = $_FILES['profile_image'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($extension, $allowedExtensions, true)) {
                echo json_encode(['success' => false, 'message' => 'Profile image must be JPG, PNG, or WebP.']);
                exit;
            }

            if ($file['size'] > 3 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Profile image must be 3MB or smaller.']);
                exit;
            }

            $currentStmt = $mysqli->prepare("SELECT profile_image FROM admins WHERE id = ? LIMIT 1");
            if (!$currentStmt) {
                throw new Exception('Unable to load current profile image: ' . $mysqli->error);
            }
            $currentStmt->bind_param("i", $accountId);
            $currentStmt->execute();
            $currentResult = $currentStmt->get_result();
            $currentAccount = $currentResult ? $currentResult->fetch_assoc() : null;
            $currentStmt->close();
            $oldProfileImage = $currentAccount['profile_image'] ?? '';

            $uploadDir = __DIR__ . '/uploads/profile_images/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                throw new Exception('Unable to create profile image folder');
            }

            $safeName = sanitize_upload_name($name);
            $storedName = $safeName . '-profile-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
            $destination = $uploadDir . $storedName;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new Exception('Unable to save profile image');
            }

            $profileImagePath = 'uploads/profile_images/' . $storedName;
        }

        if ($profileImagePath !== null) {
            $stmt = $mysqli->prepare("UPDATE admins SET full_name = ?, email = ?, profile_image = ? WHERE id = ? LIMIT 1");
        } else {
            $stmt = $mysqli->prepare("UPDATE admins SET full_name = ?, email = ? WHERE id = ? LIMIT 1");
        }
        if (!$stmt) {
            throw new Exception('Unable to update profile: ' . $mysqli->error);
        }

        if ($profileImagePath !== null) {
            $stmt->bind_param("sssi", $name, $email, $profileImagePath, $accountId);
        } else {
            $stmt->bind_param("ssi", $name, $email, $accountId);
        }
        $stmt->execute();
        $stmt->close();

        if ($profileImagePath !== null && $oldProfileImage !== '') {
            delete_admin_upload_if_safe($oldProfileImage, 'uploads/profile_images');
        }

        $publicProfileImage = $profileImagePath !== null ? '../' . $profileImagePath : ($_SESSION['admin_profile_image'] ?? '');

        $_SESSION['admin_name'] = $name;
        $_SESSION['admin_email'] = $email;
        $_SESSION['admin_profile_image'] = $publicProfileImage;

        echo json_encode(['success' => true, 'message' => 'Profile updated', 'name' => $name, 'email' => $email, 'profile_image' => $publicProfileImage]);
    } catch (Exception $e) {
        error_log('Update account profile error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to update profile.']);
    }
}

elseif ($action === 'update_account_password') {
    require_login();

    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $accountId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;

    if ($accountId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }

    if (strlen($newPassword) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        exit;
    }

    try {
        ensure_admin_table($mysqli);

        $stmt = $mysqli->prepare("SELECT password_hash FROM admins WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Unable to load account: ' . $mysqli->error);
        }

        $stmt->bind_param("i", $accountId);
        $stmt->execute();
        $result = $stmt->get_result();
        $account = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$account || !password_verify($currentPassword, $account['password_hash'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $mysqli->prepare("UPDATE admins SET password_hash = ? WHERE id = ? LIMIT 1");
        if (!$updateStmt) {
            throw new Exception('Unable to update password: ' . $mysqli->error);
        }

        $updateStmt->bind_param("si", $passwordHash, $accountId);
        $updateStmt->execute();
        $updateStmt->close();

        echo json_encode(['success' => true, 'message' => 'Password updated']);
    } catch (Exception $e) {
        error_log('Update account password error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Unable to update password.']);
    }
}

// Get all emails/messages
elseif ($action === 'get_emails') {
    require_login();

    try {
        // Query contact messages and quotation requests into one dashboard feed.
        $query = "SELECT source, source_id, name, email, phone, subject, message, created_at, status
                  FROM (
                      SELECT
                          'contact' AS source,
                          id AS source_id,
                          name,
                          email,
                          '' AS phone,
                          subject,
                          message,
                          created_at,
                          status
                      FROM contact_submissions
                      UNION ALL
                      SELECT
                          'quote' AS source,
                          id AS source_id,
                          name,
                          email,
                          phone,
                          CONCAT('Request for Quotation - ', project_type) AS subject,
                          CONCAT(
                              description,
                              '\n\nProject Type: ', project_type,
                              '\nLot Area: ', lot_area,
                              '\nProject Location: ', project_location,
                              '\nPhone: ', phone
                          ) AS message,
                          created_at,
                          'unread' AS status
                      FROM quote_submissions
                  ) AS dashboard_messages
                  ORDER BY created_at DESC
                  LIMIT 100";

        $result = mysqli_query($mysqli, $query);

        if (!$result) {
            throw new Exception('Database query failed: ' . mysqli_error($mysqli));
        }

        $emails = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $emails[] = [
                'id' => $row['source_id'],
                'source' => $row['source'],
                'sourceLabel' => $row['source'] === 'quote' ? 'Quotation' : 'Contact',
                'name' => htmlspecialchars($row['name']),
                'email' => htmlspecialchars($row['email']),
                'phone' => htmlspecialchars($row['phone'] ?? ''),
                'subject' => htmlspecialchars($row['subject']),
                'message' => htmlspecialchars($row['message']),
                'date' => $row['created_at'],
                'status' => $row['status'] ?? 'unread'
            ];
        }

        echo json_encode([
            'success' => true,
            'emails' => $emails,
            'count' => count($emails)
        ]);

    } catch (Exception $e) {
        error_log('Get emails error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'Failed to retrieve emails',
            'error' => $e->getMessage()
        ]);
    }
}

elseif ($action === 'delete_email') {
    require_admin_role();

    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $source = isset($input['source']) ? strtolower(trim((string) $input['source'])) : 'contact';
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid message ID']);
        exit;
    }

    $table = $source === 'quote' ? 'quote_submissions' : 'contact_submissions';
    $stmt = $mysqli->prepare("DELETE FROM {$table} WHERE id = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Delete request failed']);
        exit;
    }

    $stmt->bind_param("i", $id);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    echo json_encode([
        'success' => $deleted,
        'message' => $deleted ? 'Message deleted' : 'Message not found'
    ]);
}

elseif ($action === 'upload_project_file') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $projectName = '';
    $drawingDetail = isset($input['drawing_detail']) ? trim($input['drawing_detail']) : '';
    $fileType = isset($input['file_type']) ? strtolower(trim($input['file_type'])) : 'file';

    if ($projectId <= 0 || $drawingDetail === '') {
        echo json_encode(['success' => false, 'message' => 'Project name and folder are required']);
        exit;
    }

    $maxProjectFileBytes = project_file_upload_limit();

    if (!isset($_FILES['drawing_file']) || $_FILES['drawing_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = $_FILES['drawing_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        echo json_encode(['success' => false, 'message' => upload_error_message($uploadError, $maxProjectFileBytes)]);
        exit;
    }

    $file = $_FILES['drawing_file'];
    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $fileType = $extension !== '' ? $extension : 'file';

    if ($file['size'] > $maxProjectFileBytes) {
        echo json_encode(['success' => false, 'message' => 'Project file must be ' . format_upload_size($maxProjectFileBytes) . ' or smaller']);
        exit;
    }

    try {
        ensure_projects_table($mysqli);
        ensure_project_files_table($mysqli);

        $projectStmt = $mysqli->prepare("SELECT name FROM projects WHERE id = ? AND status = 'active' LIMIT 1");
        if (!$projectStmt) {
            throw new Exception('Unable to load project: ' . $mysqli->error);
        }

        $projectStmt->bind_param("i", $projectId);
        $projectStmt->execute();
        $projectResult = $projectStmt->get_result();
        $project = $projectResult ? $projectResult->fetch_assoc() : null;
        $projectStmt->close();

        if (!$project) {
            echo json_encode(['success' => false, 'message' => 'Please choose a valid project name']);
            exit;
        }

        $projectName = $project['name'];

        $uploadDir = __DIR__ . '/uploads/project_files/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            throw new Exception('Unable to create upload folder');
        }

        $safeProject = sanitize_upload_name($projectName);
        $safeOriginal = sanitize_upload_name(pathinfo($originalName, PATHINFO_FILENAME));
        $storedName = $safeProject . '-' . $fileType . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception('Unable to save uploaded file');
        }

        $relativePath = 'uploads/project_files/' . $storedName;
        $versionCode = strtoupper(bin2hex(random_bytes(3)));
        $uploadedBy = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;

        $stmt = $mysqli->prepare("
            INSERT INTO project_files (project_id, project_name, drawing_detail, file_type, original_name, stored_name, file_path, version_code, file_size, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            throw new Exception('Unable to save file record: ' . $mysqli->error);
        }

        $fileSize = (int) $file['size'];
        $stmt->bind_param("isssssssii", $projectId, $projectName, $drawingDetail, $fileType, $originalName, $storedName, $relativePath, $versionCode, $fileSize, $uploadedBy);
        if (!$stmt->execute()) {
            throw new Exception('Unable to save file record: ' . $stmt->error);
        }
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'File uploaded successfully']);
    } catch (Exception $e) {
        error_log('Project file upload error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Upload failed. Please try again.']);
    }
}

elseif ($action === 'get_project_files') {
    require_login();

    try {
        ensure_project_files_table($mysqli);

        $query = "SELECT pf.id, pf.project_id, pf.project_name, pf.drawing_detail, pf.file_type, pf.original_name, pf.file_path, pf.version_code, pf.file_size, pf.uploaded_at, a.full_name AS uploaded_by_name
                  FROM project_files pf
                  LEFT JOIN admins a ON a.id = pf.uploaded_by
                  ORDER BY pf.uploaded_at DESC
                  LIMIT 100";
        $result = $mysqli->query($query);

        if (!$result) {
            throw new Exception('Unable to load project files: ' . $mysqli->error);
        }

        $files = [];
        while ($row = $result->fetch_assoc()) {
            $files[] = [
                'id' => $row['id'],
                'project_id' => $row['project_id'],
                'project_name' => htmlspecialchars($row['project_name']),
                'drawing_detail' => htmlspecialchars($row['drawing_detail']),
                'file_type' => $row['file_type'],
                'original_name' => htmlspecialchars($row['original_name']),
                'file_path' => '../' . $row['file_path'],
                'version_code' => $row['version_code'] ?: strtoupper(substr(hash('crc32b', (string) $row['id']), 0, 6)),
                'file_size' => (int) $row['file_size'],
                'uploaded_at' => $row['uploaded_at'],
                'uploaded_by_name' => htmlspecialchars($row['uploaded_by_name'] ?? 'Unknown')
            ];
        }

        echo json_encode(['success' => true, 'files' => $files]);
    } catch (Exception $e) {
        error_log('Get project files error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load project files']);
    }
}

elseif ($action === 'delete_project_file') {
    require_login();

    $fileId = isset($input['file_id']) ? (int) $input['file_id'] : 0;

    if ($fileId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid file']);
        exit;
    }

    try {
        ensure_project_files_table($mysqli);

        $stmt = $mysqli->prepare("SELECT file_path FROM project_files WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Unable to load project file: ' . $mysqli->error);
        }

        $stmt->bind_param("i", $fileId);
        $stmt->execute();
        $result = $stmt->get_result();
        $file = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$file) {
            echo json_encode(['success' => false, 'message' => 'File not found']);
            exit;
        }

        $deleteStmt = $mysqli->prepare("DELETE FROM project_files WHERE id = ?");
        if (!$deleteStmt) {
            throw new Exception('Unable to delete project file: ' . $mysqli->error);
        }

        $deleteStmt->bind_param("i", $fileId);
        if (!$deleteStmt->execute()) {
            throw new Exception('Unable to delete project file: ' . $deleteStmt->error);
        }
        $deleteStmt->close();

        $absolutePath = realpath(__DIR__ . '/' . $file['file_path']);
        $uploadsRoot = realpath(__DIR__ . '/uploads/project_files');
        if ($absolutePath && $uploadsRoot && strpos($absolutePath, $uploadsRoot) === 0 && is_file($absolutePath)) {
            unlink($absolutePath);
        }

        echo json_encode(['success' => true, 'message' => 'File deleted']);
    } catch (Exception $e) {
        error_log('Delete project file error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete file']);
    }
}

elseif ($action === 'delete_project') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $preserveBilling = !empty($input['preserve_billing']);
    $preserveProjectFiles = !empty($input['preserve_project_files']);

    if ($projectId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid project']);
        exit;
    }

    try {
        ensure_projects_table($mysqli);
        ensure_project_files_table($mysqli);

        $stmt = $mysqli->prepare("SELECT id, name, cover_path FROM projects WHERE id = ? AND status = 'active' LIMIT 1");
        if (!$stmt) {
            throw new Exception('Unable to load project: ' . $mysqli->error);
        }

        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        $project = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$project) {
            echo json_encode(['success' => false, 'message' => 'Project not found']);
            exit;
        }

        $projectFilePaths = [];
        if (!$preserveProjectFiles) {
            $fileStmt = $mysqli->prepare("SELECT file_path FROM project_files WHERE project_id = ? OR (project_id IS NULL AND project_name = ?)");
            if (!$fileStmt) {
                throw new Exception('Unable to load project files: ' . $mysqli->error);
            }

            $fileStmt->bind_param("is", $projectId, $project['name']);
            $fileStmt->execute();
            $fileResult = $fileStmt->get_result();
            while ($fileResult && ($file = $fileResult->fetch_assoc())) {
                $projectFilePaths[] = $file['file_path'];
            }
            $fileStmt->close();
        }

        $mysqli->begin_transaction();

        if (!$preserveProjectFiles) {
            $deleteFilesStmt = $mysqli->prepare("DELETE FROM project_files WHERE project_id = ? OR (project_id IS NULL AND project_name = ?)");
            if (!$deleteFilesStmt) {
                throw new Exception('Unable to delete project files: ' . $mysqli->error);
            }
            $deleteFilesStmt->bind_param("is", $projectId, $project['name']);
            if (!$deleteFilesStmt->execute()) {
                throw new Exception('Unable to delete project files: ' . $deleteFilesStmt->error);
            }
            $deleteFilesStmt->close();
        }

        if ($preserveProjectFiles) {
            $updateProjectStmt = $mysqli->prepare("UPDATE projects SET billing_hidden = 1 WHERE id = ? AND status = 'active'");
            if (!$updateProjectStmt) {
                throw new Exception('Unable to remove project from client billing: ' . $mysqli->error);
            }
            $updateProjectStmt->bind_param("i", $projectId);
            if (!$updateProjectStmt->execute()) {
                throw new Exception('Unable to remove project from client billing: ' . $updateProjectStmt->error);
            }
            $updateProjectStmt->close();
        } elseif ($preserveBilling) {
            $updateProjectStmt = $mysqli->prepare("UPDATE projects SET billing_only = 1, cover_path = NULL WHERE id = ? AND status = 'active'");
            if (!$updateProjectStmt) {
                throw new Exception('Unable to remove project from project files: ' . $mysqli->error);
            }
            $updateProjectStmt->bind_param("i", $projectId);
            if (!$updateProjectStmt->execute()) {
                throw new Exception('Unable to remove project from project files: ' . $updateProjectStmt->error);
            }
            $updateProjectStmt->close();
        } else {
            $deleteProjectStmt = $mysqli->prepare("DELETE FROM projects WHERE id = ? AND status = 'active'");
            if (!$deleteProjectStmt) {
                throw new Exception('Unable to delete project: ' . $mysqli->error);
            }
            $deleteProjectStmt->bind_param("i", $projectId);
            if (!$deleteProjectStmt->execute()) {
                throw new Exception('Unable to delete project: ' . $deleteProjectStmt->error);
            }
            $deleteProjectStmt->close();
        }

        $mysqli->commit();

        foreach ($projectFilePaths as $filePath) {
            delete_admin_upload_if_safe($filePath, 'uploads/project_files');
        }
        if (!$preserveProjectFiles) {
            delete_admin_upload_if_safe($project['cover_path'] ?? '', 'uploads/project_covers');
        }

        $message = 'Project deleted';
        if ($preserveProjectFiles) {
            $message = 'Project removed from client billing';
        } elseif ($preserveBilling) {
            $message = 'Project removed from project files';
        }

        echo json_encode(['success' => true, 'message' => $message]);
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log('Delete project error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete project']);
    }
}

elseif ($action === 'get_projects') {
    require_login();

    try {
        ensure_projects_table($mysqli);

        $result = $mysqli->query("SELECT id, name, client_name, location, project_phase, project_cost, billing_only, billing_hidden, description, cover_path, folder_names, folder_children, created_at, updated_at FROM projects WHERE status = 'active' AND COALESCE(billing_only, 0) = 0 ORDER BY sort_order ASC, name ASC");
        if (!$result) {
            throw new Exception('Unable to load projects: ' . $mysqli->error);
        }

        $projects = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['folder_names'] === null || $row['folder_names'] === '') {
                $folderNames = default_project_folders();
            } else {
                $folderNames = json_decode($row['folder_names'], true);
            }

            if (!is_array($folderNames)) {
                $folderNames = default_project_folders();
            }

            if (count($folderNames) === 1 && strtolower(trim((string) $folderNames[0])) === 'design phase') {
                $folderNames = default_project_folders();
            }

            $folderChildren = json_decode($row['folder_children'] ?? '', true);
            if (!is_array($folderChildren)) {
                $folderChildren = default_project_folder_children();
            } else {
                $folderChildren = array_merge(default_project_folder_children(), $folderChildren);
            }

            $projects[] = [
                'id' => (int) $row['id'],
                'name' => htmlspecialchars($row['name']),
                'client_name' => htmlspecialchars($row['client_name'] ?? ''),
                'location' => htmlspecialchars($row['location'] ?? ''),
                'project_phase' => htmlspecialchars($row['project_phase'] ?? ''),
                'project_cost' => (float) ($row['project_cost'] ?? 0),
                'billing_only' => (int) ($row['billing_only'] ?? 0),
                'billing_hidden' => (int) ($row['billing_hidden'] ?? 0),
                'description' => $row['description'] ?? '',
                'cover_path' => $row['cover_path'] ? '../' . htmlspecialchars($row['cover_path']) : '',
                'folder_names' => $folderNames,
                'folder_children' => $folderChildren,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        echo json_encode(['success' => true, 'projects' => $projects]);
    } catch (Exception $e) {
        error_log('Get projects error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load projects']);
    }
}

elseif ($action === 'get_billing_projects') {
    require_login();

    try {
        ensure_client_billing_projects_table($mysqli);

        $result = $mysqli->query("SELECT id, name, client_name, location, project_phase, project_status, project_cost, created_at, updated_at FROM client_billing_projects WHERE status = 'active' ORDER BY name ASC");
        if (!$result) {
            throw new Exception('Unable to load client billing projects: ' . $mysqli->error);
        }

        $projects = [];
        while ($row = $result->fetch_assoc()) {
            $projects[] = [
                'id' => (int) $row['id'],
                'name' => htmlspecialchars($row['name']),
                'client_name' => htmlspecialchars($row['client_name'] ?? ''),
                'location' => htmlspecialchars($row['location'] ?? ''),
                'project_phase' => htmlspecialchars($row['project_phase'] ?? ''),
                'project_status' => htmlspecialchars($row['project_status'] ?? 'active'),
                'project_cost' => (float) ($row['project_cost'] ?? 0),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        echo json_encode(['success' => true, 'projects' => $projects]);
    } catch (Exception $e) {
        error_log('Get billing projects error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load client billing projects']);
    }
}

elseif ($action === 'save_billing_project') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $projectName = isset($input['project_name']) ? trim($input['project_name']) : '';
    $clientName = isset($input['client_name']) ? trim($input['client_name']) : '';
    $location = isset($input['location']) ? trim($input['location']) : '';
    $projectPhase = isset($input['project_phase']) ? trim($input['project_phase']) : '';
    $projectCost = isset($input['project_cost']) ? (float) $input['project_cost'] : 0;

    if ($projectName === '' || $clientName === '' || $location === '' || !in_array($projectPhase, ['Design', 'Construction'], true) || $projectCost <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please complete all project details']);
        exit;
    }

    try {
        ensure_client_billing_projects_table($mysqli);

        if ($projectId > 0) {
            $stmt = $mysqli->prepare("UPDATE client_billing_projects SET name = ?, client_name = ?, location = ?, project_phase = ?, project_cost = ? WHERE id = ? AND status = 'active'");
            if (!$stmt) {
                throw new Exception('Unable to update billing project: ' . $mysqli->error);
            }

            $stmt->bind_param("ssssdi", $projectName, $clientName, $location, $projectPhase, $projectCost, $projectId);
            if (!$stmt->execute()) {
                throw new Exception('Unable to update billing project: ' . $stmt->error);
            }

            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Billing project updated', 'project_id' => $projectId]);
        } else {
            $createdBy = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
            $stmt = $mysqli->prepare("INSERT INTO client_billing_projects (name, client_name, location, project_phase, project_cost, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Unable to add billing project: ' . $mysqli->error);
            }

            $stmt->bind_param("ssssdi", $projectName, $clientName, $location, $projectPhase, $projectCost, $createdBy);
            if (!$stmt->execute()) {
                throw new Exception('Unable to add billing project: ' . $stmt->error);
            }

            $newProjectId = $stmt->insert_id;
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Billing project added', 'project_id' => $newProjectId]);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('Save billing project error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'That project name already exists']);
    } catch (Exception $e) {
        error_log('Save billing project error: ' . $e->getMessage());
        $message = stripos($e->getMessage(), 'Duplicate entry') !== false
            ? 'That project name already exists'
            : 'Failed to save billing project';
        echo json_encode(['success' => false, 'message' => $message]);
    }
}

elseif ($action === 'update_billing_project_status') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $projectStatus = isset($input['project_status']) ? strtolower(trim($input['project_status'])) : '';
    $allowedStatuses = ['active', 'pending', 'complete', 'done'];

    if ($projectId <= 0 || !in_array($projectStatus, $allowedStatuses, true)) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid project status']);
        exit;
    }

    try {
        ensure_client_billing_projects_table($mysqli);

        $stmt = $mysqli->prepare("UPDATE client_billing_projects SET project_status = ? WHERE id = ? AND status = 'active'");
        if (!$stmt) {
            throw new Exception('Unable to update project status: ' . $mysqli->error);
        }

        $stmt->bind_param("si", $projectStatus, $projectId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to update project status: ' . $stmt->error);
        }

        $updated = $stmt->affected_rows >= 0;
        $stmt->close();

        echo json_encode([
            'success' => $updated,
            'message' => $updated ? 'Project status updated' : 'Project not found'
        ]);
    } catch (Exception $e) {
        error_log('Update billing project status error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update project status']);
    }
}

elseif ($action === 'delete_billing_project') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    if ($projectId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid project']);
        exit;
    }

    try {
        ensure_client_billing_projects_table($mysqli);

        $stmt = $mysqli->prepare("UPDATE client_billing_projects SET status = 'archived' WHERE id = ? AND status = 'active'");
        if (!$stmt) {
            throw new Exception('Unable to delete billing project: ' . $mysqli->error);
        }

        $stmt->bind_param("i", $projectId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to delete billing project: ' . $stmt->error);
        }
        $deleted = $stmt->affected_rows > 0;
        $stmt->close();

        echo json_encode(['success' => $deleted, 'message' => $deleted ? 'Billing project deleted' : 'Project not found']);
    } catch (Exception $e) {
        error_log('Delete billing project error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete billing project']);
    }
}

elseif ($action === 'get_leave_requests') {
    require_login();

    try {
        ensure_leave_requests_table($mysqli);
        $isAdmin = current_account_role() === 'admin';
        $accountId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;

        if ($isAdmin) {
            $stmt = $mysqli->prepare("
                SELECT id, requester_id, employee_name, employee_email, employee_role, leave_type, from_date, to_date, day_count, reason, status, reviewed_by, reviewed_at, created_at
                FROM leave_requests
                ORDER BY FIELD(status, 'pending', 'approved', 'declined'), created_at DESC, id DESC
            ");
        } else {
            $stmt = $mysqli->prepare("
                SELECT id, requester_id, employee_name, employee_email, employee_role, leave_type, from_date, to_date, day_count, reason, status, reviewed_by, reviewed_at, created_at
                FROM leave_requests
                WHERE requester_id = ?
                ORDER BY created_at DESC, id DESC
            ");
        }

        if (!$stmt) {
            throw new Exception('Unable to load leave requests: ' . $mysqli->error);
        }

        if (!$isAdmin) {
            $stmt->bind_param("i", $accountId);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = map_leave_request_row($row);
        }
        $stmt->close();

        echo json_encode(['success' => true, 'records' => $records]);
    } catch (Exception $e) {
        error_log('Get leave requests error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load leave requests']);
    }
}

elseif ($action === 'save_leave_request') {
    require_login();

    if (current_account_role() !== 'employee') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only employee accounts can submit leave requests.']);
        exit;
    }

    $leaveType = trim($input['leave_type'] ?? '');
    $fromDate = trim($input['from_date'] ?? '');
    $toDate = trim($input['to_date'] ?? '');
    $reason = trim($input['reason'] ?? '');
    $allowedTypes = ['Vacation Leave', 'Sick Leave', 'Emergency Leave', 'Personal Leave'];

    $fromTimestamp = strtotime($fromDate);
    $toTimestamp = strtotime($toDate);
    $dayCount = ($fromTimestamp && $toTimestamp && $toTimestamp >= $fromTimestamp)
        ? (int) floor(($toTimestamp - $fromTimestamp) / 86400) + 1
        : 0;

    if (!in_array($leaveType, $allowedTypes, true) || !$fromTimestamp || !$toTimestamp || $dayCount <= 0 || $reason === '') {
        echo json_encode(['success' => false, 'message' => 'Please complete a valid leave request.']);
        exit;
    }

    if (strlen($reason) > 240) {
        echo json_encode(['success' => false, 'message' => 'Reason must be 240 characters or fewer.']);
        exit;
    }

    try {
        ensure_leave_requests_table($mysqli);

        $requesterId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;
        $employeeName = trim($_SESSION['admin_name'] ?? '') ?: 'Employee';
        $employeeEmail = trim($_SESSION['admin_email'] ?? '');
        $employeeRole = current_account_role();

        $stmt = $mysqli->prepare("
            INSERT INTO leave_requests (requester_id, employee_name, employee_email, employee_role, leave_type, from_date, to_date, day_count, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        if (!$stmt) {
            throw new Exception('Unable to save leave request: ' . $mysqli->error);
        }

        $stmt->bind_param("issssssis", $requesterId, $employeeName, $employeeEmail, $employeeRole, $leaveType, $fromDate, $toDate, $dayCount, $reason);
        if (!$stmt->execute()) {
            throw new Exception('Unable to save leave request: ' . $stmt->error);
        }
        $requestId = $stmt->insert_id;
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Leave request submitted', 'request_id' => $requestId]);
    } catch (Exception $e) {
        error_log('Save leave request error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to submit leave request']);
    }
}

elseif ($action === 'save_overtime_request') {
    require_login();

    if (current_account_role() !== 'employee') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only employee accounts can submit overtime requests.']);
        exit;
    }

    $overtimeDate = trim($input['overtime_date'] ?? '');
    $startTime = trim($input['start_time'] ?? '');
    $endTime = trim($input['end_time'] ?? '');
    $reason = trim($input['reason'] ?? '');

    $startTimestamp = strtotime($overtimeDate . ' ' . $startTime);
    $endTimestamp = strtotime($overtimeDate . ' ' . $endTime);
    $hourCount = ($startTimestamp && $endTimestamp && $endTimestamp > $startTimestamp)
        ? round(($endTimestamp - $startTimestamp) / 3600, 2)
        : 0;

    if (!$startTimestamp || !$endTimestamp || $hourCount <= 0 || $reason === '') {
        echo json_encode(['success' => false, 'message' => 'Please complete a valid overtime request.']);
        exit;
    }

    if (strlen($reason) > 240) {
        echo json_encode(['success' => false, 'message' => 'Reason must be 240 characters or fewer.']);
        exit;
    }

    try {
        ensure_overtime_requests_table($mysqli);

        $requesterId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;
        $employeeName = trim($_SESSION['admin_name'] ?? '') ?: 'Employee';
        $employeeEmail = trim($_SESSION['admin_email'] ?? '');
        $employeeRole = current_account_role();

        $stmt = $mysqli->prepare("
            INSERT INTO overtime_requests (requester_id, employee_name, employee_email, employee_role, overtime_date, start_time, end_time, hour_count, reason, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");

        if (!$stmt) {
            throw new Exception('Unable to save overtime request: ' . $mysqli->error);
        }

        $stmt->bind_param("issssssds", $requesterId, $employeeName, $employeeEmail, $employeeRole, $overtimeDate, $startTime, $endTime, $hourCount, $reason);
        if (!$stmt->execute()) {
            throw new Exception('Unable to save overtime request: ' . $stmt->error);
        }
        $requestId = $stmt->insert_id;
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Overtime request submitted', 'request_id' => $requestId]);
    } catch (Exception $e) {
        error_log('Save overtime request error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to submit overtime request']);
    }
}

elseif ($action === 'get_overtime_requests') {
    require_login();

    try {
        ensure_overtime_requests_table($mysqli);
        $isAdmin = current_account_role() === 'admin';
        $accountId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;

        if ($isAdmin) {
            $stmt = $mysqli->prepare("
                SELECT id, requester_id, employee_name, employee_email, employee_role, overtime_date, start_time, end_time, hour_count, reason, status, reviewed_by, reviewed_at, created_at
                FROM overtime_requests
                ORDER BY FIELD(status, 'pending', 'approved', 'declined'), created_at DESC, id DESC
            ");
        } else {
            $stmt = $mysqli->prepare("
                SELECT id, requester_id, employee_name, employee_email, employee_role, overtime_date, start_time, end_time, hour_count, reason, status, reviewed_by, reviewed_at, created_at
                FROM overtime_requests
                WHERE requester_id = ?
                ORDER BY created_at DESC, id DESC
            ");
        }

        if (!$stmt) {
            throw new Exception('Unable to load overtime requests: ' . $mysqli->error);
        }

        if (!$isAdmin) {
            $stmt->bind_param("i", $accountId);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = map_overtime_request_row($row);
        }
        $stmt->close();

        echo json_encode(['success' => true, 'records' => $records]);
    } catch (Exception $e) {
        error_log('Get overtime requests error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load overtime requests']);
    }
}

elseif ($action === 'update_leave_request_status') {
    require_admin_role();

    $requestId = isset($input['request_id']) ? (int) $input['request_id'] : 0;
    $status = trim($input['status'] ?? '');

    if ($requestId <= 0 || !in_array($status, ['approved', 'declined'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid leave request status.']);
        exit;
    }

    try {
        ensure_leave_requests_table($mysqli);
        $reviewedBy = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : 0;

        $stmt = $mysqli->prepare("
            UPDATE leave_requests
            SET status = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmt) {
            throw new Exception('Unable to update leave request: ' . $mysqli->error);
        }

        $stmt->bind_param("sii", $status, $reviewedBy, $requestId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to update leave request: ' . $stmt->error);
        }
        $updated = $stmt->affected_rows >= 0;
        $stmt->close();

        echo json_encode(['success' => $updated, 'message' => 'Leave request updated']);
    } catch (Exception $e) {
        error_log('Update leave request status error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update leave request']);
    }
}

elseif ($action === 'upload_project_cover') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;

    if ($projectId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid project']);
        exit;
    }

    if (!isset($_FILES['cover_image']) || $_FILES['cover_image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid image']);
        exit;
    }

    $file = $_FILES['cover_image'];
    $originalName = $file['name'];
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($extension, $allowedExtensions, true)) {
        echo json_encode(['success' => false, 'message' => 'Cover image must be JPG, PNG, or WebP']);
        exit;
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Cover image must be 5MB or smaller']);
        exit;
    }

    try {
        ensure_projects_table($mysqli);

        $projectStmt = $mysqli->prepare("SELECT name FROM projects WHERE id = ? AND status = 'active' LIMIT 1");
        if (!$projectStmt) {
            throw new Exception('Unable to load project: ' . $mysqli->error);
        }

        $projectStmt->bind_param("i", $projectId);
        $projectStmt->execute();
        $projectResult = $projectStmt->get_result();
        $project = $projectResult ? $projectResult->fetch_assoc() : null;
        $projectStmt->close();

        if (!$project) {
            echo json_encode(['success' => false, 'message' => 'Project not found']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/project_covers/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            throw new Exception('Unable to create cover upload folder');
        }

        $safeProject = sanitize_upload_name($project['name']);
        $storedName = $safeProject . '-cover-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new Exception('Unable to save cover image');
        }

        $relativePath = 'uploads/project_covers/' . $storedName;
        $stmt = $mysqli->prepare("UPDATE projects SET cover_path = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Unable to save cover image: ' . $mysqli->error);
        }

        $stmt->bind_param("si", $relativePath, $projectId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to save cover image: ' . $stmt->error);
        }
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Project cover updated', 'cover_path' => '../' . $relativePath]);
    } catch (Exception $e) {
        error_log('Project cover upload error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to upload cover image']);
    }
}

elseif ($action === 'save_project') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $projectName = isset($input['project_name']) ? trim($input['project_name']) : '';
    $hasProjectDetails = array_key_exists('client_name', $input)
        || array_key_exists('location', $input)
        || array_key_exists('project_phase', $input)
        || array_key_exists('project_cost', $input);
    $clientName = isset($input['client_name']) ? trim($input['client_name']) : '';
    $location = isset($input['location']) ? trim($input['location']) : '';
    $projectPhase = isset($input['project_phase']) ? trim($input['project_phase']) : '';
    $projectCost = isset($input['project_cost']) ? (float) $input['project_cost'] : 0;
    $billingOnly = !empty($input['billing_only']) ? 1 : 0;
    $preserveProjectCost = !empty($input['preserve_project_cost']) && $projectId > 0;

    if ($projectName === '') {
        echo json_encode(['success' => false, 'message' => 'Project name is required']);
        exit;
    }

    if ($hasProjectDetails) {
        if ($clientName === '' || $location === '' || !in_array($projectPhase, ['Design', 'Construction'], true) || (!$preserveProjectCost && $projectCost <= 0)) {
            echo json_encode(['success' => false, 'message' => 'Please complete all project details']);
            exit;
        }
    }

    try {
        ensure_projects_table($mysqli);
        ensure_project_files_table($mysqli);

        if ($projectId > 0) {
            $oldStmt = $mysqli->prepare("SELECT name FROM projects WHERE id = ? LIMIT 1");
            if (!$oldStmt) {
                throw new Exception('Unable to check project: ' . $mysqli->error);
            }

            $oldStmt->bind_param("i", $projectId);
            $oldStmt->execute();
            $oldResult = $oldStmt->get_result();
            $oldProject = $oldResult ? $oldResult->fetch_assoc() : null;
            $oldStmt->close();

            if (!$oldProject) {
                echo json_encode(['success' => false, 'message' => 'Project not found']);
                exit;
            }

            if ($hasProjectDetails) {
                if ($preserveProjectCost) {
                    $stmt = $mysqli->prepare("UPDATE projects SET name = ?, client_name = ?, location = ?, project_phase = ?, billing_only = ? WHERE id = ?");
                } else {
                    $stmt = $mysqli->prepare("UPDATE projects SET name = ?, client_name = ?, location = ?, project_phase = ?, project_cost = ?, billing_only = ? WHERE id = ?");
                }
            } else {
                $stmt = $mysqli->prepare("UPDATE projects SET name = ? WHERE id = ?");
            }
            if (!$stmt) {
                throw new Exception('Unable to update project: ' . $mysqli->error);
            }

            if ($hasProjectDetails) {
                if ($preserveProjectCost) {
                    $stmt->bind_param("ssssii", $projectName, $clientName, $location, $projectPhase, $billingOnly, $projectId);
                } else {
                    $stmt->bind_param("ssssdii", $projectName, $clientName, $location, $projectPhase, $projectCost, $billingOnly, $projectId);
                }
            } else {
                $stmt->bind_param("si", $projectName, $projectId);
            }
            if (!$stmt->execute()) {
                throw new Exception('Unable to update project: ' . $stmt->error);
            }
            $stmt->close();

            $fileStmt = $mysqli->prepare("UPDATE project_files SET project_name = ? WHERE project_id = ?");
            if ($fileStmt) {
                $fileStmt->bind_param("si", $projectName, $projectId);
                $fileStmt->execute();
                $fileStmt->close();
            }

            echo json_encode(['success' => true, 'message' => 'Project name updated', 'project_id' => $projectId]);
        } else {
            $createdBy = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
            $defaultFoldersJson = json_encode(default_project_folders());
            $defaultChildrenJson = json_encode(default_project_folder_children());

            if ($hasProjectDetails) {
                $stmt = $mysqli->prepare("INSERT INTO projects (name, client_name, location, project_phase, project_cost, billing_only, folder_names, folder_children, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            } else {
                $stmt = $mysqli->prepare("INSERT INTO projects (name, folder_names, folder_children, created_by) VALUES (?, ?, ?, ?)");
            }

            if (!$stmt) {
                throw new Exception('Unable to add project: ' . $mysqli->error);
            }

            if ($hasProjectDetails) {
                $stmt->bind_param("ssssdissi", $projectName, $clientName, $location, $projectPhase, $projectCost, $billingOnly, $defaultFoldersJson, $defaultChildrenJson, $createdBy);
            } else {
                $stmt->bind_param("sssi", $projectName, $defaultFoldersJson, $defaultChildrenJson, $createdBy);
            }

            if (!$stmt->execute()) {
                throw new Exception('Unable to add project: ' . $stmt->error);
            }
            $newProjectId = $stmt->insert_id;
            $stmt->close();

            echo json_encode(['success' => true, 'message' => 'Project name added', 'project_id' => $newProjectId]);
        }
    } catch (mysqli_sql_exception $e) {
        error_log('Save project error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'That project name already exists']);
    } catch (Exception $e) {
        error_log('Save project error: ' . $e->getMessage());
        $message = stripos($e->getMessage(), 'Duplicate entry') !== false
            ? 'That project name already exists'
            : 'Failed to save project name';
        echo json_encode(['success' => false, 'message' => $message]);
    }
}

elseif ($action === 'save_project_description') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $description = isset($input['description']) ? trim(preg_replace('/\s+/', ' ', $input['description'])) : '';
    $description = substr($description, 0, 60);

    if ($projectId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid project']);
        exit;
    }

    try {
        ensure_projects_table($mysqli);

        $stmt = $mysqli->prepare("UPDATE projects SET description = ? WHERE id = ? AND status = 'active'");
        if (!$stmt) {
            throw new Exception('Unable to update project description: ' . $mysqli->error);
        }

        $stmt->bind_param("si", $description, $projectId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to update project description: ' . $stmt->error);
        }
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Project description updated']);
    } catch (Exception $e) {
        error_log('Save project description error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save project description']);
    }
}

elseif ($action === 'save_project_folders') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $folderNames = isset($input['folder_names']) ? $input['folder_names'] : [];
    $oldFolderName = isset($input['old_folder_name']) ? trim($input['old_folder_name']) : '';
    $newFolderName = isset($input['new_folder_name']) ? trim($input['new_folder_name']) : '';

    if ($projectId <= 0 || !is_array($folderNames)) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid project']);
        exit;
    }

    $cleanFolderNames = [];
    foreach ($folderNames as $folderName) {
        $folderName = trim((string) $folderName);
        if ($folderName !== '') {
            $cleanFolderNames[] = substr($folderName, 0, 80);
        }
    }

    $cleanFolderNames = array_values(array_unique($cleanFolderNames));

    if (count($cleanFolderNames) > 4) {
        echo json_encode(['success' => false, 'message' => 'You can add up to 4 folders only.']);
        exit;
    }

    try {
        ensure_projects_table($mysqli);

        $foldersJson = json_encode($cleanFolderNames);
        $stmt = $mysqli->prepare("UPDATE projects SET folder_names = ? WHERE id = ? AND status = 'active'");
        if (!$stmt) {
            throw new Exception('Unable to update project folders: ' . $mysqli->error);
        }

        $stmt->bind_param("si", $foldersJson, $projectId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to update project folders: ' . $stmt->error);
        }
        $stmt->close();

        if ($oldFolderName !== '' && $newFolderName !== '' && $oldFolderName !== $newFolderName) {
            ensure_project_files_table($mysqli);

            $fileStmt = $mysqli->prepare("UPDATE project_files SET drawing_detail = ? WHERE project_id = ? AND drawing_detail = ?");
            if ($fileStmt) {
                $fileStmt->bind_param("sis", $newFolderName, $projectId, $oldFolderName);
                $fileStmt->execute();
                $fileStmt->close();
            }
        }

        echo json_encode(['success' => true, 'message' => 'Project folders updated', 'folder_names' => $cleanFolderNames]);
    } catch (Exception $e) {
        error_log('Save project folders error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save project folders']);
    }
}

elseif ($action === 'save_project_subfolders') {
    require_login();

    $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
    $parentKey = isset($input['parent_key']) ? trim($input['parent_key']) : '';
    $folderNames = isset($input['folder_names']) ? $input['folder_names'] : [];

    if ($projectId <= 0 || $parentKey === '' || !is_array($folderNames)) {
        echo json_encode(['success' => false, 'message' => 'Please choose a valid project folder']);
        exit;
    }

    $cleanFolderNames = [];
    foreach ($folderNames as $folderName) {
        $folderName = trim((string) $folderName);
        if ($folderName !== '') {
            $cleanFolderNames[] = substr($folderName, 0, 80);
        }
    }

    $cleanFolderNames = array_values(array_unique($cleanFolderNames));

    if (count($cleanFolderNames) > 4) {
        echo json_encode(['success' => false, 'message' => 'You can add up to 4 folders only.']);
        exit;
    }

    try {
        ensure_projects_table($mysqli);

        $stmt = $mysqli->prepare("SELECT folder_children FROM projects WHERE id = ? AND status = 'active' LIMIT 1");
        if (!$stmt) {
            throw new Exception('Unable to load project subfolders: ' . $mysqli->error);
        }

        $stmt->bind_param("i", $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        $project = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$project) {
            echo json_encode(['success' => false, 'message' => 'Project not found']);
            exit;
        }

        $folderChildren = json_decode($project['folder_children'] ?? '', true);
        if (!is_array($folderChildren)) {
            $folderChildren = [];
        }

        $folderChildren[$parentKey] = $cleanFolderNames;
        $folderChildrenJson = json_encode($folderChildren);

        $updateStmt = $mysqli->prepare("UPDATE projects SET folder_children = ? WHERE id = ? AND status = 'active'");
        if (!$updateStmt) {
            throw new Exception('Unable to update project subfolders: ' . $mysqli->error);
        }

        $updateStmt->bind_param("si", $folderChildrenJson, $projectId);
        if (!$updateStmt->execute()) {
            throw new Exception('Unable to update project subfolders: ' . $updateStmt->error);
        }
        $updateStmt->close();

        echo json_encode(['success' => true, 'message' => 'Project subfolders updated', 'folder_names' => $cleanFolderNames]);
    } catch (Exception $e) {
        error_log('Save project subfolders error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save project subfolders']);
    }
}

elseif ($action === 'update_project_order') {
    require_login();

    $projectOrder = isset($input['project_order']) ? $input['project_order'] : [];

    if (!is_array($projectOrder) || empty($projectOrder)) {
        echo json_encode(['success' => false, 'message' => 'Invalid project order']);
        exit;
    }

    try {
        ensure_projects_table($mysqli);

        $mysqli->begin_transaction();

        foreach ($projectOrder as $index => $projectId) {
            $projectId = (int) $projectId;
            $sortOrder = $index;

            $stmt = $mysqli->prepare("UPDATE projects SET sort_order = ? WHERE id = ? AND status = 'active'");
            if (!$stmt) {
                throw new Exception('Unable to update project order: ' . $mysqli->error);
            }

            $stmt->bind_param("ii", $sortOrder, $projectId);
            if (!$stmt->execute()) {
                throw new Exception('Unable to update project order: ' . $stmt->error);
            }
            $stmt->close();
        }

        $mysqli->commit();
        echo json_encode(['success' => true, 'message' => 'Project order updated']);
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log('Update project order error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update project order']);
    }
}

elseif ($action === 'get_finance_records') {
    require_admin_role();

    try {
        ensure_finance_records_table($mysqli);

        $result = $mysqli->query("
            SELECT id, expense_type, expense_date, description, project_name, amount, received_by, remark, receipt_path, receipt_name, created_at, updated_at
            FROM finance_records
            ORDER BY expense_date DESC, id DESC
        ");

        if (!$result) {
            throw new Exception('Unable to load finance records: ' . $mysqli->error);
        }

        $records = [];
        while ($row = $result->fetch_assoc()) {
            $records[] = [
                'id' => (int) $row['id'],
                'expenseType' => $row['expense_type'],
                'date' => $row['expense_date'],
                'description' => $row['description'],
                'project' => $row['project_name'],
                'amount' => (float) $row['amount'],
                'receivedBy' => $row['received_by'],
                'remark' => $row['remark'],
                'receiptPath' => $row['receipt_path'] ? '../' . $row['receipt_path'] : '',
                'receiptName' => $row['receipt_name'] ?? '',
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        echo json_encode(['success' => true, 'records' => $records]);
    } catch (Exception $e) {
        error_log('Get finance records error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to load finance records']);
    }
}

elseif ($action === 'save_finance_record') {
    require_admin_role();

    $recordId = isset($input['record_id']) ? (int) $input['record_id'] : 0;
    $expenseType = trim($input['expense_type'] ?? '');
    $expenseDate = trim($input['expense_date'] ?? '');
    $description = trim($input['description'] ?? '');
    $projectName = trim($input['project_name'] ?? '');
    $amount = isset($input['amount']) ? (float) $input['amount'] : 0;
    $receivedBy = trim($input['received_by'] ?? '');
    $remark = trim($input['remark'] ?? '');
    if ($expenseType === 'Expenses for Materials') {
        $expenseType = 'Materials';
    }

    $allowedExpenseTypes = ['Materials', 'Labor Payroll', 'Other Expenses'];
    if ($expenseType === 'Labor Payroll' && $description === '') {
        $description = 'Labor payroll';
    }
    if ($expenseType === 'Other Expenses') {
        $description = $description !== '' ? $description : 'Other expense';
        $projectName = $projectName !== '' ? $projectName : 'General';
    }

    if (
        !in_array($expenseType, $allowedExpenseTypes, true) ||
        $expenseDate === '' ||
        $description === '' ||
        $projectName === '' ||
        $amount < 0 ||
        $receivedBy === '' ||
        !in_array($remark, ['Released', 'Unreleased'], true)
    ) {
        echo json_encode(['success' => false, 'message' => 'Please complete all expense fields.']);
        exit;
    }

    try {
        ensure_finance_records_table($mysqli);
        $adminId = isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
        $receiptPath = null;
        $receiptName = null;
        $oldReceiptPath = '';

        if ($recordId > 0) {
            $currentReceiptStmt = $mysqli->prepare("SELECT receipt_path, receipt_name FROM finance_records WHERE id = ? LIMIT 1");
            if ($currentReceiptStmt) {
                $currentReceiptStmt->bind_param("i", $recordId);
                $currentReceiptStmt->execute();
                $currentReceipt = $currentReceiptStmt->get_result()->fetch_assoc();
                $currentReceiptStmt->close();
                $receiptPath = $currentReceipt['receipt_path'] ?? null;
                $receiptName = $currentReceipt['receipt_name'] ?? null;
                $oldReceiptPath = $receiptPath ?: '';
            }
        }

        if (isset($_FILES['receipt_file']) && is_uploaded_file($_FILES['receipt_file']['tmp_name'])) {
            $receiptFile = $_FILES['receipt_file'];
            $allowedReceiptTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'application/pdf' => 'pdf'
            ];
            $receiptMime = mime_content_type($receiptFile['tmp_name']) ?: $receiptFile['type'];

            if (!isset($allowedReceiptTypes[$receiptMime])) {
                echo json_encode(['success' => false, 'message' => 'Receipt must be JPG, PNG, WebP, or PDF.']);
                exit;
            }

            if ((int) $receiptFile['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Receipt file must be 5MB or smaller.']);
                exit;
            }

            $receiptDir = __DIR__ . '/uploads/finance_receipts/';
            if (!is_dir($receiptDir)) {
                mkdir($receiptDir, 0775, true);
            }

            $receiptBaseName = sanitize_upload_name(pathinfo($receiptFile['name'], PATHINFO_FILENAME));
            $receiptExtension = $allowedReceiptTypes[$receiptMime];
            $storedReceiptName = $receiptBaseName . '-receipt-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $receiptExtension;
            $receiptTarget = $receiptDir . $storedReceiptName;

            if (!move_uploaded_file($receiptFile['tmp_name'], $receiptTarget)) {
                echo json_encode(['success' => false, 'message' => 'Unable to upload receipt scan.']);
                exit;
            }

            $receiptPath = 'uploads/finance_receipts/' . $storedReceiptName;
            $receiptName = $receiptFile['name'];
        }

        if (!$receiptPath) {
            echo json_encode(['success' => false, 'message' => 'Receipt scan is required.']);
            exit;
        }

        if ($recordId > 0) {
            $stmt = $mysqli->prepare("
                UPDATE finance_records
                SET expense_type = ?, expense_date = ?, description = ?, project_name = ?, amount = ?, received_by = ?, remark = ?, receipt_path = ?, receipt_name = ?
                WHERE id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                throw new Exception('Unable to update finance record: ' . $mysqli->error);
            }

            $stmt->bind_param("ssssdssssi", $expenseType, $expenseDate, $description, $projectName, $amount, $receivedBy, $remark, $receiptPath, $receiptName, $recordId);
        } else {
            $stmt = $mysqli->prepare("
                INSERT INTO finance_records (expense_type, expense_date, description, project_name, amount, received_by, remark, receipt_path, receipt_name, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new Exception('Unable to add finance record: ' . $mysqli->error);
            }

            $stmt->bind_param("ssssdssssi", $expenseType, $expenseDate, $description, $projectName, $amount, $receivedBy, $remark, $receiptPath, $receiptName, $adminId);
        }

        if (!$stmt->execute()) {
            throw new Exception('Unable to save finance record: ' . $stmt->error);
        }

        $savedId = $recordId > 0 ? $recordId : $stmt->insert_id;
        $stmt->close();

        if ($oldReceiptPath && $receiptPath && $oldReceiptPath !== $receiptPath) {
            delete_admin_upload_if_safe($oldReceiptPath, 'uploads/finance_receipts');
        }

        echo json_encode(['success' => true, 'message' => 'Finance record saved', 'record_id' => $savedId]);
    } catch (Exception $e) {
        error_log('Save finance record error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to save finance record']);
    }
}

elseif ($action === 'delete_finance_record') {
    require_admin_role();

    $recordId = isset($input['record_id']) ? (int) $input['record_id'] : 0;

    if ($recordId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid finance record']);
        exit;
    }

    try {
        ensure_finance_records_table($mysqli);
        $receiptPath = '';
        $receiptStmt = $mysqli->prepare("SELECT receipt_path FROM finance_records WHERE id = ? LIMIT 1");
        if ($receiptStmt) {
            $receiptStmt->bind_param("i", $recordId);
            $receiptStmt->execute();
            $receiptRow = $receiptStmt->get_result()->fetch_assoc();
            $receiptStmt->close();
            $receiptPath = $receiptRow['receipt_path'] ?? '';
        }

        $stmt = $mysqli->prepare("DELETE FROM finance_records WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Unable to delete finance record: ' . $mysqli->error);
        }

        $stmt->bind_param("i", $recordId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to delete finance record: ' . $stmt->error);
        }
        $stmt->close();

        if ($receiptPath) {
            delete_admin_upload_if_safe($receiptPath, 'uploads/finance_receipts');
        }

        echo json_encode(['success' => true, 'message' => 'Finance record deleted']);
    } catch (Exception $e) {
        error_log('Delete finance record error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to delete finance record']);
    }
}

elseif ($action === 'toggle_finance_record_remark') {
    require_admin_role();

    $recordId = isset($input['record_id']) ? (int) $input['record_id'] : 0;
    $remark = trim($input['remark'] ?? '');

    if ($recordId <= 0 || !in_array($remark, ['Released', 'Unreleased'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid finance record']);
        exit;
    }

    try {
        ensure_finance_records_table($mysqli);

        $stmt = $mysqli->prepare("UPDATE finance_records SET remark = ? WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Unable to update finance record: ' . $mysqli->error);
        }

        $stmt->bind_param("si", $remark, $recordId);
        if (!$stmt->execute()) {
            throw new Exception('Unable to update finance record: ' . $stmt->error);
        }
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Finance record updated']);
    } catch (Exception $e) {
        error_log('Toggle finance record remark error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update finance record']);
    }
}

else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
?>
