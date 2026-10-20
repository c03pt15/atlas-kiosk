<?php
// ============================================================
// Atlas Kiosk — Newsletter subscription via Mailchimp API
// Standalone endpoint (no WordPress dependency)
// ============================================================

// ---------- CONFIG — EDIT THESE ----------
define('MC_API_KEY',       'REPLACE_WITH_YOUR_MAILCHIMP_API_KEY-us10');
define('MC_LIST_ID',       '09707943bd');
define('MC_DATACENTER',    'us10'); // from API key suffix
define('MC_DOUBLE_OPTIN',  true);  // true = pending (confirmation email), false = subscribed directly

// Mailchimp merge field tags (from Audience > Settings > Audience fields)
define('MC_MERGE_ORG',     'ORGNAME');     // Organization name
define('MC_MERGE_POSITION','MMERGE6');     // Position
define('MC_MERGE_LANG',    'MMERGE7');     // Language (dropdown: English/Français)

// Use tags for audience (simpler, no interest group setup needed)
$AUDIENCE_TAGS = [
    'veteran'          => 'Veteran',
    'family'           => 'Veteran Family Member',
    'service_provider' => 'Service Provider',
    'researcher'       => 'Researcher',
    'other'            => 'Other',
];
// -----------------------------------------

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function mc_fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mc_fail('Method not allowed', 405);
}

// Same-origin check (defense-in-depth)
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$selfHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$allowedHosts = ['atlasveterans.ca', 'www.atlasveterans.ca'];
foreach (['origin' => $origin, 'referer' => $referer] as $kind => $val) {
    if ($val === '' || $val === null) continue;
    $host = strtolower(parse_url($val, PHP_URL_HOST) ?: '');
    if ($host === '') continue;
    $ok = ($selfHost !== '' && $host === $selfHost) || in_array($host, $allowedHosts, true);
    if (!$ok) mc_fail('Forbidden origin.', 403);
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '' || strlen($raw) > 50000) {
    mc_fail('Invalid request body.');
}
$data = json_decode($raw, true);
if (!is_array($data)) mc_fail('Invalid request body.');

// ---- Input validation ----
$email = isset($data['email']) ? trim((string)$data['email']) : '';
$fname = isset($data['fname']) ? trim((string)$data['fname']) : '';
$lname = isset($data['lname']) ? trim((string)$data['lname']) : '';
$lang  = (isset($data['lang']) && $data['lang'] === 'fr') ? 'fr' : 'en';
$audience = isset($data['audience']) && is_array($data['audience']) ? $data['audience'] : [];
$org = isset($data['org']) ? trim((string)$data['org']) : '';
$position = isset($data['position']) ? trim((string)$data['position']) : '';
$interests = isset($data['interests']) && is_array($data['interests']) ? $data['interests'] : [];

if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    mc_fail($lang === 'fr' ? 'Adresse courriel invalide.' : 'Invalid email address.');
}

// Sanitize text inputs
$fname = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($fname));
$fname = preg_replace('/\s+/u', ' ', $fname);
$fname = mb_substr(trim($fname), 0, 80);

$lname = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($lname));
$lname = preg_replace('/\s+/u', ' ', $lname);
$lname = mb_substr(trim($lname), 0, 80);

$org = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($org));
$org = preg_replace('/\s+/u', ' ', $org);
$org = mb_substr(trim($org), 0, 100);

$position = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($position));
$position = preg_replace('/\s+/u', ' ', $position);
$position = mb_substr(trim($position), 0, 100);

// ---- Build merge fields ----
$mergeFields = [];
if ($fname !== '') $mergeFields['FNAME'] = $fname;
if ($lname !== '') $mergeFields['LNAME'] = $lname;
if ($org !== '') $mergeFields[MC_MERGE_ORG] = $org;
if ($position !== '') $mergeFields[MC_MERGE_POSITION] = $position;
// Language: Mailchimp expects "English" or "Français" for the dropdown
if ($lang !== '') $mergeFields[MC_MERGE_LANG] = ($lang === 'fr') ? 'Français' : 'English';

