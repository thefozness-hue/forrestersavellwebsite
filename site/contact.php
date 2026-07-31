<?php
/**
 * Contact-form handler for forrestersavell.com
 *
 * This is the ONLY server-side file on the site. It emails contact-form
 * submissions to a fixed address via the server's mailer, so notifications
 * come from your own domain — clean, no third-party branding.
 *
 * It has no admin panel, no database, no dependencies, and never needs
 * updating. Defences, in the order they run:
 *
 *   1. Same-origin check   — the POST must come from our own page
 *   2. Honeypot            — hidden field only bots fill in
 *   3. Signed token        — the form must ask us for a token via JavaScript
 *                            first (see "token" below). This is what the old
 *                            WordPress form did implicitly by rendering
 *                            itself in JS, and it is what stops the
 *                            scrape-and-POST spam bots.
 *   4. Content filters     — BBCode link spam, Cyrillic+link, link floods
 *   5. Per-IP rate limit   — caps flooding
 *   6. Strict validation   — length limits, real email address
 *   7. Header hardening    — CR/LF stripped, fixed recipient (no open relay)
 *
 * ---- token ----
 * GET /contact.php?t=1 returns {"token":"<issued-at>.<signature>"}. The
 * signature is an HMAC over the issue time + the visitor's IP, so tokens
 * can't be forged or reused from elsewhere. The page fetches one with
 * JavaScript and posts it back in a hidden field. A bot that simply POSTs
 * to this script without executing our JavaScript has no valid token and
 * is turned away.
 */

// ---------- config ----------
$TO        = 'info@forrestersavell.com';  // fixed recipient — never user-controlled
$FROM      = 'info@forrestersavell.com';  // must be an address on THIS domain (SPF/DKIM)
$FROM_NAME = 'Forrester Savell Website';
$SITE      = 'forrestersavell.com';
$SUCCESS   = '/thanks/';                  // shown on success

// Secret used to sign form tokens. Changing it simply invalidates any
// tokens already issued (worst case, someone with the page open has to
// reload it). It is not a password and grants no access to anything.
$TOKEN_SECRET = '335bbc3439bc17d769b7d7f2b598b01d2fec7256b036e4ee8181219d455ef994';
$TOKEN_MAX_AGE = 21600;                   // 6 hours — covers a page left open

$ALLOWED_HOSTS = ['forrestersavell.com', 'www.forrestersavell.com'];

$MAX_NAME    = 100;
$MAX_EMAIL   = 254;
$MAX_MESSAGE = 5000;

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// ---------- helpers ----------
function redirect($path) {
    header('Location: ' . $path, true, 303);
    exit;
}

function page($title, $lead, $status) {
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<body style="margin:0;background:#0f0f0f;color:#e3d8c8;font-family:sans-serif;'
       . 'display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center">'
       . '<div style="max-width:32em;padding:2em"><h1 style="color:#948570;letter-spacing:4px;'
       . 'text-transform:uppercase;font-size:1.3em">' . htmlspecialchars($title) . '</h1>'
       . '<p>' . $lead . '</p>'
       . '<p><a href="/#contact" style="color:#948570">Back to the form</a></p></div>';
    exit;
}

function fail_send() {
    page('Message not sent', 'Sorry, something went wrong at our end. Please try again in a moment.', 500);
}

function fail_token() {
    page(
        'Message not sent',
        'We could not verify your browser session. This usually means JavaScript '
        . 'is turned off, or the page had been open for a long time.<br><br>'
        . 'Please reload the page and send your message again.',
        403
    );
}

function fail_validate() {
    // most invalid input is caught client-side by `required`; send them back
    redirect('/#contact');
}

function token_sign($issued, $ip, $secret) {
    return $issued . '.' . hash_hmac('sha256', $issued . '|' . $ip, $secret);
}

function token_valid($token, $ip, $secret, $max_age) {
    if (!is_string($token) || strpos($token, '.') === false) return false;
    list($issued, ) = explode('.', $token, 2);
    if ($issued === '' || !ctype_digit($issued)) return false;
    $age = time() - (int) $issued;
    if ($age < -300 || $age > $max_age) return false;   // allow small clock skew
    return hash_equals(token_sign($issued, $ip, $secret), $token);
}

/** Host of a URL-ish header value, lowercased and without port. */
function host_of($url) {
    $host = parse_url((string) $url, PHP_URL_HOST);
    return $host ? strtolower($host) : '';
}

// ---------- token endpoint: GET /contact.php?t=1 ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['t'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(['token' => token_sign((string) time(), $ip, $TOKEN_SECRET)]);
    exit;
}

// ---------- only accept POST ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('/');
}

// ---------- 1. same-origin check ----------
// Browsers send Origin on cross-origin form POSTs and (in modern versions)
// on same-origin ones too. If either header is present it must be ours.
foreach (['HTTP_ORIGIN' => $_SERVER['HTTP_ORIGIN'] ?? '',
          'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? ''] as $value) {
    if ($value !== '' && !in_array(host_of($value), $ALLOWED_HOSTS, true)) {
        redirect($SUCCESS);   // silently absorb — off-site POST, certainly a bot
    }
}

// ---------- 2. honeypot: bots fill hidden fields, humans don't ----------
if (!empty($_POST['_honey'])) {
    redirect($SUCCESS); // pretend success so the bot moves on
}

// ---------- 3. signed token: proves our page + JavaScript produced this ----------
if (!token_valid($_POST['_t'] ?? '', $ip, $TOKEN_SECRET, $TOKEN_MAX_AGE)) {
    fail_token();
}

// ---------- gather + normalise ----------
$name    = isset($_POST['name'])    ? trim((string) $_POST['name'])    : '';
$email   = isset($_POST['email'])   ? trim((string) $_POST['email'])   : '';
$message = isset($_POST['message']) ? trim((string) $_POST['message']) : '';

// strip CR/LF from single-line fields to prevent mail-header injection
$name  = preg_replace('/[\r\n]+/', ' ', $name);
$email = preg_replace('/[\r\n]+/', '', $email);

// ---------- 6. validate ----------
if ($name === '' || strlen($name) > $MAX_NAME) fail_validate();
if (strlen($email) > $MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail_validate();
if ($message === '' || strlen($message) > $MAX_MESSAGE) fail_validate();

// ---------- 4. content filters ----------
$links = preg_match_all('~https?://|www\.~i', $name . ' ' . $message);

// Certain spam — drop silently so the sender believes it worked.
$certain_spam =
       preg_match('~\[/?url~i', $message)                            // BBCode link spam
    || preg_match('~\p{Cyrillic}~u', $message) && $links > 0          // Cyrillic + link
    || preg_match('~https?://|www\.~i', $name)                       // URL in the name field
    || $links > 5;                                                   // link flood

if ($certain_spam) {
    redirect($SUCCESS);
}

// Suspicious but not certain — still deliver, but flag it in the subject so
// it can be filtered, and so a real enquiry is never silently lost.
$spam_words = '~\b(seo|backlinks?|link.?building|guest post|indexing|rank higher|'
            . 'crypto|casino|promo code|web development services|traffic boost)\b~i';
$suspicious = ($links >= 2) || preg_match($spam_words, $message);

// ---------- 5. gentle per-IP rate limit (best-effort; fails open) ----------
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

// ---------- 7. build + send ----------
$subject = ($suspicious ? '[Possible spam] ' : '') . 'New enquiry via ' . $SITE;

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
    fail_send();
}

redirect($SUCCESS);
