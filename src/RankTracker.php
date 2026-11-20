<?php

declare(strict_types=1);

namespace SpeedyIndex;

/** Google Rank Tracker API (/v2/rank-tracker). Use through $client->rank. */
class RankTracker
{
    private const RT = '/v2/rank-tracker';

    public function __construct(private Client $c)
    {
    }

    private function get(string $path, array $params = []): array
    {
        return $this->c->request('GET', self::RT . $path, null, $params);
    }

    private function send(string $method, string $path, array $body = []): array
    {
        return $this->c->request($method, self::RT . $path, $body);
    }

    /** Iterate over every item of a cursor-paginated list. */
    public function paginate(string $path, array $params = []): \Generator
    {
        $cursor = null;
        do {
            $page = $this->get($path, $params + ['cursor' => $cursor]);
            foreach ($page['items'] ?? [] as $item) {
                yield $item;
            }
            $cursor = $page['next_cursor'] ?? null;
        } while ($cursor);
    }

    public function limits(): array { return $this->get('/limits'); }
    public function locations(string $q, ?string $gl = null, ?int $limit = null): array { return $this->get('/locations', ['q' => $q, 'gl' => $gl, 'limit' => $limit])['locations'] ?? []; }
    public function groups(): array { return $this->get('/groups')['items'] ?? []; }
    public function usage(array $params = []): array { return $this->get('/usage', $params); }

    public function projects(array $params = []): \Generator { return $this->paginate('/projects', $params); }

    /** @param array<int, array{location:string, gl:string, device?:string}> $regions; $extra: name, depth, competitors, keywords, schedule, group, tags, webhook_url */
    public function createProject(string $domain, array $regions, array $extra = []): array
    {
        return $this->send('POST', '/projects', ['domain' => $domain, 'regions' => $regions] + $extra);
    }

    public function project(string $projectId): array { return $this->get("/projects/{$projectId}"); }
    public function updateProject(string $projectId, array $fields): array { return $this->send('PATCH', "/projects/{$projectId}", $fields)['project'] ?? []; }
    public function deleteProject(string $projectId): array { return $this->c->request('DELETE', self::RT . "/projects/{$projectId}"); }
    public function summary(array $projectIds): array { return $this->send('POST', '/projects/summary', ['project_ids' => $projectIds]); }

    public function addRegion(string $projectId, string $location, string $gl, ?string $device = null): array
    {
        return $this->send('POST', "/projects/{$projectId}/regions", array_filter(['location' => $location, 'gl' => $gl, 'device' => $device]));
    }

    public function removeRegion(string $projectId, string $regionId): array { return $this->c->request('DELETE', self::RT . "/projects/{$projectId}/regions/{$regionId}"); }

    public function keywords(string $projectId, array $params = []): \Generator { return $this->paginate("/projects/{$projectId}/keywords", $params); }

    /** @param array<int, string|array{q:string, tags?:string[], target_url?:string}> $keywords */
    public function addKeywords(string $projectId, array $keywords, ?array $tags = null): array
    {
        return $this->send('POST', "/projects/{$projectId}/keywords", array_filter(['keywords' => $keywords, 'tags' => $tags], static fn ($v) => $v !== null));
    }

    public function deleteKeywords(string $projectId, array $ids): mixed { return $this->send('POST', "/projects/{$projectId}/keywords/delete", ['ids' => $ids])['deleted'] ?? null; }
    public function keywordHistory(string $projectId, string $keywordId, ?string $regionId = null, ?int $limit = null): array { return $this->get("/projects/{$projectId}/keywords/{$keywordId}/history", ['region_id' => $regionId, 'limit' => $limit]); }
    public function tags(string $projectId): array { return $this->get("/projects/{$projectId}/tags")['items'] ?? []; }

    /** Minimum and maximum token cost of a check. */
    public function cost(string $projectId, ?string $regionId = null): array { return $this->get("/projects/{$projectId}/cost", ['region_id' => $regionId]); }
    /** Start a check; holds tokens up to the maximum cost. */
    public function runCheck(string $projectId, ?string $regionId = null): array { return $this->send('POST', "/projects/{$projectId}/checks", $regionId ? ['region_id' => $regionId] : [])['check'] ?? []; }
    public function checks(string $projectId, array $params = []): \Generator { return $this->paginate("/projects/{$projectId}/checks", $params); }
    public function check(string $checkId): array { return $this->get("/checks/{$checkId}")['check'] ?? []; }
    public function cancelCheck(string $checkId): array { return $this->send('POST', "/checks/{$checkId}/cancel")['check'] ?? []; }

    /** Poll a check until it is no longer queued or running (15-60 s between polls is recommended). */
    public function wait(string $checkId, int $intervalSeconds = 30, ?int $timeoutSeconds = null): array
    {
        $start = time();
        while (true) {
            $c = $this->check($checkId);
            if (!in_array($c['status'] ?? null, ['queued', 'running'], true)) {
                return $c;
            }
            if ($timeoutSeconds !== null && time() - $start > $timeoutSeconds) {
                return $c;
            }
            sleep($intervalSeconds);
        }
    }

    /** Position rows of a check. Read 'status', 'unknown' and 'approx' before treating a row as a drop. */
    public function positions(string $projectId, array $params = []): \Generator { return $this->paginate("/projects/{$projectId}/positions", $params); }
    public function trend(string $projectId, ?string $regionId = null): array { return $this->get("/projects/{$projectId}/trend", ['region_id' => $regionId]); }
    public function keywordInCheck(string $checkId, string $keywordId, ?string $regionId = null): array { return $this->get("/checks/{$checkId}/keywords/{$keywordId}", ['region_id' => $regionId]); }

    /** Export as text (CSV with BOM, or JSON). */
    public function export(string $projectId, string $format = 'csv', array $params = []): string
    {
        return $this->c->raw('GET', self::RT . "/projects/{$projectId}/export", null, ['format' => $format] + $params);
    }

    public function webhook(): array { return $this->get('/webhook')['webhook'] ?? []; }
    public function setWebhook(string $url, ?array $events = null): array { return $this->send('PUT', '/webhook', $events ? ['url' => $url, 'events' => $events] : ['url' => $url]); }
    public function removeWebhook(): array { return $this->c->request('DELETE', self::RT . '/webhook')['webhook'] ?? []; }
    public function rotateWebhookSecret(): array { return $this->send('POST', '/webhook/secret'); }
}
