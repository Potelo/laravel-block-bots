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
        foreach ($this->providers() as $provider) {
            $ranges = array_merge($ranges, $provider->getRanges());
        }
        foreach (config('block-bots.allowed_bots_by_ip', []) as $bot => $entry) {
            if (is_string($bot) && is_array($entry)) {
                $ranges[strtolower($bot)] = $entry;
            }
        }
        return $ranges;
    }

    public function match(string $userAgent): ?array
    {
        $ranges = $this->ranges();
        // Prefer specific bot names when configured keywords overlap.
        uksort($ranges, function ($a, $b) { return strlen($b) <=> strlen($a); });
        foreach ($ranges as $bot => $cidrs) {
            if ($bot !== '' && stripos($userAgent, $bot) !== false) {
                return $cidrs;
            }
        }
        return null;
    }
}
