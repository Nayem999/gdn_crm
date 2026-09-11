<?php

namespace App\Domain\Deals;

use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;
use App\Domain\Deals\Enums\DealStage;
use App\Domain\Deals\Enums\StageOutcome;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\Pipeline;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

/**
 * The modules whose status set can be configured as a pipeline.
 *
 * A module reaches this code as a **key**, from a form or a stored row, and is
 * matched here and nowhere else.
 *
 * Each module declares the column its records store a status key in, and the
 * enum that set falls back to. The fallback is what makes this safe to add to a
 * running installation: a module with no pipeline configured behaves exactly as
 * it did, and the enum's values *are* the stage keys, so a record written
 * before a pipeline existed still resolves against one written after. That is
 * the same arrangement `PipelinesSeeder` already has with `DealStage`.
 */
final class PipelineModules
{
    /**
     * @return array<string, array{
     *     label: string,
     *     model: class-string<Model>,
     *     column: string,
     *     fallback: class-string,
     *     hasProbability: bool,
     * }>
     */
    public static function all(): array
    {
        return [
            'deals' => [
                'label' => 'Deals',
                'model' => Deal::class,
                'column' => 'stage',
                'fallback' => DealStage::class,
                // Only deals weight a forecast by stage; a lead status has no
                // probability and offering one would invite a figure nothing
                // reads.
                'hasProbability' => true,
            ],
            'leads' => [
                'label' => 'Leads',
                'model' => Lead::class,
                'column' => 'status',
                'fallback' => LeadStatus::class,
                'hasProbability' => false,
            ],
            'activities' => [
                'label' => 'Activities',
                'model' => Activity::class,
                'column' => 'status',
                'fallback' => ActivityStatus::class,
                'hasProbability' => false,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $module): bool
    {
        return array_key_exists($module, self::all());
    }

    public static function label(string $module): string
    {
        return self::all()[$module]['label'] ?? 'Unknown';
    }

    public static function column(string $module): ?string
    {
        return self::all()[$module]['column'] ?? null;
    }

    public static function hasProbability(string $module): bool
    {
        return self::all()[$module]['hasProbability'] ?? false;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::all() as $key => $module) {
            $options[$key] = $module['label'];
        }

        return $options;
    }

    /**
     * The status set a module actually uses: its configured pipeline's stages,
     * or its enum when nothing is configured.
     *
     * This is the one question the board, the filter builder and the status
     * chip all ask, so they cannot disagree about what a module's statuses are.
     *
     * @return array<int, array{value: string, label: string, color: string|null, outcome: StageOutcome}>
     */
    public static function statuses(string $module): array
    {
        return app(PipelineStatusCache::class)->remember(
            $module,
            static fn (): array => self::readStatuses($module),
        );
    }

    /**
     * @return array<int, array{value: string, label: string, color: string|null, outcome: StageOutcome}>
     */
    private static function readStatuses(string $module): array
    {
        $pipeline = Pipeline::defaultFor($module);

        if ($pipeline !== null) {
            $stages = $pipeline->stages()->ordered()->get();

            if ($stages->isNotEmpty()) {
                return $stages->map(fn ($stage): array => [
                    'value' => $stage->key,
                    'label' => $stage->name,
                    'color' => $stage->color,
                    'outcome' => $stage->outcome(),
                ])->all();
            }
        }

        return self::fallbackStatuses($module);
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(string $module): array
    {
        $options = [];

        foreach (self::statuses($module) as $status) {
            $options[$status['value']] = $status['label'];
        }

        return $options;
    }

    /**
     * One status, by the key a record stores.
     *
     * Null when the key belongs to neither the configured pipeline nor the
     * fallback enum — which happens to a record left behind by a stage that was
     * removed, and is the case a caller has to render rather than crash on.
     *
     * @return array{value: string, label: string, color: string|null, outcome: StageOutcome}|null
     */
    public static function status(string $module, ?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        foreach (self::statuses($module) as $status) {
            if ($status['value'] === $key) {
                return $status;
            }
        }

        // Fall back to the enum even when a pipeline is configured: a record
        // may still hold a key from before it was.
        foreach (self::fallbackStatuses($module) as $status) {
            if ($status['value'] === $key) {
                return $status;
            }
        }

        return null;
    }

    /**
     * The keys a module's own enum defines, or an empty list when it has none.
     *
     * Deals have no enum constraint — their stage column is free-form — so an
     * empty list means "anything goes", which is exactly what a deals pipeline
     * needs.
     *
     * @return array<int, string>
     */
    public static function fallbackKeys(string $module): array
    {
        // Deals are free-form by design: DealStage is a starting point, not a
        // constraint, and 3.1 already lets a pipeline invent its own stages.
        if ($module === 'deals') {
            return [];
        }

        return array_map(
            static fn (array $status): string => $status['value'],
            self::fallbackStatuses($module),
        );
    }

    public static function isConfigured(string $module): bool
    {
        // Answered from the memoised status set rather than two more queries:
        // a module is configured exactly when its statuses did not come from
        // the fallback enum.
        return self::statuses($module) !== self::fallbackStatuses($module);
    }

    /**
     * The module's own enum, as the same shape a stage produces.
     *
     * @return array<int, array{value: string, label: string, color: string|null, outcome: StageOutcome}>
     */
    private static function fallbackStatuses(string $module): array
    {
        $enum = self::all()[$module]['fallback'] ?? null;

        // Backed specifically: the stage key a record stores is the enum's
        // value, so a pure enum could not act as a fallback at all.
        if ($enum === null || ! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            return [];
        }

        $statuses = [];

        foreach ($enum::cases() as $case) {
            $statuses[] = [
                'value' => (string) $case->value,
                'label' => method_exists($case, 'label') ? $case->label() : (string) $case->value,
                'color' => method_exists($case, 'color') ? $case->color() : null,
                'outcome' => self::fallbackOutcome($module, (string) $case->value),
            ];
        }

        return $statuses;
    }

    /**
     * What an enum case means in pipeline terms.
     *
     * Only the endings matter: everything else is open, and a board that got
     * this wrong would let somebody drag a record into a closed column and out
     * again as though nothing had happened.
     */
    private static function fallbackOutcome(string $module, string $value): StageOutcome
    {
        return match ([$module, $value]) {
            ['deals', DealStage::Won->value], ['leads', LeadStatus::Converted->value] => StageOutcome::Won,
            ['deals', DealStage::Lost->value], ['leads', LeadStatus::Unqualified->value] => StageOutcome::Lost,
            ['activities', ActivityStatus::Completed->value] => StageOutcome::Won,
            ['activities', ActivityStatus::Cancelled->value] => StageOutcome::Lost,
            default => StageOutcome::Open,
        };
    }
}
