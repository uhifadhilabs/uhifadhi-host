<?php

declare(strict_types=1);

/*
 * This file is part of uhifadhi.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Entity\WidgetCustomPreset;
use Uhifadhi\Entity\WidgetPreference;
use Uhifadhi\Exception\InvalidWidgetPreferenceException;
use Uhifadhi\Exception\UnknownWidgetPresetException;
use Uhifadhi\Model\WidgetCatalog;
use Uhifadhi\Model\WidgetPreset;
use Uhifadhi\Repository\WidgetCustomPresetRepository;
use Uhifadhi\Repository\WidgetPreferenceRepository;

/**
 * The platform's widget framework: one person's layout of one dashboard surface,
 * resolved from that surface's {@see WidgetCatalog} and their stored row.
 *
 * Generalised from the patrols module's battle-tested service — same merge,
 * validation and clamping semantics, with the catalogue handed in rather than
 * hard-coded, so every dashboard in the app arranges itself the same way and a
 * module ships a catalogue rather than a copy of this algebra.
 *
 * The merge and validation rules are static and pure — stored preferences are
 * untrusted input (a browser wrote them, and a release may have retired a widget
 * since), so reading them can never throw and writing them can never store an id
 * or a span the catalogue does not offer.
 *
 * PRESETS are whole layouts rather than one person's current one: the designs a
 * surface ships ({@see WidgetCatalog::presets()}) and the ones a person saved
 * ({@see WidgetCustomPreset}). Both are converted to an ordinary payload and
 * written through {@see save()} — there is exactly one path into a
 * stored row, and adopting a design is not an exception to it.
 */
final class WidgetService
{
    /** As long as a name can be and still read on a preset card — and the stored column's length. */
    public const int NAME_MAX = 60;

