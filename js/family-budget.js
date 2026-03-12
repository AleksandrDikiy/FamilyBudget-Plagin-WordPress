jQuery(document).ready(function($) {
    $('.fb-save-indicator-btn').on('click', function(e) {
        e.preventDefault();

        const data = {
            action: 'fb_save_indicator',
            security: fb_obj.nonce, // Передається через wp_localize_script
            account_id: $('#account_id').val(),
            value: $('#indicator_value').val(),
            month: $('#indicator_month').val(),
            year: $('#indicator_year').val()
        };

        $.post(fb_obj.ajax_url, data, function(response) {
            if (response.success) {
                alert(response.data.message + '. Спожито: ' + response.data.consumed);
                // Оновити UI (наприклад, колір рядка)
            } else {
                alert('Помилка: ' + response.data);
            }
        });
    });
});