<?php
declare(strict_types=1);

/**
 * Shipment assistant endpoint.
 *
 * Uses the DeepSeek chat API when DEEPSEEK_API_KEY is present in .env.
 * If no key is configured (or the API call fails) it answers from the live
 * database with a small rule-based responder, so the customer always gets a
 * useful answer and no paid service is ever required.
 */

require_once dirname(__DIR__, 2) . '/config/config.php';

// The chatbot is read-gated like track-api.php, so it needs the same session
// that pre-track.php uses to remember a verified customer.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function nxChatOut(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$tracking = trim((string) ($input['tracking'] ?? $_GET['tracking'] ?? ''));
$message = trim((string) ($input['message'] ?? ''));

if ($tracking === '' || $message === '') {
    nxChatOut(['reply' => 'Please include a tracking number and a question.'], 400);
}
if (strlen($message) > 600) {
    $message = substr($message, 0, 600);
}

try {
    $pdo = nxPdo();
    $stmt = $pdo->prepare('SELECT * FROM shipments WHERE tracking_number = :t LIMIT 1');
    $stmt->execute([':t' => $tracking]);
    $shipment = $stmt->fetch();

    if (!$shipment) {
        nxChatOut(['reply' => 'I could not find that tracking number. Please double-check it.']);
    }

    // ---- Access gate (same rule as track-api.php) --------------------------
    // Do not expose shipment data to an unverified visitor.
    $hasContact = trim((string) $shipment['customer_email']) !== ''
        || trim((string) $shipment['customer_phone']) !== '';
    $tracker = nxTrackerVerified($tracking);
    $isAdminPreview = nxIsAdminSession();

    if ($hasContact && !$tracker && !$isAdminPreview) {
        nxChatOut([
            'reply' => 'Please verify your tracking details on the tracking page first, then ask again.',
            'code'  => 'verify_required',
        ], 403);
    }

    $sid = (int) $shipment['id'];

    $es = $pdo->prepare('SELECT title, description, location, event_time FROM tracking_events WHERE shipment_id = :s ORDER BY event_time DESC, id DESC LIMIT 5');
    $es->execute([':s' => $sid]);
    $events = $es->fetchAll();

    $hs = $pdo->prepare("SELECT hold_type, reason FROM shipment_holds WHERE shipment_id = :s AND status = 'active'");
    $hs->execute([':s' => $sid]);
    $holds = $hs->fetchAll();

    $eventLines = [];
    foreach ($events as $ev) {
        $eventLines[] = '- ' . $ev['event_time'] . ' — ' . $ev['title']
            . ($ev['location'] ? ' @ ' . $ev['location'] : '')
            . ($ev['description'] ? ': ' . $ev['description'] : '');
    }

    $holdText = '';
    foreach ($holds as $h) {
        $holdText .= $h['hold_type'] . ' hold: ' . $h['reason'] . '; ';
    }

    $context = "Tracking number: {$shipment['tracking_number']}\n"
        . "Customer: {$shipment['customer_name']}\n"
        . "Status: {$shipment['status']}\n"
        . "Origin: {$shipment['origin']}\n"
        . "Destination: {$shipment['destination']}\n"
        . 'Progress: ' . round((float) $shipment['distance_travelled'], 1) . ' km of '
        . round((float) $shipment['distance_total'], 1) . " km\n"
        . 'Remaining: ' . round((float) $shipment['distance_remaining'], 1) . " km\n"
        . 'Current speed: ' . round((float) $shipment['speed'], 1) . " km/h\n"
        . 'Estimated delivery: ' . ($shipment['estimated_delivery'] ?: $shipment['eta'] ?: 'not set') . "\n"
        . 'Live ETA: ' . nxEtaText($shipment['distance_remaining'], $shipment['speed']) . "\n"
        . ($holdText !== '' ? "Active holds: {$holdText}\n" : '')
        . "Recent events:\n" . implode("\n", $eventLines);

    $apiKey = (string) env('DEEPSEEK_API_KEY', '');

    if ($apiKey !== '' && function_exists('curl_init')) {
        $payload = [
            'model' => (string) env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are the Nexora Logistics customer support assistant. Answer ONLY from the shipment data below. Be concise, friendly and factual. If the answer is not in the data, say you will have a human follow up.\n\nSHIPMENT DATA:\n" . $context,
                ],
                ['role' => 'user', 'content' => $message],
            ],
            'max_tokens' => 300,
            'temperature' => 0.3,
        ];

        $ch = curl_init('https://api.deepseek.com/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 25,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300 && is_string($response)) {
            $decoded = json_decode($response, true);
            $reply = $decoded['choices'][0]['message']['content'] ?? null;
            if (is_string($reply) && trim($reply) !== '') {
                nxChatOut(['reply' => trim($reply), 'source' => 'deepseek']);
            }
        }
    }

    // ---- Local fallback responder -----------------------------------------
    nxChatOut(['reply' => nxLocalAnswer($shipment, $events, $holds, $message), 'source' => 'local']);
} catch (Throwable $e) {
    nxChatOut(['reply' => 'Sorry, I could not look that up right now. Please try again shortly.']);
}

/**
 * Small rule-based responder used when no DeepSeek key is configured.
 */
function nxLocalAnswer(array $s, array $events, array $holds, string $message): string
{
    $q = strtolower($message);
    $status = str_replace('_', ' ', (string) $s['status']);
    $eta = nxEtaText($s['distance_remaining'], $s['speed']);

    if ($holds) {
        $h = $holds[0];
        return 'This shipment is currently on a ' . $h['hold_type'] . ' hold: ' . $h['reason']
            . '. Our team will update the timeline as soon as it clears.';
    }

    if (strpos($q, 'where') !== false || strpos($q, 'position') !== false || strpos($q, 'location') !== false) {
        return 'It is currently moving between ' . $s['origin'] . ' and ' . $s['destination']
            . ', with ' . round((float) $s['distance_remaining']) . ' km remaining. ' . $eta . '.';
    }

    if (strpos($q, 'when') !== false || strpos($q, 'eta') !== false || strpos($q, 'arrive') !== false || strpos($q, 'delivery') !== false) {
        $abs = $s['estimated_delivery'] ?: ($s['eta'] ?: '');
        return $eta . ($abs ? ' (scheduled around ' . $abs . ' UTC).' : '.');
    }

    if (strpos($q, 'status') !== false || strpos($q, 'update') !== false) {
        $last = $events ? (' The latest update is: ' . $events[0]['title'] . '.') : '';
        return 'Current status: ' . $status . '.' . $last . ' ' . $eta . '.';
    }

    if (strpos($q, 'speed') !== false) {
        return 'It is travelling at about ' . round((float) $s['speed']) . ' km/h. ' . $eta . '.';
    }

    $last = $events ? ('Latest event: ' . $events[0]['title'] . ' — ' . $events[0]['event_time'] . '.') : '';
    return 'Tracking ' . $s['tracking_number'] . ' is currently ' . $status . '. ' . $last . ' ' . $eta . '.';
}
