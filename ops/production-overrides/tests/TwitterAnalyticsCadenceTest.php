<?php
// Standalone scheduler contract: no application boot, queues, database or X requests.
namespace Illuminate\Database\Eloquent {
    class Builder {
        public function __construct(private array $items = []) {}
        public function where(...$args): self { return $this; }
        public function get(): self { return $this; }
        public function filter(callable $fn): self { return new self(array_values(array_filter($this->items, $fn))); }
        public function each(callable $fn): self { foreach ($this->items as $item) { $fn($item); } return $this; }
    }
}
namespace Inovector\Mixpost\Models {
    class Workspace {
        public string $name = 'Fixture';
        public function valid(): bool { return true; }
        public function execute(callable $fn): void { $fn(); }
    }
    class Account {
        public static array $items = [];
        public function __construct(public bool $authorized, public bool $active) {}
        public function isAuthorized(): bool { return $this->authorized; }
        public function isServiceActive(): bool { return $this->active; }
        public static function query(): \Illuminate\Database\Eloquent\Builder { return new \Illuminate\Database\Eloquent\Builder(self::$items); }
    }
}
namespace Inovector\Mixpost\Support {
    class MediaFilesystem { public static function isCloudDisk($disk): bool { return false; } }
}
namespace Inovector\Mixpost\Jobs {
    class WorkspaceArtisanJob { public function __construct(...$args) {} }
}
namespace Inovector\Mixpost\SocialProviders\Twitter\Jobs {
    class ImportTwitterPostsJob {
        public static array $dispatched = [];
        public static function dispatch($account, $options): void { self::$dispatched[] = [$account, $options]; }
    }
}
namespace Inovector\Mixpost {
    class Util { public static function config($key): string { return 'local'; } }
}
namespace {
    use Inovector\Mixpost\Models\{Account, Workspace};
    use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;

    class CadenceClock {
        public static string $instant = '2026-11-16T00:05:00Z';
        public function __construct(private \DateTimeImmutable $date) {}
        public function copy(): self { return clone $this; }
        public function subDays(int $days): self { $this->date = $this->date->modify("-$days days"); return $this; }
        public function startOfDay(): self { $this->date = $this->date->setTime(0, 0); return $this; }
        public function toIso8601ZuluString(): string { return $this->date->format('Y-m-d\TH:i:s\Z'); }
        public function __get(string $key): int { return $this->date->getTimestamp(); }
    }
    function now($timezone = null): CadenceClock { return new CadenceClock(new \DateTimeImmutable(CadenceClock::$instant)); }
    class CadenceEvent {
        public string $label = '';
        public array $frequency = [];
        public ?\Closure $condition = null;
        public function __construct(public mixed $callback = null) {}
        public function name(string $name): self { $this->label = $name; return $this; }
        public function when(\Closure $fn): self { $this->condition = $fn; return $this; }
        public function __call(string $method, array $args): self {
            if ($method !== 'withoutOverlapping') { $this->frequency = [$method, $args]; }
            return $this;
        }
    }
    class CadenceSchedule {
        public array $events = [];
        public function call($callback): CadenceEvent { return $this->events[] = new CadenceEvent($callback); }
        public function command(...$args): CadenceEvent { return $this->events[] = new CadenceEvent(); }
        public function job(...$args): CadenceEvent { return $this->events[] = new CadenceEvent(); }
        public function event(string $suffix): CadenceEvent {
            $matches = array_values(array_filter($this->events, fn ($event) => str_ends_with($event->label, $suffix)));
            if (count($matches) !== 1) { throw new \RuntimeException("Expected one event: $suffix"); }
            return $matches[0];
        }
    }
    function cadenceCheck(bool $ok, string $message): void {
        if (!$ok) { throw new \RuntimeException($message); }
        echo "PASS $message\n";
    }
    require getenv('MIXPOST_SCHEDULE_PATH') ?: dirname(__DIR__).'/Schedule.php';
    $schedule = new CadenceSchedule();
    \Inovector\Mixpost\Schedule::register($schedule, new \Illuminate\Database\Eloquent\Builder([new Workspace()]));
    $recent = $schedule->event('twitter-post-analytics-every-3-hours-0-3-days');
    $daily = $schedule->event('twitter-post-analytics-daily-3-7-days');
    cadenceCheck($recent->frequency === ['cron', ['5 */3 * * *']], 'recent posts refresh eight times per UTC day at minute five');
    cadenceCheck($daily->frequency === ['dailyAt', ['00:05']], '3–7-day posts retain the daily refresh');
    $eligible = new Account(true, true);
    Account::$items = [$eligible, new Account(false, true), new Account(true, false)];
    $windows = [
        [$recent, ['timeline_start_time' => '2026-11-13T00:05:00Z']],
        [$daily, ['timeline_start_time' => '2026-11-09T00:05:00Z', 'timeline_end_time' => '2026-11-13T00:05:00Z']],
        [$schedule->event('twitter-post-analytics-every-2-days-8-30-days'), ['timeline_start_time' => '2026-10-17T00:05:00Z', 'timeline_end_time' => '2026-11-09T00:05:00Z']],
        [$schedule->event('twitter-post-analytics-weekly-30-90-days'), ['timeline_start_time' => '2026-08-18T00:05:00Z', 'timeline_end_time' => '2026-10-17T00:05:00Z']],
        [$schedule->event('twitter-post-analytics-every-30-days-older-than-90-days'), ['timeline_end_time' => '2026-08-18T00:05:00Z']],
    ];
    foreach ($windows as [$event, $expected]) {
        ImportTwitterPostsJob::$dispatched = [];
        ($event->callback)();
        cadenceCheck(ImportTwitterPostsJob::$dispatched === [[$eligible, $expected]], 'exact rolling window and eligible accounts: '.$event->label);
    }
    cadenceCheck($schedule->event('twitter-post-analytics-every-2-days-8-30-days')->frequency === ['dailyAt', ['00:20']], '7–30-day dispatch time preserved');
    cadenceCheck($schedule->event('twitter-post-analytics-weekly-30-90-days')->frequency === ['weeklyOn', [1, '00:40']], '30–90-day weekly cadence preserved');
    cadenceCheck($schedule->event('twitter-post-analytics-every-30-days-older-than-90-days')->frequency === ['dailyAt', ['01:00']], 'older history dispatch time preserved');
    foreach ([2 => 'twitter-post-analytics-every-2-days-8-30-days', 30 => 'twitter-post-analytics-every-30-days-older-than-90-days'] as $interval => $name) {
        $condition = $schedule->event($name)->condition;
        CadenceClock::$instant = '2026-11-16T12:00:00Z';
        $day = intdiv((new \DateTimeImmutable(CadenceClock::$instant))->getTimestamp(), 86400);
        cadenceCheck($condition() === ($day % $interval === 0), "$interval-day UTC interval predicate preserved");
    }
    CadenceClock::$instant = '2026-11-16T03:05:00Z';
    ImportTwitterPostsJob::$dispatched = [];
    ($recent->callback)();
    cadenceCheck(ImportTwitterPostsJob::$dispatched[0][1] === ['timeline_start_time' => '2026-11-13T03:05:00Z'], 'recent window moves with dispatch time rather than midnight');
    cadenceCheck($schedule->event('low-cost-post-analytics-30min')->frequency === ['everyThirtyMinutes', []], 'other-provider analytics cadence preserved');
    cadenceCheck(count(array_filter($schedule->events, fn ($event) => str_contains($event->label, 'twitter-post-analytics-'))) === 5, 'exactly five X windows; obsolete overlapping daily event removed');
}
