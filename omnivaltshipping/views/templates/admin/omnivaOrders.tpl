<style>
    .tab-content {
        border: 1px solid #ddd;
        padding: 10px;
    }
</style>

<div class="panel col-lg-12">
    <div class="panel-heading">
        <h4>{l s='Next manifest' d='Modules.Omnivaltshipping.Admin'}: #{$manifestNum}</h4>
    </div>
    <button type="button" class="btn btn-default" data-toggle="modal" data-target="#myModal"
        title="{l s='Courier call' d='Modules.Omnivaltshipping.Admin'}" style="position:absolute; right:10px">
        <i class="fa fa fa-send-o"></i>{l s='Courier call' d='Modules.Omnivaltshipping.Admin'}
    </button>


    <ul class="nav nav-tabs">
        <li class="active"><a href="#tab-general" data-toggle="tab">{l s='New orders' d='Modules.Omnivaltshipping.Admin'}</a></li>
        <li><a href="#tab-data" data-toggle="tab">{l s='Awaiting' d='Modules.Omnivaltshipping.Admin'}</a></li>
        <li><a href="#tab-sent-orders" data-toggle="tab">{l s='Completed' d='Modules.Omnivaltshipping.Admin'}</a></li>
        <li><a href="#tab-search" data-toggle="tab">{l s='Search' d='Modules.Omnivaltshipping.Admin'}</a></li>
    </ul>
    <div class="tab-content">
        <!-- New Orders -->
        <div class="tab-pane active" id="tab-general">
            {if $newOrders != null}
                <h4 style="display: inline:block;vertical-align: baseline;">{l s='New orders' d='Modules.Omnivaltshipping.Admin'}</h4>
                <a id="print-manifest" href="" class="btn btn-default btn-xs action-call float-right pull-right"
                    target='_blank' title="{l s='Generate a manifest and move to Completed tab all orders that have a label' d='Modules.Omnivaltshipping.Admin'}">{l s='Generate manifest (all)' d='Modules.Omnivaltshipping.Admin'}</a>
                <table class="table order">
                    <thead>
                        {include file="./_partials/orders_table_header.tpl" select_all=true}
                    </thead>
                    <tbody>
                        {assign var=result value=''}
                        {foreach $newOrders as $order}
                            <tr>
                                <td><input type="checkbox" class="selected-orders" value="{$order.id_order}" /></td>
                                <td><a href="{$orderLink|replace:'/0/':"/{$order.id_order}/"}">{$order.id_order}</a></td>
                                <td>{$order.firstname|truncate:1:''|upper}. {$order.lastname}</td>
                                <td>{$order.date_add|date_format:'%Y-%m-%d'}</td>
                                <td>
                                    {if $order.tracking_numbers}
                                        {implode(', ', json_decode($order.tracking_numbers))}
                                    {/if}
                                </td>
                                <td>
                                    {if $order.tracking_numbers && !empty($order.histories)}
                                        {$order.histories[0].date|date_format:'%Y-%m-%d %H:%M'}
                                    {/if}
                                </td>
                                <td>{$order.total_paid|round:2}</td>
                                <td>
                                    {if $order.tracking_numbers == null}
                                        <a href="{$generateLabelsLink}{$order.id_order}"
                                            class="btn btn-info btn-xs">{l s='Generate Labels' d='Modules.Omnivaltshipping.Admin'}</a>
                                        <a href="{$orderSkip}{$order.id_order}"
                                            class="btn btn-danger btn-xs">{l s='Skip' d='Modules.Omnivaltshipping.Admin'}</a>
                                    {else}
                                        <a href="{$labelsLink}&id_order={$order.id_order}" class="btn btn-success btn-xs"
                                            target="_blank">{l s='Labels' d='Modules.Omnivaltshipping.Admin'}</a>
                                    {/if}
                                </td>
                                {$result = "{$result},{$order.id_order}"}
                                {$manifest = $order.manifest}
                            </tr>
                            {if !empty($order.histories) && count($order.histories) > 1}
                                {foreach $order.histories as $idx => $hist}
                                    {if $idx == 0}{continue}{/if}
                                    <tr class="omniva-history-row" style="background-color: #f9f9f9;">
                                        <td><input type="checkbox" class="selected-orders selected-history" value="history:{$hist.id}" /></td>
                                        <td></td>
                                        <td></td>
                                        <td></td>
                                        <td>
                                            {if $hist.tracking_numbers}
                                                {implode(', ', json_decode($hist.tracking_numbers))}
                                            {/if}
                                        </td>
                                        <td>{$hist.date|date_format:'%Y-%m-%d %H:%M'}</td>
                                        <td></td>
                                        <td>
                                            <a href="{$labelsLink}&id_order={$order.id_order}&history={$hist.id}" class="btn btn-default btn-xs"
                                                target="_blank">{l s='Labels' d='Modules.Omnivaltshipping.Admin'}</a>
                                        </td>
                                    </tr>
                                {/foreach}
                            {/if}
                        {/foreach}
                    </tbody>
                </table>
                <a id="print-labels" href="" class="btn btn-default btn-xs action-call"
                    target='_blank'>{l s='Labels' d='Modules.Omnivaltshipping.Admin'}</a>
                <a id="print-manifest-selected" href="" class="btn btn-default btn-xs action-call"
                    target='_blank'>{l s='Generate manifest' d='Modules.Omnivaltshipping.Admin'}</a>
                <a id="print-manifest" href="" class="btn btn-default btn-xs action-call float-right pull-right"
                    target='_blank' title="{l s='Generate a manifest and move to Completed tab all orders that have a label' d='Modules.Omnivaltshipping.Admin'}">{l s='Generate manifest (all)' d='Modules.Omnivaltshipping.Admin'}</a>
                <hr />
                <br />
            {else}
                <p class="text-center">{l s='There are no orders' d='Modules.Omnivaltshipping.Admin'}</p>
            {/if}
            <div class="text-center">
                {$finished_pagination_content}
            </div>
        </div>

        <!--/New Orders -- Skipped Orders -->
        <div class="tab-pane" id="tab-data">
            {if $skippedOrders != null}
                <h4 style="display: inline:block;vertical-align: baseline;">{l s='Skipped orders' d='Modules.Omnivaltshipping.Admin'}
                </h4>
                <table class="table order">
                    <thead>
                        {include file="./_partials/orders_table_header.tpl"}
                    </thead>
                    <tbody>
                        {foreach $skippedOrders as $order}
                            <tr>
                                <td><a href="{$orderLink|replace:'/0/':"/{$order.id_order}/"}">{$order.id_order}</a></td>
                                <td>{$order.firstname|truncate:1:''|upper}. {$order.lastname}</td>
                                <td>{$order.date_add|date_format:'%Y-%m-%d'}</td>
                                <td>{$order.tracking_number}</td>
                                <td></td>
                                <td>{$order.total_paid|round:2}</td>
                                <td>
                                    <a href="{$cancelSkip}{$order.id_order}"
                                        class="btn btn-danger btn-xs">{l s='Return to New orders' d='Modules.Omnivaltshipping.Admin'}</a>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
                <br />
                <hr />
                <br />
            {else}
                <p class="text-center">
                    {l s='There are no orders' d='Modules.Omnivaltshipping.Admin'}
                </p>
            {/if}
        </div>
        <!--/ Skipped Orders -->
        <!-- Completed Orders -->
        <div class="tab-pane" id="tab-sent-orders">
            {if $orders != null}

                <h4>{l s='Generated' d='Modules.Omnivaltshipping.Admin'}</h4>
                {assign var=newPage value=null}
                {assign var=result value=''}
                {foreach $orders as $order}
                    {if (isset($manifestOrd) && $order.manifest != $manifestOrd) || $newPage == null}
                        {assign var=newPage value=true}
                        </table>
                        <div class="omniva-manifest-header">
                            <strong>{l s='Manifest' d='Modules.Omnivaltshipping.Admin'} #{$order.manifest}</strong>
                            <a href="{$downloadManifestByNumLink}&manifest_num={$order.manifest}" class="btn btn-default btn-xs omniva-btn-get-manifest" target="_blank">
                                <i class="icon-download"></i> {l s='Get manifest' d='Modules.Omnivaltshipping.Admin'}
                            </a>
                        </div>
                        <table class="table order">
                            <thead>
                                {include file="./_partials/orders_table_header.tpl" select_all=true}
                            </thead>
                            <tbody>
                    {/if}
                            <tr>
                                <td><input type="checkbox" class="selected-orders selected-history" value="history:{$order.history_id}" /></td>
                                <td><a href="{$orderLink|replace:'/0/':"/{$order.id_order}/"}">{$order.id_order}</a></td>
                                <td>{$order.firstname|truncate:1:''|upper}. {$order.lastname}</td>
                                <td>{$order.date_add|date_format:'%Y-%m-%d'}</td>
                                <td>
                                    {if $order.tracking_numbers}
                                        {implode(', ', json_decode($order.tracking_numbers))}
                                    {/if}
                                </td>
                                <td>
                                    {if $order.history_date}
                                        {$order.history_date|date_format:'%Y-%m-%d %H:%M'}
                                    {/if}
                                </td>
                                <td>{$order.total_paid_tax_incl|round:2}</td>
                                <td>
                                    <a href="{$labelsLink}&id_order={$order.id_order}&history={$order.history_id}"
                                        class="btn btn-success btn-xs" target="_blank">{l s='Labels' d='Modules.Omnivaltshipping.Admin'}</a>
                                </td>
                                {$result = "{$result},{$order.id_order}"}
                                {$manifestOrd = $order.manifest}
                            </tr>
                {/foreach}
            {/if}
            {if $orders != null}
                    </tbody>
                </table>
                <br>
                <a id="print-labels" href="" class="btn btn-default btn-xs action-call"
                    target='_blank'>{l s='Labels' d='Modules.Omnivaltshipping.Admin'}</a>
                <a id="download-manifest" href="" class="btn btn-default btn-xs action-call"
                    target='_blank'>{l s='Generate manifest' d='Modules.Omnivaltshipping.Admin'}</a><br>
                <div class="text-center">
                    {$generated_pagination_content}
                </div>
            {/if}
        </div>
        <!--/ Completed Orders -->
        <!--/ Completed Orders -- Tab search -->
        <div class="tab-pane" id="tab-search">
            <table class="table">
                <thead>
                    {include file="./_partials/orders_table_header.tpl"}
                    <tr class="nodrag nodrop filter row_hover">
                        <th class="text-center">
                            <input type="text" class="filter" name="order_id" value="">
                        </th>
                        <th class="text-center">
                            <input type="text" class="filter" name="customer" value="">
                        </th>
                        <th class="text-center">
                            <input class="datetimepicker" name="input-order-date" type="text">
                        </th>
                        <th>
                            <input type="text" class="filter" name="tracking_nr" value="">
                        </th>
                        <th class="text-center">
                            <input class="datetimepicker" name="input-date-added" type="text">
                            <script type="text/javascript">
                                $(document).ready(function() {
                                    $(".datetimepicker").datepicker({
                                        prevText: '',
                                        nextText: '',
                                        dateFormat: 'yy-mm-dd'
                                    });
                                });
                            </script>
                        </th>
                        <th class="text-center"></th>
                        <th class="actions"><a id="button-search" class="btn btn-default btn-xs">
                                {l s='Search' d='Modules.Omnivaltshipping.Admin'}
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody id="searchTable">
                    <tr>
                        <td colspan='7'>{l s='Search' d='Modules.Omnivaltshipping.Admin'}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>


    <!-- Modal Courier call-->
    <div id="myModal" class="modal fade" role="dialog">
        <div class="modal-dialog">
            <!-- Modal content-->
            <div class="modal-content">
                <form class="form-horizontal">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title">{l s='Final shipment - courier call.' d='Modules.Omnivaltshipping.Admin'}
                        </h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>{l s='Important!' d='Modules.Omnivaltshipping.Admin'}</strong>
                            {l s='Latest time on which courier call can be made is 3 p.m. If you call courier later, we do not guarantee that shipment will be picked up.' d='Modules.Omnivaltshipping.Admin'}
                            <br />
                            <strong>{l s='Address and contact data' d='Modules.Omnivaltshipping.Admin'}</strong>
                            {l s='can be changed in Omniva module settings.' d='Modules.Omnivaltshipping.Admin'}
                        </div>
                        <h4>{l s='Data to be sent' d='Modules.Omnivaltshipping.Admin'}</h4>
                        <b>{l s='Sender:' d='Modules.Omnivaltshipping.Admin'}</b> {$sender}<br>
                        <b>{l s='Phone:' d='Modules.Omnivaltshipping.Admin'}</b> {$phone}<br>
                        <b>{l s='Zipcode:' d='Modules.Omnivaltshipping.Admin'}</b> {$postcode}<br>
                        <b>{l s='Address:' d='Modules.Omnivaltshipping.Admin'}</b> {$address}<br>
                        <b>{l s='Requested pickup time:' d='Modules.Omnivaltshipping.Admin'}</b> {$pickup_start} - {$pickup_end}<br><br>
                        <div id="alertList"></div>
                        <div class="omnivalt-courier-calls" {if empty($courier_calls)}style="display:none"{/if}>
                            <b>{l s='Scheduled courier arrivals:' d='Modules.Omnivaltshipping.Admin'}</b><br>
                            <table id="omnivalt-courier-calls-list" class="table" style="width:auto;">
                                {if !empty($courier_calls)}
                                    {foreach $courier_calls as $courier_call}
                                        <tr>
                                            <td><small>{$courier_call['start_date']}</small></td>
                                            <td>{$courier_call['start_time']}</td>
                                            <td>
                                            {if $courier_call['end_date'] != $courier_call['start_date']}
                                                <small>{$courier_call['end_date']}</small>
                                            {/if}
                                            </td>
                                            <td>{$courier_call['end_time']}</td>
                                            <td><button type="button" class="btn btn-danger btn-xs" data-callid="{$courier_call['id']}" title="{l s='Cancel this courier call' d='Modules.Omnivaltshipping.Admin'}">&times;</button></td>
                                        </tr>
                                    {/foreach}
                                {/if}
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" id="requestOmnivaltCourier"
                            class="btn btn-default">{l s='Send' d='Modules.Omnivaltshipping.Admin'}</button>
                        <button type="button" class="btn btn-default"
                            data-dismiss="modal">{l s='Cancel' d='Modules.Omnivaltshipping.Admin'}</button>
                        <button type="button" id="modalOmnivaltClose" class="btn btn-default"
                            data-dismiss="modal" style="display:none">{l s='Close' d='Modules.Omnivaltshipping.Admin'}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<!--/ Modal Courier call-->