{*
 * EIND Supplier Search - Search Bar
 *}

<div id="eind-suppliersearch-wrapper" class="eind-suppliersearch-wrapper">
  <div class="eind-suppliersearch-inner">

    <form
      id="eind-suppliersearch-form"
      class="eind-suppliersearch-form"
      method="get"
      action="{$link->getModuleLink('eindsuppliersearch', 'searchresults')|escape:'htmlall':'UTF-8'}"
      role="search"
    >
      <input type="hidden" name="api_submit" value="search">
      <input type="hidden" name="supplierId" value="0">

      <div class="eind-suppliersearch-input-group">

        <input
          type="text"
          id="eind-suppliersearch-input"
          class="eind-suppliersearch-input form-control"
          name="api_search_query"
          value="{$searchData.query|default:''|escape:'htmlall':'UTF-8'}"
          placeholder="{l s='Search products...' d='Modules.Eindsuppliersearch.Shop'}"
          autocomplete="off"
          aria-label="{l s='Search products' d='Modules.Eindsuppliersearch.Shop'}"
        >

        <button
          type="submit"
          class="btn btn-primary eind-suppliersearch-button"
          aria-label="{l s='Search' d='Modules.Eindsuppliersearch.Shop'}"
        >
          <span>{l s='Search' d='Modules.Eindsuppliersearch.Shop'}</span>
        </button>

      </div>
    </form>

  </div>
</div>
