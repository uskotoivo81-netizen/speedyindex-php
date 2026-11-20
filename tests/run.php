<?php
// Dependency-free tests with a fake transport (no network, no API key needed).
declare(strict_types=1);
spl_autoload_register(function (string $c): void {
    $f = __DIR__ . '/../src/' . str_replace('SpeedyIndex\\', '', $c) . '.php';
    if (is_file($f)) require $f;
});
use SpeedyIndex\{Client, SpeedyIndexException, Webhook};

$fails = 0;
function ok(bool $c, string $name): void { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $name . PHP_EOL; if (!$c) $fails++; }

$calls = [];
$responses = [];
$fake = function (string $m, string $u, array $h, ?string $b) use (&$calls, &$responses): array {
    $calls[] = compact('m', 'u', 'h', 'b');
    return array_shift($responses) ?? ['status' => 200, 'headers' => [], 'body' => '{"code":0}'];
};
$si = new Client('KEY', transport: $fake);

$responses[] = ['status' => 200, 'headers' => [], 'body' => '{"code":0,"balance":{"indexer":5,"checker":7}}'];
ok($si->balance() === ['indexer' => 5, 'checker' => 7], 'balance');
ok($calls[0]['h']['Authorization'] === 'KEY', 'Authorization header is the bare key (no Bearer)');

$responses[] = ['status' => 200, 'headers' => [], 'body' => '{"code":0,"task_id":"t1"}'];
$si->createTask(["https://a.com/1", " https://a.com/1 ", "#c", "https://a.com/2"], 'google', 'indexer');
$body = json_decode(end($calls)['b'], true);
ok($body['urls'] === ['https://a.com/1', 'https://a.com/2'], 'urls trimmed and de-duplicated');
ok(($body['pay_per_indexed'] ?? null) === true, 'google indexer uses pay_per_indexed');
ok(str_ends_with(end($calls)['u'], '/v2/task/google/indexer/create'), 'create path');

try { $si->createTask(['https://a.com'], 'bing', 'indexer'); ok(false, 'bing indexer rejected'); } catch (\InvalidArgumentException) { ok(true, 'bing indexer rejected'); }

$responses[] = ['status' => 200, 'headers' => [], 'body' => '{"code":1,"error":"not_found"}'];
try { $si->fullReport('x'); ok(false, 'api code != 0 throws'); } catch (SpeedyIndexException $e) { ok($e->apiCode === 1, 'api code != 0 throws'); }

$responses[] = ['status' => 429, 'headers' => ['retry-after' => '12'], 'body' => '{"code":4,"error":"rate_limited"}'];
try { $si->rank->limits(); ok(false, '429 throws'); } catch (SpeedyIndexException $e) { ok($e->status === 429 && $e->retryAfter === 12, '429 carries Retry-After'); }

$responses[] = ['status' => 200, 'headers' => [], 'body' => '{"code":0,"items":[{"id":1},{"id":2}],"next_cursor":"c2"}'];
$responses[] = ['status' => 200, 'headers' => [], 'body' => '{"code":0,"items":[{"id":3}]}'];
$ids = array_map(fn ($p) => $p['id'], iterator_to_array($si->rank->projects(), false));
ok($ids === [1, 2, 3], 'cursor pagination');
ok(str_contains(end($calls)['u'], 'cursor=c2'), 'cursor passed on next page');

$sig = Webhook::sign('{"a":1}', 's');
ok(Webhook::verify('{"a":1}', "sha256=bad, {$sig}", 's'), 'webhook signature (rotation)');
ok(!Webhook::verify('{"a":1}', $sig, 'other'), 'webhook wrong secret');

echo $fails ? "{$fails} FAILED\n" : "ALL TESTS PASSED\n";
exit($fails ? 1 : 0);
