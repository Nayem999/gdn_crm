<?php

namespace App\Domain\Reports\Models;

use App\Domain\Audit\Concerns\RecordsActivity;
use App\Domain\Reports\Enums\ScheduleFrequency;
use App\Domain\Shared\Enums\ExportFormat;
use App\Models\User;
use Database\Factories\ReportScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A report that posts itself.
 *
 * @property int $id
 * @property int $report_id
 * @property int $user_id
 * @property string $frequency
 * @property int|null $day_of_week
 * @property int|null $day_of_month
 * @property int $hour
 * @property string $format
 * @property array<int, string> $recipients
 * @property bool $is_active
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_run_at
 * @property string|null $last_status
 * @property string|null $last_error
 */
class ReportSchedule extends Model
{
    /** @use HasFactory<ReportScheduleFactory> */
    use HasFactory;

    use RecordsActivity;

    /**
     * How many addresses one schedule may post to.
     *
     * A cap, because a schedule is an unattended sender: somebody pasting two
     * hundred addresses into it has built a mailing list, and a mailing list
     * wants unsubscribes and bounce handling this does not have.
     */
    public const MAX_RECIPIENTS = 20;

    /**
     * `last_run_at`, `next_run_at` and the two status columns are absent: the
     * sweep owns them, and a form that could set next_run_at could make a
     * schedule fire immediately and repeatedly.
     *
     * @var list<string>
     */
    protected $fillable = [
        'report_id',
        'user_id',
        'frequency',
        'day_of_week',
        'day_of_month',
        'hour',
        'format',
        'recipients',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'frequency' => 'daily',
        'format' => 'pdf',
        'hour' => 8,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'is_active' => 'boolean',
            'hour' => 'integer',
            'day_of_week' => 'integer',
            'day_of_month' => 'integer',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    /**
     * Recipients are on the list: "who was this being sent to" is the question
     * somebody asks when a figure turns up somewhere it should not have.
     *
     * @return array<int, string>
     */
    protected function activityAttributes(): array
    {
        return ['report_id', 'user_id', 'frequency', 'hour', 'format', 'recipients', 'is_active'];
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * Whose view the report is run under.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * getAttributeValue, not $this->frequency: the method and the column share
     * a name — see .ai/rules/models-name-collisions.md.
     */
    public function frequency(): ScheduleFrequency
    {
        return ScheduleFrequency::tryFrom((string) $this->getAttributeValue('frequency')) ?? ScheduleFrequency::Daily;
    }

    public function format(): ExportFormat
    {
        return ExportFormat::tryFrom((string) $this->getAttributeValue('format')) ?? ExportFormat::Pdf;
    }

    /**
     * The addresses this posts to, cleaned and capped.
     *
     * @return array<int, string>
     */
    public function addresses(): array
    {
        $addresses = [];

        foreach ((array) $this->recipients as $address) {
            $address = trim((string) $address);

            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                // Keyed then re-listed, so the same address twice is one send.
                $addresses[strtolower($address)] = $address;
            }
        }

        return array_slice(array_values($addresses), 0, self::MAX_RECIPIENTS);
    }

    /**
     * When this is next due, from a given moment.
     */
    public function nextRunAfter(Carbon $after): Carbon
    {
        return $this->frequency()->next($after, $this->hour, $this->day_of_week, $this->day_of_month);
    }

    /**
     * A short phrase for a screen: "Every week on Monday at 08:00".
     */
    public function summary(): string
    {
        $at = str_pad((string) $this->hour, 2, '0', STR_PAD_LEFT).':00';

        return match ($this->frequency()) {
            ScheduleFrequency::Daily => 'Every day at '.$at,
            ScheduleFrequency::Weekly => 'Every '.Carbon::now()
                ->startOfWeek(Carbon::SUNDAY)
                ->addDays($this->day_of_week ?? Carbon::MONDAY)
                ->format('l').' at '.$at,
            ScheduleFrequency::Monthly => 'Day '.($this->day_of_month ?? 1).' of the month at '.$at,
        };
    }

    /**
     * @param  Builder<ReportSchedule>  $query
     * @return Builder<ReportSchedule>
     */
    public function scopeDue(Builder $query, Carbon $at): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->whereNotNull($query->qualifyColumn('next_run_at'))
            ->where($query->qualifyColumn('next_run_at'), '<=', $at);
    }

    /**
     * @param  Builder<ReportSchedule>  $query
     * @return Builder<ReportSchedule>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $user->id);
    }
}
