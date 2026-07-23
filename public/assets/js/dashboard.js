// Dashboard interactions: paid checkboxes and inline amount edits.
// All POSTs carry the CSRF token; the server is authoritative.
document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('dashboard');
    if (!root) {
        return;
    }

    var csrf = root.dataset.csrf;

    function post(url, fields) {
        var body = new URLSearchParams(fields);
        body.set('csrf_token', csrf);

        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (resp) { return resp.json(); });
    }

    function fmt(amount) {
        return Number(amount).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function applyTotals(totals) {
        (totals || []).forEach(function (t) {
            var card = root.querySelector('.paycheck-card[data-paycheck-id="' + t.paycheck_id + '"]');
            if (!card) {
                return;
            }
            card.querySelector('.bills-total').textContent = '$' + fmt(t.bills_total);

            var paidNote = card.querySelector('.paid-note');
            if (paidNote) {
                paidNote.querySelector('.paid-amount').textContent = fmt(t.paid_total);
                paidNote.hidden = Number(t.paid_total) === 0;
            }

            var remaining = card.querySelector('.remaining');
            remaining.textContent = '$' + fmt(t.remaining);
            remaining.classList.toggle('remaining-neg', Number(t.remaining) < 0);
            remaining.classList.toggle('remaining-pos', Number(t.remaining) >= 0);
        });
    }

    function promptAmount(current) {
        var value = window.prompt('New amount:', current.replace(/,/g, ''));
        if (value === null) {
            return null;
        }
        value = value.trim().replace(/^\$/, '');
        if (!/^\d+(\.\d{1,2})?$/.test(value.replace(/,/g, ''))) {
            window.alert('Enter a valid dollar amount, e.g. 125.00');
            return null;
        }
        return value;
    }

    // Paid checkbox -> the mark_paid seam (source=manual).
    root.addEventListener('change', function (event) {
        var checkbox = event.target.closest('.occ-paid');
        if (!checkbox) {
            return;
        }
        var row = checkbox.closest('.occ-row');

        post(root.dataset.paidUrl, {
            id: row.dataset.occurrenceId,
            paid: checkbox.checked ? '1' : '0'
        }).then(function (data) {
            if (!data.success) {
                checkbox.checked = !checkbox.checked;
                window.alert(data.error || 'Could not update.');
                return;
            }
            // An occurrence can be split across cards; sync every copy.
            root.querySelectorAll('.occ-row[data-occurrence-id="' + row.dataset.occurrenceId + '"]')
                .forEach(function (r) {
                    r.classList.toggle('paid', data.paid);
                    r.querySelector('.occ-paid').checked = data.paid;
                });
            applyTotals(data.totals);
        }).catch(function () {
            checkbox.checked = !checkbox.checked;
            window.alert('Could not reach the server.');
        });
    });

    // Sort selector: reload with the choice; the server persists it.
    var sortSelect = document.getElementById('sortSelect');
    if (sortSelect) {
        sortSelect.addEventListener('change', function () {
            var target = new URL(window.location.href);
            target.searchParams.set('sort', sortSelect.value);
            target.searchParams.delete('page');
            window.location.href = target.toString();
        });
    }

    // Click amounts to edit.
    root.addEventListener('click', function (event) {
        var payAmount = event.target.closest('.pay-amount.editable');
        if (payAmount) {
            var card = payAmount.closest('.paycheck-card');
            var value = promptAmount(payAmount.textContent);
            if (value === null) {
                return;
            }
            post(root.dataset.payAmountUrl, { id: card.dataset.paycheckId, amount: value })
                .then(function (data) {
                    if (!data.success) {
                        window.alert(data.error || 'Could not update.');
                        return;
                    }
                    payAmount.textContent = fmt(value);
                    applyTotals(data.totals);
                });
            return;
        }

        var occAmount = event.target.closest('.occ-amount.editable');
        if (occAmount) {
            var row = occAmount.closest('.occ-row');
            var current = occAmount.querySelector('.amount').textContent;
            var newValue = promptAmount(current);
            if (newValue === null) {
                return;
            }
            post(root.dataset.occAmountUrl, { id: row.dataset.occurrenceId, amount: newValue })
                .then(function (data) {
                    if (!data.success) {
                        window.alert(data.error || 'Could not update.');
                        return;
                    }
                    occAmount.querySelector('.amount').textContent = fmt(data.amount);
                    applyTotals(data.totals);
                });
        }
    });
});
