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
    // THE MAP PLATFORM, now uhifadhilabs/map-module.
    //
    // These three names are unchanged and that is the point: they were written
    // as bare specifiers rather than paths precisely so the files could move
    // underneath them one day, and this is the day. Every importer — the host's
    // own map controllers, patrol's plate, incident's plate — imports exactly
    // what it imported before; only the right-hand side moved.
    //
    // The paths are the BUNDLE's logical paths. The bundle registers its assets/
    // directory under the @uhifadhilabs/map-module namespace when it boots; the
    // import NAMES have to be declared here, because importmap entries are read
    // from this one file and AssetMapper offers no extension point for a bundle
    // to add to it. Three lines a Flex recipe would write on install.
    //
    // The platform's basemap sources — the configured satellite provider (esri,
    // google or a deployment's own source; see config/packages/map.yaml) plus
    // the OSM street layer. One basemap definition, every map.
    'uhifadhi/basemaps' => ['path' => '@uhifadhilabs/map-module/basemaps.js'],
    // How an area boundary is drawn: the host's map controller and every
    // module's map import the SAME casing + line style, so a boundary can never
    // read one way on an area page and another inside a module (the "same layer
    // renders identically everywhere" rule).
    'uhifadhi/boundary' => ['path' => '@uhifadhilabs/map-module/boundary.js'],
    // The controls that sit on a map — zoom, DIM, base-layer menu, fullscreen,
    // scale, the Ctrl/⌘-scroll bargain. Built in JS rather than written into two
    // repos' templates, so an area map and a module map wear the same instrument.
    'uhifadhi/map-chrome' => ['path' => '@uhifadhilabs/map-module/chrome.js'],
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
