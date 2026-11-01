<?php

namespace Hooey\Election;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

class Election extends AbstractModel
{
    protected $table = 'hooey_elections';

    public $timestamps = true;

    protected $casts = [
        'seat_count' => 'integer',
        'nomination_ends_at' => 'datetime',
        'publicity_ends_at' => 'datetime',
        'vote_ends_at' => 'datetime',
    ];

    protected $fillable = [
        'title', 'seat_count', 'status', 'nomination_ends_at',
        'publicity_ends_at', 'vote_ends_at', 'publicity_text',
        'discussion_id', 'poll_id', 'poll_option_map',
        'result_discussion_id', 'result_data', 'created_by',
    ];

    public const STATUS_NOMINATION = 'nomination';
    public const STATUS_PUBLICITY = 'publicity';
    public const STATUS_VOTING = 'voting';
    public const STATUS_ENDED = 'ended';

    public const STATUS_LABELS = [
        self::STATUS_NOMINATION => '提名中',
        self::STATUS_PUBLICITY => '公示中',
        self::STATUS_VOTING => '投票中',
        self::STATUS_ENDED => '已结束',
    ];

    public function nominations()
    {
        return $this->hasMany(ElectionNomination::class, 'election_id')->orderBy('created_at')->orderBy('id');
    }

    public function candidates()
    {
        return $this->nominations()->where('status', ElectionNomination::STATUS_CANDIDATE);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
