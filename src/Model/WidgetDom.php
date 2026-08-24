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

namespace Uhifadhi\Model;

/**
 * The WIRE between a widget-library page and assets/widgets.js: every attribute
 * the script drives the page through, and the header both writes carry their
 * CSRF token in.
 *
 * It is a class of constants rather than a convention because of a real bug in
 * the patrols module: the controller validated a CSRF token, the template
 * rendered one — and the script never sent it. Every server-side test passed
 * (each builds its own header); only a browser ever saw the 403. So the names
 * live HERE, a template renders them from here, and
 * {@see \Uhifadhi\Tests\Unit\WidgetLibraryAssetsTest} reads widgets.js as text
 * and proves both sides spell them the same way.
 *
 * A template renders these by exposing the class to Twig (`constant(...)`) or by
 * passing them in; either way it must never retype the literal.
 */
final class WidgetDom
{
    /**
     * The header the library's fetch() carries its CSRF token in. A header, not a
     * form field: the body is JSON, and only same-origin script can set one — a
     * cross-site form can post a body but never this.
     */
    public const string CSRF_HEADER = 'X-CSRF-Token';

    /** The library root; the script does nothing at all when no element has it. */
    public const string ROOT = 'data-widget-root';

    /** The token, on the same element as the two URLs — one element, never a lookup that can drift. */
    public const string CSRF_TOKEN = 'data-widget-csrf-token';

    public const string SAVE_URL = 'data-widget-save-url';
    public const string RESET_URL = 'data-widget-reset-url';

    /** Where a refused save says so; rendered empty and hidden by the template. */
    public const string NOTICE = 'data-widget-notice';

    /** The "reset to defaults" trigger, which asks through the host's confirm-modal controller. */
    public const string RESET = 'data-widget-reset';

    /** On a card: the widget's id, its on/off state and its current span. */
    public const string WIDGET = 'data-widget-id';
    public const string ON = 'data-widget-on';
    public const string COLS = 'data-widget-cols';

    /** Inside a card: the drag handle, the toggle (and its words), the width chips, the state chip. */
    public const string GRIP = 'data-widget-grip';
    public const string TOGGLE = 'data-widget-toggle';
    public const string TOGGLE_LABEL = 'data-widget-toggle-label';
    public const string SPAN = 'data-widget-span';
    public const string CHOSEN = 'data-widget-chosen';
    public const string STATE = 'data-widget-state';

    /** The card's preview of the real widget — the hook that dims a switched-off one. */
    public const string PREVIEW = 'data-widget-preview';

    /**
     * Every attribute of the contract, so a test can prove the script uses these
     * and only these.
     *
     * @return list<string>
     */
    public static function attributes(): array
    {
        return [
            self::ROOT,
            self::CSRF_TOKEN,
            self::SAVE_URL,
            self::RESET_URL,
            self::NOTICE,
            self::RESET,
            self::WIDGET,
            self::ON,
            self::COLS,
            self::GRIP,
            self::TOGGLE,
            self::TOGGLE_LABEL,
            self::SPAN,
            self::CHOSEN,
            self::STATE,
            self::PREVIEW,
        ];
    }
}
