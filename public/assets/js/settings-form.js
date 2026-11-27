(function () {
    var select = document.getElementById('scheduleType');
    if (!select) {
        return; // sub-users see the password form only
    }
    function toggle() {
        document.querySelectorAll('.schedule-fields').forEach(function (el) {
            el.style.display = el.dataset.type.split(' ').indexOf(select.value) !== -1 ? '' : 'none';
        });
    }
    select.addEventListener('change', toggle);
    toggle();

    // SMTP-only fields follow the transport choice.
    var transport = document.getElementById('mailTransport');
    var mailFields = document.querySelector('.mail-fields');
    if (!transport || !mailFields) {
        return;
    }
    function toggleMail() {
        mailFields.style.display = transport.value === 'smtp' ? '' : 'none';
    }
    transport.addEventListener('change', toggleMail);
    toggleMail();

})();
