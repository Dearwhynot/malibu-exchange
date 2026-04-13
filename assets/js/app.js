(function ($) {
  'use strict';

  window.MalibuExchange = {
    notify: function (message) {
      console.log('[Malibu Exchange]', message);
    }
  };

  // ---- Sidebar pin persistence (desktop only, width >= 1200) ----
  var SIDEBAR_PIN_KEY = 'malibu_sidebar_pinned';

  $(function () {
    // Restore pin state on page load
    if ($(window).width() >= 1200 && localStorage.getItem(SIDEBAR_PIN_KEY) === '1') {
      $('body').addClass('menu-pin').removeClass('menu-unpinned');
    }

    // Save pin state after Pages processes the click
    $(document).on('click.malibu.sidebar-pin', '[data-toggle-pin="sidebar"]', function () {
      setTimeout(function () {
        if ($('body').hasClass('menu-pin')) {
          localStorage.setItem(SIDEBAR_PIN_KEY, '1');
        } else {
          localStorage.removeItem(SIDEBAR_PIN_KEY);
        }
      }, 0);
    });

    $('.me-button').on('click', function () {
      if (!$(this).closest('#me-rates-form').length) {
        MalibuExchange.notify('Button clicked');
      }
    });
  });
})(jQuery);
