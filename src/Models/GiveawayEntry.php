<?php

namespace Convoro\Ext\Giveaways\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class GiveawayEntry extends Model
{
    protected $table = 'giveaway_entries';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function giveaway()
    {
        return $this->belongsTo(Giveaway::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
