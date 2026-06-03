/**
 * Omniva front-end checkout helpers.
 *
 * The legacy jQuery $.fn.omniva map plugin was removed – the map / dropdown
 * UI is now provided by the Terminal Mapping JS library
 * (views/lib/terminal-mapping/) and bootstrapped from
 * views/js/omniva-front-map.js (window.OmnivaTerminalMap).
 *
 * The hidden <select name="omnivalt_parcel_terminal"> is still the source of
 * truth for the selected parcel machine: TMJS forwards the buyer's choice
 * via a synthetic change event, and the existing change handler below
 * (inside omnivaltDelivery.init) performs the AJAX save and clears the
 * validation error.
 */
/**
 * Universal notification utility for Omniva checkout validations.
 * Shows error messages in the currently active/visible checkout step.
 */
var omnivaltNotification = {
    /**
     * Show an error element in the current active checkout step.
     * @param {jQuery} element - The error element to show
     * @param {string} selector - CSS class selector to check if already in DOM (e.g. '.omniva-phone-error')
     * @param {string} message - Fallback message for alert()
     */
    show: function(element, selector, message) {
        if (!element || !element.length) return;

        // If already visible in DOM, just show it
        if ($(selector).length && $.contains(document, $(selector)[0])) {
            $(selector).show();
            return;
        }

        // Remove from DOM if detached
        element.detach();

        // Find the current active step and insert error there
        var container = this.findActiveStepContainer();
        if (container && container.length) {
            var point = this.findInsertionPoint(container);
            if (point.method === 'before') {
                point.target.before(element);
            } else {
                point.target.prepend(element);
            }
            element.show();
        } else {
            alert(message);
        }
    },

    hide: function(element, selector) {
        if (element) element.hide();
        $(selector).hide();
    },

    /**
     * Find the container of the currently active checkout step.
     * Uses multiple strategies to be theme-agnostic.
     */
    findActiveStepContainer: function() {
        // Strategy 1: PrestaShop 8 default - step with -current class
        var currentStep = $('.checkout-step.-current .step-content, .checkout-step.-current .content');
        if (currentStep.length && currentStep.is(':visible')) return currentStep.first();

        // Strategy 2: js-current-step marker
        var jsCurrentStep = $('.js-current-step .step-content, .js-current-step .content');
        if (jsCurrentStep.length && jsCurrentStep.is(':visible')) return jsCurrentStep.first();

        // Strategy 3: Find visible step content (payment or delivery)
        var visibleContent = $('#checkout-payment-step .content:visible, #checkout-delivery-step .content:visible');
        if (visibleContent.length) return visibleContent.last();

        // Strategy 4: Look for visible payment confirmation area
        var paymentArea = $('#payment-confirmation:visible').closest('.content, .step-content, section');
        if (paymentArea.length) return paymentArea.first();

        // Strategy 5: Delivery form itself
        var deliveryForm = $('form#js-delivery:visible');
        if (deliveryForm.length) return deliveryForm;

        // Strategy 6: Find any visible checkout section with a form
        var visibleForm = $('#checkout form:visible').last().parent();
        if (visibleForm.length) return visibleForm;

        return null;
    },

    /**
     * Find the best insertion point within a container - above buttons or at top of form.
     */
    findInsertionPoint: function(container) {
        // Try to find buttons wrapper and insert before it
        var buttons = container.find('.buttons-wrapper, .form-footer, [id*="payment-confirmation"]').filter(':visible');
        if (buttons.length) return { target: buttons.first(), method: 'before' };

        // Try to find submit/action buttons
        var submitBtn = container.find('button[type="submit"], .btn-primary').filter(':visible');
        if (submitBtn.length) {
            var btnParent = submitBtn.first().parent();
            return { target: btnParent, method: 'before' };
        }

        // Fallback - prepend to container (top of the step)
        return { target: container, method: 'prepend' };
    },

};

/**
 * Phone number check for Omniva carriers.
 * Prevents moving to payment step or placing order when no phone number is entered
 * and an Omniva carrier is selected.
 */
