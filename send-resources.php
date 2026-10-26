<?php
// ============================================================
// Atlas Kiosk — Resource email dispatcher (hardened)
// Companion to the kiosk HTML file.
// Place this file in the SAME folder as the kiosk HTML file
// on your web server, then publish both.
//
// Requires: PHP 7+ with mail() configured on the server.
// IMPORTANT: set KIOSK_FROM_EMAIL below to a real address on
// your domain (e.g. kiosk@atlasveterans.ca) so SPF/DKIM pass
// and the emails don't land in spam.
//
// SECURITY NOTES:
// - This endpoint sits outside WordPress, so Wordfence does
//   NOT protect it. These checks are the protection:
//   1) HTTPS + allowlisted atlasveterans.ca URLs only
//      (prevents trusted-domain phishing via arbitrary links)
//   2) Same-origin check when Origin/Referer is present
//      (defense-in-depth; curl without headers still hits
//      rate limits + allowlist below)
//   3) Per-IP + per-recipient + global rate limits
//   4) Strict input caps + fail-closed validation
// ============================================================

// ---------- CONFIG — EDIT THESE ----------
define('KIOSK_FROM_NAME',  'Atlas Institute Kiosk');
define('KIOSK_FROM_EMAIL', 'kiosk@atlasveterans.ca'); // <-- CHANGE to a real address on your domain
define('KIOSK_BOOTH_PIN',  '2018'); // <-- CHANGE: must match BOOTH_PIN in index.html
define('KIOSK_MAX_ITEMS', 100);             // allow "select all" (catalog ~89); URLs still allowlisted
define('KIOSK_MAX_RAW_BYTES', 80000);       // max JSON body size (fits 100 titles+urls)
define('KIOSK_RATE_LIMIT_IP_PER_HOUR', 40); // shared kiosk = shared IP: ~20-30 sends/hr realistic, 40 = headroom
define('KIOSK_MIN_SECONDS_BETWEEN_SENDS', 30); // anti-bot rapid fire; humans browse 1-2 min anyway
define('KIOSK_RATE_LIMIT_EMAIL_PER_HOUR', 3); // max emails to same recipient per hour (anti email-bombing)
define('KIOSK_RATE_LIMIT_GLOBAL_PER_DAY', 1000); // max emails total per day (abuse circuit-breaker)
define('KIOSK_ALLOWED_HOSTS', ['atlasveterans.ca', 'www.atlasveterans.ca']);
// -----------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive', true);

function kiosk_fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    kiosk_fail('Method not allowed', 405);
}

// ---- Same-origin check (defense-in-depth) ----
// Browsers always send Origin on fetch POST. If present (or Referer),
// it must match this server or the allowlisted site. Direct curl
// without headers skips this — still covered by allowlist + limits.
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$selfHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
foreach (['origin' => $origin, 'referer' => $referer] as $kind => $val) {
    if ($val === '' || $val === null) continue;
    $host = strtolower(parse_url($val, PHP_URL_HOST) ?: '');
    if ($host === '') continue;
    $ok = ($selfHost !== '' && $host === $selfHost) || in_array($host, KIOSK_ALLOWED_HOSTS, true);
    if (!$ok) {
        kiosk_fail('Forbidden origin.', 403);
    }
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '' || strlen($raw) > KIOSK_MAX_RAW_BYTES) {
    kiosk_fail('Invalid request body.');
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    kiosk_fail('Invalid request body.');
}

$email = isset($data['email']) ? trim((string) $data['email']) : '';
$name  = isset($data['name'])  ? trim((string) $data['name'])  : '';
$lang  = (isset($data['lang']) && $data['lang'] === 'fr') ? 'fr' : 'en';
$items = (isset($data['items']) && is_array($data['items'])) ? $data['items'] : [];
$pin   = isset($data['pin']) ? (string) $data['pin'] : '';
$isFr  = ($lang === 'fr');

// ---- Booth PIN gate (staff unlocks iPad once at setup) ----
// Client-side keypad is UX only; this is the real enforcement so
// direct POSTs without the PIN are rejected. Uses hash_equals to
// avoid timing leaks. Wrong PIN counts as 403 booth_locked so the
// kiosk can route staff back to Step 0 instead of showing a
// generic send error to visitors.
if (!hash_equals((string) KIOSK_BOOTH_PIN, $pin)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'booth_locked']);
    exit;
}

if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    kiosk_fail($isFr ? 'Adresse courriel invalide.' : 'Invalid email address.');
}
if (count($items) < 1 || count($items) > KIOSK_MAX_ITEMS) {
    kiosk_fail($isFr ? 'Aucune ressource à envoyer.' : 'No resources to send.');
}

