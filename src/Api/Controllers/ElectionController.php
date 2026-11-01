<?php

namespace Hooey\Election\Api\Controllers;

use Carbon\Carbon;
use Hooey\Election\Election;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ElectionController implements RequestHandlerInterface
{
    use ElectionApiHelper;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $method = strtoupper($request->getMethod());

        if (! $this->canViewCommittee($actor)) {
            throw new \Flarum\User\Exception\PermissionDeniedException();
        }

        if ($method === 'POST') {
            return $this->store($request, $actor);
        }

        $id = $this->param($request, 'id');

        if ($id !== null) {
            $election = $this->visibleElection($actor, $id);

            if (! $election) {
                return $this->error('选举不存在。', 404);
            }

            return new JsonResponse(['data' => $this->electionArray($election, true)]);
        }

        $elections = Election::orderBy('id', 'desc')->get()->map(function (Election $e) {
            return $this->electionArray($e, false);
        })->all();

        return new JsonResponse(['data' => $elections]);
    }

    protected function store(ServerRequestInterface $request, $actor): ResponseInterface
    {
        if (! $this->isManager($actor)) {
            throw new \Flarum\User\Exception\PermissionDeniedException();
        }

        $body = $this->body($request);
        $title = trim((string) ($body['title'] ?? ''));

        if ($title === '' || mb_strlen($title) > 200) {
            return $this->error('选举标题不能为空且不超过 200 字。');
        }

        $seatCount = (int) ($body['seat_count'] ?? 0);

        if ($seatCount < 1) {
            return $this->error('席位数须为正整数。');
        }

        $voteEndsAt = $this->parseDateTime($body['vote_ends_at'] ?? null);

        if ($voteEndsAt === null) {
            return $this->error('投票截止时间格式无效。');
        }

        if ($voteEndsAt->isPast()) {
            return $this->error('投票截止时间须在未来。');
        }

        $election = new Election();
        $election->title = $title;
        $election->seat_count = $seatCount;
        $election->status = Election::STATUS_NOMINATION;
        $election->nomination_ends_at = $this->parseDateTime($body['nomination_ends_at'] ?? null);
        $election->publicity_ends_at = $this->parseDateTime($body['publicity_ends_at'] ?? null);
        $election->vote_ends_at = $voteEndsAt;
        $election->created_by = $actor->id;
        $election->save();

        return new JsonResponse(['data' => $this->electionArray($election, true)], 201);
    }

    protected function parseDateTime($value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new Carbon((string) $value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