var omnivaltPhoneCheck = {
    initialized: false,
    errorElement: null,

    init: function() {
        if (this.initialized) return;
        if (!omnivalt_params.phone_check || !omnivalt_params.phone_check.enabled) return;
        this.initialized = true;
        this.phoneValid = null; // null = not checked yet

        var self = this;

        // Pre-check phone on init
        self.checkPhoneAjax();

        // Intercept delivery form submission
        $('form#js-delivery').on('submit.OmnivaPhone', function(e) {
            if (!self.isOmnivaCarrierSelected()) return true;
            if (self.phoneValid === true) {
                self.hideError();
                return true;
            }
            e.preventDefault();
            self.checkPhoneAjax(function(hasPhone) {
                if (hasPhone) {
                    self.hideError();
                    $('form#js-delivery').off('submit.OmnivaPhone').submit();
                } else {
                    self.showError();
                }
            });
            return false;
        });

        // Monitor payment confirmation
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('#payment-confirmation button, #payment-confirmation [type="submit"]');
            if (btn) {
                if (!self.isOmnivaCarrierSelected()) return true;
                if (self.phoneValid === true) {
                    self.hideError();
                    return true;
                }
                e.preventDefault();
                e.stopImmediatePropagation();
                self.checkPhoneAjax(function(hasPhone) {
                    if (hasPhone) {
                        self.hideError();
                        $(btn).click();
                    } else {
                        self.showError();
                    }
                });
                return false;
            }
        }, true);

        document.addEventListener('submit', function(e) {
            var form = e.target.closest('#payment-confirmation form');
            if (form) {
                if (!self.isOmnivaCarrierSelected()) return true;
                if (self.phoneValid === true) {
                    self.hideError();
                    return true;
                }
                e.preventDefault();
                e.stopImmediatePropagation();
                self.checkPhoneAjax(function(hasPhone) {
                    if (hasPhone) {
                        self.hideError();
                        $(form).submit();
                    } else {
                        self.showError();
                    }
                });
                return false;
            }
        }, true);

        // Re-check phone when address step is updated
        if (typeof prestashop !== 'undefined') {
            prestashop.on('updatedDeliveryForm', function() {
                self.phoneValid = null;
                self.checkPhoneAjax();
                if (!self.isOmnivaCarrierSelected()) {
                    self.hideError();
                }
            });
        }

        // Hide error immediately when buyer switches to a non-Omniva carrier
        $(document).on('change', 'input[name^="delivery_option"]', function() {
            if (!self.isOmnivaCarrierSelected()) {
                self.hideError();
            }
        });
    },

    checkPhoneAjax: function(callback) {
        var self = this;
        $.ajax({
            type: 'POST',
            url: omnivalt_params.url.controller_ajax,
            dataType: 'json',
            data: 'action=checkPhone&token=' + omnivalt_params.token,
            success: function(response) {
                self.phoneValid = response.has_phone;
                if (callback) callback(response.has_phone);
            },
            error: function() {
                self.phoneValid = null;
                if (callback) callback(false);
            }
        });
    },

    isOmnivaCarrierSelected: function() {
        var checkedDelivery = $('input[name^="delivery_option"]:checked');
        if (!checkedDelivery.length) return false;
        var carrierId = parseInt(checkedDelivery.val());
        if (!carrierId) return false;
        return omnivalt_params.phone_check.all_carrier_ids.indexOf(carrierId) !== -1;
    },

    showError: function() {
        if (!this.errorElement) {
            this.errorElement = $('<div class="omniva-phone-error alert alert-danger mt-3 mb-3" role="alert">' +
                omnivalt_text.phone_required_error + '</div>');
        }
        omnivaltNotification.show(this.errorElement, '.omniva-phone-error', omnivalt_text.phone_required_error);
    },

    hideError: function() {
        omnivaltNotification.hide(this.errorElement, '.omniva-phone-error');
    }
};

/**
 * COD restriction for international shipments.
 * Prevents order placement when COD payment is selected with an Omniva carrier
 * and the delivery country is not in the domestic countries list.
 */
