<?php

namespace Potelo\LaravelBlockBots\Services;

class BotIpResolver
{
    public function providers(): array
    {
        $providers = [];
        foreach (config('block-bots.allowed_bots_by_ip', []) as $bot => $entry) {
            if (is_int($bot) && is_string($entry)) {
                $providers[] = app($entry);
            }
        }
        return $providers;
    }

    public function ranges(): array
    {
        $ranges = [];
        foreach ($this->rules() as $bot => $rule) {
            $ranges[$bot] = is_array($rule) ? $rule : $rule->ranges($bot);
        }
        return $ranges;
    }

    private function rules(): array
    {
        $rules = [];
        foreach ($this->providers() as $provider) {
            foreach (array_keys($provider->sources()) as $bot) {
                $rules[$bot] = $provider;
            }
        }
        foreach (config('block-bots.allowed_bots_by_ip', []) as $bot => $entry) {
            if (is_string($bot) && is_array($entry)) {
                $rules[strtolower($bot)] = $entry;
            }
        }
        return $rules;
    }

    public function match(string $userAgent): ?array
    {
        $rules = $this->rules();
        $bot = $this->matchingBot($userAgent, $rules);
        if ($bot === null) {
            return null;
        }

        $rule = $rules[$bot];
        return is_array($rule) ? $rule : $rule->ranges($bot);
    }

    public function manages(string $userAgent): bool
    {
        return $this->matchingBot($userAgent, $this->rules()) !== null;
    }

    private function matchingBot(string $userAgent, array $rules): ?string
    {
        // Prefer specific bot names when configured keywords overlap.
        uksort($rules, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        foreach (array_keys($rules) as $bot) {
            if ($bot !== '' && stripos($userAgent, (string) $bot) !== false) {
                return (string) $bot;
            }
        }
        return null;
    }
}
