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
            seach_header: t.seach_header || 'Search around',
            search_btn: t.search_btn || 'Find',
            modal_open_btn: t.modal_open_btn || 'Select parcel machine',
            geolocation_btn: t.geolocation_btn || 'Use my location',
            your_position: t.your_position || 'Distance calculated from this point',
            nothing_found: t.nothing_found || 'Nothing found',
            no_cities_found: t.no_cities_found || 'No cities found for your search term',
            geolocation_not_supported: t.geolocation_not_supported || 'Geolocation is not supported',
            select_pickup_point: t.select_pickup_point || 'Select a parcel machine',
            dropdown_placeholder: t.dropdown_placeholder || 'Choose a parcel machine...',
            dropdown_search_placeholder: t.dropdown_search_placeholder || 'Type to filter or search address by pressing Enter',
            find_nearest_btn: t.find_nearest_btn || 'Find nearest',
            no_terminals_match: t.no_terminals_match || 'No parcel machines match your filter',
            select_btn: t.select_btn || 'Select'
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

    function getMarkerIcon(country) {
        // Marker icons (sasi.png / sasi_mh.svg) live in the module's own
        // views/img/map/ folder, separated from the general module images
        // so they don't mix together.
        if (!global.omnivalt_params || !global.omnivalt_params.url || !global.omnivalt_params.url.parcel_machine_images) {
            return null;
        }
        var images = global.omnivalt_params.url.parcel_machine_images;
        return images + (String(country).toUpperCase() === 'FI' ? 'sasi_mh.svg' : 'sasi.png');
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
                } catch (e) { /* ignore */ }
            }, 0);
        });
        tmjs.sub('modal-closed', function () {
            var extra = container.closest && container.closest('.delivery-option__extra');
            if (extra) {
                extra.classList.remove('omniva-extra-open');
            }
        });

        tmjs.sub('tmjs-ready', function (data) {
            if (displayMode === 'map' && iconUrl && tmjs.map) {
                try {
                    tmjs.map.createIcon('omniva', iconUrl);
                    tmjs.map.createIcon('default', iconUrl);
                    tmjs.map.refreshMarkerIcons();
                } catch (e) {
                    console.warn('OMNIVA TMJS: failed to set custom icon', e);
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
