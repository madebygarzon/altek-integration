/* (EN) Admin JS to handle single-click and bulk send to ALTEK in Orders list */
jQuery(function ($) {
  // (EN) Single order action: button with class 'altek-send-order' inside actions column
  $(document).on('click', 'a.button.altek-send-order, a.altek-send-order', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const $row = $btn.closest('tr');
    const orderId = $row.attr('id') ? parseInt($row.attr('id').replace('post-', ''), 10) : null;
    if (!orderId) return;

    const originalTitle = $btn.attr('aria-label') || $btn.text();
    $btn.prop('disabled', true).text(wcAltek.i18n.sending);

    $.post(wcAltek.ajaxUrl, {
      action: 'altek_send_order',
      nonce: wcAltek.nonce,
      order_id: orderId
    })
    .done(function (res) {
      var msg = (res.data && res.data.message) ? res.data.message : wcAltek.i18n.ok;
      var icon = 'success';
      // (EN) Determine icon based on message content (customize as needed)
      if (/idempotente|ya existía|no duplicada/i.test(msg)) icon = 'info';
      if (/advertencia|omitido|excluido/i.test(msg)) icon = 'warning';
      // (EN) Show resultS
      if (res && res.success) {
        Swal.fire({
          icon: icon,
          title: 'ALTEK',
          html: msg.replace(/\n/g, '<br>'),
          confirmButtonText: 'OK'
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          html: (wcAltek.i18n.fail + (res && res.data && res.data.message ? (': ' + res.data.message) : '')).replace(/\n/g, '<br>'),
          confirmButtonText: 'Cerrar'
        });
      }
    })
    .fail(function (xhr) {
      var msg = wcAltek.i18n.fail + ' (' + xhr.status + ')';
      try {
        var json = xhr.responseJSON;
        if (json && json.data && json.data.message) {
          msg += ' - ' + json.data.message;
        } else if (xhr.responseText) {
          // Try parse in case server didn't set proper headers
          var data = JSON.parse(xhr.responseText);
          if (data && data.data && data.data.message) {
            msg += ' - ' + data.data.message;
          }
        }
      } catch (e) {}
      Swal.fire({
        icon: 'error',
        title: 'Error',
        html: msg.replace(/\n/g, '<br>'),
        confirmButtonText: 'Cerrar'
      });
    })
    .always(function () {
      $btn.prop('disabled', false).text(originalTitle);
    });
  });

  // (EN) Optionally handle bulk via JS; server bulk is already wired.
  // Keeping this file lean—bulk is processed server-side on submit.
});
