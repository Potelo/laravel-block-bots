<?php

namespace Potelo\LaravelBlockBots\Tests\Unit;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Potelo\LaravelBlockBots\Events\BotBlockedEvent;
use Potelo\LaravelBlockBots\Middleware\BlockBots;
use Potelo\LaravelBlockBots\Tests\TestCase;

class GuestPageQuotaTest extends TestCase
{
    private $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake([BotBlockedEvent::class]);
        config(['block-bots.whitelist_ips' => [], 'block-bots.log' => false]);
        $this->middleware = new class extends BlockBots {
            protected function guestQuotaLimits()
            {
                return [20, 100];
            }

            protected function guestQuotaPageKey()
            {
                return $this->request->query('page');
            }
        };
    }

    private function visit($ip, $page = null)
    {
        $request = Request::create('/process', 'GET', $page === null ? [] : ['page' => $page], [], [], [
            'REMOTE_ADDR' => $ip, 'HTTP_ACCEPT' => 'application/json', 'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]);
        return $this->middleware->handle($request, function () { return response('ok'); });
    }

    public function test_refusals_on_one_address_do_not_consume_neighbours_budget()
    {
        for ($n = 1; $n <= 20; ++$n) {
            self::assertSame(200, $this->visit('192.0.2.1')->getStatusCode());
        }
        for ($n = 0; $n < 25; ++$n) {
            $blocked = $this->visit('::ffff:c000:0201');
            self::assertSame(429, $blocked->getStatusCode());
        }
        self::assertSame('guest-page-quota', $blocked->headers->get('X-BlockBots-Reason'));
        self::assertTrue($blocked->headers->hasCacheControlDirective('no-store'));
        self::assertGreaterThan(0, (int) $blocked->headers->get('Retry-After'));
        for ($ip = 2; $ip <= 5; ++$ip) {
            for ($n = 0; $n < 20; ++$n) {
                self::assertSame(200, $this->visit('192.0.2.' . $ip)->getStatusCode());
            }
        }
        self::assertSame(429, $this->visit('192.0.2.6')->getStatusCode());
        self::assertSame(429, $this->visit('192.0.2.7')->getStatusCode());
        Event::assertDispatchedTimes(BotBlockedEvent::class, 2);
        self::assertSame(200, $this->visit('192.0.3.1')->getStatusCode());
        Auth::setUser(new GenericUser(['id' => 42]));
        self::assertSame(200, $this->visit('192.0.2.1')->getStatusCode());
    }

    public function test_rotating_ipv6_within_prefix_and_independent_networks()
    {
        for ($subnet = 1; $subnet <= 5; ++$subnet) {
            for ($n = 1; $n <= 20; ++$n) {
                self::assertSame(200, $this->visit("2001:db8:aa:$subnet::$n")->getStatusCode());
            }
            self::assertSame(429, $this->visit("2001:db8:aa:$subnet::ff")->getStatusCode());
        }
        self::assertSame(429, $this->visit('2001:db8:aa:6::1')->getStatusCode());
        self::assertSame(200, $this->visit('2001:db8:ab:6::1')->getStatusCode());
    }

    public function test_failure_in_notification_does_not_hide_the_login_response()
    {
        Event::swap(new \Illuminate\Events\Dispatcher(app()));
        Event::listen(BotBlockedEvent::class, function () {
            throw new \RuntimeException('Notification unavailable');
        });
        for ($n = 1; $n <= 20; ++$n) {
            $this->visit('192.0.2.40');
        }
        self::assertSame(429, $this->visit('192.0.2.40')->getStatusCode());
    }

    public function test_only_admitted_pages_receive_a_deduplication_key()
    {
        for ($id = 1; $id <= 20; ++$id) {
            self::assertSame(200, $this->visit('192.0.2.10', 'case-' . $id)->getStatusCode());
        }
        self::assertSame(200, $this->visit('192.0.2.10', 'case-20')->getStatusCode());
        self::assertSame(429, $this->visit('192.0.2.10', 'case-21')->getStatusCode());
        self::assertSame(429, $this->visit('192.0.2.10', 'case-21')->getStatusCode());
    }
}
