<?php

namespace Potelo\LaravelBlockBots\Middleware;

use Closure;
use Potelo\LaravelBlockBots\Services\GuestPageQuota;
use Potelo\LaravelBlockBots\Services\GuestQuotaExemptions;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\IpUtils;
use Potelo\LaravelBlockBots\Jobs\CheckIfBotIsReal;
use Potelo\LaravelBlockBots\Events\UserBlockedEvent;
use Potelo\LaravelBlockBots\Jobs\ProcessLogWithIpInfo;
use Potelo\LaravelBlockBots\Abstracts\AbstractBlockBots;
use Potelo\LaravelBlockBots\Events\BotBlockedEvent;

class BlockBots extends AbstractBlockBots
{
    private $verifiedSearchCrawler;

    /**
     * Executes the middleware
     *
     * @param \Illuminate\Http\Request $request
     * @param Closure $next
     * @param int $limit
     * @param string $frequency
     * @return mixed
     */
    public function handle($request, Closure $next, $limit = 100, $frequency = 'daily')
    {
        $this->verifiedSearchCrawler = null;
        $this->incrementHits = true;
        $this->setUp($request, $limit, $frequency);
        $this->beforeHandle();

        if (!$this->options->enabled) {
            return $next($request);
        }

        if ($this->options->mode !== 'always' && Auth::guest() && ($limits = $this->guestQuotaLimits()) !== null) {
            if ($this->options->mode === 'never') {
                return $next($request);
            }
            if (GuestQuotaExemptions::allows($this->client, $this->options, $this->getAllowedBots())
                && (!method_exists($this, 'botRules') || $this->botRules())) {
                return $next($request);
            }
            $refusal = GuestPageQuota::admit($this->client->ip, $limits, $this->guestQuotaPageKey(), $this->options->timezone);
            return $refusal === null ? $next($request) : $this->guestQuotaExceeded($refusal);
        }

        $this->hits = $this->client->countHits($this->incrementHits, $frequency);

        return $this->isAllowed() ? $next($request) : $this->notAllowed();
    }

    /**
     * Opt in per route to daily [client, network] quotas for anonymous visits.
     * The default retains the application's existing rules and frequency.
     */
    protected function guestQuotaLimits()
    {
        return null;
    }

    /** Return a stable page ID when several requests render one public page. */
    protected function guestQuotaPageKey()
    {
        return null;
    }

    /** A manual IP exemption alone does not identify a search crawler. */
    public function isVerifiedSearchCrawler()
    {
        if ($this->verifiedSearchCrawler === null) {
            $this->verifiedSearchCrawler = $this->client !== null && GuestQuotaExemptions::isVerifiedSearchCrawler(
                $this->client, $this->options, $this->getAllowedBots()
            );
        }
        return $this->verifiedSearchCrawler;
    }

    private function guestQuotaExceeded(array $refusal)
    {
        if ($refusal['first']) {
            $this->client->key = $refusal['key'];
            if ($this->options->log) {
                try {
                    $driver = config('queue.connections.' . config('queue.default') . '.driver');
                    if ($driver === 'sync' || $driver === null) {
                        // No DNS or HTTP enrichment on the request worker.
                        error_log('[Block-Bots] Daily guest quota reached (' . $refusal['limit'] . ').');
                    } else {
                        ProcessLogWithIpInfo::dispatch($this->client, 'BLOCKED', $this->options, $refusal['limit']);
                    }
                } catch (\Throwable $error) {
                    error_log('[Block-Bots] Unable to queue guest quota log: ' . get_class($error));
                }
            }
            try {
                event(new BotBlockedEvent($this->client->ip, $refusal['limit'] + 1, Carbon::now()));
            } catch (\Throwable $error) {
                error_log('[Block-Bots] Unable to notify guest quota listener: ' . get_class($error));
            }
        }
        $headers = [
            'Retry-After' => $refusal['ttl'],
            'Cache-Control' => 'private, no-store',
            'X-BlockBots-Reason' => 'guest-page-quota',
        ];
        if ($this->request->expectsJson()) {
            return response()->json($this->options->json_response, 429, $headers);
        }
        return response(view('block-bots::error', ['guestPageQuota' => true]), 429, $headers);
    }

