(function() {
  'use strict';

  var cache = {};
  var loading = {};
  var listeners = {};
  var attempts = 0;
  var maxAttempts = 200;
  var wrappedConfig = null;

  boot();

  function boot() {
    if (!window.ACMS || !window.ACMS.Config) {
      retryBoot();
      return;
    }

    var config = window.ACMS.Config;
    config.entryAdminConfig = config.entryAdminConfig || {};
    if (config.entryAdminConfig !== wrappedConfig || !config.entryAdminConfig.dfLikeWrapped) {
      wrapColumns(config.entryAdminConfig);
      config.entryAdminConfig.dfLikeWrapped = true;
      wrappedConfig = config.entryAdminConfig;
    }

    retryBoot();
  }

  function retryBoot() {
    attempts += 1;
    if (attempts > maxAttempts) {
      return;
    }
    window.setTimeout(boot, 25);
  }

  function wrapColumns(entryAdminConfig) {
    var originalGetColumns = entryAdminConfig.getColumns;

    entryAdminConfig.getColumns = function(columns) {
      var baseColumns = Array.isArray(columns) ? columns : [];
      var nextColumns = baseColumns;

      try {
        if (typeof originalGetColumns === 'function') {
          nextColumns = originalGetColumns(baseColumns);
        }
      } catch (error) {
        nextColumns = baseColumns;
      }

      if (!Array.isArray(nextColumns)) {
        nextColumns = baseColumns;
      }
      if (nextColumns.some(function(column) { return column && column.id === 'df-like-count'; })) {
        return nextColumns;
      }

      return nextColumns.concat([{
        id: 'df-like-count',
        label: 'いいね数',
        accessorKey: 'id',
        type: 'display',
        enableSorting: false,
        enableHiding: true,
        getValue: function(info) {
          var entryId = entryIdFromInfo(info);
          var React = getReact();
          if (!entryId || !React || typeof React.createElement !== 'function') {
            return '-';
          }
          return React.createElement(LikeCountCell, { entryId: entryId });
        },
      }]);
    };
  }

  function LikeCountCell(props) {
    var React = getReact();
    var entryId = String(props && props.entryId ? props.entryId : '');
    var initialCount = hasCache(entryId) ? cache[entryId] : '-';
    var state = React.useState(initialCount);
    var count = state[0];
    var setCount = state[1];

    React.useEffect(function() {
      if (!entryId) {
        return undefined;
      }
      var unsubscribe = subscribe(entryId, setCount);
      if (hasCache(entryId)) {
        setCount(cache[entryId]);
      } else {
        requestCount(entryId);
      }
      return unsubscribe;
    }, [entryId]);

    return React.createElement(
      'span',
      {
        className: 'df-like-entry-count',
        'data-df-like-entry-id': entryId,
      },
      count
    );
  }

  function getReact() {
    return window.ACMS &&
      window.ACMS.Library &&
      window.ACMS.Library.React
      ? window.ACMS.Library.React
      : null;
  }

  function entryIdFromInfo(info) {
    if (!info) {
        return '';
    }
    var original = info.row && info.row.original ? info.row.original : {};
    var id = original.id || original.eid || (info.row ? info.row.id : '') || (typeof info.getValue === 'function' ? info.getValue() : '');
    return /^\d+$/.test(String(id || '')) ? String(id) : '';
  }

  function subscribe(entryId, callback) {
    listeners[entryId] = listeners[entryId] || [];
    listeners[entryId].push(callback);
    return function() {
      listeners[entryId] = (listeners[entryId] || []).filter(function(listener) {
        return listener !== callback;
      });
    };
  }

  function notify(entryId) {
    (listeners[entryId] || []).forEach(function(listener) {
      try {
        listener(cache[entryId]);
      } catch (error) {
        // Non-critical enhancement.
      }
    });
  }

  function hasCache(entryId) {
    return Object.prototype.hasOwnProperty.call(cache, entryId);
  }

  function setCache(entryId, value) {
    cache[entryId] = value;
    notify(entryId);
  }

  function requestCount(entryId) {
    if (loading[entryId]) {
      return;
    }
    loading[entryId] = true;

    postJsonWithFallback(
      ['ACMS_POST_DFLikeEntryCounts', 'ACMS_POST_DF_Like_DFLikeEntryCounts'],
      function(action) {
        var formData = new FormData();
        formData.append(action, '1');
        formData.append('entry_ids[]', entryId);
        appendCsrfToken(formData);
        return formData;
      },
      'いいね数を取得できませんでした。'
    )
      .then(function(json) {
        if (json && json.status === 'success' && json.counts && json.counts[entryId] !== undefined) {
          setCache(entryId, Number(json.counts[entryId] || 0));
        } else {
          setCache(entryId, '-');
        }
      })
      .catch(function() {
        setCache(entryId, '-');
      })
      .finally(function() {
        loading[entryId] = false;
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
            throw new Error(failureMessage);
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
    if (token) {
      formData.append('formToken', token);
    }
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
})();
