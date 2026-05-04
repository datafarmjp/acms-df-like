(function(window) {
  'use strict';

  var Admin = window.DFLikeAdmin = window.DFLikeAdmin || {};

  Admin.appendFilters = function(formData, start, end, keyword) {
    if (start && start.value) {
      formData.append('start_date', start.value);
    }
    if (end && end.value) {
      formData.append('end_date', end.value);
    }
    if (keyword && keyword.value && keyword.value.trim()) {
      formData.append('keyword', keyword.value.trim());
    }
  };

  Admin.downloadCsv = function(type, start, end, keyword) {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = window.location.href;
    form.style.display = 'none';
    Admin.addInput(form, type === 'history' ? 'ACMS_POST_DFLikeCsvDownload' : 'ACMS_POST_DFLikeCsvDownload', '1');
    Admin.addInput(form, 'type', type);
    if (start && start.value) {
      Admin.addInput(form, 'start_date', start.value);
    }
    if (end && end.value) {
      Admin.addInput(form, 'end_date', end.value);
    }
    if (keyword && keyword.value && keyword.value.trim()) {
      Admin.addInput(form, 'keyword', keyword.value.trim());
    }
    var token = Admin.getCsrfToken();
    if (token) {
      Admin.addInput(form, 'formToken', token);
      Admin.addInput(form, 'csrf_token', token);
    }
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
  };

  Admin.addInput = function(form, name, value) {
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  };

  Admin.postJsonWithFallback = function(actions, buildFormData, failureMessage) {
    function tryAction(index) {
      var action = actions[index];
      if (!action) {
        throw new Error(failureMessage);
      }
      return fetch(window.location.href, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: buildFormData(action),
      }).then(function(response) {
        return response.text();
      }).then(function(text) {
        var json = null;
        try {
          json = JSON.parse(text);
        } catch (error) {
          if (index + 1 < actions.length) {
            return tryAction(index + 1);
          }
          if (Admin.isHtmlResponsePreview(text)) {
            throw new Error(failureMessage + '（ログイン状態またはPOSTルーティングを確認してください）');
          }
          throw new Error(failureMessage);
        }
        if (json && json.status === 'failure' && index + 1 < actions.length && /POST|ルーティング|権限/.test(String(json.message || ''))) {
          return tryAction(index + 1);
        }
        return json;
      });
    }
    return tryAction(0);
  };

  Admin.appendCsrfToken = function(formData) {
    var token = Admin.getCsrfToken();
    if (token) {
      formData.append('formToken', token);
      formData.append('csrf_token', token);
    }
  };

  Admin.getCsrfToken = function() {
    if (window.csrfToken) {
      return window.csrfToken;
    }
    var meta = document.querySelector('meta[name="csrf-token"], meta[name="csrf_token"]');
    if (meta && meta.getAttribute('content')) {
      return meta.getAttribute('content');
    }
    var input = document.querySelector('input[name="csrf_token"], input[name="formToken"], input[name="token"]');
    return input ? input.value : '';
  };

  Admin.isHtmlResponsePreview = function(text) {
    return /<!doctype html/i.test(text) || /<html[\s>]/i.test(text) || /<form[^>]+login/i.test(text);
  };
})(window);
