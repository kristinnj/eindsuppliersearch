{**
 * Extends the theme's real product-miniature partial so supplier search
 * results look identical to native catalog listings.
 *
 * For products that already exist locally (eind_virtual = false) every
 * block falls back to the parent theme markup unchanged -- real product,
 * real image, real "Add to cart" form, real product page link.
 *
 * For products that don't exist locally yet (eind_virtual = true) the
 * image and actions areas are replaced: the theme's own image lookup
 * queries the `image` table by id_product and would find nothing for a
 * synthetic placeholder id, and the native add-to-cart form posts straight
 * to PrestaShop's cart controller which only works for real catalog
 * products. Everything else (price block, flags) still comes from core.
 *}
{extends file='catalog/_partials/miniatures/product.tpl'}

{block name='product_miniature_top'}
  {if $product.eind_virtual}
    <div class="{$componentName}__top">
      <div class="{$componentName}__image-container thumbnail-container">
        <a href="{$product.eind_detail_url}" class="{$componentName}__image-link outline outline--rounded">
          {if $product.eind_image_url}
            <img
              class="{$componentName}__image"
              src="{$product.eind_image_url}"
              loading="lazy"
              alt="{$product.name|escape:'html'}"
              title="{$product.name|escape:'html'}"
            >
          {else}
            <img
              class="{$componentName}__image"
              src="{$urls.no_picture_image.bySize.default_md.url}"
              loading="lazy"
              alt="{l s='No image available' d='Shop.Theme.Catalog'}"
              title="{l s='No image available' d='Shop.Theme.Catalog'}"
            >
          {/if}
        </a>
      </div>
    </div>
  {else}
    {$smarty.block.parent}
  {/if}
{/block}

{block name='product_miniature_bottom'}
  {if $product.eind_virtual}
    <div class="{$componentName}__bottom">
      <div class="{$componentName}__infos">
        <a
          class="{$componentName}__title"
          href="{$product.eind_detail_url}"
          aria-label="{l s='View product %product_name%' sprintf=['%product_name%' => $product.name] d='Shop.Theme.Catalog'}"
        >{$product.name}</a>

        {if $product.show_price}
          <div class="{$componentName}__prices">
            <div class="{$componentName}__price" aria-label="{l s='Price' d='Shop.Theme.Catalog'}">
              {$product.price}
            </div>
          </div>
        {/if}
      </div>

      <div class="{$componentName}__actions">
        <form
          class="{$componentName}__form js-eind-add-to-cart-form"
          data-supplier-id="{$product.eind_supplier_id}"
          data-product-reference="{$product.eind_product_key}"
          data-order-code="{$product.eind_order_code|escape:'html'}"
        >
          <div class="quantity-button js-quantity-button">
            {include file='components/qty-input.tpl'
              attributes=[
                "id" => "quantity_wanted_eind_{$product.eind_supplier_id}_{$product.eind_product_key}",
                "name" => "quantity",
                "value" => "{$product.eind_minimum_order_qty}",
                "min" => "{$product.eind_minimum_order_qty}",
                "step" => "{$product.eind_order_multiples}"
              ]
            }
          </div>

          <button
            type="submit"
            data-button-action="add-to-cart"
            class="{$componentName}__add btn btn-primary btn-square-icon js-eind-add-to-cart-submit"
            aria-label="{l s='Add to cart %product_name%' sprintf=['%product_name%' => $product.name] d='Shop.Theme.Actions'}"
            title="{l s='Add to cart %product_name%' sprintf=['%product_name%' => $product.name] d='Shop.Theme.Actions'}"
          >
            <i class="material-icons" aria-hidden="true">&#xe854;</i>
            <span class="{$componentName}__add-text">{l s='Add to cart' d='Shop.Theme.Actions'}</span>
          </button>
        </form>
      </div>
    </div>
  {else}
    {$smarty.block.parent}
  {/if}
{/block}
