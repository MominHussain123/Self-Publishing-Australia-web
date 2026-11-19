<?php
/**
 * send.php — Matches form fields in form.html exactly:
 *   name, email, phone, country, genre, title, status, details
 *   manuscript = FILE upload
 * Sends email with optional attachment to authors@selfpublishingaustralia.com
 */

// ------------------------------------------------------------------
// CONFIG
// ------------------------------------------------------------------
$TO_EMAIL      = "authors@selfpublishingaustralia.com";
$FROM_EMAIL    = "info@selfpublishingaustralia.com"; // must exist on your host
$FROM_NAME     = "Self Publishing Australia";
$UPLOAD_DIR    = __DIR__ . "/uploads/";
$MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
$ALLOWED_EXTS  = ['pdf', 'doc', 'docx'];

// ------------------------------------------------------------------
// HELPERS
// ------------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');

function json_out(bool $success, string $message = ''): void {
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

function clean_header(string $v): string {
    return str_replace(["\r", "\n", "%0a", "%0d"], '', $v);
}

// ------------------------------------------------------------------
// ONLY POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_out(false, 'Method not allowed.');
}

// ------------------------------------------------------------------
// COLLECT INPUTS (matches your HTML field names exactly)
// ------------------------------------------------------------------
$name       = clean_header(trim($_POST['name']    ?? ''));
$email      = filter_var(trim($_POST['email']     ?? ''), FILTER_SANITIZE_EMAIL);
$phone      = clean_header(trim($_POST['phone']   ?? ''));
$country    = clean_header(trim($_POST['country'] ?? ''));
$genre      = clean_header(trim($_POST['genre']   ?? ''));
$title      = clean_header(trim($_POST['title']   ?? ''));
$status     = clean_header(trim($_POST['status']  ?? ''));
$details    = trim($_POST['details'] ?? '');
$ip         = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// ------------------------------------------------------------------
// VALIDATION
// ------------------------------------------------------------------
if ($name === '' || $email === '' || $phone === '') {
    json_out(false, 'Please fill in name, email and phone.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_out(false, 'Please enter a valid email address.');
}
if ($genre === '' || $title === '' || $status === '') {
    json_out(false, 'Please complete all required fields.');
}

// ------------------------------------------------------------------
// FILE UPLOAD — field name is "manuscript" in your HTML
// ------------------------------------------------------------------
$uploaded_file = '';
$original_name = '';

if (isset($_FILES['manuscript']) && $_FILES['manuscript']['error'] !== UPLOAD_ERR_NO_FILE) {

    if ($_FILES['manuscript']['error'] !== UPLOAD_ERR_OK) {
        json_out(false, 'File upload failed (error code ' . $_FILES['manuscript']['error'] . ').');
    }

    if ($_FILES['manuscript']['size'] > $MAX_FILE_SIZE) {
        json_out(false, 'File is too large. Maximum size is 10 MB.');
    }

    $original_name = basename($_FILES['manuscript']['name']);
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    if (!in_array($ext, $ALLOWED_EXTS, true)) {
        json_out(false, 'Only PDF, DOC or DOCX files are allowed.');
    }

    if (!is_dir($UPLOAD_DIR)) {
        @mkdir($UPLOAD_DIR, 0755, true);
    }

    $safe_name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $uploaded_file = $UPLOAD_DIR . $safe_name;

    if (!move_uploaded_file($_FILES['manuscript']['tmp_name'], $uploaded_file)) {
        json_out(false, 'Could not save your file. Please try again.');
    }
}

// ------------------------------------------------------------------
// BUILD EMAIL
// ------------------------------------------------------------------
$subject = "New Book Submission — " . $name;

$message  = "NEW FORM SUBMISSION\n";
$message .= "==============================\n\n";
$message .= "Name:              $name\n";
$message .= "Email:             $email\n";
$message .= "Phone:             $phone\n";
$message .= "Country:           $country\n\n";
$message .= "Book Title:        $title\n";
$message .= "Genre:             $genre\n";
$message .= "Manuscript status: $status\n\n";
$message .= "Details:\n$details\n\n";
$message .= "------------------------------\n";
$message .= "Submitted from IP: $ip\n";
$message .= "Date: " . date('Y-m-d H:i:s') . "\n";

$boundary = '----=_Boundary_' . md5(uniqid((string)mt_rand(), true));

$headers  = "From: {$FROM_NAME} <{$FROM_EMAIL}>\r\n";
$headers .= "Reply-To: {$name} <{$email}>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

$body  = "--{$boundary}\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $message . "\r\n\r\n";

if ($uploaded_file && file_exists($uploaded_file)) {
    $file_content = chunk_split(base64_encode(file_get_contents($uploaded_file)));
    $ext = strtolower(pathinfo($uploaded_file, PATHINFO_EXTENSION));
    $mime = 'application/octet-stream';
    if ($ext === 'pdf')  $mime = 'application/pdf';
    if ($ext === 'doc')  $mime = 'application/msword';
    if ($ext === 'docx') $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: {$mime}; name=\"{$original_name}\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"{$original_name}\"\r\n\r\n";
    $body .= $file_content . "\r\n";
}

$body .= "--{$boundary}--";

// ------------------------------------------------------------------
// SEND
// ------------------------------------------------------------------
$sent = @mail($TO_EMAIL, $subject, $body, $headers);

if ($uploaded_file && file_exists($uploaded_file)) {
    @unlink($uploaded_file);
}

if ($sent) {
    json_out(true);
} else {
    error_log("Mail failed for: {$email} (IP: {$ip})");
    json_out(false, 'We could not send your request right now. Please email us directly at ' . $TO_EMAIL);
}