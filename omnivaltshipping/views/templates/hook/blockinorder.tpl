<script type="text/javascript">
    var omnivaBlockParams = {
        id_order: "{$order_id}",
        printLabelsUrl: "{$generateLabelsUrl}",
        downloadLabelsUrl: "{$downloadLabelsUrl}",
        moduleUrl: "{$moduleurl}",
        successText: "{$success_add_trans|escape:'javascript'}",
        methods: {$omnivalt_methods nofilter},
        text: {$omnivalt_text nofilter}
    };
</script>
<style>
    #labels-history {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
    }
    #labels-history a {
        margin-bottom: 10px;
    }
</style>
<div class="product-row row omniva-block">
    <div class="col-md-12 d-print-block left-column">
        <div class="card">
            <div class="card-header">
                <h3 class="card-header-title">
                    <i class="material-icons">local_shipping</i>
                    {l s="Omniva Shipping" d='Modules.Omnivaltshipping.Admin'}
                </h3>
            </div>
            <div class="card-body">
                {if $error}
                    {$error}
                {/if}
                <form action="{$moduleurl}" method="POST" id="omnivaltOrderSubmitForm">
                    <div class="form-row">
                        <div class="form-group col-md-6 col-xs-12">
                            <label for="omniva-packs">{l s="Packets" d='Modules.Omnivaltshipping.Admin'}:</label>
                            <input id="omniva-packs" type="text" name="packs" value="{$packs}" class="form-control" />
                        </div>
                        <div class="form-group col-md-6 col-xs-12">
                            <label for="omniva-weight">{l s="Weight" d='Modules.Omnivaltshipping.Admin'}:</label>
                            <input id="omniva-weight" type="text" name="weight" value="{$total_weight}" class="form-control" />
                        </div>
                    </div>
                    <div class="form-row omniva-cod-block">
                        <div class="form-group col-md-6 col-xs-12">
                            <label for="omniva-cod">{l s="C.O.D." d='Modules.Omnivaltshipping.Admin'}:</label>
                            <select name="is_cod" id="omniva-cod" class="form-control">
                                <option value="0">{l s='No' d='Modules.Omnivaltshipping.Admin'}</option>
                                <option value="1" {if $is_cod} selected {/if}>{l s='Yes' d='Modules.Omnivaltshipping.Admin'}</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6 col-xs-12">
                            <label for="omniva-cod-amount">{l s="C.O.D. amount" d='Modules.Omnivaltshipping.Admin'}:</label>
                            <input id="omniva-cod-amount" type="text" name="cod_amount" value="{$total_paid_tax_incl}" class="form-control" />
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-12">
                            <label for="omniva-carrier">{l s='Carrier' d='Modules.Omnivaltshipping.Admin'}:</label>
                            <select id="omniva-carrier" name="carrier" class="form-control">
                                {$carriers}
                            </select>
                        </div>
                    </div>
                    <div class="form-row omniva-terminal-block">
                        <div class="form-group col-md-12">
                            <label for="omniva-parcel-terminal">{l s='Parcel machine' d='Modules.Omnivaltshipping.Admin'}:</label>
                            <select id="omniva-parcel-terminal" name="parcel_terminal" class="form-control"
                                data-toggle="select2" data-minimumresultsforsearch="3" aria-hidden="true">
                                {$parcel_terminals}
                            </select>
                        </div>
                    </div>
                    {if !empty($active_additional_services)}
                        <div class="form-row omniva-additionalservices-block">
                            <div class="form-group col-md-12">
                                <label class="text-muted">{l s="Active shipment additional services" d='Modules.Omnivaltshipping.Admin'}:</label> <i>{$active_additional_services}</i>
                            </div>
                        </div>
                    {/if}
                    <div class="form-row">
                        <div class="form-group col-md-12 d-flex justify-content-end">
                            <button type="button" name="omnivalt_save" id="omnivaltOrderSubmitBtn" class="btn btn-default"><i class="material-icons">save</i> {l s='Save' d='Modules.Omnivaltshipping.Admin'}</button>
                        </div>
                    </div>
                </form>
                {if !empty($orderHistory) && ($orderHistory[0]->tracking_numbers)}
                    <div class="card-header">
                        <h3 class="card-header-title">
                            {l s="Omniva Labels History" d='Modules.Omnivaltshipping.Admin'}
                        </h3>
                    </div>
                    <div id="labels-history" class="card-body">
                        <div class="col-md-3 col-xs-12">
                            <h4>{l s="Tracking numbers" d='Modules.Omnivaltshipping.Admin'}</h4>
                        </div>
                        <div class="col-md-3 col-xs-12">
                            <h4>{l s="Service Code" d='Modules.Omnivaltshipping.Admin'}</h4>
                        </div>
                        <div class="col-md-3 col-xs-12">
                            <h4>{l s="Date" d='Modules.Omnivaltshipping.Admin'}</h4>
                        </div>
                        <div class="col-md-3 col-xs-12">
                            <h4>{l s="Label printing" d='Modules.Omnivaltshipping.Admin'}</h4>
                        </div>
                        {foreach $orderHistory as $historyPage}
                            <div class="col-md-3 col-xs-12">
                                {implode(', ', json_decode($historyPage->tracking_numbers))}
                            </div>
                            <div class="col-md-3 col-xs-12">
                                {$historyPage->service_code}
                            </div>
                            <div class="col-md-3 col-xs-12">
                                {$historyPage->date_add}
                            </div>
                            <div class="col-md-3 col-xs-12">
                                <a href="{$printLabelsUrl}&history={$historyPage->id}" target="_blank" class="omnivalt_print_btn btn btn-default"  d='Modules.Omnivaltshipping.Admin'><i class="material-icons">print</i> {l s="Print labels" d='Modules.Omnivaltshipping.Admin'}</a>
                            </div>
                        {/foreach}
                    </div>
                {/if}
                <div class="omniva-response alert d-none" role="alert"></div>
            </div>
            <div class="card-footer omniva-footer d-flex justify-content-between">
                <button type="button" name="omnivalt_printlabel" id="omnivaltOrderPrintLabels" class="btn btn-default"><i class="material-icons">tag</i> {l s="Generate label" d='Modules.Omnivaltshipping.Admin'}</button>
            </div>
        </div>
    </div>
</div>
<script src="{$omniva_js_path}"></script>