var omnivaltCodRestriction = {
    initialized: false,
    errorElement: null,

    init: function() {
        if (this.initialized) return;
        if (!omnivalt_params.cod_restriction) return;
        this.initialized = true;

        var self = this;
        // Monitor payment confirmation - use capture phase to intercept before other handlers
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('#payment-confirmation button, #payment-confirmation [type="submit"]');
            if (btn && !self.validate()) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        }, true);

        document.addEventListener('submit', function(e) {
            var form = e.target.closest('#payment-confirmation form');
            if (form && !self.validate()) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        }, true);

        // Monitor payment option changes to show/hide warning
        $(document).on('change', 'input[name="payment-option"]', function() {
            self.checkAndWarn();
        });

        // Listen to prestashop events for delivery changes
        if (typeof prestashop !== 'undefined') {
            prestashop.on('updatedDeliveryForm', function() {
                self.checkAndWarn();
            });
        }
    },

    getSelectedCarrierId: function() {
        var checkedDelivery = $('input[name^="delivery_option"]:checked');
        if (checkedDelivery.length) {
            var val = checkedDelivery.val();
            // Value format in PS8 is "{carrier_id},"
            return parseInt(val);
        }
        return 0;
    },

    getSelectedPaymentModule: function() {
        var checkedPayment = $('input[name="payment-option"]:checked');
        if (checkedPayment.length) {
            var paymentOptionId = checkedPayment.attr('id');
            // In PS8, the module name is in data-module-name attribute or in the form action
            var form = $('#' + paymentOptionId + '-additional-information').closest('.payment-option')
                .find('form');
            if (form.length) {
                var action = form.attr('action') || '';
                for (var i = 0; i < omnivalt_params.cod_restriction.cod_modules.length; i++) {
                    if (action.indexOf(omnivalt_params.cod_restriction.cod_modules[i]) !== -1) {
                        return omnivalt_params.cod_restriction.cod_modules[i];
                    }
                }
            }
            var container = checkedPayment.closest('.payment-option');
            var moduleName = container.find('[data-module-name]').data('module-name') 
                || checkedPayment.data('module-name')
                || '';
            if (moduleName) return moduleName;

            // Fallback: check the payment confirmation form action
            var confirmForm = $('#payment-confirmation form');
            if (confirmForm.length) {
                var confirmAction = confirmForm.attr('action') || '';
                for (var i = 0; i < omnivalt_params.cod_restriction.cod_modules.length; i++) {
                    if (confirmAction.indexOf(omnivalt_params.cod_restriction.cod_modules[i]) !== -1) {
                        return omnivalt_params.cod_restriction.cod_modules[i];
                    }
                }
            }
        }
        return '';
    },

    isCodPaymentSelected: function() {
        var checkedPayment = $('input[name="payment-option"]:checked');
        if (!checkedPayment.length) return false;

        var codModules = omnivalt_params.cod_restriction.cod_modules;
        var paymentModule = this.getSelectedPaymentModule();
        if (paymentModule && codModules.indexOf(paymentModule) !== -1) {
            return true;
        }

        // Additional detection: check payment option container for COD module names
        var paymentOptionId = checkedPayment.attr('id');
        var optionContainer = checkedPayment.closest('.payment-option');
        var containerHtml = (optionContainer.html() || '').toLowerCase();
        
        for (var i = 0; i < codModules.length; i++) {
            if (containerHtml.indexOf(codModules[i]) !== -1) {
                return true;
            }
        }
        return false;
    },

    isInternationalCarrierSelected: function() {
        var carrierId = this.getSelectedCarrierId();
        if (!carrierId) return false;
        return omnivalt_params.cod_restriction.international_carrier_ids.indexOf(carrierId) !== -1;
    },

    validate: function() {
        if (!omnivalt_params.cod_restriction) return true;
        if (!this.isInternationalCarrierSelected()) return true;
        if (!this.isCodPaymentSelected()) return true;

        // International carrier + COD: block order
        this.showError();
        return false;
    },

    checkAndWarn: function() {
        if (!omnivalt_params.cod_restriction) return;
        if (this.isInternationalCarrierSelected() && this.isCodPaymentSelected()) {
            this.showError();
        } else {
            this.hideError();
        }
    },

    showError: function() {
        if (!this.errorElement) {
            this.errorElement = $('<div class="omniva-cod-error alert alert-danger mt-3 mb-0" role="alert">' +
                omnivalt_text.cod_international_error + '</div>');
        }
        if (!$('.omniva-cod-error').length) {
            var buttonsWrapper = $('#payment-confirmation').closest('.buttons-wrapper');
            if (buttonsWrapper.length) {
                buttonsWrapper.before(this.errorElement);
            } else {
                $('.payment-options__list').after(this.errorElement);
            }
        }
        this.errorElement.show();
    },

    hideError: function() {
        if (this.errorElement) {
            this.errorElement.hide();
        }
        $('.omniva-cod-error').hide();
    }
};

