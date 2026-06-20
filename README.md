# Giveaways for Convoro

Run **provably-fair** giveaways and raffles in your [Convoro](https://convoro.co)
community. Members enter with one click, and every draw can be verified by anyone.

Free, first-party, MIT-licensed. Requires Convoro core **≥ 1.39.6**.

## Features

- **One-click entry** — the active giveaway appears in the forum sidebar with an
  Enter button (one entry per member).
- **Provably fair (commit–reveal)** — when a giveaway is created we publish
  `sha256(seed)` as a commitment but keep the seed secret. At the draw the seed
  is revealed, so anyone can confirm it matches the commitment and recompute the
  winner = the entrant with the lowest `sha256(seed : user-id)`.
- **Built-in verifier** — every giveaway has a public `/giveaways/{id}/verify`
  page that recomputes the result **in your browser** and shows a ✓/✗.
- **Scheduled auto-draws** — give a giveaway an end time and it draws itself.
  Runs every minute via the host scheduler (`php artisan schedule:run`), with an
  opportunistic fallback so winners are still drawn on hosts without cron.
- **Public Giveaways page** — the active giveaway plus a list of past winners,
  each linking to its fairness proof.
- **Themed admin manager** — create, draw, open/close and delete giveaways from
  a panel that matches the forum theme.

## How the fairness works

1. **Commit** — on creation a CSPRNG seed is generated; only `sha256(seed)` is
   published. The operator cannot change the seed afterwards without breaking the
   commitment.
2. **Enter** — members enter; entries are one-per-user and counted publicly.
3. **Reveal & draw** — the seed is revealed and the winner is the entrant
   minimising `sha256(seed : user-id)` over the (published) entrant list.
4. **Verify** — anyone can recompute it from the revealed seed + entrant ids and
   confirm `sha256(seed)` equals the original commitment.

## Install

Install from the Convoro Marketplace. Optionally toggle the sidebar widget under
**Admin → Extensions → Giveaways**. For scheduled auto-draws, make sure your
host runs Laravel's scheduler (`* * * * * php artisan schedule:run`).
