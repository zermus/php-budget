(function () {
    var select = document.getElementById('recurrenceType');
    function toggle() {
        document.querySelectorAll('.recurrence-fields').forEach(function (el) {
            el.style.display = el.dataset.type === select.value ? '' : 'none';
        });
    }
    select.addEventListener('change', toggle);
    toggle();
})();
