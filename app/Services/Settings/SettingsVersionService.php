<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\SettingVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Settings version control (ADR-043): snapshot, pull and rollback.
 *
 * Every mutation of the settings map is captured as an immutable
 * {@see SettingVersion} row so an operator can always return to a known-good
 * state. The service is the only writer of `setting_versions`.
 *
 * "Pull update" in a self-hosted, telemetry-free app (PRD AC-23) never makes an
 * outbound call. It resolves an upstream source in this order:
 *   1. `sentinel.settings.upstream_path` — a local JSON file (e.g. a
 *      git-tracked defaults file or a file synced by the operator's own
 *      tooling). Only registered keys are read; anything else is ignored.
 *   2. The most recent stored snapshot (refresh/reload to the latest known
 *      version).
 *   3. The registry's config-derived defaults (a fresh install with no history).
 *
 * A snapshot of the current state is always written before a pull or a
 * rollback applies anything, so no data is destroyed without a recovery point.
 */
final class SettingsVersionService
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Capture the current settings map as a new version.
     *
     * Routine saves dedupe an identical consecutive state (same checksum as the
     * latest version) so the history stays meaningful. Explicit pull/rollback
     * actions pass `$dedupe = false` so the action is always recorded, even when
     * it restores a state seen before.
     */
    public function snapshot(
        string $source = SettingVersion::SOURCE_SAVE,
        ?User $author = null,
        ?string $label = null,
        bool $dedupe = true,
    ): SettingVersion {
        $snapshot = $this->settings->all();
        $checksum = SettingVersion::checksumFor($snapshot);

        $latest = $this->latest();

        if ($dedupe && $latest !== null && $latest->checksum === $checksum) {
            return $latest;
        }

        return DB::transaction(function () use ($snapshot, $checksum, $source, $author, $label): SettingVersion {
            $version = (int) (SettingVersion::query()->max('version') ?? 0) + 1;

            return SettingVersion::query()->create([
                'version' => $version,
                'label' => $label,
                'snapshot' => $snapshot,
                'checksum' => $checksum,
                'source' => $source,
                'author_id' => $author?->getKey(),
            ]);
        });
    }

    /**
     * Pull the latest upstream/default settings state into the live settings.
     *
     * Safety: the current state is snapshotted first. Only registered keys are
     * applied; an unknown or malformed upstream entry is ignored (never stored).
     *
     * @return array{source: string, applied: int, version: SettingVersion, before: SettingVersion}
     */
    public function pull(?User $author = null): array
    {
        // Resolve the upstream source BEFORE snapshotting the current state, so
        // the "before pull" recovery snapshot cannot become the "latest" source.
        [$source, $values] = $this->resolveUpstream();

        // Recovery point for the pre-pull state.
        $before = $this->snapshot(SettingVersion::SOURCE_SAVE, $author, 'Before pull update');

        $applied = $this->apply($values);

        // Always record the pull as its own version (no dedupe) so the history
        // shows the action even when it re-applies a previously seen state.
        $version = $this->snapshot(SettingVersion::SOURCE_PULL, $author, 'Pulled update', dedupe: false);

        return [
            'source' => $source,
            'applied' => $applied,
            'version' => $version,
            'before' => $before,
        ];
    }

    /**
     * Restore settings to a previous version. The current state is snapshotted
     * first so the rollback itself is reversible.
     *
     * @return array{applied: int, version: SettingVersion, before: SettingVersion, restored: SettingVersion}
     */
    public function rollbackTo(SettingVersion $target, ?User $author = null): array
    {
        $before = $this->snapshot(SettingVersion::SOURCE_SAVE, $author, 'Before rollback');

        /** @var array<string, mixed> $values */
        $values = is_array($target->snapshot) ? $target->snapshot : [];

        $applied = $this->apply($values);

        $restored = $this->snapshot(
            SettingVersion::SOURCE_ROLLBACK,
            $author,
            'Rolled back to v'.$target->version,
            dedupe: false,
        );

        return [
            'applied' => $applied,
            'version' => $restored,
            'before' => $before,
            'restored' => $target,
        ];
    }

    public function latest(): ?SettingVersion
    {
        return SettingVersion::query()->orderByDesc('version')->first();
    }

    /**
     * Resolve the upstream settings map. Never reaches the network (AC-23).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function resolveUpstream(): array
    {
        $path = config('sentinel.settings.upstream_path');

        if (is_string($path) && $path !== '' && is_file($path) && is_readable($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (is_array($decoded)) {
                return ['upstream-file', $decoded];
            }
        }

        $latest = $this->latest();

        if ($latest !== null && is_array($latest->snapshot)) {
            return ['latest-version', $latest->snapshot];
        }

        return ['defaults', array_map(
            static fn (string $key): mixed => SettingsRepository::defaultFor($key),
            SettingsRepository::keys(),
        )];
    }

    /**
     * Apply a settings map, ignoring unknown keys. Returns the number of known
     * keys applied.
     *
     * @param  array<string, mixed>  $values
     */
    private function apply(array $values): int
    {
        $known = array_filter(
            $values,
            static fn (mixed $value, string $key): bool => SettingsRepository::isKnown($key),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($known === []) {
            return 0;
        }

        $this->settings->setMany($known);

        return count($known);
    }
}
