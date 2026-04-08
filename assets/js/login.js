(function ($) {
  'use strict';

  function syncGroupState($input) {
    var $group = $input.closest('.form-group-default');

    if (!$group.length) {
      return;
    }

    $group.toggleClass('focused', $.trim($input.val()).length > 0 || $input.is(':focus'));
  }

  function refreshCaptcha($button) {
    var $captcha = $button.closest('.mc-captcha');

    if (!$captcha.length || $button.prop('disabled')) {
      return;
    }

    $button.prop('disabled', true);

    $.post(malibuLogin.ajaxUrl, {
      action: 'mc_captcha_new'
    }).done(function (response) {
      if (!response || !response.success || !response.data) {
        return;
      }

      $captcha.find('.mc-captcha__question').text(response.data.question + ' = ?');
      $captcha.find('input[name="mc_captcha_token"]').val(response.data.token);
      $captcha.find('input[name="mc_captcha_answer"]').val('').trigger('focus');
    }).always(function () {
      $button.prop('disabled', false);
    });
  }

  $(function () {
    var $form = $('#malibu-login-form');

    if (!$form.length) {
      return;
    }

    $form.find('.form-control').each(function () {
      syncGroupState($(this));
    });

    $form.on('focus blur input change', '.form-control', function () {
      syncGroupState($(this));
    });

    $form.on('click', '.mc-captcha-refresh', function (event) {
      event.preventDefault();
      refreshCaptcha($(this));
    });
  });
})(jQuery);
