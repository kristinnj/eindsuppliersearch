{**
 * Renders one presented ProductSearchHit view-model (see
 * EindSupplierSearch\Presenter\ProductSearchHitPresenter and
 * docs/development/grouped-search-ui.md): one normalized product, with
 * every matching supplier offer listed underneath it.
 *
 * Expects a single `hit` array with the shape ProductSearchHitPresenter
 * produces:
 *   manufacturer, manufacturerPartNumber, name, description,
 *   attributes[], images[], datasheets[], compliance{}, supplierCount,
 *   offers[] (supplierName, supplierOrderCode, stockLevel, stockStatus,
 *   minimumOrderQty, packaging, deliveryInfo, priceTiers[], timestamp)
 *
 * Intentionally generic/reusable: does not assume it is only used on the
 * search-results page (no page-specific query/pagination state is read
 * here), and does not rank/merge/format offers -- it only loops and
 * displays what the presenter already computed.
 *
 * No supplier-offer action (add-to-cart / view-detail) is rendered here:
 * the current view-model carries no supplier-bucket/row identifiers, so
 * there is nothing safe to link to yet without inventing new commerce
 * plumbing (see docs/development/grouped-search-ui.md, "Known
 * limitations").
 *}
<article class="eind-search-hit">
    <header class="eind-search-hit__header">
        <div class="eind-search-hit__identity">
            <span class="eind-search-hit__manufacturer">{$hit.manufacturer|escape:'html'}</span>
            <span class="eind-search-hit__mpn">{$hit.manufacturerPartNumber|escape:'html'}</span>
        </div>
        {if $hit.name}
            <h3 class="eind-search-hit__name">{$hit.name|escape:'html'}</h3>
        {/if}
        {if $hit.description}
            <p class="eind-search-hit__description">{$hit.description|escape:'html'}</p>
        {/if}
        <div class="eind-search-hit__meta">
            <span class="eind-search-hit__supplier-count">
                {l s='%count% supplier offers' sprintf=['%count%' => $hit.supplierCount] d='Modules.Eindsuppliersearch.Shop'}
            </span>
            {if $hit.compliance.rohsCompliant}
                <span class="eind-search-hit__compliance">{l s='RoHS compliant' d='Modules.Eindsuppliersearch.Shop'}</span>
            {/if}
        </div>
    </header>

    <div class="eind-search-hit__body">
        <div class="eind-search-hit__technical">
            <div class="eind-search-hit__image-wrapper js-eind-image-zoom eind-search-hit__image-wrapper--{if $hit.images.0.PrimaryURL}has-image{else}no-image{/if}"
                 {if $hit.images.0.PrimaryURL}data-image-src="{$hit.images.0.PrimaryURL|escape:'html'}" data-image-alt="{$hit.name|escape:'html'}"{/if}>
                {if $hit.images.0.PrimaryURL}
                    <img
                        class="eind-search-hit__image"
                        src="{$hit.images.0.PrimaryURL|escape:'html'}"
                        loading="lazy"
                        alt="{$hit.name|escape:'html'}"
                    >
                {else}
                    <img
                        class="eind-search-hit__image"
                        src="{_MODULE_DIR_}eindsuppliersearch/views/images/No_image.jpg"
                        loading="lazy"
                        alt="{l s='No image available' d='Shop.Theme.Catalog'}"
                    >
                {/if}
            </div>

            {if $hit.attributes}
                <ul class="eind-search-hit__attributes">
                    {foreach from=$hit.attributes item=attribute name=eindHitAttributes}
                        {if $smarty.foreach.eindHitAttributes.index < 4 && $attribute.Label}
                            <li class="eind-search-hit__attribute">
                                <span class="eind-search-hit__attribute-label">{$attribute.Label|escape:'html'}</span>
                                <span class="eind-search-hit__attribute-value">{$attribute.Value|escape:'html'}{if $attribute.Unit} {$attribute.Unit|escape:'html'}{/if}</span>
                            </li>
                        {/if}
                    {/foreach}
                </ul>
            {/if}

            {if $hit.datasheets.0.SheetUrl}
                <a class="eind-search-hit__datasheet" href="{$hit.datasheets.0.SheetUrl|escape:'html'}" target="_blank" rel="noopener noreferrer">
                    {if $hit.datasheets.0.SheetName}{$hit.datasheets.0.SheetName|escape:'html'}{else}{l s='Datasheet' d='Modules.Eindsuppliersearch.Shop'}{/if}
                </a>
            {/if}
        </div>

        <div class="eind-search-hit__offers">
            <table class="eind-search-offers-table">
                <caption class="sr-only visually-hidden">
                    {l s='Supplier offers for %product%' sprintf=['%product%' => $hit.manufacturerPartNumber] d='Modules.Eindsuppliersearch.Shop'}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">{l s='Supplier' d='Modules.Eindsuppliersearch.Shop'}</th>
                        <th scope="col">{l s='Stock' d='Modules.Eindsuppliersearch.Shop'}</th>
                        <th scope="col">{l s='MOQ' d='Modules.Eindsuppliersearch.Shop'}</th>
                        <th scope="col">{l s='Packaging' d='Modules.Eindsuppliersearch.Shop'}</th>
                        <th scope="col">{l s='Price' d='Modules.Eindsuppliersearch.Shop'}</th>
                        <th scope="col">{l s='Delivery' d='Modules.Eindsuppliersearch.Shop'}</th>
                        <th scope="col">{l s='Order code' d='Modules.Eindsuppliersearch.Shop'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$hit.offers item=offer name=eindHitOffers}
                        <tr class="eind-search-offer">
                            <td class="eind-search-offer__supplier" data-label="{l s='Supplier' d='Modules.Eindsuppliersearch.Shop'}">
                                {$offer.supplierName|escape:'html'}
                            </td>
                            <td class="eind-search-offer__stock" data-label="{l s='Stock' d='Modules.Eindsuppliersearch.Shop'}">
                                <span class="eind-search-offer__stock-status">{$offer.stockStatus|escape:'html'}</span>
                                {if $offer.stockLevel > 0}
                                    <span class="eind-search-offer__stock-level">({$offer.stockLevel|number_format:0:',':'.'})</span>
                                {/if}
                            </td>
                            <td class="eind-search-offer__moq" data-label="{l s='MOQ' d='Modules.Eindsuppliersearch.Shop'}">
                                {$offer.minimumOrderQty|number_format:0:',':'.'}
                            </td>
                            <td class="eind-search-offer__packaging" data-label="{l s='Packaging' d='Modules.Eindsuppliersearch.Shop'}">
                                {if $offer.packaging}{$offer.packaging|escape:'html'}{else}&mdash;{/if}
                            </td>
                            <td class="eind-search-offer__price" data-label="{l s='Price' d='Modules.Eindsuppliersearch.Shop'}">
                                {if $offer.priceTiers.0}
                                    <span class="eind-search-offer__price-tier">
                                        {l s='From %qty%: %price%' sprintf=[
                                            '%qty%' => $offer.priceTiers.0.From|default:1,
                                            '%price%' => $offer.priceTiers.0.LocalVATPrice|default:$offer.priceTiers.0.LocalPrice|default:$offer.priceTiers.0.ProductPrice|default:$offer.priceTiers.0.SupplierPrice|default:'-'
                                        ] d='Modules.Eindsuppliersearch.Shop'}
                                    </span>
                                    {if $offer.priceTiers|@count > 1}
                                        <span class="eind-search-offer__price-tiers-more">
                                            {foreach from=$offer.priceTiers item=tier name=eindPriceTiers}
                                                {if !$smarty.foreach.eindPriceTiers.first}{if $smarty.foreach.eindPriceTiers.index > 1} &middot; {/if}{$tier.From|default:1}+: {$tier.LocalVATPrice|default:$tier.LocalPrice|default:$tier.ProductPrice|default:$tier.SupplierPrice|default:'-'}{/if}
                                            {/foreach}
                                        </span>
                                    {/if}
                                {else}
                                    &mdash;
                                {/if}
                            </td>
                            <td class="eind-search-offer__delivery" data-label="{l s='Delivery' d='Modules.Eindsuppliersearch.Shop'}">
                                {if $offer.deliveryInfo}{$offer.deliveryInfo|escape:'html'}{else}&mdash;{/if}
                            </td>
                            <td class="eind-search-offer__order-code" data-label="{l s='Order code' d='Modules.Eindsuppliersearch.Shop'}">
                                {$offer.supplierOrderCode|escape:'html'}
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
</article>
