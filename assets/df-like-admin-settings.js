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
      root.querySelector('[name="df_like_label_liked"]')
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
    var button = root.querySelector('.js-df-like-copy-tag');
    var tag = root.querySelector('.js-df-like-manual-tag');
    var status = root.querySelector('.js-df-like-manual-copy-status');
    if (!button || !tag) {
      return;
    }
    button.addEventListener('click', function() {
      Admin.copyWithStatus(tag.textContent || '', status, '手動設置タグをコピーしました。', '手動設置タグをコピーできませんでした。');
    });
  };

  Admin.setupAnalyticsTagCopy = function(root) {
    var button = root.querySelector('.js-df-like-copy-analytics');
    var tag = root.querySelector('.js-df-like-analytics-tag');
    var status = root.querySelector('.js-df-like-analytics-copy-status');
    if (!button || !tag) {
      return;
    }
    button.addEventListener('click', function() {
      Admin.copyWithStatus(tag.textContent || '', status, 'コピーしました', 'コピーできませんでした');
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
