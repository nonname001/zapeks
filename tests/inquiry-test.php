<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inquiry.php';

use function Zapeks\Inquiry\submit;
use function Zapeks\Inquiry\claimRateSlot;

$root = sys_get_temp_dir() . '/zapeks-inquiry-test-' . bin2hex(random_bytes(5));
mkdir($root, 0700);
ini_set('error_log', $root . '/errors.log');
$now = 1800000000;
$session = ['csrf' => str_repeat('a', 64), 'issued' => $now - 10];
$base = ['csrfToken' => $session['csrf'], 'website' => '', 'name' => 'Test User',
    'email' => 'test@example.com', 'company' => 'Test Company', 'requestType' => 'Component sourcing / RFQ',
    'parts' => "Connector ABC\nApproved alternate DEF", 'quantity' => '25', 'date' => '2026-12-20',
    'location' => 'New York', 'requirements' => 'Traceability required'];
$checks = 0;
$deliveries = [];
$fakeMail = static function (array $mail) use (&$deliveries): bool {
    $deliveries[] = $mail;
    return true;
};
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}

try {
    $response = submit($base, $session, '192.0.2.1', $now, $root . '/valid', $fakeMail);
    check($response['status'] === 200 && $response['body']['ok'], 'Valid inquiry was not accepted.');
    check(count($deliveries) === 1, 'A valid inquiry must be sent exactly once.');
    check($deliveries[0]['to'] === 'sales@zapeks.com', 'Recipient must be fixed.');
    check($deliveries[0]['headers']['Reply-To'] === 'test@example.com', 'Reply-To must use the visitor email.');
    check($deliveries[0]['headers']['From'] === 'Zapeks Website <sales@zapeks.com>', 'Sender must be fixed.');
    check(str_contains(quoted_printable_decode($deliveries[0]['body']), 'Approved alternate DEF'), 'Multiline details were lost.');
    check(str_starts_with($response['body']['reference'], 'ZAP-'), 'Receipt reference missing.');

    $invalidCases = [
        ['name' => ''], ['email' => ''], ['company' => ''], ['parts' => ''],
        ['email' => "test@example.com\r\nBcc: other@example.com"], ['name' => "Test\r\nBcc: other@example.com"],
        ['email' => ['test@example.com']], ['name' => str_repeat('x', 121)], ['parts' => str_repeat('x', 6001)],
        ['requestType' => 'Unknown type'], ['date' => '2026-02-31'], ['email' => 'not-an-email'],
    ];
    foreach ($invalidCases as $index => $changes) {
        $response = submit(array_replace($base, $changes), $session, '192.0.2.2', $now, $root . '/invalid', $fakeMail);
        check($response['status'] === 422 && !$response['body']['ok'], 'Invalid field case accepted: ' . $index);
    }
    check(count($deliveries) === 1, 'Invalid submissions reached the mail transport.');
    $response = submit(array_replace($base, ['csrfToken' => 'invalid']), $session, '192.0.2.3', $now, $root . '/csrf', $fakeMail);
    check($response['status'] === 403, 'Invalid CSRF token accepted.');
    $response = submit($base, array_replace($session, ['issued' => $now - 3601]), '192.0.2.3', $now, $root . '/expired', $fakeMail);
    check($response['status'] === 403, 'Expired CSRF token accepted.');
    $response = submit($base, array_replace($session, ['issued' => $now]), '192.0.2.3', $now, $root . '/fast', $fakeMail);
    check($response['status'] === 422, 'Instant submission accepted.');
    $response = submit(array_replace($base, ['website' => 'spam.example']), $session, '192.0.2.3', $now, $root . '/bot', $fakeMail);
    check($response['status'] === 200 && count($deliveries) === 1, 'Honeypot reached the mail transport.');

    check(claimRateSlot($root . '/rate', '192.0.2.10', $now) === 0, 'First rate slot rejected.');
    check(claimRateSlot($root . '/rate', '192.0.2.10', $now + 1) === 29, 'Cooldown did not apply.');
    for ($i = 1; $i <= 4; $i++) {
        check(claimRateSlot($root . '/rate', '192.0.2.10', $now + $i * 31) === 0, 'Valid later slot rejected.');
    }
    check(claimRateSlot($root . '/rate', '192.0.2.10', $now + 155) > 0, 'Hourly limit did not apply.');
    check(claimRateSlot($root . '/rate', '192.0.2.11', $now + 155) === 0, 'Another visitor was incorrectly rate-limited.');
    check(claimRateSlot($root . '/rate', '192.0.2.10', $now + 3601) === 0, 'Expired rate slot was not released.');

    $response = submit($base, $session, '192.0.2.20', $now, $root . '/mail-failed', static fn(array $mail): bool => false);
    check($response['status'] === 503 && !$response['body']['ok'], 'Failed mail transport was reported as success.');
    file_put_contents($root . '/blocked', 'not a directory');
    $failedClosed = false;
    try {
        submit($base, $session, '192.0.2.21', $now, $root . '/blocked', $fakeMail);
    } catch (RuntimeException $error) {
        $failedClosed = true;
    }
    check($failedClosed && count($deliveries) === 1, 'Unavailable rate storage allowed a message through.');
    echo 'Passed ' . $checks . " inquiry checks; no email was sent.\n";
} finally {
    foreach (glob($root . '/*/limits.json') as $file) {
        unlink($file);
    }
    foreach (glob($root . '/*') as $file) {
        is_dir($file) ? rmdir($file) : unlink($file);
    }
    rmdir($root);
}
