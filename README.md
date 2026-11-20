# speedyindex/client — PHP client for the SpeedyIndex API

PHP client for [SpeedyIndex](https://en.speedyindex.com): Google, Bing and Yandex index checks, link indexing, backlink checks, the Google Rank Tracker API and webhook signatures. It covers the [SpeedyIndex API](https://en.speedyindex.com/api.php) v2.

- PHP 8.1+, ext-curl, no other dependencies
- Index checks for Google, Bing and Yandex, with indexed and unindexed URL reports
- Link indexing for Google and Yandex: tasks, single URLs, drip feed
- Backlink checks on donor pages
- Rank Tracker: projects, keywords, checks, positions, trend, export, webhooks

The same client exists for Node.js ([`speedyindex` on npm](https://www.npmjs.com/package/speedyindex), [`@speedyindex/client` on JSR](https://jsr.io/@speedyindex/client)) and Python ([`speedyindex` on PyPI](https://pypi.org/project/speedyindex/)).

## Install

```bash
composer require speedyindex/client
```

## API key

Copy the key from the API section of your SpeedyIndex dashboard (https://app.speedyindex.com/cabinet/api) and pass it to the constructor, or set `SPEEDYINDEX_API_KEY`. Keep it in environment variables or a secret store, not in code.

## Check whether URLs are indexed in Google

```php
use SpeedyIndex\Client;

$si = new Client(); // reads SPEEDYINDEX_API_KEY

$task = $si->createTask([
    'https://example.com/page-1',
    'https://example.com/page-2',
], engine: 'google', taskType: 'checker');

// later
$status = $si->taskStatus([$task['task_id']], engine: 'google', taskType: 'checker');
$report = $si->fullReport($task['task_id'], engine: 'google', taskType: 'checker');
// $report['indexed_links'], $report['unindexed_links']
```

Bing and Yandex work the same way with `engine: 'bing'` or `engine: 'yandex'`.

## Submit URLs for indexing

```php
$task = $si->createTask($urls, engine: 'google', taskType: 'indexer');   // pay per indexed: URLs not indexed at the day-7 check are refunded
$task = $si->createTask($urls, engine: 'google', taskType: 'indexer', dripFeedDays: 7);
$one  = $si->indexUrl('https://example.com/new-post', engine: 'google');
```

## Backlink checks

```php
$task = $si->createBacklinkCheck($donorPages, ['example.com']);
$page = $si->backlinkReport($task['task_id'], page: 0, limit: 50);
```

## Rank Tracker

```php
$project = $si->rank->createProject('example.com', [['location' => 'United States', 'gl' => 'us']], [
    'keywords' => ['google index checker', 'bulk index checker'],
    'depth' => 30,
]);
$cost  = $si->rank->cost($project['project']['id']);
$check = $si->rank->runCheck($project['project']['id']);
$done  = $si->rank->wait($check['id']);

foreach ($si->rank->positions($project['project']['id']) as $row) {
    // read $row['status'], $row['unknown'] and $row['approx'] before treating a row as a drop
}
```

## Webhooks

```php
use SpeedyIndex\Webhook;

$event = Webhook::parse(file_get_contents('php://input'), $_SERVER['HTTP_X_SPEEDYINDEX_SIGNATURE'] ?? null, getenv('SPEEDYINDEX_WEBHOOK_SECRET'));
```

## Errors

API and HTTP errors throw `SpeedyIndex\SpeedyIndexException` with `apiCode`, `status`, `error`, `payload` and `retryAfter` (seconds, on HTTP 429).

## Tests

```bash
composer test
```

## License

MIT
