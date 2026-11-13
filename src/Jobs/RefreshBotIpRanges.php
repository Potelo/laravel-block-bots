<?php

namespace Potelo\LaravelBlockBots\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Potelo\LaravelBlockBots\Services\BotIpProvider;

class RefreshBotIpRanges implements ShouldQueue
{
    use Dispatchable, Queueable;

    public $tries = 1;
    public $timeout = 60;
    public $bot;
    public $token;
    public $attempt;
    const RETRY_DELAYS = [60, 300, 1800, 7200, 43200];

    public function __construct(string $bot, string $token, int $attempt = 0)
    {
        $this->bot = $bot;
        $this->token = $token;
        $this->attempt = $attempt;
    }

    public function handle(BotIpProvider $provider)
    {
        if (!$provider->ownsLease($this->bot, $this->token)) {
            return;
        }
        try {
            $provider->refresh($this->bot, $this->token);
        } catch (\Throwable $exception) {
            Log::warning('Bot IP refresh failed; keeping previous ranges.', [
                'bot' => $this->bot, 'attempt' => $this->attempt + 1, 'error' => $exception->getMessage(),
            ]);
            if (isset(self::RETRY_DELAYS[$this->attempt])) {
                self::dispatch($this->bot, $this->token, $this->attempt + 1)
                    ->delay(\Carbon\Carbon::now()->addSeconds(self::RETRY_DELAYS[$this->attempt]));
            } else {
                $provider->release($this->bot, $this->token);
            }
        }
    }
}
