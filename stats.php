<?php
// ============================================================
// Atlas Kiosk — Usage Statistics Viewer
// Place in same folder as send-resources.php
// Access with: ?key=YOUR_SECRET_KEY
// ============================================================

define('STATS_KEY', 'atlas-kiosk-stats'); // CHANGE THIS to a secret key

$providedKey = $_GET['key'] ?? '';
if ($providedKey !== STATS_KEY) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>Invalid or missing key. Use ?key=YOUR_SECRET_KEY</p>';
    exit;
}

date_default_timezone_set('America/Toronto');
$displayTz = new DateTimeZone('America/Toronto');

$logDir = __DIR__ . '/logs';
$sendFiles = glob($logDir . '/send-resources-*.jsonl');
$subFiles = glob($logDir . '/subscribe-newsletter-*.jsonl');
$files = array_merge($sendFiles, $subFiles);
usort($files, fn($a, $b) => strcmp(basename($b), basename($a))); // newest first

// Parse query params
$dateFrom = $_GET['from'] ?? '';
$dateTo   = $_GET['to'] ?? '';
$searchEmail = strtolower(trim($_GET['email'] ?? ''));
$limit = min(max((int)($_GET['limit'] ?? 100), 1), 1000);
$page = max((int)($_GET['page'] ?? 1), 1);
$sort = $_GET['sort'] ?? 'timestamp';
$order = $_GET['order'] ?? 'desc';
$logType = $_GET['type'] ?? 'all'; // all, send, subscribe

// Eastern-time day boundaries for filtering
$fromBoundary = $dateFrom ? (new DateTime($dateFrom . ' 00:00:00', $displayTz))->getTimestamp() : null;
$toBoundary   = $dateTo ? (new DateTime($dateTo . ' 23:59:59', $displayTz))->getTimestamp() : null;

$entries = [];
foreach ($files as $file) {
    $fileType = str_contains($file, 'subscribe-newsletter') ? 'subscribe' : 'send';
    if ($logType !== 'all' && $logType !== $fileType) continue;

    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) continue;
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!$entry) continue;
        $entry['_type'] = $fileType;

        // Filter by date range (Eastern days)
        $entryTs = strtotime($entry['timestamp']);
        if ($fromBoundary !== null && $entryTs < $fromBoundary) continue;
        if ($toBoundary !== null && $entryTs > $toBoundary) continue;

        // Filter by email
        if ($searchEmail && stripos($entry['email'] ?? '', $searchEmail) === false) continue;

        $entries[] = $entry;
    }
}

// Sort
usort($entries, function($a, $b) use ($sort, $order) {
    $av = $a[$sort] ?? '';
    $bv = $b[$sort] ?? '';
    $cmp = is_numeric($av) && is_numeric($bv) ? ($av <=> $bv) : strcmp($av, $bv);
    return $order === 'asc' ? $cmp : -$cmp;
});

// Pagination
$total = count($entries);
$totalPages = max(1, ceil($total / $limit));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;
$pageEntries = array_slice($entries, $offset, $limit);

// Stats summary
$totalSends = 0;
$totalSubs = 0;
$totalResources = 0;
$uniqueEmails = [];
$byLang = ['en' => 0, 'fr' => 0];
$byDate = [];
$byStatus = [];
foreach ($entries as $e) {
    if (($e['_type'] ?? 'send') === 'send') $totalSends++;
    else $totalSubs++;
    $totalResources += $e['items_count'] ?? 0;
    $uniqueEmails[$e['email']] = true;
    $byLang[$e['lang'] ?? 'en']++;
    $byStatus[$e['status'] ?? 'unknown'] = ($byStatus[$e['status'] ?? 'unknown'] ?? 0) + 1;
    $date = (new DateTime($e['timestamp']))->setTimezone($displayTz)->format('Y-m-d');
    $byDate[$date] = ($byDate[$date] ?? 0) + 1;
}
krsort($byDate);

