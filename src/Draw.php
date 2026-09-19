<?php

namespace Convoro\Ext\Giveaways;

use Convoro\Ext\Giveaways\Models\Giveaway;
use Convoro\Ext\Giveaways\Models\GiveawayEntry;
use Illuminate\Support\Facades\DB;

/**
 * Provably-fair draw logic.
 *
 * Commit–reveal: a secret `seed` is generated when a giveaway is created and we
 * immediately publish only `seed_hash = sha256(seed)` (the commitment). The raw
 * seed stays hidden until the draw runs, so the operator cannot swap the seed
 * after entries are known. The winner is a pure, deterministic function of the
 * revealed seed and the (published) ordered list of entrant user-ids:
 *
 *     winner = entrant minimising sha256(seed . ':' . userId)
 *
 * Anyone can recompute it from the revealed seed + entrant list and confirm the
 * seed matches the pre-published hash — that's the "provable" part.
 */
class Draw
{
    /** Generate a secret seed + its public commitment hash. */
    public static function newSeed(): array
    {
        $seed = bin2hex(random_bytes(24)); // 48 hex chars, CSPRNG
        return [$seed, hash('sha256', $seed)];
    }

    /**
     * Pure winner selection: lowest sha256(seed:userId).
     *
     * $forfeited ids are excluded — a winner who fails the skill-testing
     * question forfeits, and the prize passes to the next-lowest hash. The
     * forfeited list is published alongside the seed, so the result stays a
     * pure function of (seed, entrants, forfeits) and anyone can recompute it.
     */
    public static function winnerFor(string $seed, array $userIds, array $forfeited = []): ?int
    {
        if ($forfeited) {
            $forfeited = array_map('intval', $forfeited);
            $userIds = array_values(array_filter($userIds, fn ($uid) => ! in_array((int) $uid, $forfeited, true)));
        }

        if (! $userIds) {
            return null;
        }
        $best = null;
        $bestHash = null;
        foreach ($userIds as $uid) {
            $h = hash('sha256', $seed.':'.$uid);
            if ($bestHash === null || strcmp($h, $bestHash) < 0) {
                $bestHash = $h;
                $best = (int) $uid;
            }
        }

        return $best;
    }

    /**
     * Draw a single giveaway. Transactional + idempotent: it locks the row,
     * re-checks it hasn't already been drawn, and only then records the winner.
     * Returns the winner's user-id, or null if there was nothing to draw.
     */
    public static function perform(int $giveawayId): ?int
    {
        return DB::transaction(function () use ($giveawayId) {
            $g = Giveaway::whereKey($giveawayId)->lockForUpdate()->first();
            if (! $g || $g->drawn_at !== null) {
                return null;
            }

            $userIds = GiveawayEntry::where('giveaway_id', $g->id)
                ->orderBy('user_id')->pluck('user_id')->all();
            $winner = self::winnerFor($g->seed, $userIds, $g->forfeitedUserIds());
            if ($winner === null) {
                return null; // no entries — leave it open
            }

            $g->winner_user_id = $winner;
            $g->drawn_at = now();
            $g->active = false;
            $g->save();

            return $winner;
        });
    }

    /**
     * The winner forfeited: record it and promote the next-lowest hash.
     *
     * The seed and its commitment are untouched — only the exclusion list
     * grows, so the new winner is still the one the original draw would have
     * produced had the forfeiting entrant never entered. Returns the new
     * winner's user-id, or null when no entrants are left.
     */
    public static function forfeitAndRedraw(int $giveawayId): ?int
    {
        return DB::transaction(function () use ($giveawayId) {
            $g = Giveaway::whereKey($giveawayId)->lockForUpdate()->first();
            if (! $g || $g->winner_user_id === null || $g->skill_verified_at !== null) {
                return null;
            }

            $forfeited = $g->forfeitedUserIds();
            if (! in_array((int) $g->winner_user_id, $forfeited, true)) {
                $forfeited[] = (int) $g->winner_user_id;
            }

            $userIds = GiveawayEntry::where('giveaway_id', $g->id)
                ->orderBy('user_id')->pluck('user_id')->all();

            $g->forfeited_user_ids = json_encode($forfeited);
            $g->winner_user_id = self::winnerFor($g->seed, $userIds, $forfeited);
            $g->skill_attempts = 0; // the replacement starts with a full set
            $g->save();

            return $g->winner_user_id;
        });
    }

    /**
     * Draw every active giveaway whose end time has passed and that has at least
     * one entry. Returns the number of giveaways drawn.
     */
    public static function drawDue(): int
    {
        $due = Giveaway::query()
            ->where('active', true)
            ->whereNull('drawn_at')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())
            ->pluck('id');

        $count = 0;
        foreach ($due as $id) {
            if (self::perform($id) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
