<?php

namespace Hooey\Election\Api\Controllers;

use Flarum\Group\Permission;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Hooey\Election\Election;
use Hooey\Election\ElectionNomination;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

trait ElectionApiHelper
{
    public const COMMITTEE_TAG_ID = 7;
    public const RESIDENT_GROUP_ID = 6;
    public const COMMITTEE_GROUP_ID = 7;

    protected function actor(ServerRequestInterface $request): User
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            throw new PermissionDeniedException();
        }

        return $actor;
    }

    protected function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed)) {
            return $parsed;
        }

        $raw = (string) $request->getBody();
        $decoded = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    protected function param(ServerRequestInterface $request, string $key): ?string
    {
        $v = Arr::get($request->getAttribute('routeParameters'), $key);

        return $v === null ? null : (string) $v;
    }

    protected function error(string $detail, int $code = 422): ResponseInterface
    {
        return new JsonResponse(['errors' => [['detail' => $detail]]], $code);
    }

    /** Group ids of the actor (ints). */
    protected function actorGroupIds(User $actor): array
    {
        return array_map('intval', $actor->groups->pluck('id')->all());
    }

    protected function isManager(User $actor): bool
    {
        return $actor->isAdmin() || in_array(self::COMMITTEE_GROUP_ID, $this->actorGroupIds($actor), true);
    }

    protected function isResident(User $actor): bool
    {
        return in_array(self::RESIDENT_GROUP_ID, $this->actorGroupIds($actor), true);
    }

    /** Election pages follow committee-board visibility (tag7.viewForum). */
    protected function canViewCommittee(User $actor): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        $viewGroups = Permission::where('permission', 'tag'.self::COMMITTEE_TAG_ID.'.viewForum')
            ->pluck('group_id')->all();

        return ! empty(array_intersect(array_map('intval', $viewGroups), $this->actorGroupIds($actor)));
    }

    protected function visibleElection(User $actor, $id): ?Election
    {
        if ($id === null || (int) $id <= 0 || ! $this->canViewCommittee($actor)) {
            return null;
        }

        return Election::where('id', (int) $id)->first();
    }

    protected function displayName(User $user): string
    {
        $nick = $user->getAttribute('nickname');

        return ($nick !== null && $nick !== '') ? (string) $nick : $user->username;
    }

    /** Candidate display: nickname + verified badge only, never any house data. */
    protected function nomineeArray(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'nickname' => $this->displayName($user),
            'is_verified' => true,
        ];
    }

    protected function nominationArray(ElectionNomination $nomination): array
    {
        $nominee = $nomination->nominee;
        $nominator = $nomination->nominator;

        return [
            'id' => (int) $nomination->id,
            'election_id' => (int) $nomination->election_id,
            'nominee' => $nominee ? $this->nomineeArray($nominee) : null,
            'nominator' => $nominator ? [
                'id' => (int) $nominator->id,
                'username' => $nominator->username,
                'nickname' => $this->displayName($nominator),
            ] : null,
            'status' => $nomination->status,
            'created_at' => $nomination->created_at ? $nomination->created_at->toDateTimeString() : null,
        ];
    }

    protected function dateStr($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return substr((string) $value, 0, 19);
    }

    protected function electionArray(Election $election, bool $withNominations = false): array
    {
        $out = [
            'id' => (int) $election->id,
            'title' => $election->title,
            'seat_count' => (int) $election->seat_count,
            'status' => $election->status,
            'status_label' => Election::STATUS_LABELS[$election->status] ?? $election->status,
            'nomination_ends_at' => $this->dateStr($election->nomination_ends_at),
            'publicity_ends_at' => $this->dateStr($election->publicity_ends_at),
            'vote_ends_at' => $this->dateStr($election->vote_ends_at),
            'publicity_text' => $election->publicity_text,
            'discussion_id' => $election->discussion_id !== null ? (int) $election->discussion_id : null,
            'poll_id' => $election->poll_id !== null ? (int) $election->poll_id : null,
            'result_discussion_id' => $election->result_discussion_id !== null ? (int) $election->result_discussion_id : null,
            'created_by' => (int) $election->created_by,
            'created_at' => $election->created_at ? $election->created_at->toDateTimeString() : null,
        ];

        if ($withNominations) {
            $out['nominations'] = $election->nominations()->with(['nominee', 'nominator'])->get()
                ->map(function (ElectionNomination $n) { return $this->nominationArray($n); })->all();
            $out['candidate_count'] = $election->candidates()->count();

            if ($election->poll_id) {
                $out['vote_counts'] = $this->voteCounts($election);
            }

            if ($election->result_data) {
                $decoded = json_decode((string) $election->result_data, true);
                $out['result'] = is_array($decoded) ? $decoded : null;
            }
        } else {
            $out['candidate_count'] = $election->candidates()->count();
        }

        return $out;
    }

    /**
     * Live vote counts per nominee id, from the poll's votes mapped back
     * through the stored option map. Candidates with no votes read as 0.
     */
    protected function voteCounts(Election $election): array
    {
        $map = $election->poll_option_map ? json_decode((string) $election->poll_option_map, true) : null;

        if (! is_array($map)) {
            return [];
        }

        $optionToNominee = [];

        foreach ($map as $row) {
            if (isset($row['option_id'], $row['nominee_id'])) {
                $optionToNominee[(int) $row['option_id']] = (int) $row['nominee_id'];
            }
        }

        $counts = [];

        foreach ($election->nominations()->where('status', ElectionNomination::STATUS_CANDIDATE)->get() as $nom) {
            $counts[(int) $nom->nominee_id] = 0;
        }

        if ($election->poll_id) {
            $rows = \FoF\Polls\PollVote::where('poll_id', (int) $election->poll_id)
                ->selectRaw('option_id, COUNT(*) AS c')->groupBy('option_id')->get();

            foreach ($rows as $row) {
                $nomineeId = $optionToNominee[(int) $row->option_id] ?? null;

                if ($nomineeId !== null) {
                    $counts[$nomineeId] = (int) $row->c;
                }
            }
        }

        return $counts;
    }
}