    /**
     * Responses to disallowed client
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    protected function notAllowed()
    {
        if ($this->options->log) {
            if (!$this->options->log_only_guest || Auth::guest()) {
                $this->client->logDisallowance($this->limit, $this->frequency);
            }
        }

        if (Auth::check() && $this->isTheFirstOverflow()) {
            event(new UserBlockedEvent(Auth::user(), $this->hits, Carbon::now()));
        }

        if (Auth::guest() && $this->isTheFirstOverflow()) {
            event(new BotBlockedEvent($this->client->ip, $this->hits, Carbon::now()));
        }

        $headers = [];
        if ($this->isLimitExceeded()) {
            $ttl = (int) Redis::ttl($this->client->key);
            if ($ttl > 0) {
                $headers['Retry-After'] = $ttl;
            }
        }

        if ($this->request->expectsJson()) {
            return response()->json($this->options->json_response, 429, $headers);
        }

        return response(view('block-bots::error'), 429, $headers);
    }

    /**
     * Check if the client access is allowed
     *
     * @return boolean
     */
    protected function isAllowed()
    {
        if ($this->options->mode === 'never') {
            return true;
        } elseif ($this->options->mode === 'always') {
            return false;
        } elseif (Auth::check()) {
            return $this->passesAuthRules() && !$this->isLimitExceeded();
        } elseif (Auth::guest() && $this->passesGuestRules() && !$this->isLimitExceeded()) {
            return true;
        }

        return $this->passesBotRules();
    }

    /**
     * Check if the client is a preseted allowed bot.
     *
     * @return boolean
     */
    private function isAllowedBot()
    {
        $allowedBotsKeys = array_keys($this->getAllowedBots());
        if ($allowedBotsKeys === []) {
            return false;
        }

        $userAgent = strtolower($this->client->userAgent);
        $pattern = strtolower('(' . implode('|', $allowedBotsKeys) . ')');

        return preg_match($pattern, $userAgent);
    }

    /**
     * @return mixed|string|null
     */
    public function getMatchedBot()
    {
        foreach (array_keys($this->getAllowedBots()) as $bot) {
            if (strpos(strtolower($this->client->userAgent), strtolower($bot)) !== false) {
                return $bot;
            }
        }
    }

    /**
     * Check if the client is whitelisted.
     *
     * @return bool
     */
    public function isWhitelisted()
    {
        // Check Redis whitelist using trackable IP (normalized IPv6 prefix)
        if (Redis::sismember($this->options->whitelist_key, $this->client->trackableIp)) {
            return true;
        }

        if ($this->isExplicitlyWhitelisted()) {
            // Add trackable IP to Redis whitelist for faster future checks
            Redis::sadd($this->options->whitelist_key, $this->client->trackableIp);
            if ($this->options->log) {
                ProcessLogWithIpInfo::dispatch($this->client, 'WHITELISTED', $this->options);
            }
            return true;
        }

        return false;
    }

    private function isExplicitlyWhitelisted(): bool
    {
        return $this->client->ip !== null
            && IpUtils::checkIp($this->client->ip, $this->options->whitelist_ips);
    }


    /**
     * Determine if the request passes the auth check.
     *
     * @return bool
     */
    protected function passesAuthRules()
    {
        if (method_exists($this, 'authRules')) {
            if (!call_user_func_array([$this, 'authRules'], [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if the request passes the guest check.
     *
     * @return bool
     */
    protected function passesGuestRules()
    {
        if (method_exists($this, 'guestRules')) {
            if (!call_user_func_array([$this, 'guestRules'], [])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determine if the request passes the bot check.
     *
     * @return bool
     */
    public function passesBotRules()
    {
        if (method_exists($this, 'botRules')) {
            if (!call_user_func_array([$this, 'botRules'], [])) {
                return false;
            }
        }
        // IP rules are authoritative, including an explicit empty static array.
        // Never reuse prefix-wide verification results for IP-managed bots.
        $ranges = app(\Potelo\LaravelBlockBots\Services\BotIpResolver::class)
            ->match((string) $this->client->userAgent);
        if ($ranges !== null) {
            return $this->isExplicitlyWhitelisted()
                || (($this->options->use_default_allowed_bots || $this->isAllowedBot())
                    && filter_var($this->client->ip, FILTER_VALIDATE_IP) !== false
                    && \Potelo\LaravelBlockBots\Helpers\IpHelper::ipInAnyCidr($this->client->ip, $ranges));
        }

        //We fast-track allowed IPs
        if ($this->isWhitelisted()) {
            return true;
        }
        // Block fake bots using trackable IP (normalized IPv6 prefix)
        if (Redis::sismember($this->options->fake_bot_list_key, $this->client->trackableIp)) {
            return false;
        }

        if ($this->isAllowedBot()) {
            // While the bot is on pending_list, it's unchecked, so we allow this bot to pass-thru
            if (!Redis::sismember($this->options->pending_bot_list_key, $this->client->trackableIp)) {
                // If we got here, it is an unknown bot. Let's create a job to test it
                Redis::sadd($this->options->pending_bot_list_key, $this->client->trackableIp);
                CheckIfBotIsReal::dispatch($this->client, $this->getAllowedBots(), $this->options);
            }

            return true;
        }

        return false;
    }
}
