(function () {
    var rows = document.getElementById('alloc-rows');

    document.getElementById('add-row').addEventListener('click', function () {
        var row = rows.querySelector('.alloc-row').cloneNode(true);
        row.querySelector('input').value = '';
        row.querySelector('select').value = '';
        rows.appendChild(row);
    });

    rows.addEventListener('click', function (event) {
        if (event.target.classList.contains('remove-row') && rows.querySelectorAll('.alloc-row').length > 1) {
            event.target.closest('.alloc-row').remove();
        }
    });
})();
