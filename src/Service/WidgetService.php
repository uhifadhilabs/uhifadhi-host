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
use Uhifadhi\Entity\WidgetPreference;
use Uhifadhi\Exception\InvalidWidgetPreferenceException;
use Uhifadhi\Model\WidgetCatalog;
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
 */
final class WidgetService
{
    public function __construct(
        private readonly WidgetPreferenceRepository $preferences,
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

    private static function unknown(WidgetCatalog $catalog, mixed $id): string
    {
        return \sprintf(
            '"%s" is not a widget of the "%s" dashboard.',
            \is_string($id) ? $id : \gettype($id),
            $catalog->surface,
        );
    }
}
