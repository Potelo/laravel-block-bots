<?php

namespace Potelo\LaravelBlockBots\Tests\Unit;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Potelo\LaravelBlockBots\Contracts\Configuration;
use Potelo\LaravelBlockBots\Jobs\VerifyGuestCrawler;
use Potelo\LaravelBlockBots\Services\GuestQuotaExemptions;
use Potelo\LaravelBlockBots\Tests\TestCase;

class GuestQuotaExemptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['queue.default' => 'redis', 'block-bots.allowed_bots_by_ip' => [
            'gptbot' => ['192.0.2.0/24'],
        ]]);
    }

    private function client(string $agent, string $ip = '2001:db8:abcd:1::7')
    {
        return (object) ['ip' => $ip, 'userAgent' => $agent];
    }

    public function test_regular_browser_and_wildcard_use_no_redis_or_queue()
    {
        Redis::shouldReceive('get', 'eval', 'sismember')->never();
        $options = new Configuration();
        foreach (['Mozilla/5.0 Chrome/130', 'SemrushBot'] as $agent) {
            $this->assertFalse(GuestQuotaExemptions::allows($this->client($agent), $options, ['semrushbot' => '*']));
        }
        Queue::assertNothingPushed();
    }

    public function test_official_ranges_and_explicit_ips_are_scoped()
    {
        $options = new Configuration();
        config(['block-bots.whitelist_ips' => ['2001:db8:1::1/128']]);
        $admin = $this->client('Googlebot', '2001:db8:1::1');
        $this->assertTrue(GuestQuotaExemptions::allows($admin, $options, []));
        $this->assertFalse(GuestQuotaExemptions::isVerifiedSearchCrawler($admin, $options, []));
        $this->assertTrue(GuestQuotaExemptions::allows($this->client('GPTBot', '192.0.2.1'), $options, []));
        $this->assertFalse(GuestQuotaExemptions::allows($this->client('GPTBot', '192.0.3.1'), $options, []));
        config(['block-bots.use_default_allowed_bots' => false]);
        $this->assertFalse(GuestQuotaExemptions::allows($this->client('GPTBot', '192.0.2.1'), $options, []));
        $this->assertTrue(GuestQuotaExemptions::allows($this->client('GPTBot', '192.0.2.1'), $options, ['gptbot' => '*']));
    }

    public function test_pending_is_not_proof_and_same_address_queues_once()
    {
        $client = $this->client('Googlebot/2.1');
        $options = new Configuration();
        Redis::sadd($options->whitelist_key, '2001:db8:abcd:1::');
        $this->assertFalse(GuestQuotaExemptions::allows($client, $options, []));
        $this->assertFalse(GuestQuotaExemptions::isVerifiedSearchCrawler($client, $options, []));
        Queue::assertPushed(VerifyGuestCrawler::class, 1);

        // The queue worker publishes only to the exact identity it was asked to verify.
        $job = Queue::pushed(VerifyGuestCrawler::class)->first();
        $key = new \ReflectionProperty($job, 'key');
        if (PHP_VERSION_ID < 80100) {
            $key->setAccessible(true);
        }
        Redis::setex($key->getValue($job), 300, 'verified');
        $this->assertTrue(GuestQuotaExemptions::isVerifiedSearchCrawler($client, $options, []));
        $this->assertFalse(GuestQuotaExemptions::isVerifiedSearchCrawler($this->client('Googlebot', '2001:db8:abcd:1::8'), $options, []));
        $this->assertFalse(GuestQuotaExemptions::isVerifiedSearchCrawler($this->client('Googlebot Bingbot'), $options, []));
        $this->assertFalse(GuestQuotaExemptions::isVerifiedSearchCrawler($this->client('Googlebot MyOtherBot'), $options, ['myotherbot' => '*']));
        $this->assertFalse(GuestQuotaExemptions::isVerifiedSearchCrawler($this->client('Bingbot'), $options, []));
        config(['block-bots.allowed_bots_by_ip.googlebot' => []]);
        $this->assertFalse(GuestQuotaExemptions::isVerifiedSearchCrawler($client, $options, []));
        config(['block-bots.allowed_bots_by_ip' => [], 'block-bots.guest_quota_dns_domains.googlebot' => ['other.example']]);
        $this->assertFalse(GuestQuotaExemptions::isVerifiedSearchCrawler($client, $options, []));
    }

    public function test_missing_async_queue_leaves_dns_candidate_metered()
    {
        config(['queue.default' => 'sync']);
        $this->assertFalse(GuestQuotaExemptions::allows($this->client('Googlebot'), new Configuration(), []));
        Queue::assertNothingPushed();
    }

    public function test_rotating_addresses_cannot_enqueue_unlimited_dns_work()
    {
        $options = new Configuration();
        for ($i = 1; $i <= 40; $i++) {
            $this->assertFalse(GuestQuotaExemptions::allows($this->client('Bingbot', '2001:db8:abcd:' . $i . '::1'), $options, []));
        }
        Queue::assertPushed(VerifyGuestCrawler::class, 32);
    }

    public function test_suffix_and_forward_address_must_both_match_in_the_same_lease()
    {
        $ip = '2001:db8::15';
        foreach ([
            ['BADGOOGLEBOT.COM.', [$ip], false],
            ['x.googlebot.com.example', [$ip], false],
            ['x.googlebot.com.', ['2001:db8::14'], false],
            ['X.GOOGLEBOT.COM.', ['2001:db8::14', '2001:db8:0000:0:0:0:0:15'], true],
        ] as [$host, $records, $expected]) {
            $job = new class($ip, ['googlebot.com'], 'guest:proof', 'lease') extends VerifyGuestCrawler {
                public $host;
                public $records;
                protected function reverse(string $ip) { return $this->host; }
                protected function forward(string $host, bool $ipv4): array { return $this->records; }
            };
            $job->host = $host;
            $job->records = $records;
            Redis::setex('guest:proof', 10, 'lease');
            $job->handle();
            $this->assertSame($expected ? 'verified' : 'denied', Redis::get('guest:proof'));
            $this->assertGreaterThan(0, Redis::ttl('guest:proof'));
            Redis::setex('guest:proof', 10, 'new-lease');
            $job->handle();
            $this->assertSame('new-lease', Redis::get('guest:proof'));
        }
    }
}
