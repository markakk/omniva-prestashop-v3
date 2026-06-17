{*
* 2007-2014 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
*         DISCLAIMER   *
* *************************************** */
/* Do not edit or add to this file if you wish to upgrade Prestashop to newer
* versions in the future.
* ****************************************************
*}
<script>
    var omnivalt_terminals = {$terminals_list|@json_encode nofilter};
</script>
<div id="omnivalt_parcel_terminal_carrier_details" class="select-omnivalt theme-{$select_block_theme} mode-{if $omniva_map}map{else}dropdown{/if}" style="margin-top:3px;">
    <div class="omniva-terminal-loading alert alert-info">
        {l s='Parcel machine selection is loading. Please wait...' d='Modules.Omnivaltshipping.Shop'}
    </div>

    {* Hidden <select> – kept for the existing AJAX save / validation
       handlers in omniva.js. The TerminalMapping library updates it
       through a synthetic change event when the buyer picks a machine. *}
    <select name="omnivalt_parcel_terminal" style="display:none;">{$parcel_terminals nofilter}</select>

    {* TerminalMapping JS library mounts its UI inside this container.
       data-omniva-tmjs-mode controls whether a map modal or an inline
       dropdown is rendered. *}
    <div
        id="omniva_pm_map_{$carrier_id|intval}"
        class="omniva-tmjs-container"
        data-omniva-tmjs
        data-omniva-tmjs-mode="{if $omniva_map}map{else}dropdown{/if}"
        data-omniva-tmjs-country="{$omniva_current_country}"
        data-omniva-tmjs-postcode="{$omniva_postcode}"
        data-omniva-tmjs-city="{$omniva_city|escape:'html':'UTF-8'}"
        data-omniva-tmjs-address="{$omniva_address|escape:'html':'UTF-8'}"
    ></div>

    {* "Install the app" promo block. Rendered here (inside the carrier-extra
       markup) rather than injected by JS, so it sits in the template for both
       map and dropdown modes. omniva-front-map.js only toggles its visibility
       with the selection state and opens the QR modal. The box-search / qr-code
       icons are drawn via CSS mask (currentColor), with the SVG URLs passed in
       as CSS variables. *}
    <div
        class="omniva-app-promo omnivalt-theme-{$select_block_theme}"
        data-omniva-app-for="omniva_pm_map_{$carrier_id|intval}"
        data-omniva-app-url="{$app_external_url|escape:'html':'UTF-8'}"
        style="--omniva-app-box-icon:url('{$module_url}views/img/icons/box-search.svg');--omniva-app-qr-icon:url('{$module_url}views/img/icons/qr-code.svg');--omniva-app-external-icon:url('{$module_url}views/img/icons/open-external.svg');"
    >
        <span class="omniva-app-promo-icon" aria-hidden="true"></span>
        <span class="omniva-app-promo-text">{l s='Track your parcel and get the door code in the Omniva app.' d='Modules.Omnivaltshipping.Shop'}</span>
        {if $app_qr_image}
            <button type="button" class="omniva-app-promo-qr" aria-label="{l s='Show app QR code' d='Modules.Omnivaltshipping.Shop'}"></button>
        {/if}
    </div>

    {if $app_qr_image}
        {* Small QR popup opened from the promo block. omniva-front-popup.js
           re-homes it to <body> on init and wires open/close. The popup is
           self-contained (styled in omniva-front.css); the theme class only
           selects the Matkahuolto color override. *}
        <div
            class="omniva-qr-modal omnivalt-theme-{$select_block_theme}"
            data-omniva-qr-for="omniva_pm_map_{$carrier_id|intval}"
            role="dialog"
            aria-modal="true"
        >
            <div class="omniva-qr-modal-overlay"></div>
            <div class="omniva-qr-modal-content">
                <button type="button" class="omniva-qr-modal-close" aria-label="{l s='Done' d='Modules.Omnivaltshipping.Shop'}"></button>
                <img class="omniva-qr-modal-image" src="{$app_qr_image}" alt="">
                <h3 class="omniva-qr-modal-header">{l s='Scan to install' d='Modules.Omnivaltshipping.Shop'}</h3>
                <p class="omniva-qr-modal-text">{l s='Point your phone camera at the code.<br/>Free on iOS & Android.' d='Modules.Omnivaltshipping.Shop'}</p>
                <button type="button" class="omniva-qr-modal-done">{l s='Done' d='Modules.Omnivaltshipping.Shop'}</button>
            </div>
        </div>
    {/if}

    <div class="omniva-terminal-error alert alert-danger" style="display: none;">
        {l s='Parcel machine selection is currently unavailable. You can still place an order with this shipping method — please specify your preferred parcel machine in the order comments or contact the store administrator.' d='Modules.Omnivaltshipping.Shop'}
    </div>
</div>