// ---- Fail-closed URL allowlist ----
// Only https://atlasveterans.ca/* links may be sent. Any other URL
// (including http, protocol-relative, userinfo@ tricks, other hosts)
// rejects the WHOLE request so attackers can't mix 1 valid + 1 phish.
function kiosk_clean_title($s) {
    $s = strip_tags((string) $s);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s); // strip control chars
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = trim($s);
    if (function_exists('mb_substr')) return mb_substr($s, 0, 200);
    return substr($s, 0, 200);
}

$clean = [];
foreach ($items as $it) {
    if (!is_array($it)) {
        kiosk_fail($isFr ? 'Lien de ressource invalide.' : 'Invalid resource link.');
    }
    $title = kiosk_clean_title($it['title'] ?? '');
    $url   = trim((string) ($it['url'] ?? ''));
    if ($title === '' || $url === '' || strlen($url) > 1000) {
        kiosk_fail($isFr ? 'Lien de ressource invalide.' : 'Invalid resource link.');
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        kiosk_fail($isFr ? 'Lien de ressource invalide.' : 'Invalid resource link.');
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    $host   = strtolower($parts['host'] ?? '');
    // https only, allowlisted host only, no userinfo, must have path
    if ($scheme !== 'https'
        || !in_array($host, KIOSK_ALLOWED_HOSTS, true)
        || isset($parts['user']) || isset($parts['pass'])
        || !isset($parts['path']) || $parts['path'] === ''
    ) {
        kiosk_fail($isFr ? 'Lien de ressource invalide.' : 'Invalid resource link.');
    }
    // Rebuild canonical URL to kill credential/@ and case tricks
    $canon = 'https://' . $host
        . $parts['path']
        . (isset($parts['query']) ? '?' . $parts['query'] : '')
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    $clean[] = ['title' => $title, 'url' => $canon];
    if (count($clean) > KIOSK_MAX_ITEMS) {
        kiosk_fail($isFr ? 'Aucune ressource à envoyer.' : 'No resources to send.');
    }
}
if (count($clean) < 1) {
    kiosk_fail($isFr ? 'Aucune ressource à envoyer.' : 'No resources to send.');
}

// Name: plain text only, used in body greeting — strip tags/line breaks.
$name = strip_tags($name);
$name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $name);
$name = preg_replace('/\s+/u', ' ', $name);
$name = trim($name);
if (function_exists('mb_strlen') && mb_strlen($name) > 80) {
    $name = mb_substr($name, 0, 80);
} elseif (strlen($name) > 80) {
    $name = substr($name, 0, 80);
}

// ---- Rate limits (file based): per-IP + per-recipient + global ----
function kiosk_prune_hits(array $stored, $now, $window) {
    $hits = [];
    foreach ($stored as $t) {
        if (($now - (int) $t) < $window) $hits[] = (int) $t;
    }
    return $hits;
}
function kiosk_load_hits($file, $now, $window) {
    if (!is_file($file)) return [];
    $stored = json_decode(@file_get_contents($file), true);
    if (!is_array($stored)) return [];
    return kiosk_prune_hits($stored, $now, $window);
}

$ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now    = time();
$tmpDir = rtrim(sys_get_temp_dir(), '/\\');
$ipFile    = $tmpDir . '/atlas_kiosk_rl_' . md5($ip) . '.json';
$emailFile = $tmpDir . '/atlas_kiosk_rlemail_' . hash('sha256', strtolower($email)) . '.json';
$dayFile   = $tmpDir . '/atlas_kiosk_rlday_' . gmdate('Y-m-d') . '.json';

$ipHits    = kiosk_load_hits($ipFile, $now, 3600);
$emailHits = kiosk_load_hits($emailFile, $now, 3600);
$dayHits   = kiosk_load_hits($dayFile, $now, 86400);

// Anti-bot: minimum pause between two sends from the same IP.
// Invisible at events (visitors need 1-2 min to browse/select).
if (!empty($ipHits) && ($now - (int) end($ipHits)) < KIOSK_MIN_SECONDS_BETWEEN_SENDS) {
    kiosk_fail($isFr ? 'Veuillez patienter quelques secondes avant le prochain envoi.'
                     : 'Please wait a few seconds before sending again.', 429);
}

if (count($ipHits) >= KIOSK_RATE_LIMIT_IP_PER_HOUR
    || count($emailHits) >= KIOSK_RATE_LIMIT_EMAIL_PER_HOUR
    || count($dayHits) >= KIOSK_RATE_LIMIT_GLOBAL_PER_DAY) {
    kiosk_fail($isFr ? 'Limite atteinte. Veuillez réessayer plus tard.'
                     : 'Rate limit reached. Please try again later.', 429);
}
$ipHits[] = $now; $emailHits[] = $now; $dayHits[] = $now;
@file_put_contents($ipFile, json_encode($ipHits), LOCK_EX);
@file_put_contents($emailFile, json_encode($emailHits), LOCK_EX);
@file_put_contents($dayFile, json_encode($dayHits), LOCK_EX);

