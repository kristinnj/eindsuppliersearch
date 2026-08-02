{extends file="layouts/layout-full-width.tpl"}

{block name="content"}
{if !$eind_product}
    <div id="js-product-list">
        {capture assign="errorContent"}
            <p class="h3">{l s='Product not found' d='Modules.Eindsuppliersearch.Shop'}</p>
            <p>{l s='Your search session may have expired. Please run the search again.' d='Modules.Eindsuppliersearch.Shop'}</p>
        {/capture}
        {include file='errors/not-found.tpl' errorContent=$errorContent}
    </div>
{else}
    {$product=$eind_product}
    {$hazardous=false}
    <div class="eind-suppliersearch-detail">
        <div class="mb-3">
            <a href="{$eind_back_url}" class="btn btn-outline-primary btn-sm" onclick="history.back(); return false;">
                &larr; {l s='Back to search results' d='Modules.Eindsuppliersearch.Shop'}
            </a>
        </div>

        <div class="row">
            <div class="col-12 col-md-5 mb-4">
                {if isset($product.Images[0])}
                    <div class="text-center">
                        <img
                            src="{$product.Images[0].PrimaryURL}"
                            alt="{$product.ManufacturerPartNumber|escape:'html'}"
                            title="{$product.ManufacturerPartNumber|escape:'html'} - {$product.DisplayText|escape:'html'}"
                            class="img-fluid"
                        >
                    </div>
                    <p class="text-center text-muted small mt-2">{l s='Images are for reference only. See product description for details.' d='Modules.Eindsuppliersearch.Shop'}</p>
                {else}
                    <div class="text-center">
                        <img src="{_MODULE_DIR_}eind_suppliersearch/views/images/No_image.jpg" alt="{l s='No image available' d='Shop.Theme.Catalog'}" class="img-fluid">
                    </div>
                {/if}
            </div>

            <div class="col-12 col-md-7">
                <h1 class="h4">{$product.BrandName|escape:'html'} - {$product.ManufacturerPartNumber|escape:'html'}</h1>
                <p class="text-muted">{$product.DisplayText|escape:'html'}</p>

                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <th scope="row">{l s='Manufacturer' d='Modules.Eindsuppliersearch.Shop'}</th>
                            <td>{$product.ManufacturerName|escape:'html'}</td>
                        </tr>
                        <tr>
                            <th scope="row">{l s='Manufacturer Part No' d='Modules.Eindsuppliersearch.Shop'}</th>
                            <td>{$product.ManufacturerPartNumber|escape:'html'}</td>
                        </tr>
                        <tr>
                            <th scope="row">{l s='Order Code' d='Modules.Eindsuppliersearch.Shop'}</th>
                            <td>{$product.OrderCode|escape:'html'}</td>
                        </tr>
                        {if isset($product.Datasheets) && $product.Datasheets|@count}
                            <tr>
                                <th scope="row">{l s='Datasheet' d='Modules.Eindsuppliersearch.Shop'}</th>
                                <td>
                                    {foreach from=$product.Datasheets item=datasheet}
                                        <a href="{$datasheet.SheetUrl}" target="_blank" rel="noopener">{$datasheet.SheetName|escape:'html'}</a><br>
                                    {/foreach}
                                </td>
                            </tr>
                        {/if}
                        {if isset($product.CountryOfOrigin)}
                            <tr>
                                <th scope="row">{l s='Country of Origin' d='Modules.Eindsuppliersearch.Shop'}</th>
                                <td>{$product.CountryOfOrigin|escape:'html'}</td>
                            </tr>
                        {/if}
                    </tbody>
                </table>

                {if isset($product.Attributes)}
                    {foreach from=$product.Attributes item=attribute}
                        {if $attribute.Label === "hazardous" and $attribute.Value === "true"}
                            {$hazardous=true}
                            <div class="alert alert-danger py-2">{l s='Hazardous product. Special handling may be required.' d='Modules.Eindsuppliersearch.Shop'}</div>
                        {/if}
                    {/foreach}
                {/if}
            </div>
        </div>

        <div class="row mt-2">
            <div class="col-12 col-lg-7 mb-4">
                <h2 class="h5">{l s='Product Description' d='Modules.Eindsuppliersearch.Shop'}</h2>
                {if isset($product.ProductInformation.Description)}
                    {foreach from=$product.ProductInformation.Description item=description}
                        <p>{$description|escape:'html'}</p>
                    {/foreach}
                {/if}
                {if isset($product.ProductInformation.Bullets)}
                    <ul>
                        {foreach from=$product.ProductInformation.Bullets item=bullet}
                            <li>{$bullet|escape:'html'}</li>
                        {/foreach}
                    </ul>
                {/if}
                {if isset($product.ProductInformation.Applications)}
                    <h3 class="h6 mt-3">{l s='Applications' d='Modules.Eindsuppliersearch.Shop'}</h3>
                    <ul>
                        {foreach from=$product.ProductInformation.Applications item=application}
                            <li>{$application|escape:'html'}</li>
                        {/foreach}
                    </ul>
                {/if}

                {if isset($product.Attributes) && $product.Attributes|@count}
                    <h2 class="h5 mt-4">{l s='Product Information' d='Modules.Eindsuppliersearch.Shop'}</h2>
                    <div class="row">
                        {foreach from=$product.Attributes item=attribute}
                            <div class="col-12 col-md-6 d-flex justify-content-between border-bottom py-1">
                                <span class="fw-semibold">{$attribute.Label|escape:'html'}</span>
                                <span>{$attribute.Value|escape:'html'}{if isset($attribute.Unit)} {$attribute.Unit|escape:'html'}{/if}</span>
                            </div>
                        {/foreach}
                    </div>
                {/if}

                {if isset($product.ProductInformation.PackageContent)}
                    <div class="alert alert-light mt-3">
                        <h3 class="h6">{l s='Package Content' d='Modules.Eindsuppliersearch.Shop'}</h3>
                        {foreach from=$product.ProductInformation.PackageContent item=content}
                            <p class="mb-1">{$content|escape:'html'}</p>
                        {/foreach}
                    </div>
                {/if}
                {if isset($product.ProductInformation.Warnings)}
                    <div class="alert alert-warning mt-3">
                        <h3 class="h6">{l s='Warning' d='Modules.Eindsuppliersearch.Shop'}</h3>
                        {foreach from=$product.ProductInformation.Warnings item=warning}
                            <p class="mb-1">{$warning|escape:'html'}</p>
                        {/foreach}
                    </div>
                {/if}
            </div>

            <div class="col-12 col-lg-5">
                <div class="card">
                    <div class="card-body">
                        {if isset($product.Stock)}
                            <p class="mb-2">
                                {if $product.Stock.Status == 1}
                                    <span class="badge bg-success">{l s='In stock' d='Shop.Theme.Catalog'}</span>
                                    {$product.Stock.Level|number_format:0:',':'.'} {l s='units' d='Modules.Eindsuppliersearch.Shop'}
                                {elseif $product.Stock.Status == 0}
                                    <span class="badge bg-danger">{l s='Out of stock' d='Shop.Theme.Catalog'}</span>
                                {elseif $product.Stock.Status == -1 || $product.Stock.Status == -2}
                                    <span class="badge bg-info text-dark">{l s='Awaiting delivery' d='Modules.Eindsuppliersearch.Shop'}</span>
                                {else}
                                    <span class="badge bg-secondary">{l s='Unknown availability' d='Modules.Eindsuppliersearch.Shop'}</span>
                                {/if}
                            </p>
                        {/if}

                        {if isset($product.Prices[0])}
                            <p class="h4 mb-0">{$product.Prices[0].FormattedLocalVATPrice}</p>
                            <p class="text-muted">({$product.Prices[0].FormattedLocalPrice} {l s='excl. tax' d='Modules.Eindsuppliersearch.Shop'})</p>
                            {if isset($product.PacketSize) && isset($product.Unit)}
                                <p class="small text-muted">{l s='Price for %size% %unit%' sprintf=['%size%' => $product.PacketSize, '%unit%' => $product.Unit] d='Modules.Eindsuppliersearch.Shop'}</p>
                            {/if}
                        {/if}

                        <form class="js-eind-add-to-cart-form d-flex align-items-center gap-2 mt-3"
                            data-supplier-id="{$eind_supplier_id}"
                            data-product-reference="{$eind_product_key|escape:'html'}"
                            data-order-code="{$product.OrderCode|escape:'html'}"
                        >
                            <input
                                type="number"
                                name="quantity"
                                value="{$product.MinimumOrderQty|escape:'html'}"
                                min="{$product.MinimumOrderQty|escape:'html'}"
                                step="{$product.OrderMultiples|escape:'html'}"
                                class="form-control"
                                style="max-width: 6rem;"
                                required
                            >
                            <button
                                type="submit"
                                class="btn btn-primary flex-grow-1 js-eind-add-to-cart-submit"
                                {if (isset($product.Stock) && $product.Stock.Status == 0) || $hazardous}disabled{/if}
                            >
                                {l s='Add to cart' d='Shop.Theme.Actions'}
                            </button>
                        </form>

                        <p class="small text-muted mt-2 mb-0">
                            {l s='Minimum order' d='Modules.Eindsuppliersearch.Shop'}: {$product.MinimumOrderQty|escape:'html'}
                            &middot;
                            {l s='Multiples of' d='Modules.Eindsuppliersearch.Shop'}: {$product.OrderMultiples|escape:'html'}
                        </p>

                        {if isset($product.Prices) && $product.Prices|@count > 1}
                            <table class="table table-sm mt-3">
                                <thead>
                                    <tr>
                                        <th>{l s='Quantity' d='Modules.Eindsuppliersearch.Shop'}</th>
                                        <th class="text-end">{l s='Price' d='Shop.Theme.Catalog'}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {foreach from=$product.Prices item=price}
                                        <tr>
                                            <td>{$price.From|escape:'html'}+</td>
                                            <td class="text-end">{$price.FormattedLocalVATPrice}</td>
                                        </tr>
                                    {/foreach}
                                </tbody>
                            </table>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
    </div>
{/if}
{/block}
