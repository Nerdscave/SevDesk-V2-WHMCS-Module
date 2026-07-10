(function () {
    'use strict';

    var root = document.querySelector('.sd-admin');

    if (!root) {
        return;
    }

    var liveRegion = root.querySelector('[data-live-region]');

    function announce(message) {
        if (!liveRegion) {
            return;
        }

        liveRegion.textContent = '';
        window.setTimeout(function () {
            liveRegion.textContent = message;
        }, 20);
    }

    function each(selector, callback, context) {
        Array.prototype.forEach.call((context || root).querySelectorAll(selector), callback);
    }

    function toNumber(value, fallback) {
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
    }

    function clamp(value, minimum, maximum) {
        return Math.min(Math.max(value, minimum), maximum);
    }

    function revealActiveNavigationTab() {
        var navigation = root.querySelector('.sd-nav');
        var activeTab = navigation ? navigation.querySelector('.sd-nav-link[aria-current="page"]') : null;

        if (!navigation || !activeTab) {
            return;
        }

        window.requestAnimationFrame(function () {
            var navigationRect = navigation.getBoundingClientRect();
            var activeRect = activeTab.getBoundingClientRect();

            if (activeRect.left >= navigationRect.left && activeRect.right <= navigationRect.right) {
                return;
            }

            navigation.scrollLeft += activeRect.left
                - navigationRect.left
                - ((navigationRect.width - activeRect.width) / 2);
        });
    }

    each('[data-dismiss-alert]', function (button) {
        button.addEventListener('click', function () {
            var alert = button.closest('.sd-alert');
            if (alert) {
                alert.remove();
                announce('Hinweis geschlossen.');
            }
        });
    });

    each('[data-history-back]', function (button) {
        button.addEventListener('click', function () {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = root.getAttribute('data-module-link') || 'addonmodules.php?module=sevdesk';
            }
        });
    });

    each('[data-toggle-password]', function (button) {
        var inputId = button.getAttribute('aria-controls');
        var input = inputId ? document.getElementById(inputId) : null;

        if (!input) {
            return;
        }

        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Ausblenden' : 'Anzeigen';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            input.focus();
        });
    });

    // Confirmation-only controls are disabled while hidden so a stale value can
    // never be submitted without the administrator seeing the acknowledgement.
    function initConditionalFields() {
        each('[data-controls]', function (control) {
            var targetId = control.getAttribute('data-controls');
            var target = document.getElementById(targetId);

            if (!target) {
                return;
            }

            function updateTarget() {
                var rule = target.getAttribute('data-visible-when') || '';
                var separator = rule.indexOf(':');
                var expectedValue = separator >= 0 ? rule.slice(separator + 1) : '';
                var visible = String(control.value) === expectedValue;
                target.hidden = !visible;

                each('input, select, textarea, button', function (field) {
                    field.disabled = !visible;
                    if (!visible && field.type === 'checkbox') {
                        field.checked = false;
                    }
                }, target);
            }

            control.addEventListener('change', updateTarget);
            updateTarget();
        });
    }

    function initSelections() {
        var checkboxes = Array.prototype.slice.call(root.querySelectorAll('[data-export-checkbox]'));
        var selectAllButtons = root.querySelectorAll('[data-select-all]');
        var selectNoneButtons = root.querySelectorAll('[data-select-none]');
        var countLabels = root.querySelectorAll('[data-selection-count]');
        var submitButtons = root.querySelectorAll('[data-requires-selection]');

        if (!checkboxes.length) {
            return;
        }

        function updateSelection() {
            var selectedCount = checkboxes.filter(function (checkbox) {
                return checkbox.checked && !checkbox.disabled;
            }).length;
            var label = selectedCount === 1 ? '1 ausgewählt' : selectedCount + ' ausgewählt';

            Array.prototype.forEach.call(countLabels, function (element) {
                element.textContent = label;
            });
            Array.prototype.forEach.call(submitButtons, function (button) {
                button.disabled = selectedCount === 0;
                button.setAttribute('aria-disabled', selectedCount === 0 ? 'true' : 'false');
            });
        }

        checkboxes.forEach(function (checkbox) {
            checkbox.addEventListener('change', updateSelection);
        });

        Array.prototype.forEach.call(selectAllButtons, function (button) {
            button.addEventListener('click', function () {
                checkboxes.forEach(function (checkbox) {
                    if (!checkbox.disabled) {
                        checkbox.checked = true;
                    }
                });
                updateSelection();
                announce('Alle zulässigen Einträge wurden ausgewählt.');
            });
        });

        Array.prototype.forEach.call(selectNoneButtons, function (button) {
            button.addEventListener('click', function () {
                checkboxes.forEach(function (checkbox) {
                    checkbox.checked = false;
                });
                updateSelection();
                announce('Auswahl wurde geleert.');
            });
        });

        updateSelection();
    }

    function initCorrectionForm() {
        var transactionSelect = root.querySelector('[data-refund-transaction]');
        var invoiceInput = root.querySelector('[data-correction-invoice]');

        if (!transactionSelect || !invoiceInput) {
            return;
        }

        function copyInvoiceId(shouldAnnounce) {
            var option = transactionSelect.options[transactionSelect.selectedIndex];
            var invoiceId = option ? option.getAttribute('data-invoice-id') : '';

            if (!invoiceId) {
                return;
            }

            invoiceInput.value = invoiceId;
            if (shouldAnnounce) {
                announce('Die zugehörige WHMCS-Rechnungs-ID ' + invoiceId + ' wurde übernommen.');
            }
        }

        transactionSelect.addEventListener('change', function () {
            copyInvoiceId(true);
        });

        if (!invoiceInput.value) {
            copyInvoiceId(false);
        }
    }

    function initForms() {
        var overlay = root.querySelector('[data-loading-overlay]');

        each('form', function (form) {
            form.addEventListener('submit', function (event) {
                var submitter = event.submitter || document.activeElement;
                var confirmation = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');

                if (confirmation && !window.confirm(confirmation)) {
                    event.preventDefault();
                    return;
                }

                if (!form.checkValidity()) {
                    return;
                }

                if (form.hasAttribute('data-loading-form') && overlay) {
                    overlay.hidden = false;
                    overlay.setAttribute('aria-hidden', 'false');
                    document.body.setAttribute('aria-busy', 'true');

                    if (submitter && submitter.matches('button, input[type="submit"]')) {
                        submitter.setAttribute('aria-disabled', 'true');
                    }
                }
            });
        });

        window.addEventListener('pageshow', function () {
            if (overlay) {
                overlay.hidden = true;
                overlay.setAttribute('aria-hidden', 'true');
                document.body.removeAttribute('aria-busy');
            }
        });
    }

    var statusLabels = {
        pending: 'Ausstehend',
        queued: 'Ausstehend',
        running: 'In Arbeit',
        processing: 'In Arbeit',
        completed: 'Erfolgreich',
        succeeded: 'Erfolgreich',
        success: 'Erfolgreich',
        skipped: 'Übersprungen',
        retryable_failed: 'Wiederholung geplant',
        retrying: 'Wiederholung geplant',
        retry_wait: 'Wiederholung geplant',
        failed: 'Fehlgeschlagen',
        permanent_failed: 'Fehlgeschlagen',
        error: 'Fehlgeschlagen',
        ambiguous: 'Unklar',
        completed_with_errors: 'Abgeschlossen mit Klärfällen',
        ready: 'Eindeutig',
        blocked: 'Blockiert',
        cancelled: 'Abgebrochen',
        canceled: 'Abgebrochen',
        paused: 'Pausiert'
    };

    function renderStatus(container, status) {
        if (!container) {
            return;
        }

        var safeStatus = String(status || 'unknown').toLowerCase().replace(/[^a-z0-9_-]/g, '');
        var badge = document.createElement('span');
        var dot = document.createElement('span');

        badge.className = 'sd-status sd-status--' + safeStatus;
        dot.className = 'sd-status-dot';
        dot.setAttribute('aria-hidden', 'true');
        badge.appendChild(dot);
        badge.appendChild(document.createTextNode(statusLabels[safeStatus] || safeStatus));
        container.replaceChildren(badge);
    }

    function setText(scope, selector, value) {
        var element = scope.querySelector(selector);
        if (element && value !== undefined && value !== null) {
            element.textContent = String(value);
        }
    }

    // Poll only the local WHMCS status endpoint. The browser never talks to
    // sevdesk directly and therefore never receives the API token.
    function initJobMonitor() {
        var monitor = root.querySelector('[data-job-monitor]');

        if (!monitor || !window.fetch) {
            return;
        }

        var statusUrl = monitor.getAttribute('data-status-url');
        if (!statusUrl) {
            return;
        }

        var interval = clamp(toNumber(monitor.getAttribute('data-refresh-interval'), 3000), 1500, 30000);
        var terminalStatuses = (monitor.getAttribute('data-terminal-statuses') || 'completed,failed,cancelled').split(',');
        var timer = null;
        var stopped = false;
        var inFlight = false;
        var consecutiveErrors = 0;
        var lastProcessed = toNumber((monitor.querySelector('[data-job-progress-label]') || {}).textContent, -1);
        var pollingState = monitor.querySelector('[data-polling-state]');

        function schedule(delay) {
            window.clearTimeout(timer);
            if (!stopped) {
                timer = window.setTimeout(poll, delay);
            }
        }

        function isTerminal(status) {
            return terminalStatuses.indexOf(String(status || '').toLowerCase()) !== -1;
        }

        function updateJob(data) {
            var processed = toNumber(data.processed_items !== undefined ? data.processed_items : data.processed, 0);
            var total = toNumber(data.total_items !== undefined ? data.total_items : data.total, 0);
            var progress = data.progress_percent !== undefined ? toNumber(data.progress_percent, 0) : (total > 0 ? Math.round((processed / total) * 100) : 0);
            var progressBar = monitor.querySelector('[data-job-progress]');
            var progressFill = progressBar ? progressBar.querySelector('span') : null;
            var status = String(data.status || '').toLowerCase();

            progress = clamp(progress, 0, 100);
            setText(monitor, '[data-job-progress-label]', processed + ' von ' + total + ' verarbeitet');
            setText(monitor, '[data-job-progress-percent]', progress + ' %');
            setText(monitor, '[data-job-started]', data.started_at);
            setText(monitor, '[data-job-finished]', data.finished_at);
            setText(monitor, '[data-count-success]', data.succeeded_items !== undefined ? data.succeeded_items : data.succeeded);
            setText(monitor, '[data-count-skipped]', data.skipped_items !== undefined ? data.skipped_items : data.skipped);
            setText(monitor, '[data-count-failed]', data.failed_items !== undefined ? data.failed_items : data.failed);
            setText(monitor, '[data-count-ambiguous]', data.ambiguous_items !== undefined ? data.ambiguous_items : data.ambiguous);

            if (progressBar) {
                progressBar.setAttribute('aria-valuenow', String(progress));
            }
            if (progressFill) {
                progressFill.style.width = progress + '%';
            }
            if (status) {
                renderStatus(monitor.querySelector('[data-job-status]'), status);
            }

            if (lastProcessed >= 0 && processed !== lastProcessed) {
                announce('Jobfortschritt: ' + processed + ' von ' + total + ' Rechnungen verarbeitet.');
            }
            lastProcessed = processed;

            if (isTerminal(status)) {
                stopped = true;
                if (pollingState) {
                    pollingState.textContent = 'Job beendet. Angezeigt wird der letzte gespeicherte Stand.';
                }
                announce('Der Exportjob wurde mit dem Status ' + (statusLabels[status] || status) + ' beendet.');
            } else if (pollingState) {
                pollingState.textContent = 'Zuletzt aktualisiert um ' + new Date().toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + '.';
            }
        }

        function poll() {
            if (stopped || inFlight) {
                return;
            }

            if (document.hidden) {
                schedule(interval);
                return;
            }

            inFlight = true;
            fetch(statusUrl, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Statusabfrage fehlgeschlagen (' + response.status + ')');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    consecutiveErrors = 0;
                    updateJob(payload.job || payload.data || payload);
                })
                .catch(function () {
                    consecutiveErrors += 1;
                    if (pollingState) {
                        pollingState.textContent = 'Automatische Aktualisierung vorübergehend nicht erreichbar. Neuer Versuch folgt.';
                    }
                })
                .finally(function () {
                    inFlight = false;
                    if (!stopped) {
                        schedule(Math.min(interval * Math.max(1, consecutiveErrors), 30000));
                    }
                });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !stopped) {
                schedule(100);
            }
        });

        schedule(interval);
    }

    revealActiveNavigationTab();
    initConditionalFields();
    initSelections();
    initCorrectionForm();
    initForms();
    initJobMonitor();
}());
