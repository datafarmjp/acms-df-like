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
        if (json.liked && json.action === 'like') {
          showThanksMessage(button);
        }
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

  function showThanksMessage(button) {
    var message = (button.getAttribute('data-thanks-message') || '').trim();
    if (!message) {
      return;
    }
    removeThanksMessage();

    var bubble = document.createElement('span');
    var accent = normalizeAccent(button.getAttribute('data-thanks-accent') || 'none');
    bubble.className = 'df-like-thanks-message';
    if (accent !== 'none') {
      bubble.classList.add('df-like-thanks-message--has-accent');
      bubble.classList.add('df-like-thanks-message--' + accent);
    }
    bubble.setAttribute('role', 'status');
    bubble.setAttribute('aria-live', 'polite');

    var text = document.createElement('span');
    text.className = 'df-like-thanks-message__text';
    text.textContent = message;
    bubble.appendChild(text);
    appendThanksAccent(bubble, accent);

    document.body.appendChild(bubble);
    positionThanksMessage(button, bubble);

    activateThanksMessage(bubble);

    bubble._dfLikeTimer = window.setTimeout(function() {
      hideThanksMessage(bubble);
    }, 2400);
  }

  function activateThanksMessage(bubble) {
    var show = function() {
      if (bubble.parentElement) {
        bubble.classList.add('is-visible');
        playThanksAccent(bubble);
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
  }

  function hideThanksMessage(bubble) {
    if (!bubble || !bubble.parentElement) {
      return;
    }
    if (bubble._dfLikeTimer) {
      window.clearTimeout(bubble._dfLikeTimer);
      bubble._dfLikeTimer = null;
    }
    bubble.classList.remove('is-visible');
    bubble.classList.add('is-hiding');
    window.setTimeout(function() {
      if (bubble.parentElement) {
        bubble.parentElement.removeChild(bubble);
      }
    }, 260);
  }

  function removeThanksMessage() {
    Array.prototype.slice.call(document.querySelectorAll('.df-like-thanks-message')).forEach(function(bubble) {
      if (bubble._dfLikeTimer) {
        window.clearTimeout(bubble._dfLikeTimer);
      }
      if (bubble.parentElement) {
        bubble.parentElement.removeChild(bubble);
      }
    });
  }

  function positionThanksMessage(button, bubble) {
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
    bubble.style.setProperty('--df-like-arrow-left', arrowLeft + 'px');
  }

  function normalizeAccent(value) {
    if (value === 'fireworks') {
      return 'confetti';
    }
    return ['none', 'confetti', 'cracker', 'star', 'heart', 'thanks_face'].indexOf(value) !== -1 ? value : 'none';
  }

  function appendThanksAccent(bubble, accent) {
    if (accent === 'none') {
      return;
    }
    var holder = document.createElement('span');
    holder.className = 'df-like-thanks-message__accent';
    holder.setAttribute('aria-hidden', 'true');

    if (accent === 'confetti') {
      appendAccentPieces(holder, ['🌱', '🌼', '🍀']);
    } else if (accent === 'cracker') {
      appendAccentPieces(holder, ['🎉', '✨']);
    } else if (accent === 'star') {
      appendAccentPieces(holder, ['⭐', '🌟', '✨']);
    } else if (accent === 'heart') {
      appendAccentPieces(holder, ['💖', '💕', '✨']);
    } else if (accent === 'thanks_face') {
      appendAccentPieces(holder, ['😊', '🙏', '✨']);
    }

    bubble.appendChild(holder);
  }

  function appendAccentPieces(holder, pieces) {
    pieces.forEach(function(piece, index) {
      var span = document.createElement('span');
      span.className = 'df-like-thanks-message__accent-piece df-like-thanks-message__accent-piece--' + (index + 1);
      span.style.setProperty('--df-like-accent-index', String(index));
      span.textContent = piece;
      holder.appendChild(span);
    });
  }

  function playThanksAccent(bubble) {
    var pieces = Array.prototype.slice.call(bubble.querySelectorAll('.df-like-thanks-message__accent-piece'));
    if (!pieces.length) {
      return;
    }
    var reduceMotion = window.matchMedia
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var useWebAnimation = !reduceMotion && typeof pieces[0].animate === 'function';
    if (useWebAnimation) {
      bubble.classList.add('df-like-thanks-message--js-animated');
    }
    pieces.forEach(function(piece, index) {
      window.setTimeout(function() {
        if (piece.parentElement) {
          piece.classList.add('is-active');
          if (reduceMotion) {
            piece.style.transition = 'opacity .22s ease';
            piece.style.transform = 'none';
            piece.style.opacity = '1';
          }
          if (useWebAnimation) {
            piece.animate(thanksAccentKeyframes(bubble, index), {
              duration: 760,
              easing: 'cubic-bezier(.16, .92, .24, 1.22)',
              fill: 'forwards'
            });
          }
        }
      }, index * 90);
    });
  }

  function thanksAccentKeyframes(bubble, index) {
    if (bubble.classList.contains('df-like-thanks-message--confetti')) {
      return floatingAccentKeyframes(index, 8, 7, 5);
    }
    if (bubble.classList.contains('df-like-thanks-message--cracker')) {
      return floatingAccentKeyframes(index, 5, 8, 3);
    }
    if (bubble.classList.contains('df-like-thanks-message--star')) {
      return floatingAccentKeyframes(index, 7, 8, 3);
    }
    if (bubble.classList.contains('df-like-thanks-message--heart')) {
      return floatingAccentKeyframes(index, 6, 9, 4);
    }
    return floatingAccentKeyframes(index, 4, 7, 2);
  }

  function floatingAccentKeyframes(index, spread, lift, drift) {
    var x = (index - 1) * spread;
    return [
      { opacity: 0, transform: 'translate(' + (x * .35) + 'px, 8px) scale(.92)' },
      { opacity: 1, transform: 'translate(' + x + 'px, -' + lift + 'px) scale(1.02)', offset: .68 },
      { opacity: 1, transform: 'translate(' + (x + drift) + 'px, -' + (lift + 2) + 'px) scale(1)' }
    ];
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
