<?php

namespace Hooey\Election\Api\Controllers;

use Carbon\Carbon;
use Flarum\User\User;
use FoF\Polls\Poll;
use Hooey\Election\Election;
use Hooey\Election\ElectionNomination;
use Hooey\Election\ElectionService;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stage progression and result publication. All three actions are
 * admin-or-committee (group 7); progression is a button press, never a
 * cron, and stage dates are display-only except vote_ends_at, which is
 * copied to the ballot poll's end_date and gates publication.
 */
class ElectionActionController implements RequestHandlerInterface
{
    use ElectionApiHelper;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $election = $this->visibleElection($actor, $this->param($request, 'id'));

        if (! $election) {
            return $this->error('选举不存在。', 404);
        }

        $path = $request->getUri()->getPath();

        if (str_ends_with($path, '/publicity')) {
            return $this->publicity($request, $actor, $election);
        }

        if (str_ends_with($path, '/advance')) {
            return $this->advance($request, $actor, $election);
        }

        return $this->publish($request, $actor, $election);
    }

    protected function requireManager(User $actor): void
    {
        if (! $this->isManager($actor)) {
            throw new \Flarum\User\Exception\PermissionDeniedException();
        }
    }

    /** Publicity board text: admin or group 7, publicity stage only. */
    protected function publicity(ServerRequestInterface $request, User $actor, Election $election): ResponseInterface
    {
        $this->requireManager($actor);

        if ($election->status !== Election::STATUS_PUBLICITY) {
            return $this->error('只有公示期可以填写公示牌。');
        }

        $body = $this->body($request);

        if (! array_key_exists('publicity_text', $body)) {
            return $this->error('公示牌内容不能为空。');
        }

        $election->publicity_text = (string) $body['publicity_text'];
        $election->save();

        return new JsonResponse(['data' => $this->electionArray($election, true)]);
    }

    protected function advance(ServerRequestInterface $request, User $actor, Election $election): ResponseInterface
    {
        $this->requireManager($actor);

        if ($election->status === Election::STATUS_NOMINATION) {
            $election->status = Election::STATUS_PUBLICITY;
            $election->save();

            return new JsonResponse(['data' => $this->electionArray($election, true)]);
        }

        if ($election->status === Election::STATUS_PUBLICITY) {
            return $this->startVoting($actor, $election);
        }

        if ($election->status === Election::STATUS_VOTING) {
            return $this->error('投票进行中，请通过公布结果结束选举。');
        }

        return $this->error('选举已结束。');
    }

    protected function startVoting(User $actor, Election $election): ResponseInterface
    {
        $candidates = $election->candidates()->with('nominee')->get();

        if ($candidates->isEmpty()) {
            return $this->error('暂无候选人，无法开始投票。');
        }

        $endDate = $election->vote_ends_at ? new Carbon($election->vote_ends_at) : null;

        if ($endDate === null || $endDate->isPast()) {
            return $this->error('投票截止时间须在未来。');
        }

        $items = [];
        $lines = [];

        foreach ($candidates as $nomination) {
            $name = $this->displayName($nomination->nominee);
            $items[] = ['nominee_id' => (int) $nomination->nominee_id, 'name' => $name];
            $lines[] = '<li>'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'</li>';
        }

        $content = '<p>业委会选举投票：'.htmlspecialchars($election->title, ENT_QUOTES, 'UTF-8')
            .'，席位 '.(int) $election->seat_count.' 名，投票截止 '
            .htmlspecialchars($endDate->format('Y-m-d H:i'), ENT_QUOTES, 'UTF-8')
            .'。候选人：</p><ul>'.implode('', $lines).'</ul>'
            .'<p>业委会投票仅认证住户可投，每人限投 1 票，截止前可改投。</p>';

        $discussion = ElectionService::createDiscussion(
            $actor,
            '业委会选举投票：'.$election->title,
            $content
        );

        [$poll, $optionMap] = ElectionService::createPoll($actor, $discussion, $endDate, $items);

        $election->discussion_id = $discussion->id;
        $election->poll_id = $poll->id;
        $election->poll_option_map = json_encode($optionMap);
        $election->status = Election::STATUS_VOTING;
        $election->save();

        return new JsonResponse(['data' => $this->electionArray($election, true)]);
    }

    protected function publish(ServerRequestInterface $request, User $actor, Election $election): ResponseInterface
    {
        $this->requireManager($actor);

        // Repeat publication is idempotent: same stored result, no new
        // post, no group changes.
        if ($election->status === Election::STATUS_ENDED) {
            return new JsonResponse(['data' => $this->electionArray($election, true)]);
        }

        if ($election->status !== Election::STATUS_VOTING) {
            return $this->error('未进入投票期，无法公布结果。');
        }

        $poll = $election->poll_id ? Poll::find((int) $election->poll_id) : null;

        if (! $poll || ! $poll->hasEnded()) {
            return $this->error('投票尚未截止，无法公布结果。');
        }

        $counts = $this->voteCounts($election);

        $candidates = [];
        foreach ($election->candidates()->with('nominee')->get() as $nomination) {
            $candidates[] = [
                'nominee_id' => (int) $nomination->nominee_id,
                'name' => $this->displayName($nomination->nominee),
                'nominated_at' => $nomination->created_at ? $nomination->created_at->toDateTimeString() : null,
                'nomination_id' => (int) $nomination->id,
            ];
        }

        $ranking = ElectionService::rankCandidates($candidates, $counts, (int) $election->seat_count);
        $winnerIds = array_map(function ($w) { return (int) $w['nominee_id']; }, $ranking['winners']);

        $winnerLines = [];
        foreach ($ranking['winners'] as $w) {
            $winnerLines[] = '<li>'.htmlspecialchars($w['name'], ENT_QUOTES, 'UTF-8').'（'.$w['votes'].' 票）</li>';
        }

        $rankLines = [];
        foreach ($ranking['ranked'] as $i => $r) {
            $rankLines[] = '<li>第 '.($i + 1).' 名：'.htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8').'（'.$r['votes'].' 票）</li>';
        }

        $content = '<p>业委会选举结果公示：'.htmlspecialchars($election->title, ENT_QUOTES, 'UTF-8')
            .'，席位 '.(int) $election->seat_count.' 名。</p>'
            .'<p>当选名单：</p><ul>'.implode('', $winnerLines).'</ul>'
            .'<p>全部候选人得票：</p><ul>'.implode('', $rankLines).'</ul>';

        if ($ranking['tie_at_cutoff']) {
            $content .= '<p>说明：第 '.(int) $election->seat_count.' 名出现平票，按最早提名者优先确定当选。</p>';
        }

        $content .= '<p><a href="/d/'.(int) $election->discussion_id.'">投票帖</a></p>';

        $resultDiscussion = ElectionService::createDiscussion(
            $actor,
            '业委会选举结果公示：'.$election->title,
            $content,
            true
        );

        $sync = ElectionService::syncCommitteeGroup($winnerIds);

        $resultData = [
            'winners' => $ranking['winners'],
            'ranked' => $ranking['ranked'],
            'tie_at_cutoff' => $ranking['tie_at_cutoff'],
            'published_at' => Carbon::now()->toDateTimeString(),
            'discussion_id' => (int) $election->discussion_id,
            'poll_id' => (int) $election->poll_id,
            'group_sync' => $sync,
        ];

        $election->result_discussion_id = $resultDiscussion->id;
        $election->result_data = json_encode($resultData);
        $election->status = Election::STATUS_ENDED;
        $election->save();

        $data = $this->electionArray($election, true);
        $data['publish'] = $resultData;

        return new JsonResponse(['data' => $data]);
    }
}
