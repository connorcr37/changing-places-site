<?php
// Handle consignment form submissions without relying on third-party services.

declare(strict_types=1);

// Configuration
$recipientEmail = 'jen@shopchangingplaces.com';
$recipientName = 'Changing Places Consignment';
$fromEmail = 'no-reply@shopchangingplaces.com';
$maxFiles = 5;
$maxFileSize = 5 * 1024 * 1024; // 5 MB per file
$maxTotalSize = 20 * 1024 * 1024; // 20 MB combined
$allowedMimePrefixes = ['image/'];

$expectsJson = isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function respond(bool $success, string $message, int $statusCode = 200, bool $expectsJson = false): void
{
    http_response_code($statusCode);

    if ($expectsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
        ]);
    } else {
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><title>Consignment Submission</title>";
        echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
        echo '</head><body style="font-family: Arial, sans-serif; margin: 2rem;">';
        echo '<h1>Consignment Submission</h1>';
        echo '<p>' . $escapedMessage . '</p>';
        echo '<p><a href="form.html">Return to the form</a></p>';
        echo '</body></html>';
    }

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Unsupported request method.', 405, $expectsJson);
}

$requiredFields = ['name', 'phone', 'email', 'description'];
foreach ($requiredFields as $field) {
    if (!isset($_POST[$field]) || trim((string)$_POST[$field]) === '') {
        respond(false, 'Please complete all required fields.', 422, $expectsJson);
    }
}

$name = trim((string)$_POST['name']);
$phone = trim((string)$_POST['phone']);
$email = filter_var((string)$_POST['email'], FILTER_SANITIZE_EMAIL);
$description = trim((string)$_POST['description']);
$contactMethod = isset($_POST['contact-method']) ? trim((string)$_POST['contact-method']) : 'No preference';

$allowedContactMethods = ['text', 'email'];
$contactMethod = strtolower($contactMethod);
if (!in_array($contactMethod, $allowedContactMethods, true)) {
    $contactMethod = '';
}

$contactMethodLabel = match ($contactMethod) {
    'text' => 'Text',
    'email' => 'Email',
    default => 'No preference',
};

$sanitizeHeader = static fn(string $value): string => trim(str_replace(["\r", "\n"], '', $value));
$encodeHeader = static function (string $value): string {
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8');
    }

    return '=?UTF-8?B?' . base64_encode($value) . '?=';
};

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please provide a valid email address.', 422, $expectsJson);
}

$agreementAccepted = isset($_POST['agreement']);
if (!$agreementAccepted) {
    respond(false, 'You must confirm the consignment agreement.', 422, $expectsJson);
}

$attachments = [];
$totalSize = 0;
$finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;

if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
    $fileCount = count(array_filter(
        $_FILES['photos']['name'],
        static fn($name) => $name !== ''
    ));
    if ($fileCount > $maxFiles) {
        respond(false, 'Please limit photo uploads to ' . $maxFiles . ' files.', 422, $expectsJson);
    }

    for ($i = 0; $i < $fileCount; $i++) {
        $error = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            respond(false, 'One or more photos could not be uploaded. Please try again.', 400, $expectsJson);
        }

        $tmpPath = $_FILES['photos']['tmp_name'][$i];
        $originalName = $_FILES['photos']['name'][$i];
        $size = (int)($_FILES['photos']['size'][$i] ?? 0);
        $type = '';
        if ($finfo) {
            $detectedType = finfo_file($finfo, $tmpPath);
            if (is_string($detectedType)) {
                $type = $detectedType;
            }
        }
        if ($type === '') {
            $type = $_FILES['photos']['type'][$i] ?? '';
        }

        if (!is_uploaded_file($tmpPath)) {
            respond(false, 'Invalid upload detected.', 400, $expectsJson);
        }

        if ($size > $maxFileSize) {
            respond(false, 'Each photo must be smaller than 5 MB.', 422, $expectsJson);
        }

        $totalSize += $size;
        if ($totalSize > $maxTotalSize) {
            respond(false, 'Please keep the combined photo size under 20 MB.', 422, $expectsJson);
        }

        $isAllowedType = false;
        foreach ($allowedMimePrefixes as $prefix) {
            if (stripos($type, $prefix) === 0) {
                $isAllowedType = true;
                break;
            }
        }

        if (!$isAllowedType) {
            respond(false, 'Only image files may be uploaded.', 422, $expectsJson);
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$originalName) ?: 'photo.jpg';

        $attachments[] = [
            'name' => $safeName,
            'type' => $type,
            'content' => chunk_split(base64_encode((string)file_get_contents($tmpPath))),
        ];
    }
}

if ($finfo) {
    finfo_close($finfo);
}

$subject = 'New consignment submission from ' . $sanitizeHeader($name);

$lines = [
    'You have received a new consignment inquiry from the website.',
    '',
    'Name: ' . $name,
    'Phone: ' . $phone,
    'Email: ' . $email,
    'Preferred Contact Method: ' . $contactMethodLabel,
    '',
    'Item Details:',
    $description,
];

$normalizeLineBreaks = static fn(string $value): string => preg_replace("/\r\n|\r|\n/", "\r\n", $value ?? '') ?? '';

$messageBodyLines = array_map($normalizeLineBreaks, $lines);
$messageBody = implode("\r\n", $messageBodyLines);

$boundary = '=====' . bin2hex(random_bytes(16)) . '=====';

$headers = [];
$headers[] = 'From: ' . $encodeHeader($recipientName) . ' <' . $fromEmail . '>';
$headers[] = 'Reply-To: ' . $sanitizeHeader($email);
$headers[] = 'MIME-Version: 1.0';

if ($attachments) {
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

    $body = '--' . $boundary . "\r\n";
    $body .= 'Content-Type: text/plain; charset="UTF-8"' . "\r\n";
    $body .= 'Content-Transfer-Encoding: 7bit' . "\r\n\r\n";
    $body .= $messageBody . "\r\n";

    foreach ($attachments as $attachment) {
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $attachment['type'] . '; name="' . $attachment['name'] . '"' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . $attachment['name'] . '"' . "\r\n\r\n";
        $body .= $attachment['content'] . "\r\n";
    }

    $body .= '--' . $boundary . "--\r\n";
} else {
    $headers[] = 'Content-Type: text/plain; charset="UTF-8"';
    $body = $messageBody;
}

$headersString = implode("\r\n", $headers);

$mailSent = mail($recipientEmail, $subject, $body, $headersString);

if ($mailSent) {
    respond(true, 'Thanks! We have your details and will be in touch soon.', 200, $expectsJson);
}

respond(false, 'We could not send your message. Please try again later.', 500, $expectsJson);
