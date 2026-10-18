<?php
declare(strict_types=1);

namespace Zapeks\Inquiry;

const SALES_EMAIL = 'sales@zapeks.com';
const REQUEST_TYPES = [
    'Component sourcing / RFQ',
    'Supplier development',
    'Engineering or quality support',
    'Warehousing and logistics',
    'General inquiry',
];

function result(int $status, array $body): array
{
    return ['status' => $status, 'body' => $body];
}

function validateFields(array $post): array
{
    $limits = ['name' => 120, 'email' => 254, 'company' => 160, 'requestType' => 80,
        'parts' => 6000, 'quantity' => 200, 'date' => 10, 'location' => 300, 'requirements' => 5000];
    $required = ['name', 'email', 'company', 'parts'];
    $fields = [];
    $errors = [];
    foreach ($limits as $name => $limit) {
        $value = $post[$name] ?? '';
        if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
            || (!in_array($name, ['parts', 'requirements'], true) && preg_match('/[\r\n]/', $value))) {
            $errors[$name] = 'Please check this field and its length.';
            $fields[$name] = '';
            continue;
        }
        $fields[$name] = trim($value);
        if (in_array($name, $required, true) && $fields[$name] === '') {
            $errors[$name] = 'This field is required.';
        }
    }
    if (!isset($errors['email']) && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid work email address.';
    }
    if (!in_array($fields['requestType'], REQUEST_TYPES, true)) {
        $errors['requestType'] = 'Select an inquiry type from the list.';
    }
    if ($fields['date'] !== '') {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $fields['date']);
        if (!$date || $date->format('Y-m-d') !== $fields['date']) {
            $errors['date'] = 'Enter a valid target date.';
        }
    }
    return [$fields, $errors];
}

function claimRateSlot(string $directory, string $ip, int $now): int
{
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new \RuntimeException('Could not create the inquiry rate-limit directory.');
    }
    $handle = @fopen($directory . '/limits.json', 'c+');
    if (!$handle) {
        throw new \RuntimeException('Could not open inquiry rate-limit storage.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Could not lock inquiry rate-limit storage.');
        }
        $raw = stream_get_contents($handle);
        $state = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($state)) {
            throw new \RuntimeException('Invalid inquiry rate-limit storage.');
        }
        foreach ($state as $key => $times) {
            $recent = array_values(array_filter((array)$times, static fn($time) => is_int($time) && $time > $now - 3600));
            if ($recent) {
                $state[$key] = $recent;
            } else {
                unset($state[$key]);
            }
        }
        $key = hash('sha256', $ip);
        $times = $state[$key] ?? [];
        if (count($times) >= 5) {
            return max(1, $times[0] + 3600 - $now);
        }
        if ($times && $times[count($times) - 1] > $now - 30) {
            return $times[count($times) - 1] + 30 - $now;
        }
        if (!isset($state[$key]) && count($state) >= 10000) {
            return 60;
        }
        $times[] = $now;
        $state[$key] = $times;
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new \RuntimeException('Could not save inquiry rate-limit state.');
        }
        return 0;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function buildMail(array $fields, string $reference, int $now): array
{
    $labels = ['name' => 'Name', 'company' => 'Company', 'email' => 'Work email',
        'requestType' => 'Inquiry type', 'parts' => 'Parts / description', 'quantity' => 'Quantity',
        'date' => 'Target date', 'location' => 'Delivery location', 'requirements' => 'Requirements'];
    $body = "Zapeks website inquiry\nReference: $reference\nSubmitted: " . gmdate('Y-m-d H:i:s', $now) . " UTC\n";
    foreach ($labels as $name => $label) {
        if ($fields[$name] !== '') {
            $body .= "\n$label: " . str_replace(["\r\n", "\r"], "\n", $fields[$name]) . "\n";
        }
    }
    return [
        'to' => SALES_EMAIL,
        'subject' => 'Zapeks inquiry: ' . $fields['requestType'],
        'body' => quoted_printable_encode(str_replace("\n", "\r\n", $body)),
        'headers' => [
            'From' => 'Zapeks Website <' . SALES_EMAIL . '>',
            'Reply-To' => $fields['email'],
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
        ],
    ];
}

