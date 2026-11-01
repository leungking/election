<?php

namespace Hooey\Election;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Discussion\Event\Started;
use Flarum\Post\CommentPost;
use Flarum\User\User;
use FoF\Polls\Poll;
use FoF\Polls\PollOption;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * System discussions for elections, built with core models only (this
 * plugin never touches core code, it only uses core's public models and
 * events, so upgrades stay safe):
 *
 * - Discussion title mutator sets the slug automatically.
 * - CommentPost creation auto-assigns the in-discussion number and
 *   raises the Posted event; the approval Saving hook only runs in the
 *   API resource layer, so these admin/committee-authored system posts
 *   keep the approved/public defaults (is_approved=1, is_private=0).
 * - Dispatching Started after tags are attached lets the tag metadata
 *   listeners count the discussion like any normally started one.
 */
class ElectionService
{
    public const COMMITTEE_TAG_ID = 7;

    public static function createDiscussion(User $actor, string $title, string $htmlContent, bool $sticky = false): Discussion
    {
        $discussion = new Discussion();
        $discussion->title = $title;
        $discussion->user_id = $actor->id;
        $discussion->created_at = Carbon::now();
        $discussion->comment_count = 0;
        $discussion->save();

        $post = new CommentPost();
        $post->discussion_id = $discussion->id;
        $post->user_id = $actor->id;
        $post->content = $htmlContent;
        $post->created_at = Carbon::now();
        $post->save();

        $discussion->setFirstPost($post);
        $discussion->setLastPost($post);
        $discussion->refreshCommentCount();

        if ($sticky) {
            $discussion->is_sticky = true;
        }

        $discussion->tags()->attach(self::COMMITTEE_TAG_ID);
        $discussion->save();

        resolve(Dispatcher::class)->dispatch(new Started($discussion, $actor));

        return $discussion->fresh();
    }

    /**
     * Create the ballot poll on a discussion's first post. Settings mirror
     * the site's existing single-choice polls: private ballots, one vote,
     * changeable until the deadline.
     *
     * @param array $candidates [['nominee_id' => int, 'name' => string], ...]
     * @return array [Poll $poll, array $optionMap]
     */
    public static function createPoll(User $actor, Discussion $discussion, Carbon $endDate, array $candidates): array
    {
        $poll = Poll::build(
            '你支持哪位候选人担任业委会委员？',
            (int) $discussion->first_post_id,
            (int) $actor->id,
            $endDate,
            false, // public_poll
            false, // allow_multiple_votes
            1,     // max_votes
            false, // hide_votes
            true   // allow_change_vote
        );
        $poll->save();

        $optionMap = [];

        foreach ($candidates as $candidate) {
            $option = PollOption::build((string) $candidate['name']);
            $poll->options()->save($option);
            $optionMap[] = ['option_id' => (int) $option->id, 'nominee_id' => (int) $candidate['nominee_id']];
        }

        return [$poll, $optionMap];
    }

    /**
     * Rank candidates by votes (desc), then earliest nomination first
     * (created_at, then id) — the deterministic tie-break. Returns the
     * full ranking plus the winner list.
     *
     * @param array $candidates [['nominee_id','name','nominated_at','nomination_id'], ...]
     * @param array $counts [nominee_id => votes]
     */
    public static function rankCandidates(array $candidates, array $counts, int $seatCount): array
    {
        $ranked = [];

        foreach ($candidates as $candidate) {
            $ranked[] = [
                'nominee_id' => (int) $candidate['nominee_id'],
                'name' => (string) $candidate['name'],
                'votes' => (int) ($counts[(int) $candidate['nominee_id']] ?? 0),
                'nominated_at' => $candidate['nominated_at'],
                'nomination_id' => (int) $candidate['nomination_id'],
            ];
        }

        usort($ranked, function (array $a, array $b) {
            if ($a['votes'] !== $b['votes']) {
                return $b['votes'] <=> $a['votes'];
            }

            $ta = $a['nominated_at'] ?? '';
            $tb = $b['nominated_at'] ?? '';

            if ($ta !== $tb) {
                return strcmp($ta, $tb);
            }

            return $a['nomination_id'] <=> $b['nomination_id'];
        });

        $winners = array_slice($ranked, 0, max(0, $seatCount));

        // Tie exactly at the cutoff (the Nth and N+1th share votes).
        $tieAtCutoff = false;

        if ($seatCount > 0 && count($ranked) > $seatCount) {
            $tieAtCutoff = $ranked[$seatCount - 1]['votes'] === $ranked[$seatCount]['votes'];
        }

        return ['ranked' => $ranked, 'winners' => $winners, 'tie_at_cutoff' => $tieAtCutoff];
    }

    /**
     * Sync group 7 to exactly the winner list: grant winners, remove
     * every current group-7 member who did not win (losers and old
     * members alike). Winners keep group 6 (untouched here).
     *
     * @param int[] $winnerIds
     * @return array ['granted' => int[], 'removed' => int[]]
     */
    public static function syncCommitteeGroup(array $winnerIds): array
    {
        $winnerIds = array_values(array_unique(array_map('intval', $winnerIds)));

        $current = User::whereHas('groups', function ($q) {
            $q->where('groups.id', 7);
        })->pluck('users.id')->all();
        $current = array_map('intval', $current);

        $toRemove = array_values(array_diff($current, $winnerIds));
        $toGrant = array_values(array_diff($winnerIds, $current));

        foreach ($toRemove as $userId) {
            $user = User::find($userId);

            if ($user) {
                $user->groups()->detach(7);
            }
        }

        foreach ($toGrant as $userId) {
            $user = User::find($userId);

            if ($user) {
                $user->groups()->syncWithoutDetaching([7]);
            }
        }

        return ['granted' => $toGrant, 'removed' => $toRemove];
    }
}
