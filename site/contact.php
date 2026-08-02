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
 *   3. Signed token        — the form must fetch a token before posting
 *                            (see "token" below). Stops bots that blindly
 *                            POST, but see the honest limits noted there.
 *   4. Content filters     — BBCode link spam, Cyrillic+link, link floods,
 *                            keyboard-mash gibberish, generated Gmail
 *                            addresses, implausibly fast submissions
 *   5. Per-IP rate limit   — caps flooding
 *   6. Strict validation   — length limits, real email address
 *   7. Header hardening    — CR/LF stripped, fixed recipient (no open relay)
 *
 * ---- token ----
 * GET /contact.php?t=1 returns {"token":"<issued-at>.<signature>"}. The
 * signature is an HMAC over the issue time + the visitor's IP, so a token
 * cannot be forged, reused from another address, or replayed after it
 * expires. The page fetches one with JavaScript and posts it back in a
 * hidden field.
 *
 * KNOW WHAT THIS DOES NOT DO. It is NOT the equivalent of the old
 * JavaScript-rendered WordPress form, and it does NOT prove a browser ran
 * our JavaScript. The endpoint is a plain URL visible in the page source,
 * so any script can simply fetch a token and post it — two requests instead
 * of one. Verified: a bare curl fetch-then-post passes this gate. What it
 * genuinely stops is the common bot that blindly POSTs a scraped form
 * without fetching a token first.
 *
 * Consequence: the token is a speed bump, and the content filters below are
 * pattern-matching that a spammer can word around. If spam volume ever
 * justifies it, the real fix is a challenge service (e.g. Cloudflare
 * Turnstile) or a proof-of-work step — something a plain script cannot
 * replicate cheaply. Deliberately not added yet: volume is low and neither
 * belongs between a real client and this form without cause.
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

/**
 * True when a string reads like keyboard mash rather than language.
 *
 * Real words — in any language written in Latin script — keep roughly a third
 * vowels and change letter case at most once or twice (a capital at the start
 * of each word). Randomly generated strings like "ehqpILyHzTIHNKsFqCWO" are
 * vowel-starved AND flip case constantly. BOTH must be true, which keeps
 * legitimate names safe: checked against a spread of real-world names
 * (Krzysztof Wojciechowski, Tsuyoshi Nakamura, Christopherson, McDonald,
 * D'Angelo, MARK JOHNSON...) and none of them trip it. Strings under 10
 * letters are never judged — too short to tell noise from a short name.
 */
function looks_random($s) {
    $letters = preg_replace('/[^A-Za-z]/', '', $s);
    $len = strlen($letters);
    if ($len < 10) return false;
    $vowel_ratio = preg_match_all('/[aeiouAEIOU]/', $letters) / $len;
    $flips = 0;
    for ($i = 1; $i < $len; $i++) {
        if (ctype_upper($letters[$i]) !== ctype_upper($letters[$i - 1])) $flips++;
    }
    return $vowel_ratio < 0.25 && ($flips / $len) > 0.25;
}

/**
 * Gmail ignores dots in addresses, so one account can spray endlessly many
 * unique-looking senders ("r.i.y.a.r.a.h.i.jez.05.8@gmail.com"). Real people
 * use at most a couple; four or more is address-generation.
 */
