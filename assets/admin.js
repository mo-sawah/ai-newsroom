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

  var campaignTypeMeta = {
    rss: {
      badge: 'RSS',
      title: 'RSS Monitor',
      desc: 'Monitor publisher RSS feeds, remove duplicates, and group fresh items into newsroom story assignments.',
      tip: 'RSS is best when the site has clean feeds. Add one feed per line, then let the story desk group related items.'
    },
    gnews: {
      badge: 'GNEWS',
      title: 'GNews Search',
      desc: 'Search broad news coverage by topic, country, and language using the GNews API.',
      tip: 'GNews is best for broad discovery. Use a clear query and optional country/language filters.'
    },
    firecrawl: {
      badge: 'CRAWL',
      title: 'Firecrawl Site Monitor',
      desc: 'Extract fresh article links from category, listing, or newsroom pages that do not have clean RSS feeds.',
      tip: 'Firecrawl is best for pages without RSS. Add category or listing URLs, not individual article URLs.'
    },
    perplexity: {
      badge: 'RESEARCH',
      title: 'Perplexity Research',
      desc: 'Create story opportunities from a research query and its cited sources.',
      tip: 'Perplexity mode needs only a focused topic query. It is useful for finding news opportunities from wider research.'
    },
    press_release: {
      badge: 'PR',
      title: 'Press Release Monitor',
      desc: 'Monitor official announcement, PR, investor-relations, and newsroom pages or feeds.',
      tip: 'Use official source pages when possible. The writer will treat this material carefully and avoid promotional wording.'
    },
    youtube: {
      badge: 'VIDEO',
      title: 'YouTube Video Desk',
      desc: 'Find fresh YouTube videos by query and turn them into story assignments with embeds when appropriate.',
      tip: 'Use a specific YouTube query. This works well for briefings, speeches, interviews, and breaking video clips.'
    },
    manual: {
      badge: 'MANUAL',
      title: 'Manual URL Research',
      desc: 'Process exact URLs you paste manually, useful for hand-picked stories or documents.',
      tip: 'Manual mode is best when you already know which exact URLs should be turned into story assignments.'
    }
  };

  function updateCampaignSourceFields(){
    var form = $('[data-ain-campaign-form]');
    if(!form.length) return;
    var type = form.find('select[name="type"]').val() || 'rss';
    var meta = campaignTypeMeta[type] || campaignTypeMeta.rss;

    form.find('.ain-current-type-badge').text(meta.badge);
    form.find('.ain-current-type-title').text(meta.title);
    form.find('.ain-current-type-desc').text(meta.desc);
    form.find('.ain-source-mode-tip').text(meta.tip);

    form.find('[data-source-types]').each(function(){
      var el = $(this);
      var raw = (el.attr('data-source-types') || '').toLowerCase();
      var list = raw.split(/\s+/);
      var show = raw === 'all' || list.indexOf(type) !== -1;
      el.toggleClass('ain-is-hidden', !show);
    });
  }

  $(document).on('change', '[data-ain-campaign-form] select[name="type"]', updateCampaignSourceFields);
  $(updateCampaignSourceFields);

})(jQuery);
