(function ($) {
  'use strict';

  window.MalibuExchange = {
    notify: function (message) {
      console.log('[Malibu Exchange]', message);
    }
  };

  $(function () {
    $('.me-button').on('click', function () {
      if (!$(this).closest('#me-rates-form').length) {
        MalibuExchange.notify('Button clicked');
      }
    });
  });
})(jQuery);
