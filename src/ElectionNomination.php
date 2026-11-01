<?php

namespace Hooey\Election;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

class ElectionNomination extends AbstractModel
{
    protected $table = 'hooey_election_nominations';

    public $timestamps = true;

    public const STATUS_CANDIDATE = 'candidate';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'election_id', 'nominator_id', 'nominee_id', 'status',
    ];

    public function election()
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function nominator()
    {
        return $this->belongsTo(User::class, 'nominator_id');
    }

    public function nominee()
    {
        return $this->belongsTo(User::class, 'nominee_id');
    }
}
