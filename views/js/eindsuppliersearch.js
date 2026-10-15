(function () {
    'use strict';

    var EindSupplierSearch = (function () {
        function init() {
            initSearchFormProtection();
            initPaginationDropdowns();
            initAddToCartForms();
            initImageZoom();
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

        function addToCart(form) {
            var url = form.getAttribute('action') ? form.action : null;
            var token = typeof eindsuppliersearch_addToCartToken !== 'undefined' ? eindsuppliersearch_addToCartToken : null;

            if (!url) {
                console.error('Missing supplier ordering form action');
                window.alert(eindsuppliersearch_orderingUnavailableMessage);
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
                            throw new Error('Invalid supplier ordering response.');
                        }
                    });
                })
                .then(function (data) {
                    // Supplier ordering is deliberately contained. Never manufacture
                    // a native cart event or a success confirmation for this response.
                    if (!data || data.success !== false || typeof data.message !== 'string') {
                        throw new Error('Unexpected supplier ordering response.');
                    }
                    window.alert(data.message);
                })
                .catch(function (error) {
                    console.error('Error requesting supplier ordering.');
                    window.alert(eindsuppliersearch_orderingUnavailableMessage);
                })
                .finally(function () {
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                });
        }

        // Hover-to-zoom overlay for product images (ported from apisearchmodule's
        // product modal zoom). Unlike the old module, there's no host modal here --
        // each wrapper gets its own overlay appended to <body>, shown/hidden by
        // toggling a class so CSS can animate the zoom in/out. The overlay is
        // built once per wrapper (not per hover) so that toggling is instant and
        // the mouseleave listener on the popped-up dialog itself is what makes
        // moving the cursor off it actually close it again.
        function initImageZoom() {
            var wrappers = document.querySelectorAll('.js-eind-image-zoom');

            wrappers.forEach(function (wrapper) {
                var imageSrc = wrapper.getAttribute('data-image-src') || '';
                if (!imageSrc) {
                    return;
                }
                var imageAlt = wrapper.getAttribute('data-image-alt') || 'Product image';

                var overlay = document.createElement('div');
                overlay.className = 'eind-image-zoom-modal';

                var dialog = document.createElement('div');
                dialog.className = 'eind-image-zoom-modal__dialog';

                var img = document.createElement('img');
                img.className = 'eind-image-zoom-img';
                img.src = imageSrc;
                img.alt = imageAlt;

                dialog.appendChild(img);
                overlay.appendChild(dialog);
                document.body.appendChild(overlay);

                function isWithinZoom(target) {
                    return !!target && (wrapper.contains(target) || overlay.contains(target));
                }

                function showOverlay() {
                    // dialog.offsetWidth/Height are measurable even while the
                    // overlay is visibility:hidden, since that (unlike
                    // display:none) keeps the box in the layout tree.
                    var margin = 10;
                    var rect = wrapper.getBoundingClientRect();
                    var viewportWidth = document.documentElement.clientWidth;
                    var viewportHeight = document.documentElement.clientHeight;
                    var dialogWidth = dialog.offsetWidth;
                    var dialogHeight = dialog.offsetHeight;

                    // left/transform:translateX(-50%) together mean "left" is the
                    // dialog's horizontal CENTER, not its edge -- clamp so the
                    // actual left/right edges stay within the viewport.
                    var minLeft = margin + dialogWidth / 2;
                    var maxLeft = Math.max(minLeft, viewportWidth - margin - dialogWidth / 2);
                    var desiredLeft = rect.left + rect.width / 2;
                    dialog.style.left = Math.min(Math.max(desiredLeft, minLeft), maxLeft) + 'px';

                    var maxTop = Math.max(margin, viewportHeight - margin - dialogHeight);
                    dialog.style.top = Math.min(Math.max(rect.top, margin), maxTop) + 'px';

                    overlay.classList.add('is-visible');
                }

                function hideOverlay(event) {
                    if (isWithinZoom(event && event.relatedTarget)) {
                        return;
                    }
                    overlay.classList.remove('is-visible');
                }

                wrapper.addEventListener('mouseenter', showOverlay);
                wrapper.addEventListener('mouseleave', hideOverlay);
                dialog.addEventListener('mouseleave', hideOverlay);
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
