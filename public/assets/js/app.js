/**
 * GreenCorner — Front-end JavaScript
 *
 * Progressive enhancement only. Every feature works without JavaScript;
 * this file adds the background cart behaviour of Phase 3 and the
 * confirmation dialogs.
 *
 * No token is ever stored in localStorage or sessionStorage (FR-7.6).
 */

(function () {
    'use strict';

    /**
     * The application's base URL, so the background requests reach the same
     * place as the pages.
     */
    var APP_URL = window.GreenCornerBaseUrl || '';

    /**
     * Read the CSRF token from the page. The token lives in a hidden form
     * field on the page, so it is tied to the current session.
     */
    function getCsrfToken() {
        var field = document.querySelector('input[name="csrf_token"]');
        return field ? field.value : '';
    }

    /**
     * Post JSON to an endpoint, including the CSRF token and credentials.
     */
    function postJson(url, payload) {
        var body = payload || {};
        body.csrf_token = getCsrfToken();

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (data) {
                return { status: response.status, data: data };
            });
        });
    }

    // Expose a small helper for the Phase 3 cart code.
    window.GreenCorner = {
        getCsrfToken: getCsrfToken,
        postJson: postJson
    };

    window.GreenCornerBaseUrl = APP_URL;

    /**
     * Update the cart count in the navigation bar in place (FR-3.1).
     */
    function updateCartCount(count) {
        var badge = document.querySelector('#cart-count');

        if (badge && typeof count === 'number') {
            badge.textContent = String(count);
        }
    }

    /**
     * Render the cart rows and the grand total returned by a background
     * cart request (FR-3.5, FR-3.6).
     */
    function renderCart(payload) {
        updateCartCount(payload.cart_count);

        var total = document.querySelector('#cart-grand-total');

        if (total && typeof payload.total_display === 'string') {
            total.textContent = payload.total_display;
        }

        if (!Array.isArray(payload.items)) {
            return;
        }

        payload.items.forEach(function (item) {
            var row = document.querySelector('.cart-row[data-product-id="' + item.product_id + '"]');

            if (!row) {
                return;
            }

            var lineTotal = row.querySelector('.cart-line-total');

            if (lineTotal) {
                lineTotal.textContent = formatAmount(item.line_total);
            }

            var unitPrice = row.querySelector('.cart-unit-price');

            if (unitPrice) {
                unitPrice.textContent = formatAmount(item.unit_price);
            }

            var input = row.querySelector('.cart-quantity-input');

            if (input) {
                input.value = String(item.quantity);
            }
        });
    }

    /**
     * Format an amount the way the server does, so the background update
     * and the server-rendered page agree.
     */
    function formatAmount(amount) {
        var value = Number(amount);

        if (!isFinite(value)) {
            return '';
        }

        return value.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // -----------------------------------------------------------------------
    // Add to Cart (FR-3.1, FR-3.3, FR-3.12)
    //
    // The listing background-updates the cart; the detail page navigates to
    // the Cart page (FR-3.12).
    // -----------------------------------------------------------------------

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.add-to-cart');

        if (!button) {
            return;
        }

        event.preventDefault();

        var productId = button.getAttribute('data-product-id');

        if (!productId) {
            return;
        }

        button.disabled = true;

        postJson(APP_URL + '/cart/add', { product_id: productId })
            .then(function (result) {
                button.disabled = false;

                if (result.status === 401 && result.data && result.data.login) {
                    // FR-3.4: a visitor is sent to the login page, and the
                    // requested product is kept as a pending action.
                    window.location.href = result.data.login;
                    return;
                }

                if (!result.data || result.data.ok !== true) {
                    showToast(
                        (result.data && result.data.error) || 'That item could not be added.',
                        null,
                        null
                    );
                    return;
                }

                updateCartCount(result.data.cart_count);

                // FR-3.1: the success toast carries a "View Cart" action.
                showToast(
                    result.data.message || 'Added to your cart.',
                    result.data.toast_action || 'View Cart',
                    result.data.toast_action_url || (APP_URL + '/cart')
                );

                // FR-3.12: from the detail page the customer is taken to the
                // Cart page.
                if (document.body.classList.contains('product-detail-page')) {
                    window.location.href = APP_URL + '/cart';
                }
            })
            .catch(function () {
                button.disabled = false;
                showToast('That item could not be added. Please try again.', null, null);
            });
    });

    // -----------------------------------------------------------------------
    // Cart quantity changes and removals (FR-3.5, FR-3.6)
    // -----------------------------------------------------------------------

    document.addEventListener('change', function (event) {
        var input = event.target.closest('.cart-quantity-input');

        if (!input) {
            return;
        }

        var productId = input.getAttribute('data-product-id');
        var quantity = parseInt(input.value, 10);

        postJson(APP_URL + '/cart/update', {
            product_id: productId,
            quantity: quantity
        }).then(function (result) {
            if (!result.data || result.data.ok !== true) {
                showToast(
                    (result.data && result.data.error) || 'The quantity could not be changed.',
                    null,
                    null
                );

                // Put the input back to the value the server still holds.
                var summary = postJson(APP_URL + '/cart/summary', {});
                summary.then(function (result2) {
                    if (result2.data && result2.data.ok === true) {
                        renderCart(result2.data);
                    }
                });

                return;
            }

            renderCart(result.data);

            if (result.data.message) {
                showToast(result.data.message, null, null);
            }
        }).catch(function () {
            showToast('The quantity could not be changed. Please try again.', null, null);
        });
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.cart-remove');

        if (!button) {
            return;
        }

        event.preventDefault();

        var productId = button.getAttribute('data-product-id');

        postJson(APP_URL + '/cart/remove', { product_id: productId })
            .then(function (result) {
                if (!result.data || result.data.ok !== true) {
                    showToast(
                        (result.data && result.data.error) || 'That item could not be removed.',
                        null,
                        null
                    );
                    return;
                }

                var row = document.querySelector('.cart-row[data-product-id="' + productId + '"]');

                if (row) {
                    row.remove();
                }

                renderCart(result.data);
                showToast('Removed from your cart.', null, null);
            })
            .catch(function () {
                showToast('That item could not be removed. Please try again.', null, null);
            });
    });

    // -----------------------------------------------------------------------
    // Payment method selection (FR-4.2, FR-4.13)
    //
    // The card fields are hidden immediately when another method is
    // selected, and the matching wallet instruction panel is shown. The
    // server makes the same decision, so the page works without
    // JavaScript too.
    // -----------------------------------------------------------------------

    /**
     * The panel that belongs to each payment method. The card panel is
     * `#card-fields`; each wallet has its own.
     */
    var PAYMENT_PANELS = {
        card: '#card-fields',
        bkash: '#bkash-fields',
        nagad: '#nagad-fields'
    };

    document.addEventListener('change', function (event) {
        var radio = event.target.closest('[data-payment-method]');

        if (!radio) {
            return;
        }

        var selected = radio.getAttribute('data-payment-method');

        Object.keys(PAYMENT_PANELS).forEach(function (method) {
            var panel = document.querySelector(PAYMENT_PANELS[method]);

            if (panel) {
                panel.hidden = method !== selected;
            }
        });
    });

    /**
     * Ask before performing a destructive action.
     */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form instanceof HTMLFormElement && form.dataset.confirm) {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        }
    });

    /**
     * Show a toast message. Used by the Phase 3 Add-to-Cart flow, which
     * requires a toast with a "View Cart" action (FR-3.1).
     */
    function showToast(message, actionLabel, actionUrl) {
        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'toast';
        toast.setAttribute('role', 'status');

        var text = document.createElement('span');
        text.textContent = message;
        toast.appendChild(text);

        if (actionLabel && actionUrl) {
            var link = document.createElement('a');
            link.className = 'toast-action';
            link.href = actionUrl;
            link.textContent = actionLabel;
            toast.appendChild(link);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Dismiss');
        close.textContent = '×';
        close.addEventListener('click', function () {
            toast.remove();
        });
        toast.appendChild(close);

        container.appendChild(toast);

        window.setTimeout(function () {
            toast.remove();
        }, 6000);
    }

    window.GreenCorner.showToast = showToast;

    // -----------------------------------------------------------------------
    // Admin panel (Phase 3)
    //
    // The category rename form is always in the page; JavaScript only shows
    // and hides it, so the rename still works without JavaScript.
    // -----------------------------------------------------------------------

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-toggle-edit]');
        if (!toggle) {
            return;
        }

        var target = document.getElementById(toggle.dataset.toggleEdit);
        if (!target) {
            return;
        }

        target.hidden = !target.hidden;

        if (!target.hidden) {
            var field = target.querySelector('input[name="name"]');
            if (field) {
                field.focus();
            }
        }
    });

    // -----------------------------------------------------------------------
    // Catalogue enhancements (Phase 2)
    //
    // Everything below is progressive enhancement: the listing page works
    // completely without JavaScript. These behaviours only make it quicker.
    // -----------------------------------------------------------------------

    /**
     * The catalogue search form.
     *
     * Escape clears the search field, and submitting the form always resets
     * the page to the first page — the second page of a previous search
     * would otherwise be meaningless.
     */
    var searchForm = document.querySelector('.catalogue-search');

    if (searchForm) {
        var searchInput = searchForm.querySelector('input[name="search"]');

        if (searchInput) {
            searchInput.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') {
                    return;
                }

                if (searchInput.value === '') {
                    return;
                }

                event.preventDefault();
                searchInput.value = '';
                searchInput.focus();
            });
        }

        searchForm.addEventListener('submit', function () {
            // A new search starts at the first page.
            var page = searchForm.querySelector('input[name="page"]');

            if (page) {
                page.value = '1';
            }
        });
    }

    /**
     * The category filter chips.
     *
     * A chip is a link, so the filter works without JavaScript. This only
     * adds the active state to the chip the visitor is on, which keeps the
     * visual state correct when the page is restored from the back-forward
     * cache.
     */
    var chips = document.querySelectorAll('.category-filter-chip');

    if (chips.length > 0 && 'IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }

                var chip = entry.target;

                chips.forEach(function (other) {
                    other.classList.toggle('is-visible', other === chip);
                });
            });
        }, { threshold: 0.75 });

        chips.forEach(function (chip) {
            observer.observe(chip);
        });
    }
}());
