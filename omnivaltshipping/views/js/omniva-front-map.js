/**
 * Omniva Front Map – glue layer between PrestaShop checkout and the
 * Terminal Mapping JS library (views/lib/terminal-mapping/).
 *
 * Everything is wrapped in a single namespace so multiple Omniva carriers
 * (or other shipping modules using the same library) can coexist on the
 * same page without conflicting state.
 *
 * Each parcel-machine container in the DOM is identified by a unique
 * containerId (typically "omniva_pm_map_<carrier_id>") and has its own
 * TerminalMapping instance kept in OmnivaTerminalMap.instances.
 */
(function (global) {
    'use strict';

    var instances = {};

    /**
     * Convert the legacy array-of-arrays terminal payload produced by
     * omnivaltshipping.php::getTerminalForMap() into the object format
     * required by TerminalMapping `terminalList`.
     *
     * Legacy row indexes:
     *   0 = NAME, 1 = lat, 2 = lng, 3 = ZIP (used as id),
     *   4 = city, 5 = address, 6 = comment
     */
    function convertTerminals(rawList) {
        if (!rawList || !rawList.length) {
            return [];
        }
        return rawList.map(function (row, index) {
            return {
                id: String(row[3] != null ? row[3] : 'omniva_' + index),
                identifier: 'omniva',
                name: row[0] || '',
                city: row[4] || '',
                address: row[5] || '',
                coords: {
                    lat: parseFloat(row[1]),
                    lng: parseFloat(row[2])
                },
                comment: row[6] || ''
            };
        });
    }

    /**
     * Build the translation strings TMJS expects, sourcing from
     * window.omnivalt_text.tmjs (set by the module via Media::addJsDef).
     * Falls back to English defaults if a key is missing.
     */
    function buildStrings() {
        var t = (global.omnivalt_text && global.omnivalt_text.tmjs) || {};
        return {
            modal_header: t.modal_header || 'Parcel machine map',
            terminal_list_header: t.terminal_list_header || 'Parcel machine list',
            terminal_list_header_sorted: t.terminal_list_header_sorted || 'Parcel machines sorted by distance',
            seach_header: t.seach_header || 'Search around',
            search_btn: t.search_btn || 'Find',
            modal_open_btn: t.modal_open_btn || 'Select parcel machine',
            geolocation_btn: t.geolocation_btn || 'Use my location',
            locating_btn: t.locating_btn || 'Locating',
            your_position: t.your_position || 'Distance calculated from this point',
            nothing_found: t.nothing_found || 'Nothing found',
            no_cities_found: t.no_cities_found || 'No cities found for your search term',
            geolocation_not_supported: t.geolocation_not_supported || 'Geolocation is not supported',
            select_pickup_point: t.select_pickup_point || 'Select a parcel machine',
            dropdown_placeholder: t.dropdown_placeholder || 'Choose a parcel machine...',
            dropdown_search_placeholder: t.dropdown_search_placeholder || 'Type to filter or search address by pressing Enter',
            search_placeholder: t.search_placeholder || 'Search address',
            find_nearest_btn: t.find_nearest_btn || 'Find nearest',
            no_terminals_match: t.no_terminals_match || 'No parcel machines match your filter',
            select_btn: t.select_btn || 'Select',
            confirm_btn: t.confirm_btn || 'Confirm parcel machine',
            change_btn: t.change_btn || 'Change'
        };
    }

    function getImagesPath() {
        // TMJS expects this path to contain its own UI icons
        // (gps.svg, search.svg, info.svg, default_icon.svg, ...).
        // Those live next to the library inside views/lib/terminal-mapping/images/.
        // Custom marker icons (sasi.png / sasi_mh.svg) are loaded with full
        // URLs through getMarkerIcon() below, so they don't need to share
        // this folder.
        if (global.omnivalt_params && global.omnivalt_params.url && global.omnivalt_params.url.plugin) {
            return global.omnivalt_params.url.plugin + 'views/lib/terminal-mapping/images/';
        }
        return '';
    }

    /**
     * Build a full URL to an image in the module's own views/img/map/ folder
     * (exposed via omnivalt_params.url.parcel_machine_images). All custom map
     * icons – parcel-machine pins, the reference marker and the search icon –
     * live there, separated from the general module images so they don't mix.
     * Returns null when the base path is unavailable.
     */
    function getMapImageUrl(filename) {
        if (!global.omnivalt_params || !global.omnivalt_params.url || !global.omnivalt_params.url.parcel_machine_images) {
            return null;
        }
        return global.omnivalt_params.url.parcel_machine_images + filename;
    }

    function getMarkerIcon(country) {
        return getMapImageUrl(String(country).toUpperCase() === 'FI' ? 'matkahuolto-pin.svg' : 'omniva-pin.svg');
    }

    function getSelectedMarkerIcon(country) {
        // Icon used for the currently selected parcel machine.
        return getMapImageUrl(String(country).toUpperCase() === 'FI' ? 'matkahuolto-pin-selected.svg' : 'omniva-pin-selected.svg');
    }

    function getReferenceIcon() {

        return getMapImageUrl('location-marker.svg');
    }

    function getSearchIconUrl() {
        return getMapImageUrl('search.svg');
    }

    function getGeolocationIconUrl() {
        return getMapImageUrl('location-icon.svg');
    }

    /**
     * Build a full URL to an icon in the module's views/img/icons/ folder
     * (exposed via omnivalt_params.url.images). These icons use
     * fill/stroke="currentColor" so they can be recolored via CSS.
     * Returns null when the base path is unavailable.
     */
    function getIconUrl(filename) {
        if (!global.omnivalt_params || !global.omnivalt_params.url || !global.omnivalt_params.url.images) {
            return null;
        }
        return global.omnivalt_params.url.images + 'icons/' + filename;
    }

    // Repoint every <img> inside elements matching `selector` (within `root`)
    // to `url`. Used to swap the library's lib-folder icons for the module's
    // own copies (in views/img/map/), which we are allowed to customise.
    function applyButtonIcon(root, selector, url) {
        if (!url || !root || typeof root.querySelectorAll !== 'function') {
            return;
        }
        var imgs = root.querySelectorAll(selector + ' img');
        for (var i = 0; i < imgs.length; i++) {
            imgs[i].src = url;
        }
    }

    /**
     * Add a leading icon to the `.tmjs-selected-terminal` trigger label and
     * keep it in sync with the selection state: pin-marker.svg while no
     * parcel machine is selected, terminal.svg once one is. The icons are
     * rendered as a CSS `::before` mask (see omniva-front-map.css) so they
     * inherit the label color via currentColor; this only wires the icon
     * URLs (as CSS variables) and toggles the `omniva-pm-selected` state
     * class on the container.
     *
     * @param {Object}      tmjs      TerminalMapping instance.
     * @param {HTMLElement} container Module container element.
     */
    function setupSelectedTerminalIcon(tmjs, container) {
        var pinUrl = getIconUrl('pin-marker.svg');
        var terminalUrl = getIconUrl('terminal.svg');
        if (!pinUrl || !terminalUrl) {
            return;
        }
        // The theme class (.omniva-tmjs) and the .tmjs-selected-terminal label
        // both live on the library-created .tmjs-container element, so the CSS
        // variables and the state class must go there too.
        var themeEl = tmjs.dom && tmjs.dom.UI ? tmjs.dom.UI.container : null;
        if (!themeEl) {
            return;
        }
        themeEl.style.setProperty('--omniva-pm-pin-icon', 'url("' + pinUrl + '")');
        themeEl.style.setProperty('--omniva-pm-terminal-icon', 'url("' + terminalUrl + '")');

        function setSelected(isSelected) {
            themeEl.classList.toggle('omniva-pm-selected', !!isSelected);
        }

        // Initial state: selected when the hidden <select> already has a value.
        var select = container.querySelector('select[name="omnivalt_parcel_terminal"]');
        if (!select && container.parentNode) {
            select = container.parentNode.querySelector('select[name="omnivalt_parcel_terminal"]');
        }
        setSelected(select && select.value);

        tmjs.sub('terminal-selected', function (data) {
            setSelected(data && data.id);
        });
    }

    /**
     * Wire the "install the app" promo block that is rendered server-side in
     * displayCarrierExtraContent.tpl (so it lives inside the carrier-extra
     * markup, not injected after the map container). The glue layer only:
     *   - toggles the block's visibility with the selection state, and
     *   - hands the QR modal off to the generic OmnivaPopup helper
     *     (omniva-front-popup.js), which re-homes it to <body> and wires the
     *     open/close behaviour.
     * Works the same in map and dropdown mode. Does nothing when the template
     * did not render the block (e.g. no QR image configured is still fine –
     * the text-only block shows, just without the QR button/modal).
     *
     * @param {Object}      tmjs      TerminalMapping instance.
     * @param {HTMLElement} container Module container element.
     */
    function setupAppPromo(tmjs, container) {
        if (!container || !container.parentNode) {
            return;
        }
        var scope = container.parentNode;
        var promo = scope.querySelector('.omniva-app-promo[data-omniva-app-for="' + container.id + '"]');
        if (!promo) {
            return;
        }

        var qrBtn = promo.querySelector('.omniva-app-promo-qr');
        var externalUrl = promo.getAttribute('data-omniva-app-url') || '';

        // On mobile devices the QR code is useless (the buyer is already on
        // their phone). Instead show an "open external" icon and open the
        // store landing page in a new tab. A viewport media query decides the
        // mode and keeps it in sync on resize/orientation change.
        var mobileQuery = (typeof global.matchMedia === 'function')
            ? global.matchMedia('(max-width: 767px)')
            : null;

        function isMobile() {
            return !!(mobileQuery && mobileQuery.matches && externalUrl);
        }

        function applyPromoMode() {
            promo.classList.toggle('omniva-app-promo-external', isMobile());
        }
        applyPromoMode();
        if (mobileQuery) {
            if (typeof mobileQuery.addEventListener === 'function') {
                mobileQuery.addEventListener('change', applyPromoMode);
            } else if (typeof mobileQuery.addListener === 'function') {
                // Safari < 14 fallback.
                mobileQuery.addListener(applyPromoMode);
            }
        }

        // The QR modal is a generic popup; OmnivaPopup handles re-homing it to
        // <body>, the open/close wiring and Esc. We open it manually (no
        // trigger passed) so the same button can branch between the desktop
        // popup and the mobile external link.
        var popup = null;
        var modal = scope.querySelector('.omniva-qr-modal[data-omniva-qr-for="' + container.id + '"]');
        if (modal && global.OmnivaPopup && typeof global.OmnivaPopup.attach === 'function') {
            popup = global.OmnivaPopup.attach({
                modal: modal,
                openClass: 'omniva-qr-modal-open',
                overlaySelector: '.omniva-qr-modal-overlay',
                closeSelector: '.omniva-qr-modal-close, .omniva-qr-modal-done'
            });
        }

        if (qrBtn) {
            qrBtn.addEventListener('click', function (e) {
                e.preventDefault();
                if (isMobile()) {
                    global.open(externalUrl, '_blank', 'noopener');
                } else if (popup) {
                    popup.open();
                }
            });
        }

        function setSelected(isSelected) {
            promo.classList.toggle('omniva-app-promo-visible', !!isSelected);
        }

        var select = container.querySelector('select[name="omnivalt_parcel_terminal"]');
        if (!select && container.parentNode) {
            select = container.parentNode.querySelector('select[name="omnivalt_parcel_terminal"]');
        }
        setSelected(select && select.value);

        tmjs.sub('terminal-selected', function (data) {
            setSelected(data && data.id);
        });
    }

    /**
     * The Omniva tile server and credit
     */
    var TILE_SERVER_URL = 'https://maps.omnivasiunta.lt/tile/{z}/{x}/{y}.png';
    var TILE_ATTRIBUTION =
        '&copy; <a href="https://www.omniva.lt">Omniva</a>' +
        ' | Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors';

    function getThemeRule(country) {
        // The library joins this string straight into className via `classList.join(' ')`,
        // so we can smuggle additional classes in here.
        var theme = String(country).toUpperCase() === 'FI'
            ? 'omnivalt-theme-matkahuolto'
            : 'omnivalt-theme-omniva';
        return 'omniva-tmjs ' + theme;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Build a "confirm bar" at the bottom of the map-modal sidebar. The bar
     * shows the currently highlighted parcel machine (name, address, comment)
     * on the left and a single "Confirm parcel machine" button on the right.
     *
     * This implements a two-step selection (highlight, then confirm) WITHOUT
     * touching the TerminalMapping library:
     *  - The library already only highlights a row (adds `tmjs-active`) on
     *    click in map mode; it publishes `terminal-selected` only from the
     *    per-row select button. We hide those per-row buttons via CSS.
     *  - The confirm button publishes `terminal-selected` with the active
     *    location, reusing the existing handler (save to <select> + close).
     *  - We wrap `tmjs.map.setActiveLocation` to refresh the bar whenever the
     *    highlighted machine changes.
     *
     * @param {Object} tmjs    TerminalMapping instance (already initialised).
     * @param {Object} strings Translation strings (from buildStrings()).
     */
    function setupConfirmBar(tmjs, strings) {
        if (!tmjs.dom || !tmjs.dom.UI || !tmjs.dom.UI.modal) {
            return;
        }
        var sidebar = tmjs.dom.UI.modal.querySelector('.tmjs-terminal-sidebar');
        if (!sidebar || sidebar.querySelector('.omniva-confirm-bar')) {
            return;
        }

        var bar = document.createElement('div');
        bar.className = 'omniva-confirm-bar';
        bar.innerHTML =
            '<div class="omniva-confirm-selected">'
            + '<span class="omniva-confirm-placeholder">' + escapeHtml(strings.select_pickup_point) + '</span>'
            + '<span class="omniva-confirm-name"></span>'
            + '<span class="omniva-confirm-address"></span>'
            + '<span class="omniva-confirm-comment"></span>'
            + '</div>'
            + '<button type="button" class="omniva-confirm-btn" disabled>' + escapeHtml(strings.confirm_btn) + '</button>';
        sidebar.appendChild(bar);

        var btn = bar.querySelector('.omniva-confirm-btn');
        var nameEl = bar.querySelector('.omniva-confirm-name');
        var addressEl = bar.querySelector('.omniva-confirm-address');
        var commentEl = bar.querySelector('.omniva-confirm-comment');

        function updateBar(location) {
            var hasSelection = !!(location && location.id);
            bar.classList.toggle('omniva-has-selection', hasSelection);
            btn.disabled = !hasSelection;
            if (!hasSelection) {
                return;
            }
            nameEl.textContent = (location.name || '').toString().trim();
            var address = [location.address, location.city]
                .filter(function (s) { return s && String(s).trim().length; })
                .map(function (s) { return String(s).trim(); })
                .join(', ');
            addressEl.textContent = address;
            commentEl.textContent = (location.comment || '').toString().trim();
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var selected = tmjs.map && typeof tmjs.map.getActiveLocation === 'function'
                ? tmjs.map.getActiveLocation()
                : null;
            if (selected) {
                tmjs.publish('terminal-selected', selected);
            }
        });

        // Refresh the bar whenever the highlighted machine changes.
        if (tmjs.map && typeof tmjs.map.setActiveLocation === 'function') {
            var originalSetActiveLocation = tmjs.map.setActiveLocation.bind(tmjs.map);
            tmjs.map.setActiveLocation = function (location) {
                originalSetActiveLocation(location);
                updateBar(location);
            };
        }

        // Reflect any pre-selected machine on first open.
        if (tmjs.map && typeof tmjs.map.getActiveLocation === 'function') {
            updateBar(tmjs.map.getActiveLocation());
        }
    }

    /**
     * Keep the highlighted parcel machine clear of the floating sidebar.
     *
     * In the map modal the terminal sidebar floats over the left side of the
     * map. When a machine is highlighted the library centres it in the
     * geometric middle of the map, so on narrow screens (768-1024px) the
     * sidebar covers it. We wrap `tmjs.map.zoomToMarker` and, once the
     * library has finished centring, pan the active marker right into the
     * visible zone next to the sidebar. Outside that range the pan offset is
     * 0, so behaviour is unchanged. Does not touch the library.
     *
     * @param {Object} tmjs TerminalMapping instance (already initialised).
     */
    function setupSidebarOffsetPan(tmjs) {
        var mapModule = tmjs.map;
        if (!mapModule || typeof mapModule.zoomToMarker !== 'function') {
            return;
        }
        var originalZoomToMarker = mapModule.zoomToMarker.bind(mapModule);

        // Horizontal shift (px) needed to move the centred marker into the
        // area to the right of the sidebar; 0 when no shift should apply.
        function getOffset() {
            var width = global.innerWidth || document.documentElement.clientWidth;
            // Only while the sidebar floats over the map (>768px, below that
            // it stacks under the map) and the screen is narrow enough that it
            // covers the centred marker (<=1024px).
            if (!(width > 768 && width <= 1024)) {
                return 0;
            }
            if (!tmjs.dom || !tmjs.dom.UI || !tmjs.dom.UI.modal) {
                return 0;
            }
            var sidebar = tmjs.dom.UI.modal.querySelector('.tmjs-terminal-sidebar');
            if (!sidebar || !sidebar.offsetWidth) {
                return 0;
            }
            // Centre the marker in the visible zone to the right of the
            // sidebar by shifting it right by half the area the sidebar
            // occupies (its left inset + width).
            return (sidebar.offsetLeft + sidebar.offsetWidth) / 2;
        }

        mapModule.zoomToMarker = function (marker) {
            var leaflet = mapModule._map;
            var layer = mapModule._markerLayer;
            var offset = getOffset();
            if (!leaflet || !layer || !offset || typeof layer.zoomToShowLayer !== 'function') {
                originalZoomToMarker(marker);
                return;
            }
            // Let the library centre the marker first, then nudge it right
            // (negative x pans the map left, moving the marker right on screen).
            layer.zoomToShowLayer(marker, function () {
                leaflet.panBy([-offset, 0], { animate: true });
            });
        };

        // When the modal is opened with a machine already selected, the
        // library centres it via zoomMap() (a plain setView, not
        // zoomToMarker), so the wrap above doesn't run. Re-apply the offset
        // on open. Deferred so it runs after the other modal-opened handler
        // lays the map out (invalidateSize), giving correct dimensions.
        tmjs.sub('modal-opened', function () {
            var active = mapModule._activeLocation;
            if (!active || !active.coords) {
                return;
            }
            setTimeout(function () {
                var leaflet = mapModule._map;
                var offset = getOffset();
                if (!leaflet || !offset) {
                    return;
                }
                // On a re-open the map already has a size, so zoomMap()'s
                // setView animates; that animation would otherwise finish
                // after our pan and snap the marker back to centre. Stop it
                // and recentre without animation before shifting, so the
                // offset sticks on every open (not just the first).
                if (typeof leaflet.stop === 'function') {
                    leaflet.stop();
                }
                leaflet.setView(active.coords, leaflet.getZoom(), { animate: false });
                leaflet.panBy([-offset, 0], { animate: false });
            }, 0);
        });
    }

    /**
     * Replace the library's "Use my location" feedback. By default the
     * library renders a spinner inside the search-result area while the
     * browser resolves the position. Instead we keep the feedback inside the
     * button itself: swap its icon for a spinning loader and change its label
     * to "Locating", and suppress the search-result spinner.
     *
     * Works without touching the TerminalMapping library:
     *  - A capture-phase click listener flips the button into the loading
     *    state BEFORE the library's own handler publishes 'add-search-loader'.
     *  - Our 'add-search-loader' subscriber (registered after the library's)
     *    clears the search-result spinner while geolocation is in progress.
     *  - The 'geolocation' event (success) resets the button; a timeout acts
     *    as a fallback for the error case (the library emits no error event).
     *
     * @param {Object}      tmjs      TerminalMapping instance.
     * @param {HTMLElement} container Module container element.
     * @param {Object}      strings   Translation strings (from buildStrings()).
     */
    function setupGeolocationLoader(tmjs, container, strings) {
        var buttons = [];
        var roots = [container];
        if (tmjs.dom && tmjs.dom.UI && tmjs.dom.UI.modal) {
            roots.push(tmjs.dom.UI.modal);
        }
        roots.forEach(function (root) {
            if (!root || typeof root.querySelectorAll !== 'function') {
                return;
            }
            var found = root.querySelectorAll('.tmjs-geolocation-btn');
            for (var i = 0; i < found.length; i++) {
                buttons.push(found[i]);
            }
        });
        if (!buttons.length) {
            return;
        }

        var loadingActive = false;
        var resetTimer = null;

        function setLoading(on) {
            loadingActive = on;
            buttons.forEach(function (btn) {
                var label = btn.querySelector('span');
                if (on) {
                    if (label && btn.getAttribute('data-omniva-geo-label') === null) {
                        btn.setAttribute('data-omniva-geo-label', label.textContent);
                        label.textContent = strings.locating_btn;
                    }
                    btn.classList.add('omniva-geo-loading');
                    // Disable the button while geolocation is in progress so
                    // the buyer can't fire many lookups in a row. These are
                    // <a> elements, where the `disabled` attribute is ignored,
                    // so block interaction via aria-disabled + a CSS class
                    // (pointer-events:none) and remove it from the tab order.
                    btn.setAttribute('aria-disabled', 'true');
                    btn.classList.add('omniva-geo-disabled');
                    btn.setAttribute('data-omniva-geo-tabindex', btn.getAttribute('tabindex') || '');
                    btn.setAttribute('tabindex', '-1');
                } else {
                    var saved = btn.getAttribute('data-omniva-geo-label');
                    if (label && saved !== null) {
                        label.textContent = saved;
                        btn.removeAttribute('data-omniva-geo-label');
                    }
                    btn.classList.remove('omniva-geo-loading');
                    btn.removeAttribute('aria-disabled');
                    btn.classList.remove('omniva-geo-disabled');
                    var savedTabindex = btn.getAttribute('data-omniva-geo-tabindex');
                    if (savedTabindex) {
                        btn.setAttribute('tabindex', savedTabindex);
                    } else {
                        btn.removeAttribute('tabindex');
                    }
                    btn.removeAttribute('data-omniva-geo-tabindex');
                }
            });
        }

        function reset() {
            if (resetTimer) {
                clearTimeout(resetTimer);
                resetTimer = null;
            }
            setLoading(false);
        }

        buttons.forEach(function (btn) {
            // Capture phase: run before the library's bubble-phase handler so
            // loadingActive is true by the time 'add-search-loader' fires.
            btn.addEventListener('click', function (e) {
                // Ignore repeat clicks while a lookup is already running.
                if (loadingActive) {
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }
                setLoading(true);
                if (resetTimer) {
                    clearTimeout(resetTimer);
                }
                // Fallback: geolocation denial/timeout emits no event.
                resetTimer = setTimeout(reset, 20000);
            }, true);
        });

        // Suppress the library's search-result spinner during geolocation.
        tmjs.sub('add-search-loader', function () {
            if (!loadingActive) {
                return;
            }
            var sr = tmjs.dom && typeof tmjs.dom._searchResultEl === 'function'
                ? tmjs.dom._searchResultEl()
                : null;
            if (sr) {
                sr.innerHTML = '';
            }
        });

        // Position resolved successfully → restore the button.
        tmjs.sub('geolocation', reset);

        // Geolocation failure (denied/unavailable/timeout) emits no event –
        // the library only writes the error message into the search-result
        // element and logs to the console. Watch that element while loading
        // and reset the button as soon as the error text appears, instead of
        // waiting for the fallback timeout.
        if (typeof global.MutationObserver === 'function'
            && tmjs.dom && typeof tmjs.dom._searchResultEl === 'function') {
            var srEl = tmjs.dom._searchResultEl();
            if (srEl) {
                var observer = new global.MutationObserver(function () {
                    // Ignore our own clearing (empty content) and changes when
                    // not loading. Any non-empty text here during loading is
                    // the library's error message.
                    if (loadingActive && srEl.textContent && srEl.textContent.trim().length) {
                        reset();
                    }
                });
                observer.observe(srEl, { childList: true, characterData: true, subtree: true });
            }
        }
    }

    /**
     * Initialise a single TerminalMapping instance for the given container.
     *
     * @param {Object} options
     * @param {HTMLElement} options.container       Wrapper element where TMJS markup is mounted.
     * @param {Array}       options.terminals       Raw terminal list (legacy array format).
     * @param {String}      options.country         ISO 2-letter country code.
     * @param {String}      [options.postcode]      Buyer postcode (helps API/sort).
     * @param {String}      [options.city]          Buyer city (used for initial address geocode).
     * @param {String}      [options.address]       Buyer street address (used for initial address geocode).
     * @param {String}      [options.displayMode]   'map' (default) or 'dropdown'.
     * @param {String}      [options.selectedId]    Pre-selected terminal id.
     * @param {HTMLElement} [options.selectEl]      Hidden <select name="omnivalt_parcel_terminal">.
     * @returns {Object|null} TerminalMapping instance or null on failure.
     */
    function init(options) {
        if (typeof TerminalMapping === 'undefined') {
            console.warn('OMNIVA TMJS: TerminalMapping library not loaded.');
            return null;
        }
        if (!options || !options.container) {
            return null;
        }

        var container = options.container;
        if (!container.id) {
            container.id = 'omniva_tmjs_' + Math.random().toString(36).slice(2, 9);
        }
        var containerId = container.id;

        // Guard against double-init (e.g. when the carrier list is re-rendered).
        if (instances[containerId]) {
            return instances[containerId];
        }

        var displayMode = options.displayMode === 'dropdown' ? 'dropdown' : 'map';
        var country = (options.country || '').toUpperCase();
        var iconUrl = getMarkerIcon(country);

        var tmjs = new TerminalMapping(); // NO API mode – list passed in via terminalList
        tmjs.prefix = '[OMNIVA_TMJS_' + containerId + '] ';
        tmjs.setImagesPath(getImagesPath());
        tmjs.setTranslation(buildStrings());
        tmjs.dom.setContainerParent(container);

        // Reference point from the buyer's geocoded address. Captured from
        // the `search-result` event so we can re-center the map every time
        // the modal opens (Leaflet does not render correctly while the map
        // div is hidden, and the modal's own zoomMap() would otherwise
        // reset the view to the country default).
        var buyerRef = null;
        tmjs.sub('search-result', function (candidate) {
            if (candidate && candidate.location
                && typeof candidate.location.y === 'number'
                && typeof candidate.location.x === 'number') {
                buyerRef = { lat: candidate.location.y, lng: candidate.location.x };
            }
        });
        tmjs.sub('modal-opened', function () {
            // Some PrestaShop themes wrap the carrier-extra block with
            // `.delivery-option__extra { overflow: hidden; }` to animate
            // its expand/collapse. That clips the dropdown panel that
            // opens below the trigger. Add a marker class on that
            // ancestor (if found) so our CSS can re-enable overflow.
            // Used as a fallback for browsers without `:has()` support.
            var extra = container.closest && container.closest('.delivery-option__extra');
            if (extra) {
                extra.classList.add('omniva-extra-open');
            }

            if (!tmjs.map || !tmjs.map._map) {
                return;
            }
            // Defer to next frame so the modal element is laid out before
            // Leaflet recalculates its dimensions.
            setTimeout(function () {
                try {
                    tmjs.map._map.invalidateSize();
                    if (buyerRef && !tmjs.map._activeLocation) {
                        tmjs.map.addReferencePosition(buyerRef);
                    }
                    // When a terminal is pre-selected, the original (pin.svg)
                    // marker is hidden by adding 'tmjs-active-marker-hidden' to
                    // its icon. On the first open the map is still hidden when
                    // that runs, so the icon DOM element does not exist yet and
                    // the original marker stays visible alongside the selected
                    // dummy. Re-run it now that the map is laid out.
                    if (tmjs.map._activeLocation && typeof tmjs.map.updateActiveMarkerClass === 'function') {
                        tmjs.map.updateActiveMarkerClass();
                    }
                } catch (e) { /* ignore */ }
            }, 0);
        });
        tmjs.sub('modal-closed', function () {
            var extra = container.closest && container.closest('.delivery-option__extra');
            if (extra) {
                extra.classList.remove('omniva-extra-open');
            }
        });

        // Swap the terminal-list header text depending on whether the list is
        // currently sorted by distance. The library appends a
        // `.tmjs-terminal-distance` element to every row only after a search
        // or geolocation has added distances, so its presence is a reliable
        // signal. The header (<h3 data-tmjs-string="terminal_list_header">)
        // only exists in map (modal) mode.
        tmjs.sub('list-updated', function (listEl) {
            if (!tmjs.dom || !tmjs.dom.UI || !tmjs.dom.UI.modal) {
                return;
            }
            var header = tmjs.dom.UI.modal.querySelector('[data-tmjs-string="terminal_list_header"]');
            if (!header) {
                return;
            }
            var strings = buildStrings();
            var sorted = !!(listEl && typeof listEl.querySelector === 'function'
                && listEl.querySelector('.tmjs-terminal-distance'));
            header.textContent = sorted
                ? strings.terminal_list_header_sorted
                : strings.terminal_list_header;
        });

        tmjs.sub('tmjs-ready', function (data) {
            // The modal (map mode) search input is rendered without a
            // placeholder by the library. Add one so the field hints at
            // searching by address. The dropdown input already has its own
            // placeholder (dropdown_search_placeholder).
            if (displayMode === 'map' && tmjs.dom && tmjs.dom.UI && tmjs.dom.UI.modal) {
                var searchInput = tmjs.dom.UI.modal.querySelector('.tmjs-search-input');
                if (searchInput) {
                    searchInput.setAttribute('placeholder', buildStrings().search_placeholder);
                }
            }

            // Use the module's own icons instead of the (unmodifiable) ones
            // shipped inside the lib folder.
            applyButtonIcon(container, '.tmjs-search-btn', getSearchIconUrl());
            applyButtonIcon(container, '.tmjs-geolocation-btn', getGeolocationIconUrl());
            if (tmjs.dom && tmjs.dom.UI && tmjs.dom.UI.modal) {
                applyButtonIcon(tmjs.dom.UI.modal, '.tmjs-search-btn', getSearchIconUrl());
                applyButtonIcon(tmjs.dom.UI.modal, '.tmjs-geolocation-btn', getGeolocationIconUrl());
            }

            // Show the geolocation progress inside the "Use my location"
            // button (spinner icon + "Locating" label) instead of the
            // library's search-result spinner.
            try {
                setupGeolocationLoader(tmjs, container, buildStrings());
            } catch (e) {
                console.warn('OMNIVA TMJS: failed to set up geolocation loader', e);
            }

            // Add a leading icon to the selected-terminal trigger label
            // (pin-marker when nothing is selected, terminal once chosen).
            try {
                setupSelectedTerminalIcon(tmjs, container);
            } catch (e) {
                console.warn('OMNIVA TMJS: failed to set up selected-terminal icon', e);
            }

            // Show the "install the app" promo (with the QR-code modal)
            // below the selector once a parcel machine is selected. The
            // markup is rendered server-side; this only wires it up.
            try {
                setupAppPromo(tmjs, container);
            } catch (e) {
                console.warn('OMNIVA TMJS: failed to set up app promo', e);
            }

            if (displayMode === 'map' && iconUrl && tmjs.map) {
                try {
                    // pin.svg is square (48x48), so use 34x34 to keep its
                    // aspect ratio (no distortion). Anchor at bottom-center.
                    tmjs.map.Icon.prototype.options.iconSize = [34, 34];
                    tmjs.map.Icon.prototype.options.iconAnchor = [17, 34];
                    tmjs.map.Icon.prototype.options.popupAnchor = [0, -34];
                    tmjs.map.createIcon('omniva', iconUrl);
                    tmjs.map.createIcon('default', iconUrl);
                    var referenceUrl = getReferenceIcon();
                    if (referenceUrl) {
                        // Reference (buyer position) marker is anchored at its
                        // center so the icon points at the position, unlike the
                        // parcel-machine pins which are anchored at the bottom.
                        tmjs.map._icons['reference'] = new tmjs.map.Icon({
                            iconUrl: referenceUrl,
                            iconSize: [34, 34],
                            iconAnchor: [17, 17],
                            popupAnchor: [0, -17]
                        });
                    }
                    tmjs.map.refreshMarkerIcons();
                } catch (e) {
                    console.warn('OMNIVA TMJS: failed to set custom icon', e);
                }

                // Swap the active marker to the "selected" pin. TMJS builds a
                // dummy marker (with the 'tmjs-active-marker' class) for the
                // active location, so we hook addMarker and hand that marker a
                // dedicated "selected" Leaflet icon. Doing it at the icon level
                // (rather than overwriting _icon.src) survives every later
                // refreshMarkerIcons() / re-render, including the very first
                // open with a pre-selected terminal.
                var selectedUrl = getSelectedMarkerIcon(country);
                if (selectedUrl && typeof tmjs.map.addMarker === 'function') {
                    var selectedIcon = new tmjs.map.Icon({
                        iconUrl: selectedUrl,
                        iconSize: [34, 34],
                        iconAnchor: [17, 34],
                        popupAnchor: [0, -34]
                    });
                    var originalAddMarker = tmjs.map.addMarker.bind(tmjs.map);
                    tmjs.map.addMarker = function (latLong, id, identifier, className) {
                        var marker = originalAddMarker(latLong, id, identifier, className);
                        if (className && className.indexOf('tmjs-active-marker') !== -1) {
                            marker.setIcon(selectedIcon);
                        }
                        return marker;
                    };
                }
            }

            // Geocode the buyer's checkout address (street/city/postcode)
            // and have TMJS sort the parcel-machine list by distance from
            // that point. This also pins a reference marker on the map.
            var searchTerm = [options.address, options.city, options.postcode]
                .map(function (s) { return s == null ? '' : String(s).trim(); })
                .filter(function (s) { return s.length; })
                .join(', ');
            if (searchTerm && tmjs.dom && typeof tmjs.dom.searchNearest === 'function') {
                try {
                    tmjs.dom.searchNearest(searchTerm);
                } catch (e) {
                    console.warn('OMNIVA TMJS: initial address search failed', e);
                }
            }

            if (options.selectedId && data && data.map && typeof data.map.getLocationById === 'function') {
                var selected = data.map.getLocationById(options.selectedId);
                if (selected) {
                    tmjs.publish('terminal-selected', selected);
                }
            }
        });

        tmjs.sub('terminal-selected', function (data) {
            if (!data) {
                return;
            }

            // Forward the selection to the hidden <select> – its existing
            // change handler in omniva.js performs the AJAX save and clears
            // the validation error.
            if (options.selectEl) {
                var $j = global.jQuery || global.$;
                if ($j) {
                    var $sel = $j(options.selectEl);
                    if ($sel.val() !== String(data.id)) {
                        $sel.val(String(data.id)).trigger('change');
                    }
                } else {
                    options.selectEl.value = String(data.id);
                    options.selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            try {
                tmjs.dom.setActiveTerminal(data.id);
            } catch (e) { /* ignore */ }
            // NOTE: do NOT publish 'terminal-selected-text' – that would
            // overwrite the library's two-line trigger label (name on the
            // first line, address/city on the second) with a single-line
            // innerText, which is exactly what we want to keep here.
            if (displayMode === 'map') {
                // In map mode the library renders only the name in the
                // inline `.tmjs-selected-terminal` element (innerText).
                // Replace it with a two-line layout matching the
                // dropdown trigger: name on row 1, address (+ city) on
                // row 2. Runs after the library's own subscriber.
                try {
                    var sel = container.querySelector('.tmjs-selected-terminal');
                    if (sel) {
                        var name = (data.name || '').toString().trim();
                        var address = (data.address || '').toString().trim();
                        var city = (data.city || '').toString().trim();
                        var escape = function (s) {
                            return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        };
                        var secondary = [address, city].filter(function (s) { return s && s.length; }).join(', ');
                        if (name && secondary) {
                            sel.innerHTML = '<span class="omniva-selected-name">' + escape(name) + '</span>'
                                + '<span class="omniva-selected-address">' + escape(secondary) + '</span>';
                        } else if (name || secondary) {
                            sel.innerText = name || secondary;
                        }
                    }
                } catch (e) { /* ignore */ }

                // Once a parcel machine is chosen, relabel the open-modal
                // button from "Select parcel machine" to "Change". Drop the
                // data-tmjs-string binding so the library can't reset it.
                try {
                    var openBtn = container.querySelector('.tmjs-open-modal-btn');
                    if (openBtn) {
                        openBtn.removeAttribute('data-tmjs-string');
                        openBtn.innerText = buildStrings().change_btn;
                    }
                } catch (e) { /* ignore */ }

                tmjs.publish('close-map-modal');
            }
        });

        var initOptions = {
            country_code: country,
            identifier: 'omniva',
            isModal: displayMode === 'map',
            displayMode: displayMode,
            hideContainer: false,
            hideSelectBtn: false,
            cssThemeRule: getThemeRule(country),
            terminalList: convertTerminals(options.terminals),
            postal_code: options.postcode || '',
            customTileServerUrl: TILE_SERVER_URL,
            customTileAttribution: TILE_ATTRIBUTION,
            parseMapTooltip: function (location) {
                var text = [location.address, location.city]
                    .filter(function (s) { return s && String(s).length; })
                    .join(', ');
                if (location.id) {
                    text += (text.length ? ' ' : '') + '<span class="point-id">[ID: ' + location.id + ']</span>';
                }
                return text;
            },
            parseLocationName: function (location) {
                // List items show the parcel-machine name on the first
                // line and the street address + distance on a second
                // line. When the address is missing only the name is
                // rendered and the library appends the distance span on
                // the same line, which is fine.
                //
                // The trigger label (when a terminal is selected) uses
                // the library's own two-line layout (primary = name,
                // secondary = address + city) automatically because we
                // don't override it via 'terminal-selected-text'.
                var name = (location.name || '').toString().trim();
                var address = (location.address || '').toString().trim();
                var escape = function (s) {
                    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                };
                if (name && address) {
                    return '<span class="omniva-terminal-name-text">' + escape(name) + '</span>'
                        + '<span class="omniva-terminal-street">' + escape(address) + '</span>';
                }
                return name || address;
            },
            parseLocationComment: function (location) {
                return location.comment || '';
            }
        };

        tmjs.init(initOptions);

        if (displayMode === 'map') {
            // Two-step selection UI: highlight a machine in the list, then
            // confirm it with a single button at the bottom of the sidebar.
            try {
                setupConfirmBar(tmjs, buildStrings());
            } catch (e) {
                console.warn('OMNIVA TMJS: failed to set up confirm bar', e);
            }

            // Keep the highlighted marker clear of the floating sidebar on
            // narrow (768-1024px) screens.
            try {
                setupSidebarOffsetPan(tmjs);
            } catch (e) {
                console.warn('OMNIVA TMJS: failed to set up sidebar offset pan', e);
            }
        }

        instances[containerId] = tmjs;
        return tmjs;
    }

    /**
     * Tear down an existing instance and remove its DOM nodes. Useful when
     * the checkout step gets re-rendered by the theme.
     */
    function destroy(containerId) {
        var tmjs = instances[containerId];
        if (!tmjs) {
            return false;
        }
        try {
            var modalId = tmjs.containerId ? tmjs.containerId + '_modal' : null;
            var inner = tmjs.containerId ? document.getElementById(tmjs.containerId) : null;
            if (inner && inner.parentNode) {
                inner.parentNode.removeChild(inner);
            }
            if (modalId) {
                var modal = document.getElementById(modalId);
                if (modal && modal.parentNode) {
                    modal.parentNode.removeChild(modal);
                }
            }
        } catch (e) { /* ignore */ }
        delete instances[containerId];
        return true;
    }

    /**
     * Discover all TMJS containers rendered by the module and boot them.
     * Containers must carry `data-omniva-tmjs` and may carry:
     *   data-omniva-tmjs-mode     – "map" | "dropdown" (default "map")
     *   data-omniva-tmjs-country  – ISO country code
     *   data-omniva-tmjs-postcode – buyer postcode
     *   data-omniva-tmjs-city     – buyer city
     *   data-omniva-tmjs-address  – buyer street address
     */
    function autoBoot() {
        var rawList = global.omnivalt_terminals || [];
        var nodes = document.querySelectorAll('[data-omniva-tmjs]');
        // Hidden <select> may live as a sibling of the container rather
        // than as a descendant, depending on the template. Look up to the
        // shared parent before falling back to a descendant lookup.
        for (var i = 0; i < nodes.length; i++) {
            var container = nodes[i];
            if (container.getAttribute('data-omniva-tmjs-ready') === '1') {
                continue;
            }
            var selectEl = container.querySelector('select[name="omnivalt_parcel_terminal"]');
            if (!selectEl && container.parentNode) {
                selectEl = container.parentNode.querySelector('select[name="omnivalt_parcel_terminal"]');
            }
            var selectedId = selectEl ? selectEl.value : '';

            init({
                container: container,
                terminals: rawList,
                country: container.getAttribute('data-omniva-tmjs-country') || '',
                postcode: container.getAttribute('data-omniva-tmjs-postcode') || '',
                city: container.getAttribute('data-omniva-tmjs-city') || '',
                address: container.getAttribute('data-omniva-tmjs-address') || '',
                displayMode: container.getAttribute('data-omniva-tmjs-mode') || 'map',
                selectedId: selectedId,
                selectEl: selectEl
            });
            container.setAttribute('data-omniva-tmjs-ready', '1');
        }
    }

    global.OmnivaTerminalMap = {
        init: init,
        autoBoot: autoBoot,
        destroy: destroy,
        getInstance: function (id) { return instances[id]; },
        instances: instances
    };
})(window);
