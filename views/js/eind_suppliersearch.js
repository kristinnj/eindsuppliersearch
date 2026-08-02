(function () {
    'use strict';

    var EindSupplierSearch = (function () {
        function init() {
            initSearchFormProtection();
            initPaginationDropdowns();
            initAddToCartForms();
        }

        // The theme's own search-as-you-type JS listens for submits on any
        // search-looking form; force a hard GET navigation instead so our
        // controller (not the theme's catalog search) handles the query.
        function initSearchFormProtection() {
            var form = document.getElementById('eind-suppliersearch-form');

            if (!form) {
                return;
            }

            var input = form.querySelector('input[name="api_search_query"]');

            function handleSubmit(event) {
                event.preventDefault();
                event.stopImmediatePropagation();

                var url = new URL(form.action, window.location.origin);
                var formData = new FormData(form);

                formData.forEach(function (value, key) {
                    url.searchParams.append(key, value);
                });

                window.location.href = url.toString();
            }

            form.addEventListener('submit', handleSubmit, true);

            if (input) {
                input.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        handleSubmit(event);
                    }
                }, true);
            }
        }

        function initPaginationDropdowns() {
            document.querySelectorAll('.js-eind-items-on-page').forEach(function (select) {
                select.addEventListener('change', function () {
                    var form = select.form;
                    if (!form) {
                        return;
                    }
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                });
            });
        }

        function initAddToCartForms() {
            document.addEventListener('submit', function (event) {
                var form = event.target.closest('.js-eind-add-to-cart-form');
                if (!form) {
                    return;
                }

                event.preventDefault();
                addToCart(form);
            });
        }

        function updateCartIndicators(quantity) {
            if (typeof quantity === 'string') {
                quantity = parseInt(quantity, 10);
            }

            if (!Number.isFinite(quantity)) {
                quantity = null;
            }

            try {
                if (typeof prestashop !== 'undefined' && typeof prestashop.emit === 'function') {
                    prestashop.emit('updateCart', {
                        reason: {
                            linkAction: 'add-to-cart'
                        },
                        cart: {
                            nbProducts: quantity
                        }
                    });
                }
            } catch (error) {
                console.error('Failed to emit PrestaShop cart update:', error);
            }

            if (quantity === null) {
                return;
            }

            document.querySelectorAll('.cart-products-count, [data-cart-count]').forEach(function (target) {
                if (target.dataset && typeof target.dataset.cartCount !== 'undefined') {
                    target.dataset.cartCount = String(quantity);
                }
                target.textContent = String(quantity);
            });
        }

        function refreshMiniCart(reason) {
            document.querySelectorAll('.blockcart').forEach(function (cartElement) {
                var refreshUrl = cartElement.dataset ? cartElement.dataset.refreshUrl : null;
                if (!refreshUrl) {
                    return;
                }

                var payload = new URLSearchParams();
                payload.append('action', 'refresh');
                if (reason && reason.idProduct) {
                    payload.append('id_product', reason.idProduct);
                }
                if (reason && reason.linkAction) {
                    payload.append('link_action', reason.linkAction);
                }

                fetch(refreshUrl, {
                    method: 'POST',
                    body: payload,
                    credentials: 'same-origin'
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Failed to refresh cart');
                        }
                        return response.json();
                    })
                    .then(function (resp) {
                        if (resp && resp.preview) {
                            var wrapper = document.createElement('div');
                            wrapper.innerHTML = resp.preview;
                            var updatedCart = wrapper.querySelector('.blockcart');

                            if (updatedCart && cartElement.parentNode) {
                                cartElement.parentNode.replaceChild(updatedCart, cartElement);
                            } else {
                                cartElement.innerHTML = resp.preview;
                            }
                        }

                        if (typeof prestashop !== 'undefined' && typeof prestashop.emit === 'function') {
                            prestashop.emit('updatedCart', { reason: reason || {}, resp: resp || {} });
                        }
                    })
                    .catch(function (error) {
                        console.warn('Cart refresh failed:', error);
                    });
            });
        }

        function addToCart(form) {
            var url = typeof eind_suppliersearch_addToCartUrl !== 'undefined' ? eind_suppliersearch_addToCartUrl : null;
            var token = typeof eind_suppliersearch_addToCartToken !== 'undefined' ? eind_suppliersearch_addToCartToken : null;

            if (!url) {
                console.error('Missing eind_suppliersearch_addToCartUrl');
                return;
            }

            var submitButton = form.querySelector('.js-eind-add-to-cart-submit');
            var quantityField = form.querySelector('input[name="quantity"]');

            var formData = new FormData();
            formData.append('supplierId', form.dataset.supplierId || '0');
            formData.append('product_reference', form.dataset.productReference || '');
            formData.append('order_code', form.dataset.orderCode || '');
            formData.append('quantity', quantityField ? quantityField.value : '1');
            if (token) {
                formData.append('token', token);
            }

            if (submitButton) {
                submitButton.disabled = true;
            }

            fetch(url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    var contentType = response.headers.get('content-type') || '';

                    if (contentType.indexOf('application/json') !== -1) {
                        return response.json();
                    }

                    return response.text().then(function (text) {
                        try {
                            return JSON.parse(text);
                        } catch (error) {
                            throw new Error('Invalid add to cart response: ' + text);
                        }
                    });
                })
                .then(function (data) {
                    if (data && data.success) {
                        if (typeof data.cart_quantity !== 'undefined') {
                            updateCartIndicators(data.cart_quantity);
                        }

                        refreshMiniCart({
                            linkAction: 'add-to-cart',
                            idProduct: data.product_id || null
                        });
                    } else {
                        console.error('Add to cart failed:', data && data.message ? data.message : data);
                        window.alert((data && data.message) || 'Failed to add product to cart.');
                    }
                })
                .catch(function (error) {
                    console.error('Error adding to cart:', error);
                })
                .finally(function () {
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                });
        }

        return {
            init: init,
            addToCart: addToCart
        };
    })();

    window.EindSupplierSearch = EindSupplierSearch;

    document.addEventListener('DOMContentLoaded', function () {
        EindSupplierSearch.init();
    });
})();
