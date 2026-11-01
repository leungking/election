<?php

namespace Hooey\Election\Api\Controllers;

use Flarum\User\User;
use Hooey\Election\Election;
use Hooey\Election\ElectionNomination;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Nomination (any group-6 user, no admin approval: eligibility is the
 * nominee's group-6 membership itself) and candidate removal (admin /
 * group 7, publicity stage only; rows keep their status, never deleted).
 */
class ElectionNominationController implements RequestHandlerInterface
{
    use ElectionApiHelper;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $election = $this->visibleElection($actor, $this->param($request, 'id'));

        if (! $election) {
            return $this->error('选举不存在。', 404);
        }

        $nid = $this->param($request, 'nid');

        if ($nid !== null) {
            return $this->remove($request, $actor, $election, (int) $nid);
        }

        return $this->nominate($request, $actor, $election);
    }

    protected function nominate(ServerRequestInterface $request, User $actor, Election $election): ResponseInterface
    {
        if ($election->status !== Election::STATUS_NOMINATION) {
            return $this->error('不在提名期，无法提名。');
        }

        if (! $this->isResident($actor)) {
            throw new \Flarum\User\Exception\PermissionDeniedException();
        }

        $body = $this->body($request);
        $nomineeId = (int) ($body['nominee_id'] ?? 0);

        if ($nomineeId <= 0) {
            return $this->error('被提名人无效。');
        }

        $nominee = User::find($nomineeId);

        if (! $nominee) {
            return $this->error('被提名人不存在。');
        }

        if (! in_array(self::RESIDENT_GROUP_ID, array_map('intval', $nominee->groups->pluck('id')->all()), true)) {
            return $this->error('被提名人须为认证住户。');
        }

        // One candidate slot per person: repeat nominations dedupe.
        $existing = ElectionNomination::where('election_id', $election->id)
            ->where('nominee_id', $nomineeId)
            ->first();

        if ($existing) {
            if ($existing->status !== ElectionNomination::STATUS_CANDIDATE) {
                return $this->error('该候选人已在公示期被移除。');
            }

            return new JsonResponse(['data' => $this->nominationArray($existing)]);
        }

        $nomination = new ElectionNomination();
        $nomination->election_id = $election->id;
        $nomination->nominator_id = $actor->id;
        $nomination->nominee_id = $nomineeId;
        $nomination->status = ElectionNomination::STATUS_CANDIDATE;
        $nomination->save();

        return new JsonResponse(['data' => $this->nominationArray($nomination)], 201);
    }

    protected function remove(ServerRequestInterface $request, User $actor, Election $election, int $nid): ResponseInterface
    {
        if (! $this->isManager($actor)) {
            throw new \Flarum\User\Exception\PermissionDeniedException();
        }

        if ($election->status !== Election::STATUS_PUBLICITY) {
            return $this->error('只有公示期可以移除候选人。');
        }

        $nomination = ElectionNomination::where('id', $nid)
            ->where('election_id', $election->id)
            ->first();

        if (! $nomination) {
            return $this->error('提名不存在。', 404);
        }

        if ($nomination->status !== ElectionNomination::STATUS_CANDIDATE) {
            return $this->error('该候选人已被移除。');
        }

        $nomination->status = ElectionNomination::STATUS_REMOVED;
        $nomination->save();

        return new JsonResponse(['data' => $this->nominationArray($nomination)]);
    }
}
