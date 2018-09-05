<?php

namespace Potelo\LaravelBlockBots;

use Closure;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

use Carbon\Carbon;
use Potelo\LaravelBlockBots\CheckIfBotIsReal;

class BlockBots
{
    protected $config;

    public function __construct()
    {
        $this->config = config('block');
    }

    private function enabled()
    {
        return $this->config['enabled'];
    }

    /**
     * Register all visits in Redis
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle($request, Closure $next, $dailyLimit, $redirectUrl)
    {

        if ($this->enabled() && ($request->getRequestUri() != $redirectUrl)) {

            try {
                $blocked = $this->blocked($request, $dailyLimit);

            } catch (Exception $e) {
                Log::error("[Block-Bots] Error at handling request: {$e->getMessage()}");
                $blocked = false;
            }

            if ($blocked) {
                return Redirect::to($redirectUrl);
            }
        }

        return $next($request);
    }

    /**
     * Check if user is blocked
     *
     * @return mixed
     */
    public function blocked($request, $dailyLimit)
    {

        $ip = $request->getClientIp();
        $user_agent = $request->header('User-Agent');
        $fake_mode = $this->config['fake_mode'];
        $log_blocked_requests = $this->config['log_blocked_requests'];
        $allow_logged_user = $this->config['allow_logged_user'];

        $full_url = substr($request->fullUrl(), strlen($request->getScheme(). "://")); # Get the URL without scheme

        $key_access_count = "block_bot:{$ip}";

        $number_of_hits = 1;

        // Lets get the total count, or create a new key for him
        if(!Redis::exists($key_access_count))
        {
            $end_of_day_unix_timestamp = Carbon::tomorrow()->startOfDay()->timestamp;
            Redis::set($key_access_count, 1);
            Redis::expireat($key_access_count, $end_of_day_unix_timestamp);
        }
        else{
            $number_of_hits = Redis::incr($key_access_count);
        }

        $over_the_limit = $number_of_hits > $dailyLimit;
        $is_user_logged = $allow_logged_user && Auth::check();


        if(!$over_the_limit || $is_user_logged || $this->isWhitelisted($ip, $user_agent))
        {
            return false;
        }
        else{
            if($fake_mode)
            {
                if ($log_blocked_requests){
                    Log::error("[Block-Bots] IP: {$ip} with User agent: {$user_agent} would be blocked after {$number_of_hits} hits while accessing URL {$full_url}");
                }

                return false;
            }
            else{
                return true;
            }
        }
    }

    private function isABot($user_agent)
    {
        $allowed_bots = array_keys($this->config['allowed_crawlers']);
        $lower_user_agent = strtolower($user_agent);
        $pattern = strtolower('('.implode('|',$allowed_bots).')');

        if ( preg_match($pattern, $lower_user_agent)){
            return true;
        }else{
            return false;
        }
    }

    public function isWhitelisted($ip, $user_agent)
    {

        $key_whitelist = "block_bot:whitelist";
        $allowed = Redis::sismember($key_whitelist, $ip);
        //We fast-track allowed IPs
        if($allowed)
            return true;

        //Lets block fake bots
        $key_fake_bot = "block_bot:fake_bots";
        $fake_bot = Redis::sismember($key_fake_bot, $ip);

        if ($fake_bot)
            return false;

        //Lets verify if its on our whitelist
        if (in_array($ip, $this->config['whitelist_ips'])){
            //Add this to the redis list as it is faster
            Redis::sadd($key_whitelist, $ip);
            if ($this->config['log_blocked_requests']){
                Log::info("[Block-Bots] WHITELISTED IP: {$ip} with User agent: {$user_agent} because was on our config");
            }
        }

        // Or if is a allowed crawler
        $is_a_bot = $this->isABot($user_agent);

        if ($is_a_bot)
        {
            $key_pending_bot = "block_bot:pending_bots";
            $pending_bot = Redis::sismember($key_pending_bot, $ip);
            //Check if the verification is pending. While the check is not made, we allow this bot to pass-thru

            if ($pending_bot)
                return true;

            // If we got here, it is an unverified bot. Lets create a job to test it
            \Potelo\LaravelBlockBots\Jobs\CheckIfBotIsReal::dispatch($ip, $user_agent);

        }

        return false;

    }

}
