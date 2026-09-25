<?php

namespace App\Actions\Invitations;

use App\Enums\GuestStatus;
use App\Invitations\InvitationName;
use App\Invitations\LikePattern;
use App\Invitations\NameNormalizer;
use App\Models\Event;
use App\Models\WeddingRole;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ListInvitations
{
    public const PAGE_SIZE = 25;

    /** @return array{invitations: LengthAwarePaginator, summary: array, lifecycleCounts: array} */
    public function handle(Event $event, array $filters): array
    {
        $this->validateRoles($event, $filters['role_ids'] ?? []);

        $query = $event->invitations()->getQuery()
            ->with(['guests' => fn ($guests) => $guests->with('weddingRoles')->withExists('rsvpSubmissionItems')])
            ->withExists(['rsvpSubmissions', 'accessTransferRequests', 'currentBrowserCredential', 'activeAccessTransferRequest'])
            ->withMax('rsvpSubmissions', 'created_at');
        $this->applySearch($query, $filters['q'] ?? '');
        $this->applyLifecycle($query, $filters['lifecycle'] ?? 'all');
        $this->applyGuestFilters($query, $filters);
        $this->applyRsvpStatuses($query, $filters['rsvp_statuses'] ?? []);
        $this->applySort($query, $filters['sort'] ?? 'recently_added');

        return [
            'invitations' => $query->paginate(self::PAGE_SIZE)->withQueryString(),
            'summary' => $this->summary($event),
            'lifecycleCounts' => $this->lifecycleCounts($event),
        ];
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $identity = NameNormalizer::identity($search);
        $pattern = LikePattern::contains($identity);
        $query->where(function (Builder $query) use ($pattern): void {
            $query->whereRaw(LikePattern::clause('LOWER(custom_name)'), [$pattern])
                ->orWhereHas('guests', function (Builder $guests) use ($pattern): void {
                    $guests->where(function (Builder $match) use ($pattern): void {
                        $match->whereRaw(LikePattern::clause('normalized_first_name'), [$pattern])
                            ->orWhereRaw(LikePattern::clause('normalized_last_name'), [$pattern])
                            ->orWhereRaw(LikePattern::clause($this->fullNameSql()), [$pattern])
                            ->orWhereHas('weddingRoles', fn (Builder $roles) => $roles->whereRaw(LikePattern::clause('normalized_name'), [$pattern]));
                    });
                });
        });
    }

    private function applyLifecycle(Builder $query, string $lifecycle): void
    {
        if ($lifecycle !== 'all') {
            $query->where('status', $lifecycle);
        }
    }

    private function applyGuestFilters(Builder $query, array $filters): void
    {
        foreach ([['relationships', 'relationship'], ['sides', 'side']] as [$filter, $column]) {
            if (($filters[$filter] ?? []) !== []) {
                $query->whereHas('guests', fn (Builder $guests) => $guests
                    ->where('status', GuestStatus::Active->value)->whereIn($column, $filters[$filter]));
            }
        }
        if (($filters['role_ids'] ?? []) !== []) {
            $query->whereHas('guests', fn (Builder $guests) => $guests
                ->where('status', GuestStatus::Active->value)
                ->whereHas('weddingRoles', fn (Builder $roles) => $roles->whereKey($filters['role_ids'])));
        }
        if (($filters['guest_responses'] ?? []) !== []) {
            $responses = $filters['guest_responses'];
            $query->whereHas('guests', function (Builder $guests) use ($responses): void {
                $guests->where('status', GuestStatus::Active->value)
                    ->where(function (Builder $responsesQuery) use ($responses): void {
                        $values = array_values(array_diff($responses, ['pending']));
                        if ($values !== []) {
                            $responsesQuery->whereIn('rsvp_response', $values);
                        }
                        if (in_array('pending', $responses, true)) {
                            $values === [] ? $responsesQuery->whereNull('rsvp_response') : $responsesQuery->orWhereNull('rsvp_response');
                        }
                    });
            });
        }
    }

    /** @param list<string> $statuses */
    private function applyRsvpStatuses(Builder $query, array $statuses): void
    {
        if ($statuses === []) {
            return;
        }

        $pendingGuests = fn (Builder $guests) => $guests
            ->where('status', GuestStatus::Active->value)->whereNull('rsvp_response');
        $answeredGuests = fn (Builder $guests) => $guests
            ->where('status', GuestStatus::Active->value)->whereNotNull('rsvp_response');

        $query->where(function (Builder $statusQuery) use ($statuses, $pendingGuests, $answeredGuests): void {
            foreach ($statuses as $status) {
                $statusQuery->orWhere(function (Builder $candidate) use ($status, $pendingGuests, $answeredGuests): void {
                    match ($status) {
                        'pending' => $candidate->whereDoesntHave('guests', $answeredGuests),
                        'partial' => $candidate->whereHas('guests', $pendingGuests)->whereHas('guests', $answeredGuests),
                        'complete' => $candidate->whereDoesntHave('guests', $pendingGuests),
                    };
                });
            }
        });
    }

    private function applySort(Builder $query, string $sort): void
    {
        if (str_starts_with($sort, 'invitation_')) {
            $query->orderByRaw(InvitationName::caseInsensitiveSortExpression(DB::connection()->getDriverName()).' '.($sort === 'invitation_asc' ? 'ASC' : 'DESC'))
                ->orderBy('invitations.id');

            return;
        }

        if (str_starts_with($sort, 'last_response_')) {
            $direction = $sort === 'last_response_asc' ? 'ASC' : 'DESC';
            $query->orderByRaw('(SELECT MAX(created_at) FROM rsvp_submissions WHERE invitation_id = invitations.id) '.$direction)
                ->orderByDesc('invitations.created_at')->orderByDesc('invitations.id');

            return;
        }

        $query->orderByDesc('invitations.created_at')->orderByDesc('invitations.id');
    }

    private function fullNameSql(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? "TRIM(CONCAT(normalized_first_name, ' ', normalized_last_name))"
            : "TRIM(normalized_first_name || ' ' || normalized_last_name)";
    }

    private function summary(Event $event): array
    {
        $counts = DB::table('invitations')->where('event_id', $event->id)->selectRaw(
            "SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_invitations,
            (SELECT COUNT(*) FROM guests g INNER JOIN invitations active_i ON active_i.id = g.invitation_id WHERE active_i.event_id = ? AND active_i.status = 'active' AND g.status = 'active') AS active_guests,
            (SELECT COUNT(*) FROM guests g INNER JOIN invitations active_i ON active_i.id = g.invitation_id WHERE active_i.event_id = ? AND active_i.status = 'active' AND g.status = 'active' AND g.rsvp_response = 'attending') AS attending,
            (SELECT COUNT(*) FROM guests g INNER JOIN invitations active_i ON active_i.id = g.invitation_id WHERE active_i.event_id = ? AND active_i.status = 'active' AND g.status = 'active' AND g.rsvp_response = 'declined') AS declined,
            (SELECT COUNT(*) FROM guests g INNER JOIN invitations active_i ON active_i.id = g.invitation_id WHERE active_i.event_id = ? AND active_i.status = 'active' AND g.status = 'active' AND g.rsvp_response IS NULL) AS pending",
            [$event->id, $event->id, $event->id, $event->id],
        )->first();
        $activeInvitations = (int) $counts->active_invitations;
        $guests = (int) $counts->active_guests;

        return [
            'activeInvitations' => $activeInvitations,
            'guests' => $guests,
            'attending' => (int) $counts->attending,
            'declined' => (int) $counts->declined,
            'pending' => (int) $counts->pending,
        ];
    }

    private function lifecycleCounts(Event $event): array
    {
        $counts = $event->invitations()->selectRaw(
            "COUNT(*) AS aggregate_all, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS aggregate_active, SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS aggregate_inactive",
        )->first();

        return [
            'all' => (int) $counts->aggregate_all,
            'active' => (int) $counts->aggregate_active,
            'inactive' => (int) $counts->aggregate_inactive,
        ];
    }

    /** @param list<string> $roleIds */
    private function validateRoles(Event $event, array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }

        $available = WeddingRole::query()->whereKey($roleIds)
            ->where(fn (Builder $query) => $query->whereNull('event_id')->orWhere('event_id', $event->id))
            ->count();
        if ($available !== count($roleIds)) {
            throw ValidationException::withMessages(['roleIds' => 'A selected Wedding Role is unavailable for this Event.']);
        }
    }
}
