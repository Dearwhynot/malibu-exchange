(function ($) {
  'use strict';

  $(function () {
    $('#me-rates-form .me-button').on('click', function () {
      MalibuExchange.notify('Hook this button to your real save logic.');
      if (window.MalibuToast && typeof window.MalibuToast.show === 'function') {
        window.MalibuToast.show('Starter theme demo: connect this form to admin-ajax or REST.', 'info');
      }
    });
  });
})(jQuery);
