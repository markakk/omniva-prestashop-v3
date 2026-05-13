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
<div id="omnivalt_parcel_terminal_carrier_details" class="select-omnivalt theme-{$select_block_theme}" style="margin-top: 10px;">
    <div class="omniva-terminal-loading alert alert-info">
        {l s='Terminal selection is loading. Please wait...' d='Modules.Omnivaltshipping.Shop'}
    </div>
    <select class="" name="omnivalt_parcel_terminal" style = "width:100%;">{$parcel_terminals nofilter}</select>

    <style>
        {literal}
            #omnivalt_parcel_terminal_carrier_details{ margin-bottom: 5px }
        {/literal}
    </style>
{if $omniva_map != false } 
  <button type="button" id="show-omniva-map" class="btn btn-basic btn-sm omniva-btn" style = "display: none;">{l s='Show parcel terminals map' d='Modules.Omnivaltshipping.Shop'} <img src = "{$module_url}views/img/{$marker_img}" title = "{l s='Show parcel terminals map' d='Modules.Omnivaltshipping.Shop'}"/></button>
{/if}
    <div class="omniva-terminal-error alert alert-danger" style="display: none;">
        {l s='Terminal selection is currently unavailable. You can still place an order with this shipping method — please specify your preferred terminal in the order comments or contact the store administrator.' d='Modules.Omnivaltshipping.Shop'}
    </div>
</div>
{if $omniva_map != false }
{include file="module:omnivaltshipping/views/templates/hook/modalMap.tpl"}
{/if}