/**
 * Скрипти для модуля "Валюти"
 * Файл: js/currency.js
 */
jQuery(document).ready(function($) {

	// Ініціалізація
	loadCurrencyData();

	// 1. AJAX фільтрація
	$('#fb-filter-currency-family').on('change', function() {
		loadCurrencyData();
	});

	// 2. Додавання запису (Inline form)
	$('#fb-add-currency-form').on('submit', function(e) {
		e.preventDefault();

		let form = $(this);
		let submitBtn = form.find('button[type="submit"]');
		submitBtn.prop('disabled', true).css('opacity', '0.6');

		let data = {
			action: 'fb_add_currency',
			security: fbCurrencyObj.nonce,
			family_id: form.find('[name="family_id"]').val(),
			currency_name: form.find('[name="currency_name"]').val(),
			currency_code: form.find('[name="currency_code"]').val(),
			currency_symbol: form.find('[name="currency_symbol"]').val()
		};

		$.post(fbCurrencyObj.ajax_url, data, function(response) {
			submitBtn.prop('disabled', false).css('opacity', '1');
			if (response.success) {
				form[0].reset();
				loadCurrencyData();
			} else {
				alert(response.data.message || 'Сталася помилка при додаванні.');
			}
		}).fail(function() {
			submitBtn.prop('disabled', false).css('opacity', '1');
			alert('Помилка з\'єднання з сервером.');
		});
	});

	// 3. Таблиця: Встановити як Головну (Primary)
	$('#fb-currency-tbody').on('click', '.fb-star', function() {
		let row = $(this).closest('tr');
		let id = row.data('id');

		$('.fb-star').removeClass('is-primary');
		$(this).addClass('is-primary');

		$.post(fbCurrencyObj.ajax_url, {
			action: 'fb_set_primary_currency',
			security: fbCurrencyObj.nonce,
			id: id
		}, function(response) {
			if (!response.success) {
				loadCurrencyData();
				alert(response.data.message || 'Помилка зміни статусу.');
			} else {
				loadCurrencyData(); // Перезавантажуємо для правильного сортування
			}
		});
	});

	// 4. Таблиця: Видалення
	$('#fb-currency-tbody').on('click', '.fb-delete-btn', function() {
		if (!confirm(fbCurrencyObj.confirm)) return;

		let row = $(this).closest('tr');
		let id = row.data('id');
		row.css('opacity', '0.5');

		$.post(fbCurrencyObj.ajax_url, {
			action: 'fb_delete_currency',
			security: fbCurrencyObj.nonce,
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

	// 5. Таблиця: Inline-редагування
	$('#fb-currency-tbody').on('click', '.fb-edit-btn', function() {
		let row = $(this).closest('tr');
		row.find('.fb-text-val, .fb-edit-btn').addClass('hidden');
		row.find('.fb-input-val, .fb-save-btn').removeClass('hidden');
		row.find('.fb-name-input').focus();
	});

	// 5.1 Таблиця: Збереження inline-редагування
	$('#fb-currency-tbody').on('click', '.fb-save-btn', function() {
		let row = $(this).closest('tr');
		let id = row.data('id');

		let newName = row.find('.fb-name-input').val().trim();
		let newCode = row.find('.fb-code-input').val().trim();
		let newSymbol = row.find('.fb-symbol-input').val().trim();

		if (newName === '') {
			alert('Назва валюти не може бути порожньою.');
			row.find('.fb-name-input').focus();
			return;
		}

		row.css('opacity', '0.6');

		$.post(fbCurrencyObj.ajax_url, {
			action: 'fb_edit_currency',
			security: fbCurrencyObj.nonce,
			id: id,
			name: newName,
			code: newCode,
			symbol: newSymbol
		}, function(response) {
			row.css('opacity', '1');
			if (response.success) {
				row.find('.fb-name-val').text(newName);
				row.find('.fb-code-val').text(newCode);
				row.find('.fb-symbol-val').text(newSymbol);

				row.find('.fb-text-val').removeClass('hidden');
				row.find('.fb-edit-btn').removeClass('hidden');
				row.find('.fb-input-val, .fb-save-btn').addClass('hidden');
			} else {
				alert(response.data.message || 'Помилка збереження.');
			}
		});
	});

	// Функція завантаження даних
	function loadCurrencyData() {
		let tbody = $('#fb-currency-tbody');
		tbody.css('opacity', '0.5');

		$.post(fbCurrencyObj.ajax_url, {
			action: 'fb_load_currencies',
			security: fbCurrencyObj.nonce,
			family_id: $('#fb-filter-currency-family').val()
		}, function(response) {
			if (response.success) {
				tbody.html(response.data.html).css('opacity', '1');
				initSortable();
			} else {
				tbody.html('<tr><td colspan="6" class="text-center">Помилка завантаження даних</td></tr>').css('opacity', '1');
			}
		}).fail(function() {
			tbody.html('<tr><td colspan="6" class="text-center">Помилка сервера</td></tr>').css('opacity', '1');
		});
	}

	// Ініціалізація Drag and Drop
	function initSortable() {
		$('#fb-currency-tbody').sortable({
			handle: '.fb-drag-handle',
			helper: function(e, tr) {
				var $originals = tr.children();
				var $helper = tr.clone();
				$helper.children().each(function(index) {
					$(this).width($originals.eq(index).width());
				});
				return $helper;
			},
			update: function(event, ui) {
				let order = [];
				$('#fb-currency-tbody tr').each(function() {
					let rowId = $(this).data('id');
					if (rowId) order.push(rowId);
				});

				$('#fb-currency-tbody').css('opacity', '0.7');

				$.post(fbCurrencyObj.ajax_url, {
					action: 'fb_reorder_currencies',
					security: fbCurrencyObj.nonce,
					order: order
				}, function(response) {
					$('#fb-currency-tbody').css('opacity', '1');
				});
			}
		}).disableSelection();
	}
});