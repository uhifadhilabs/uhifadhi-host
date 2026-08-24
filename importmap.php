<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 *
 * @return array<string, array{    // Import name as key, description of the imported file as value
 *     path: string,               // Logical, relative or absolute path to the file
 *     type?: 'js'|'css'|'json',   // Type of the file, defaults to 'js'
 *     entrypoint?: bool,          // Whether the file is an entrypoint, for 'js' only
 * }|array{
 *     version: string,            // Version of the remote package
 *     package_specifier?: string, // Remote "package-name/path" specifier, defaults to the import name
 *     type?: 'js'|'css'|'json',
 *     entrypoint?: bool,
 * }>
 */
return [
    'app' => ['path' => './assets/app.js', 'entrypoint' => true],
    // The platform's basemap sources (Google Map Tiles satellite + OSM street),
    // under a bare specifier so MODULE controllers can import the same helper the
    // host's own map controller uses — one basemap definition, every map.
    'uhifadhi/basemaps' => ['path' => './assets/google_tiles.js'],
    // How an area boundary is drawn, likewise under a bare specifier: the host's
    // map controller and every module's map import the SAME casing + line style,
    // so a boundary can never read one way on an area page and another inside a
    // module (the "same layer renders identically everywhere" rule).
    'uhifadhi/boundary' => ['path' => './assets/map_boundary.js'],
    // The controls that sit on a map — zoom, DIM, base-layer menu, fullscreen,
    // scale, the Ctrl/⌘-scroll bargain. Built in JS rather than written into two
    // repos' templates, so an area map and a module map wear the same instrument.
    'uhifadhi/map-chrome' => ['path' => './assets/map_chrome.js'],
    // The widget library's editing behaviour — on/off, width, drag-to-place —
    // for ANY dashboard surface that ships a WidgetCatalog. Under a bare
    // specifier for the same reason the map helpers are: the host's own
    // dashboards and every module's arrange themselves through one script, so a
    // layout can never behave one way in a module and another in the host. The
    // wire it speaks is Uhifadhi\Model\WidgetDom.
    'uhifadhi/widgets' => ['path' => './assets/widgets.js'],
    '@hotwired/stimulus' => ['version' => '3.2.2'],
    '@symfony/stimulus-bundle' => ['path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js'],
    '@hotwired/turbo' => ['version' => '8.0.23'],
];
