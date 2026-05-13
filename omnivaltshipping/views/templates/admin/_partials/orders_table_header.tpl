<tr class="nodrag nodrop">
    {if isset($select_all) && $select_all}
        <th width='5%'>
            <span class="title_box"><input type="checkbox" class="select-all"/></span>
        </th>
    {/if}
    <th width='5%'>
        <span class="title_box active">{l s='ID' d='Modules.Omnivaltshipping.Admin'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Customer' d='Modules.Omnivaltshipping.Admin'}</span>
    </th>
    <th width='10%'>
        <span class="title_box">{l s='Order date' d='Modules.Omnivaltshipping.Admin'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Tracking' d='Modules.Omnivaltshipping.Admin'}</span>
    </th>
    <th width='10%'>
        <span class="title_box">{l s='Labels registered' d='Modules.Omnivaltshipping.Admin'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Total' d='Modules.Omnivaltshipping.Admin'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Labels' d='Modules.Omnivaltshipping.Admin'}</span>
    </th>
</tr>