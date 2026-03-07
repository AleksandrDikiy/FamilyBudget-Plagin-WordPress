/**
 * Скрипти для модуля "Категорії"
 */
jQuery(document).ready(function($) {

	// Ініціалізація
	loadCategoryData();

	// 1. AJAX фільтрація
	$('#fb-filter-cat-family, #fb-filter-cat-type').off('change').on('change', function() {
		loadCategoryData();
	});

	// 2. Додавання категорії
	$('#fb-add-category-form').off('submit').on('submit', function(e) {
		e.preventDefault();
		let form = $(this);
		let submitBtn = form.find('button[type="submit"]');
		submitBtn.prop('disabled', true).css('opacity', '0.6');

		$.post(fbCatObj.ajax_url, {
			action: 'fb_ajax_add_category',
			security: fbCatObj.nonce,
			family_id: form.find('[name="family_id"]').val(),
			type_id: form.find('[name="type_id"]').val(),
			category_name: form.find('[name="category_name"]').val()
		}, function(response) {
			submitBtn.prop('disabled', false).css('opacity', '1');
			if (response.success) {
				form[0].reset();
				loadCategoryData();
			} else {
				alert(response.data.message || 'Помилка.');
			}
		});
	});

	// 3. Таблиця Категорій: Inline-редагування
	$('#fb-category-tbody').off('click', '.fb-edit-btn').on('click', '.fb-edit-btn', function(e) {
		e.preventDefault();
		let row = $(this).closest('tr');
		row.find('.fb-text-val, .fb-edit-btn, .fb-param-btn').addClass('hidden');
		row.find('.fb-input-val, .fb-save-btn').removeClass('hidden');
		row.find('.fb-name-input').focus();
	});

	$('#fb-category-tbody').off('click', '.fb-save-btn').on('click', '.fb-save-btn', function(e) {
		e.preventDefault();
		let row = $(this).closest('tr');
		let id = row.data('id');
		let newName = row.find('.fb-name-input').val().trim();

		if (newName === '') return alert('Назва не може бути порожньою.');

		row.css('opacity', '0.6');
		$.post(fbCatObj.ajax_url, {
			action: 'fb_ajax_update_category_name',
			security: fbCatObj.nonce,
			id: id,
			name: newName
		}, function(response) {
			row.css('opacity', '1');
			if (response.success) {
				row.find('.fb-cat-name-val strong').text(newName);
				row.data('cat-name', newName);
				row.find('.fb-text-val, .fb-edit-btn, .fb-param-btn').removeClass('hidden');
				row.find('.fb-input-val, .fb-save-btn').addClass('hidden');
			}
		});
	});

	// 4. Таблиця Категорій: Видалення (ФІКС ПОДВІЙНОГО ВИКЛИКУ)
	$('#fb-category-tbody').off('click', '.fb-delete-btn').on('click', '.fb-delete-btn', function(e) {
		e.preventDefault();
		e.stopImmediatePropagation(); // Блокуємо будь-які інші обробники

		if (!confirm(fbCatObj.confirm)) return;

		let row = $(this).closest('tr');
		$.post(fbCatObj.ajax_url, {
			action: 'fb_ajax_delete_category',
			security: fbCatObj.nonce,
			id: row.data('id')
		}, function(response) {
			if (response.success) row.fadeOut(300, function() { $(this).remove(); });
			else alert(response.data.message);
		});
	});

	// 5. МОДАЛЬНЕ ВІКНО ПАРАМЕТРІВ
	$('#fb-category-tbody').off('click', '.fb-param-btn').on('click', '.fb-param-btn', function(e) {
		e.preventDefault();
		let row = $(this).closest('tr');
		let catId = row.data('id');
		let familyId = row.data('family-id');
		let catName = row.data('cat-name');

		$('#modal_category_id').val(catId);
		$('#modal_family_id').val(familyId);
		$('#fb-modal-cat-name').text('Параметри: ' + catName);

		loadParamsData(catId);
		$('#fb-params-modal').removeClass('hidden').fadeIn(200);
	});

	$('.fb-modal-close').off('click').on('click', function() {
		$('#fb-params-modal').fadeOut(200, function() { $(this).addClass('hidden'); });
		loadCategoryData(); // Оновлюємо бейджі з кількістю параметрів
	});

	// Додавання параметра
	$('#fb-add-param-form').off('submit').on('submit', function(e) {
		e.preventDefault();
		let form = $(this);
		$.post(fbCatObj.ajax_url, {
			action: 'fb_ajax_add_category_param',
			security: fbCatObj.nonce,
			family_id: form.find('[name="modal_family_id"]').val(),
			category_id: form.find('[name="modal_category_id"]').val(),
			param_type_id: form.find('[name="param_type_id"]').val(),
			param_name: form.find('[name="param_name"]').val()
		}, function(response) {
			if (response.success) {
				form.find('[name="param_name"]').val('');
				loadParamsData(form.find('[name="modal_category_id"]').val());
			}
		});
	});

	// Редагування параметра (Inline у модалці)
	$('#fb-params-tbody').off('click', '[data-action="edit-param"]').on('click', '[data-action="edit-param"]', function(e) {
		e.preventDefault();
		let row = $(this).closest('tr');
		row.find('.fb-text-val, [data-action="edit-param"]').addClass('hidden');
		row.find('.fb-input-val, [data-action="save-param"]').removeClass('hidden');
	});

	$('#fb-params-tbody').off('click', '[data-action="save-param"]').on('click', '[data-action="save-param"]', function(e) {
		e.preventDefault();
		let row = $(this).closest('tr');
		let id = row.data('id');
		let newName = row.find('.fb-p-name-input').val().trim();

		$.post(fbCatObj.ajax_url, {
			action: 'fb_ajax_edit_category_param',
			security: fbCatObj.nonce,
			id: id,
			name: newName
		}, function(response) {
			if (response.success) {
				row.find('.fb-p-name-val').text(newName);
				row.find('.fb-text-val, [data-action="edit-param"]').removeClass('hidden');
				row.find('.fb-input-val, [data-action="save-param"]').addClass('hidden');
			}
		});
	});

	// Видалення параметра (ФІКС ПОДВІЙНОГО ВИКЛИКУ)
	$('#fb-params-tbody').off('click', '[data-action="delete-param"]').on('click', '[data-action="delete-param"]', function(e) {
		e.preventDefault();
		e.stopImmediatePropagation();

		if (!confirm('Видалити параметр?')) return;

		let row = $(this).closest('tr');
		$.post(fbCatObj.ajax_url, {
			action: 'fb_ajax_delete_category_param',
			security: fbCatObj.nonce,
			id: row.data('id')
		}, function(response) {
			if (response.success) row.fadeOut(200, function(){ $(this).remove(); });
		});
	});

	// Функції завантаження
	function loadCategoryData() {
		let tbody = $('#fb-category-tbody');
		tbody.css('opacity', '0.5');
		$.post(fbCatObj.ajax_url, {
			action: 'fb_ajax_load_categories',
			security: fbCatObj.nonce,
			family_id: $('#fb-filter-cat-family').val(),
			type_id: $('#fb-filter-cat-type').val()
		}, function(response) {
			if (response.success) {
				tbody.html(response.data.html).css('opacity', '1');
				initSortable('#fb-category-tbody', '.fb-drag-handle', 'fb_ajax_move_category');
			}
		});
	}

	function loadParamsData(categoryId) {
		let tbody = $('#fb-params-tbody');
		tbody.css('opacity', '0.5');
		$.post(fbCatObj.ajax_url, {
			action: 'fb_ajax_load_category_params',
			security: fbCatObj.nonce,
			category_id: categoryId
		}, function(response) {
			if (response.success) {
				tbody.html(response.data.html).css('opacity', '1');
				initSortable('#fb-params-tbody', '.fb-drag-handle-param', 'fb_ajax_move_category_param');
			}
		});
	}

	// Універсальна ініціалізація Drag and Drop
	function initSortable(container, handleClass, ajaxAction) {
		$(container).sortable({
			handle: handleClass,
			helper: function(e, tr) {
				var $originals = tr.children();
				var $helper = tr.clone();
				$helper.children().each(function(index) { $(this).width($originals.eq(index).width()); });
				return $helper;
			},
			update: function(event, ui) {
				let order = [];
				$(container + ' tr').each(function() {
					let rowId = $(this).data('id');
					if (rowId) order.push(rowId);
				});
				$(container).css('opacity', '0.7');
				$.post(fbCatObj.ajax_url, {
					action: ajaxAction,
					security: fbCatObj.nonce,
					order: order
				}, function() {
					$(container).css('opacity', '1');
				});
			}
		}).disableSelection();
	}
});