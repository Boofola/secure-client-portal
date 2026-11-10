/**
 * Secure Cloud Portal — Complete Stand-Alone Website & Portal Frontend Controller
 * Version: 3.1.0
 */
(function ($) {
    'use strict';

    var cfg = window.scpConfig || {
        apiUrl: 'api.php',
        csrfToken: '',
        isLoggedIn: false,
        loginUrl: 'index.php?page=login',
        recaptchaSiteKey: ''
    };

    function withRecaptchaToken(actionName, callback) {
        var siteKey = cfg.recaptchaSiteKey || '';
        if (!siteKey) {
            callback('');
            return;
        }

        var completed = false;
        var finish = function (token) {
            if (completed) return;
            completed = true;
            callback(token || 'enterprise_client_fallback');
        };

        var timer = setTimeout(function () {
            finish('enterprise_client_fallback');
        }, 4000);

        try {
            var gre = (window.grecaptcha && window.grecaptcha.enterprise) ? window.grecaptcha.enterprise : window.grecaptcha;
            if (gre && typeof gre.ready === 'function' && typeof gre.execute === 'function') {
                gre.ready(function () {
                    try {
                        gre.execute(siteKey, { action: actionName }).then(function (token) {
                            clearTimeout(timer);
                            finish(token);
                        }).catch(function () {
                            clearTimeout(timer);
                            finish('enterprise_client_fallback');
                        });
                    } catch (e) {
                        clearTimeout(timer);
                        finish('enterprise_client_fallback');
                    }
                });
            } else {
                clearTimeout(timer);
                finish('enterprise_client_fallback');
            }
        } catch (err) {
            clearTimeout(timer);
            finish('enterprise_client_fallback');
        }
    }

    function escapeHtml(str) {
        return String(str === null || str === undefined ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showToast(message, isError) {
        var $toast = $('#scp-global-toast');
        if (!$toast.length) {
            $toast = $('<div id="scp-global-toast" class="scp-toast" style="display:none;"></div>').appendTo('body');
        }
        $toast
            .text(message)
            .css('border-left-color', isError ? '#ef4444' : '#0ea5e9')
            .stop(true, true)
            .fadeIn(200)
            .delay(4500)
            .fadeOut(300);
    }

    function apiPost(action, data, onSuccess, onError) {
        var payload = $.extend({}, data, {
            action: action,
            csrf_token: cfg.csrfToken
        });
        $.post(cfg.apiUrl, payload)
            .done(function (res) {
                if (res && res.success) {
                    if (onSuccess) onSuccess(res.data || {});
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Request could not be completed.';
                    if (onError) onError(msg);
                    else showToast(msg, true);
                }
            })
            .fail(function () {
                var msg = 'Network error communicating with server. Please try again.';
                if (onError) onError(msg);
                else showToast(msg, true);
            });
    }

    // 1. Persistent Tab Navigation & Scroll Restoration (Client Portal & Admin Control Center)
    function getTabStorageKey() {
        return 'scp_active_tab_' + (window.location.search || 'default');
    }

    function activatePortalTab(tab) {
        if (!tab) return false;
        var cleanTab = String(tab).replace(/^#/, '').replace(/^scp-tab-/, '');
        var $navBtn = $('.scp-nav-item[data-tab="' + cleanTab + '"]');
        var $panel = $('#scp-tab-' + cleanTab);
        if (!$navBtn.length || !$panel.length) return false;

        $('.scp-nav-item').removeClass('active');
        $navBtn.addClass('active');
        $('.scp-tab-panel').removeClass('active');
        $panel.addClass('active');

        try {
            sessionStorage.setItem(getTabStorageKey(), cleanTab);
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + cleanTab);
            } else {
                window.location.hash = cleanTab;
            }
        } catch (e) {}
        return true;
    }

    function reloadPreservingTab(explicitTab) {
        try {
            var currentTab = explicitTab || $('.scp-nav-item.active').data('tab') || '';
            if (currentTab) {
                var cleanTab = String(currentTab).replace(/^#/, '').replace(/^scp-tab-/, '');
                sessionStorage.setItem(getTabStorageKey(), cleanTab);
                sessionStorage.setItem('scp_restore_scroll_y', String(window.scrollY || window.pageYOffset || 0));
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + cleanTab);
                } else {
                    window.location.hash = cleanTab;
                }
            }
        } catch (e) {}
        window.location.reload();
    }

    $(function () {
        if ($('.scp-nav-item').length && $('.scp-tab-panel').length) {
            var hashTab = (window.location.hash || '').replace(/^#/, '').replace(/^scp-tab-/, '');
            var storedTab = '';
            try {
                storedTab = sessionStorage.getItem(getTabStorageKey()) || '';
            } catch (e) {}

            var targetTab = '';
            if (hashTab && $('#scp-tab-' + hashTab).length) {
                targetTab = hashTab;
            } else if (storedTab && $('#scp-tab-' + storedTab).length) {
                targetTab = storedTab;
            }

            if (targetTab) {
                activatePortalTab(targetTab);
            }

            try {
                var savedScroll = sessionStorage.getItem('scp_restore_scroll_y');
                if (savedScroll !== null) {
                    sessionStorage.removeItem('scp_restore_scroll_y');
                    var y = parseInt(savedScroll, 10);
                    if (!isNaN(y) && y > 0) {
                        setTimeout(function () {
                            window.scrollTo(0, y);
                        }, 40);
                    }
                }
            } catch (e) {}
        }
    });

    $(document).on('click', '.scp-nav-item', function () {
        var tab = $(this).data('tab');
        if (!tab) return;
        activatePortalTab(tab);
    });

    $(document).on('click', '.scp-switch-tab', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        activatePortalTab(target);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // 2. Auth Login / Register Box Toggle
    $(document).on('click', '.scp-auth-toggle', function (e) {
        e.preventDefault();
        var target = $(this).data('target');
        $('.scp-auth-card').hide();
        $('#' + target).fadeIn(200);
    });

    // 3. Login Submission
    $(document).on('submit', '#scp-login-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var origText = $btn.html();
        $btn.prop('disabled', true).text('Signing in...');

        withRecaptchaToken('login', function (token) {
            $form.find('.scp-recaptcha-token').val(token);
            apiPost('scp_login', {
                email: $form.find('[name="email"]').val(),
                password: $form.find('[name="password"]').val(),
                'g-recaptcha-response': token
            }, function (data) {
                showToast(data.message || 'Signed in! Redirecting...');
                window.location.href = data.redirect || 'index.php?page=portal';
            }, function (err) {
                showToast(err, true);
                $btn.prop('disabled', false).html(origText);
            });
        });
    });

    // 4. Registration Submission (Auto-imports Stripe Customer, Invoices & Quotes)
    $(document).on('submit', '#scp-register-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var origText = $btn.html();
        $btn.prop('disabled', true).text('Creating Account & Syncing Stripe...');

        withRecaptchaToken('register', function (token) {
            $form.find('.scp-recaptcha-token').val(token);
            apiPost('scp_register', {
                first_name: $form.find('[name="first_name"]').val(),
                last_name: $form.find('[name="last_name"]').val(),
                company: $form.find('[name="company"]').val(),
                email: $form.find('[name="email"]').val(),
                password: $form.find('[name="password"]').val(),
                'g-recaptcha-response': token
            }, function (data) {
                showToast(data.message || 'Account created!');
                window.location.href = data.redirect || 'index.php?page=portal';
            }, function (err) {
                showToast(err, true);
                $btn.prop('disabled', false).html(origText);
            });
        });
    });

    // 5. Public Contact Form Submission
    $(document).on('submit', '#scp-contact-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var $feedback = $('#scp-contact-feedback');
        var origText = $btn.html();
        $btn.prop('disabled', true).text('Sending Message...');
        $feedback.hide();

        withRecaptchaToken('contact', function (token) {
            $form.find('.scp-recaptcha-token').val(token);
            apiPost('scp_contact_submit', {
                name: $form.find('[name="name"]').val(),
                email: $form.find('[name="email"]').val(),
                phone: $form.find('[name="phone"]').val(),
                department: $form.find('[name="department"]').val(),
                subject: $form.find('[name="subject"]').val(),
                message: $form.find('[name="message"]').val(),
                'g-recaptcha-response': token
            }, function (data) {
                $feedback
                    .css({ background: '#dcfce7', color: '#166534', border: '1px solid #bbf7d0' })
                    .text(data.message || 'Message sent!')
                    .fadeIn(200);
                $form[0].reset();
                $btn.prop('disabled', false).html(origText);
            }, function (err) {
                $feedback
                    .css({ background: '#fee2e2', color: '#991b1b', border: '1px solid #fecaca' })
                    .text(err)
                    .fadeIn(200);
                $btn.prop('disabled', false).html(origText);
            });
        });
    });

    // 6. Domain Availability Search (Both Public Home Page & Client Portal)
    function renderSingleDomainRow(item, highlightPrimary) {
        var safeDomain = escapeHtml(item.domain);
        var rawPrice = (item.price !== undefined && item.price !== null)
            ? item.price
            : (item.retail_price !== undefined && item.retail_price !== null ? item.retail_price : 19.19);
        var safePrice = escapeHtml(Number(rawPrice).toFixed(2));
        var rowStyle = highlightPrimary
            ? 'background:rgba(14,165,233,0.14);border:1px solid rgba(56,189,248,0.35);border-radius:10px;margin-bottom:10px;padding:14px 16px;'
            : '';

        var html = '<div class="scp-domain-result-item" style="' + rowStyle + '">';
        html += '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">';
        html += '<strong style="font-size:' + (highlightPrimary ? '17px' : '15px') + ';">' + safeDomain + '</strong>';
        if (item.available) {
            html += '<span class="scp-status-pill scp-status-active">Available</span>';
        } else {
            html += '<span class="scp-status-pill scp-status-canceled" style="background:rgba(239,68,68,0.2);color:#fca5a5;">Already Registered</span>';
        }
        if (!highlightPrimary && item.category === 'suggestion') {
            html += '<span class="scp-badge" style="background:rgba(16,185,129,0.2);color:#6ee7b7;margin-left:0;">Suggested Name</span>';
        }
        html += '</div>';

        html += '<div style="display:flex;align-items:center;gap:14px;">';
        html += '<span style="font-weight:700;font-size:15.5px;color:#fff;">$' + safePrice + ' / yr</span>';
        if (item.available) {
            if (cfg.isLoggedIn) {
                html += '<button type="button" class="scp-btn scp-btn-sm scp-btn-primary scp-domain-checkout-btn" ' +
                    'data-domain="' + safeDomain + '" ' +
                    'data-action="register">Register Now &rarr;</button>';
            } else {
                html += '<a href="index.php?page=register" class="scp-btn scp-btn-sm scp-btn-primary">Sign Up to Register &rarr;</a>';
            }
        }
        html += '</div></div>';
        return html;
    }

    function renderDomainSearchResults(results, $container) {
        if (!results || !results.length) {
            $container.html('<p style="color:#fff;margin:0;">No domain results returned.</p>').show();
            return;
        }

        var primaryItem = results[0];
        var alternatives = results.slice(1);

        var html = '<div class="scp-domain-results-wrap" style="margin-top:14px;text-align:left;">';
        html += renderSingleDomainRow(primaryItem, true);

        if (alternatives.length > 0) {
            var headingText = primaryItem.available
                ? 'Also Available — Other Extensions &amp; Variations:'
                : '<strong>' + escapeHtml(primaryItem.domain) + '</strong> is already taken — Here Are Available Alternatives:';
            html += '<div style="margin:14px 4px 8px;font-size:13px;color:#38bdf8;font-weight:600;letter-spacing:0.3px;">' + headingText + '</div>';
            $.each(alternatives, function (_, altItem) {
                html += renderSingleDomainRow(altItem, false);
            });
        }

        html += '</div>';
        $container.html(html).fadeIn(200);
    }

    $(document).on('submit', '#scp-public-domain-form, #scp-domain-search-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var isPublic = $form.attr('id') === 'scp-public-domain-form';
        var query = isPublic ? $('#scp-public-domain-input').val() : $('#scp-domain-query').val();
        var $results = isPublic ? $('#scp-public-domain-results') : $('#scp-domain-search-results');
        var $btn = $form.find('button[type="submit"]');
        var origText = $btn.text();

        $btn.prop('disabled', true).text('Checking Registrars...');
        $results.html('<p style="color:#cbd5e1;margin:8px 0;">Checking real-time availability with Namecheap &amp; OpenSRS...</p>').show();

        apiPost('scp_search_domain', { domain_query: query }, function (data) {
            renderDomainSearchResults(data.results || [], $results);
            $btn.prop('disabled', false).text(origText);
        }, function (err) {
            $results.html('<p style="color:#fca5a5;margin:8px 0;">' + escapeHtml(err) + '</p>').show();
            $btn.prop('disabled', false).text(origText);
        });
    });

    // 7. Domain Registration / Renewal Stripe Checkout
    $(document).on('click', '.scp-domain-checkout-btn', function () {
        var $btn = $(this);
        var origText = $btn.html();
        $btn.prop('disabled', true).text('Redirecting to Stripe...');

        apiPost('scp_checkout_domain', {
            domain_name: $btn.data('domain'),
            domain_action: $btn.data('action') || 'register',
            years: 1
        }, function (data) {
            if (data.checkout_url) {
                window.location.href = data.checkout_url;
            } else {
                showToast('Could not start domain checkout.', true);
                $btn.prop('disabled', false).html(origText);
            }
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html(origText);
        });
    });

    // 8. Stripe Product / Subscription Checkout (Both Home Page & Portal)
    $(document).on('click', '.scp-buy-product-btn, .scp-btn-subscribe', function () {
        var $btn = $(this);
        var origText = $btn.html();
        $btn.prop('disabled', true).text('Opening Stripe Checkout...');

        apiPost('scp_stripe_checkout', {
            price_id: $btn.data('price-id'),
            mode: $btn.data('mode') || 'subscription'
        }, function (data) {
            if (data.checkout_url) {
                window.location.href = data.checkout_url;
            } else {
                showToast('Unable to create Stripe Checkout session.', true);
                $btn.prop('disabled', false).html(origText);
            }
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html(origText);
        });
    });

    // 9. Open Stripe Customer Billing Portal
    $(document).on('click', '.scp-open-stripe-portal', function () {
        var $btn = $(this);
        var origText = $btn.text();
        $btn.prop('disabled', true).text('Opening Stripe...');

        apiPost('scp_stripe_portal', {}, function (data) {
            if (data.portal_url) {
                window.location.href = data.portal_url;
            } else {
                showToast('Unable to open Stripe Billing Portal.', true);
                $btn.prop('disabled', false).text(origText);
            }
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text(origText);
        });
    });

    // 10. Cancel Subscription
    $(document).on('click', '.scp-cancel-sub-btn', function () {
        if (!window.confirm('Are you sure you want to cancel this subscription at the end of the current billing period?')) {
            return;
        }
        var $btn = $(this);
        var subId = $btn.data('sub-id');
        $btn.prop('disabled', true).text('Canceling...');

        apiPost('scp_cancel_subscription', { subscription_id: subId }, function (data) {
            showToast(data.message || 'Subscription scheduled for cancellation.');
            setTimeout(function () { reloadPreservingTab('billing'); }, 1200);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Remove / Cancel');
        });
    });

    // 11. Live Stripe Sync Button
    $(document).on('click', '.scp-sync-stripe-btn', function () {
        var $btn = $(this);
        var origHtml = $btn.html();
        $btn.prop('disabled', true).text('Syncing with Stripe...');

        apiPost('scp_sync_stripe', {}, function (data) {
            showToast(data.message || 'Synced with Stripe!');
            setTimeout(function () { reloadPreservingTab(); }, 1000);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html(origHtml);
        });
    });

    // 12. One-Click WordPress SSO Launch (On-Demand 60s Single-Use Token)
    $(document).on('click', '.scp-sso-launch-btn', function () {
        var $btn = $(this);
        var siteId = $btn.data('site-id');
        var origHtml = $btn.html();
        $btn.prop('disabled', true).text('Generating Secure Login...');

        var ssoWindow = window.open('about:blank', '_blank');

        apiPost('scp_generate_sso_url', { site_id: siteId }, function (data) {
            $btn.prop('disabled', false).html(origHtml);
            if (data.sso_url) {
                if (ssoWindow) {
                    ssoWindow.location.href = data.sso_url;
                } else {
                    window.location.href = data.sso_url;
                }
            } else {
                if (ssoWindow) ssoWindow.close();
                showToast('Could not generate SSO login URL.', true);
            }
        }, function (err) {
            if (ssoWindow) ssoWindow.close();
            $btn.prop('disabled', false).html(origHtml);
            showToast(err, true);
        });
    });

    // 13. Client Profile & Password Update
    $(document).on('submit', '#scp-profile-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Saving...');

        var payload = {};
        $.each($form.serializeArray(), function (_, field) {
            payload[field.name] = field.value;
        });

        apiPost('scp_update_profile', payload, function (data) {
            showToast(data.message || 'Profile saved!');
            $btn.prop('disabled', false).text('Save Profile & Sync to Stripe');
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Save Profile & Sync to Stripe');
        });
    });

    $(document).on('submit', '#scp-password-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Updating...');

        apiPost('scp_change_password', {
            current_password: $form.find('[name="current_password"]').val(),
            new_password: $form.find('[name="new_password"]').val()
        }, function (data) {
            showToast(data.message || 'Password updated!');
            $form[0].reset();
            $btn.prop('disabled', false).text('Update Password');
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Update Password');
        });
    });

    // 14. Nameserver Modal & Update
    $(document).on('click', '.scp-open-ns-modal', function () {
        var domain = $(this).data('domain');
        var ns = $(this).data('ns');
        $('#scp-ns-domain-label').text(domain);
        $('#scp-ns-domain-input').val(domain);
        $('#scp-ns-textarea').val(ns);
        $('#scp-ns-modal').fadeIn(150);
    });

    $(document).on('click', '.scp-modal-close', function (e) {
        e.preventDefault();
        $('.scp-modal').fadeOut(150);
        if (window.scpCatalogModalNeedsReload) {
            window.scpCatalogModalNeedsReload = false;
            setTimeout(function () { reloadPreservingTab('admin-products'); }, 200);
        }
    });

    $(document).on('click', '.scp-modal', function (e) {
        if ($(e.target).hasClass('scp-modal')) {
            // Never discard a drafted email by an accidental backdrop click
            if (this.id === 'scp-compose-email-modal') return;
            $(this).fadeOut(150);
            if (window.scpCatalogModalNeedsReload) {
                window.scpCatalogModalNeedsReload = false;
                setTimeout(function () { reloadPreservingTab('admin-products'); }, 200);
            }
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            var $composeOpen = $('#scp-compose-email-modal:visible');
            if ($composeOpen.length && $.trim($composeOpen.find('textarea[name="message"]').val()) !== '' &&
                !window.confirm('Discard this email draft?')) {
                return;
            }
            $('.scp-modal:visible').fadeOut(150);
            if (window.scpCatalogModalNeedsReload) {
                window.scpCatalogModalNeedsReload = false;
                setTimeout(function () { reloadPreservingTab('admin-products'); }, 200);
            }
        }
    });

    $(document).on('submit', '#scp-ns-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('button[type="submit"]');
        $btn.prop('disabled', true).text('Updating DNS...');

        apiPost('scp_update_nameservers', {
            domain_name: $('#scp-ns-domain-input').val(),
            nameservers: $('#scp-ns-textarea').val()
        }, function (data) {
            showToast(data.message || 'Nameservers updated.');
            $('#scp-ns-modal').fadeOut(150);
            setTimeout(function () { reloadPreservingTab('domains'); }, 1000);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Save Nameservers');
        });
    });

    // 15. Administrator Control Center Actions
    $(document).on('click', '.scp-open-create-user-modal', function () {
        $('#scp-create-user-modal').fadeIn(150);
    });

    $(document).on('submit', '#scp-admin-create-user-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Creating & Syncing...');

        var payload = {};
        $.each($form.serializeArray(), function (_, field) {
            payload[field.name] = field.value;
        });

        apiPost('admin_create_user', payload, function (data) {
            showToast(data.message || 'Account created!');
            setTimeout(function () { reloadPreservingTab('admin-clients'); }, 1000);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Create Account');
        });
    });

    $(document).on('click', '.scp-admin-sync-client-btn', function () {
        var $btn = $(this);
        var userId = $btn.data('user-id');
        var origText = $btn.html();
        $btn.prop('disabled', true).text('Syncing...');

        apiPost('admin_sync_client_stripe', { user_id: userId }, function (data) {
            showToast(data.message || 'Client synced with Stripe!');
            setTimeout(function () { reloadPreservingTab('admin-clients'); }, 1000);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html(origText);
        });
    });

    $(document).on('click', '.scp-admin-import-all-stripe-btn', function () {
        var $btns = $('.scp-admin-import-all-stripe-btn');
        var origText = $(this).html();
        $btns.prop('disabled', true).text('Importing & Syncing All Stripe Data...');

        apiPost('admin_import_all_stripe', {}, function (data) {
            showToast(data.message || 'All Stripe records imported & synced!');
            setTimeout(function () { reloadPreservingTab(); }, 1400);
        }, function (err) {
            showToast(err, true);
            $btns.prop('disabled', false).html(origText);
        });
    });

    $(document).on('submit', '#scp-admin-add-website-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Assigning Website...');

        var payload = {};
        $.each($form.serializeArray(), function (_, field) {
            payload[field.name] = field.value;
        });

        apiPost('admin_add_website', payload, function (data) {
            showToast(data.message || 'Website assigned!');
            setTimeout(function () { reloadPreservingTab('admin-websites'); }, 1000);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Assign Website to Client');
        });
    });

    $(document).on('click', '.scp-admin-delete-site-btn', function () {
        if (!window.confirm('Remove this website assignment from the client?')) return;
        var siteId = $(this).data('site-id');
        apiPost('admin_delete_website', { site_id: siteId }, function (data) {
            showToast(data.message || 'Website removed.');
            setTimeout(function () { reloadPreservingTab('admin-websites'); }, 800);
        });
    });

    $(document).on('submit', '#scp-admin-add-domain-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Assigning Domain...');

        var payload = {};
        $.each($form.serializeArray(), function (_, field) {
            payload[field.name] = field.value;
        });

        apiPost('admin_add_domain', payload, function (data) {
            showToast(data.message || 'Domain assigned!');
            setTimeout(function () { reloadPreservingTab('admin-domains'); }, 1000);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Assign Domain to Client');
        });
    });

    $(document).on('click', '.scp-mark-inquiry-btn', function () {
        var id = $(this).data('id');
        var status = $(this).data('status');
        apiPost('admin_update_inquiry', { message_id: id, status: status }, function () {
            reloadPreservingTab('admin-inquiries');
        });
    });

    $(document).on('submit', '#scp-admin-settings-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Saving Settings...');

        var payload = {};
        $.each($form.serializeArray(), function (_, field) {
            payload[field.name] = field.value;
        });

        apiPost('admin_save_settings', payload, function (data) {
            showToast(data.message || 'Settings saved!');
            $btn.prop('disabled', false).text('Save All Settings');
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Save All Settings');
        });
    });

    // Admin Logo Upload and Reset Handlers
    $(document).on('change', '#scp-admin-logo-file', function () {
        var file = this.files && this.files[0];
        if (!file) return;

        var allowed = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'];
        if (allowed.indexOf(file.type) === -1 && !file.name.match(/\.(png|jpg|jpeg|svg|webp)$/i)) {
            showToast('Please select a valid image file (SVG, PNG, JPG, WebP).', true);
            $(this).val('');
            return;
        }
        if (file.size > 2 * 1024 * 1024) {
            showToast('Logo file size must be less than 2 MB.', true);
            $(this).val('');
            return;
        }

        $('#scp-admin-logo-status').text(file.name + ' (' + Math.round(file.size / 1024) + ' KB)');
        $('#scp-admin-logo-upload-btn').show();

        // Show local preview immediately using FileReader
        var reader = new FileReader();
        reader.onload = function (e) {
            $('#scp-admin-logo-preview').attr('src', e.target.result);
        };
        reader.readAsDataURL(file);
    });

    $(document).on('click', '#scp-admin-logo-upload-btn', function () {
        var fileInput = document.getElementById('scp-admin-logo-file');
        var file = fileInput && fileInput.files && fileInput.files[0];
        if (!file) {
            showToast('Please choose a logo file first.', true);
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('Uploading...');
        var formData = new FormData();
        formData.append('action', 'admin_upload_logo');
        formData.append('csrf_token', cfg.csrfToken);
        formData.append('logo', file);

        $.ajax({
            url: cfg.apiUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (res) {
                $btn.prop('disabled', false).text('Upload Selected Logo').hide();
                if (res && res.success) {
                    showToast(res.data.message || 'Logo uploaded successfully!');
                    var newLogoUrl = res.data.logo_url + '?v=' + new Date().getTime();
                    $('#scp-admin-logo-preview').attr('src', newLogoUrl);
                    $('.scp-brand-logo').attr('src', newLogoUrl);
                    $('#scp-admin-logo-reset-btn').show();
                    $('#scp-admin-logo-status').text('Active logo saved!');
                    $('#scp-admin-logo-file').val('');
                } else {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'Logo upload failed.';
                    showToast(msg, true);
                }
            },
            error: function () {
                $btn.prop('disabled', false).text('Upload Selected Logo');
                showToast('Network error during logo upload.', true);
            }
        });
    });

    $(document).on('click', '#scp-admin-logo-reset-btn', function () {
        if (!confirm('Are you sure you want to reset to the default portal logo?')) {
            return;
        }
        var $btn = $(this);
        $btn.prop('disabled', true).text('Resetting...');

        apiPost('admin_remove_logo', {}, function (data) {
            $btn.prop('disabled', false).text('Reset to Default Logo').hide();
            showToast(data.message || 'Logo reset to default.');
            var defaultLogo = (data.default_logo || 'assets/logo.svg') + '?v=' + new Date().getTime();
            $('#scp-admin-logo-preview').attr('src', defaultLogo);
            $('.scp-brand-logo').attr('src', defaultLogo);
            $('#scp-admin-logo-status').text('Default logo restored.');
            $('#scp-admin-logo-upload-btn').hide();
            $('#scp-admin-logo-file').val('');
        }, function (err) {
            $btn.prop('disabled', false).text('Reset to Default Logo');
            showToast(err, true);
        });
    });

    $(document).on('click', '.scp-copy-btn', function () {
        var text = $(this).data('copy');
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showToast('Copied to clipboard!');
            });
        } else {
            var $temp = $('<input>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $temp.remove();
            showToast('Copied to clipboard!');
        }
    });

    // 16. Product Categories, Multi-Category Assignment & Product On/Off Toggles
    function getCategoryNoteSvg(icon) {
        switch (icon) {
            case 'green-exclamation':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><circle cx="12" cy="16" r="0.75" fill="#16a34a"></circle></svg>';
            case 'red-exclamation':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><circle cx="12" cy="16" r="0.75" fill="#dc2626"></circle></svg>';
            case 'dark-grey-asterisk':
            case 'dark-gray-asterisk':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#334155" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="7" x2="12" y2="17"></line><line x1="7.67" y1="9.5" x2="16.33" y2="14.5"></line><line x1="7.67" y1="14.5" x2="16.33" y2="9.5"></line></svg>';
            case 'green-checkmark':
            case 'green-check':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="7.5 12 10.5 15 16.5 9"></polyline></svg>';
            case 'red-x':
            case 'red-cross':
                return '<svg class="scp-cat-note-svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="8.5" y1="8.5" x2="15.5" y2="15.5"></line><line x1="15.5" y1="8.5" x2="8.5" y2="15.5"></line></svg>';
            default:
                return '';
        }
    }

    function escapeAttr(str) {
        return String(str === undefined || str === null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function createCategoryNoteCard(index, note) {
        var n = note || {};
        var icon = n.icon || 'green-exclamation';
        var title = n.title || '';
        var text = n.text || '';

        var $card = $(
            '<div class="scp-cat-note-item-card" data-note-index="' + index + '">' +
                '<div class="scp-cat-note-item-header">' +
                    '<span class="scp-cat-note-item-title">Callout Section #' + (index + 1) + '</span>' +
                    '<div style="display:flex;align-items:center;gap:6px;">' +
                        '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-move-cat-note-up-btn" title="Move Callout Section Up">&uarr;</button>' +
                        '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-move-cat-note-down-btn" title="Move Callout Section Down">&darr;</button>' +
                        '<button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-remove-cat-note-btn" title="Remove this callout section">&times; Remove</button>' +
                    '</div>' +
                '</div>' +
                '<div class="scp-form-row">' +
                    '<div class="scp-form-group" style="flex:1.2;">' +
                        '<label>Callout Icon</label>' +
                        '<select class="scp-select scp-cat-note-icon-field">' +
                            '<option value="green-exclamation">&#9888; Green Exclamation (!)</option>' +
                            '<option value="red-exclamation">&#9888; Red Exclamation (!)</option>' +
                            '<option value="dark-grey-asterisk">&#10033; Dark Grey Asterisk (*)</option>' +
                            '<option value="green-checkmark">&#10004; Green Checkmark (&#10003;)</option>' +
                            '<option value="red-x">&#10006; Red X (&#10005;)</option>' +
                            '<option value="none">No Icon (Text Only)</option>' +
                        '</select>' +
                    '</div>' +
                    '<div class="scp-form-group" style="flex:2;">' +
                        '<label>Callout Heading / Prefix (Bold Title)</label>' +
                        '<input type="text" class="scp-cat-note-title-field" placeholder="e.g., All plans include:" />' +
                    '</div>' +
                '</div>' +
                '<div class="scp-form-group" style="margin-bottom:0;">' +
                    '<label>Callout Note Text / Details</label>' +
                    '<textarea class="scp-cat-note-text-field" rows="2" placeholder="e.g., 99.99% Uptime SLA, Weekly Website Backup, AI Site Creation, Multi-site Management, Plugins Management, Yoast SEO..."></textarea>' +
                '</div>' +
            '</div>'
        );

        $card.find('.scp-cat-note-icon-field').val(icon);
        $card.find('.scp-cat-note-title-field').val(title);
        $card.find('.scp-cat-note-text-field').val(text);
        return $card;
    }

    function reindexCategoryNoteCards() {
        var $cards = $('#scp-cat-notes-list .scp-cat-note-item-card');
        if ($cards.length === 0) {
            if ($('#scp-cat-notes-list .scp-cat-notes-empty-hint').length === 0) {
                $('#scp-cat-notes-list').html(
                    '<div class="scp-cat-notes-empty-hint">No callout sections configured. Click <strong>+ Add Callout Section</strong> above to add one.</div>'
                );
            }
            return;
        }
        $('#scp-cat-notes-list .scp-cat-notes-empty-hint').remove();
        $cards.each(function (idx) {
            $(this).attr('data-note-index', idx);
            $(this).find('.scp-cat-note-item-title').text('Callout Section #' + (idx + 1));
        });
    }

    function collectCategoryNotes() {
        var notes = [];
        $('#scp-cat-notes-list .scp-cat-note-item-card').each(function () {
            var $c = $(this);
            var icon = $c.find('.scp-cat-note-icon-field').val() || 'green-exclamation';
            var title = $.trim($c.find('.scp-cat-note-title-field').val());
            var text = $.trim($c.find('.scp-cat-note-text-field').val());
            if (title !== '' || text !== '') {
                notes.push({
                    icon: icon,
                    title: title,
                    text: text
                });
            }
        });
        $('#scp-cat-notes-json-input').val(JSON.stringify(notes));
        return notes;
    }

    function updateCategoryNotePreview() {
        var notes = collectCategoryNotes();
        var $previewWrap = $('#scp-cat-note-preview-wrap');
        var $previewList = $('#scp-cat-note-preview-list');

        if (notes.length > 0) {
            var html = '';
            $.each(notes, function (i, n) {
                var svg = getCategoryNoteSvg(n.icon);
                html += '<div class="scp-category-footer-note" style="margin-top:' + (i === 0 ? '0' : '8px') + ';">';
                if (svg) {
                    html += '<span class="scp-cat-note-icon-wrap">' + svg + '</span>';
                }
                html += '<div class="scp-cat-note-content">';
                if (n.title) {
                    html += '<strong>' + escapeAttr(n.title) + '</strong> ';
                }
                html += '<span>' + escapeAttr(n.text) + '</span>';
                html += '</div></div>';
            });
            $previewList.html(html);
            $previewWrap.slideDown(150);
        } else {
            $previewList.empty();
            $previewWrap.slideUp(150);
        }
    }

    function renderCategoryNotesBuilder(notesArray) {
        var $list = $('#scp-cat-notes-list');
        $list.empty();
        if (Array.isArray(notesArray) && notesArray.length > 0) {
            $.each(notesArray, function (idx, item) {
                $list.append(createCategoryNoteCard(idx, item));
            });
        } else {
            $list.append(createCategoryNoteCard(0, { icon: 'green-exclamation', title: '', text: '' }));
        }
        reindexCategoryNoteCards();
        updateCategoryNotePreview();
    }

    $(document).on('click', '#scp-add-cat-note-btn', function () {
        $('#scp-cat-notes-list .scp-cat-notes-empty-hint').remove();
        var nextIdx = $('#scp-cat-notes-list .scp-cat-note-item-card').length;
        var $newCard = createCategoryNoteCard(nextIdx, { icon: 'green-exclamation', title: '', text: '' });
        $('#scp-cat-notes-list').append($newCard);
        reindexCategoryNoteCards();
        $newCard.find('.scp-cat-note-title-field').focus();
        updateCategoryNotePreview();
    });

    $(document).on('click', '.scp-move-cat-note-up-btn', function () {
        var $card = $(this).closest('.scp-cat-note-item-card');
        var $prev = $card.prev('.scp-cat-note-item-card');
        if ($prev.length) {
            $card.insertBefore($prev);
            reindexCategoryNoteCards();
            updateCategoryNotePreview();
        }
    });

    $(document).on('click', '.scp-move-cat-note-down-btn', function () {
        var $card = $(this).closest('.scp-cat-note-item-card');
        var $next = $card.next('.scp-cat-note-item-card');
        if ($next.length) {
            $card.insertAfter($next);
            reindexCategoryNoteCards();
            updateCategoryNotePreview();
        }
    });

    $(document).on('click', '.scp-remove-cat-note-btn', function () {
        $(this).closest('.scp-cat-note-item-card').remove();
        reindexCategoryNoteCards();
        updateCategoryNotePreview();
    });

    $(document).on('input change', '.scp-cat-note-icon-field, .scp-cat-note-title-field, .scp-cat-note-text-field', updateCategoryNotePreview);

    $(document).on('submit', '#scp-admin-category-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $('#scp-save-category-btn');
        $btn.prop('disabled', true).text('Saving Category...');

        var notes = collectCategoryNotes();
        var payload = {};
        $.each($form.serializeArray(), function (_, field) {
            payload[field.name] = field.value;
        });
        payload.bottom_notes_json = JSON.stringify(notes);
        if (notes.length > 0) {
            payload.bottom_note_icon = notes[0].icon;
            payload.bottom_note_title = notes[0].title;
            payload.bottom_note_text = notes[0].text;
        } else {
            payload.bottom_note_icon = 'green-exclamation';
            payload.bottom_note_title = '';
            payload.bottom_note_text = '';
        }

        apiPost('admin_save_category', payload, function (data) {
            showToast(data.message || 'Category saved!');
            setTimeout(function () {
                reloadPreservingTab('admin-products');
            }, 700);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('+ Save Category');
        });
    });

    $(document).on('click', '.scp-edit-category-btn', function () {
        var $btn = $(this);
        $('#scp-cat-id-input').val($btn.data('id'));
        $('#scp-cat-name-input').val($btn.data('name')).focus();
        $('#scp-cat-desc-input').val($btn.data('desc'));
        $('#scp-cat-order-input').val($btn.data('order'));

        var rawNotes = $btn.attr('data-notes');
        var parsedNotes = [];
        if (rawNotes) {
            try {
                parsedNotes = JSON.parse(rawNotes);
            } catch (e) {
                parsedNotes = [];
            }
        }
        if ((!Array.isArray(parsedNotes) || parsedNotes.length === 0) && ($btn.data('note-title') || $btn.data('note-text'))) {
            parsedNotes = [{
                icon: $btn.data('note-icon') || 'green-exclamation',
                title: $btn.data('note-title') || '',
                text: $btn.data('note-text') || ''
            }];
        }

        renderCategoryNotesBuilder(parsedNotes);
        $('#scp-category-form-title').text('Edit Category: ' + $btn.data('name'));
        $('#scp-save-category-btn').text('Update Category');
        $('#scp-cancel-category-edit-btn').show();
    });

    $(document).on('click', '#scp-cancel-category-edit-btn', function () {
        $('#scp-cat-id-input').val('0');
        $('#scp-cat-name-input').val('');
        $('#scp-cat-desc-input').val('');
        $('#scp-cat-order-input').val('10');
        renderCategoryNotesBuilder([]);
        $('#scp-category-form-title').text('Create New Product Category');
        $('#scp-save-category-btn').text('+ Save Category');
        $(this).hide();
    });

    $(document).on('click', '.scp-delete-category-btn', function () {
        var catId = $(this).data('id');
        var catName = $(this).data('name');
        if (!window.confirm('Delete category "' + catName + '"? Products in this category will remain in your catalog.')) {
            return;
        }
        apiPost('admin_delete_category', { category_id: catId }, function (data) {
            showToast(data.message || 'Category deleted.');
            setTimeout(function () {
                reloadPreservingTab('admin-products');
            }, 700);
        }, function (err) {
            showToast(err, true);
        });
    });

    // Quick-Add New Category directly from a Product Row
    $(document).on('click', '.scp-open-quick-cat-modal', function () {
        var priceId = $(this).data('price-id');
        var prodName = $(this).data('product-name');
        $('#scp-quick-cat-price-input').val(priceId);
        $('#scp-quick-cat-product-label').text(prodName);
        $('#scp-quick-cat-name-input').val('');
        $('#scp-quick-category-modal').fadeIn(150);
        setTimeout(function () {
            $('#scp-quick-cat-name-input').focus();
        }, 160);
    });

    $(document).on('submit', '#scp-quick-category-form', function (e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        $btn.prop('disabled', true).text('Creating & Assigning...');

        var payload = {};
        $.each($form.serializeArray(), function (_, field) {
            payload[field.name] = field.value;
        });

        apiPost('admin_save_category', payload, function (data) {
            showToast(data.message || 'Category created and assigned!');
            $('#scp-quick-category-modal').fadeOut(150);
            setTimeout(function () {
                reloadPreservingTab('admin-products');
            }, 700);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Create & Assign Category');
        });
    });

    // Toggle Individual Product Visibility ON/OFF
    $(document).on('change', '.scp-product-visibility-toggle', function () {
        var $chk = $(this);
        var priceId = $chk.data('price-id');
        var isVisible = $chk.is(':checked') ? 1 : 0;
        var $row = $chk.closest('tr');
        var $label = $row.find('.scp-vis-label');

        $chk.prop('disabled', true);
        apiPost('admin_toggle_product_visibility', {
            price_id: priceId,
            visible: isVisible
        }, function (data) {
            $chk.prop('disabled', false);
            $row.attr('data-visible', isVisible ? '1' : '0');
            if (isVisible) {
                $row.removeClass('scp-product-row-off');
                $label.removeClass('scp-status-canceled').addClass('scp-status-active').text('ON');
            } else {
                $row.addClass('scp-product-row-off');
                $label.removeClass('scp-status-active').addClass('scp-status-canceled').text('OFF');
            }
            showToast(data.message || 'Product visibility updated.');
        }, function (err) {
            $chk.prop('disabled', false).prop('checked', !isVisible);
            showToast(err, true);
        });
    });

    // Toggle Multi-Category Pill Assignment on a Product Row
    $(document).on('click', '.scp-cat-select-pill', function () {
        var $pill = $(this);
        var priceId = $pill.data('price-id');
        var categoryId = $pill.data('category-id');
        var currentlyAssigned = String($pill.attr('data-assigned')) === '1';
        var nextAssign = currentlyAssigned ? 0 : 1;

        $pill.prop('disabled', true);
        apiPost('admin_toggle_product_category', {
            price_id: priceId,
            category_id: categoryId,
            assign: nextAssign
        }, function (data) {
            $pill.prop('disabled', false);
            $pill.attr('data-assigned', nextAssign ? '1' : '0');
            if (nextAssign) {
                $pill.addClass('active');
                $pill.find('.scp-pill-check').html('&#10003;');
            } else {
                $pill.removeClass('active');
                $pill.find('.scp-pill-check').text('+');
            }
            var $row = $pill.closest('tr');
            var anyActive = $row.find('.scp-cat-select-pill.active').length > 0;
            $row.attr('data-categorized', anyActive ? '1' : '0');
            showToast(data.message || 'Category updated.');
        }, function (err) {
            $pill.prop('disabled', false);
            showToast(err, true);
        });
    });

    // Filter & Search Products Table in Admin Control Center
    function filterAdminProductsTable() {
        var query = ($('#scp-admin-product-search').val() || '').toLowerCase().trim();
        var filterMode = $('#scp-admin-product-filter').val() || 'all';

        $('#scp-admin-products-table tbody tr.scp-admin-product-row').each(function () {
            var $row = $(this);
            var searchText = String($row.attr('data-search') || '');
            var isVis = String($row.attr('data-visible')) === '1';
            var isFeat = String($row.attr('data-featured')) === '1';
            var isCat = String($row.attr('data-categorized')) === '1';

            var matchesQuery = !query || searchText.indexOf(query) !== -1;
            var matchesFilter = true;
            if (filterMode === 'visible') {
                matchesFilter = isVis;
            } else if (filterMode === 'hidden') {
                matchesFilter = !isVis;
            } else if (filterMode === 'featured') {
                matchesFilter = isFeat;
            } else if (filterMode === 'uncategorized') {
                matchesFilter = !isCat;
            }

            if (matchesQuery && matchesFilter) {
                $row.show();
            } else {
                $row.hide();
            }
        });
    }

    $(document).on('input', '#scp-admin-product-search', filterAdminProductsTable);
    $(document).on('change', '#scp-admin-product-filter', filterAdminProductsTable);

    // Smooth Scroll for Front-End Category Quick-Jump Links
    $(document).on('click', '.scp-category-jump-pill, .scp-back-to-top-link', function (e) {
        var href = $(this).attr('href');
        if (href && href.charAt(0) === '#') {
            var $target = $(href);
            if ($target.length) {
                e.preventDefault();
                if ($(this).hasClass('scp-category-jump-pill')) {
                    $(this).closest('.scp-quicklinks-pills').find('.scp-category-jump-pill').removeClass('active');
                    $(this).addClass('active');
                }
                $('html, body').animate({
                    scrollTop: $target.offset().top - 90
                }, 400);
            }
        }
    });

    // 17. Client Billing, Invoice & Payment History Inspector Modal
    function formatMoneyCents(cents, currency) {
        var val = (parseInt(cents || 0, 10) / 100).toFixed(2);
        return '$' + val + (currency ? ' ' + String(currency).toUpperCase() : '');
    }

    function formatDateTs(ts) {
        if (!ts || parseInt(ts, 10) <= 0) return '—';
        var d = new Date(parseInt(ts, 10) * 1000);
        return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    $(document).on('click', '.scp-view-client-billing-btn', function (e) {
        e.preventDefault();
        var userId = $(this).data('user-id');
        if (!userId) return;

        $('#scp-cb-modal-title').text('Loading Client Billing History...');
        $('#scp-cb-modal-subtitle').text('Fetching invoices, payment history, and outstanding balances...');
        $('#scp-cb-modal-body').html('<div class="scp-empty-state"><p>Loading client financial records from portal &amp; Stripe...</p></div>');
        $('#scp-client-billing-modal').fadeIn(150);

        apiPost('admin_get_client_billing', { user_id: userId }, function (res) {
            var data = res.billing || {};
            var client = data.user || {};
            var sum = data.summary || {};
            var outInvoices = data.outstanding_invoices || [];
            var invoices = data.invoices || [];
            var payments = data.payments || [];
            var subs = data.subscriptions || [];
            var quotes = data.quotes || [];

            $('#scp-cb-modal-title').text((client.name || client.email || 'Client') + ' — Billing & Payment History');
            var subParts = [];
            if (client.company) subParts.push(client.company);
            if (client.email) subParts.push(client.email);
            if (client.phone) subParts.push(client.phone);
            if (client.stripe_customer_id) subParts.push('Stripe ID: ' + client.stripe_customer_id);
            $('#scp-cb-modal-subtitle').text(subParts.join(' • '));

            var hasPastDue = (parseInt(sum.past_due_balance_cents || 0, 10) > 0) || (parseInt(sum.past_due_invoices_count || 0, 10) > 0);
            var hasOutstanding = (parseInt(sum.outstanding_balance_cents || 0, 10) > 0);

            var html = '';

            // 1. Client Financial KPI Summary Cards
            html += '<div class="scp-stats-grid" style="margin-bottom:20px;">';
            html += '<div class="scp-stat-card" style="padding:14px 16px;' + (hasPastDue ? 'border-color:#991b1b;background:#fff1f2;' : '') + '">';
            html += '<div class="scp-stat-label">Outstanding Balance</div>';
            html += '<div class="scp-stat-value ' + (hasOutstanding ? 'scp-text-danger' : 'scp-text-success') + '" style="font-size:22px;">' + formatMoneyCents(sum.outstanding_balance_cents || 0) + '</div>';
            html += '<div class="scp-stat-footer" style="font-size:12px;">' + (hasPastDue ? '<strong style="color:#991b1b;">&#9888; ' + formatMoneyCents(sum.past_due_balance_cents || 0) + ' Past Due</strong>' : 'No past-due balance') + '</div>';
            html += '</div>';

            html += '<div class="scp-stat-card" style="padding:14px 16px;">';
            html += '<div class="scp-stat-label">Open / Unpaid Invoices</div>';
            html += '<div class="scp-stat-value" style="font-size:22px;">' + parseInt(sum.open_invoices_count || 0, 10) + '</div>';
            html += '<div class="scp-stat-footer" style="font-size:12px;">' + parseInt(sum.past_due_invoices_count || 0, 10) + ' Past-Due • ' + invoices.length + ' Total Invoices</div>';
            html += '</div>';

            html += '<div class="scp-stat-card" style="padding:14px 16px;">';
            html += '<div class="scp-stat-label">Total Paid (Lifetime)</div>';
            html += '<div class="scp-stat-value scp-text-success" style="font-size:22px;">' + formatMoneyCents(sum.total_paid_cents || 0) + '</div>';
            html += '<div class="scp-stat-footer" style="font-size:12px;">' + payments.length + ' Recorded Payments</div>';
            html += '</div>';

            html += '<div class="scp-stat-card" style="padding:14px 16px;">';
            html += '<div class="scp-stat-label">Active Subscriptions</div>';
            html += '<div class="scp-stat-value" style="font-size:22px;color:#0284c7;">' + parseInt(sum.active_subscriptions_count || 0, 10) + '</div>';
            html += '<div class="scp-stat-footer" style="font-size:12px;">' + subs.length + ' Total Subscriptions • ' + quotes.length + ' Quotes</div>';
            html += '</div>';
            html += '</div>';

            // 2. Outstanding Payments / Unpaid Invoices Alert Section
            html += '<div style="margin-bottom:22px;border:1px solid ' + (outInvoices.length > 0 ? '#fecdd3' : '#e2e8f0') + ';border-radius:10px;padding:16px;background:' + (outInvoices.length > 0 ? '#fff1f2' : '#f8fafc') + ';">';
            html += '<h4 style="margin:0 0 10px 0;font-size:15px;color:#0f172a;">Outstanding Payments &amp; Open Invoices (' + outInvoices.length + ')</h4>';
            if (outInvoices.length === 0) {
                html += '<p style="margin:0;font-size:13.5px;color:#15803d;font-weight:600;">&#10003; This client has no outstanding payments or unpaid invoices.</p>';
            } else {
                html += '<div class="scp-table-responsive"><table class="scp-table"><thead><tr><th>Invoice #</th><th>Issued</th><th>Due Date</th><th>Amount Due</th><th>Status</th><th>Action</th></tr></thead><tbody>';
                for (var i = 0; i < outInvoices.length; i++) {
                    var oi = outInvoices[i];
                    var oiPast = !!oi.is_past_due;
                    var oiAmt = oi.amount_due ? oi.amount_due : oi.total_cents;
                    html += '<tr class="' + (oiPast ? 'scp-row-past-due' : '') + '">';
                    html += '<td><strong>' + escapeHtml(oi.invoice_number || oi.invoice_id) + '</strong></td>';
                    html += '<td>' + formatDateTs(oi.created_ts) + '</td>';
                    html += '<td>' + (oi.due_date ? formatDateTs(oi.due_date) : 'Upon Receipt') + '</td>';
                    html += '<td><strong>' + formatMoneyCents(oiAmt, oi.currency) + '</strong></td>';
                    html += '<td>' + (oiPast ? '<span class="scp-status-pill scp-status-past-due">PAST DUE</span>' : '<span class="scp-status-pill scp-status-' + escapeHtml(oi.status) + '">' + escapeHtml(oi.status.toUpperCase()) + '</span>') + '</td>';
                    html += '<td>';
                    if (oi.hosted_invoice_url) {
                        html += '<a href="' + escapeHtml(oi.hosted_invoice_url) + '" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-primary" style="margin-right:6px;">View / Pay Link &#8599;</a>';
                    }
                    if (oi.invoice_pdf) {
                        html += '<a href="' + escapeHtml(oi.invoice_pdf) + '" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">PDF &#8595;</a>';
                    }
                    html += '</td></tr>';
                }
                html += '</tbody></table></div>';
            }
            html += '</div>';

            // 3. Complete Invoice History & Payment History Side-by-Side
            html += '<div class="scp-overview-columns" style="margin-bottom:22px;">';

            // Left: Full Invoice History
            html += '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#ffffff;">';
            html += '<h4 style="margin:0 0 12px 0;font-size:15px;color:#0f172a;">Complete Invoice History (' + invoices.length + ')</h4>';
            if (invoices.length === 0) {
                html += '<div class="scp-empty-state" style="padding:16px;"><p>No invoices found for this client.</p></div>';
            } else {
                html += '<div class="scp-table-responsive"><table class="scp-table"><thead><tr><th>Invoice #</th><th>Date / Due</th><th>Total</th><th>Status</th><th>Links</th></tr></thead><tbody>';
                for (var j = 0; j < invoices.length; j++) {
                    var inv = invoices[j];
                    var invPast = !!inv.is_past_due;
                    var invTotal = (inv.status === 'open' || inv.status === 'past_due' || inv.status === 'uncollectible') && inv.amount_due ? inv.amount_due : (inv.total_cents || inv.amount_paid || inv.amount_due);
                    html += '<tr class="' + (invPast ? 'scp-row-past-due' : '') + '">';
                    html += '<td><strong>' + escapeHtml(inv.invoice_number || inv.invoice_id) + '</strong></td>';
                    html += '<td style="font-size:12px;">' + formatDateTs(inv.created_ts) + (inv.due_date ? '<div class="scp-row-subtext" style="font-size:11px;color:#64748b;">Due: ' + formatDateTs(inv.due_date) + '</div>' : '') + '</td>';
                    html += '<td><strong>' + formatMoneyCents(invTotal, inv.currency) + '</strong></td>';
                    html += '<td>' + (invPast ? '<span class="scp-status-pill scp-status-past-due">PAST DUE</span>' : '<span class="scp-status-pill scp-status-' + escapeHtml(inv.status) + '">' + escapeHtml(inv.status.toUpperCase()) + '</span>') + '</td>';
                    html += '<td>';
                    if (inv.hosted_invoice_url) {
                        html += '<a href="' + escapeHtml(inv.hosted_invoice_url) + '" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline" style="margin-right:4px;">Invoice &#8599;</a>';
                    }
                    if (inv.invoice_pdf) {
                        html += '<a href="' + escapeHtml(inv.invoice_pdf) + '" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">PDF</a>';
                    }
                    html += '</td></tr>';
                }
                html += '</tbody></table></div>';
            }
            html += '</div>';

            // Right: Complete Payment History
            html += '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#ffffff;">';
            html += '<h4 style="margin:0 0 12px 0;font-size:15px;color:#0f172a;">Payment History &amp; Receipts (' + payments.length + ')</h4>';
            if (payments.length === 0) {
                html += '<div class="scp-empty-state" style="padding:16px;"><p>No payments recorded for this client yet.</p></div>';
            } else {
                html += '<div class="scp-table-responsive"><table class="scp-table"><thead><tr><th>Date</th><th>Description &amp; Card</th><th>Amount</th><th>Status</th><th>Receipt</th></tr></thead><tbody>';
                for (var k = 0; k < payments.length; k++) {
                    var pay = payments[k];
                    var isRef = parseInt(pay.refunded || 0, 10) === 1;
                    html += '<tr>';
                    html += '<td style="font-size:12px;white-space:nowrap;">' + formatDateTs(pay.created_ts) + '</td>';
                    html += '<td><div style="font-weight:600;font-size:13px;">' + escapeHtml(pay.description || 'Stripe Charge') + '</div>';
                    if (pay.card_last4) {
                        html += '<div style="font-size:11px;color:#64748b;">' + escapeHtml(pay.card_brand || 'Card') + ' &bull;&bull;&bull;&bull; ' + escapeHtml(pay.card_last4) + '</div>';
                    }
                    html += '</td>';
                    html += '<td><strong>' + formatMoneyCents(pay.amount_cents, pay.currency) + '</strong></td>';
                    html += '<td><span class="scp-status-pill scp-status-' + (isRef ? 'canceled' : (pay.status === 'succeeded' ? 'active' : 'open')) + '">' + (isRef ? 'REFUNDED' : escapeHtml((pay.status || '').toUpperCase())) + '</span></td>';
                    html += '<td>' + (pay.receipt_url ? '<a href="' + escapeHtml(pay.receipt_url) + '" target="_blank" rel="noopener" class="scp-btn scp-btn-xs scp-btn-outline">Receipt &#8599;</a>' : '—') + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table></div>';
            }
            html += '</div>';
            html += '</div>';

            // 4. Subscriptions & Quotes (if any)
            if (subs.length > 0 || quotes.length > 0) {
                html += '<div class="scp-overview-columns" style="margin-bottom:0;">';
                html += '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#ffffff;">';
                html += '<h4 style="margin:0 0 12px 0;font-size:15px;color:#0f172a;">Client Subscriptions (' + subs.length + ')</h4>';
                if (subs.length === 0) {
                    html += '<div class="scp-empty-state" style="padding:14px;"><p>No subscriptions.</p></div>';
                } else {
                    html += '<div class="scp-table-responsive"><table class="scp-table"><thead><tr><th>Plan</th><th>Billing</th><th>Status</th><th>Renews</th></tr></thead><tbody>';
                    for (var s = 0; s < subs.length; s++) {
                        var sub = subs[s];
                        html += '<tr>';
                        html += '<td><strong>' + escapeHtml(sub.plan_name) + '</strong></td>';
                        html += '<td>' + formatMoneyCents(sub.amount_cents, sub.currency) + '/' + escapeHtml(sub.interval_unit || 'mo') + '</td>';
                        html += '<td><span class="scp-status-pill scp-status-' + escapeHtml(sub.status) + '">' + escapeHtml((sub.status || '').toUpperCase()) + '</span></td>';
                        html += '<td style="font-size:12px;">' + formatDateTs(sub.current_period_end) + '</td>';
                        html += '</tr>';
                    }
                    html += '</tbody></table></div>';
                }
                html += '</div>';

                html += '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:16px;background:#ffffff;">';
                html += '<h4 style="margin:0 0 12px 0;font-size:15px;color:#0f172a;">Client Quotes (' + quotes.length + ')</h4>';
                if (quotes.length === 0) {
                    html += '<div class="scp-empty-state" style="padding:14px;"><p>No quotes.</p></div>';
                } else {
                    html += '<div class="scp-table-responsive"><table class="scp-table"><thead><tr><th>Quote #</th><th>Expires / Due</th><th>Total</th><th>Status</th></tr></thead><tbody>';
                    for (var q = 0; q < quotes.length; q++) {
                        var qt = quotes[q];
                        var qtPast = !!qt.is_past_due;
                        html += '<tr class="' + (qtPast ? 'scp-row-past-due' : '') + '">';
                        html += '<td><strong>' + escapeHtml(qt.quote_number || qt.quote_id) + '</strong></td>';
                        html += '<td style="font-size:12px;">' + formatDateTs(qt.expires_at) + '</td>';
                        html += '<td><strong>' + formatMoneyCents(qt.amount_total, qt.currency) + '</strong></td>';
                        html += '<td>' + (qtPast ? '<span class="scp-status-pill scp-status-past-due">PAST DUE DATE</span>' : '<span class="scp-status-pill scp-status-' + escapeHtml(qt.status) + '">' + escapeHtml((qt.status || '').toUpperCase()) + '</span>') + '</td>';
                        html += '</tr>';
                    }
                    html += '</tbody></table></div>';
                }
                html += '</div>';
                html += '</div>';
            }

            $('#scp-cb-modal-body').html(html);
        }, function (err) {
            $('#scp-cb-modal-body').html('<div class="scp-empty-state"><p style="color:#dc2626;">' + escapeHtml(err || 'Unable to load client billing history.') + '</p></div>');
        });
    });

    // =========================================================================
    // 18. Multi-Term Pricing Switcher (Category-Level & Card-Level) — v3.0.2
    // =========================================================================
    function applyTermToCard($card, termKey, termIndex) {
        if (String($card.attr('data-has-terms')) !== '1') {
            return; // One-time or single-price products stay untouched
        }
        var rawTerms = $card.attr('data-terms');
        var terms = [];
        try {
            terms = typeof rawTerms === 'string' ? JSON.parse(rawTerms) : (rawTerms || []);
        } catch (e) {
            terms = [];
        }
        if (!terms || !terms.length) return;

        var matched = null;
        var matchedIndex = -1;
        if (termIndex !== undefined && termIndex !== null && !isNaN(termIndex) && terms[termIndex]) {
            matched = terms[termIndex];
            matchedIndex = termIndex;
        } else {
            for (var i = 0; i < terms.length; i++) {
                if (terms[i].term_key === termKey) {
                    matched = terms[i];
                    matchedIndex = i;
                    break;
                }
            }
            if (!matched) {
                for (var j = 0; j < terms.length; j++) {
                    if (terms[j].canonical_term_key === termKey) {
                        matched = terms[j];
                        matchedIndex = j;
                        break;
                    }
                }
            }
        }
        if (!matched) return;

        $card.find('.scp-card-term-btn').removeClass('active');
        var $targetBtn = $card.find('.scp-card-term-btn[data-term-index="' + matchedIndex + '"]');
        if ($targetBtn.length) {
            $targetBtn.addClass('active');
        } else {
            $card.find('.scp-card-term-btn[data-term-key="' + matched.term_key + '"]').first().addClass('active');
        }

        var amtFmt = parseFloat(matched.price_amount || 0).toFixed(2);
        $card.find('.scp-js-price-amount').text(amtFmt);
        $card.find('.scp-js-price-unit').text(matched.price_unit || '');

        var $saveBadge = $card.find('.scp-js-save-badge');
        if (matched.save_badge_text && String(matched.save_badge_text).trim() !== '') {
            $saveBadge.text(matched.save_badge_text).show();
        } else {
            $saveBadge.text('').hide();
        }

        var $termLen = $card.find('.scp-js-term-length');
        if (matched.term_length_label && String(matched.term_length_label).trim() !== '') {
            $termLen.text(matched.term_length_label).show();
        } else {
            $termLen.text('').hide();
        }

        var $renewsAt = $card.find('.scp-js-renews-at');
        if (matched.renews_at_label && String(matched.renews_at_label).trim() !== '') {
            $renewsAt.text(matched.renews_at_label).show();
        } else {
            $renewsAt.text('').hide();
        }

        var $ctaBtn = $card.find('.scp-js-cta-btn');
        if ($ctaBtn.length && matched.stripe_hook) {
            $ctaBtn.attr('data-price-id', matched.stripe_hook);
            $ctaBtn.data('price-id', matched.stripe_hook);
            var modeVal = (matched.billing_mode === 'one_time') ? 'payment' : 'subscription';
            $ctaBtn.attr('data-mode', modeVal);
            $ctaBtn.data('mode', modeVal);
        }
    }

    // Card-level term button click
    $(document).on('click', '.scp-card-term-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var termKey = $btn.attr('data-term-key');
        var rawIdx = $btn.attr('data-term-index');
        var termIdx = (rawIdx !== undefined && rawIdx !== '') ? parseInt(rawIdx, 10) : undefined;
        var $card = $btn.closest('.scp-v3-product-card');
        applyTermToCard($card, termKey, termIdx);
    });

    // Category-level master term switcher button click
    $(document).on('click', '.scp-category-term-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var termKey = $btn.attr('data-term-key');
        $btn.closest('.scp-category-term-bar').find('.scp-category-term-btn').removeClass('active');
        $btn.addClass('active');

        var $secBlock = $btn.closest('.scp-category-section-block');
        $secBlock.find('.scp-v3-product-card').each(function () {
            applyTermToCard($(this), termKey);
        });
    });

    // =========================================================================
    // 19. Admin Multi-Platform Website / App Type Selector — v3.0.0
    // =========================================================================
    $(document).on('change', '#scp-admin-platform-type-select', function () {
        var pType = $(this).val() || 'wordpress';
        var $planInput = $('#scp-admin-hosting-plan-input');
        var $loginLabel = $('#scp-admin-login-url-label');
        var $loginInput = $('#scp-admin-login-url-input');
        var $wpSsoRow = $('#scp-admin-wp-sso-fields-row');
        var $ssoCb = $('#scp-admin-enable-sso-cb');

        if (pType === 'wordpress') {
            $planInput.val('Managed Cloud WordPress');
            $loginLabel.text('WP Admin Login URL');
            $loginInput.attr('placeholder', 'https://clientdomain.com/wp-login.php');
            $wpSsoRow.slideDown(150);
            $ssoCb.prop('checked', true);
        } else if (pType === 'ad_server') {
            $planInput.val('Managed Ad Server Cluster');
            $loginLabel.text('Ad Server Control Panel Login URL');
            $loginInput.attr('placeholder', 'https://ads.clientdomain.com/admin');
            $wpSsoRow.slideUp(150);
            $ssoCb.prop('checked', false);
        } else if (pType === 'mass_email') {
            $planInput.val('Dedicated Mass Emailing Server');
            $loginLabel.text('Mass Emailing Dashboard Login URL');
            $loginInput.attr('placeholder', 'https://mail.clientdomain.com/login');
            $wpSsoRow.slideUp(150);
            $ssoCb.prop('checked', false);
        } else {
            $planInput.val('Managed Cloud & VPS Hosting');
            $loginLabel.text('Control Panel / App Login URL');
            $loginInput.attr('placeholder', 'https://panel.clientdomain.com');
            $wpSsoRow.slideUp(150);
            $ssoCb.prop('checked', false);
        }
    });

    // =========================================================================
    // 20. Admin Custom Product, Multi-Term & Features Visual Builder — v3.0.2
    // =========================================================================
    var termRadioCounter = 0;

    function buildTermRowHtml(termObj) {
        termRadioCounter++;
        var t = termObj || {};
        var tName = t.term_name || '';
        var pAmt = (t.price_amount !== undefined && t.price_amount !== '') ? parseFloat(t.price_amount).toFixed(2) : '0.00';
        var pUnit = t.price_unit !== undefined ? t.price_unit : '/mo';
        var tLen = t.term_length_label || '';
        var tRen = t.renews_at_label || '';
        var sBadge = t.save_badge_text || '';
        var sHook = t.stripe_hook || '';
        var bMode = t.billing_mode || 'recurring';
        var isDef = !!t.is_default;

        var html = '<div class="scp-cp-term-row-card">';
        html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:10px;flex-wrap:wrap;">';
        html += '<label style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;color:#0369a1;cursor:pointer;">';
        html += '<input type="radio" name="scp_cp_default_term_radio" class="scp-cp-term-default-radio" ' + (isDef ? 'checked' : '') + ' style="width:auto;margin:0;" />';
        html += '<span>Default Selected Term on Page Load</span>';
        html += '</label>';
        html += '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-split-term-btn" style="border-color:#0284c7;color:#0369a1;background:#f0f9ff;" title="Copy all product information to a new product listing and move this price-point/term to the new product">&#10697; Copy Product &amp; Move Term to New Product</button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-cp-remove-term-row-btn">&times; Remove Term</button>';
        html += '</div>';
        html += '</div>';

        html += '<div class="scp-form-row" style="margin-bottom:8px;">';
        html += '<div class="scp-form-group" style="flex:1;margin-bottom:0;"><label>Term Pill Label *</label><input type="text" class="scp-cp-t-name" placeholder="e.g., 12 Months or 36 Months" value="' + escapeHtml(tName) + '" required /></div>';
        html += '<div class="scp-form-group" style="flex:0.8;margin-bottom:0;"><label>Price Amount ($)</label><input type="number" step="0.01" min="0" class="scp-cp-t-price" value="' + escapeHtml(pAmt) + '" /></div>';
        html += '<div class="scp-form-group" style="flex:0.7;margin-bottom:0;"><label>Price Unit</label><input type="text" class="scp-cp-t-unit" placeholder="/mo" value="' + escapeHtml(pUnit) + '" /></div>';
        html += '<div class="scp-form-group" style="flex:0.9;margin-bottom:0;"><label>Save Badge (Save $/%)</label><input type="text" class="scp-cp-t-save" placeholder="e.g., Save 50%" value="' + escapeHtml(sBadge) + '" /></div>';
        html += '</div>';

        html += '<div class="scp-form-row" style="margin-bottom:0;">';
        html += '<div class="scp-form-group" style="flex:1;margin-bottom:0;"><label>Term Length Subtext</label><input type="text" class="scp-cp-t-len" placeholder="e.g., For 36 month term" value="' + escapeHtml(tLen) + '" /></div>';
        html += '<div class="scp-form-group" style="flex:1;margin-bottom:0;"><label>Renews At Subtext</label><input type="text" class="scp-cp-t-renews" placeholder="e.g., Renews at $13.99/mo" value="' + escapeHtml(tRen) + '" /></div>';
        html += '<div class="scp-form-group" style="flex:0.8;margin-bottom:0;"><label>Mode</label><select class="scp-cp-t-mode"><option value="recurring" ' + (bMode !== 'one_time' ? 'selected' : '') + '>Recurring</option><option value="one_time" ' + (bMode === 'one_time' ? 'selected' : '') + '>One-Time</option></select></div>';
        html += '<div class="scp-form-group" style="flex:1.4;margin-bottom:0;"><label>Stripe Hook / Link for this Term</label><input type="text" class="scp-cp-t-hook" list="scp-stripe-price-hooks-datalist" placeholder="https://buy.stripe.com/... or price_..." value="' + escapeHtml(sHook) + '" /></div>';
        html += '</div>';
        html += '</div>';
        return html;
    }

    function getFeatureHintSvg(iconKey) {
        switch (String(iconKey || 'black-info').toLowerCase()) {
            case 'green-checkmark':
            case 'green-check':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="7.5 12 10.5 15 16.5 9"></polyline></svg>';
            case 'red-x':
            case 'red-cross':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="8.5" y1="8.5" x2="15.5" y2="15.5"></line><line x1="15.5" y1="8.5" x2="8.5" y2="15.5"></line></svg>';
            case 'blue-question':
            case 'question':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><circle cx="12" cy="17" r="0.85" fill="#2563eb" stroke="none"></circle></svg>';
            case 'yellow-triangle':
            case 'disclosure-triangle':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="#fef08a" stroke="#ca8a04" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13.5" stroke="#854d0e" stroke-width="2.3"></line><circle cx="12" cy="17" r="0.9" fill="#854d0e" stroke="none"></circle></svg>';
            case 'red-exclamation':
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="7.5" x2="12" y2="12.5"></line><circle cx="12" cy="16.5" r="0.85" fill="#dc2626" stroke="none"></circle></svg>';
            case 'black-info':
            default:
                return '<svg class="scp-feat-hint-svg" viewBox="0 0 24 24" fill="none" stroke="#0f172a" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16.2" x2="12" y2="11.2"></line><circle cx="12" cy="7.8" r="0.9" fill="#0f172a" stroke="none"></circle></svg>';
        }
    }

    function buildFeatureItemRowHtml(featObj) {
        var f = featObj || {};
        var text = f.text || '';
        var isBold = !!f.is_bold;
        var isItalic = !!f.is_italic;
        var isNew = !!f.is_new;
        var hint = $.trim(String(f.hint || ''));
        var hintIcon = String(f.hint_icon || 'black-info').toLowerCase();
        var hasHint = hint !== '';
        var icon = f.icon || 'check';

        var textStyle = (isBold ? 'font-weight:700;' : '') + (isItalic ? 'font-style:italic;' : '');

        var html = '<div class="scp-cp-feature-item-row" data-bold="' + (isBold ? '1' : '0') + '" data-italic="' + (isItalic ? '1' : '0') + '" data-new="' + (isNew ? '1' : '0') + '">';
        html += '<select class="scp-cp-f-icon" title="Select bullet / icon style">';
        html += '<option value="check" ' + (icon === 'check' ? 'selected' : '') + '>&#10003; Check</option>';
        html += '<option value="cross" ' + (icon === 'cross' ? 'selected' : '') + '>&#10005; Not Included</option>';
        html += '<option value="bullet" ' + (icon === 'bullet' ? 'selected' : '') + '>&bull; Bullet</option>';
        html += '</select>';
        html += '<input type="text" class="scp-cp-f-text" placeholder="Enter feature (e.g., 10 GB storage per mailbox)" value="' + escapeHtml(text) + '" style="' + textStyle + '" />';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-cp-feat-bold-btn ' + (isBold ? 'active' : '') + '" title="Toggle Bold Text"><strong>B</strong></button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-cp-feat-italic-btn ' + (isItalic ? 'active' : '') + '" title="Toggle Italic Text"><em>I</em></button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-cp-feat-new-btn ' + (isNew ? 'active' : '') + '" title="Mark Feature as \'New\' (displays a New badge next to the feature)">New</button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-cp-feat-hint-btn ' + (hasHint ? 'active' : '') + '" title="Add / Edit Mouse-Over Hint / Extra Information">&#9432; Hint</button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-feat-up-btn" title="Move Feature Up">&uarr;</button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-feat-down-btn" title="Move Feature Down">&darr;</button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-cp-feat-del-btn" title="Remove Feature">&times;</button>';
        html += '<div class="scp-cp-f-hint-wrap" style="' + (hasHint ? '' : 'display:none;') + '">';
        html += '<span class="scp-cp-f-hint-label">Companion Icon:</span>';
        html += '<span class="scp-cp-f-hint-icon-preview">' + getFeatureHintSvg(hintIcon) + '</span>';
        html += '<select class="scp-cp-f-hint-icon" title="Select companion icon shown at the end of the feature row">';
        html += '<option value="black-info" ' + (hintIcon === 'black-info' ? 'selected' : '') + '>Black (i) Info Circle</option>';
        html += '<option value="blue-question" ' + (hintIcon === 'blue-question' ? 'selected' : '') + '>Blue (?) Question Mark</option>';
        html += '<option value="green-checkmark" ' + (hintIcon === 'green-checkmark' ? 'selected' : '') + '>Green Checkmark</option>';
        html += '<option value="red-x" ' + (hintIcon === 'red-x' ? 'selected' : '') + '>Red X</option>';
        html += '<option value="yellow-triangle" ' + (hintIcon === 'yellow-triangle' ? 'selected' : '') + '>Yellow Disclosure Triangle</option>';
        html += '<option value="red-exclamation" ' + (hintIcon === 'red-exclamation' ? 'selected' : '') + '>Red Exclamation Mark</option>';
        html += '</select>';
        html += '<input type="text" class="scp-cp-f-hint" placeholder="Extra info shown on mouse-over (e.g., A specific storage space will be allotted for each mailbox that is created)" value="' + escapeHtml(hint) + '" />';
        html += '</div>';
        html += '</div>';
        return html;
    }

    function buildFeatureGroupHtml(headingText, featuresArray) {
        var h = headingText || '';
        var items = (featuresArray && featuresArray.length) ? featuresArray : [{ text: '', is_bold: 0, is_italic: 0, hint: '', hint_icon: 'black-info', icon: 'check' }];
        var showHeadings = $('#scp-cp-use-feature-headings').is(':checked');

        var html = '<div class="scp-cp-feature-group-box">';
        html += '<div class="scp-cp-feature-group-header" style="' + (showHeadings ? '' : 'display:none;') + '">';
        html += '<div style="flex:1;display:flex;align-items:center;gap:8px;">';
        html += '<span style="font-size:11.5px;font-weight:700;color:#475569;text-transform:uppercase;white-space:nowrap;">Section Heading:</span>';
        html += '<input type="text" class="scp-cp-fg-heading-input" placeholder="e.g., PROTECTION & SUPPORT or ROOM TO GROW (leave blank for no heading)" value="' + escapeHtml(h) + '" />';
        html += '</div>';
        html += '<div style="display:flex;align-items:center;gap:6px;">';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-fg-up-btn" title="Move Section Up">&uarr;</button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-fg-down-btn" title="Move Section Down">&darr;</button>';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-cp-remove-feature-group-btn" title="Remove this heading group">&times; Remove Group</button>';
        html += '</div>';
        html += '</div>';

        html += '<div class="scp-cp-feature-items-list">';
        for (var i = 0; i < items.length; i++) {
            html += buildFeatureItemRowHtml(items[i]);
        }
        html += '</div>';

        html += '<div style="margin-top:8px;">';
        html += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-cp-add-feature-in-group-btn">+ Add Feature under this Section</button>';
        html += '</div>';
        html += '</div>';
        return html;
    }

    function syncTermPanelVisibility() {
        if ($('#scp-cp-has-terms-toggle').is(':checked')) {
            $('#scp-cp-single-price-panel').hide();
            $('#scp-cp-multi-terms-panel').show();
            if ($('#scp-cp-terms-rows-container .scp-cp-term-row-card').length === 0) {
                $('#scp-cp-terms-rows-container').append(buildTermRowHtml({
                    term_name: '12 Months',
                    price_amount: $('#scp-cp-price-amount').val() || '9.99',
                    price_unit: '/mo',
                    term_length_label: 'For 12 month term',
                    renews_at_label: '',
                    save_badge_text: '',
                    stripe_hook: $('#scp-cp-stripe-hook').val() || '',
                    is_default: 1
                }));
            }
        } else {
            $('#scp-cp-multi-terms-panel').hide();
            $('#scp-cp-single-price-panel').show();
        }
    }

    $(document).on('change', '#scp-cp-has-terms-toggle', syncTermPanelVisibility);

    $(document).on('change', '#scp-cp-use-feature-headings', function () {
        if ($(this).is(':checked')) {
            $('.scp-cp-feature-group-header').slideDown(120);
        } else {
            $('.scp-cp-feature-group-header').slideUp(120);
        }
    });

    $(document).on('click', '#scp-cp-add-term-row-btn', function () {
        var isFirst = $('#scp-cp-terms-rows-container .scp-cp-term-row-card').length === 0;
        $('#scp-cp-terms-rows-container').append(buildTermRowHtml({
            term_name: '',
            price_amount: '0.00',
            price_unit: '/mo',
            is_default: isFirst ? 1 : 0
        }));
    });

    $(document).on('click', '.scp-cp-preset-term-btn', function () {
        var tName = $(this).data('term');
        var tUnit = $(this).data('unit') || '/mo';
        var tLen = $(this).data('len') || '';
        var isFirst = $('#scp-cp-terms-rows-container .scp-cp-term-row-card').length === 0;
        $('#scp-cp-terms-rows-container').append(buildTermRowHtml({
            term_name: tName,
            price_amount: '0.00',
            price_unit: tUnit,
            term_length_label: tLen,
            is_default: isFirst ? 1 : 0
        }));
    });

    $(document).on('click', '.scp-cp-remove-term-row-btn', function () {
        $(this).closest('.scp-cp-term-row-card').remove();
        if ($('#scp-cp-terms-rows-container .scp-cp-term-default-radio:checked').length === 0) {
            $('#scp-cp-terms-rows-container .scp-cp-term-default-radio').first().prop('checked', true);
        }
    });

    $(document).on('click', '#scp-cp-add-feature-group-btn', function () {
        $('#scp-cp-use-feature-headings').prop('checked', true);
        $('.scp-cp-feature-group-header').show();
        $('#scp-cp-features-groups-container').append(buildFeatureGroupHtml('', [
            { text: '', is_bold: 0, icon: 'check' }
        ]));
    });

    $(document).on('click', '#scp-cp-add-flat-feature-btn', function () {
        var $lastGroup = $('#scp-cp-features-groups-container .scp-cp-feature-group-box').last();
        if ($lastGroup.length === 0) {
            $('#scp-cp-features-groups-container').append(buildFeatureGroupHtml('', [
                { text: '', is_bold: 0, icon: 'check' }
            ]));
        } else {
            $lastGroup.find('.scp-cp-feature-items-list').append(buildFeatureItemRowHtml({ text: '', is_bold: 0, icon: 'check' }));
        }
    });

    $(document).on('click', '.scp-cp-add-feature-in-group-btn', function () {
        var $group = $(this).closest('.scp-cp-feature-group-box');
        $group.find('.scp-cp-feature-items-list').append(buildFeatureItemRowHtml({ text: '', is_bold: 0, icon: 'check' }));
    });

    $(document).on('click', '.scp-cp-remove-feature-group-btn', function () {
        $(this).closest('.scp-cp-feature-group-box').remove();
    });

    $(document).on('click', '.scp-cp-fg-up-btn', function () {
        var $group = $(this).closest('.scp-cp-feature-group-box');
        var $prev = $group.prev('.scp-cp-feature-group-box');
        if ($prev.length) {
            $group.insertBefore($prev);
        }
    });

    $(document).on('click', '.scp-cp-fg-down-btn', function () {
        var $group = $(this).closest('.scp-cp-feature-group-box');
        var $next = $group.next('.scp-cp-feature-group-box');
        if ($next.length) {
            $group.insertAfter($next);
        }
    });

    $(document).on('click', '.scp-cp-feat-bold-btn', function () {
        var $btn = $(this);
        var $row = $btn.closest('.scp-cp-feature-item-row');
        var nextBold = String($row.attr('data-bold')) !== '1';
        $row.attr('data-bold', nextBold ? '1' : '0');
        $btn.toggleClass('active', nextBold);
        $row.find('.scp-cp-f-text').css('font-weight', nextBold ? '700' : '400');
    });

    $(document).on('click', '.scp-cp-feat-italic-btn', function () {
        var $btn = $(this);
        var $row = $btn.closest('.scp-cp-feature-item-row');
        var nextItalic = String($row.attr('data-italic')) !== '1';
        $row.attr('data-italic', nextItalic ? '1' : '0');
        $btn.toggleClass('active', nextItalic);
        $row.find('.scp-cp-f-text').css('font-style', nextItalic ? 'italic' : 'normal');
    });

    $(document).on('click', '.scp-cp-feat-new-btn', function () {
        var $btn = $(this);
        var $row = $btn.closest('.scp-cp-feature-item-row');
        var nextNew = String($row.attr('data-new')) !== '1';
        $row.attr('data-new', nextNew ? '1' : '0');
        $btn.toggleClass('active', nextNew);
    });

    $(document).on('click', '.scp-cp-feat-hint-btn', function () {
        var $btn = $(this);
        var $row = $btn.closest('.scp-cp-feature-item-row');
        var $wrap = $row.find('.scp-cp-f-hint-wrap');
        var $input = $wrap.find('.scp-cp-f-hint');
        if ($wrap.is(':visible')) {
            if ($.trim($input.val()) === '') {
                $wrap.slideUp(120);
                $btn.removeClass('active');
            } else {
                $wrap.slideUp(120);
            }
        } else {
            $wrap.slideDown(120, function () {
                $input.focus();
            });
            $btn.addClass('active');
        }
    });

    $(document).on('input', '.scp-cp-f-hint', function () {
        var $input = $(this);
        var $row = $input.closest('.scp-cp-feature-item-row');
        var hasVal = $.trim($input.val()) !== '';
        $row.find('.scp-cp-feat-hint-btn').toggleClass('active', hasVal || $row.find('.scp-cp-f-hint-wrap').is(':visible'));
    });

    $(document).on('change', '.scp-cp-f-hint-icon', function () {
        var $select = $(this);
        var $wrap = $select.closest('.scp-cp-f-hint-wrap');
        $wrap.find('.scp-cp-f-hint-icon-preview').html(getFeatureHintSvg($select.val()));
    });

    $(document).on('click', '.scp-cp-feat-up-btn', function () {
        var $row = $(this).closest('.scp-cp-feature-item-row');
        var $prev = $row.prev('.scp-cp-feature-item-row');
        if ($prev.length) {
            $row.insertBefore($prev);
        }
    });

    $(document).on('click', '.scp-cp-feat-down-btn', function () {
        var $row = $(this).closest('.scp-cp-feature-item-row');
        var $next = $row.next('.scp-cp-feature-item-row');
        if ($next.length) {
            $row.insertAfter($next);
        }
    });

    $(document).on('click', '.scp-cp-feat-del-btn', function () {
        $(this).closest('.scp-cp-feature-item-row').remove();
    });

    // Open Create / Edit Product Builder Modal
    $(document).on('click', '.scp-open-catalog-product-modal', function () {
        var rawProd = $(this).attr('data-product');
        var prod = null;
        if (rawProd && rawProd.trim() !== '') {
            try {
                prod = JSON.parse(rawProd);
            } catch (e) {
                prod = null;
            }
        }

        $('#scp-cp-terms-rows-container').empty();
        $('#scp-cp-features-groups-container').empty();
        $('.scp-cp-cat-cb').prop('checked', false);

        if (prod && prod.id) {
            $('#scp-cp-modal-title').text('Edit Product: ' + (prod.name || ''));
            $('#scp-cp-id').val(prod.id);
            $('#scp-cp-name').val(prod.name || '');
            $('#scp-cp-super-title').val(prod.super_title || '');
            $('#scp-cp-cta-label').val(prod.cta_label || 'Choose Plan');
            $('#scp-cp-featured').prop('checked', parseInt(prod.is_featured || 0, 10) === 1);
            $('#scp-cp-visible').prop('checked', parseInt(prod.portal_visible !== undefined ? prod.portal_visible : 1, 10) === 1);
            $('#scp-cp-description').val(prod.description || '');
            $('#scp-cp-promo-callout').val(prod.promo_callout || '');

            var catIds = prod.category_ids || [];
            for (var c = 0; c < catIds.length; c++) {
                $('.scp-cp-cat-cb[value="' + catIds[c] + '"]').prop('checked', true);
            }

            var hasTerms = parseInt(prod.has_terms || 0, 10) === 1 && prod.terms && prod.terms.length > 0;
            $('#scp-cp-has-terms-toggle').prop('checked', hasTerms);
            $('#scp-cp-billing-mode').val(prod.billing_mode || 'recurring');
            $('#scp-cp-price-amount').val(parseFloat(prod.price_amount || 0).toFixed(2));
            $('#scp-cp-price-unit').val(prod.price_unit !== undefined ? prod.price_unit : '/mo');
            $('#scp-cp-save-badge').val(prod.save_badge_text || '');
            $('#scp-cp-term-length').val(prod.term_length_label || '');
            $('#scp-cp-renews-at').val(prod.renews_at_label || '');
            $('#scp-cp-stripe-hook').val(prod.stripe_hook || '');

            if (prod.terms && prod.terms.length > 0) {
                for (var tIdx = 0; tIdx < prod.terms.length; tIdx++) {
                    $('#scp-cp-terms-rows-container').append(buildTermRowHtml(prod.terms[tIdx]));
                }
            }
            syncTermPanelVisibility();

            var useHeadings = parseInt(prod.use_feature_headings !== undefined ? prod.use_feature_headings : 1, 10) === 1;
            $('#scp-cp-use-feature-headings').prop('checked', useHeadings);

            var feats = prod.features || [];
            if (feats.length > 0) {
                // Group consecutive features by heading
                var groups = [];
                var currGroup = null;
                for (var fIdx = 0; fIdx < feats.length; fIdx++) {
                    var fItem = feats[fIdx];
                    var hName = String(fItem.heading || '');
                    if (!currGroup || currGroup.heading !== hName) {
                        currGroup = { heading: hName, items: [] };
                        groups.push(currGroup);
                    }
                    currGroup.items.push(fItem);
                }
                for (var gIdx = 0; gIdx < groups.length; gIdx++) {
                    $('#scp-cp-features-groups-container').append(buildFeatureGroupHtml(groups[gIdx].heading, groups[gIdx].items));
                }
            } else {
                $('#scp-cp-features-groups-container').append(buildFeatureGroupHtml('PROTECTION & SUPPORT', [
                    { text: '', is_bold: 0, icon: 'check' }
                ]));
            }
        } else {
            $('#scp-cp-modal-title').text('Create New Product');
            $('#scp-cp-id').val('0');
            $('#scp-cp-name').val('');
            $('#scp-cp-super-title').val('');
            $('#scp-cp-cta-label').val('Choose Plan');
            $('#scp-cp-featured').prop('checked', false);
            $('#scp-cp-visible').prop('checked', true);
            $('#scp-cp-description').val('');
            $('#scp-cp-promo-callout').val('');
            $('#scp-cp-has-terms-toggle').prop('checked', false);
            $('#scp-cp-billing-mode').val('recurring');
            $('#scp-cp-price-amount').val('0.00');
            $('#scp-cp-price-unit').val('/mo');
            $('#scp-cp-save-badge').val('');
            $('#scp-cp-term-length').val('');
            $('#scp-cp-renews-at').val('');
            $('#scp-cp-stripe-hook').val('');
            syncTermPanelVisibility();

            $('#scp-cp-use-feature-headings').prop('checked', true);
            $('#scp-cp-features-groups-container').append(buildFeatureGroupHtml('PROTECTION & SUPPORT', [
                { text: '', is_bold: 0, icon: 'check' },
                { text: '', is_bold: 0, icon: 'check' }
            ]));
        }

        $('#scp-catalog-product-modal').fadeIn(150);
    });

    function collectCurrentCatalogModalPayload() {
        var catIds = [];
        $('.scp-cp-cat-cb:checked').each(function () {
            catIds.push(parseInt($(this).val(), 10));
        });

        var hasTerms = $('#scp-cp-has-terms-toggle').is(':checked') ? 1 : 0;
        var termsArr = [];
        $('#scp-cp-terms-rows-container .scp-cp-term-row-card').each(function () {
            var $card = $(this);
            var tName = ($card.find('.scp-cp-t-name').val() || '').trim();
            if (!tName) return;
            termsArr.push({
                term_name: tName,
                price_amount: $card.find('.scp-cp-t-price').val() || '0.00',
                price_unit: $card.find('.scp-cp-t-unit').val() || '/mo',
                save_badge_text: ($card.find('.scp-cp-t-save').val() || '').trim(),
                term_length_label: ($card.find('.scp-cp-t-len').val() || '').trim(),
                renews_at_label: ($card.find('.scp-cp-t-renews').val() || '').trim(),
                billing_mode: $card.find('.scp-cp-t-mode').val() || 'recurring',
                stripe_hook: ($card.find('.scp-cp-t-hook').val() || '').trim(),
                is_default: $card.find('.scp-cp-term-default-radio').is(':checked') ? 1 : 0
            });
        });

        var useFeatureHeadings = $('#scp-cp-use-feature-headings').is(':checked') ? 1 : 0;
        var featuresArr = [];
        $('#scp-cp-features-groups-container .scp-cp-feature-group-box').each(function () {
            var $group = $(this);
            var headingText = ($group.find('.scp-cp-fg-heading-input').val() || '').trim();
            $group.find('.scp-cp-feature-item-row').each(function () {
                var $fRow = $(this);
                var fText = ($fRow.find('.scp-cp-f-text').val() || '').trim();
                if (!fText) return;
                featuresArr.push({
                    heading: headingText,
                    text: fText,
                    is_bold: String($fRow.attr('data-bold')) === '1' ? 1 : 0,
                    is_italic: String($fRow.attr('data-italic')) === '1' ? 1 : 0,
                    is_new: String($fRow.attr('data-new')) === '1' ? 1 : 0,
                    hint: ($fRow.find('.scp-cp-f-hint').val() || '').trim(),
                    hint_icon: $fRow.find('.scp-cp-f-hint-icon').val() || 'black-info',
                    icon: $fRow.find('.scp-cp-f-icon').val() || 'check'
                });
            });
        });

        return {
            product_id: $('#scp-cp-id').val(),
            name: $('#scp-cp-name').val(),
            super_title: $('#scp-cp-super-title').val(),
            cta_label: $('#scp-cp-cta-label').val(),
            is_featured: $('#scp-cp-featured').is(':checked') ? 1 : 0,
            portal_visible: $('#scp-cp-visible').is(':checked') ? 1 : 0,
            description: $('#scp-cp-description').val(),
            promo_callout: $('#scp-cp-promo-callout').val(),
            has_terms: hasTerms,
            billing_mode: $('#scp-cp-billing-mode').val(),
            price_amount: $('#scp-cp-price-amount').val(),
            price_unit: $('#scp-cp-price-unit').val(),
            save_badge_text: $('#scp-cp-save-badge').val(),
            term_length_label: $('#scp-cp-term-length').val(),
            renews_at_label: $('#scp-cp-renews-at').val(),
            stripe_hook: $('#scp-cp-stripe-hook').val(),
            terms_json: JSON.stringify(termsArr),
            use_feature_headings: useFeatureHeadings,
            features_json: JSON.stringify(featuresArr),
            category_ids: JSON.stringify(catIds)
        };
    }

    // Split / Move Term to New Product (from inside the Edit Product Modal)
    $(document).on('click', '.scp-cp-split-term-btn', function () {
        var $btn = $(this);
        var $termCard = $btn.closest('.scp-cp-term-row-card');
        var $allTermCards = $('#scp-cp-terms-rows-container .scp-cp-term-row-card');
        if ($allTermCards.length <= 1) {
            showToast('This product only has one price/term remaining, so there is no extra price-point to move.', true);
            return;
        }
        var termIndex = $allTermCards.index($termCard);
        if (termIndex < 0) return;

        var origHtml = $btn.html();
        $btn.prop('disabled', true).text('Moving to New Product...');

        var payload = collectCurrentCatalogModalPayload();
        payload.term_index = termIndex;

        apiPost('admin_split_term_to_product', payload, function (data) {
            showToast(data.message || 'Copied product to a new listing and moved price/term!');
            window.scpCatalogModalNeedsReload = true;
            if (data.source_product_id) {
                $('#scp-cp-id').val(data.source_product_id);
            }

            $termCard.remove();
            var $remaining = $('#scp-cp-terms-rows-container .scp-cp-term-row-card');
            if ($remaining.find('.scp-cp-term-default-radio:checked').length === 0) {
                $remaining.find('.scp-cp-term-default-radio').first().prop('checked', true);
            }

            // If only 1 term remains on the source product, sync its values into the Single Price fields
            if ($remaining.length === 1) {
                var $last = $remaining.first();
                $('#scp-cp-billing-mode').val($last.find('.scp-cp-t-mode').val() || 'recurring');
                $('#scp-cp-price-amount').val($last.find('.scp-cp-t-price').val() || '0.00');
                $('#scp-cp-price-unit').val($last.find('.scp-cp-t-unit').val() || '/mo');
                $('#scp-cp-save-badge').val($last.find('.scp-cp-t-save').val() || '');
                $('#scp-cp-term-length').val($last.find('.scp-cp-t-len').val() || '');
                $('#scp-cp-renews-at').val($last.find('.scp-cp-t-renews').val() || '');
                $('#scp-cp-stripe-hook').val($last.find('.scp-cp-t-hook').val() || '');
                $('#scp-cp-has-terms-toggle').prop('checked', false);
                syncTermPanelVisibility();
            }
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html(origHtml);
        });
    });

    // Split / Move Term to New Product (directly from the Admin Products Table Row)
    $(document).on('click', '.scp-table-split-term-btn', function () {
        var $btn = $(this);
        var prodId = $btn.data('product-id');
        var termIdx = $btn.data('term-index');
        var origHtml = $btn.html();
        $btn.prop('disabled', true).text('Moving...');

        apiPost('admin_split_term_to_product', {
            product_id: prodId,
            term_index: termIdx
        }, function (data) {
            showToast(data.message || 'Copied product to a new listing and moved price/term!');
            setTimeout(function () { reloadPreservingTab('admin-products'); }, 750);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html(origHtml);
        });
    });

    // Save Custom Catalog Product
    $(document).on('submit', '#scp-catalog-product-form', function (e) {
        e.preventDefault();
        var $btn = $('#scp-cp-save-submit-btn');
        var origText = $btn.text();
        $btn.prop('disabled', true).text('Saving Product...');

        var payload = collectCurrentCatalogModalPayload();

        apiPost('admin_save_catalog_product', payload, function (data) {
            window.scpCatalogModalNeedsReload = false;
            showToast(data.message || 'Product saved to catalog!');
            $('#scp-catalog-product-modal').fadeOut(150);
            setTimeout(function () { reloadPreservingTab('admin-products'); }, 750);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text(origText);
        });
    });

    // Delete Custom Catalog Product
    $(document).on('click', '.scp-delete-catalog-product-btn', function () {
        var prodId = $(this).data('product-id');
        var prodName = $(this).data('product-name');
        if (!window.confirm('Delete product "' + prodName + '" from your catalog?')) {
            return;
        }
        apiPost('admin_delete_catalog_product', { product_id: prodId }, function (data) {
            showToast(data.message || 'Product deleted.');
            setTimeout(function () { reloadPreservingTab('admin-products'); }, 750);
        }, function (err) {
            showToast(err, true);
        });
    });

    // Import Unlisted Stripe Products into Custom Catalog
    $(document).on('click', '.scp-import-stripe-to-catalog-btn', function () {
        var $btn = $(this);
        var origHtml = $btn.html();
        $btn.prop('disabled', true).text('Importing...');
        apiPost('admin_import_stripe_to_catalog', {}, function (data) {
            showToast(data.message || 'Stripe products imported into catalog!');
            setTimeout(function () { reloadPreservingTab('admin-products'); }, 850);
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html(origHtml);
        });
    });


    // =========================================================================
    // Admin Email Center (v3.1.0) — Compose, Inbox, Read, Reply, Delete
    // =========================================================================
    var scpMail = {
        folder: 'inbox',
        search: '',
        currentId: 0,
        loadedOnce: false,
        autoTimer: null,
        searchTimer: null
    };

    function mailUpdateCounts(counts) {
        if (!counts) return;
        $('.scp-mail-count').each(function () {
            var key = $(this).data('count');
            var n = parseInt(counts[key], 10) || 0;
            $(this).text(n > 0 ? n : '');
        });
        var unread = parseInt(counts.inbox_unread, 10) || 0;
        var $badge = $('#scp-email-nav-unread-badge');
        if (unread > 0) { $badge.text(unread).show(); } else { $badge.hide(); }
    }

    function mailSyncTrashUi() {
        var inTrash = scpMail.folder === 'trash';
        $('.scp-mail-show-in-trash').toggle(inTrash);
        $('.scp-mail-hide-in-trash').toggle(!inTrash);
    }

    function mailRenderList(messages) {
        var $list = $('#scp-mail-list');
        $('#scp-mail-select-all').prop('checked', false);
        if (!messages || !messages.length) {
            var emptyMsg = scpMail.search ? 'No emails match your search.' : 'No emails in this folder.';
            $list.html('<div class="scp-empty-state" style="padding:30px;"><p>' + escapeHtml(emptyMsg) + '</p></div>');
            return;
        }
        var html = '';
        $.each(messages, function (_, m) {
            var outbound = m.direction === 'outbound';
            var who = outbound
                ? 'To: ' + (m.to_name || m.to_email)
                : (m.from_name || m.from_email);
            var classes = 'scp-mail-row' + (m.is_read ? '' : ' unread') + (m.id === scpMail.currentId ? ' active' : '');
            html += '<div class="' + classes + '" data-id="' + m.id + '">' +
                '<label class="scp-mail-row-check" onclick="event.stopPropagation();"><input type="checkbox" class="scp-mail-cb" value="' + m.id + '" /></label>' +
                '<button type="button" class="scp-mail-star' + (m.is_starred ? ' on' : '') + '" data-id="' + m.id + '" title="' + (m.is_starred ? 'Unstar' : 'Star') + '">' + (m.is_starred ? '&#9733;' : '&#9734;') + '</button>' +
                '<div class="scp-mail-row-main">' +
                    '<div class="scp-mail-row-top">' +
                        '<span class="scp-mail-row-from">' + escapeHtml(who) + '</span>' +
                        (m.user_id ? '<span class="scp-mail-client-tag" title="Matched to a portal client">Client</span>' : '') +
                        (m.replied_at ? '<span class="scp-mail-replied-tag" title="You replied to this email">&#8617;</span>' : '') +
                        '<span class="scp-mail-row-date">' + escapeHtml(m.date_short || '') + '</span>' +
                    '</div>' +
                    '<div class="scp-mail-row-subject">' + escapeHtml(m.subject || '(No Subject)') + '</div>' +
                    '<div class="scp-mail-row-snippet">' + escapeHtml(m.snippet || '') + '</div>' +
                '</div>' +
            '</div>';
        });
        $list.html(html);
    }

    function mailLoadList(silent) {
        if (!$('#scp-mail-list').length) return;
        if (!silent) {
            $('#scp-mail-list').html('<div class="scp-empty-state" style="padding:30px;"><p>Loading...</p></div>');
        }
        apiPost('admin_email_list', { folder: scpMail.folder, search: scpMail.search }, function (data) {
            mailRenderList(data.messages || []);
            mailUpdateCounts(data.counts);
        }, function (err) {
            $('#scp-mail-list').html('<div class="scp-empty-state" style="padding:30px;"><p>' + escapeHtml(err) + '</p></div>');
        });
    }

    function mailSync(showResult) {
        var $btn = $('#scp-email-sync-btn');
        var orig = $btn.html();
        $btn.prop('disabled', true).html('&#8635; Checking...');
        apiPost('admin_email_sync', {}, function (data) {
            $btn.prop('disabled', false).html(orig);
            mailUpdateCounts(data.counts);
            if (data.synced_at) { $('#scp-email-last-sync').html('&bull; Last synced ' + escapeHtml(data.synced_at)); }
            if (showResult) showToast(data.message || 'Mailbox synced.');
            mailLoadList(true);
        }, function (err) {
            $btn.prop('disabled', false).html(orig);
            if (showResult) showToast(err, true);
            mailLoadList(true);
        });
    }

    function mailBuildIframeDoc(bodyHtml) {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank">' +
            '<style>body{margin:0;padding:16px;font-family:Inter,Arial,sans-serif;font-size:14px;line-height:1.55;color:#1e293b;word-wrap:break-word;}img{max-width:100%;height:auto;}</style>' +
            '</head><body>' + (bodyHtml || '') + '</body></html>';
    }

    function mailRenderReader(data) {
        var m = data.message;
        var outbound = m.direction === 'outbound';
        var inTrash = m.folder === 'trash';
        var fromLine = (m.from_name ? escapeHtml(m.from_name) + ' &lt;' + escapeHtml(m.from_email) + '&gt;' : escapeHtml(m.from_email));
        var toLine = (m.to_name ? escapeHtml(m.to_name) + ' &lt;' + escapeHtml(m.to_email) + '&gt;' : escapeHtml(m.to_email));

        var actions = '';
        if (!inTrash) {
            actions += '<button type="button" class="scp-btn scp-btn-xs scp-btn-primary scp-mail-reply-btn">&#8617; Reply</button>';
            actions += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-mail-forward-btn">&#10150; Forward</button>';
            actions += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-mail-single-action" data-act="' + (m.is_starred ? 'unstar' : 'star') + '">' + (m.is_starred ? '&#9733; Unstar' : '&#9734; Star') + '</button>';
            actions += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-mail-single-action" data-act="mark_unread">&#9679; Mark Unread</button>';
            actions += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-mail-single-action" data-act="trash">&#128465; Delete</button>';
        } else {
            actions += '<button type="button" class="scp-btn scp-btn-xs scp-btn-outline scp-mail-single-action" data-act="restore">&#8634; Restore</button>';
            actions += '<button type="button" class="scp-btn scp-btn-xs scp-btn-danger scp-mail-single-action" data-act="delete_permanent">Delete Forever</button>';
        }

        var clientInfo = '';
        if (m.user_id) {
            clientInfo = '<a href="#" class="scp-mail-client-tag scp-view-client-billing-btn" data-user-id="' + m.user_id + '" title="View client billing">' +
                '&#128100; ' + escapeHtml(m.client_display_name || 'Portal Client') + '</a>';
        }

        var threadHtml = '';
        if (data.thread && data.thread.length) {
            threadHtml = '<div class="scp-mail-thread"><div class="scp-mail-thread-title">Conversation History (' + data.thread.length + ')</div>';
            $.each(data.thread, function (_, t) {
                var tWho = t.direction === 'outbound' ? 'You &rarr; ' + escapeHtml(t.to_name || t.to_email) : escapeHtml(t.from_name || t.from_email);
                threadHtml += '<details class="scp-mail-thread-item">' +
                    '<summary><strong>' + tWho + '</strong> <span>' + escapeHtml(t.date_formatted || '') + '</span><div>' + escapeHtml(t.subject || '') + '</div></summary>' +
                    '<div class="scp-mail-thread-body">' + escapeHtml(t.body_text || '') + '</div>' +
                '</details>';
            });
            threadHtml += '</div>';
        }

        var html =
            '<div class="scp-mail-reader-head">' +
                '<h3 class="scp-mail-reader-subject">' + escapeHtml(m.subject || '(No Subject)') + '</h3>' +
                '<div class="scp-mail-reader-actions">' + actions + '</div>' +
                '<div class="scp-mail-reader-meta">' +
                    '<div><span>From:</span> ' + fromLine + '</div>' +
                    '<div><span>To:</span> ' + toLine + '</div>' +
                    '<div><span>Date:</span> ' + escapeHtml(m.date_formatted || '') + (outbound ? ' &bull; <em>Sent from portal</em>' : '') + '</div>' +
                    (clientInfo ? '<div style="margin-top:6px;">' + clientInfo + '</div>' : '') +
                '</div>' +
            '</div>' +
            '<iframe class="scp-mail-body-frame" title="Email message" sandbox="allow-popups allow-popups-to-escape-sandbox"></iframe>' +
            threadHtml;

        var $reader = $('#scp-mail-reader');
        $reader.html(html).data('message', m);
        $reader.find('.scp-mail-body-frame').attr('srcdoc', mailBuildIframeDoc(m.body_html || ('<pre style="white-space:pre-wrap;font-family:inherit;">' + escapeHtml(m.body_text || '') + '</pre>')));
    }

    function mailOpen(id) {
        scpMail.currentId = id;
        $('.scp-mail-row').removeClass('active');
        $('.scp-mail-row[data-id="' + id + '"]').addClass('active').removeClass('unread');
        $('#scp-mail-reader').html('<div class="scp-mail-reader-empty"><p>Loading email...</p></div>');
        $('.scp-mail-layout').addClass('reading');
        apiPost('admin_email_get', { email_id: id }, function (data) {
            mailRenderReader(data);
            mailUpdateCounts(data.counts);
        }, function (err) {
            $('#scp-mail-reader').html('<div class="scp-mail-reader-empty"><p>' + escapeHtml(err) + '</p></div>');
        });
    }

    function mailRunAction(ids, act, onDone) {
        if (act === 'delete_permanent' && !window.confirm('Permanently delete ' + (ids.length === 1 ? 'this email' : ids.length + ' emails') + '? This cannot be undone.')) {
            return;
        }
        apiPost('admin_email_action', { ids: ids, email_action: act }, function (data) {
            showToast(data.message || 'Done.');
            mailUpdateCounts(data.counts);
            if (onDone) onDone(data);
        });
    }

    function mailOpenCompose(opts) {
        opts = opts || {};
        var $modal = $('#scp-compose-email-modal');
        if (!$modal.length) return;
        var $f = $('#scp-compose-email-form');
        $f[0].reset();
        $f.find('[name="user_id"]').val(opts.userId || 0);
        $f.find('[name="reply_to_id"]').val(opts.replyToId || 0);
        $f.find('[name="inquiry_id"]').val(opts.inquiryId || 0);
        $f.find('[name="to_email"]').val(opts.toEmail || '');
        $f.find('[name="attention_name"]').val(opts.attention || '');
        $f.find('[name="subject"]').val(opts.subject || '');
        $f.find('[name="message"]').val(opts.message || '');
        $('#scp-compose-quote-wrap').css('display', opts.replyToId ? 'flex' : 'none');
        $('#scp-compose-preview-wrap').hide();
        $('#scp-compose-preview-btn').html('&#128065; Preview Email');
        $('#scp-compose-title').text(opts.title || 'New Email');
        $('#scp-compose-send-btn').prop('disabled', false).html('&#10148; Send Email');
        $modal.fadeIn(150);
        setTimeout(function () {
            var $focus = !opts.toEmail ? $f.find('[name="to_email"]') : (!opts.subject ? $f.find('[name="subject"]') : $f.find('[name="message"]'));
            $focus.trigger('focus');
        }, 180);
    }

    // Open compose from client emails, inquiries, Compose button
    $(document).on('click', '.scp-open-compose-email', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var $el = $(this);
        mailOpenCompose({
            toEmail: $el.data('to-email') || '',
            userId: parseInt($el.data('user-id'), 10) || 0,
            attention: $el.data('attention') || '',
            inquiryId: parseInt($el.data('inquiry-id'), 10) || 0,
            subject: $el.data('subject') || '',
            title: $el.data('to-email') ? 'Email ' + ($el.data('attention') || $el.data('to-email')) : 'New Email'
        });
    });

    // Auto-fill client name & ID when choosing an email from the datalist
    $(document).on('change', '#scp-compose-email-form [name="to_email"]', function () {
        var val = $.trim($(this).val()).toLowerCase();
        var $f = $('#scp-compose-email-form');
        var matched = false;
        $('#scp-compose-client-datalist option').each(function () {
            if (String($(this).val()).toLowerCase() === val) {
                $f.find('[name="user_id"]').val($(this).data('user-id') || 0);
                if (!$.trim($f.find('[name="attention_name"]').val())) {
                    $f.find('[name="attention_name"]').val($(this).data('name') || '');
                }
                matched = true;
                return false;
            }
        });
        if (!matched && !parseInt($f.find('[name="reply_to_id"]').val(), 10)) {
            $f.find('[name="user_id"]').val(0);
        }
    });

    // Simple formatting helpers (wrap selection in the message textarea)
    $(document).on('click', '.scp-compose-format-bar [data-wrap], .scp-compose-format-bar [data-insert-link]', function () {
        var ta = $('#scp-compose-email-form [name="message"]')[0];
        if (!ta) return;
        var start = ta.selectionStart, end = ta.selectionEnd;
        var sel = ta.value.substring(start, end);
        var before = ta.value.substring(0, start), after = ta.value.substring(end);
        var insert;
        if ($(this).data('insert-link')) {
            var url = window.prompt('Enter the link URL (https://...)', 'https://');
            if (!url || !/^https?:\/\//i.test(url)) return;
            insert = '<a href="' + url.replace(/"/g, '%22') + '">' + (sel || url) + '</a>';
        } else {
            var tag = $(this).data('wrap');
            insert = '<' + tag + '>' + (sel || 'text') + '</' + tag + '>';
        }
        ta.value = before + insert + after;
        ta.focus();
        ta.selectionStart = start;
        ta.selectionEnd = start + insert.length;
    });

    // Preview the branded template
    $(document).on('click', '#scp-compose-preview-btn', function () {
        var $wrap = $('#scp-compose-preview-wrap');
        var $btn = $(this);
        if ($wrap.is(':visible')) {
            $wrap.slideUp(150);
            $btn.html('&#128065; Preview Email');
            return;
        }
        var $f = $('#scp-compose-email-form');
        $btn.prop('disabled', true).text('Rendering...');
        apiPost('admin_email_preview', {
            to_email: $f.find('[name="to_email"]').val(),
            user_id: $f.find('[name="user_id"]').val(),
            attention_name: $f.find('[name="attention_name"]').val(),
            subject: $f.find('[name="subject"]').val(),
            message: $f.find('[name="message"]').val()
        }, function (data) {
            $('#scp-compose-preview-frame').attr('srcdoc', data.html || '');
            $wrap.slideDown(150);
            $btn.prop('disabled', false).html('&#10005; Hide Preview');
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html('&#128065; Preview Email');
        });
    });

    // Send email
    $(document).on('submit', '#scp-compose-email-form', function (e) {
        e.preventDefault();
        var $f = $(this);
        var $btn = $('#scp-compose-send-btn');
        $btn.prop('disabled', true).text('Sending...');
        apiPost('admin_email_send', {
            to_email: $f.find('[name="to_email"]').val(),
            user_id: $f.find('[name="user_id"]').val(),
            attention_name: $f.find('[name="attention_name"]').val(),
            subject: $f.find('[name="subject"]').val(),
            message: $f.find('[name="message"]').val(),
            reply_to_id: $f.find('[name="reply_to_id"]').val(),
            inquiry_id: $f.find('[name="inquiry_id"]').val(),
            include_quoted: $f.find('[name="include_quoted"]').is(':checked') ? 1 : 0
        }, function (data) {
            showToast(data.message || 'Email sent!');
            $('#scp-compose-email-modal').fadeOut(150);
            mailUpdateCounts(data.counts);
            if ($('#scp-tab-admin-email').hasClass('active')) {
                mailLoadList(true);
                if (scpMail.currentId) mailOpen(scpMail.currentId);
            }
            if (parseInt($f.find('[name="inquiry_id"]').val(), 10) > 0) {
                setTimeout(function () { reloadPreservingTab('admin-inquiries'); }, 900);
            }
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).html('&#10148; Send Email');
        });
    });

    // Folder navigation
    $(document).on('click', '.scp-mail-folder', function () {
        $('.scp-mail-folder').removeClass('active');
        $(this).addClass('active');
        scpMail.folder = $(this).data('folder');
        scpMail.currentId = 0;
        $('.scp-mail-layout').removeClass('reading');
        $('#scp-mail-reader').html('<div class="scp-mail-reader-empty"><div style="font-size:42px;line-height:1;">&#9993;</div><p>Select an email to read it here.</p></div>');
        mailSyncTrashUi();
        mailLoadList();
    });

    // Search
    $(document).on('input', '#scp-mail-search', function () {
        var v = $.trim($(this).val());
        clearTimeout(scpMail.searchTimer);
        scpMail.searchTimer = setTimeout(function () {
            scpMail.search = v;
            mailLoadList();
        }, 350);
    });

    // Open message
    $(document).on('click', '.scp-mail-row', function () {
        mailOpen(parseInt($(this).data('id'), 10));
    });

    // Star toggle in list
    $(document).on('click', '.scp-mail-star', function (e) {
        e.stopPropagation();
        var $s = $(this);
        var on = $s.hasClass('on');
        mailRunAction([parseInt($s.data('id'), 10)], on ? 'unstar' : 'star', function () {
            $s.toggleClass('on', !on).html(!on ? '&#9733;' : '&#9734;');
            if (scpMail.folder === 'starred') mailLoadList(true);
        });
    });

    // Select all
    $(document).on('change', '#scp-mail-select-all', function () {
        $('.scp-mail-cb').prop('checked', $(this).is(':checked'));
    });

    // Bulk actions
    $(document).on('click', '#scp-mail-bulk-actions [data-bulk]', function () {
        var ids = $('.scp-mail-cb:checked').map(function () { return parseInt(this.value, 10); }).get();
        if (!ids.length) {
            showToast('Select one or more emails first.', true);
            return;
        }
        var act = $(this).data('bulk');
        mailRunAction(ids, act, function () {
            if (ids.indexOf(scpMail.currentId) !== -1 && (act === 'trash' || act === 'restore' || act === 'delete_permanent')) {
                scpMail.currentId = 0;
                $('#scp-mail-reader').html('<div class="scp-mail-reader-empty"><p>Select an email to read it here.</p></div>');
            }
            mailLoadList(true);
        });
    });

    // Empty trash
    $(document).on('click', '#scp-mail-empty-trash-btn', function () {
        if (!window.confirm('Permanently delete every email in Trash? This cannot be undone.')) return;
        apiPost('admin_email_action', { ids: [], email_action: 'empty_trash' }, function (data) {
            showToast(data.message || 'Trash emptied.');
            mailUpdateCounts(data.counts);
            scpMail.currentId = 0;
            $('#scp-mail-reader').html('<div class="scp-mail-reader-empty"><p>Trash is empty.</p></div>');
            mailLoadList(true);
        });
    });

    // Single-message actions in reader
    $(document).on('click', '.scp-mail-single-action', function () {
        var act = $(this).data('act');
        var id = scpMail.currentId;
        if (!id) return;
        mailRunAction([id], act, function () {
            if (act === 'trash' || act === 'restore' || act === 'delete_permanent' || act === 'mark_unread') {
                scpMail.currentId = 0;
                $('.scp-mail-layout').removeClass('reading');
                $('#scp-mail-reader').html('<div class="scp-mail-reader-empty"><p>Select an email to read it here.</p></div>');
            } else {
                mailOpen(id);
            }
            mailLoadList(true);
        });
    });

    // Reply / Forward
    $(document).on('click', '.scp-mail-reply-btn, .scp-mail-forward-btn', function () {
        var m = $('#scp-mail-reader').data('message');
        if (!m) return;
        var isForward = $(this).hasClass('scp-mail-forward-btn');
        var baseSubject = String(m.subject || '').replace(/^\s*((re|fwd?|fw)\s*:\s*)+/i, '');
        if (isForward) {
            mailOpenCompose({
                replyToId: m.id,
                subject: 'Fwd: ' + baseSubject,
                title: 'Forward Email'
            });
            return;
        }
        var outbound = m.direction === 'outbound';
        mailOpenCompose({
            toEmail: outbound ? m.to_email : m.from_email,
            attention: outbound ? (m.to_name || m.attention_name || '') : (m.from_name || ''),
            userId: m.user_id || 0,
            replyToId: m.id,
            subject: 'Re: ' + baseSubject,
            title: 'Reply to ' + (outbound ? (m.to_name || m.to_email) : (m.from_name || m.from_email))
        });
    });

    // Mobile: back to list
    $(document).on('click', '.scp-mail-back-btn', function () {
        $('.scp-mail-layout').removeClass('reading');
    });

    // Load mailbox when Email Center tab is opened, auto-check every 3 minutes while visible
    function mailActivateIfVisible() {
        if (!$('#scp-tab-admin-email').hasClass('active')) return;
        mailSyncTrashUi();
        if (!scpMail.loadedOnce) {
            scpMail.loadedOnce = true;
            mailLoadList();
            mailSync(false);
        }
        if (!scpMail.autoTimer) {
            scpMail.autoTimer = setInterval(function () {
                if ($('#scp-tab-admin-email').hasClass('active') && !document.hidden) {
                    mailSync(false);
                }
            }, 180000);
        }
    }

    $(document).on('click', '.scp-nav-item[data-tab="admin-email"]', function () {
        setTimeout(mailActivateIfVisible, 30);
    });
    $(function () { setTimeout(mailActivateIfVisible, 120); });

    $(document).on('click', '#scp-email-sync-btn', function () {
        mailSync(true);
    });

    // Email server settings form
    $(document).on('submit', '#scp-admin-email-settings-form', function (e) {
        e.preventDefault();
        var $f = $(this);
        var $btn = $f.find('button[type="submit"]');
        var data = {};
        $.each($f.serializeArray(), function (_, fld) { data[fld.name] = fld.value; });
        $btn.prop('disabled', true).text('Saving...');
        apiPost('admin_email_save_settings', data, function (res) {
            showToast(res.message || 'Saved.');
            $btn.prop('disabled', false).text('Save Email Server Settings');
            $f.find('[name="mail_password"]').val('');
        }, function (err) {
            showToast(err, true);
            $btn.prop('disabled', false).text('Save Email Server Settings');
        });
    });

})(jQuery);
