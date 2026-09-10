<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Collection;

/**
 * Turns the `@name` tokens in a comment body into the users they actually refer to.
 *
 * The important half of this class is what it refuses to resolve. A mention is a
 * notification channel into somebody's inbox, and a comment is written by whoever can
 * comment — so resolution is closed over the people who are already members of the project
 * the comment sits on (or of the workspace, for comments that hang off no project). Typing
 * `@ceo` on a client project cannot reach the CEO if they were never added to it: the token
 * simply resolves to nobody and stays inert text.
 *
 * Matching is deliberately generous *within* that closed set — a full email address, the
 * part before the at sign, the name with its spaces taken out, the name dotted, and a first
 * name that belongs to only one member — because a wrong guess there can only ever reach
 * somebody who was already entitled to read the comment.
 */
final class MentionResolver
{
    /**
     * `@` followed by a name-ish token, optionally a whole email address. The lookbehind
     * stops `user@example.com` in running text from being read as a mention of `example`.
     */
    private const TOKEN_PATTERN = '/(?<![\p{L}\p{N}_@.\-])@([\p{L}\p{N}][\p{L}\p{N}._%+\-]{0,62})/u';

    /** Mentioning more than this in one comment is a mailing list, not a conversation. */
    private const MAX_MENTIONS = 25;

    /**
     * Every member named in the body, in the order they were first mentioned.
     *
     * @return Collection<int, User>
     */
    public function __invoke(
        string $body,
        Workspace $workspace,
        ?Project $project = null,
        ?User $exclude = null,
    ): Collection {
        $tokens = self::tokens($body);

        if ($tokens === []) {
            /** @var Collection<int, User> $empty */
            $empty = new Collection;

            return $empty;
        }

        $candidates = $this->candidates($workspace, $project, $exclude);

        if ($candidates->isEmpty()) {
            /** @var Collection<int, User> $empty */
            $empty = new Collection;

            return $empty;
        }

        $index = self::index($candidates);
        $resolved = [];

        foreach ($tokens as $token) {
            foreach ($index[$token] ?? [] as $userId) {
                $resolved[$userId] = true;
            }

            if (count($resolved) >= self::MAX_MENTIONS) {
                break;
            }
        }

        return $candidates
            ->filter(static fn (User $user): bool => isset($resolved[(int) $user->getKey()]))
            ->values()
            ->take(self::MAX_MENTIONS);
    }

    /**
     * The distinct lower-cased tokens in the body, in the order they appear.
     *
     * @return list<string>
     */
    public static function tokens(string $body): array
    {
        // Mentions are written as text; reading them out of the markup would let an author
        // hide one inside an attribute where no reader can see who they pinged.
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match_all(self::TOKEN_PATTERN, $text, $matches) === 0) {
            return [];
        }

        $tokens = [];

        foreach ($matches[1] as $token) {
            // Sentence punctuation is not part of a name: "thanks @jane." mentions jane.
            $token = mb_strtolower(rtrim($token, '._-'), 'UTF-8');

            if ($token !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * The people a mention in this comment is allowed to reach.
     *
     * @return Collection<int, User>
     */
    private function candidates(Workspace $workspace, ?Project $project, ?User $exclude): Collection
    {
        $memberIds = $project instanceof Project
            ? ProjectMember::query()
                ->where('project_id', $project->getKey())
                ->pluck('user_id')
            : WorkspaceMember::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->getKey())
                ->pluck('user_id');

        if ($exclude !== null) {
            $memberIds = $memberIds->reject(
                static fn (mixed $id): bool => (int) $id === (int) $exclude->getKey(),
            );
        }

        if ($memberIds->isEmpty()) {
            /** @var Collection<int, User> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->whereIn('id', $memberIds->all())
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        return $users;
    }

    /**
     * Token to user ids. One user answers to several spellings, and a spelling may be
     * shared by two people — both of whom are in the same project, so notifying both is
     * the safe reading.
     *
     * @param Collection<int, User> $candidates
     * @return array<string, list<int>>
     */
    private static function index(Collection $candidates): array
    {
        $index = [];
        $firstNames = [];

        foreach ($candidates as $user) {
            $id = (int) $user->getKey();
            $email = mb_strtolower((string) $user->email, 'UTF-8');
            $name = trim(preg_replace('/\s+/u', ' ', (string) $user->name) ?? '');
            $lowerName = mb_strtolower($name, 'UTF-8');

            $spellings = [$email];

            $localPart = strstr($email, '@', true);

            if (is_string($localPart) && $localPart !== '') {
                $spellings[] = $localPart;
            }

            if ($lowerName !== '') {
                $spellings[] = str_replace(' ', '', $lowerName);
                $spellings[] = str_replace(' ', '.', $lowerName);
                $spellings[] = str_replace(' ', '-', $lowerName);
                $spellings[] = str_replace(' ', '_', $lowerName);

                $first = mb_strtolower((string) strtok($name, ' '), 'UTF-8');

                if ($first !== '') {
                    $firstNames[$first][] = $id;
                }
            }

            foreach ($spellings as $spelling) {
                if ($spelling === '') {
                    continue;
                }

                $index[$spelling][] = $id;
            }
        }

        // A bare first name only resolves when it belongs to exactly one member: with two
        // Janes on the project, "@jane" names neither of them.
        foreach ($firstNames as $first => $ids) {
            if (count(array_unique($ids)) === 1 && ! isset($index[$first])) {
                $index[$first] = $ids;
            }
        }

        foreach ($index as $spelling => $ids) {
            $index[$spelling] = array_values(array_unique($ids));
        }

        return $index;
    }
}