    public function __construct(
        private readonly WidgetPreferenceRepository $preferences,
        private readonly WidgetCustomPresetRepository $savedPresets,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * This person's layout of this surface, complete and ordered. A null user (an
     * anonymous request) always gets the catalogue's defaults. The area is null
     * on an org-wide surface.
     *
     * @return list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}>
     */
    public function resolve(WidgetCatalog $catalog, ?int $userId, ?Uuid $areaUuid = null): array
    {
        $stored = null !== $userId
            ? $this->preferences->findOneForUser($catalog->surface, $userId, $areaUuid)?->getPrefs()
            : null;

        return self::merge($catalog, $stored);
    }

    /**
     * Store this person's layout, canonicalised. Throws rather than storing a
     * payload the catalogue does not recognise.
     *
     * @param array<string, mixed> $payload
     *
     * @throws InvalidWidgetPreferenceException
     */
    public function save(WidgetCatalog $catalog, int $userId, array $payload, ?Uuid $areaUuid = null): void
    {
        $prefs = self::validate($catalog, $payload);

        $row = $this->preferences->findOneForUser($catalog->surface, $userId, $areaUuid)
            ?? new WidgetPreference($catalog->surface, $userId, $areaUuid);
        $row->setPrefs($prefs);

        $this->entityManager->persist($row);
        $this->entityManager->flush();
    }

    /**
     * Adopt one of the surface's designs wholesale: the preset's widgets, in its
     * order, at its widths; everything else off.
     *
     * It goes through {@see save()} rather than writing a row itself, so the
     * tolerant ranking, the span clamping and the completeness rule stay in ONE
     * place and a preset can never store something the library could not.
     *
     * @throws InvalidWidgetPreferenceException when the surface names no such design
     */
    public function applyPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, string $presetId): void
    {
        $preset = self::presetOf($catalog, $presetId);

        $this->save($catalog, $userId, self::presetPayload($catalog, $preset), $areaUuid);
    }

    /**
     * This person's own saved layouts for this surface, as presets — the strip
     * draws them beside the shipped ones with no idea which is which.
     *
     * @return list<WidgetPreset>
     */
    public function customPresets(WidgetCatalog $catalog, ?int $userId, ?Uuid $areaUuid = null): array
    {
        if (null === $userId) {
            return [];
        }

        return array_map(
            static fn (WidgetCustomPreset $row): WidgetPreset => new WidgetPreset(
                (string) $row->getUuidString(),
                $row->getName(),
                '',
                $row->getLayout(),
            ),
            $this->savedPresets->findForUser($catalog->surface, $userId, $areaUuid),
        );
    }

    /**
     * Save the dashboard AS IT IS NOW under a name of their own.
     *
     * Saving under a name they already used OVERWRITES that preset rather than
     * refusing: it is their own layout under their own word, two cards reading
     * "Morning check" would be worse than a replacement, and the library asks
     * first through the confirm modal. The table's unique index says the same
     * thing, so a race ends as a replacement too.
     *
     * @throws InvalidWidgetPreferenceException on an unusable name or an empty dashboard
     */
    public function saveCustomPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, string $rawName): WidgetCustomPreset
    {
        $name = self::presetName($rawName);
        $layout = self::captureLayout($this->resolve($catalog, $userId, $areaUuid));
        // Refuses an empty dashboard, and refuses it BEFORE any row is touched.
        self::customPreset('draft', $name, $layout);

        $row = $this->savedPresets->findOneNamed($catalog->surface, $userId, $areaUuid, $name)
            ?? new WidgetCustomPreset($catalog->surface, $userId, $areaUuid, $name);
        $row->setLayout($layout);

        $this->entityManager->persist($row);
        $this->entityManager->flush();

        return $row;
    }

    /**
     * Put one of their own saved layouts back on. Through {@see save()} like every
     * other write, so drift is tolerated and the stored row stays canonical.
     *
     * @throws UnknownWidgetPresetException when the preset is not theirs, or gone
     */
    public function applyCustomPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, Uuid $uuid): void
    {
        $row = $this->ownedPreset($catalog, $userId, $areaUuid, $uuid);
        $preset = self::customPreset((string) $row->getUuidString(), $row->getName(), $row->getLayout());

        $this->save($catalog, $userId, self::presetPayload($catalog, $preset), $areaUuid);
    }

    /**
     * @throws InvalidWidgetPreferenceException on an unusable name
     * @throws UnknownWidgetPresetException     when the preset is not theirs, or gone
     */
    public function renameCustomPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, Uuid $uuid, string $rawName): void
    {
        $row = $this->ownedPreset($catalog, $userId, $areaUuid, $uuid);
        $name = self::presetName($rawName);

        $clash = $this->savedPresets->findOneNamed($catalog->surface, $userId, $areaUuid, $name);
        if (null !== $clash && $clash !== $row) {
            // Renaming onto an existing name is the one place overwriting would
            // destroy a layout the person did not have on screen.
            throw new InvalidWidgetPreferenceException(\sprintf('You already have a preset called “%s”.', $name));
        }

        $row->setName($name);
        $this->entityManager->flush();
    }

    /** @throws UnknownWidgetPresetException when the preset is not theirs, or gone */
    public function deleteCustomPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, Uuid $uuid): void
    {
        $this->entityManager->remove($this->ownedPreset($catalog, $userId, $areaUuid, $uuid));
        $this->entityManager->flush();
    }

    /** Back to the catalogue's layout — no row means the defaults, so reset deletes. */
    public function reset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid = null): void
    {
        $row = $this->preferences->findOneForUser($catalog->surface, $userId, $areaUuid);
        if (null === $row) {
            return;
        }

        $this->entityManager->remove($row);
        $this->entityManager->flush();
    }

    /**
     * Stored preferences over the catalogue defaults. Never throws: a row written
     * by an older release, or edited by hand, degrades to the defaults rather
     * than taking the dashboard down.
     *
     * @param array<string, mixed>|null $stored
     *
     * @return list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}>
     */
    public static function merge(WidgetCatalog $catalog, ?array $stored): array
    {
        $order = self::readOrder($catalog, $stored['order'] ?? null);
        $widgets = \is_array($stored['widgets'] ?? null) ? $stored['widgets'] : [];

        $resolved = [];
        foreach ($order as $id) {
            $definition = $catalog->get($id);
            $entry = $widgets[$id] ?? null;
            $entry = \is_array($entry) ? $entry : [];

            $resolved[] = [
                'id' => $id,
                'label' => $definition->label,
                'group' => $definition->group,
                'on' => \array_key_exists('on', $entry) ? (bool) $entry['on'] : $definition->on,
                'cols' => $catalog->clamp($id, isset($entry['cols']) && is_numeric($entry['cols'])
                    ? (int) $entry['cols']
                    : $definition->cols),
                'spans' => $definition->spans,
            ];
        }

        return $resolved;
    }

    /**
     * The canonical stored shape for a payload from the library screen. Every
     * catalogue widget ends up in the result, so a stored row is always a
     * complete picture and a later read needs no defaulting.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{order: list<string>, widgets: array<string, array{on: bool, cols: int}>}
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function validate(WidgetCatalog $catalog, array $payload): array
    {
        $rawOrder = $payload['order'] ?? [];
        if (!\is_array($rawOrder)) {
            throw new InvalidWidgetPreferenceException('The widget order must be a list of widget ids.');
        }
        $rawWidgets = $payload['widgets'] ?? [];
        if (!\is_array($rawWidgets)) {
            throw new InvalidWidgetPreferenceException('The widget preferences must be a map of widget id to settings.');
        }

        $order = [];
        foreach ($rawOrder as $id) {
            if (!\is_string($id) || !$catalog->has($id)) {
                throw new InvalidWidgetPreferenceException(self::unknown($catalog, $id));
            }
            if (!\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        foreach ($catalog->ids() as $id) {
            if (!\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        $widgets = [];
        foreach ($order as $id) {
            $entry = $rawWidgets[$id] ?? [];
            if (!\is_array($entry)) {
                throw new InvalidWidgetPreferenceException(\sprintf('The settings for widget "%s" must be an object.', $id));
            }
            $definition = $catalog->get($id);
            $widgets[$id] = [
                'on' => \array_key_exists('on', $entry) ? (bool) $entry['on'] : $definition->on,
                'cols' => $catalog->clamp($id, isset($entry['cols']) && is_numeric($entry['cols'])
                    ? (int) $entry['cols']
                    : $definition->cols),
            ];
        }

        foreach (array_keys($rawWidgets) as $id) {
            if (!\is_string($id) || !$catalog->has($id)) {
                throw new InvalidWidgetPreferenceException(self::unknown($catalog, $id));
            }
        }

        return ['order' => $order, 'widgets' => $widgets];
    }

    /**
     * The design this id names. A preset id arrives in a URL, so an unknown one
     * is a REFUSED PREFERENCE (the endpoint answers 422), never a 500.
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function presetOf(WidgetCatalog $catalog, string $presetId): WidgetPreset
    {
        return $catalog->preset($presetId)
            ?? throw new InvalidWidgetPreferenceException(\sprintf('"%s" is not a design of the "%s" dashboard.', $presetId, $catalog->surface));
    }

    /**
     * A preset read as a library payload: its widgets on, in its order, at its
     * widths — and every other widget the surface ships explicitly off, so
     * adopting a design leaves nothing of the previous arrangement behind.
     *
     * TOLERANT, like {@see merge()} and for the same reason: a SHIPPED preset is
     * catalogue-checked at boot, but a preset a person saved is a stored row, and
     * a release may have retired a widget or narrowed its spans since. A ghost is
     * dropped, a width is clamped, and the widgets the surface has gained arrive
     * off — applying an old preset is never an error page.
     *
     * @return array{order: list<string>, widgets: array<string, array{on: bool, cols: int}>}
     */
    public static function presetPayload(WidgetCatalog $catalog, WidgetPreset $preset): array
    {
        $widgets = [];
        foreach ($preset->layout as $id => $cols) {
            if (!$catalog->has($id)) {
                continue;
            }
            $widgets[$id] = ['on' => true, 'cols' => $catalog->clamp($id, $cols)];
        }
        foreach ($catalog->ids() as $id) {
            if (!isset($widgets[$id])) {
                $widgets[$id] = ['on' => false, 'cols' => $catalog->get($id)->cols];
            }
        }

        /** @var list<string> $order */
        $order = array_keys(array_filter($widgets, static fn (array $widget): bool => $widget['on']));

        return ['order' => $order, 'widgets' => $widgets];
    }

    /**
     * A resolved layout read as a preset's layout: the widgets that are ON, in
     * their order, at their width. Absence IS off, exactly as in a shipped
     * preset, so "save this dashboard as a preset" and "ship this design" write
     * the same thing.
     *
     * @param list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}> $resolved
     *
     * @return array<string, int>
     */
    public static function captureLayout(array $resolved): array
    {
        $layout = [];
        foreach ($resolved as $widget) {
            if ($widget['on']) {
                $layout[$widget['id']] = $widget['cols'];
            }
        }

        return $layout;
    }

    /**
     * A person's own saved layout, as the very same value object a surface ships.
     * Nothing downstream distinguishes the two.
     *
     * @param array<string, int> $layout
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function customPreset(string $id, string $name, array $layout): WidgetPreset
    {
        if ([] === $layout) {
            // Saving an empty dashboard would store a preset that, applied, shows
            // nothing — a trap dressed as a feature.
            throw new InvalidWidgetPreferenceException('Switch at least one widget on before saving this layout as a preset.');
        }

        return new WidgetPreset($id, $name, '', $layout);
    }

    /**
     * A name a person typed: trimmed, saying something, and short enough to read
     * on a card. Untrusted input, so a refusal is a 422 and never an exception
     * page.
     *
     * @throws InvalidWidgetPreferenceException
     */
    public static function presetName(string $raw): string
    {
        $name = trim($raw);
        if ('' === $name) {
            throw new InvalidWidgetPreferenceException('A preset needs a name.');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidWidgetPreferenceException(\sprintf('A preset name may be at most %d characters.', self::NAME_MAX));
        }

        return $name;
    }

    /**
     * A resolved layout read as the library's headed sections: the catalogue's
     * groups in the catalogue's order, each carrying the widgets filed under it
     * IN THE PERSON'S OWN ORDER. A group no widget lands in is not drawn — an
     * empty heading says nothing.
     *
     * Grouping is a library-side reading only: the dashboard grid stays one
     * ordered list, and a drag moves a widget across the whole surface.
     *
     * @param list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}> $resolved
     *
     * @return list<array{id: string, label: string, description: string, widgets: list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}>}>
     */
    public static function sections(WidgetCatalog $catalog, array $resolved): array
    {
        $sections = [];
        foreach ($catalog->groups() as $group) {
            $widgets = array_values(array_filter(
                $resolved,
                static fn (array $widget): bool => $widget['group'] === $group->id,
            ));
            if ([] === $widgets) {
                continue;
            }

            $sections[] = [
                'id' => $group->id,
                'label' => $group->label,
                'description' => $group->description,
                'widgets' => $widgets,
            ];
        }

        return $sections;
    }

    /**
     * A stored order, skipping ids this surface no longer ships and appending the
     * ones it gained. Unreadable input simply means "the catalogue order".
     *
     * @return list<string>
     */
    private static function readOrder(WidgetCatalog $catalog, mixed $stored): array
    {
        $order = [];
        if (\is_array($stored)) {
            foreach ($stored as $id) {
                if (\is_string($id) && $catalog->has($id) && !\in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
        }
        foreach ($catalog->ids() as $id) {
            if (!\in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return $order;
    }

    /**
     * The ownership check, in the lookup itself: someone else's preset and a
     * deleted one are the same answer, so a URL tells nobody what exists.
     *
     * @throws UnknownWidgetPresetException
     */
    private function ownedPreset(WidgetCatalog $catalog, int $userId, ?Uuid $areaUuid, Uuid $uuid): WidgetCustomPreset
    {
        return $this->savedPresets->findOwned($catalog->surface, $userId, $areaUuid, $uuid)
            ?? throw new UnknownWidgetPresetException(\sprintf('You have no saved preset "%s" on the "%s" dashboard.', $uuid->toRfc4122(), $catalog->surface));
    }

    private static function unknown(WidgetCatalog $catalog, mixed $id): string
    {
        return \sprintf(
            '"%s" is not a widget of the "%s" dashboard.',
            \is_string($id) ? $id : \gettype($id),
            $catalog->surface,
        );
    }
}
