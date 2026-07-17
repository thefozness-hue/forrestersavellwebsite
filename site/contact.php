<?php
/**
 * Contact-form handler for forrestersavell.com
 *
 * This is the ONLY server-side file on the site. It emails contact-form
 * submissions to a fixed address via the server's mailer, so notifications
 * come from your own domain — clean, no third-party branding.
 *
 * It has no admin panel, no database, no dependencies, and never needs
 * updating. It is hardened against the ways a simple mail script can be
 * abused:
 *   - only ever sends to ONE hard-coded recipient (can't be a spam relay)
 *   - strips CR/LF from header fields (no header injection)
 *   - honeypot field silently absorbs bots
 *   - per-IP rate limit (best-effort) caps flooding
 *   - validates and length-limits every field
 */

// ---------- config ----------
$TO       = 'info@forrestersavell.com';   // fixed recipient — never user-controlled
$FROM     = 'info@forrestersavell.com';   // must be an address on THIS domain (SPF/DKIM)
$FROM_NAME = 'Forrester Savell Website';
$SITE     = 'forrestersavell.com';
$SUCCESS  = '/thanks/';                    // shown on success
$MAX_NAME    = 100;
$MAX_EMAIL   = 254;
$MAX_MESSAGE = 5000;

function redirect($path) {
    header('Location: ' . $path, true, 303);
    exit;
}

function fail() {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>Message not sent</title>'
       . '<body style="margin:0;background:#0f0f0f;color:#e3d8c8;font-family:sans-serif;'
       . 'display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center">'
       . '<div><h1 style="color:#948570;letter-spacing:4px;text-transform:uppercase">Message not sent</h1>'
       . '<p>Sorry, something went wrong. Please try again in a moment.</p>'
       . '<p><a href="/#contact" style="color:#948570">Back to the form</a></p></div>';
    exit;
}

function fail_validate() {
    // most invalid input is caught client-side by `required`; send them back
    redirect('/#contact');
}

// ---------- only accept POST ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('/');
}

// ---------- honeypot: bots fill hidden fields, humans don't ----------
if (!empty($_POST['_honey'])) {
    redirect($SUCCESS); // pretend success so the bot moves on
}

// ---------- gather + normalise ----------
$name    = isset($_POST['name'])    ? trim((string) $_POST['name'])    : '';
$email   = isset($_POST['email'])   ? trim((string) $_POST['email'])   : '';
$message = isset($_POST['message']) ? trim((string) $_POST['message']) : '';

// strip CR/LF from single-line fields to prevent mail-header injection
$name  = preg_replace('/[\r\n]+/', ' ', $name);
$email = preg_replace('/[\r\n]+/', '', $email);

// ---------- validate ----------
if ($name === '' || strlen($name) > $MAX_NAME) fail_validate();
if (strlen($email) > $MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail_validate();
if ($message === '' || strlen($message) > $MAX_MESSAGE) fail_validate();

// ---------- gentle per-IP rate limit (best-effort; fails open) ----------
$ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bucket = sys_get_temp_dir() . '/fs_contact_' . md5($ip);
$now    = time();
$window = 3600;  // 1 hour
$limit  = 5;     // max submissions per IP per window
$times  = [];
if (is_readable($bucket)) {
    $times = array_filter(
        array_map('intval', explode(',', (string) @file_get_contents($bucket))),
        function ($t) use ($now, $window) { return $t > $now - $window; }
    );
}
if (count($times) >= $limit) {
    redirect($SUCCESS); // silently absorb — don't tip off abusers
}
$times[] = $now;
@file_put_contents($bucket, implode(',', $times), LOCK_EX);

// ---------- build + send ----------
$subject = 'New enquiry via ' . $SITE;

$body  = "You received a new message via " . $SITE . "\n";
$body .= str_repeat('-', 48) . "\n\n";
$body .= "Name:   " . $name . "\n";
$body .= "Email:  " . $email . "\n\n";
$body .= "Message:\n" . $message . "\n";

$headers  = 'From: ' . $FROM_NAME . ' <' . $FROM . ">\r\n";
$headers .= 'Reply-To: ' . $email . "\r\n";   // validated, CR/LF-stripped — safe
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: forrestersavell-contact\r\n";

$ok = @mail($TO, $subject, $body, $headers, '-f' . $FROM);

if (!$ok) {
    fail();
}

redirect($SUCCESS);