function gmail_dot_abuse($email) {
    $parts = explode('@', strtolower($email));
    if (count($parts) !== 2) return false;
    if (!in_array($parts[1], ['gmail.com', 'googlemail.com'], true)) return false;
    return substr_count($parts[0], '.') >= 4;
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
// Browsers send Origin on cross-origin form POSTs and (in modern versions) on
// same-origin ones too. If either header is present it must match the host
// this request arrived on. Deriving the allowed host from the request itself
// (rather than hard-coding the domain) means this can never silently reject a
// genuine visitor — on the live domain, a www. address, a staging subdomain
// or a local copy — while still turning away forms posted from other sites.
$self_host = strtolower(preg_replace('~:\d+$~', '', $_SERVER['HTTP_HOST'] ?? ''));
$allowed_hosts = array_filter([
    $self_host,
    preg_match('~^www\.~', $self_host) ? preg_replace('~^www\.~', '', $self_host) : 'www.' . $self_host,
]);
foreach ([$_SERVER['HTTP_ORIGIN'] ?? '', $_SERVER['HTTP_REFERER'] ?? ''] as $value) {
    if ($value !== '' && $self_host !== ''
        && !in_array(host_of($value), $allowed_hosts, true)) {
        redirect($SUCCESS);   // silently absorb — off-site POST, certainly a bot
    }
}

// ---------- 2. honeypot: bots fill hidden fields, humans don't ----------
if (!empty($_POST['_honey'])) {
    redirect($SUCCESS); // pretend success so the bot moves on
}

// ---------- 3. signed token: proves our page + JavaScript produced this ----------
$token = (string) ($_POST['_t'] ?? '');
if (!token_valid($token, $ip, $TOKEN_SECRET, $TOKEN_MAX_AGE)) {
    fail_token();
}

// How long the page was open before this was submitted. Typing a name, an
// email and a message takes a person several seconds; a script posts the
// instant it has a token. Only ever used to FLAG, never to drop — if the
// page-load token fetch failed, the form fetches one as it submits, and that
// legitimate path also looks instant.
$fill_seconds = time() - (int) strstr($token, '.', true);

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
// Count links, but ignore the music and file-sharing services real clients
// send all the time (an artist linking three Bandcamp tracks is the most
// normal enquiry there is — it must never look like spam).
$FRIENDLY = '(bandcamp\.com|spotify\.com|youtube\.com|youtu\.be|soundcloud\.com|'
          . 'wetransfer\.com|dropbox\.com|drive\.google\.com|music\.apple\.com|'
          . 'tidal\.com|vimeo\.com|instagram\.com|facebook\.com|linktr\.ee)';
preg_match_all('~(?:https?://|www\.)([^\s/]+)~i', $name . ' ' . $message, $found);
$links = 0;
foreach ($found[1] as $host) {
    if (!preg_match('~' . $FRIENDLY . '$~i', $host)) $links++;
}

// Gibberish signals. Judged separately so that one odd-looking field alone
// never loses a message — an unusual name with a real enquiry attached is
// merely flagged, not dropped.
$random_name    = looks_random($name);
$random_message = looks_random($message);
$dot_abuse      = gmail_dot_abuse($email);

// Certain spam — drop silently so the sender believes it worked.
$certain_spam =
       preg_match('~\[/?url~i', $message)                            // BBCode link spam
    || preg_match('~\p{Cyrillic}~u', $message) && $links > 0          // Cyrillic + link
    || preg_match('~https?://|www\.~i', $name)                       // URL in the name field
    || $links > 5                                                    // link flood
    // Keyboard mash in BOTH the name and the message. Requiring both is what
    // makes this safe to drop: no genuine enquiry has a random name attached
    // to a random message.
    || ($random_name && $random_message)
    // A generated Gmail address paired with either field reading as noise.
    || ($dot_abuse && ($random_name || $random_message));

if ($certain_spam) {
    redirect($SUCCESS);
}

// Suspicious but not certain — still deliver, but flag it in the subject so
// it can be filtered, and so a real enquiry is never silently lost.
$spam_words = '~\b(seo|backlinks?|link.?building|guest post|indexing|rank higher|'
            . 'crypto|casino|promo code|web development services|traffic boost)\b~i';
$suspicious = ($links >= 3)
    || preg_match($spam_words, $message)
    || $random_name || $random_message || $dot_abuse
    || $fill_seconds < 4                                             // posted too fast to have been typed
    // A 12-plus character message with no space at all is not a sentence.
    || (strlen($message) >= 12 && !preg_match('~\s~', $message));

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
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "X-Mailer: forrestersavell-contact\r\n";

$ok = @mail($TO, $subject, $body, $headers, '-f' . $FROM);

if (!$ok) {
    fail_send();
}

redirect($SUCCESS);
