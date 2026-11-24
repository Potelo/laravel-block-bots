# Changelog

All notable changes to `laravel-block-bots` are documented in this file.

## 4.0 - Unreleased

### ⚠️ Breaking changes

- **IP-managed bots are verified only by IP.** A user-agent that matches an
  `allowed_bots_by_ip` rule (static array or `BotIpProvider` source) is exempted
  only when its original IP is inside a configured range. Its requests no longer
  pass while awaiting a DNS check, no longer fall back to DNS, and no longer read
  or write the shared `whitelist`/`fake_bots` Redis sets. Explicit `whitelist_ips`
  still apply. DNS jobs that were already queued for these bots exit without
  writing results.
- **IP-managed bots no longer need an `allowed_bots` entry.** GPTBot,
  ChatGPT-User, OAI-SearchBot, DuckAssistBot and ClaudeBot are exempted by default
  when they come from their official ranges. Before 4.0 they were exempted only
  if they were also listed in `allowed_bots`.
- **Bot IP feeds are configured only in `bot_ip_provider.bots`.** The package no
  longer ships hardcoded feed URLs or bundled range snapshots. By default, each
  bot is listed with its URL only, so it is **not exempted until its first
  successful refresh** unless you configure `defaults`.
- **Static GPTBot, ChatGPT-User, OAI-SearchBot and DuckAssistBot ranges were
  removed** from `allowed_bots_by_ip` and replaced by
  `\Potelo\LaravelBlockBots\Services\BotIpProvider::class`.

### Added

- `BotIpProvider`: fetches official bot IP feeds over HTTPS and stores them in Redis,
  with a lease-protected refresh and retries.
- `block-bots:refresh-ips` command (`--due` refreshes only new or expired sources)
  and an automatic daily scheduler entry (`bot_ip_provider.schedule_enabled`).
- `BotIpResolver::bots()`: lists the configured IP-managed bot keywords (provider
  sources and static arrays) without reading Redis snapshots.

### Upgrading from 3.x

See "Dynamic bot IP ranges" and "Verification behavior and upgrade notes" in the
README. In short:

1. If `config/block-bots.php` is published, replace the four static bot entries
   with `BotIpProvider::class` and copy the `bot_ip_provider` block, including
   `bots`. Optionally keep the old ranges as `defaults`.
2. Run the Laravel scheduler and an asynchronous queue worker that supports
   12-hour delays.
3. After deploying, restart the workers and run
   `php artisan block-bots:refresh-ips --due`.
