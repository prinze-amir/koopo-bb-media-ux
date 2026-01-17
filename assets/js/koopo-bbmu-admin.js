jQuery(function($){
  var $form = $('.koopo-admin__form');
  if (!$form.length) return;

  var ajaxUrl = window.koopoBBMUAdmin ? window.koopoBBMUAdmin.ajaxUrl : '';
  var saveNonce = window.koopoBBMUAdmin ? window.koopoBBMUAdmin.saveNonce : '';
  var webpNonce = window.koopoBBMUAdmin ? window.koopoBBMUAdmin.webpNonce : '';
  var offloadNonce = window.koopoBBMUAdmin ? window.koopoBBMUAdmin.offloadNonce : '';
  var messages = window.koopoBBMUAdmin ? window.koopoBBMUAdmin.messages : {};
  var $notice = $('.koopo-admin__notice-area');
  var $status = $('.koopo-admin__save-status');
  var $webpButton = $('.koopo-admin__webp-backfill');
  var $webpProgress = $('.koopo-admin__progress').not('.koopo-admin__progress--offload');
  var $webpBar = $webpProgress.find('.koopo-admin__progress-bar');
  var $webpText = $('.koopo-admin__progress-text').not('.koopo-admin__progress-text--offload');
  var $offloadButton = $('.koopo-admin__offload-backfill');
  var $offloadProgress = $('.koopo-admin__progress--offload');
  var $offloadBar = $offloadProgress.find('.koopo-admin__progress-bar');
  var $offloadText = $('.koopo-admin__progress-text--offload');

  var $headings = $form.find('h2');
  if (!$headings.length) return;

  $headings.each(function(i){
    var $h = $(this);
    var $section = $('<div class="koopo-admin__section" data-section="section-' + i + '"></div>');
    $h.before($section);
    $section.append($h);

    var $next = $section.next();
    while ($next.length && !$next.is('h2') && !$next.is('.koopo-admin__submit')) {
      var $move = $next;
      $next = $next.next();
      $section.append($move);
    }
  });

  function activate(section){
    $('.koopo-admin__nav-item').removeClass('is-active');
    $('.koopo-admin__nav-item[data-section="' + section + '"]').addClass('is-active');
    $('.koopo-admin__section').hide();
    $('.koopo-admin__section[data-section="' + section + '"]').show();
  }

  $('.koopo-admin__nav-item').on('click', function(e){
    e.preventDefault();
    var section = $(this).data('section');
    if (!section) return;
    activate(section);
  });

  var originalLabel = $form.find('input[type="submit"], button[type="submit"]').first().val();

  $form.on('submit', function(e){
    if (!ajaxUrl || !saveNonce) return;
    e.preventDefault();

    var $button = $form.find('input[type="submit"], button[type="submit"]').first();
    if ($button.length) {
      $button.prop('disabled', true);
      $button.val(messages.saving || 'Saving…');
    }
    if ($status.length) {
      $status
        .removeClass('is-success is-error')
        .addClass('is-saving')
        .text(messages.saving || 'Saving…');
    }

    var data = $form.serializeArray();
    data.push({ name: 'action', value: 'koopo_bbmu_save_settings' });
    data.push({ name: 'nonce', value: saveNonce });

    $.post(ajaxUrl, data)
      .done(function(resp){
        if (resp && resp.success) {
          if ($notice.length) {
            $notice.html('<div class="koopo-admin__notice">' + (resp.data && resp.data.message ? resp.data.message : (messages.saved || 'Settings saved.')) + '</div>');
          }
          if ($status.length) {
            $status
              .removeClass('is-saving is-error')
              .addClass('is-success')
              .text(messages.saved || 'Settings saved.');
          }
        } else {
          if ($notice.length) {
            var msg = (resp && resp.data && resp.data.message) ? resp.data.message : (messages.error || 'Settings could not be saved.');
            $notice.html('<div class="koopo-admin__notice koopo-admin__notice--error">' + msg + '</div>');
          }
          if ($status.length) {
            var errorMsg = (resp && resp.data && resp.data.message) ? resp.data.message : (messages.error || 'Settings could not be saved.');
            $status
              .removeClass('is-saving is-success')
              .addClass('is-error')
              .text(errorMsg);
          }
        }
      })
      .fail(function(){
        if ($notice.length) {
          $notice.html('<div class="koopo-admin__notice koopo-admin__notice--error">' + (messages.error || 'Settings could not be saved.') + '</div>');
        }
        if ($status.length) {
          $status
            .removeClass('is-saving is-success')
            .addClass('is-error')
            .text(messages.error || 'Settings could not be saved.');
        }
      })
      .always(function(){
        if ($button.length) {
          $button.prop('disabled', false);
          $button.val(originalLabel || 'Save Changes');
        }
        if ($status.length) {
          setTimeout(function(){
            $status.removeClass('is-success is-error is-saving').text('');
          }, 2500);
        }
      });
  });

  activate('section-0');

  function updateWebpProgress(done, total) {
    if (!$webpBar.length || !$webpProgress.length || !$webpText.length) return;
    var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
    $webpProgress.attr('aria-hidden', 'false');
    $webpBar.css('width', percent + '%');
    $webpText.text(done + ' / ' + total + ' images processed');
  }

  function runWebpBackfill(page) {
    if (!ajaxUrl || !webpNonce) return;
    $.post(ajaxUrl, {
      action: 'koopo_bbmu_webp_backfill_step',
      nonce: webpNonce,
      paged: page
    }).done(function(resp){
      if (!resp || !resp.success) {
        $webpText.text(messages.error || 'WebP backfill failed.');
        $webpButton.prop('disabled', false);
        return;
      }
      var data = resp.data || {};
      updateWebpProgress(data.done || 0, data.total || 0);
      if (data.status === 'complete') {
        $webpText.text(messages.webpDone || 'WebP backfill complete.');
        $webpButton.prop('disabled', false);
        return;
      }
      setTimeout(function(){
        runWebpBackfill(data.next || (page + 1));
      }, 500);
    }).fail(function(){
      $webpText.text(messages.error || 'WebP backfill failed.');
      $webpButton.prop('disabled', false);
    });
  }

  $webpButton.on('click', function(){
    $webpButton.prop('disabled', true);
    $webpText.text(messages.webpStarting || 'Starting WebP backfill…');
    runWebpBackfill(1);
  });

  function updateOffloadProgress(done, total) {
    if (!$offloadBar.length || !$offloadProgress.length || !$offloadText.length) return;
    var percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
    $offloadProgress.attr('aria-hidden', 'false');
    $offloadBar.css('width', percent + '%');
    $offloadText.text(done + ' / ' + total + ' images offloaded');
  }

  function runOffloadBackfill(page) {
    if (!ajaxUrl || !offloadNonce) return;
    $.post(ajaxUrl, {
      action: 'koopo_bbmu_offload_backfill_step',
      nonce: offloadNonce,
      paged: page
    }).done(function(resp){
      if (!resp || !resp.success) {
        $offloadText.text(messages.error || 'Offload backfill failed.');
        $offloadButton.prop('disabled', false);
        return;
      }
      var data = resp.data || {};
      updateOffloadProgress(data.done || 0, data.total || 0);
      if (data.status === 'complete') {
        $offloadText.text(messages.offloadDone || 'Offload backfill complete.');
        $offloadButton.prop('disabled', false);
        return;
      }
      setTimeout(function(){
        runOffloadBackfill(data.next || (page + 1));
      }, 500);
    }).fail(function(){
      $offloadText.text(messages.error || 'Offload backfill failed.');
      $offloadButton.prop('disabled', false);
    });
  }

  $offloadButton.on('click', function(){
    $offloadButton.prop('disabled', true);
    $offloadText.text(messages.offloadStarting || 'Starting offload backfill…');
    runOffloadBackfill(1);
  });
});
