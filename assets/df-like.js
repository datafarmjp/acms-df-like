(function() {
  'use strict';

  if (window.dfLikeInitialized) {
    return;
  }
  window.dfLikeInitialized = true;

  document.addEventListener('click', function(event) {
    var button = event.target.closest('.js-df-like-button');
    if (!button) {
      return;
    }
    event.preventDefault();
    toggle(button);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', syncInitialButtons);
  } else {
    syncInitialButtons();
  }

  function syncInitialButtons() {
    groupedButtons().forEach(function(group) {
      postJsonWithFallback(
        ['ACMS_POST_DFLikeStatus', 'ACMS_POST_DF_Like_DFLikeStatus'],
        function(action) {
          var formData = new FormData();
          var button = group[0];
          formData.append(action, '1');
          formData.append('object_type', button.getAttribute('data-object-type') || 'entry');
          formData.append('object_id', button.getAttribute('data-object-id') || '');
          formData.append('blog_id', button.getAttribute('data-blog-id') || '');
          formData.append('entry_id', button.getAttribute('data-entry-id') || '');
          appendCsrfToken(formData);
          return formData;
        },
        'いいね状態を確認できませんでした。'
      )
        .then(function(json) {
          if (json && json.status === 'success') {
            updateButtons(group[0], json);
          }
        })
        .catch(function(error) {
          if (window.console && console.warn) {
            console.warn(error && error.message ? error.message : error);
          }
        });
    });
  }

  function toggle(button) {
    if (button.disabled) {
      return;
    }
    var buttons = relatedButtons(button);
    setButtonsDisabled(buttons, true);

    postJsonWithFallback(
      ['ACMS_POST_DFLikeToggle', 'ACMS_POST_DF_Like_DFLikeToggle'],
      function(action) {
        var formData = new FormData();
        formData.append(action, '1');
        formData.append('object_type', button.getAttribute('data-object-type') || 'entry');
        formData.append('object_id', button.getAttribute('data-object-id') || '');
        formData.append('blog_id', button.getAttribute('data-blog-id') || '');
        formData.append('entry_id', button.getAttribute('data-entry-id') || '');
        appendCsrfToken(formData);
        return formData;
      },
      'いいねを保存できませんでした。'
    )
      .then(function(json) {
        if (!json || json.status !== 'success') {
          throw new Error((json && json.message) || 'いいねを保存できませんでした。');
        }
        updateButtons(button, json);
      })
      .catch(function(error) {
        button.setAttribute('data-error', error && error.message ? error.message : 'error');
        if (window.console && console.warn) {
          console.warn(error && error.message ? error.message : error);
        }
      })
      .finally(function() {
        setButtonsDisabled(buttons, false);
      });
  }

  function postJsonWithFallback(actions, buildFormData, failureMessage) {
    return tryAction(0);

    function tryAction(index) {
      var action = actions[index];
      if (!action) {
        return Promise.reject(new Error(failureMessage));
      }
      return fetch(window.location.href, {
        method: 'POST',
        body: buildFormData(action),
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'X-Csrf-Token': getCsrfToken(),
        },
      })
        .then(function(response) {
          if (!response.ok) {
            throw new Error(failureMessage);
          }
          return response.text();
        })
        .then(function(text) {
          try {
            return text ? JSON.parse(text) : {};
          } catch (error) {
            throw new Error(isHtmlResponsePreview(text)
              ? 'POSTが認識されずHTMLが返りました。extension/acms/POST の互換POST配置を確認してください。'
              : failureMessage);
          }
        })
        .catch(function(error) {
          if (index + 1 < actions.length) {
            return tryAction(index + 1);
          }
          throw error;
        });
    }
  }

  function appendCsrfToken(formData) {
    var token = getCsrfToken();
    formData.append('formToken', token || '');
  }

  function getCsrfToken() {
    if (window.csrfToken) {
      return window.csrfToken;
    }
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.content) {
      return meta.content;
    }
    var input = document.querySelector('input[name="formToken"]');
    return input && input.value ? input.value : '';
  }

  function labelText(button, state) {
    var attr = state === 'liked' ? 'data-label-liked' : 'data-label-like';
    var fallback = state === 'liked' ? 'いいね済み' : 'いいね';
    return button.getAttribute(attr) || fallback;
  }

  function updateButtons(sourceButton, json) {
    relatedButtons(sourceButton).forEach(function(button) {
      button.classList.toggle('is-liked', !!json.liked);
      button.setAttribute('aria-pressed', json.liked ? 'true' : 'false');
      button.removeAttribute('data-error');
      var label = button.querySelector('.df-like-button__label');
      var count = button.querySelector('.js-df-like-count');
      if (label) {
        label.textContent = json.liked ? labelText(button, 'liked') : labelText(button, 'like');
      }
      if (count && button.getAttribute('data-count-display') !== 'hide') {
        var nextCount = Number(json.count || 0);
        count.textContent = String(nextCount);
        button.classList.toggle('df-like-button--count-zero', nextCount <= 0);
      }
    });
  }

  function relatedButtons(sourceButton) {
    var selector = '.js-df-like-button[data-object-type="' + cssEscape(sourceButton.getAttribute('data-object-type') || 'entry') + '"][data-object-id="' + cssEscape(sourceButton.getAttribute('data-object-id') || '') + '"]';
    return Array.prototype.slice.call(document.querySelectorAll(selector));
  }

  function groupedButtons() {
    var groups = {};
    Array.prototype.slice.call(document.querySelectorAll('.js-df-like-button')).forEach(function(button) {
      var key = (button.getAttribute('data-object-type') || 'entry') + ':' + (button.getAttribute('data-object-id') || '');
      if (!groups[key]) {
        groups[key] = [];
      }
      groups[key].push(button);
    });
    return Object.keys(groups).map(function(key) {
      return groups[key];
    });
  }

  function setButtonsDisabled(buttons, disabled) {
    buttons.forEach(function(button) {
      button.disabled = disabled;
      button.classList.toggle('is-loading', disabled);
      button.setAttribute('aria-busy', disabled ? 'true' : 'false');
    });
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return String(value).replace(/["\\]/g, '\\$&');
  }

  function isHtmlResponsePreview(text) {
    var value = String(text || '').trim().toLowerCase();
    return value.indexOf('<!doctype html') === 0 || value.indexOf('<html') === 0;
  }
})();
