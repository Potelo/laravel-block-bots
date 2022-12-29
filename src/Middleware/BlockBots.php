<?php

namespace Potelo\LaravelBlockBots\Middleware;

use Closure;

use Carbon\Carbon;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Auth;

use Potelo\LaravelBlockBots\Abstracts\AbstractBlockBots;
use Potelo\LaravelBlockBots\Events\UserBlockedEvent;
use Potelo\LaravelBlockBots\Jobs\CheckIfBotIsReal;
use Potelo\LaravelBlockBots\Jobs\ProcessLogWithIpInfo;


class BlockBots extends AbstractBlockBots
{

    /**
     * Executes the middleware
     *
     * @param Request $request
     * @param Closure $next
     * @param int $limit
     * @param string $frequency
     * @return void
     */
    public function handle($request, Closure $next, $limit = 100, $frequency = 'daily')
    {
        $this->beforeHandle();

        if (!$this->options->enabled) {
            return $next($request);
        }

        $this->setUp($request, $limit, $frequency);
        if ($this->countHits) {
            $this->countHits();
        }

        return $this->isAllowed() ? $next($request) : $this->notAllowed();
    }

    /**
     * Responses to disallowed client
     *
     * @return response
     */
    protected function notAllowed()
    {
        if ($this->options->log) {
            if (!$this->options->log_only_guest || Auth::guest()) {
                $this->logDisallowance();
            }
        }

        if (Auth::check() && $this->isTheFirstOverflow()) {
            event(new UserBlockedEvent(Auth::user(), $this->hits, Carbon::now()));
        }


        if ($this->request->expectsJson()) {
            return response()->json($this->options->json_response, 429);
        }

        return response(view('block-bots::error'), 429);
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
     * Returns the value of the access counter
     *
     * @return void
     */
    protected function countHits()
    {
        if (!Redis::exists($this->client->key)) {
            Redis::set($this->client->key, 1);
            Redis::expireat($this->client->key, $this->timeOutAt);
            return $this->hits = 1;
        }

        return $this->hits = Redis::incr($this->client->key);
    }

    private function logDisallowance()
    {
        if (!Redis::exists($this->client->logKey)) {
            Redis::set($this->client->logKey, 1);
            Redis::expireat($this->client->logKey, $this->timeOutAt);
            ProcessLogWithIpInfo::dispatch($this->client, "BLOCKED", $this->options, $this->limit);
        }
    }

    /**
     * Check if the client is a preseted allowed bot.
     *
     * @return boolean
     */
    private function isAllowedBot()
    {
        $allowedBotsKeys = array_keys($this->getAllowedBots());
        $userAgent = strtolower($this->client->userAgent);
        $pattern = strtolower('(' . implode('|', $allowedBotsKeys) . ')');

        return preg_match($pattern, $userAgent);
    }

    /**
     * Check if the client is whitelisted.
     *
     * @return bool
     */
    private function isWhitelisted()
    {
        if (Redis::sismember($this->options->whitelist_key, $this->client->ip)) {
            return true;
        }

        //Lets verify if its on our whitelist
        if (in_array($this->client->ip, $this->options->whitelist_ips)) {
            //Add this to the redis list as it is faster
            Redis::sadd($this->options->whitelist_key, $this->client->ip);
            if ($this->options->log) {
                ProcessLogWithIpInfo::dispatch($this->client, 'WHITELISTED', $this->options);
            }
            return true;
        }

        return false;
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
        //We fast-track allowed IPs
        if ($this->isWhitelisted()) {
            return true;
        }
        //Lets block fake bots
        if (Redis::sismember($this->options->fake_bot_list_key, $this->client->ip)) {
            return false;
        }

        if ($this->isAllowedBot()) {
            // While the bot is on pending_list, it's unchecked, so we allow this bot to pass-thru
            if (!Redis::sismember($this->options->pending_bot_list_key, $this->client->ip)) {
                // If we got here, it is an unknown bot. Let's create a job to test it
                CheckIfBotIsReal::dispatch($this->client, $this->getAllowedBots(), $this->options);
                Redis::sadd($this->options->pending_bot_list_key, $this->client->ip);
            }

            return true;
        }

        return false;
    }
}
