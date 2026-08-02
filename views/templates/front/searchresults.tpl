{extends file="layouts/layout-full-width.tpl"}

{block name="content"}
<div class="eind-suppliersearch-results">
    {if isset($search_results.Error)}
        <div id="js-product-list">
            {capture assign="errorContent"}
                <p class="h3">{l s='No results found for "%query%"' sprintf=['%query%' => $search_results.Query] d='Shop.Theme.Catalog'}</p>
                <p>{l s='Try adjusting your search criteria.' d='Shop.Theme.Catalog'}</p>
            {/capture}
            {include file='errors/not-found.tpl' errorContent=$errorContent}
        </div>
    {else}
        <ul class="nav nav-tabs mb-3" role="tablist">
            {foreach from=$search_results item=supplierData key=supplierId name=supplierTabs}
                <li class="nav-item" role="presentation">
                    <a class="nav-link{if $smarty.foreach.supplierTabs.first} active{/if}" id="eind-tab-{$supplierId}" data-bs-toggle="tab" href="#eind-tabpane-{$supplierId}" role="tab" aria-controls="eind-tabpane-{$supplierId}" aria-selected="{if $smarty.foreach.supplierTabs.first}true{else}false{/if}">
                        {$supplierData.SupplierName|escape:'html'}
                    </a>
                </li>
            {/foreach}
        </ul>

        <div class="tab-content">
            {foreach from=$search_results item=supplierData key=supplierId name=supplierPanes}
                {if isset($supplierData.Products) && $supplierData.Products|@count}
                    <div class="tab-pane fade{if $smarty.foreach.supplierPanes.first} show active{/if}" id="eind-tabpane-{$supplierId}" role="tabpanel" aria-labelledby="eind-tab-{$supplierId}">

                        {if $supplierData.NumberOfResults > 0}
                            <div class="row align-items-center bg-light mx-0 mb-3 py-2">
                                <div class="col-auto">
                                    <span>{l s='%count% results' sprintf=['%count%' => $supplierData.NumberOfResults|number_format:0:',':'.'] d='Modules.Eindsuppliersearch.Shop'}</span>
                                </div>
                                <div class="col d-flex justify-content-end align-items-center flex-wrap gap-2">
                                    <form method="get" class="d-flex align-items-center gap-1" id="eind-items-on-page-form-{$supplierId}">
                                        <input name="supplierId" value="{$supplierId}" type="hidden">
                                        <input name="submit_search" value="iop" type="hidden">
                                        <label class="mb-0" for="eind-items-on-page-{$supplierId}">{l s='Per page' d='Modules.Eindsuppliersearch.Shop'}</label>
                                        <select id="eind-items-on-page-{$supplierId}" name="itemsonpage" class="form-select form-select-sm w-auto js-eind-items-on-page">
                                            {foreach from=[10,25,100] item=option}
                                                <option value="{$option}"{if (int)$supplierData.ItemsOnPage == $option} selected{/if}>{$option}</option>
                                            {/foreach}
                                        </select>
                                    </form>
                                    <form method="get" action="{$link->getModuleLink('eind_suppliersearch', 'searchresults')}">
                                        <input name="supplierId" value="{$supplierId}" type="hidden">
                                        <input name="submit_search" value="previous" type="hidden">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"{if !$supplierData.HasPreviousPage} disabled{/if}>&laquo; {l s='Previous' d='Modules.Eindsuppliersearch.Shop'}</button>
                                    </form>
                                    <span>{l s='Page %current% of %total%' sprintf=['%current%' => $supplierData.CurrentPage, '%total%' => $supplierData.NumberOfPages|number_format:0:',':'.'] d='Modules.Eindsuppliersearch.Shop'}</span>
                                    <form method="get" action="{$link->getModuleLink('eind_suppliersearch', 'searchresults')}">
                                        <input name="supplierId" value="{$supplierId}" type="hidden">
                                        <input name="submit_search" value="next" type="hidden">
                                        <input name="totalResults" value="{$supplierData.NumberOfResults}" type="hidden">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary"{if !$supplierData.HasNextPage} disabled{/if}>{l s='Next' d='Modules.Eindsuppliersearch.Shop'} &raquo;</button>
                                    </form>
                                </div>
                            </div>

                            {if $supplierData.BackTrack}
                                <form method="post" class="mb-3">
                                    <input name="submit_search" value="back" type="hidden">
                                    <input name="supplierId" value="{$supplierId}" type="hidden">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">&larr; {l s='Back to search' d='Modules.Eindsuppliersearch.Shop'}</button>
                                </form>
                            {/if}

                            <div id="js-product-list">
                                <div class="products">
                                    {foreach from=$supplierData.PresentedProducts item=product key=productKey}
                                        {include file='module:eind_suppliersearch/views/templates/front/_partials/virtual-product-miniature.tpl' product=$product position=$productKey}
                                    {/foreach}
                                </div>
                            </div>
                        {else}
                            <div id="js-product-list">
                                {capture assign="errorContent"}
                                    <p class="h3">{l s='No results found for "%query%"' sprintf=['%query%' => $supplierData.Query] d='Shop.Theme.Catalog'}</p>
                                    <p>{l s='Try adjusting your search criteria.' d='Shop.Theme.Catalog'}</p>
                                {/capture}
                                {include file='errors/not-found.tpl' errorContent=$errorContent}
                            </div>
                        {/if}
                    </div>
                {/if}
            {/foreach}
        </div>
    {/if}
</div>
{/block}
