/* Pylon Admin UI v2.0 — Toast, AJAX, Validation, Animations */

(function ($) {
  'use strict';

  // ─── Toast System ───
  window.pylonToast = function (title, message, type) {
    type = type || 'info';
    var icons = { success: '✓', error: '✗', warning: '⚠', info: 'ℹ' };
    var container = document.getElementById('pylon-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'pylon-toast-container';
      container.className = 'pylon-toast-container';
      document.body.appendChild(container);
    }
    var toast = document.createElement('div');
    toast.className = 'pylon-toast pylon-toast-' + type;
    toast.innerHTML =
      '<span class="pylon-toast-icon">' + (icons[type] || 'ℹ') + '</span>' +
      '<div class="pylon-toast-content">' +
        '<div class="pylon-toast-title">' + title + '</div>' +
        (message ? '<div class="pylon-toast-message">' + message + '</div>' : '') +
      '</div>' +
      '<button class="pylon-toast-close">&times;</button>';
    container.appendChild(toast);
    var closeBtn = toast.querySelector('.pylon-toast-close');
    closeBtn.addEventListener('click', function () { removeToast(toast); });
    setTimeout(function () { removeToast(toast); }, 4000);
  };

  function removeToast(toast) {
    if (!toast || toast.classList.contains('pylon-toast-leaving')) return;
    toast.classList.add('pylon-toast-leaving');
    setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 300);
  }

  // ─── Loading overlay ───
  window.pylonLoading = function (show) {
    var overlay = document.getElementById('pylon-loading');
    if (show) {
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'pylon-loading';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.3);z-index:99998;display:flex;align-items:center;justify-content:center;';
        overlay.innerHTML = '<div class="pylon-spinner pylon-spinner-lg" style="border-top-color:#fff;border-color:rgba(255,255,255,0.3)"></div>';
        document.body.appendChild(overlay);
      }
      overlay.style.display = 'flex';
    } else {
      if (overlay) overlay.style.display = 'none';
    }
  };

  // ─── Form Validation ───
  window.pylonValidate = function (formOrSelector) {
    var form = formOrSelector instanceof jQuery ? formOrSelector[0] : (typeof formOrSelector === 'string' ? document.querySelector(formOrSelector) : formOrSelector);
    if (!form) return true;
    var valid = true;
    var inputs = form.querySelectorAll('[required]');
    inputs.forEach(function (input) {
      var group = input.closest('.pylon-form-group');
      if (!input.value.trim()) {
        valid = false;
        if (group) group.classList.add('has-error');
        input.classList.add('error');
      } else {
        if (group) group.classList.remove('has-error');
        input.classList.remove('error');
      }
    });
    return valid;
  };

  // ─── Clear validation errors on input ───
  $(document).on('input change', '.pylon-input, .pylon-select, .pylon-textarea', function () {
    var group = this.closest('.pylon-form-group');
    if (group) group.classList.remove('has-error');
    this.classList.remove('error');
  });

  // ─── AJAX Helper ───
  window.pylonAjax = function (action, data, options) {
    options = options || {};
    var dfd = $.Deferred();
    $.post(pylonAdmin.ajaxUrl, $.extend({ action: action, _ajax_nonce: pylonAdmin.nonce }, data || {}), function (resp) {
      if (resp.success) {
        if (options.toast !== false) pylonToast('Success', resp.data && resp.data.message ? resp.data.message : 'Action completed.', 'success');
        dfd.resolve(resp.data);
      } else {
        if (options.toast !== false) pylonToast('Error', resp.data && resp.data.message ? resp.data.message : 'An error occurred.', 'error');
        dfd.reject(resp.data);
      }
    }, 'json').fail(function (jqXHR) {
      if (options.toast !== false) pylonToast('Error', 'Network error: ' + (jqXHR.statusText || 'request failed'), 'error');
      dfd.reject();
    });
    return dfd.promise();
  };

  // ─── Confirmation Dialog ───
  window.pylonConfirm = function (message) {
    return new Promise(function (resolve) {
      if (window.confirm(message || 'Are you sure?')) resolve(true);
      else resolve(false);
    });
  };

  // ─── Auto-hide admin notices ───
  $(document).on('click', '.pylon-notice-dismiss', function () {
    $(this).closest('.pylon-notice').fadeOut(300);
  });

  // ─── Tab System ───
  $(document).on('click', '.pylon-tab', function () {
    var tabs = $(this).closest('.pylon-tabs');
    if (!tabs.length) return;
    tabs.find('.pylon-tab').removeClass('active');
    $(this).addClass('active');
    var target = $(this).data('tab');
    tabs.siblings('.pylon-tab-content').removeClass('active');
    $('#' + target).addClass('active');
  });

  // ─── data-pylon-toggle: show/hide elements ───
  $(document).on('click', '[data-pylon-toggle]', function () {
    var target = $('#' + $(this).data('pylon-toggle'));
    if (target.length) target.slideToggle(200);
  });

  // ─── data-pylon-ajax: click to fire AJAX ───
  $(document).on('click', '[data-pylon-ajax]', function () {
    var $btn = $(this);
    var action = $btn.data('pylon-ajax');
    var data = $btn.data('pylon-data') || {};
    var target = $btn.data('pylon-target');
    var reload = $btn.data('pylon-reload');
    data.action = action;
    if (!data._ajax_nonce && !data._wpnonce) data._ajax_nonce = pylonAdmin.nonce;
    $btn.prop('disabled', true);
    var origText = $btn.html();
    $btn.html('<span class="pylon-spinner pylon-spinner-sm"></span>');
    $.post(pylonAdmin.ajaxUrl, data, function (resp) {
      if (resp.success) {
        if (target) { $(target).removeClass('pylon-hidden').html(resp.data && resp.data.content ? resp.data.content : (resp.data.message || '')); }
        else { pylonToast('Success', resp.data && resp.data.message ? resp.data.message : 'Done.', 'success'); }
        if (reload) { setTimeout(function () { location.reload(); }, reload === true ? 800 : parseInt(reload)); }
        else if (!target) { setTimeout(function () { location.reload(); }, 1200); }
      } else {
        if (target) { $(target).removeClass('pylon-hidden').html('<div class="pylon-notice pylon-notice-danger">' + (resp.data && resp.data.message ? resp.data.message : 'Request failed.') + '</div>'); }
        else { pylonToast('Error', resp.data && resp.data.message ? resp.data.message : 'Request failed.', 'error'); }
      }
      if (!reload) { $btn.prop('disabled', false).html(origText); }
    }, 'json').fail(function () {
      if (target) { $(target).removeClass('pylon-hidden').html('<div class="pylon-notice pylon-notice-danger">Network error.</div>'); }
      else { pylonToast('Error', 'Network error.', 'error'); }
      $btn.prop('disabled', false).html(origText);
    });
  });

  // ─── data-pylon-form: submit via AJAX ───
  $(document).on('submit', '[data-pylon-form]', function (e) {
    e.preventDefault();
    var $form = $(this);
    var action = $form.data('pylon-form');
    var reload = $form.data('pylon-reload');
    var data = {};
    $form.serializeArray().forEach(function (field) { data[field.name] = field.value; });
    data.action = action;
    data._ajax_nonce = pylonAdmin.nonce;
    var $btn = $form.find('[type="submit"]');
    $btn.prop('disabled', true);
    var origText = $btn.html();
    $btn.html('<span class="pylon-spinner pylon-spinner-sm"></span>');
    $.post(pylonAdmin.ajaxUrl, data, function (resp) {
      if (resp.success) {
        pylonToast('Success', resp.data && resp.data.message ? resp.data.message : 'Saved.', 'success');
        if (reload !== false) { setTimeout(function () { location.reload(); }, 800); }
      } else {
        pylonToast('Error', resp.data && resp.data.message ? resp.data.message : 'Save failed.', 'error');
        $btn.prop('disabled', false).html(origText);
      }
    }, 'json').fail(function () {
      pylonToast('Error', 'Network error.', 'error');
      $btn.prop('disabled', false).html(origText);
    });
  });

  // ─── Toggle switch enhancement ───
  $(document).on('change', '.pylon-toggle input[type="checkbox"]', function () {
    var $cb = $(this);
    var label = $cb.closest('.pylon-toggle').find('.pylon-toggle-label');
    if (label.length) {
      label.text($cb.prop('checked') ? label.data('on') || 'Enabled' : label.data('off') || 'Disabled');
    }
    var target = $cb.data('pylon-toggle-target');
    if (target) {
      $('#' + target)[$cb.prop('checked') ? 'slideDown' : 'slideUp'](200);
    }
  });

  // Click on the visual track/slider toggles the hidden checkbox (settings page uses <div> not <label>).
  $(document).on('click', '.pylon-toggle-track, .pylon-toggle-slider', function () {
    var $toggle = $(this).closest('.pylon-toggle');
    if ($toggle.is('label')) return; // native <label> behavior already toggles the checkbox
    var $cb = $toggle.find('input[type="checkbox"]');
    if ($cb.length) {
      $cb.prop('checked', !$cb.prop('checked')).trigger('change');
    }
  });

  // ─── Table row hover animation ───
  $(document).on('mouseenter', '.pylon-table tbody tr', function () {
    $(this).find('td').css('background', 'var(--pylon-gray-50)');
  }).on('mouseleave', '.pylon-table tbody tr', function () {
    $(this).find('td').css('background', '');
  });

  // ─── Inline Bulk Editor (AJAX save) ───
  $(document).on('change', '[data-pylon-bulk-save]', function () {
    var $input = $(this);
    var data = {
      action: 'pylon_bulk_save',
      _ajax_nonce: pylonAdmin.nonce,
      post_id: $input.data('post-id'),
      field: $input.data('pylon-field'),
      value: $input.val(),
    };
    $.post(pylonAdmin.ajaxUrl, data, function (resp) {
      if (resp.success) {
        pylonToast('Saved', 'Field updated.', 'success');
        $input.removeClass('error');
      } else {
        pylonToast('Error', resp.data && resp.data.message ? resp.data.message : 'Save failed.', 'error');
        $input.addClass('error');
      }
    });
  });

  // ─── 404 Redirect Suggestions ───
  $(document).on('click', '[data-pylon-suggest]', function () {
    var $btn = $(this);
    var url = $btn.data('pylon-suggest');
    var target = $('#' + $btn.data('target'));
    $btn.prop('disabled', true).html('<span class="pylon-spinner pylon-spinner-sm"></span>');
    $.post(pylonAdmin.ajaxUrl, {
      action: 'pylon_suggest_404_redirect',
      url: url,
      _ajax_nonce: pylonAdmin.nonce,
    }, function (resp) {
      if (resp.success && resp.data.html) {
        target.html(resp.data.html);
      } else {
        target.html('<span style="font-size:11px;color:var(--pylon-gray-400);">No suggestions found.</span>');
      }
      $btn.prop('disabled', false).text('Suggest');
    }).fail(function () {
      target.html('<span style="font-size:11px;color:var(--pylon-danger);">Error loading suggestions.</span>');
      $btn.prop('disabled', false).text('Suggest');
    });
  });

  // ─── Fade in cards on page load ───
  $(function () {
    $('.pylon-card, .pylon-status-card, .pylon-usage-card').each(function (i) {
      var $el = $(this);
      $el.css({ opacity: 0, transform: 'translateY(10px)' });
      setTimeout(function () {
        $el.css({ transition: 'all 0.3s ease', opacity: 1, transform: 'translateY(0)' });
      }, i * 80);
    });
  });

  // ─── Confirm dialogs for dangerous actions ───
  $(document).on('click', '[data-confirm]', function (e) {
    if (!window.confirm($(this).data('confirm') || 'Are you sure?')) {
      e.preventDefault();
      return false;
    }
  });

  // ═══════════════════════════════════════════════════════
  // Pylon SEO Metabox — Live Preview + Real-Time Score
  // ═══════════════════════════════════════════════════════

  var pylonEditorContent = '';

  function pylonGetEditorContent() {
    if (typeof wp !== 'undefined' && wp.data && wp.data.select && wp.data.select('core/editor')) {
      return wp.data.select('core/editor').getEditedPostContent() || '';
    }
    var $content = $('#content');
    return $content.length ? $content.val() : '';
  }

  function pylonGetWordCount(html) {
    var text = html.replace(/<[^>]+>/g, ' ').replace(/&[^;]+;/g, ' ').replace(/\s+/g, ' ').trim();
    if (!text) return 0;
    return text.split(/\s+/).length;
  }

  function pylonGetHeadings(html) {
    var m = html.match(/<h[1-6][^>]*>/gi);
    return m ? m.length : 0;
  }

  function pylonGetImages(html) {
    var m = html.match(/<img[^>]+>/gi);
    return m ? m.length : 0;
  }

  // ─── Score: JS formula for instant feedback, AJAX for authoritative ───
  var _pylonScoreTimer = null;

  function pylonRecalcScore() {
    if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) return;
    clearTimeout(_pylonScoreTimer);
    _pylonScoreTimer = setTimeout(function () {
      var postId = ($('#post_ID').length) ? $('#post_ID').val() : 0;
      if (!postId) return;
      var title = $('#pylon_title').val() || '';
      var desc = $('#pylon_description').val() || '';
      var kw = $('#pylon_focus_keyword').val() || '';
      var canonical = $('#pylon_canonical').val() || '';
      var ogImage = $('#pylon_og_image').val() || '';
      var schemaType = $('select[name="pylon_schema_type"]').val() || '';
      var noindex = $('input[name="pylon_noindex"]').is(':checked') ? '1' : '';
      var content = pylonGetEditorContent();
      $.post(ajaxurl, {
        action: 'pylon_recalculate_engine_score',
        post_id: postId,
        pylon_title: title,
        pylon_description: desc,
        pylon_focus_keyword: kw,
        pylon_canonical: canonical,
        pylon_og_image: ogImage,
        pylon_schema_type: schemaType,
        pylon_noindex: noindex,
        content: content,
        _ajax_nonce: pylonAdmin.nonce
      }, function (res) {
        if (res.success && res.data && typeof res.data.overall !== 'undefined') {
          window.pylonServerScore = parseInt(res.data.overall) || 0;
          if (window.pylonPageBuilderData) window.pylonPageBuilderData.engine_overall = window.pylonServerScore;
          pylonUpdateGauge(window.pylonServerScore);
          pylonUpdateScore(window.pylonServerScore);
        }
      });
    }, 800);
  }

  function pylonCalcScore() {
    var title = $('#pylon_title').val().trim() || $('#pylon_title').attr('placeholder') || '';
    var desc = $('#pylon_description').val().trim();
    var kw = $('#pylon_focus_keyword').val().trim();
    var canonical = $('#pylon_canonical').val().trim();
    var ogImage = $('#pylon_og_image').val().trim();
    var schemaType = $('select[name="pylon_schema_type"]').val() || '';

    var content = pylonGetEditorContent();
    var pb = window.pylonPageBuilderData || {};

    var wordCount = pb.words > 0 ? pb.words : pylonGetWordCount(content);
    var headings = (typeof pb.headings !== 'undefined' && pb.headings > 0) ? pb.headings : pylonGetHeadings(content);
    var images = (typeof pb.images !== 'undefined' && pb.images > 0) ? pb.images : pylonGetImages(content);

    var contentForChecks = pb.content_text || content;
    var contentLower = contentForChecks.toLowerCase().replace(/<[^>]+>/g, ' ');
    var hasList = (typeof pb.has_list !== 'undefined') ? pb.has_list : (/<[uo]l/i.test(content));
    var hasTable = (typeof pb.has_table !== 'undefined') ? pb.has_table : (/<table/i.test(content));
    var firstSentence = contentForChecks.replace(/<[^>]+>/g, ' ').replace(/&[^;]+;/g, ' ').trim().substring(0, 200);
    var hasQA = (contentForChecks.indexOf('?') !== -1) && headings > 0;

    var slug = '';
    if ($('#editable-post-name').length) { slug = $('#editable-post-name').text().trim(); }
    else if ($('#post_name').length) { slug = $('#post_name').val() || ''; }

    var keywords = kw ? kw.split(',').map(function(s){ return s.trim().toLowerCase(); }).filter(function(s){ return s; }) : [];
    var kwSet = keywords.length > 0;
    var kwInTitle = false, kwInDesc = false, kwInContent = false, kwInSlug = false;
    for (var ki = 0; ki < keywords.length; ki++) {
      var k = keywords[ki];
      if (!kwInTitle && title.toLowerCase().indexOf(k) !== -1) kwInTitle = true;
      if (!kwInDesc && desc.toLowerCase().indexOf(k) !== -1) kwInDesc = true;
      if (!kwInContent && contentLower.indexOf(k) !== -1) kwInContent = true;
      if (!kwInSlug && slug.toLowerCase().indexOf(k) !== -1) kwInSlug = true;
    }
    var kwPts = 0;
    if (kwSet) {
      kwPts += 5;
      if (kwInTitle) kwPts += 5;
      if (kwInDesc) kwPts += 4;
      if (kwInContent) kwPts += 4;
      if (kwInSlug) kwPts += 2;
    }

    var google = 0;
    if (title.length >= 30 && title.length <= 60) google += 15;
    else if (title) google += 8;
    if (desc.length >= 120 && desc.length <= 160) google += 15;
    else if (desc) google += 7;
    if (wordCount >= 300) google += 10;
    if (wordCount >= 1000) google += 5;
    if (headings >= 3) google += 10;
    if (images > 0) google += 10;
    else google += 5;
    if (schemaType) google += 10;
    if (canonical) google += 5;
    if (ogImage) google += 5;
    if (hasList || hasTable) google += 5;
    google += kwPts;

    var bing = Math.min(100, google + (schemaType ? 5 : -5) + (images >= 2 ? 5 : 0));

    var chatgpt = 0;
    if (wordCount >= 500) chatgpt += 15;
    if (wordCount >= 1500) chatgpt += 10;
    if (/^[A-Z].+\./.test(firstSentence)) chatgpt += 15;
    if (hasQA) chatgpt += 10;
    if (hasList) chatgpt += 10;
    if (headings >= 5) chatgpt += 10;
    if (schemaType && ['Article', 'FAQPage', 'HowTo'].indexOf(schemaType) !== -1) chatgpt += 15;
    if (/\d{4}/.test(contentLower)) chatgpt += 5;
    if (hasTable) chatgpt += 10;
    chatgpt += kwPts;

    var perplexity = 0;
    if (wordCount >= 800) perplexity += 20;
    if (/\[\d+\]|<sup>|(University|Research|Study|According)/i.test(contentForChecks)) perplexity += 15;
    if (hasList) perplexity += 10;
    if (hasQA) perplexity += 10;
    if (headings >= 4) perplexity += 10;
    if (schemaType) perplexity += 15;
    if (images >= 1) perplexity += 5;
    if (/\d{4}/.test(contentLower)) perplexity += 5;
    if (/definition|meaning|what is|how to/i.test(title)) perplexity += 10;
    perplexity += kwPts;

    var gemini = Math.min(100, Math.floor((google + bing) / 2) + (images >= 2 ? 10 : 0) + (ogImage ? 5 : 0));

    var claude = 0;
    if (wordCount >= 1000) claude += 20;
    if (headings >= 6) claude += 15;
    if (hasList && hasTable) claude += 10;
    if (/nuance|trade-off|however|contrast|comparison|pros and cons/i.test(contentForChecks)) claude += 15;
    if (schemaType) claude += 10;
    if (/expert|professional|years of experience/i.test(contentForChecks)) claude += 10;
    if (hasQA) claude += 10;
    claude += kwPts;

    var scores = [
      Math.min(100, google), Math.min(100, bing), Math.min(100, chatgpt),
      Math.min(100, perplexity), Math.min(100, gemini), Math.min(100, claude)
    ];
    var overall = Math.floor(scores.reduce(function(a, b) { return a + b; }, 0) / scores.length);
    return Math.min(100, Math.max(0, overall));
  }

  function pylonUpdateScore(serverScore) {
    var score = pylonCalcScore();
    var $num = $('#pylon-score-num');
    var $label = $('#pylon-score-label');
    var $arc = $('#pylon-score-arc');

    $num.text(score);

    var label, color;
    if (score >= 80) { label = 'Good'; color = '#22c55e'; }
    else if (score >= 50) { label = 'Ok'; color = '#f59e0b'; }
    else if (score >= 1) { label = 'Poor'; color = '#ef4444'; }
    else { label = 'N/A'; color = '#9ca3af'; }

    $label.text(label);
    $arc.css({ stroke: color, strokeDasharray: (score / 100 * 100) + ', 100' });
  }

  // Estimate pixel width of a string (approximate for Google SERP)
  function pylonEstimatePixels(text) {
    if (!text) return 0;
    var px = 0;
    for (var i = 0; i < text.length; i++) {
      var c = text.charAt(i);
      // Approximate pixel widths for Google SERP font (Arial 13px)
      if (c === 'W' || c === 'w' || c === 'M' || c === 'm') px += 10;
      else if (c === 'I' || c === 'i' || c === 'l' || c === 't' || c === '!' || c === '|') px += 5;
      else if (c === 'f' || c === 'r' || c === 's' || c === 'j') px += 6;
      else if (c === ' ' || c === '.' || c === "'") px += 4;
      else if (c >= 'A' && c <= 'Z') px += 9;
      else if (c >= 'a' && c <= 'z') px += 8;
      else if (c >= '0' && c <= '9') px += 8;
      else px += 8;
    }
    return Math.round(px);
  }

  function pylonUpdatePreviews() {
    var title = $('#pylon_title').val().trim() || $('#pylon_title').attr('placeholder') || '';
    var desc = $('#pylon_description').val().trim() || $('#pylon_description').attr('placeholder') || '';

    var ogTitle = $('#pylon_og_title').val().trim() || title;
    var ogDesc = $('#pylon_og_description').val().trim() || desc;
    var twTitle = $('#pylon_twitter_title').val().trim() || ogTitle;
    var twDesc = $('#pylon_twitter_description').val().trim() || ogDesc;

    // Pixel width estimation for Google SERP
    var titlePx = pylonEstimatePixels(title);
    var descPx = pylonEstimatePixels(desc);
    var titlePxOk = titlePx <= 600;
    var descPxOk = descPx <= 920;

    // Google preview (truncate at 600px title, 920px desc, then also character limits as fallback)
    var titleMax = title;
    if (titlePx > 600) {
      while (pylonEstimatePixels(titleMax) > 600 && titleMax.length > 0) {
        titleMax = titleMax.substring(0, titleMax.length - 1);
      }
    } else if (title.length > 60) {
      titleMax = title.substring(0, 60);
    }
    $('#pylon-gp-title').text(titleMax);

    var descMax = desc;
    if (descPx > 920) {
      while (pylonEstimatePixels(descMax) > 920 && descMax.length > 0) {
        descMax = descMax.substring(0, descMax.length - 1);
      }
    } else if (desc.length > 160) {
      descMax = desc.substring(0, 160);
    }
    $('#pylon-gp-desc').text(descMax);

    // Show pixel width warnings
    var $pixelInfo = $('.pylon-pixel-info');
    var warnings = [];
    if (!titlePxOk) {
      warnings.push('Title: ~' + titlePx + 'px (max 600px) — will be truncated in SERP');
    }
    if (!descPxOk) {
      warnings.push('Description: ~' + descPx + 'px (max 920px) — will be truncated in SERP');
    }
    if (warnings.length > 0) {
      $pixelInfo.show();
      if (warnings[0]) $('#pylon-pixel-title-warn').text(warnings[0]).css('color', titlePxOk ? '#16a34a' : '#dc2626');
      if (warnings[1]) $('#pylon-pixel-desc-warn').text(warnings[1]).css('color', descPxOk ? '#16a34a' : '#dc2626');
      if (!warnings[1]) $('#pylon-pixel-desc-warn').text('');
    } else {
      $pixelInfo.hide();
    }

    // OG preview
    $('#pylon-og-title').text(ogTitle.substring(0, 70));
    $('#pylon-og-desc').text(ogDesc.substring(0, 120));
    var ogImg = $('#pylon_og_image').val().trim() || $('#pylon_og_image').attr('placeholder') || '';
    var $ogImgEl = $('#pylon-og-img');
    if (ogImg) {
      $ogImgEl.empty().append($('<img>').attr('src', ogImg).attr('alt', ''));
    } else {
      $ogImgEl.html('<span>No image</span>');
    }

    // Twitter preview
    $('#pylon-tw-title').text(twTitle.substring(0, 70));
    $('#pylon-tw-desc').text(twDesc.substring(0, 120));
    var twImg = $('#pylon_twitter_image').val().trim() || $('#pylon_twitter_image').attr('placeholder') || '';
    var $twImgEl = $('#pylon-tw-img');
    if (twImg) {
      $twImgEl.empty().append($('<img>').attr('src', twImg).attr('alt', ''));
    } else {
      $twImgEl.html('<span>No image</span>');
    }
  }

  var pylonCheckImpacts = {
    keyword: 'high', kw_in_title: 'high', kw_in_desc: 'medium',
    kw_in_content: 'medium', kw_in_slug: 'low'
  };

  function pylonSetCheck(id, pass, note) {
    var $item = $('[data-check="' + id + '"]');
    if (!$item.length) return;
    var impact = pylonCheckImpacts[id] || '';
    $item.removeClass('ok no impact-high impact-medium impact-low');
    $item.addClass(pass ? 'ok' : 'no');
    if (impact) $item.addClass('impact-' + impact);
    var $note = $item.find('.pylon-adash-i-note');
    if (note) { $note.text(note).show(); }
    else { $note.hide(); }
  }

  function pylonUpdateGauge(score) {
    var $arc = $('#pylon-adash-arc');
    var circumference = 106.8;
    var offset = (score / 100) * circumference;
    var color = score >= 80 ? '#22c55e' : score >= 50 ? '#f59e0b' : score >= 1 ? '#ef4444' : '#9ca3af';
    $arc.css({ strokeDasharray: offset + ', ' + circumference, stroke: color });
    $('#pylon-adash-num').text(score);
  }

  function pylonUpdateAnalysis() {
    var kw = $('#pylon_focus_keyword').val().trim();
    var title = $('#pylon_title').val().trim() || $('#pylon_title').attr('placeholder') || '';
    var desc = $('#pylon_description').val().trim() || $('#pylon_description').attr('placeholder') || '';
    var content = pylonGetEditorContent();
    var slug = '';
    if ($('#editable-post-name').length) { slug = $('#editable-post-name').text().trim(); }
    else if ($('#post_name').length) { slug = $('#post_name').val() || ''; }

    // For page-builder pages (Elementor, etc.) the editor #content is empty.
    // Use server-side computed analysis data when available.
    var usePbData = !content && window.pylonPageBuilderData && window.pylonPageBuilderData.words > 0;

    var wordCount = usePbData ? window.pylonPageBuilderData.words : pylonGetWordCount(content);
    var headings = usePbData ? window.pylonPageBuilderData.headings : pylonGetHeadings(content);
    var images = usePbData ? window.pylonPageBuilderData.images : pylonGetImages(content);
    var contentText = usePbData ? '' : content.replace(/<[^>]+>/g, ' ').replace(/&[^;]+;/g, ' ');

    var keywords = kw ? kw.split(',').map(function (s) { return s.trim().toLowerCase(); }).filter(function (s) { return s; }) : [];
    var kwInTitle = false, kwInDesc = false, kwInContent = false, kwInSlug = false;
    for (var ki = 0; ki < keywords.length; ki++) {
      var k = keywords[ki];
      if (!kwInTitle && title.toLowerCase().indexOf(k) !== -1) kwInTitle = true;
      if (!kwInDesc && desc.toLowerCase().indexOf(k) !== -1) kwInDesc = true;
      if (!kwInContent && contentText.toLowerCase().indexOf(k) !== -1) kwInContent = true;
      if (!kwInSlug && slug.toLowerCase().indexOf(k) !== -1) kwInSlug = true;
    }

    var canonical = $('#pylon_canonical').val().trim();
    var noindex = $('input[name="pylon_noindex"]').is(':checked');

    var checks = {
      keyword: !!kw,
      kw_in_title: kwInTitle,
      kw_in_desc: kwInDesc,
      kw_in_content: kwInContent,
      kw_in_slug: kwInSlug,
      title_len: title.length >= 10 && title.length <= 70,
      desc_len: desc.length >= 50 && desc.length <= 160,
      content_words: wordCount >= 300,
      headings: headings >= 1,
      images: images >= 1,
      canonical: !!canonical,
      noindex: !noindex,
    };

    // Update each check
    pylonSetCheck('keyword', checks.keyword, checks.keyword ? '' : 'Add a focus keyword');
    pylonSetCheck('kw_in_title', checks.kw_in_title, checks.kw_in_title ? 'Found: ' + kw : 'Add keyword to title');
    pylonSetCheck('kw_in_desc', checks.kw_in_desc, checks.kw_in_desc ? 'Found: ' + kw : 'Add keyword to description');
    pylonSetCheck('kw_in_content', checks.kw_in_content, checks.kw_in_content ? 'Found in body text' : 'Use keyword in body text');
    pylonSetCheck('kw_in_slug', checks.kw_in_slug, checks.kw_in_slug ? 'Found: ' + slug : 'Add keyword to URL slug');
    pylonSetCheck('title_len', checks.title_len, title.length + ' / 10-70 chars');
    pylonSetCheck('desc_len', checks.desc_len, desc.length + ' / 50-160 chars');
    pylonSetCheck('content_words', checks.content_words, wordCount + ' words');
    pylonSetCheck('headings', checks.headings, headings >= 1 ? '' : 'Add headings');
    pylonSetCheck('images', checks.images, images >= 1 ? '' : 'Add images');
    pylonSetCheck('canonical', checks.canonical, checks.canonical ? '' : 'Not set');
    pylonSetCheck('noindex', checks.noindex, checks.noindex ? 'Disabled (noindex)' : '');

    // Update pass/fail counts
    var passCount = 0;
    var totalChecks = 0;
    for (var k in checks) { totalChecks++; if (checks[k]) passCount++; }
    var failCount = totalChecks - passCount;
    $('#pylon-adash-num').closest('.pylon-adash').find('.pylon-adash-stat.ok .pylon-adash-stat-val').text(passCount);
    $('#pylon-adash-num').closest('.pylon-adash').find('.pylon-adash-stat.no .pylon-adash-stat-val').text(failCount);

    // Always use live JS calc for instant feedback while typing.
    var gaugeScore = pylonCalcScore();
    pylonUpdateGauge(gaugeScore);

    // Update category scores
    var kwScore = (checks.keyword ? 1 : 0) + (checks.kw_in_title ? 1 : 0) + (checks.kw_in_desc ? 1 : 0) + (checks.kw_in_content ? 1 : 0) + (checks.kw_in_slug ? 1 : 0);
    $('#pylon-cat-keyword-score').text(kwScore + '/5');
    $('#pylon-cat-keyword-fill').css('width', (kwScore / 5 * 100) + '%');

    var contentScore = (checks.title_len ? 1 : 0) + (checks.desc_len ? 1 : 0) + (checks.content_words ? 1 : 0) + (checks.headings ? 1 : 0) + (checks.images ? 1 : 0);
    $('#pylon-cat-content-score').text(contentScore + '/5');
    $('#pylon-cat-content-fill').css('width', (contentScore / 5 * 100) + '%');

    var techScore = ($('#pylon_canonical').val().trim() ? 1 : 0) + (!$('input[name="pylon_noindex"]').is(':checked') ? 1 : 0);
    $('#pylon-cat-tech-score').text(techScore + '/2');
    $('#pylon-cat-tech-fill').css('width', (techScore / 2 * 100) + '%');
  }

  function pylonUpdateAll() {
    pylonUpdateScore();
    pylonUpdatePreviews();
    pylonUpdateAnalysis();
    pylonRecalcScore();
    $('#pylon-kw-tags .pylon-tag').each(function () { pylonColorKeywordTag($(this)); });
  }

  // ─── Metabox tab switching ───
  $(document).on('click', '.pylon-meta-tab', function () {
    var tab = $(this).data('tab');
    $('.pylon-meta-tab').removeClass('active');
    $(this).addClass('active');
    $('.pylon-meta-panel').removeClass('active');
    $('#pylon-panel-' + tab).addClass('active');
  });

  // ─── Live updates on input ───
  $(document).on('input', '#pylon_title, #pylon_description, #pylon_focus_keyword, #pylon_og_title, #pylon_og_description, #pylon_twitter_title, #pylon_twitter_description, #pylon_canonical', function () {
    pylonUpdateAll();
  });

  $(document).on('change', 'input[name="pylon_noindex"], input[name="pylon_nofollow"]', function () {
    pylonUpdateAll();
  });

  // ─── Media button (open WP media library) ───
  $(document).on('click', '.pylon-media-btn', function (e) {
    e.preventDefault();
    var target = $(this).data('target');
    var frame = wp.media({
      title: 'Select Image',
      button: { text: 'Use this image' },
      multiple: false,
      library: { type: 'image' }
    });
    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      $('#' + target).val(attachment.url).trigger('input');
    });
    frame.open();
  });

  // ─── Poll editor content for analysis (Gutenberg) ───
  setInterval(function () {
    var currentContent = pylonGetEditorContent();
    if (currentContent !== pylonEditorContent) {
      pylonEditorContent = currentContent;
      pylonUpdateAll();
    }
  }, 2000);

  // ─── Initial update on page load + force meta box open ───
  $(function () {
    // Seed server score from inline data (available after inline scripts run).
    var pb = window.pylonPageBuilderData;
    if (pb && pb.engine_overall > 0) {
      window.pylonServerScore = pb.engine_overall;
    }
    if ($('#pylon_meta_box').length) {
      // Force meta box open
      var $box = $('#pylon_meta_box');
      if ($box.hasClass('closed')) {
        $box.removeClass('closed');
        $box.find('.inside').css('display', 'block');
      }
      pylonEditorContent = pylonGetEditorContent();
      setTimeout(pylonUpdateAll, 500);
    }
  });

  // ─── Keyword tag input ───
  function pylonColorKeywordTag($tag) {
    var kw = $tag.clone().children().remove().end().text().trim().toLowerCase();
    if (!kw) return;
    var title = ($('#pylon_title').val() || $('#pylon_title').attr('placeholder') || '').toLowerCase();
    var desc = ($('#pylon_description').val() || $('#pylon_description').attr('placeholder') || '').toLowerCase();
    var content = pylonGetEditorContent().replace(/<[^>]+>/g, ' ').replace(/&[^;]+;/g, ' ').toLowerCase();
    var slug = ($('#editable-post-name').length ? $('#editable-post-name').text().trim() : ($('#post_name').length ? $('#post_name').val() : '')).toLowerCase();
    $tag.removeClass('kw-in-title kw-in-desc kw-in-content kw-in-slug kw-missing');
    if (title.indexOf(kw) !== -1) $tag.addClass('kw-in-title');
    else if (desc.indexOf(kw) !== -1) $tag.addClass('kw-in-desc');
    else if (content.indexOf(kw) !== -1) $tag.addClass('kw-in-content');
    else if (slug.indexOf(kw) !== -1) $tag.addClass('kw-in-slug');
    else $tag.addClass('kw-missing');
  }

  function pylonSyncKeywords() {
    var tags = [];
    $('#pylon-kw-tags .pylon-tag').each(function () {
      var text = $(this).clone().children().remove().end().text().trim();
      if (text) tags.push(text);
      pylonColorKeywordTag($(this));
    });
    $('#pylon_focus_keyword').val(tags.join(', '));
    var count = tags.length;
    var $wrapper = $('#pylon-kw-wrapper');
    var limit = parseInt($wrapper.data('limit')) || 999;
    $('#pylon-kw-count').html(count + ' / ' + (limit >= 999 ? '&infin;' : limit));
    if (count > 0) {
      $('#pylon-kw-tags').closest('.pylon-meta-field').find('.pylon-meta-field-label').hide();
    }
    pylonUpdateAll();
  }
  $(document).on('keydown', '.pylon-kw-input', function (e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      var val = $(this).val().trim();
      if (!val) return;
      var $wrapper = $('#pylon-kw-wrapper');
      var limit = parseInt($wrapper.data('limit')) || 999;
      var current = $('#pylon-kw-tags .pylon-tag').length;
      if (current >= limit) {
        pylonToast('Limit reached', 'Keyword limit reached.', 'warning');
        return;
      }
      var $tag = $('<span class="pylon-tag">' + $('<span>').text(val).html() + '<button type="button" class="pylon-tag-remove" aria-label="Remove">&times;</button></span>');
      $('#pylon-kw-tags').append($tag);
      $(this).val('');
      pylonSyncKeywords();
    }
  });
  $(document).on('click', '.pylon-tag-remove', function () {
    $(this).closest('.pylon-tag').remove();
    pylonSyncKeywords();
  });

  // ─── Override char counters ───
  function pylonUpdateCharCounter(el) {
    var $el = $(el);
    var max = parseInt($el.data('pylon-maxlength')) || 0;
    var counterId = $el.data('pylon-counter');
    var current = $el.val().length;
    if (counterId) {
      var $counter = $('#' + counterId);
      $counter.text(current + ' / ' + max);
      $counter.removeClass('warning danger');
      if (current > max) {
        $counter.addClass('danger');
      } else if (current > max * 0.85) {
        $counter.addClass('warning');
      }
      $el.css('border-color', current > max ? 'var(--pylon-danger)' : '');
    }
  }

  $(document).on('input', '[data-pylon-counter]', function () {
    pylonUpdateCharCounter(this);
  });

  // Init counters on page load for pre-filled values
  $('[data-pylon-counter]').each(function () {
    pylonUpdateCharCounter(this);
  });

  // ─── Init score with server value on page load ───
  if (window.pylonPageBuilderData && window.pylonPageBuilderData.engine_overall > 0) {
    window.pylonServerScore = window.pylonPageBuilderData.engine_overall;
    pylonUpdateScore(window.pylonServerScore);
  }

})(jQuery);
