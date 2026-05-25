(function($){
  function setButtonState(btn, state, text){
    btn.removeClass('ain-is-working ain-is-done ain-is-error');
    if(state) btn.addClass(state);
    btn.prop('disabled', state === 'ain-is-working');
    btn.html(text);
  }

  function escapeHtml(text){
    return $('<div>').text(text || '').html();
  }

  function pollStatus(btn, type, id, original, attempts){
    attempts = attempts || 0;
    if(attempts > 180){
      setButtonState(btn, 'ain-is-error', '<span class="dashicons dashicons-warning"></span>');
      alert('Still running or timed out. Please refresh the queue page to check the final status.');
      return;
    }
    $.post(AIN.ajaxurl, { action: 'ain_get_action_status', type: type, id: id, nonce: AIN.nonce }, function(resp){
      if(!resp || !resp.success){
        setButtonState(btn, 'ain-is-error', '<span class="dashicons dashicons-warning"></span>');
        return;
      }
      var data = resp.data || {};
      var state = data.state || '';
      if(state === 'complete'){
        setButtonState(btn, 'ain-is-done', '<span class="dashicons dashicons-yes"></span>');
        if(type === 'item'){
          var row = $('#ain-row-' + id);
          if(data.queue_status_label){
            row.find('.ain-row-status').html('<span class="ain-status ain-status-'+escapeHtml(data.queue_status)+'">'+escapeHtml(data.queue_status_label)+'</span>' + (data.edit_url ? '<br><a href="'+escapeHtml(data.edit_url)+'">Edit post</a>' : ''));
          }
          if(data.quality_score !== undefined){
            row.find('.ain-score span').css('width', Math.max(0, Math.min(100, parseInt(data.quality_score, 10) || 0)) + '%');
            row.find('.ain-score').next('small').text((parseInt(data.quality_score, 10) || 0) + '/100');
          }
        }
        setTimeout(function(){ window.location.reload(); }, 1200);
      } else if(state === 'error'){
        setButtonState(btn, 'ain-is-error', '<span class="dashicons dashicons-warning"></span>');
        alert(data.message || 'The background action failed.');
      } else {
        setButtonState(btn, 'ain-is-working', '<span class="ain-spinner"></span>');
        setTimeout(function(){ pollStatus(btn, type, id, original, attempts + 1); }, 4000);
      }
    }).fail(function(){
      setButtonState(btn, 'ain-is-working', '<span class="ain-spinner"></span>');
      setTimeout(function(){ pollStatus(btn, type, id, original, attempts + 1); }, 5000);
    });
  }

  function runAction(btn){
    var action = btn.data('action');
    var id = btn.data('id');
    var confirmText = btn.data('confirm');
    if(confirmText && !window.confirm(confirmText)) return;
    var original = btn.html();
    setButtonState(btn, 'ain-is-working', '<span class="ain-spinner"></span>');
    $.post(AIN.ajaxurl, { action: action, id: id, nonce: AIN.nonce }, function(resp){
      if(resp && resp.success){
        var data = resp.data || {};
        if(typeof data === 'object' && data.background){
          setButtonState(btn, 'ain-is-working', '<span class="ain-spinner"></span>');
          pollStatus(btn, data.type, data.id, original, 0);
          return;
        }
        if(action === 'ain_delete_item') $('#ain-row-' + id).fadeOut(200, function(){ $(this).remove(); });
        else if(action === 'ain_delete_campaign') $('#ain-campaign-row-' + id).fadeOut(200, function(){ $(this).remove(); });
        else window.location.reload();
      } else {
        alert('Error: ' + (resp && resp.data ? resp.data : 'Request failed.'));
        setButtonState(btn, '', original);
      }
    }).fail(function(){
      alert('Request failed. The action may still be running in the background. Refresh the page in a minute to check.');
      setButtonState(btn, '', original);
    });
  }
  $(document).on('click', '.ain-js-action', function(e){ e.preventDefault(); runAction($(this)); });

  $(document).on('click', '.ain-source-toggle', function(e){
    e.preventDefault();
    var target = $('#' + $(this).data('target'));
    if(!target.length) return;
    target.slideToggle(160);
    $(this).toggleClass('is-open');
  });
  $(document).on('click', '.ain-tabs button', function(){
    var tab = $(this).data('tab');
    $('.ain-tabs button').removeClass('is-active');
    $(this).addClass('is-active');
    $('.ain-tab-panel').removeClass('is-active');
    $('.ain-tab-panel[data-panel="'+tab+'"]').addClass('is-active');
  });
})(jQuery);