// Build tags from audience selection
$tags = [];
foreach ($audience as $key) {
    if (isset($AUDIENCE_TAGS[$key])) {
        $tags[] = $AUDIENCE_TAGS[$key];
    }
}
// Also include any explicitly passed tags
if (!empty($data['tags']) && is_array($data['tags'])) {
    $tags = array_merge($tags, array_filter(array_map('trim', $data['tags'])));
}
$tags = array_unique($tags);

// ---- Mailchimp API call ----
$url = "https://" . MC_DATACENTER . ".api.mailchimp.com/3.0/lists/" . MC_LIST_ID . "/members";

$payload = [
    'email_address' => $email,
    'status'        => MC_DOUBLE_OPTIN ? 'pending' : 'subscribed',
    'merge_fields'  => $mergeFields,
];

if (!empty($tags)) {
    $payload['tags'] = $tags;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_USERPWD         => 'anystring:' . MC_API_KEY,
    CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_POST            => true,
    CURLOPT_POSTFIELDS      => json_encode($payload),
    CURLOPT_TIMEOUT         => 10,
    CURLOPT_SSL_VERIFYPEER  => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    mc_fail($lang === 'fr' ? 'Erreur de connexion au service.' : 'Service connection error.', 502);
}

$respData = json_decode($response, true);

    // ---- Detailed usage log for newsletter ----
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/subscribe-newsletter-' . gmdate('Y-m') . '.jsonl';
    $logEntry = [
        'timestamp' => gmdate('c'),
        'email' => $email,
        'fname' => $fname,
        'lname' => $lname,
        'org' => $org ?? '',
        'position' => $position ?? '',
        'audience' => $audience ?? [],
        'tags' => $tags ?? [],
        'lang' => $lang,
        'status' => $respData['status'] ?? 'unknown',
        'http_code' => $httpCode,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
    @file_put_contents($logFile, json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    // Handle specific Mailchimp responses
    if ($httpCode >= 200 && $httpCode < 300) {
        echo json_encode(['ok' => true, 'status' => $respData['status'] ?? 'subscribed']);
        exit;
    }

// Already subscribed
if ($httpCode === 400 && isset($respData['title']) && $respData['title'] === 'Member Exists') {
    // Try to update existing member (add tags/merge fields if needed)
    $subscriberHash = md5(strtolower($email));
    $updateUrl = "https://" . MC_DATACENTER . ".api.mailchimp.com/3.0/lists/" . MC_LIST_ID . "/members/" . $subscriberHash;
    
    $updatePayload = [];
    if (!empty($mergeFields)) $updatePayload['merge_fields'] = $mergeFields;
    if (!empty($tags)) $updatePayload['tags'] = $tags;
    
    if (!empty($updatePayload)) {
        $ch2 = curl_init($updateUrl);
        curl_setopt_array($ch2, [
            CURLOPT_USERPWD         => 'anystring:' . MC_API_KEY,
            CURLOPT_HTTPHEADER      => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => 'PATCH',
            CURLOPT_POSTFIELDS      => json_encode($updatePayload),
            CURLOPT_TIMEOUT         => 10,
        ]);
        curl_exec($ch2);
        curl_close($ch2);
    }
    
    echo json_encode(['ok' => true, 'status' => 'already_subscribed', 'message' => $lang === 'fr' ? 'Cette adresse est déjà inscrite.' : 'This email is already subscribed.']);
    exit;
}

// Other errors
$errorMsg = $respData['detail'] ?? ($lang === 'fr' ? 'Inscription impossible.' : 'Subscription failed.');
if ($httpCode === 429) {
    mc_fail($lang === 'fr' ? 'Trop de requêtes. Réessayez plus tard.' : 'Too many requests. Try again later.', 429);
}

mc_fail($errorMsg, $httpCode);