var omnivaltDelivery = {
    terminalValidationInitialized: false,
    terminalErrorElement: null,
    terminalValid: null, // null = not checked, true/false = result

    init : function() {
        console.groupCollapsed('OMNIVA: Initializing Omniva terminal carrier');
        var self = this;
        
        console.log('Updating events...');

        // Intercept delivery form submit
        $('form#js-delivery').off('submit.Omniva').on('submit.Omniva', function(e){
            if (!self.isTerminalCarrierSelected()) return true;
            if (!self.validateTerminalClient()) {
                e.preventDefault();
                return false;
            }
            return true;
        });

        // Intercept delivery confirm button click (PS8 may use button click, not form submit)
        $('form#js-delivery button[name="confirmDeliveryOption"], form#js-delivery button[type="submit"]')
            .off('click.OmnivaTerminal')
            .on('click.OmnivaTerminal', function(e) {
                if (!self.isTerminalCarrierSelected()) return true;
                if (!self.validateTerminalClient()) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
                return true;
            });

        // Hide validation error if buyer switches away from terminal carrier
        $(document).off('change.OmnivaTerminalSwitch')
            .on('change.OmnivaTerminalSwitch', 'input[name^="delivery_option"]', function() {
                if (!self.isTerminalCarrierSelected()) {
                    self.hideTerminalError();
                }
            });

        // Capture phase listener for delivery step (catches all click/submit regardless of binding order)
        if (!self.terminalValidationInitialized) {
            self.terminalValidationInitialized = true;

            document.addEventListener('click', function(e) {
                // Delivery step button
                var deliveryBtn = e.target.closest('form#js-delivery button[name="confirmDeliveryOption"], form#js-delivery button[type="submit"]');
                if (deliveryBtn && self.isTerminalCarrierSelected() && !self.validateTerminalClient()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }

                // Payment confirmation button
                var paymentBtn = e.target.closest('#payment-confirmation button, #payment-confirmation [type="submit"]');
                if (paymentBtn) {
                    if (!self.isTerminalCarrierSelectedServer()) return;
                    if (self.terminalValid === true) {
                        self.hideTerminalError();
                        return;
                    }
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    self.checkTerminalAjax(function(hasTerminal) {
                        if (hasTerminal) {
                            self.hideTerminalError();
                            self.terminalValid = true;
                            $(paymentBtn).click();
                        } else {
                            self.showTerminalError();
                        }
                    });
                    return false;
                }
            }, true);

            document.addEventListener('submit', function(e) {
                // Delivery form submit
                var deliveryForm = e.target.closest('form#js-delivery');
                if (deliveryForm && self.isTerminalCarrierSelected() && !self.validateTerminalClient()) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return false;
                }

                // Payment form submit
                var paymentForm = e.target.closest('#payment-confirmation form');
                if (paymentForm) {
                    if (!self.isTerminalCarrierSelectedServer()) return;
                    if (self.terminalValid === true) {
                        self.hideTerminalError();
                        return;
                    }
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    self.checkTerminalAjax(function(hasTerminal) {
                        if (hasTerminal) {
                            self.hideTerminalError();
                            self.terminalValid = true;
                            $(paymentForm).submit();
                        } else {
                            self.showTerminalError();
                        }
                    });
                    return false;
                }
            }, true);
        }

        $('select[name="omnivalt_parcel_terminal"]').off('change.Omniva').on('change.Omniva', function(e) {
            // Mark as valid and hide error when terminal is selected
            if ($(this).val()) {
                self.terminalValid = true;
                self.hideTerminalError();
            } else {
                self.terminalValid = false;
            }

            console.groupCollapsed('OMNIVA: Saving selected terminal');
            var terminal = $(this).val();
            if(terminal)
            {
                $.ajax({
                    type: 'POST',
                    headers: { "cache-control": "no-cache" },
                    url: omnivalt_params.url.controller_ajax,
                    async: true,
                    cache: false,
                    dataType: 'json',
                    data: 'action=saveParcelTerminalDetails'
                        + '&token=' + omnivalt_params.token
                        + '&terminal=' + terminal,
                    success: function(jsonData)
                    {
                        //console.log(jsonData);
                        console.log('Terminal saved successfully');

                        // Run third-party checkout post-save hooks
                        for (var i = 0; i < omnivaCheckoutCompat.length; i++) {
                            var compat = omnivaCheckoutCompat[i];
                            if (compat.onTerminalSaved && compat.detect()) {
                                compat.onTerminalSaved();
                            }
                        }
                    },
                    error: function(XMLHttpRequest, textStatus, errorThrown) {
                        console.log('Failed terminal save');
                        if (textStatus !== 'abort') {
                            alert(omnivalt_text.select_terminal_error);
                        }
                    }
                });
            }
            console.groupEnd();
        });
        console.groupEnd();
    },
    isTerminalCarrierSelected : function() {
        // Check if terminal carrier radio is currently checked in the DOM
        var carrierInputs = $('input[name^="delivery_option"]:checked, .delivery-options .delivery-option input[type="radio"]:checked');
        var terminalId = omnivalt_params.methods.omniva_terminal;
        var selected = false;
        carrierInputs.each(function() {
            var val = $(this).val();
            if (val == terminalId + ',' || val == terminalId) {
                selected = true;
                return false;
            }
        });
        return selected;
    },
    isTerminalCarrierSelectedServer : function() {
        // For payment step - we can't reliably check DOM, use last known AJAX result
        // or return true to trigger AJAX check
        return this.terminalValid !== true;
    },
    validateTerminalClient : function() {
        // Client-side validation: check if terminal select exists and has a value
        var terminalSelect = $('select[name="omnivalt_parcel_terminal"]');
        if (terminalSelect.length && terminalSelect.val() === '') {
            this.terminalValid = false;
            this.showTerminalError();
            return false;
        }
        // If select is not in DOM (shouldn't happen in delivery step), allow and let server check
        if (!terminalSelect.length) {
            return true;
        }
        this.terminalValid = true;
        this.hideTerminalError();
        return true;
    },
    checkTerminalAjax : function(callback) {
        var self = this;
        $.ajax({
            type: 'POST',
            url: omnivalt_params.url.controller_ajax,
            dataType: 'json',
            data: 'action=checkTerminal&token=' + omnivalt_params.token,
            success: function(response) {
                if (!response.terminal_required) {
                    self.terminalValid = true;
                    if (callback) callback(true);
                } else {
                    self.terminalValid = response.has_terminal;
                    if (callback) callback(response.has_terminal);
                }
            },
            error: function() {
                self.terminalValid = null;
                if (callback) callback(false);
            }
        });
    },
    showTerminalError : function() {
        if (!this.terminalErrorElement) {
            this.terminalErrorElement = $('<div class="omniva-terminal-validation-error alert alert-danger mt-3 mb-3" role="alert">' +
                omnivalt_text.select_terminal_error + '</div>');
        }

        // If already in DOM and visible, just ensure it's shown
        var existing = $('.omniva-terminal-validation-error');
        if (existing.length && existing.closest(':visible').length) {
            existing.show();
            return;
        }

        // Remove from DOM to re-insert in correct location
        this.terminalErrorElement.detach();

        // Try to insert near the visible terminal selection area (delivery step)
        var terminalContainer = $('#omnivalt_parcel_terminal_carrier_details, .omniva-tmjs-container').filter(':visible').first();
        if (terminalContainer.length) {
            terminalContainer.after(this.terminalErrorElement);
            this.terminalErrorElement.show();
            return;
        }

        // Try the selected delivery option (visible delivery step)
        var selectedDeliveryOption = $('.delivery-options .delivery-option input[type="radio"]:checked')
            .closest('.delivery-option').filter(':visible');
        if (selectedDeliveryOption.length) {
            selectedDeliveryOption.after(this.terminalErrorElement);
            this.terminalErrorElement.show();
            return;
        }

        // Fallback to universal notification (payment step, OPC, etc.)
        omnivaltNotification.show(this.terminalErrorElement, '.omniva-terminal-validation-error', omnivalt_text.select_terminal_error);
    },
    hideTerminalError : function() {
        omnivaltNotification.hide(this.terminalErrorElement, '.omniva-terminal-validation-error');
    }
}

