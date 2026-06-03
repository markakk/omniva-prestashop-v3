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
    var omnivalt_current_country = '{$omniva_current_country}';
    var omnivalt_postcode = '{$omniva_postcode}';
    var omnivalt_autoselect = {$omniva_autoselect};
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

    <div class="omniva-terminal-error alert alert-danger" style="display: none;">
        {l s='Parcel machine selection is currently unavailable. You can still place an order with this shipping method — please specify your preferred parcel machine in the order comments or contact the store administrator.' d='Modules.Omnivaltshipping.Shop'}
    </div>
</div>