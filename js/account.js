/**
 * Скрипти для модуля "Рахунки"
 * Файл: js/account.js
 */
jQuery(document).ready(function($) {

    // Ініціалізація: Завантаження даних при старті сторінки
    loadTableData();

    // 1. AJAX фільтрація: миттєва реакція на зміну будь-якого select
    $('#fb-filter-family, #fb-filter-type').on('change', function() {
        loadTableData();
    });

    // 2. Додавання запису (Inline form)
    $('#fb-add-account-form').on('submit', function(e) {
        e.preventDefault();

        let form = $(this);
        let submitBtn = form.find('button[type="submit"]');
        submitBtn.prop('disabled', true).css('opacity', '0.6'); // Захист від подвійного кліку

        let data = {
            action: 'fb_add_account',
            security: fbAccountObj.nonce,
            family_id: form.find('[name="family_id"]').val(),
            type_id: form.find('[name="type_id"]').val(),
            account_name: form.find('[name="account_name"]').val()
        };

        $.post(fbAccountObj.ajax_url, data, function(response) {
            submitBtn.prop('disabled', false).css('opacity', '1');
            if (response.success) {
                form[0].reset(); // Очищаємо форму після успішного додавання
                loadTableData(); // Оновлюємо таблицю
            } else {
                alert(response.data.message || 'Сталася помилка при додаванні.');
            }
        }).fail(function() {
            submitBtn.prop('disabled', false).css('opacity', '1');
            alert('Помилка з\'єднання з сервером.');
        });
    });

    // 3. Таблиця: Встановити як Головну (клік по зірочці)
    $('#fb-accounts-tbody').on('click', '.fb-star', function() {
        let row = $(this).closest('tr');
        let id = row.data('id');

        // Одразу візуально змінюємо для кращого UX
        $('.fb-star').removeClass('is-default');
        $(this).addClass('is-default');

        $.post(fbAccountObj.ajax_url, {
            action: 'fb_set_default_account',
            security: fbAccountObj.nonce,
            id: id
        }, function(response) {
            if (!response.success) {
                loadTableData(); // Відкочуємо зміни у разі помилки
                alert(response.data.message || 'Помилка зміни статусу.');
            } else {
                // Якщо потрібно, можна зробити повне перезавантаження для правильного сортування:
                loadTableData();
            }
        });
    });

    // 4. Таблиця: Видалення з модальним вікном (confirm)
    $('#fb-accounts-tbody').on('click', '.fb-delete-btn', function() {
        if (!confirm(fbAccountObj.confirm)) return;

        let row = $(this).closest('tr');
        let id = row.data('id');

        row.css('opacity', '0.5'); // Індикація процесу видалення

        $.post(fbAccountObj.ajax_url, {
            action: 'fb_delete_account',
            security: fbAccountObj.nonce,
            id: id
        }, function(response) {
            if (response.success) {
                row.fadeOut(300, function() { $(this).remove(); });
            } else {
                row.css('opacity', '1');
                alert(response.data.message || 'Помилка видалення.');
            }
        });
    });

    // 5. Таблиця: Inline-редагування назви
    $('#fb-accounts-tbody').on('click', '.fb-edit-btn', function() {
        let row = $(this).closest('tr');
        row.find('.fb-acc-name-text, .fb-edit-btn').addClass('hidden');
        row.find('.fb-acc-name-input, .fb-save-btn').removeClass('hidden');
        row.find('.fb-acc-name-input').focus();
    });

    // 5.1 Таблиця: Збереження inline-редагування
    $('#fb-accounts-tbody').on('click', '.fb-save-btn', function() {
        let row = $(this).closest('tr');
        let id = row.data('id');
        let inputField = row.find('.fb-acc-name-input');
        let newName = inputField.val().trim();

        if (newName === '') {
            alert('Назва рахунку не може бути порожньою.');
            inputField.focus();
            return;
        }

        row.css('opacity', '0.6');

        $.post(fbAccountObj.ajax_url, {
            action: 'fb_edit_account',
            security: fbAccountObj.nonce,
            id: id,
            name: newName
        }, function(response) {
            row.css('opacity', '1');
            if (response.success) {
                row.find('.fb-acc-name-text').text(newName).removeClass('hidden');
                row.find('.fb-edit-btn').removeClass('hidden');
                row.find('.fb-acc-name-input, .fb-save-btn').addClass('hidden');
            } else {
                alert(response.data.message || 'Помилка збереження.');
            }
        });
    });

    // 5.2 Таблиця: Збереження по Enter у полі вводу
    $('#fb-accounts-tbody').on('keypress', '.fb-acc-name-input', function(e) {
        if (e.which === 13) { // Клавіша Enter
            $(this).closest('tr').find('.fb-save-btn').click();
        }
    });

    // Функція завантаження даних
    function loadTableData() {
        let tbody = $('#fb-accounts-tbody');
        tbody.css('opacity', '0.5'); // Візуальний фідбек завантаження

        $.post(fbAccountObj.ajax_url, {
            action: 'fb_load_accounts',
            security: fbAccountObj.nonce,
            family_id: $('#fb-filter-family').val(),
            type_id: $('#fb-filter-type').val()
        }, function(response) {
            if (response.success) {
                tbody.html(response.data.html).css('opacity', '1');
                initSortable(); // Переініціалізація Drag & Drop після оновлення DOM
            } else {
                tbody.html('<tr><td colspan="5" class="text-center">Помилка завантаження даних</td></tr>').css('opacity', '1');
            }
        }).fail(function() {
            tbody.html('<tr><td colspan="5" class="text-center">Помилка сервера</td></tr>').css('opacity', '1');
        });
    }

    // Ініціалізація jQuery UI Sortable (Drag and Drop)
    function initSortable() {
        $('#fb-accounts-tbody').sortable({
            handle: '.fb-drag-handle', // Тягнемо тільки за іконку
            helper: function(e, tr) {
                // Фікс ширини колонок під час перетягування
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function(index) {
                    $(this).width($originals.eq(index).width());
                });
                return $helper;
            },
            update: function(event, ui) {
                let order = [];
                $('#fb-accounts-tbody tr').each(function() {
                    let rowId = $(this).data('id');
                    if (rowId) order.push(rowId);
                });

                // Візуально підсвічуємо таблицю під час збереження порядку
                $('#fb-accounts-tbody').css('opacity', '0.7');

                $.post(fbAccountObj.ajax_url, {
                    action: 'fb_reorder_accounts',
                    security: fbAccountObj.nonce,
                    order: order
                }, function(response) {
                    $('#fb-accounts-tbody').css('opacity', '1');
                    // Якщо треба зберігати суворе сортування (Головна зверху), можна викликати loadTableData();
                });
            }
        }).disableSelection();
    }
});