/**
 * Third-party checkout compatibility registry.
 * Each entry defines how to detect and integrate with a specific checkout module.
 *
 * To add support for a new checkout module, add a new entry with:
 *   - name: identifier for logging
 *   - detect: function returning true if the checkout module is active
 *   - boot: function to set up the initialization trigger (must call launch_omniva())
 *   - onTerminalSaved: (optional) function called after terminal is saved via AJAX
 */
var omnivaCheckoutCompat = [
    {
        name: 'onepagecheckoutps',
        description: 'onepagecheckoutps v5 - 4.2.3 - presteamshop',
        detect: function() {
            return typeof OPC !== typeof undefined;
        },
        boot: function() {
            prestashop.on('opc-shipping-getCarrierList-complete', function() {
                launch_omniva();
            });
        },
        onTerminalSaved: function() {
            if ($('#btn-placer_order').is(':disabled')) {
                prestashop.emit('opc-payment-getPaymentList');
            }
        }
    },
    {
        name: 'supercheckout',
        description: 'supercheckout - 9.0.2 - Knowband',
        detect: function() {
            return $('#module-supercheckout-supercheckout').length > 0;
        },
        boot: function() {
            $(document).ajaxComplete(function(event, xhr, settings) {
                if (settings.url.includes('supercheckout') && settings.data && settings.data.includes('method=updateCheckoutBehaviour') && settings.data.includes('field_name=shipping_method')) {
                    launch_omniva();
                }
            });
        }
    }
];

