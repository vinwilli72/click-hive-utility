(function () {
    'use strict';
    let sequence = Date.now();
    function renumber() {
        document.querySelectorAll('[data-chm-list]').forEach(function (list) {
            const type = list.dataset.chmList;
            Array.from(list.children).forEach(function (row, index) {
                row.querySelectorAll('[name]').forEach(function (input) {
                    input.name = input.name.replace(new RegExp('\\[' + type + '\\]\\[[^\\]]+\\]'), '[' + type + '][' + index + ']');
                });
            });
        });
    }
    document.addEventListener('click', function (event) {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.classList.contains('chm-add')) {
            const type = button.dataset.type;
            const template = document.getElementById('chm-template-' + type);
            const list = document.querySelector('[data-chm-list="' + type + '"]');
            const holder = document.createElement('div');
            holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(++sequence));
            list.appendChild(holder.firstElementChild);
            renumber();
        }
        const row = button.closest('.chm-edit-row');
        if (row) {
            if (button.classList.contains('chm-remove')) row.remove();
            if (button.classList.contains('chm-up') && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
            if (button.classList.contains('chm-down') && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
            renumber();
        }
        if (button.id === 'chm-logo-select' && window.wp && wp.media) {
            const frame = wp.media({title: 'Choose client logo', library: {type: 'image'}, multiple: false});
            frame.on('select', function () {
                document.getElementById('chm-logo').value = frame.state().get('selection').first().toJSON().url;
            });
            frame.open();
        }
    });
})();