// ---- Abuse log (no full email stored — hash + domain only) ----
$recipientDomain = strtolower(substr(strrchr($email, '@'), 1) ?: '');
@file_put_contents(
    $tmpDir . '/atlas_kiosk_send.log',
    gmdate('c') . ' ip=' . $ip . ' domain=' . $recipientDomain
        . ' items=' . count($clean) . ' lang=' . $lang
        . ' ua=' . substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120) . "\n",
    FILE_APPEND | LOCK_EX
);

// ---- Build the message ----
$greet   = $name !== '' ? ($isFr ? "Bonjour {$name}," : "Hello {$name},")
                        : ($isFr ? 'Bonjour,' : 'Hello,');
$subject = $isFr ? 'Vos ressources de l’Institut Atlas'
                 : 'Your Atlas Institute resources';
$intro   = $isFr ? 'Voici les liens vers les ressources que vous avez sélectionnées sur la borne :'
                 : 'Here are links to the resources you selected at the kiosk:';
$siteUrl = $isFr ? 'https://atlasveterans.ca/fr/' : 'https://atlasveterans.ca/';
$signoff = $isFr ? 'L’équipe de l’Institut Atlas pour les vétérans et leur famille'
                 : 'The Atlas Institute for Veterans and Families team';

$text = $greet . "\n\n" . $intro . "\n\n";
$htmlItems = '';
foreach ($clean as $i => $it) {
    $n = $i + 1;
    $text .= "{$n}. {$it['title']}\n   {$it['url']}\n\n";
    $htmlItems .= '<li style="margin:0 0 10px 0;"><a href="'
        . htmlspecialchars($it['url'], ENT_QUOTES, 'UTF-8')
        . '" style="color:#1a6b5a;font-weight:bold;">'
        . htmlspecialchars($it['title'], ENT_QUOTES, 'UTF-8')
        . '</a><br><span style="color:#64748b;font-size:12px;">'
        . htmlspecialchars($it['url'], ENT_QUOTES, 'UTF-8')
        . '</span></li>' . "\r\n";
}
$text .= "— {$signoff}\n{$siteUrl}";

$html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;color:#1e293b;">'
    . '<p>' . htmlspecialchars($greet, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<ol>' . $htmlItems . '</ol>'
    . '<p style="color:#64748b;">— ' . htmlspecialchars($signoff, ENT_QUOTES, 'UTF-8')
    . '<br><a href="' . $siteUrl . '" style="color:#1a6b5a;">atlasveterans.ca</a></p></div>';

// multipart/alternative (plain text + HTML)
$boundary = '=_kiosk_' . md5(uniqid((string) mt_rand(), true));
$from     = KIOSK_FROM_NAME . ' <' . KIOSK_FROM_EMAIL . '>';
$headers  = "From: {$from}\r\n";
$headers .= "Reply-To: {$from}\r\n";
$headers .= "Date: " . date(DATE_RFC2822) . "\r\n";
$headers .= "Message-ID: <kiosk-" . md5(uniqid((string) mt_rand(), true)) . "@atlasveterans.ca>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
$headers .= "X-Mailer: Atlas-Kiosk/1.0\r\n";

$textEncoded = quoted_printable_encode($text);
$htmlEncoded = quoted_printable_encode($html);

$body  = "--{$boundary}\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
$body .= $textEncoded . "\r\n\r\n";
$body .= "--{$boundary}\r\n";
$body .= "Content-Type: text/html; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
$body .= $htmlEncoded . "\r\n\r\n";
$body .= "--{$boundary}--";

// -f envelope sender improves deliverability
error_clear_last();
$sent = @mail(
    $email,
    '=?UTF-8?B?' . base64_encode($subject) . '?=',
    $body,
    $headers,
    '-f' . KIOSK_FROM_EMAIL
);
$mailError = $sent ? null : (error_get_last()['message'] ?? 'mail() returned false');

// ---- Detailed usage log (email, name, resources, timestamp) ----
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$logFile = $logDir . '/send-resources-' . gmdate('Y-m') . '.jsonl';
$logEntry = [
    'timestamp' => gmdate('c'),
    'email' => $email,
    'name' => $name,
    'lang' => $lang,
    'items_count' => count($clean),
    'items' => array_map(fn($it) => ['title' => $it['title'], 'url' => $it['url']], $clean),
    'ip' => $ip,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'sent' => (bool) $sent,
    'mail_error' => $mailError,
];
@file_put_contents($logFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

if (!$sent) {
    kiosk_fail($isFr ? 'L’envoi a échoué. Veuillez réessayer.'
                     : 'Delivery failed. Please try again.', 500);
}

echo json_encode(['ok' => true, 'sent' => count($clean)]);
