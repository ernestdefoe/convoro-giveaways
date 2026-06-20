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

    // The raw seed is secret until the draw reveals it — never expose it through
    // array/JSON serialization by accident.
    protected $hidden = ['seed'];

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
}
