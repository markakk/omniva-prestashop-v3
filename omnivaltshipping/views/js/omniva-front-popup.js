/**
 * Omniva Front Popup – a tiny, dependency-free popup/modal helper.
 *
 * It is intentionally generic so it can drive any "click a trigger, show a
 * modal" interaction in the storefront (the parcel-machine app QR code is the
 * first user, more may follow). It does NOT build any markup: the modal and
 * its trigger are expected to already exist in the DOM (rendered server-side),
 * which keeps the markup translatable/themable from templates.
 *
 * Behaviour provided:
 *   - optionally re-homes the modal element onto <body> so its position:fixed
 *     layout is never clipped by an overflow:hidden ancestor,
 *   - opens on trigger click, closes on overlay/close/done click and Esc,
 *   - toggles an "open" class (default "omniva-popup-open") on the modal,
 *   - manages a single keydown listener only while the popup is open,
 *   - is idempotent: attaching twice to the same modal is a no-op.
 *
 * Usage:
 *   var popup = OmnivaPopup.attach({
 *       modal: modalEl,                 // required: the modal root element
 *       trigger: buttonEl,              // optional: element(s) that open it
 *       openClass: 'omniva-qr-modal-open',
 *       overlaySelector: '.omniva-qr-modal-overlay',
 *       closeSelector: '.omniva-qr-modal-close, .omniva-qr-modal-done',
 *       moveToBody: true,
 *       onOpen: function (modal) {},
 *       onClose: function (modal) {}
 *   });
 *   popup.open();  popup.close();  popup.destroy();
 */
(function (global) {
    'use strict';

    var DATA_FLAG = 'omnivaPopupBound';

    function resolveElement(ref, scope) {
        if (!ref) {
            return null;
        }
        if (typeof ref === 'string') {
            return (scope || document).querySelector(ref);
        }
        // Already an element.
        return ref.nodeType === 1 ? ref : null;
    }

    function resolveElements(ref, scope) {
        if (!ref) {
            return [];
        }
        if (typeof ref === 'string') {
            return Array.prototype.slice.call((scope || document).querySelectorAll(ref));
        }
        if (ref.nodeType === 1) {
            return [ref];
        }
        // NodeList / array-like / array of elements.
        if (typeof ref.length === 'number') {
            return Array.prototype.slice.call(ref).filter(function (el) {
                return el && el.nodeType === 1;
            });
        }
        return [];
    }

    /**
     * Wire a modal element (and optional trigger) into an openable popup.
     *
     * @param {Object} options
     * @param {HTMLElement|string} options.modal             Modal root element or selector. Required.
     * @param {HTMLElement|NodeList|Array|string} [options.trigger]  Element(s) that open the popup on click.
     * @param {string} [options.openClass='omniva-popup-open']      Class toggled on the modal while open.
     * @param {string} [options.overlaySelector='.omniva-popup-overlay']  Backdrop element(s) that close on click.
     * @param {string} [options.closeSelector='.omniva-popup-close']      Element(s) inside the modal that close on click.
     * @param {boolean} [options.moveToBody=true]            Re-home the modal onto <body> on attach.
     * @param {boolean} [options.closeOnEsc=true]            Close when Escape is pressed.
     * @param {Function} [options.onOpen]                    Called with the modal after it opens.
     * @param {Function} [options.onClose]                   Called with the modal after it closes.
     * @returns {?Object} A controller ({ open, close, toggle, isOpen, destroy, modal }) or null.
     */
    function attach(options) {
        options = options || {};
        var scope = options.scope || document;
        var modal = resolveElement(options.modal, scope);
        if (!modal) {
            return null;
        }
        // Idempotent: return the existing controller if already bound.
        if (modal[DATA_FLAG]) {
            return modal[DATA_FLAG];
        }

        var openClass = options.openClass || 'omniva-popup-open';
        var overlaySelector = options.overlaySelector || '.omniva-popup-overlay';
        var closeSelector = options.closeSelector || '.omniva-popup-close';
        var closeOnEsc = options.closeOnEsc !== false;
        var moveToBody = options.moveToBody !== false;

        if (moveToBody && modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }

        function onKey(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                close();
            }
        }

        function isOpen() {
            return modal.classList.contains(openClass);
        }

        function open() {
            if (isOpen()) {
                return;
            }
            modal.classList.add(openClass);
            if (closeOnEsc) {
                document.addEventListener('keydown', onKey);
            }
            if (typeof options.onOpen === 'function') {
                options.onOpen(modal);
            }
        }

        function close() {
            if (!isOpen()) {
                return;
            }
            modal.classList.remove(openClass);
            document.removeEventListener('keydown', onKey);
            if (typeof options.onClose === 'function') {
                options.onClose(modal);
            }
        }

        function toggle() {
            if (isOpen()) {
                close();
            } else {
                open();
            }
        }

        // Track listeners so destroy() can clean up fully.
        var bound = [];
        function bind(el, type, handler) {
            el.addEventListener(type, handler);
            bound.push({ el: el, type: type, handler: handler });
        }

        resolveElements(options.trigger, scope).forEach(function (el) {
            bind(el, 'click', function (e) {
                e.preventDefault();
                open();
            });
        });
        resolveElements(overlaySelector, modal).forEach(function (el) {
            bind(el, 'click', close);
        });
        resolveElements(closeSelector, modal).forEach(function (el) {
            bind(el, 'click', function (e) {
                e.preventDefault();
                close();
            });
        });

        var controller = {
            modal: modal,
            open: open,
            close: close,
            toggle: toggle,
            isOpen: isOpen,
            destroy: function () {
                close();
                bound.forEach(function (b) {
                    b.el.removeEventListener(b.type, b.handler);
                });
                bound = [];
                delete modal[DATA_FLAG];
            }
        };

        modal[DATA_FLAG] = controller;
        return controller;
    }

    global.OmnivaPopup = {
        attach: attach
    };
})(window);
