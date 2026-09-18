<?php

namespace Convoro\Ext\Giveaways\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Giveaway extends Model
{
    protected $table = 'giveaways';

    // Mass-assignment is locked down; the controller sets attributes explicitly.
    protected $guarded = ['id'];

    protected $casts = [
        'ends_at' => 'datetime',
        'drawn_at' => 'datetime',
        'active' => 'boolean',
    ];

    // The raw seed is secret until the draw reveals it, and the skill-testing
    // answer must never leak to entrants — keep both out of array/JSON
    // serialization by accident.
    protected $hidden = ['seed', 'skill_answer'];

    public function entries()
    {
        return $this->hasMany(GiveawayEntry::class);
    }

    public function winner()
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    public function hasEnded(): bool
    {
        return $this->ends_at !== null && now()->greaterThan($this->ends_at);
    }

    /** Whether entrants must answer a skill-testing question to enter. */
    public function requiresSkillAnswer(): bool
    {
        return trim((string) $this->skill_question) !== '' && trim((string) $this->skill_answer) !== '';
    }

    /**
     * Check an entrant's skill-testing answer leniently: case, surrounding
     * whitespace, thousands separators and a trailing full stop are ignored, and
     * two numeric answers are compared as numbers ("7.0" matches "7").
     */
    public function skillAnswerMatches(?string $given): bool
    {
        if (! $this->requiresSkillAnswer()) {
            return true;
        }

        $expected = self::normalizeAnswer((string) $this->skill_answer);
        $actual = self::normalizeAnswer((string) $given);
        if ($actual === '') {
            return false;
        }

        if (is_numeric($expected) && is_numeric($actual)) {
            return abs((float) $expected - (float) $actual) < 0.000001;
        }

        return $expected === $actual;
    }

    private static function normalizeAnswer(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = rtrim($value, '.');
        // Drop spaces and thousands separators so "1 000" / "1,000" == "1000".
        $value = preg_replace('/[\s,]+/u', '', $value) ?? '';

        return trim($value);
    }
}
