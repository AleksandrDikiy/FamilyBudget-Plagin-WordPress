/**
 * Family Budget — Модуль Показників лічильників
 * Версія з пагінацією (20 рядків на сторінку)
 */
jQuery( document ).ready( function ( $ ) {

    const $tbody      = $( '#fb-ind-tbody' );
    const $pagination = $( '#fb-ind-pagination' );

    // Guard: запобігає подвійній ініціалізації
    if ( $tbody.data( 'fb-ind-init' ) ) return;
    $tbody.data( 'fb-ind-init', true );

    $tbody.off( '.fbind' );
    $( document ).off( '.fbind' );
    $( '#fb-ind-add-form' ).off( '.fbind' );

    let currentPage  = 1;
    let currentIndId = 0;
    let searchTimer  = null;
    /* ─── ЗАВАНТАЖЕННЯ ТАБЛИЦІ ───────────────────────────────────────────── */

    function loadIndicators( page ) {
        currentPage = page || 1;
        $tbody.css( 'opacity', '0.5' );

        $.post( fbIndObj.ajax_url, {
            action:     'fb_ajax_ind_load',
            security:   fbIndObj.nonce,
            family_id:  $( '#fb-ind-f-family' ).val(),
            account_id: $( '#fb-ind-f-account' ).val(),
            year:       $( '#fb-ind-f-year' ).val(),
            month:      $( '#fb-ind-f-month' ).val(),
            page:       currentPage,
        }, function ( res ) {
            $tbody.css( 'opacity', '1' );
            if ( ! res.success ) return;

            $tbody.html( res.data.html );

            // Рендер пагінації
            renderPagination( res.data.page, res.data.total_pages, res.data.total );
        } );
    }

    loadIndicators( 1 );

    $( document ).on( 'change.fbind',
        '#fb-ind-f-family, #fb-ind-f-account, #fb-ind-f-year, #fb-ind-f-month',
        function () { loadIndicators( 1 ); }
    );

    /* ─── ПАГІНАЦІЯ ──────────────────────────────────────────────────────── */

    function renderPagination( page, totalPages, total ) {
        if ( totalPages <= 1 ) {
            $pagination.hide().empty();
            return;
        }

        $pagination.show();
        let html = '<div class="fb-ind-pag-info">Показано сторінку ' + page + ' з ' + totalPages
                 + ' (всього: ' + total + ')</div><div class="fb-ind-pag-btns">';

        // Кнопка "Перша"
        if ( page > 1 ) {
            html += '<button class="fb-ind-pag-btn" data-page="1">«</button>';
            html += '<button class="fb-ind-pag-btn" data-page="' + ( page - 1 ) + '">‹</button>';
        }

        // Кнопки сторінок (±2 від поточної)
        const from = Math.max( 1, page - 2 );
        const to   = Math.min( totalPages, page + 2 );

        for ( let i = from; i <= to; i++ ) {
            const active = i === page ? ' active' : '';
            html += '<button class="fb-ind-pag-btn' + active + '" data-page="' + i + '">' + i + '</button>';
        }

        // Кнопка "Остання"
        if ( page < totalPages ) {
            html += '<button class="fb-ind-pag-btn" data-page="' + ( page + 1 ) + '">›</button>';
            html += '<button class="fb-ind-pag-btn" data-page="' + totalPages + '">»</button>';
        }

        html += '</div>';
        $pagination.html( html );
    }

    // Клік по кнопці пагінації
    $( document ).on( 'click.fbind', '.fb-ind-pag-btn', function () {
        const page = parseInt( $( this ).data( 'page' ) );
        if ( page ) loadIndicators( page );
    } );

    /* ─── ФОРМА ДОДАВАННЯ ────────────────────────────────────────────────── */

    $( '#fb-ind-add-form' ).on( 'submit.fbind', function ( e ) {
        e.preventDefault();

        const $btn = $( this ).find( '.fb-btn-primary' ).prop( 'disabled', true );

        $.post( fbIndObj.ajax_url,
            $( this ).serialize() + '&action=fb_ajax_ind_add&security=' + fbIndObj.nonce,
            function ( res ) {
                $btn.prop( 'disabled', false );
                if ( res.success ) {
                    $( '#fb-ind-add-form' )[ 0 ].reset();
                    $( '#fb-ind-f-month' ).val( '0' );
                    loadIndicators( 1 );
                } else {
                    alert( res.data.message );
                }
            }
        );
    } );

    /* ─── INLINE-РЕДАГУВАННЯ ─────────────────────────────────────────────── */

    $tbody.on( 'click.fbind', '.fb-edit-btn', function () {
        const $tr = $( this ).closest( 'tr' );
        $tr.find( '.fb-view-mode' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-btn' ).addClass( 'hidden' );
        $tr.find( '.fb-edit-mode' ).removeClass( 'hidden' );
        $tr.find( '.fb-save-btn' ).removeClass( 'hidden' );
        $tr.find( '.fb-ind-edit-consumed' ).trigger( 'focus' );
    } );

    $tbody.on( 'click.fbind', '.fb-save-btn', function () {
        const $btn = $( this ).prop( 'disabled', true );
        const $tr  = $btn.closest( 'tr' );

        $.post( fbIndObj.ajax_url, {
            action:   'fb_ajax_ind_edit',
            security: fbIndObj.nonce,
            id:       $tr.data( 'id' ),
            pa_id:    $tr.find( '.fb-ind-edit-pa' ).val(),
            month:    $tr.find( '.fb-ind-edit-month' ).val(),
            year:     $tr.find( '.fb-ind-edit-year' ).val(),
            val1:     $tr.find( '.fb-ind-edit-val1' ).val(),
            val2:     $tr.find( '.fb-ind-edit-val2' ).val(),
            consumed: $tr.find( '.fb-ind-edit-consumed' ).val(),
        }, function ( res ) {
            $btn.prop( 'disabled', false );
            if ( res.success ) loadIndicators( currentPage );
            else alert( res.data.message );
        } );
    } );

    /* ─── ВИДАЛЕННЯ ──────────────────────────────────────────────────────── */

    $tbody.on( 'click.fbind', '.fb-delete-btn', function ( e ) {
        e.stopImmediatePropagation();
        if ( ! window.confirm( fbIndObj.confirm_delete ) ) return;

        const $btn = $( this ).css( 'opacity', '0.4' );

        $.post( fbIndObj.ajax_url, {
            action:   'fb_ajax_ind_delete',
            security: fbIndObj.nonce,
            id:       $btn.closest( 'tr' ).data( 'id' ),
        }, function ( res ) {
            if ( res.success ) loadIndicators( currentPage );
            else { $btn.css( 'opacity', '1' ); alert( res.data.message ); }
        } );
    } );

    /* ─── МОДАЛЬНЕ ВІКНО: ПРИВ'ЯЗКА ─────────────────────────────────────── */

    const $modal        = $( '#fb-ind-modal' );
    const $searchInput  = $( '#fb-ind-search-input' );
    const $searchResult = $( '#fb-ind-search-results' );
    const $linkedList   = $( '#fb-ind-linked-list' );

    function openModal( indId ) {
        currentIndId = indId;
        $searchInput.val( '' );
        $searchResult.html( '<p class="fb-ind-hint">Введіть запит для пошуку платежів</p>' );
        $modal.fadeIn( 150 );
        loadLinked();
        $searchInput.trigger( 'focus' );
    }

    function closeModal() {
        $modal.fadeOut( 150 );
        currentIndId = 0;
        loadIndicators( currentPage );
    }

    $tbody.on( 'click.fbind', '.fb-link-btn', function ( e ) {
        e.stopImmediatePropagation();
        openModal( $( this ).data( 'id' ) );
    } );

    $( '#fb-ind-modal-close' ).on( 'click.fbind', closeModal );
    $modal.on( 'click.fbind', function ( e ) { if ( $( e.target ).is( $modal ) ) closeModal(); } );
    $( document ).on( 'keydown.fbind', function ( e ) {
        if ( e.key === 'Escape' && $modal.is( ':visible' ) ) closeModal();
    } );

    function loadLinked() {
        $.post( fbIndObj.ajax_url, {
            action:   'fb_ajax_ind_get_linked',
            security: fbIndObj.nonce,
            ind_id:   currentIndId,
        }, function ( res ) { if ( res.success ) renderLinked( res.data.linked ); } );
    }

    function renderLinked( items ) {
        if ( ! items || ! items.length ) {
            $linkedList.html( '<p class="fb-ind-hint">Прив\'язаних платежів немає</p>' );
            return;
        }
        let html = '<div class="fb-ind-linked-title">Прив\'язані платежі:</div>';
        items.forEach( function ( item ) {
            html += '<div class="fb-ind-linked-item">'
                  + '<span class="fb-ind-linked-val">' + escHtml( item.Amount_Value ) + '</span>'
                  + '<span class="fb-ind-linked-note">' + escHtml( item.Note || '' ) + '</span>'
                  + '<button class="fb-ind-unlink-btn" data-amount-id="' + parseInt( item.id ) + '">Від\'язати</button>'
                  + '</div>';
        } );
        $linkedList.html( html );
    }

    $searchInput.on( 'input.fbind', function () {
        clearTimeout( searchTimer );
        const q = $( this ).val().trim();
        if ( q.length < 1 ) {
            $searchResult.html( '<p class="fb-ind-hint">Введіть запит для пошуку</p>' );
            return;
        }
        searchTimer = setTimeout( function () {
            $searchResult.html( '<p class="fb-ind-hint">Пошук...</p>' );
            $.post( fbIndObj.ajax_url, {
                action: 'fb_ajax_ind_search_amounts', security: fbIndObj.nonce,
                ind_id: currentIndId, query: q,
            }, function ( res ) {
                if ( res.success ) renderSearchResults( res.data.amounts, res.data.linked_ids );
            } );
        }, 400 );
    } );

    function renderSearchResults( amounts, linkedIds ) {
        if ( ! amounts || ! amounts.length ) {
            $searchResult.html( '<p class="fb-ind-hint">Нічого не знайдено</p>' );
            return;
        }
        let html = '';
        amounts.forEach( function ( a ) {
            const isLinked = linkedIds.indexOf( parseInt( a.id ) ) !== -1;
            html += '<div class="fb-ind-result-item' + ( isLinked ? ' is-linked' : '' ) + '">'
                  + '<span class="fb-ind-res-val">' + escHtml( a.Amount_Value ) + '</span>'
                  + '<span class="fb-ind-res-note">' + escHtml( a.Note || '' ) + '</span>'
                  + ( isLinked
                      ? '<span class="fb-ind-already">✓ Прив\'язано</span>'
                      : '<button class="fb-ind-link-amount-btn" data-amount-id="' + parseInt( a.id ) + '">+ Прив\'язати</button>'
                    )
                  + '</div>';
        } );
        $searchResult.html( html );
    }

    $searchResult.on( 'click.fbind', '.fb-ind-link-amount-btn', function () {
        const $btn = $( this ).prop( 'disabled', true ).text( '...' );
        $.post( fbIndObj.ajax_url, {
            action: 'fb_ajax_ind_link', security: fbIndObj.nonce,
            ind_id: currentIndId, amount_id: $btn.data( 'amount-id' ),
        }, function ( res ) {
            if ( res.success ) { loadLinked(); $searchInput.trigger( 'input' ); }
            else { $btn.prop( 'disabled', false ).text( '+ Прив\'язати' ); alert( res.data.message ); }
        } );
    } );

    $linkedList.on( 'click.fbind', '.fb-ind-unlink-btn', function () {
        if ( ! window.confirm( fbIndObj.confirm_unlink ) ) return;
        const $btn = $( this ).prop( 'disabled', true );
        $.post( fbIndObj.ajax_url, {
            action: 'fb_ajax_ind_unlink', security: fbIndObj.nonce,
            ind_id: currentIndId, amount_id: $btn.data( 'amount-id' ),
        }, function ( res ) {
            if ( res.success ) { loadLinked(); $searchInput.trigger( 'input' ); }
            else { $btn.prop( 'disabled', false ); alert( res.data.message ); }
        } );
    } );

    /* ─── ІМПОРТ CSV (Thickbox) ──────────────────────────────────────────── */

    const $csvFileInput  = $( '#fb-ind-csv-file' );
    const $importTrigger = $( '#fb-ind-import-btn' );
    const $importBox     = $( '#fb-ind-import-box' );
    const $debugPanel    = $( '#fb-ind-debug-panel' );
    const $addFormInputs = $( '#fb-ind-add-form :input' );
    const $filterInputs  = $( '#fb-ind-f-family, #fb-ind-f-account, #fb-ind-f-year, #fb-ind-f-month' );

    // [FIX-1] КРИТИЧНИЙ БАГ — WordPress Thickbox в режимі inline КОПІЮЄ вміст
    // #fb-ind-import-box у #TB_ajaxContent і ПРИХОВУЄ оригінальний div.
    // Тому всі оновлення після tb_show() мають йти в #TB_ajaxContent,
    // а НЕ в $importBox — інакше оновлюється прихований елемент і спіннер вічний.
    function fbIndUpdateDisplay( html ) {
        var $tb = $( '#TB_ajaxContent' );
        ( $tb.length ? $tb : $importBox ).html( html );
    }

    // Кнопка відкриває діалог вибору файлу
    $importTrigger.on( 'click.fbind', function () {
        $csvFileInput.trigger( 'click' );
    } );

    // Вибір файлу → запускаємо імпорт
    $csvFileInput.on( 'change.fbind', function () {
        const file = this.files[ 0 ];
        if ( ! file ) return;

        if ( ! /\.csv$/i.test( file.name ) ) {
            alert( 'Оберіть файл із розширенням .csv' );
            this.value = '';
            return;
        }

        // Наповнюємо $importBox спіннером ДО виклику tb_show(),
        // щоб Thickbox скопіював коректний вміст у #TB_ajaxContent
        $importBox.show().html(
            '<div class="fb-ind-tb-inner">'
          + '<div class="fb-ind-tb-spinner"></div>'
          + '<p class="fb-ind-tb-loading-msg">Обробка файлу: <strong>' + escHtml( file.name ) + '</strong></p>'
          + '</div>'
        );

        tb_show(
            'Імпорт CSV — результат',
            '#TB_inline?width=500&height=360&inlineId=fb-ind-import-box',
            false
        );

        // Блокуємо UI форми та фільтрів на час обробки
        $addFormInputs.prop( 'disabled', true );
        $filterInputs.prop( 'disabled', true );
        $importTrigger.prop( 'disabled', true );

        // Формуємо FormData та надсилаємо
        const formData = new FormData();
        formData.append( 'action',   'fb_ind_import' );
        formData.append( 'security', fbIndObj.import_nonce );
        formData.append( 'csv_file', file );

        $.ajax( {
            url:         fbIndObj.ajax_url,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,

            success: function ( res ) {
                renderImportResult( res );
            },

            error: function ( xhr ) {
                fbIndUpdateDisplay(
                    '<div class="fb-ind-tb-inner">'
                  + '<p class="fb-ind-tb-error-msg">Помилка з\'єднання (HTTP '
                  + xhr.status + '). Спробуйте ще раз.</p>'
                  + fbIndTbCloseBtn()
                  + '</div>'
                );
            },

            complete: function () {
                // Розблоковуємо UI в будь-якому випадку
                $addFormInputs.prop( 'disabled', false );
                $filterInputs.prop( 'disabled', false );
                $importTrigger.prop( 'disabled', false );
                $csvFileInput.val( '' );
            },
        } );
    } );

    /**
     * Рендерить фінальний звіт імпорту всередині Thickbox
     * та debug-панель на сторінці нижче таблиці.
     *
     * @param {Object} res Відповідь WordPress AJAX.
     */
    function renderImportResult( res ) {
        let html = '<div class="fb-ind-tb-inner">';

        if ( ! res || ! res.success ) {
            const msg = res && res.data && res.data.message
                ? res.data.message
                : 'Невідома помилка. Перевірте консоль браузера.';
            html += '<p class="fb-ind-tb-error-msg">' + escHtml( msg ) + '</p>';
            html += fbIndTbCloseBtn();
            html += '</div>';
            fbIndUpdateDisplay( html );
            return;
        }

        const d = res.data;

        // Статистика: успішно / помилки
        html += '<div class="fb-ind-tb-stats">';
        html += '<div class="fb-ind-tb-stat fb-ind-tb-stat-ok">'
              + '<span class="fb-ind-tb-stat-num">' + parseInt( d.imported || 0 ) + '</span>'
              + '<span class="fb-ind-tb-stat-lbl">успішно</span>'
              + '</div>';
        html += '<div class="fb-ind-tb-stat fb-ind-tb-stat-err">'
              + '<span class="fb-ind-tb-stat-num">' + parseInt( d.errors || 0 ) + '</span>'
              + '<span class="fb-ind-tb-stat-lbl">помилок</span>'
              + '</div>';
        html += '</div>';

        // Рядки з помилками
        if ( d.failed_rows && d.failed_rows.length ) {
            html += '<div class="fb-ind-tb-failed">'
                  + '<div class="fb-ind-tb-failed-title">Рядки з помилками (' + d.failed_rows.length + '):</div>'
                  + '<ul class="fb-ind-tb-failed-list">';
            d.failed_rows.forEach( function ( row ) {
                html += '<li>' + escHtml( row ) + '</li>';
            } );
            html += '</ul></div>';
        }

        const btnLabel = parseInt( d.imported || 0 ) > 0 ? 'Закрити та оновити' : 'Закрити';
        html += fbIndTbCloseBtn( btnLabel );
        html += '</div>';

        fbIndUpdateDisplay( html );

        // Рендеримо debug-панель на сторінці
        if ( d.debug_log && d.debug_log.length ) {
            renderDebugPanel( d.debug_log );
        }

        // Оновлюємо таблицю якщо є успішні рядки
        if ( parseInt( d.imported || 0 ) > 0 ) {
            loadIndicators( currentPage );
        }
    }

    /**
     * Рендерить debug-панель із SQL-запитами та параметрами під таблицею.
     *
     * @param {Array} log Масив записів відлагодження з PHP.
     */
    function renderDebugPanel( log ) {
        let html = '<div class="fb-dbg-header">'
                 + '<span class="fb-dbg-title">🐛 Debug: Імпорт CSV — ' + log.length + ' рядків</span>'
                 + '<button type="button" class="fb-dbg-toggle" data-open="1">▲ Згорнути</button>'
                 + '</div>'
                 + '<div class="fb-dbg-body">';

        log.forEach( function ( entry ) {
            const isOk      = entry.action === 'insert' || entry.action === 'update';
            const isSkip    = entry.action === 'skip';
            const isError   = entry.action === 'error';
            const rowClass  = isOk ? 'fb-dbg-row-ok' : ( isSkip ? 'fb-dbg-row-skip' : 'fb-dbg-row-err' );
            const actionIcon = isOk ? '✔' : ( isSkip ? '⊘' : '✖' );

            html += '<div class="fb-dbg-row ' + rowClass + '">';

            // Заголовок рядка
            html += '<div class="fb-dbg-row-head">'
                  + '<span class="fb-dbg-row-num">#' + entry.row + '</span>'
                  + '<span class="fb-dbg-action">' + actionIcon + ' ' + escHtml( entry.action ) + '</span>'
                  + '<span class="fb-dbg-account">' + escHtml( entry.account || '—' ) + '</span>'
                  + '<span class="fb-dbg-period">' + entry.month + '/' + entry.year + '</span>';

            if ( entry.sum_str ) {
                html += '<span class="fb-dbg-sum">sum: <b>' + escHtml( entry.sum_str ) + '</b></span>';
            }
            html += '</div>';

            // SQL пошуку рахунку
            if ( entry.pa_sql ) {
                html += '<div class="fb-dbg-sql-block">'
                      + '<span class="fb-dbg-label">PA SQL</span>'
                      + '<span class="fb-dbg-sql-tag ' + ( entry.pa_id ? 'tag-ok' : 'tag-err' ) + '">'
                      + 'pa_id=' + entry.pa_id + '</span>'
                      + '<code class="fb-dbg-sql">' + escHtml( entry.pa_sql ) + '</code>'
                      + '</div>';
            }

            // SQL пошуку суми
            if ( entry.amount_sql ) {
                const amtClass = entry.amount_id ? 'tag-ok' : 'tag-warn';
                const amtLabel = entry.amount_id
                    ? 'amount_id=' + entry.amount_id
                    : 'matches=' + entry.matches + ' (пропущено)';
                html += '<div class="fb-dbg-sql-block">'
                      + '<span class="fb-dbg-label">AMOUNT SQL</span>'
                      + '<span class="fb-dbg-sql-tag ' + amtClass + '">' + amtLabel + '</span>'
                      + '<code class="fb-dbg-sql">' + escHtml( entry.amount_sql ) + '</code>'
                      + '</div>';
            } else if ( entry.sum_raw && ! entry.amount_sql ) {
                html += '<div class="fb-dbg-sql-block">'
                      + '<span class="fb-dbg-label">AMOUNT SQL</span>'
                      + '<span class="fb-dbg-sql-tag tag-warn">sum порожній або 0 — пошук пропущено</span>'
                      + '</div>';
            }

            // Помилка рядка
            if ( entry.error ) {
                html += '<div class="fb-dbg-error-line">⚠ ' + escHtml( entry.error ) + '</div>';
            }

            html += '</div>';
        } );

        html += '</div>'; // .fb-dbg-body
        $debugPanel.html( html ).show();
    }

    // Toggle згорнути/розгорнути debug-панель
    $( document ).on( 'click.fbind', '.fb-dbg-toggle', function () {
        const $btn  = $( this );
        const $body = $debugPanel.find( '.fb-dbg-body' );
        if ( $btn.data( 'open' ) ) {
            $body.hide();
            $btn.text( '▼ Розгорнути' ).data( 'open', 0 );
        } else {
            $body.show();
            $btn.text( '▲ Згорнути' ).data( 'open', 1 );
        }
    } );

    /**
     * Повертає HTML кнопки закриття Thickbox.
     *
     * @param  {string} label Текст кнопки (необов'язково).
     * @return {string} HTML-рядок.
     */
    function fbIndTbCloseBtn( label ) {
        label = label || 'Закрити';
        return '<div class="fb-ind-tb-footer">'
             + '<button type="button" class="fb-ind-tb-close-btn fb-btn-primary">'
             + escHtml( label )
             + '</button>'
             + '</div>';
    }

    // Делегований обробник кнопки "Закрити" всередині Thickbox
    $( document ).on( 'click.fbind', '.fb-ind-tb-close-btn', function () {
        tb_remove();
    } );

    /* ─── УТИЛІТИ ────────────────────────────────────────────────────────── */

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

} );
