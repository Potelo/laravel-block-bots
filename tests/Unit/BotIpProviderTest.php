<?php

namespace Potelo\LaravelBlockBots\Tests\Unit;

use Carbon\Carbon;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Contracts\Client;
use Potelo\LaravelBlockBots\Contracts\Configuration;
use Potelo\LaravelBlockBots\Jobs\CheckIfBotIsReal;
use Potelo\LaravelBlockBots\Jobs\RefreshBotIpRanges;
use Potelo\LaravelBlockBots\Middleware\BlockBots;
use Potelo\LaravelBlockBots\Services\BotIpProvider;
use Potelo\LaravelBlockBots\Services\BotIpResolver;
use Potelo\LaravelBlockBots\Tests\TestCase;
use Predis\Cluster\RedisStrategy;

class BotIpProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config([
            'block-bots.log' => false,
            'block-bots.bot_ip_provider.bots.gptbot.defaults' => ['132.196.86.0/24'],
        ]);
    }

    private function feed(array $ranges): string
    {
        return json_encode(['prefixes' => array_map(function ($range) {
            return [strpos($range, ':') === false ? 'ipv4Prefix' : 'ipv6Prefix' => $range];
        }, $ranges)]);
    }

    private function http(array $responses)
    {
        $this->app->instance(HttpClient::class, new HttpClient([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));
    }

    private function request(string $ip, string $agent = 'GPTBot'): Request
    {
        $request = new Request();
        $request->server->set('REMOTE_ADDR', $ip);
        $request->headers->set('User-Agent', $agent);
        return $request;
    }

    private function allowed(string $ip, string $agent = 'GPTBot', int $limit = 0): bool
    {
        $response = (new BlockBots())->handle($this->request($ip, $agent), function () { return 'ok'; }, $limit);
        return $response === 'ok';
    }

    public function test_defaults_and_static_precedence_are_independent_of_order()
    {
        $provider = new BotIpProvider();
        $this->assertNotEmpty($provider->ranges('gptbot'));
        foreach ([['gptbot' => [], BotIpProvider::class], [BotIpProvider::class, 'gptbot' => []]] as $entries) {
            config(['block-bots.allowed_bots_by_ip' => $entries]);
            $this->assertSame([], (new BotIpResolver())->match('GPTBot/1.0'));
            $this->assertFalse($this->allowed('132.196.86.1'));
        }
    }

    public function test_configured_defaults_cover_only_the_window_before_the_first_refresh()
    {
        $provider = new BotIpProvider();
        config(['block-bots.bot_ip_provider.bots.gptbot' => ['url' => 'https://openai.com/gptbot.json']]);
        $this->assertTrue((new BotIpResolver())->manages('GPTBot'));
        $this->assertSame([], $provider->ranges('gptbot'));
        $this->assertFalse($this->allowed('132.196.86.1'));

        config(['block-bots.bot_ip_provider.bots.gptbot.defaults' => ['132.196.86.0/24']]);
        $this->assertTrue($this->allowed('132.196.86.1'));

        $this->http([new Response(200, [], $this->feed(['198.51.100.0/24']))]);
        $provider->queueRefresh('gptbot');
        (new RefreshBotIpRanges('gptbot', Redis::get($provider->key('gptbot') . ':lock')))->handle($provider);
        $this->assertFalse($this->allowed('132.196.86.1'));
        $this->assertTrue($this->allowed('198.51.100.1'));
    }

    public function test_removing_a_bot_from_config_stops_managing_it()
    {
        config(['block-bots.bot_ip_provider.bots' => []]);
        $this->assertSame([], (new BotIpProvider())->sources());
        $this->assertFalse((new BotIpResolver())->manages('GPTBot'));
    }

    public function test_refresh_replaces_defaults_and_ignores_old_verification_sets()
    {
        $provider = new BotIpProvider();
        Redis::sadd(config('block-bots.whitelist_key'), '192.0.2.1');
        Redis::sadd(config('block-bots.fake_bot_list_key'), '198.51.100.1');
        $this->http([new Response(200, [], $this->feed(['198.51.100.0/24']))]);
        $this->assertTrue($provider->queueRefresh('gptbot'));
        $token = Redis::get($provider->key('gptbot') . ':lock');
        (new RefreshBotIpRanges('gptbot', $token))->handle($provider);
        $this->assertSame(['198.51.100.0/24'], $provider->ranges('gptbot'));
        $this->assertFalse($this->allowed('192.0.2.1'));
        $this->assertTrue($this->allowed('198.51.100.1'));
        $this->assertFalse($provider->queueRefresh('gptbot'));
        $this->assertSame(-1, Redis::ttl($provider->key('gptbot')));
        Queue::assertNotPushed(CheckIfBotIsReal::class);
    }

    public function test_ipv6_uses_original_address_and_never_whitelists_prefix()
    {
        config(['block-bots.bot_ip_provider.bots.gptbot.defaults' => ['2001:db8::1/128']]);
        Redis::sadd(config('block-bots.whitelist_key'), '2001:db8::');
        $this->assertTrue($this->allowed('2001:db8::1'));
        $this->assertFalse($this->allowed('2001:db8::2'));
        $this->assertTrue($this->allowed('2001:db8::2', 'GPTBot', 100));
    }

    public function test_explicit_whitelist_applies_to_managed_bots_without_caching_the_prefix()
    {
        config([
            'block-bots.whitelist_ips' => ['192.0.2.0/24', '2001:db8::1/128'],
            'block-bots.allowed_bots_by_ip' => ['gptbot' => []],
        ]);
        Redis::sadd(config('block-bots.fake_bot_list_key'), '192.0.2.1');

        $this->assertTrue($this->allowed('192.0.2.1'));
        $this->assertTrue($this->allowed('2001:db8::1'));
        $this->assertFalse($this->allowed('2001:db8::2'));
        $this->assertFalse(Redis::sismember(config('block-bots.whitelist_key'), '2001:db8::'));
    }

    public function test_resolver_reads_only_the_selected_dynamic_snapshot()
    {
        $provider = new BotIpProvider();
        $resolver = new BotIpResolver();
        config(['block-bots.allowed_bots_by_ip' => [BotIpProvider::class, 'bot' => []]]);
        Redis::shouldReceive('get')->once()->with($provider->key('gptbot'))
            ->andReturn(json_encode(['ranges' => ['192.0.2.0/24']]));

        $this->assertNull($resolver->match('Mozilla'));
        $this->assertSame([], $resolver->match('OtherBot'));
        $this->assertTrue($resolver->manages('GPTBot'));
        $this->assertSame(['192.0.2.0/24'], $resolver->match('GPTBot'));
    }

    public function test_resolver_lists_configured_bots_without_reading_snapshots()
    {
        config([
            'block-bots.allowed_bots_by_ip' => [BotIpProvider::class, 'StaticBot' => ['192.0.2.0/24'], 'gptbot' => []],
            'block-bots.bot_ip_provider.bots' => ['gptbot' => [], 'OtherFeedBot' => []],
        ]);
        Redis::shouldReceive('get')->never();

        $bots = (new BotIpResolver())->bots();
        sort($bots);
        $this->assertSame(['gptbot', 'otherfeedbot', 'staticbot'], $bots);

        config(['block-bots.allowed_bots_by_ip' => ['staticbot' => []]]);
        $this->assertSame(['staticbot'], (new BotIpResolver())->bots());
    }

    public function test_snapshot_and_lease_use_the_same_redis_cluster_slot()
    {
        $provider = new BotIpProvider();
        $strategy = new RedisStrategy();
        $slots = [];
        foreach (array_keys($provider->sources()) as $bot) {
            $key = $provider->key($bot);
            $slots[] = $strategy->getSlotByKey($key);
            $this->assertSame($strategy->getSlotByKey($key), $strategy->getSlotByKey($key . ':lock'));
        }

        $this->assertCount(count($slots), array_unique($slots));
    }

    public function test_new_bot_is_eligible_and_due_immediately()
    {
        config(['block-bots.bot_ip_provider.bots.newbot' => [
            'url' => 'https://example.com/ips.json', 'defaults' => ['192.0.2.0/24'],
        ]]);
        $this->assertTrue($this->allowed('192.0.2.1', 'NewBot'));
        $this->artisan('block-bots:refresh-ips', ['--due' => true])->assertExitCode(0);
        Queue::assertPushed(RefreshBotIpRanges::class, function ($job) { return $job->bot === 'newbot'; });
        $this->assertFalse((new BotIpProvider())->queueRefresh('newbot', true));
    }

    public function test_invalid_feeds_are_rejected_atomically()
    {
        $provider = new BotIpProvider();
        foreach (['{}', '{', '{"prefixes":[]}', $this->feed(['192.0.2.0/24', 'invalid/32']),
            '{"prefixes":[{"ipv4Prefix":"2001:db8::/32"}]}', $this->feed(['192.0.2.0/33'])] as $feed) {
            try {
                $provider->parse($feed);
                $this->fail('Invalid feed accepted');
            } catch (\UnexpectedValueException $expected) {
                $this->assertNotEmpty($expected->getMessage());
            }
        }
        $this->assertSame(['192.0.2.0/24', '2001:db8::/32'], $provider->parse($this->feed(['192.0.2.0/24', '2001:db8::/32'])));
    }

    public function test_failure_retries_with_increasing_delays_and_keeps_snapshot()
    {
        $provider = new BotIpProvider();
        Redis::set($provider->key('gptbot'), json_encode(['ranges' => ['192.0.2.0/24'], 'updated_at' => 1]));
        $this->http(array_fill(0, 6, new Response(200, [], '{"prefixes":[]}')));
        $provider->queueRefresh('gptbot');
        $token = Redis::get($provider->key('gptbot') . ':lock');
        $now = Carbon::now();
        Carbon::setTestNow($now);
        try {
            foreach (RefreshBotIpRanges::RETRY_DELAYS as $attempt => $seconds) {
                (new RefreshBotIpRanges('gptbot', $token, $attempt))->handle($provider);
                Queue::assertPushed(RefreshBotIpRanges::class, function ($job) use ($attempt, $seconds, $now) {
                    return $job->attempt === $attempt + 1 && $job->delay->timestamp === $now->timestamp + $seconds;
                });
                $this->assertSame(['192.0.2.0/24'], $provider->ranges('gptbot'));
            }
            (new RefreshBotIpRanges('gptbot', $token, 5))->handle($provider);
            $this->assertFalse($provider->ownsLease('gptbot', $token));
            $this->assertTrue($provider->queueRefresh('gptbot'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_old_verification_job_cannot_repopulate_global_sets()
    {
        $request = $this->request('192.0.2.1');
        $job = new CheckIfBotIsReal(new Client($request), ['gptbot' => '*'], new Configuration());
        $job->handle();
        $this->assertEmpty(Redis::smembers(config('block-bots.whitelist_key')));
        $this->assertEmpty(Redis::smembers(config('block-bots.fake_bot_list_key')));
    }

    public function test_scheduler_registers_daily_due_check()
    {
        $commands = array_map(function ($event) { return $event->command; }, $this->app->make(Schedule::class)->events());
        $this->assertNotEmpty(array_filter($commands, function ($command) {
            return strpos($command, 'block-bots:refresh-ips --due') !== false;
        }));
    }
    public function test_snapshot_becomes_due_after_fifteen_days()
    {
        $provider = new BotIpProvider();
        $now = Carbon::now();
        Redis::set($provider->key('gptbot'), json_encode([
            'ranges' => ['192.0.2.0/24'], 'updated_at' => $now->timestamp,
        ]));
        try {
            Carbon::setTestNow($now->copy()->addDays(15)->subSecond());
            $this->assertFalse($provider->queueRefresh('gptbot'));
            Carbon::setTestNow($now->copy()->addDays(15));
            $this->assertTrue($provider->queueRefresh('gptbot'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_due_check_skips_a_snapshot_refreshed_before_acquiring_the_lease()
    {
        $provider = new class extends BotIpProvider {
            private $firstRead = true;

            public function snapshot(string $bot): ?array
            {
                $snapshot = parent::snapshot($bot);
                if ($this->firstRead) {
                    $this->firstRead = false;
                    Redis::set($this->key($bot), json_encode([
                        'ranges' => ['192.0.2.0/24'],
                        'updated_at' => Carbon::now()->timestamp,
                    ]));
                }

                return $snapshot;
            }
        };

        $this->assertFalse($provider->queueRefresh('gptbot'));
        Queue::assertNotPushed(RefreshBotIpRanges::class);
        $this->assertSame(0, Redis::exists($provider->key('gptbot') . ':lock'));

        $this->assertTrue($provider->queueRefresh('gptbot', true));
        Queue::assertPushed(RefreshBotIpRanges::class, 1);
    }

    public function test_expired_cycle_cannot_overwrite_or_unlock_its_successor()
    {
        $provider = new BotIpProvider();
        Redis::set($provider->key('gptbot') . ':lock', 'new-cycle');
        $this->http([new Response(200, [], $this->feed(['192.0.2.0/24']))]);
        $provider->refresh('gptbot', 'old-cycle');
        $provider->release('gptbot', 'old-cycle');
        $this->assertNull($provider->snapshot('gptbot'));
        $this->assertTrue($provider->ownsLease('gptbot', 'new-cycle'));
    }

    public function test_http_failure_keeps_defaults_and_queues_retry()
    {
        $provider = new BotIpProvider();
        $defaults = $provider->ranges('gptbot');
        $this->http([new Response(503)]);
        $provider->queueRefresh('gptbot');
        $token = Redis::get($provider->key('gptbot') . ':lock');
        (new RefreshBotIpRanges('gptbot', $token))->handle($provider);
        $this->assertSame($defaults, $provider->ranges('gptbot'));
        Queue::assertPushed(RefreshBotIpRanges::class, function ($job) { return $job->attempt === 1; });
    }

    public function test_schedule_can_be_disabled()
    {
        config(['block-bots.bot_ip_provider.schedule_enabled' => false]);
        $this->app->forgetInstance(Schedule::class);
        $this->assertEmpty($this->app->make(Schedule::class)->events());
    }

}