function fmtDate($iso) {
    global $displayTz;
    return (new DateTime($iso))->setTimezone($displayTz)->format('M j, Y H:i T');
}
function maskToken($token, $visible = 3) {
    if ($token === '') return '';
    $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
    $len = $chars ? count($chars) : strlen($token);
    $show = min($visible, max(1, $len - 1));
    $prefix = $chars ? implode('', array_slice($chars, 0, $show)) : substr($token, 0, $show);
    return $prefix . str_repeat('*', max(1, $len - $show));
}
function maskEmail($email) {
    $email = trim((string)$email);
    if ($email === '') return '-';
    $at = strrpos($email, '@');
    if ($at === false) return maskToken($email);
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    $dot = strrpos($domain, '.');
    if ($dot === false) {
        $domainMasked = maskToken($domain);
    } else {
        $domainMasked = maskToken(substr($domain, 0, $dot)) . substr($domain, $dot);
    }
    return maskToken($local) . '@' . $domainMasked;
}
function maskName($name) {
    $name = trim((string)$name);
    if ($name === '') return '-';
    $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_map('maskToken', $parts));
}
function sortLink($col, $label) {
    global $sort, $order, $dateFrom, $dateTo, $searchEmail, $limit, $logType;
    $newOrder = ($sort === $col && $order === 'desc') ? 'asc' : 'desc';
    $icon = $sort === $col ? ($order === 'desc' ? ' ▼' : ' ▲') : '';
    $params = http_build_query(array_filter([
        'key' => STATS_KEY,
        'type' => $logType,
        'from' => $dateFrom,
        'to' => $dateTo,
        'email' => $searchEmail,
        'limit' => $limit,
        'sort' => $col,
        'order' => $newOrder,
    ]));
    return '<a href="?' . $params . '">' . htmlspecialchars($label) . $icon . '</a>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiosk Usage Stats</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brandNavy: '#0F2C59',
                        brandTeal: '#436760',
                        brandTealDark: '#324D48',
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .log-detail { display: none; font-size: 12px; background: #f8fafc; padding: 8px; border-radius: 4px; margin-top: 4px; white-space: pre-wrap; max-height: 300px; overflow: auto; }
        .log-detail.open { display: block; }
        .url-list { font-size: 11px; color: #475569; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen p-6">
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold text-slate-800">Kiosk Usage Statistics</h1>
            <span class="text-sm text-slate-500">Key: <?= htmlspecialchars(STATS_KEY) ?></span>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-5 gap-4 mb-6">
            <div class="bg-white p-4 rounded-lg shadow border border-slate-200">
                <p class="text-sm text-slate-500">Resource Sends</p>
                <p class="text-3xl font-bold text-brandNavy"><?= number_format($totalSends) ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow border border-slate-200">
                <p class="text-sm text-slate-500">Newsletter Subs</p>
                <p class="text-3xl font-bold text-emerald-600"><?= number_format($totalSubs) ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow border border-slate-200">
                <p class="text-sm text-slate-500">Resources Sent</p>
                <p class="text-3xl font-bold text-brandTeal"><?= number_format($totalResources) ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow border border-slate-200">
                <p class="text-sm text-slate-500">Unique Emails</p>
                <p class="text-3xl font-bold text-amber-600"><?= number_format(count($uniqueEmails)) ?></p>
            </div>
            <div class="bg-white p-4 rounded-lg shadow border border-slate-200">
                <p class="text-sm text-slate-500">Avg Resources/Send</p>
                <p class="text-3xl font-bold text-indigo-600"><?= $totalSends ? round($totalResources / $totalSends, 1) : 0 ?></p>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white p-4 rounded-lg shadow border border-slate-200 mb-6">
            <form method="get" class="flex flex-wrap gap-4 items-end">
                <input type="hidden" name="key" value="<?= htmlspecialchars(STATS_KEY) ?>">
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Type</label>
                    <select name="type" class="px-3 py-2 border border-slate-300 rounded-md text-sm focus:ring-2 focus:ring-brandTeal">
                        <option value="all" <?= $logType === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="send" <?= $logType === 'send' ? 'selected' : '' ?>>Resource Sends</option>
                        <option value="subscribe" <?= $logType === 'subscribe' ? 'selected' : '' ?>>Newsletter Subs</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">From Date</label>
                    <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" class="px-3 py-2 border border-slate-300 rounded-md text-sm focus:ring-2 focus:ring-brandTeal">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">To Date</label>
                    <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" class="px-3 py-2 border border-slate-300 rounded-md text-sm focus:ring-2 focus:ring-brandTeal">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Email Search</label>
                    <input type="text" name="email" value="<?= htmlspecialchars($searchEmail) ?>" placeholder="Filter by email..." class="px-3 py-2 border border-slate-300 rounded-md text-sm focus:ring-2 focus:ring-brandTeal w-64">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-600 mb-1">Limit</label>
                    <select name="limit" class="px-3 py-2 border border-slate-300 rounded-md text-sm focus:ring-2 focus:ring-brandTeal">
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                        <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
                        <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 bg-brandTeal text-white rounded-md text-sm font-medium hover:bg-brandTealDark">Filter</button>
                <a href="?key=<?= urlencode(STATS_KEY) ?>" class="px-4 py-2 bg-slate-200 text-slate-700 rounded-md text-sm font-medium hover:bg-slate-300">Reset</a>
            </form>
        </div>

        <!-- Language breakdown -->
        <div class="bg-white p-4 rounded-lg shadow border border-slate-200 mb-6">
            <h3 class="font-semibold text-slate-800 mb-3">By Language</h3>
            <div class="flex gap-6 text-sm">
                <span class="font-medium">EN: <span class="font-bold text-brandNavy"><?= $byLang['en'] ?></span></span>
                <span class="font-medium">FR: <span class="font-bold text-brandTeal"><?= $byLang['fr'] ?></span></span>
            </div>
        </div>

        <!-- Daily chart (simple text) -->
        <div class="bg-white p-4 rounded-lg shadow border border-slate-200 mb-6">
            <h3 class="font-semibold text-slate-800 mb-3">Sends by Day (last 30)</h3>
            <div class="font-mono text-xs overflow-x-auto">
                <?php $i = 0; foreach (array_slice($byDate, 0, 30, true) as $date => $count): $i++; ?>
                    <div class="flex items-center gap-3 py-1">
                        <span class="w-24 text-slate-600"><?= $date ?></span>
                        <div class="flex-1 h-4 bg-slate-200 rounded relative">
                            <div class="h-full bg-brandTeal rounded" style="width: <?= min(100, $count / max(1, max($byDate)) * 100) ?>%"></div>
                        </div>
                        <span class="w-16 text-right font-bold"><?= $count ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Results Table -->
        <div class="bg-white rounded-lg shadow border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="p-3 text-left font-semibold text-slate-600"><?= sortLink('timestamp', 'Timestamp') ?></th>
                            <th class="p-3 text-left font-semibold text-slate-600">Type</th>
                            <th class="p-3 text-left font-semibold text-slate-600"><?= sortLink('email', 'Email') ?></th>
                            <th class="p-3 text-left font-semibold text-slate-600"><?= sortLink('name', 'Name') ?></th>
                            <th class="p-3 text-left font-semibold text-slate-600"><?= sortLink('lang', 'Lang') ?></th>
                            <th class="p-3 text-left font-semibold text-slate-600">Items / Status</th>
                            <th class="p-3 text-left font-semibold text-slate-600">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($pageEntries as $idx => $entry): 
                            $isSend = ($entry['_type'] ?? 'send') === 'send';
                            $itemsCount = $entry['items_count'] ?? 0;
                            $status = $entry['status'] ?? '';
                        ?>
                            <tr class="hover:bg-slate-50 cursor-pointer" onclick="toggleDetail(this)">
                                <td class="p-3 text-slate-700"><?= fmtDate($entry['timestamp']) ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-medium <?= $isSend ? 'bg-blue-100 text-blue-700' : 'bg-emerald-100 text-emerald-700' ?>">
                                        <?= $isSend ? 'Send' : 'Subscribe' ?>
                                    </span>
                                </td>
                                <td class="p-3 text-slate-700"><?= htmlspecialchars(maskEmail($entry['email'] ?? '')) ?></td>
                                <td class="p-3 text-slate-700">
                                    <?php if ($isSend): ?>
                                        <?= htmlspecialchars(maskName($entry['name'] ?? '')) ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars(maskName(($entry['fname'] ?? '') . ' ' . ($entry['lname'] ?? ''))) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-xs font-medium <?= $entry['lang'] === 'fr' ? 'bg-teal-100 text-teal-700' : 'bg-blue-100 text-blue-700' ?>">
                                        <?= strtoupper($entry['lang'] ?? 'EN') ?>
                                    </span>
                                </td>
                                <td class="p-3 text-slate-700 font-medium">
                                    <?php if ($isSend): ?>
                                        <?= $itemsCount ?> resources
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-xs font-medium 
                                            <?= $status === 'subscribed' ? 'bg-emerald-100 text-emerald-700' : 
                                               ($status === 'pending' ? 'bg-amber-100 text-amber-700' : 
                                               ($status === 'already_subscribed' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-700')) ?>">
                                            <?= $status === 'pending' ? 'Sent' : ucfirst($status) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-center text-slate-400">▼</td>
                            </tr>
                            <tr class="log-detail" id="detail-<?= $offset + $idx ?>">
                                <td colspan="7">
                                    <div class="px-6 pb-4">
                                        <div class="mb-2"><strong>Type:</strong> <?= $isSend ? 'Resource Send' : 'Newsletter Subscribe' ?></div>
                                        <div class="mb-2"><strong>Email:</strong> <?= htmlspecialchars(maskEmail($entry['email'] ?? '')) ?></div>
                                        <div class="mb-2"><strong>Name:</strong> <?= $isSend ? htmlspecialchars(maskName($entry['name'] ?? '')) : htmlspecialchars(maskName(($entry['fname'] ?? '') . ' ' . ($entry['lname'] ?? ''))) ?></div>
                                        <?php if (!$isSend): ?>
                                            <div class="mb-2"><strong>Organization:</strong> <?= htmlspecialchars($entry['org'] ?? '-') ?></div>
                                            <div class="mb-2"><strong>Position:</strong> <?= htmlspecialchars($entry['position'] ?? '-') ?></div>
                                            <div class="mb-2"><strong>Audience:</strong> <?= htmlspecialchars(implode(', ', $entry['audience'] ?? [])) ?></div>
                                            <div class="mb-2"><strong>Tags:</strong> <?= htmlspecialchars(implode(', ', $entry['tags'] ?? [])) ?></div>
                                            <div class="mb-2"><strong>Status:</strong> <?= htmlspecialchars($status) ?></div>
                                        <?php endif; ?>
                                        <div class="mb-2"><strong>Timestamp:</strong> <?= fmtDate($entry['timestamp']) ?> <span class="text-slate-400">(<?= htmlspecialchars($entry['timestamp']) ?> UTC)</span></div>
                                        <div class="mb-2"><strong>IP:</strong> <?= htmlspecialchars($entry['ip'] ?? '-') ?></div>
                                        <div class="mb-2"><strong>User Agent:</strong> <?= htmlspecialchars(substr($entry['user_agent'] ?? '-', 0, 120)) ?></div>
                                        <?php if ($isSend): ?>
                                            <div class="mb-2"><strong>Resources (<?= $itemsCount ?>):</strong></div>
                                            <div class="url-list ml-4 space-y-1">
                                                <?php foreach (($entry['items'] ?? []) as $item): ?>
                                                    <div>• <a href="<?= htmlspecialchars($item['url']) ?>" target="_blank" class="text-brandTeal hover:underline"><?= htmlspecialchars($item['title']) ?></a></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($pageEntries)): ?>
                            <tr><td colspan="7" class="p-8 text-center text-slate-500">No entries found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="p-4 border-t border-slate-200 flex items-center justify-between">
                    <span class="text-sm text-slate-600">Page <?= $page ?> of <?= $totalPages ?> (<?= $total ?> total)</span>
                    <div class="flex gap-2">
                        <?php if ($page > 1): ?>
                            <a href="<?= '?' . http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="px-3 py-1 bg-slate-100 text-slate-700 rounded text-sm hover:bg-slate-200">Prev</a>
                        <?php endif; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="<?= '?' . http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="px-3 py-1 bg-brandTeal text-white rounded text-sm hover:bg-brandTealDark">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
        </div>
    </div>

    <script>
        function toggleDetail(row) {
            const nextRow = row.nextElementSibling;
            if (nextRow && nextRow.classList.contains('log-detail')) {
                nextRow.classList.toggle('open');
                row.querySelector('td:last-child').textContent = nextRow.classList.contains('open') ? '▲' : '▼';
            }
        }
    </script>
</body>
</html>