<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/v1/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Hiring's part of the unified calendar (calendar-feed/v1):
 *   birthday          active employees, on the day THIS year — day and month only
 *   last_working_day  separations with a `completed` decision
 *   hiring_need       hiring requests, on their desired start date
 *
 * THIS DATABASE HOLDS SSN, BANK DETAILS, CONTACTS, ADDRESSES AND PAY. Every query
 * below names its columns; no model, resource or `*` is used, so nothing can
 * reach the response by accident. Never sent: birth year or age, separation
 * type or reason, letters, notes, candidates.
 *
 * Who may see which stores is pizzasys's decision: the auth middleware forwards
 * the query string and the rule for this route reads `stores` from it (policy
 * "all"). We only refuse numbers we do not know, because pizzasys silently drops
 * those instead of refusing them.
 */
class CalendarFeedController extends Controller
{
    private const MAX_STORES = 50;

    private const MAX_DAYS = 62;

    /** The latest status that means "still working here". */
    private const ACTIVE = ['hired', 'rehired', 'OJE'];

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $numbers = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($numbers === [] || count($numbers) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Date range may not exceed ' . self::MAX_DAYS . ' days.']);
        }

        // id => store_number
        $stores = DB::table('stores')->whereIn('store_number', $numbers)->pluck('store_number', 'id');
        $unknown = array_values(array_diff($numbers, $stores->values()->all()));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['stores' => 'Unknown store: ' . implode(', ', $unknown) . '.']);
        }

        $from = Carbon::parse($data['from'], 'UTC')->startOfDay();
        $to = Carbon::parse($data['to'], 'UTC')->startOfDay();

        $events = array_merge(
            $this->birthdays($stores, $from, $to),
            $this->lastWorkingDays($stores, $from, $to),
            $this->hiringNeeds($stores, $from, $to),
        );

        usort($events, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        return response()->json([
            'contract'     => 'calendar-feed/v1',
            'source'       => 'hiring',
            'generated_at' => $this->utc(now()),
            'range'        => ['from' => $data['from'], 'to' => $data['to']],
            'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $numbers),
            'events'       => $events,
            'meta'         => ['count' => count($events), 'truncated' => false],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function birthdays(Collection $stores, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('employee_stores as es')
            ->join('employees as e', 'e.id', '=', 'es.employee_id')
            ->join('employee_obsessions as o', 'o.employee_id', '=', 'e.id')
            ->join('employee_status_histories as h', 'h.employee_id', '=', 'e.id')
            ->whereIn('es.store_id', $stores->keys())
            // the employee's CURRENT store: no later store row
            ->whereNotExists(fn ($q) => $q->from('employee_stores as es2')
                ->whereColumn('es2.employee_id', 'es.employee_id')
                ->where(fn ($w) => $w->whereColumn('es2.effective_date', '>', 'es.effective_date')
                    ->orWhere(fn ($x) => $x->whereColumn('es2.effective_date', 'es.effective_date')->whereColumn('es2.id', '>', 'es.id'))))
            // and their CURRENT status: no later status row
            ->whereNotExists(fn ($q) => $q->from('employee_status_histories as h2')
                ->whereColumn('h2.employee_id', 'h.employee_id')
                ->where(fn ($w) => $w->whereColumn('h2.effective_date', '>', 'h.effective_date')
                    ->orWhere(fn ($x) => $x->whereColumn('h2.effective_date', 'h.effective_date')->whereColumn('h2.id', '>', 'h.id'))))
            ->whereIn('h.status', self::ACTIVE)
            ->whereNotNull('o.birth_date')
            ->get(['e.id', 'e.first_name', 'e.last_name', 'es.store_id', 'o.birth_date']);

        $events = [];

        foreach ($rows as $r) {
            $born = Carbon::parse($r->birth_date);

            // The day this year (and next, when the range crosses New Year).
            for ($year = $from->year; $year <= $to->year; $year++) {
                $day = min($born->day, Carbon::create($year, $born->month, 1, 0, 0, 0, 'UTC')->daysInMonth); // 29 Feb → 28 Feb
                $date = Carbon::create($year, $born->month, $day, 0, 0, 0, 'UTC');
                if ($date->lt($from) || $date->gt($to)) {
                    continue;
                }

                $store = $stores[$r->store_id];
                $name = $this->shortName($r->first_name, $r->last_name);

                $events[] = $this->event(
                    id: "hiring:birthday:{$r->id}:{$date->toDateString()}",
                    type: 'birthday',
                    store: $store,
                    title: "🎂 {$name}",
                    date: $date->toDateString(),
                    people: [['kind' => 'employee', 'id' => (int) $r->id, 'name' => $name]],
                    link: '/employees?' . http_build_query(['store' => $store]),
                );
            }
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function lastWorkingDays(Collection $stores, Carbon $from, Carbon $to): array
    {
        return DB::table('separation_requests as sr')
            ->join('employees as e', 'e.id', '=', 'sr.employee_id')
            ->whereIn('sr.store_id', $stores->keys())
            ->whereBetween('sr.final_working_day', [$from->toDateString(), $to->toDateString()])
            ->whereExists(fn ($q) => $q->from('separation_request_decisions as d')
                ->whereColumn('d.separation_request_id', 'sr.id')
                ->where('d.decision', 'completed'))
            ->get(['sr.id', 'sr.store_id', 'sr.final_working_day', 'sr.updated_at', 'e.id as employee_id', 'e.first_name', 'e.last_name'])
            ->map(function ($s) use ($stores) {
                $store = $stores[$s->store_id];
                $name = $this->shortName($s->first_name, $s->last_name);

                return $this->event(
                    id: 'hiring:last_working_day:' . $s->id,
                    type: 'last_working_day',
                    store: $store,
                    title: "👋 Last day: {$name}",
                    date: Carbon::parse($s->final_working_day)->toDateString(),
                    people: [['kind' => 'employee', 'id' => (int) $s->employee_id, 'name' => $name]],
                    link: '/employees?' . http_build_query(['store' => $store]),
                    updatedAt: $s->updated_at,
                );
            })
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function hiringNeeds(Collection $stores, Carbon $from, Carbon $to): array
    {
        return DB::table('hiring_requests')
            ->whereIn('store_id', $stores->keys())
            ->whereBetween('desired_start_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'store_id', 'employees_needed', 'desired_start_date', 'updated_at'])
            ->map(function ($h) use ($stores) {
                $store = $stores[$h->store_id];

                return $this->event(
                    id: 'hiring:hiring_need:' . $h->id,
                    type: 'hiring_need',
                    store: $store,
                    title: "➕ Hire {$h->employees_needed}",
                    date: Carbon::parse($h->desired_start_date)->toDateString(),
                    people: [],
                    link: '/hiring-requests?' . http_build_query(['store' => $store]),
                    updatedAt: $h->updated_at,
                    extra: ['count' => (int) $h->employees_needed],
                );
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function event(string $id, string $type, string $store, string $title, string $date, array $people, string $link, $updatedAt = null, array $extra = []): array
    {
        $event = [
            'id'         => $id,
            'type'       => $type,
            'layer'      => 'hr',
            'store'      => $store,
            'title'      => mb_substr($title, 0, 120),
            'all_day'    => true,
            'date'       => $date,
            'date_end'   => null,
            'start'      => null,
            'end'        => null,
            'status'     => 'info',
            'people'     => $people,
            'link'       => $link,
            'updated_at' => $this->utc($updatedAt ?? now()),
        ];

        // Left out when empty: PHP would send [] where the contract expects an object.
        if ($extra !== []) {
            $event['extra'] = $extra;
        }

        return $event;
    }

    /** "Ana Lopez" → "Ana L.": first name and the family initial, never more. */
    private function shortName(?string $first, ?string $last): string
    {
        $last = trim((string) $last);

        return trim((string) $first) . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : '');
    }

    private function utc($at): ?string
    {
        return $at ? Carbon::parse($at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
