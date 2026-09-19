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
- **Official rules** — publish long-form terms (eligibility, how the winner is
  selected, deadlines, sponsor, odds) with each giveaway. They appear on the
  Giveaways page, in the sidebar widget and on the giveaway's permanent
  verification page, so the rules stay on the record after the draw.
- **Skill-testing question** — optionally require the **drawn winner** to answer
  a question correctly before the prize is awarded, for jurisdictions (Canada
  among them) where a pure game of chance would be an illegal lottery. Entering
  stays one click; the winner answers on the Giveaways page after the draw, and
  the giveaway records when they passed. Answers are checked server-side; case,
  spaces and thousands separators are ignored, and numeric answers compare as
  numbers.
- **Public Giveaways page** — the active giveaway plus a list of past winners,
  each linking to its fairness proof.
- **Themed admin manager** — create, edit, draw, open/close and delete giveaways
  from a panel that matches the forum theme. Details stay editable until the
  draw (so a typo in the rules is a quick fix); the seed and its commitment are
  never rewritten, and a drawn giveaway is frozen as the published record.

## How the fairness works

1. **Commit** — on creation a CSPRNG seed is generated; only `sha256(seed)` is
   published. The operator cannot change the seed afterwards without breaking the
   commitment.
2. **Enter** — members enter; entries are one-per-user and counted publicly.
3. **Reveal & draw** — the seed is revealed and the winner is the entrant
   minimising `sha256(seed : user-id)` over the (published) entrant list.
4. **Verify** — anyone can recompute it from the revealed seed + entrant ids and
   confirm `sha256(seed)` equals the original commitment.
5. **Claim** — if a skill-testing question is set, the winner answers it before
   the prize is awarded. The draw itself is unaffected: the question qualifies
   the award, not the entry.

## Install

Install from the Convoro Marketplace. Create giveaways — including their rules
and skill-testing question — under **Admin → Extensions → Giveaways**, where you
can also toggle the sidebar widget. For scheduled auto-draws, make sure your
host runs Laravel's scheduler (`* * * * * php artisan schedule:run`).
