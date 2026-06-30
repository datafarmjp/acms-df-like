(function(window) {
  'use strict';

  var Admin = window.DFLikeAdmin = window.DFLikeAdmin || {};

  Admin.setupColorInputs = function(root) {
    root.querySelectorAll('input[type="color"][data-default]').forEach(function(input) {
      var stored = input.getAttribute('data-config-value') || '';
      var fallback = input.getAttribute('data-default') || '#cf3251';
      input.value = Admin.isColor(stored) ? stored : fallback;
    });
  };

  Admin.setupPlacementSelects = function(root) {
    root.querySelectorAll('.js-df-like-placement').forEach(function(select) {
      var stored = select.getAttribute('data-config-value') || '';
      var fallback = select.getAttribute('data-default') || 'none';
      var legacy = select.getAttribute('data-legacy-position') || '';
      var position = select.getAttribute('data-position') || '';
      select.value = Admin.isPlacement(stored) ? stored : Admin.legacyPlacement(legacy, position, fallback);
    });
  };

  Admin.setupIconSelects = function(root) {
    root.querySelectorAll('.js-df-like-icon').forEach(function(select) {
      var stored = select.getAttribute('data-config-value') || '';
      if (Admin.isUnexpandedValue(stored) || stored === '') {
        return;
      }
      if (Admin.isIcon(stored)) {
        select.value = stored;
      }
    });
  };

  Admin.isIcon = function(value) {
    return ['heart', 'star', 'clap', 'good', 'none'].indexOf(value || '') !== -1;
  };

  Admin.isUnexpandedValue = function(value) {
    return /\{[^}]+\}/.test(String(value || ''));
  };

  Admin.isPlacement = function(value) {
    return ['none', 'left', 'center', 'right'].indexOf(value || '') !== -1;
  };

  Admin.legacyPlacement = function(legacy, position, fallback) {
    if (position === 'top') {
      return legacy === 'before' || legacy === 'both' ? 'left' : fallback;
    }
    if (position === 'bottom') {
      return legacy === 'before' ? 'none' : (legacy === 'after' || legacy === 'both' ? 'left' : fallback);
    }
    return fallback;
  };

  Admin.isColor = function(value) {
    return /^#[0-9a-f]{6}$/i.test(value || '');
  };

  Admin.setupRadiusInputs = function(root) {
    var range = root.querySelector('.js-df-like-radius-range');
    var number = root.querySelector('.js-df-like-radius-number');
    if (!range || !number) {
      return;
    }
    var stored = number.getAttribute('data-config-value') || range.getAttribute('data-config-value') || '';
    var fallback = number.getAttribute('data-default') || '18';
    var value = Admin.clampNumber(stored, 0, 32, Admin.clampNumber(fallback, 0, 32, 18));
    range.value = String(value);
    number.value = String(value);
    range.addEventListener('input', function() {
      number.value = String(Admin.clampNumber(range.value, 0, 32, 18));
    });
    number.addEventListener('input', function() {
      var next = Admin.clampNumber(number.value, 0, 32, 18);
      range.value = String(next);
      number.value = String(next);
    });
  };

  Admin.setupAnalyticsScope = function(root) {
    var select = root.querySelector('.js-df-like-analytics-scope');
    if (!select) {
      return;
    }
    var stored = select.getAttribute('data-config-value') || '';
    select.value = ['current_with_descendants', 'current'].indexOf(stored) !== -1
      ? stored
      : (select.getAttribute('data-default') || 'current_with_descendants');
  };

  Admin.setupNotifyForms = function(root) {
    var row = root.querySelector('.js-df-like-notify-form-row');
    var toggle = root.querySelector('.js-df-like-notify-toggle');
    var select = root.querySelector('.js-df-like-notify-form-select');
    var input = root.querySelector('.js-df-like-notify-form-id');
    var fallback = root.querySelector('.js-df-like-notify-form-id-fallback');
    if (!row || !toggle || !select || !input) {
      return;
    }
    var selected = input.getAttribute('data-config-value') || select.getAttribute('data-config-value') || '';
    input.value = selected || input.value || '';
    if (fallback) {
      fallback.value = input.value || fallback.value || '';
    }
    Admin.syncNotifyFormRow(row, toggle);
    ['change', 'click', 'input'].forEach(function(eventName) {
      toggle.addEventListener(eventName, function() {
        window.setTimeout(function() {
          Admin.syncNotifyFormRow(row, toggle);
        }, 0);
      });
    });
    select.addEventListener('change', function() {
      input.value = select.value || '';
      if (fallback) {
        fallback.value = input.value;
      }
    });
    if (fallback) {
      fallback.addEventListener('input', function() {
        input.value = fallback.value || '';
        select.value = input.value || '';
      });
    }
    Admin.postJsonWithFallback(
      ['ACMS_POST_DFLikeFormOptions', 'ACMS_POST_DF_Like_DFLikeFormOptions'],
      function(action) {
        var formData = new FormData();
        formData.append(action, '1');
        Admin.appendCsrfToken(formData);
        return formData;
      },
      'フォーム一覧を取得できませんでした。'
    ).then(function(json) {
      if (!json || json.status !== 'success') {
        Admin.showNotifyFallback(select, fallback);
        return;
      }
      var forms = json.forms || [];
      if (!forms.length) {
        Admin.showNotifyFallback(select, fallback);
        return;
      }
      forms.forEach(function(form) {
        if (!form || !form.id) {
          return;
        }
        var option = document.createElement('option');
        option.value = String(form.id);
        option.textContent = '#' + form.id + ' ' + (form.name || form.code || 'form') + ' / bid:' + (form.blog_id || 0);
        select.appendChild(option);
      });
      select.value = selected || '';
    }).catch(function() {
      Admin.showNotifyFallback(select, fallback);
    });
  };

  Admin.setupPreview = function(root) {
    var preview = root.querySelector('.js-df-like-preview');
    if (!preview) {
      return;
    }

    var controls = [
      root.querySelector('[name="df_like_icon"]'),
      root.querySelector('[name="df_like_count_display"]'),
      root.querySelector('[name="df_like_color"]'),
      root.querySelector('.js-df-like-radius-range'),
      root.querySelector('[name="df_like_border_radius"]'),
      root.querySelector('[name="df_like_label_like"]'),
      root.querySelector('[name="df_like_label_liked"]'),
      root.querySelector('[name="df_like_thanks_message"]'),
      root.querySelector('[name="df_like_thanks_accent"]')
    ].filter(function(control) {
      return !!control;
    });

    var update = function() {
      Admin.updatePreview(root, preview);
    };

    controls.forEach(function(control) {
      control.addEventListener('input', update);
      control.addEventListener('change', update);
    });
    Admin.setupPreviewThanks(preview);
    update();
  };

  Admin.updatePreview = function(root, preview) {
    var iconValue = Admin.formValue(root, 'df_like_icon', 'heart');
    var countDisplay = Admin.formValue(root, 'df_like_count_display', 'show');
    var color = Admin.isColor(Admin.formValue(root, 'df_like_color', '#cf3251'))
      ? Admin.formValue(root, 'df_like_color', '#cf3251')
      : '#cf3251';
    var radius = Admin.clampNumber(Admin.formValue(root, 'df_like_border_radius', '18'), 0, 32, 18);
    var labelLike = Admin.formValue(root, 'df_like_label_like', '').trim() || 'いいね';
    var labelLiked = Admin.formValue(root, 'df_like_label_liked', '').trim() || 'いいね済み';
    var thanksMessage = Admin.formValue(root, 'df_like_thanks_message', '').trim();
    var thanksAccent = Admin.normalizePreviewThanksAccent(Admin.formValue(root, 'df_like_thanks_accent', 'none'));
    var icon = Admin.previewIcon(iconValue);
    var showCount = countDisplay !== 'hide';
    var buttons = [
      {
        button: preview.querySelector('.js-df-like-preview-like'),
        icon: preview.querySelector('.js-df-like-preview-like-icon'),
        label: preview.querySelector('.js-df-like-preview-like-label'),
        count: preview.querySelector('.js-df-like-preview-like-count'),
        text: labelLike
      },
      {
        button: preview.querySelector('.js-df-like-preview-liked'),
        icon: preview.querySelector('.js-df-like-preview-liked-icon'),
        label: preview.querySelector('.js-df-like-preview-liked-label'),
        count: preview.querySelector('.js-df-like-preview-liked-count'),
        text: labelLiked
      }
    ];

    buttons.forEach(function(item) {
      if (!item.button || !item.label) {
        return;
      }
      item.button.style.setProperty('--df-like-preview-main-color', color);
      item.button.style.setProperty('--df-like-preview-border-radius', radius + 'px');
      item.button.setAttribute('data-thanks-message', thanksMessage);
      item.button.setAttribute('data-thanks-accent', thanksAccent);
      item.label.textContent = item.text;
      if (item.icon) {
        item.icon.textContent = icon;
        item.icon.hidden = icon === '';
      }
      if (item.count) {
        item.count.hidden = !showCount;
        item.count.textContent = '12';
      }
    });
  };

  Admin.setupPreviewThanks = function(preview) {
    var likeButton = preview.querySelector('.js-df-like-preview-like');
    var likedButton = preview.querySelector('.js-df-like-preview-liked');
    if (likeButton && !likeButton._dfLikePreviewThanksReady) {
      likeButton._dfLikePreviewThanksReady = true;
      likeButton.addEventListener('click', function() {
        Admin.showPreviewThanksMessage(likeButton);
      });
    }
    if (likedButton && !likedButton._dfLikePreviewThanksReady) {
      likedButton._dfLikePreviewThanksReady = true;
      likedButton.addEventListener('click', function() {
        Admin.removePreviewThanksMessage();
      });
    }
  };

  Admin.showPreviewThanksMessage = function(button) {
    var message = (button.getAttribute('data-thanks-message') || '').trim();
    if (!message) {
      Admin.removePreviewThanksMessage();
      return;
    }
    Admin.removePreviewThanksMessage();

    var accent = Admin.normalizePreviewThanksAccent(button.getAttribute('data-thanks-accent') || 'none');
    var bubble = document.createElement('span');
    bubble.className = 'df-like-admin-thanks-preview';
    if (accent !== 'none') {
      bubble.classList.add('df-like-admin-thanks-preview--has-accent');
      bubble.classList.add('df-like-admin-thanks-preview--' + accent);
    }
    bubble.setAttribute('role', 'status');
    bubble.setAttribute('aria-live', 'polite');

    var text = document.createElement('span');
    text.className = 'df-like-admin-thanks-preview__text';
    text.textContent = message;
    bubble.appendChild(text);
    Admin.appendPreviewThanksAccent(bubble, accent);

    document.body.appendChild(bubble);
    Admin.positionPreviewThanksMessage(button, bubble);
    Admin.activatePreviewThanksMessage(bubble);
    bubble._dfLikePreviewTimer = window.setTimeout(function() {
      Admin.hidePreviewThanksMessage(bubble);
    }, 2400);
  };

  Admin.activatePreviewThanksMessage = function(bubble) {
    var show = function() {
      if (bubble.parentElement) {
        bubble.classList.add('is-visible');
        Admin.playPreviewThanksAccent(bubble);
      }
    };
    bubble.getBoundingClientRect();
    if (typeof window.requestAnimationFrame === 'function') {
      window.requestAnimationFrame(function() {
        window.requestAnimationFrame(show);
      });
      return;
    }
    window.setTimeout(show, 40);
  };

  Admin.hidePreviewThanksMessage = function(bubble) {
    if (!bubble || !bubble.parentElement) {
      return;
    }
    if (bubble._dfLikePreviewTimer) {
      window.clearTimeout(bubble._dfLikePreviewTimer);
      bubble._dfLikePreviewTimer = null;
    }
    bubble.classList.remove('is-visible');
    bubble.classList.add('is-hiding');
    window.setTimeout(function() {
      if (bubble.parentElement) {
        bubble.parentElement.removeChild(bubble);
      }
    }, 260);
  };

  Admin.removePreviewThanksMessage = function() {
    Array.prototype.slice.call(document.querySelectorAll('.df-like-admin-thanks-preview')).forEach(function(bubble) {
      if (bubble._dfLikePreviewTimer) {
        window.clearTimeout(bubble._dfLikePreviewTimer);
      }
      if (bubble.parentElement) {
        bubble.parentElement.removeChild(bubble);
      }
    });
  };

  Admin.positionPreviewThanksMessage = function(button, bubble) {
    var rect = button.getBoundingClientRect();
    var bubbleRect = bubble.getBoundingClientRect();
    var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
    var margin = 12;
    var halfWidth = bubbleRect.width / 2;
    var buttonCenter = rect.left + (rect.width / 2);
    var left = buttonCenter;
    var minLeft = margin + halfWidth;
    var maxLeft = viewportWidth ? viewportWidth - margin - halfWidth : left;
    if (viewportWidth && maxLeft >= minLeft) {
      left = Math.min(Math.max(left, minLeft), maxLeft);
    }
    var visualLeft = left - halfWidth;
    var arrowLeft = Math.min(Math.max(buttonCenter - visualLeft, 14), bubbleRect.width - 14);
    bubble.style.left = left + 'px';
    bubble.style.top = rect.top + 'px';
    bubble.style.setProperty('--df-like-preview-arrow-left', arrowLeft + 'px');
  };

  Admin.normalizePreviewThanksAccent = function(value) {
    if (value === 'fireworks') {
      return 'confetti';
    }
    return ['none', 'confetti', 'cracker', 'star', 'heart', 'thanks_face'].indexOf(value || '') !== -1 ? value : 'none';
  };

  Admin.appendPreviewThanksAccent = function(bubble, accent) {
    if (accent === 'none') {
      return;
    }
    var holder = document.createElement('span');
    holder.className = 'df-like-admin-thanks-preview__accent';
    holder.setAttribute('aria-hidden', 'true');
    if (accent === 'confetti') {
      Admin.appendPreviewAccentPieces(holder, ['🌱', '🌼', '🍀']);
    } else if (accent === 'cracker') {
      Admin.appendPreviewAccentPieces(holder, ['🎉', '✨']);
    } else if (accent === 'star') {
      Admin.appendPreviewAccentPieces(holder, ['⭐', '🌟', '✨']);
    } else if (accent === 'heart') {
      Admin.appendPreviewAccentPieces(holder, ['💖', '💕', '✨']);
    } else if (accent === 'thanks_face') {
      Admin.appendPreviewAccentPieces(holder, ['😊', '🙏', '✨']);
    }
    bubble.appendChild(holder);
  };

  Admin.appendPreviewAccentPieces = function(holder, pieces) {
    pieces.forEach(function(piece, index) {
      var span = document.createElement('span');
      span.className = 'df-like-admin-thanks-preview__accent-piece df-like-admin-thanks-preview__accent-piece--' + (index + 1);
      span.style.setProperty('--df-like-preview-accent-index', String(index));
      span.textContent = piece;
      holder.appendChild(span);
    });
  };

  Admin.playPreviewThanksAccent = function(bubble) {
    var pieces = Array.prototype.slice.call(bubble.querySelectorAll('.df-like-admin-thanks-preview__accent-piece'));
    pieces.forEach(function(piece, index) {
      window.setTimeout(function() {
        if (piece.parentElement) {
          piece.classList.add('is-active');
        }
      }, index * 90);
    });
  };

  Admin.formValue = function(root, name, fallback) {
    var input = root.querySelector('[name="' + name + '"]');
    return input ? String(input.value || '') : fallback;
  };

  Admin.previewIcon = function(value) {
    var icons = {
      none: '',
      heart: '♥',
      star: '★',
      clap: '👏',
      good: '👍'
    };
    return Object.prototype.hasOwnProperty.call(icons, value) ? icons[value] : icons.heart;
  };

  Admin.syncNotifyFormRow = function(row, toggle) {
    row.hidden = !toggle.checked;
  };

  Admin.showNotifyFallback = function(select, fallback) {
    if (select) {
      select.hidden = true;
      select.disabled = true;
    }
    if (fallback) {
      fallback.hidden = false;
      fallback.disabled = false;
    }
  };

  Admin.setupManualTagCopy = function(root) {
    var status = root.querySelector('.js-df-like-manual-copy-status');
    Admin.setupCopyButton(root, '.js-df-like-copy-tag', '.js-df-like-manual-tag', status, '手動設置タグをコピーしました。', '手動設置タグをコピーできませんでした。');
    Admin.setupCopyButton(root, '.js-df-like-copy-twig-tag', '.js-df-like-manual-twig-tag', status, 'Twig用の手動設置タグをコピーしました。', 'Twig用の手動設置タグをコピーできませんでした。');
  };

  Admin.setupAnalyticsTagCopy = function(root) {
    Admin.setupCopyButton(root, '.js-df-like-copy-analytics', '.js-df-like-analytics-tag', root.querySelector('.js-df-like-analytics-copy-status'), 'コピーしました', 'コピーできませんでした');
    Admin.setupCopyButton(root, '.js-df-like-copy-analytics-twig', '.js-df-like-analytics-twig-tag', root.querySelector('.js-df-like-analytics-twig-copy-status'), 'Twig用の解析表示タグをコピーしました。', 'Twig用の解析表示タグをコピーできませんでした。');
    Admin.setupCopyButton(root, '.js-df-like-copy-ranking', '.js-df-like-ranking-tag', root.querySelector('.js-df-like-ranking-copy-status'), 'ランキング表示タグをコピーしました。', 'ランキング表示タグをコピーできませんでした。');
    Admin.setupCopyButton(root, '.js-df-like-copy-ranking-v2', '.js-df-like-ranking-v2-tag', root.querySelector('.js-df-like-ranking-v2-copy-status'), 'Twig/V2用のランキング表示コードをコピーしました。', 'Twig/V2用のランキング表示コードをコピーできませんでした。');
  };

  Admin.setupSnippetModals = function(root) {
    var activeTrigger = null;

    root.querySelectorAll('.js-df-like-snippet-open').forEach(function(button) {
      button.addEventListener('click', function() {
        var key = button.getAttribute('data-df-like-modal') || '';
        var modal = root.querySelector('.js-df-like-snippet-modal[data-df-like-modal="' + key + '"]');
        if (!modal) {
          return;
        }
        activeTrigger = button;
        Admin.openSnippetModal(modal);
      });
    });

    root.querySelectorAll('.js-df-like-snippet-modal').forEach(function(modal) {
      modal.addEventListener('click', function(event) {
        if (event.target === modal || event.target.closest('.js-df-like-snippet-close')) {
          Admin.closeSnippetModal(modal, activeTrigger);
        }
      });
    });

    document.addEventListener('keydown', function(event) {
      if (event.key !== 'Escape') {
        return;
      }
      var modal = root.querySelector('.js-df-like-snippet-modal:not([hidden])');
      if (modal) {
        Admin.closeSnippetModal(modal, activeTrigger);
      }
    });
  };

  Admin.openSnippetModal = function(modal) {
    Admin.clearSnippetModalStatus(modal);
    modal.hidden = false;
    modal.classList.remove('out');
    modal.classList.add('in', 'display');
    var dialog = modal.querySelector('[role="dialog"]');
    if (dialog) {
      dialog.focus();
    }
  };

  Admin.closeSnippetModal = function(modal, trigger) {
    modal.classList.remove('in', 'display');
    modal.classList.add('out');
    modal.hidden = true;
    if (trigger && typeof trigger.focus === 'function') {
      trigger.focus();
    }
  };

  Admin.clearSnippetModalStatus = function(modal) {
    modal.querySelectorAll('.df-like-admin-local-status').forEach(function(status) {
      status.textContent = '';
      status.className = status.getAttribute('data-base-class') || 'df-like-admin-local-status';
      status.removeAttribute('data-base-class');
    });
  };

  Admin.setupCopyButton = function(root, buttonSelector, textSelector, status, successMessage, failureMessage) {
    var button = root.querySelector(buttonSelector);
    var tag = root.querySelector(textSelector);
    if (!button || !tag) {
      return;
    }
    button.addEventListener('click', function() {
      Admin.copyWithStatus(tag.textContent || '', status, successMessage, failureMessage);
    });
  };

  Admin.setupNotifyExampleCopy = function(root) {
    var button = root.querySelector('.js-df-like-copy-notify-example');
    var example = root.querySelector('.js-df-like-notify-example');
    var status = root.querySelector('.js-df-like-notify-copy-status');
    if (!button || !example) {
      return;
    }
    button.addEventListener('click', function() {
      Admin.copyWithStatus(example.textContent || '', status, '通知メール例文をコピーしました。', '通知メール例文をコピーできませんでした。');
    });
  };

  Admin.setupNotifyTest = function(root) {
    var button = root.querySelector('.js-df-like-notify-test');
    var status = root.querySelector('.js-df-like-notify-test-status');
    var select = root.querySelector('.js-df-like-notify-form-select');
    var input = root.querySelector('.js-df-like-notify-form-id');
    var fallback = root.querySelector('.js-df-like-notify-form-id-fallback');
    if (!button || !status) {
      return;
    }
    button.addEventListener('click', function() {
      var formId = '';
      if (select && !select.disabled && select.value) {
        formId = select.value;
      } else if (input && input.value) {
        formId = input.value;
      } else if (fallback && fallback.value) {
        formId = fallback.value;
      }
      if (!formId) {
        status.className = Admin.statusClass(status, 'danger');
        status.textContent = '通知フォームを選択してください。';
        return;
      }
      button.disabled = true;
      status.className = Admin.statusClass(status, 'muted');
      status.textContent = '通知テストを送信しています...';
      Admin.postJsonWithFallback(
        ['ACMS_POST_DFLikeNotificationTest', 'ACMS_POST_DF_Like_DFLikeNotificationTest'],
        function(action) {
          var formData = new FormData();
          formData.append(action, '1');
          formData.append('form_id', formId);
          Admin.appendCsrfToken(formData);
          return formData;
        },
        '通知テストを送信できませんでした。'
      ).then(function(json) {
        var ok = json && json.status === 'success';
        status.className = Admin.statusClass(status, ok ? 'success' : 'danger');
        status.innerHTML = Admin.notifyTestResultHtml(json, ok);
      }).catch(function(error) {
        status.className = Admin.statusClass(status, 'danger');
        status.textContent = error && error.message ? error.message : '通知テストを送信できませんでした。';
      }).finally(function() {
        button.disabled = false;
      });
    });
  };

  Admin.notifyTestResultHtml = function(json, ok) {
    if (!json) {
      return '通知テストを送信できませんでした。';
    }
    var message = json.message || (ok ? '通知テストを送信しました。' : '通知テストに失敗しました。');
    var html = '<span>' + Admin.escapeHtml(message) + '</span>';
    var diagnostics = json.diagnostics || {};
    var keys = ['form_id', 'form_code', 'form_name', 'form_blog_id', 'form_scope', 'AdminFormSend', 'AdminTo_count', 'has_from', 'AdminReplyTo_count', 'has_subject', 'has_body'];
    var rows = keys.filter(function(key) {
      return Object.prototype.hasOwnProperty.call(diagnostics, key);
    }).map(function(key) {
      return key + ': ' + diagnostics[key];
    });
    if (rows.length) {
      html += '<details class="df-like-admin-error-detail"><summary title="詳細">詳細</summary><pre>' + Admin.escapeHtml(rows.join('\n')) + '</pre></details>';
    }
    return html;
  };

  Admin.copyWithStatus = function(text, status, successMessage, failureMessage) {
    Admin.copyText(text).then(function() {
      if (status) {
        status.className = Admin.statusClass(status, 'success');
        status.textContent = successMessage;
      }
    }).catch(function() {
      if (status) {
        status.className = Admin.statusClass(status, 'danger');
        status.textContent = failureMessage;
      }
    });
  };

  Admin.statusClass = function(element, type) {
    var base = element.getAttribute('data-base-class') || element.className.replace(/\s*acms-admin-text-(success|danger|muted)\b/g, '');
    element.setAttribute('data-base-class', base);
    return base + ' acms-admin-text-' + (type || 'muted');
  };

  Admin.copyText = function(text) {
    return Admin.copyTextFallback(text).catch(function() {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text);
      }
      throw new Error('copy failed');
    });
  };

  Admin.copyTextFallback = function(text) {
    return new Promise(function(resolve, reject) {
      var textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.style.position = 'fixed';
      textarea.style.left = '-9999px';
      textarea.setAttribute('readonly', 'readonly');
      document.body.appendChild(textarea);
      textarea.focus();
      textarea.select();
      try {
        document.execCommand('copy') ? resolve() : reject(new Error('copy failed'));
      } catch (error) {
        reject(error);
      } finally {
        document.body.removeChild(textarea);
      }
    });
  };

  Admin.clampNumber = function(value, min, max, fallback) {
    var number = parseInt(value, 10);
    if (isNaN(number)) {
      number = fallback;
    }
    return Math.max(min, Math.min(max, number));
  };

  Admin.setupFeatureToggle = function(toggle, hidden) {
    if (!toggle || !hidden) {
      return;
    }
    toggle.checked = Admin.isEnabled(toggle.getAttribute('data-config-value'));
    Admin.syncFeature(toggle, hidden);
    toggle.addEventListener('change', function() {
      Admin.syncFeature(toggle, hidden);
    });
  };

  Admin.syncFeature = function(toggle, hidden) {
    hidden.value = toggle.checked ? 'enabled' : 'disabled';
  };

  Admin.isEnabled = function(value) {
    var normalized = String(value || '').trim().toLowerCase();
    return normalized === 'enabled' || normalized === 'on' || normalized === '1' || normalized === 'true';
  };
})(window);