function submit(array $post, array $session, string $ip, int $now, string $stateDirectory, callable $deliver): array
{
    $token = $post['csrfToken'] ?? null;
    if (!is_string($token) || !is_string($session['csrf'] ?? null)
        || !hash_equals($session['csrf'], $token) || !is_int($session['issued'] ?? null)
        || $now - $session['issued'] > 3600) {
        return result(403, ['ok' => false, 'message' => 'Your form session expired. Please try submitting again.']);
    }
    if (!is_string($post['website'] ?? '')) {
        return result(400, ['ok' => false, 'message' => 'Please refresh the page and try again.']);
    }
    if (($post['website'] ?? '') !== '') {
        return result(200, ['ok' => true, 'message' => 'Your inquiry has been submitted. Thank you for contacting Zapeks.']);
    }
    if ($now - $session['issued'] < 2) {
        return result(422, ['ok' => false, 'message' => 'Please wait a moment, then submit your inquiry again.']);
    }
    [$fields, $errors] = validateFields($post);
    if ($errors) {
        return result(422, ['ok' => false, 'message' => 'Please check the highlighted fields.', 'errors' => $errors]);
    }
    $retryAfter = claimRateSlot($stateDirectory, $ip, $now);
    if ($retryAfter) {
        return result(429, ['ok' => false, 'message' => 'Please wait before sending another inquiry. You can also email sales@zapeks.com.', 'retryAfter' => $retryAfter]);
    }
    $reference = 'ZAP-' . gmdate('Ymd', $now) . '-' . strtoupper(bin2hex(random_bytes(3)));
    if (!$deliver(buildMail($fields, $reference, $now))) {
        error_log('Zapeks inquiry mail acceptance failed: ' . $reference);
        return result(503, ['ok' => false, 'message' => 'We could not send your inquiry. Your details are still here. Please try again later or email sales@zapeks.com.']);
    }
    return result(200, ['ok' => true, 'message' => 'Your inquiry has been submitted. Thank you for contacting Zapeks.', 'reference' => $reference]);
}

function respond(array $response): void
{
    http_response_code($response['status']);
    if (isset($response['body']['retryAfter'])) {
        header('Retry-After: ' . (int)$response['body']['retryAfter']);
    }
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function handleRequest(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    ini_set('display_errors', '0');
    try {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['GET', 'POST'], true)) {
            header('Allow: GET, POST');
            respond(result(405, ['ok' => false, 'message' => 'This request method is not supported.']));
            return;
        }
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && !in_array($origin, ['https://zapeks.com', 'https://www.zapeks.com'], true)) {
            respond(result(403, ['ok' => false, 'message' => 'Please submit your inquiry from zapeks.com.']));
            return;
        }
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000) {
            respond(result(413, ['ok' => false, 'message' => 'Your inquiry is too long. Please shorten it and try again.']));
            return;
        }
        if ($method === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded') !== 0) {
            respond(result(415, ['ok' => false, 'message' => 'Please submit the form on the Contact page.']));
            return;
        }
        session_name('zapeks_inquiry');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start()) {
            throw new \RuntimeException('Could not start inquiry session.');
        }
        $now = time();
        if ($method === 'GET') {
            if (!isset($_SESSION['csrf'], $_SESSION['issued']) || $now - $_SESSION['issued'] > 3600) {
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $_SESSION['issued'] = $now;
            }
            $token = $_SESSION['csrf'];
            session_write_close();
            respond(result(200, ['csrfToken' => $token]));
            return;
        }
        $session = $_SESSION;
        session_write_close();
        $response = submit($_POST, $session, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $now,
            getenv('ZAPEKS_INQUIRY_STATE_DIR') ?: '/home/zapeksco/.zapeks-inquiry',
            static fn(array $email): bool => @mail($email['to'], $email['subject'], $email['body'], $email['headers'], '-f' . SALES_EMAIL));
        respond($response);
    } catch (\Throwable $error) {
        error_log('Zapeks inquiry service error: ' . $error->getMessage());
        respond(result(503, ['ok' => false, 'message' => 'The inquiry service is temporarily unavailable. Please try again later or email sales@zapeks.com.']));
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    handleRequest();
}