// Detect and boot compatible third-party checkout or use default
(function() {
    var booted = false;
    for (var i = 0; i < omnivaCheckoutCompat.length; i++) {
        var compat = omnivaCheckoutCompat[i];
        if (compat.detect()) {
            console.log('OMNIVA:', 'Detected third-party checkout: ' + compat.name);
            compat.boot();
            booted = true;
        }
    }
    if (!booted) {
        $(document).ready(function() {
            launch_omniva();
            omnivaltCodRestriction.init();
        });
    }
    // Phone check works on all checkout types
    $(document).ready(function() {
        omnivaltPhoneCheck.init();
    });
})();

function launch_omniva(retry = 0) {
    console.log('OMNIVA:', 'Loading parcel machine selector... Retry ' + retry);

    if (retry >= 50) {
        console.log('OMNIVA:', 'Failed to load parcel machine selector after 50 retries.');
        $('.omniva-terminal-loading').hide();
        $('.omniva-terminal-error').show();
        return;
    }

    var $container = $('#omnivalt_parcel_terminal_carrier_details [data-omniva-tmjs]');
    if (!$container.length) {
        // Carrier extra-content block has not been rendered yet – retry shortly.
        setTimeout(function() { launch_omniva(retry + 1); }, 200);
        return;
    }

    if (typeof window.OmnivaTerminalMap === 'undefined' || typeof TerminalMapping === 'undefined') {
        // Library scripts may still be loading.
        setTimeout(function() { launch_omniva(retry + 1); }, 200);
        return;
    }

    $('.omniva-terminal-loading').hide();
    window.OmnivaTerminalMap.autoBoot();
    omnivaltDelivery.init();
    $('.delivery-options .delivery-option input[type="radio"]')
        .off('click.OmnivaInit')
        .on('click.OmnivaInit', function() {
            omnivaltDelivery.init();
        });
}
