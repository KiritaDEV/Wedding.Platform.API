<?php

namespace App\Actions\Invitations;

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
        $this->validateRole($event, $filters['wedding_role_id'] ?? null);

        $query = $event->invitations()->getQuery()->with(['guests.weddingRoles']);
        $this->applySearch($query, $filters['q'] ?? '');
        $this->applyLifecycle($query, $filters['lifecycle'] ?? 'all');
        $this->applyGuestFilters($query, $filters);
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
        $active = isset($filters['relationship']) || isset($filters['side'])
            || isset($filters['wedding_role_id']) || isset($filters['rsvp']);
        if (! $active) {
            return;
        }

        $query->whereHas('guests', function (Builder $guest) use ($filters): void {
            if (isset($filters['relationship'])) {
                $guest->where('relationship', $filters['relationship']);
            }
            if (isset($filters['side'])) {
                $guest->where('side', $filters['side']);
            }
            if (isset($filters['wedding_role_id'])) {
                $guest->whereHas('weddingRoles', fn (Builder $roles) => $roles->whereKey($filters['wedding_role_id']));
            }
            if (isset($filters['rsvp']) && $filters['rsvp'] !== 'pending') {
                $guest->whereRaw('0 = 1');
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

        // Until RSVP persistence exists, every last response is null. The stable
        // no-response fallback for both last-response modes is recently added.
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
            (SELECT COUNT(*) FROM guests g INNER JOIN invitations active_i ON active_i.id = g.invitation_id WHERE active_i.event_id = ? AND active_i.status = 'active') AS active_guests",
            [$event->id],
        )->first();
        $activeInvitations = (int) $counts->active_invitations;
        $guests = (int) $counts->active_guests;

        return [
            'activeInvitations' => $activeInvitations,
            'guests' => $guests,
            'attending' => 0,
            'declined' => 0,
            'pending' => $guests,
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

    private function validateRole(Event $event, ?string $roleId): void
    {
        if ($roleId === null) {
            return;
        }

        $available = WeddingRole::query()->whereKey($roleId)
            ->where(fn (Builder $query) => $query->whereNull('event_id')->orWhere('event_id', $event->id))
            ->exists();
        if (! $available) {
            throw ValidationException::withMessages(['weddingRoleId' => 'The selected Wedding Role is unavailable for this Event.']);
        }
    }
}
