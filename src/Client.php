<?php

declare(strict_types=1);

namespace SpeedyIndex;

/**
 * SpeedyIndex API v2 client: indexing, Google/Bing/Yandex index checks, backlink checks and the Rank Tracker API.
 *
 * $si = new Client(getenv('SPEEDYINDEX_API_KEY'));
 * $task = $si->createTask(['https://example.com/a'], engine: 'google', taskType: 'checker');
 */
class Client
{
    public const DEFAULT_BASE_URL = 'https://api.speedyindex.com';
    public const ENGINES = ['google', 'yandex', 'bing'];
    public const TASK_TYPES = ['indexer', 'checker'];
    public const MAX_URLS = 10000;
    public const MAX_IDS = 1000;

    private string $apiKey;
    private string $baseUrl;
    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{status:int, headers:array<string,string>, body:string} */
    private $transport;
    public readonly RankTracker $rank;

    public function __construct(?string $apiKey = null, string $baseUrl = self::DEFAULT_BASE_URL, private int $timeout = 60, ?callable $transport = null)
    {
        $apiKey = $apiKey ?: (getenv('SPEEDYINDEX_API_KEY') ?: null);
        if (!$apiKey) {
            throw new SpeedyIndexException('No API key: pass it to the constructor or set SPEEDYINDEX_API_KEY');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport;
        $this->rank = new RankTracker($this);
    }

    /** @internal Raw HTTP call; returns the response body. Throws on HTTP errors. */
    public function raw(string $method, string $path, ?array $body = null, array $params = []): string
    {
        $url = $this->baseUrl . $path;
        $params = array_filter($params, static fn ($v) => $v !== null);
        if ($params) {
            $url .= '?' . http_build_query(array_map(static fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $params));
        }
        $headers = ['Authorization' => $this->apiKey, 'Accept' => 'application/json', 'User-Agent' => 'speedyindex-php'];
        $json = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $res = ($this->transport ?? [$this, 'curl'])($method, $url, $headers, $json);
        $status = (int) $res['status'];
        if ($status < 200 || $status >= 300) {
            $payload = json_decode($res['body'], true);
            $error = is_array($payload) ? ($payload['error'] ?? null) : null;
            $message = is_array($payload) ? ($payload['message'] ?? null) : null;
            $ra = $res['headers']['retry-after'] ?? null;
            throw new SpeedyIndexException(
                "HTTP {$status} for {$path}" . ($error ? ": {$error}" : '') . ($message ? " - {$message}" : ''),
                is_array($payload) ? ($payload['code'] ?? $status) : $status,
                $status,
                $error,
                $payload ?? $res['body'],
                $ra !== null ? (int) $ra : null,
            );
        }
        return $res['body'];
    }

    /** @internal JSON call; throws when the API returns a non-zero code. */
    public function request(string $method, string $path, ?array $body = null, array $params = []): array
    {
        $text = $this->raw($method, $path, $body, $params);
        $payload = json_decode($text, true);
        if (!is_array($payload)) {
            throw new SpeedyIndexException("Non-JSON response for {$path}", payload: $text);
        }
        if (isset($payload['code']) && $payload['code'] !== 0) {
            throw new SpeedyIndexException(
                "API error {$payload['code']}: " . ($payload['error'] ?? $payload['message'] ?? 'see payload'),
                $payload['code'],
                error: $payload['error'] ?? null,
                payload: $payload,
            );
        }
        return $payload;
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new SpeedyIndexException("Request to SpeedyIndex failed: {$err}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $out];
    }

    private static function checkEngine(string $engine, ?string $taskType = null): void
    {
        if (!in_array($engine, self::ENGINES, true)) {
            throw new \InvalidArgumentException('engine must be one of ' . implode(', ', self::ENGINES));
        }
        if ($taskType !== null) {
            if (!in_array($taskType, self::TASK_TYPES, true)) {
                throw new \InvalidArgumentException('taskType must be one of ' . implode(', ', self::TASK_TYPES));
            }
            if ($engine === 'bing' && $taskType !== 'checker') {
                throw new \InvalidArgumentException("Bing supports only the 'checker' task type");
            }
        }
    }

    /** Trim, drop empty and comment lines, de-duplicate. @param iterable<string> $urls */
    private static function cleanUrls(iterable $urls): array
    {
        $out = [];
        foreach ($urls as $u) {
            $u = trim((string) $u);
            if ($u !== '' && $u[0] !== '#' && !isset($out[$u])) {
                $out[$u] = true;
            }
        }
        return array_keys($out);
    }

    /** GET /v2/account — tokens, indexer and checker credits. */
    public function balance(): array
    {
        return $this->request('GET', '/v2/account')['balance'];
    }

    /**
     * POST /v2/task/{engine}/{type}/create. Google indexing always runs in pay-per-indexed mode
     * (URLs not indexed at the check on day 7 are refunded).
     */
    public function createTask(iterable $urls, string $engine = 'google', string $taskType = 'checker', ?string $title = null, ?int $dripFeedDays = null): array
    {
        self::checkEngine($engine, $taskType);
        $list = self::cleanUrls($urls);
        if (!$list) {
            throw new \InvalidArgumentException('urls is empty');
        }
        if (count($list) > self::MAX_URLS) {
            throw new \LengthException('max ' . self::MAX_URLS . ' URLs per task');
        }
        $body = ['urls' => $list];
        if ($title) {
            $body['title'] = $title;
        }
        if ($engine === 'google' && $taskType === 'indexer') {
            $body['pay_per_indexed'] = true;
        }
        if ($dripFeedDays) {
            if (!($engine === 'google' && $taskType === 'indexer')) {
                throw new \InvalidArgumentException('drip feed is available for google/indexer only');
            }
            if ($dripFeedDays < 2 || $dripFeedDays > 30) {
                throw new \OutOfRangeException('dripFeedDays must be 2-30');
            }
            if (count($list) < $dripFeedDays) {
                throw new \LengthException('number of URLs must be >= dripFeedDays');
            }
            $body['drip_feed'] = true;
            $body['drip_feed_days'] = $dripFeedDays;
        }
        return $this->request('POST', "/v2/task/{$engine}/{$taskType}/create", $body);
    }

    /** GET /v2/task/{engine}/{type}/list/{page} */
    public function listTasks(string $engine = 'google', string $taskType = 'indexer', int $page = 0, ?string $filter = null): array
    {
        self::checkEngine($engine, $taskType);
        return $this->request('GET', "/v2/task/{$engine}/{$taskType}/list/{$page}", null, ['filter' => $filter]);
    }

    /** POST /v2/task/{engine}/{type}/status @param string[] $taskIds */
    public function taskStatus(array $taskIds, string $engine = 'google', string $taskType = 'checker'): array
    {
        self::checkEngine($engine, $taskType);
        if (count($taskIds) > self::MAX_IDS) {
            throw new \LengthException('max ' . self::MAX_IDS . ' task IDs');
        }
        return $this->request('POST', "/v2/task/{$engine}/{$taskType}/status", ['task_ids' => array_values($taskIds)]);
    }

    /** POST /v2/task/{engine}/{type}/report — indexed_links / unindexed_links as URL lists. */
    public function report(string $taskId, string $engine = 'google', string $taskType = 'checker'): array
    {
        self::checkEngine($engine, $taskType);
        return $this->request('POST', "/v2/task/{$engine}/{$taskType}/report", ['task_id' => $taskId])['result'] ?? [];
    }

    /** POST /v2/task/{engine}/{type}/fullreport — indexed {url,title}, unindexed {url,error_code}. */
    public function fullReport(string $taskId, string $engine = 'google', string $taskType = 'checker'): array
    {
        self::checkEngine($engine, $taskType);
        return $this->request('POST', "/v2/task/{$engine}/{$taskType}/fullreport", ['task_id' => $taskId])['result'] ?? [];
    }

    /** POST /v2/{engine}/url — submit one URL for indexing (google or yandex). */
    public function indexUrl(string $url, string $engine = 'google'): array
    {
        if (!in_array($engine, ['google', 'yandex'], true)) {
            throw new \InvalidArgumentException('single-link submission supports google and yandex');
        }
        $body = ['url' => $url];
        if ($engine === 'google') {
            $body['pay_per_indexed'] = true;
        }
        return $this->request('POST', "/v2/{$engine}/url", $body);
    }

    /** POST /v2/task/backlink/checker/create @param string[] $targetDomains */
    public function createBacklinkCheck(iterable $urls, array $targetDomains, ?string $title = null): array
    {
        $list = self::cleanUrls($urls);
        if (!$list) {
            throw new \InvalidArgumentException('urls is empty');
        }
        if (count($list) > self::MAX_URLS) {
            throw new \LengthException('max ' . self::MAX_URLS . ' URLs per task');
        }
        $body = ['urls' => $list, 'target_domains' => array_values($targetDomains)];
        if ($title) {
            $body['title'] = $title;
        }
        return $this->request('POST', '/v2/task/backlink/checker/create', $body);
    }

    /** POST /v2/task/backlink/checker/status @param string|string[] $taskIds */
    public function backlinkStatus(string|array $taskIds): mixed
    {
        $body = is_string($taskIds) ? ['task_id' => $taskIds] : ['task_ids' => array_values($taskIds)];
        return $this->request('POST', '/v2/task/backlink/checker/status', $body)['result'] ?? null;
    }

    /** POST /v2/task/backlink/checker/report */
    public function backlinkReport(string $taskId, int $page = 0, int $limit = 50): array
    {
        return $this->request('POST', '/v2/task/backlink/checker/report', ['task_id' => $taskId, 'page' => $page, 'limit' => $limit]);
    }
}
