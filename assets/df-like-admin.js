(function() {
  'use strict';

  document.addEventListener('DOMContentLoaded', function() {
    var root = document.querySelector('.js-df-like-admin');
    if (!root) {
      return;
    }

    var status = root.querySelector('.js-df-like-status');
    var errorStatus = root.querySelector('.js-df-like-error-status');
    var filter = root.querySelector('.js-df-like-filter');
    var start = root.querySelector('.js-df-like-start');
    var end = root.querySelector('.js-df-like-end');
    var keywordForm = root.querySelector('.js-df-like-keyword-form');
    var keyword = root.querySelector('.js-df-like-keyword');
    var deleteLogs = root.querySelector('.js-df-like-delete-logs');
    var deleteErrors = root.querySelector('.js-df-like-delete-errors');
    var rebuildCurrent = root.querySelector('.js-df-like-rebuild-current');
    var autoInsertToggle = root.querySelector('.js-df-like-auto-insert-toggle');
    var autoInsertValue = root.querySelector('.js-df-like-auto-insert-value');
    var notifyToggle = root.querySelector('.js-df-like-notify-toggle');
    var notifyValue = root.querySelector('.js-df-like-notify-value');
    var hookWarning = root.querySelector('.js-df-like-hook-warning');
    var importForm = root.querySelector('.js-df-like-import-form');
    var importResult = root.querySelector('.js-df-like-import-result');
    var historyPage = 1;
    var historyPerPage = 50;

    var Admin = window.DFLikeAdmin || {};
    Admin.setupColorInputs(root);
    Admin.setupPlacementSelects(root);
    Admin.setupIconSelects(root);
    Admin.setupRadiusInputs(root);
    Admin.setupAnalyticsScope(root);
    Admin.setupManualTagCopy(root);
    Admin.setupAnalyticsTagCopy(root);
    Admin.setupNotifyExampleCopy(root);
    Admin.setupFeatureToggle(autoInsertToggle, autoInsertValue);
    Admin.setupFeatureToggle(notifyToggle, notifyValue);
    Admin.setupNotifyForms(root);
    Admin.setupPreview(root);
    setupUpdateNotice(root);
    root.querySelectorAll('.js-df-like-preset').forEach(function(button) {
      button.addEventListener('click', function() {
        applyPreset(button.getAttribute('data-preset'), start, end);
        setActivePreset(button.getAttribute('data-preset'));
        historyPage = 1;
        load();
      });
    });
    root.querySelectorAll('.js-df-like-csv').forEach(function(button) {
      button.addEventListener('click', function() {
        Admin.downloadCsv(button.getAttribute('data-type') || 'ranking', start, end, keyword);
      });
    });
    filter.addEventListener('submit', function(event) {
      event.preventDefault();
      historyPage = 1;
      load();
    });
    if (keywordForm) {
      keywordForm.addEventListener('submit', function(event) {
        event.preventDefault();
        historyPage = 1;
        load();
      });
    }
    [start, end].forEach(function(input) {
      if (input) {
        input.addEventListener('change', function() {
          setActivePreset('');
        });
      }
    });
    deleteLogs.addEventListener('click', function() {
      deleteCurrentLogs();
    });
    if (deleteErrors) {
      deleteErrors.addEventListener('click', function() {
        deleteErrorLogs();
      });
    }
    if (rebuildCurrent) {
      rebuildCurrent.addEventListener('click', function() {
        rebuildCurrentLikes();
      });
    }
    root.addEventListener('click', function(event) {
      var button = event.target.closest('.js-df-like-delete-like');
      if (button && root.contains(button)) {
        deleteHistoryLike(button);
      }
      var pager = event.target.closest('.js-df-like-history-page');
      if (pager && root.contains(pager)) {
        var page = parseInt(pager.getAttribute('data-page'), 10);
        if (page > 0 && page !== historyPage) {
          historyPage = page;
          load();
        }
      }
    });
    root.querySelectorAll('.js-df-like-import-dry-run, .js-df-like-import-run').forEach(function(button) {
      button.addEventListener('click', function() {
        importCsv(button.classList.contains('js-df-like-import-dry-run'));
      });
    });

    applyPreset('7d', start, end);
    setActivePreset('7d');
    load();

    function load(silent) {
      if (!silent) {
        setStatus('', '');
      }
      Admin.postJsonWithFallback(
        ['ACMS_POST_DFLikeAdminData', 'ACMS_POST_DF_Like_DFLikeAdminData'],
        function(action) {
          var formData = new FormData();
          formData.append(action, '1');
          formData.append('limit', '50');
          formData.append('history_page', String(historyPage));
          formData.append('history_per_page', String(historyPerPage));
          Admin.appendFilters(formData, start, end, keyword);
          Admin.appendCsrfToken(formData);
          return formData;
        },
        'いいね解析を取得できませんでした。'
      )
        .then(function(json) {
          if (!json || json.status !== 'success') {
            throw new Error((json && json.message) || 'いいね解析を取得できませんでした。');
          }
          Admin.updateHookWarning(hookWarning, json.hook_enabled);
          Admin.render(root, json.data || {});
          if (!silent) {
            setStatus('', '');
          }
        })
        .catch(function(error) {
          setStatus(error && error.message ? error.message : 'いいね解析を取得できませんでした。', 'danger');
        });
    }

    function deleteCurrentLogs() {
      if (!start.value && !end.value) {
        setStatus('履歴を削除する期間を指定してください。', 'danger');
        return;
      }
      if (!window.confirm('指定期間内のいいね履歴を削除します。現在のいいね数は維持されます。')) {
        return;
      }
      deleteLogs.disabled = true;
      setStatus('履歴を削除しています。', '');
      Admin.postJsonWithFallback(
        ['ACMS_POST_DFLikeDeleteLogs', 'ACMS_POST_DF_Like_DFLikeDeleteLogs'],
        function(action) {
          var formData = new FormData();
          formData.append(action, '1');
          formData.append('confirm', '1');
          Admin.appendFilters(formData, start, end);
          Admin.appendCsrfToken(formData);
          return formData;
        },
        '履歴を削除できませんでした。'
      )
        .then(function(json) {
          if (!json || json.status !== 'success') {
            throw new Error((json && json.message) || '履歴を削除できませんでした。');
          }
          setStatus('履歴を ' + Number(json.deleted_count || 0) + ' 件削除しました。', 'success');
          historyPage = 1;
          load(true);
        })
        .catch(function(error) {
          setStatus(error && error.message ? error.message : '履歴を削除できませんでした。', 'danger');
        })
        .finally(function() {
          deleteLogs.disabled = false;
        });
    }

    function deleteHistoryLike(button) {
      var logId = button.getAttribute('data-log-id') || '';
      if (!logId) {
        setStatus('削除するいいね履歴が指定されていません。', 'danger');
        return;
      }
      if (!window.confirm('この履歴のいいねを削除します。現在のいいね数も減ります。')) {
        return;
      }
      button.disabled = true;
      setStatus('いいねを削除しています。', '');
      Admin.postJsonWithFallback(
        ['ACMS_POST_DFLikeDeleteLike', 'ACMS_POST_DF_Like_DFLikeDeleteLike'],
        function(action) {
          var formData = new FormData();
          formData.append(action, '1');
          formData.append('confirm', '1');
          formData.append('log_id', logId);
          Admin.appendCsrfToken(formData);
          return formData;
        },
        'いいねを削除できませんでした。'
      )
        .then(function(json) {
          if (!json || json.status !== 'success') {
            throw new Error((json && json.message) || 'いいねを削除できませんでした。');
          }
          setStatus('いいねを削除しました。', 'success');
          load(true);
        })
        .catch(function(error) {
          setStatus(error && error.message ? error.message : 'いいねを削除できませんでした。', 'danger');
        })
        .finally(function() {
          button.disabled = false;
        });
    }

    function rebuildCurrentLikes() {
      if (!window.confirm('いいね履歴から現在のいいね状態を再構築します。履歴ログは増えません。')) {
        return;
      }
      rebuildCurrent.disabled = true;
      setStatus('現在いいねを再構築しています。', '');
      Admin.postJsonWithFallback(
        ['ACMS_POST_DFLikeRebuildCurrentLikes', 'ACMS_POST_DF_Like_DFLikeRebuildCurrentLikes'],
        function(action) {
          var formData = new FormData();
          formData.append(action, '1');
          formData.append('confirm', '1');
          Admin.appendCsrfToken(formData);
          return formData;
        },
        '現在いいねを再構築できませんでした。'
      )
        .then(function(json) {
          if (!json || json.status !== 'success') {
            throw new Error((json && json.message) || '現在いいねを再構築できませんでした。');
          }
          setStatus(
            '現在いいねを再構築しました。作成 ' + Number(json.created || 0) +
            ' 件 / 削除 ' + Number(json.deleted || 0) +
            ' 件 / スキップ ' + Number(json.skipped || 0) +
            ' 件 / 失敗 ' + Number(json.failed || 0) + ' 件',
            Number(json.failed || 0) > 0 ? 'danger' : 'success'
          );
          historyPage = 1;
          load(true);
        })
        .catch(function(error) {
          setStatus(error && error.message ? error.message : '現在いいねを再構築できませんでした。', 'danger');
        })
        .finally(function() {
          rebuildCurrent.disabled = false;
        });
    }

    function deleteErrorLogs() {
      if (!window.confirm('最近のエラー履歴を削除します。いいね履歴や現在のいいね数は削除されません。')) {
        return;
      }
      deleteErrors.disabled = true;
      setStatus('', 'muted');
      setErrorStatus('エラー履歴を削除しています。', '');
      Admin.postJsonWithFallback(
        ['ACMS_POST_DFLikeDeleteErrors', 'ACMS_POST_DF_Like_DFLikeDeleteErrors'],
        function(action) {
          var formData = new FormData();
          formData.append(action, '1');
          formData.append('confirm', '1');
          Admin.appendCsrfToken(formData);
          return formData;
        },
        'エラー履歴を削除できませんでした。'
      )
        .then(function(json) {
          if (!json || json.status !== 'success') {
            throw new Error((json && json.message) || 'エラー履歴を削除できませんでした。');
          }
          setStatus('', 'muted');
          setErrorStatus('エラー履歴を ' + Number(json.deleted_count || 0) + ' 件削除しました。', 'success');
          load(true);
        })
        .catch(function(error) {
          setErrorStatus(error && error.message ? error.message : 'エラー履歴を削除できませんでした。', 'danger');
        })
        .finally(function() {
          deleteErrors.disabled = false;
        });
    }

    function setStatus(text, type) {
      if (!status) {
        return;
      }
      status.className = 'js-df-like-status acms-admin-text-' + (type || 'muted');
      status.textContent = text;
    }

    function setErrorStatus(text, type) {
      if (!errorStatus) {
        return;
      }
      errorStatus.className = 'df-like-admin-local-status js-df-like-error-status acms-admin-text-' + (type || 'muted');
      errorStatus.textContent = text;
    }

    function importCsv(dryRun) {
      if (!importForm || !importResult) {
        return;
      }
      var file = importForm.querySelector('.js-df-like-import-file');
      if (!file || !file.files || !file.files.length) {
        Admin.renderImportResult(importResult, {status: 'failure', message: 'CSVファイルを指定してください。'});
        return;
      }
      if (!dryRun && !window.confirm('CSVの内容をいいね履歴にインポートします。重複はスキップします。')) {
        return;
      }
      Admin.renderImportMessage(importResult, dryRun ? 'CSVを検証しています。' : 'CSVをインポートしています。', '');
      Admin.postJsonWithFallback(
        ['ACMS_POST_DFLikeImportCsv', 'ACMS_POST_DF_Like_DFLikeImportCsv'],
        function(action) {
          var formData = new FormData(importForm);
          formData.append(action, '1');
          formData.append('dry_run', dryRun ? '1' : '0');
          Admin.appendCsrfToken(formData);
          return formData;
        },
        'CSVインポートに失敗しました。'
      )
        .then(function(json) {
          Admin.renderImportResult(importResult, json || {});
          if (!json || json.status !== 'success') {
            throw new Error((json && json.message) || 'CSVインポートに失敗しました。');
          }
          if (!dryRun) {
            file.value = '';
            historyPage = 1;
            load(true);
          }
        })
        .catch(function(error) {
          Admin.renderImportResult(importResult, {status: 'failure', message: error && error.message ? error.message : 'CSVインポートに失敗しました。'});
        });
    }
  });

  function applyPreset(preset, start, end) {
    var today = new Date();
    var from = new Date(today.getTime());
    end.value = formatDate(today);
    if (preset === 'all') {
      start.value = '';
      end.value = '';
      return;
    }
    if (preset === 'today') {
      start.value = formatDate(today);
      return;
    }
    from.setDate(today.getDate() - (preset === '30d' ? 29 : 6));
    start.value = formatDate(from);
  }

  function setActivePreset(preset) {
    document.querySelectorAll('.js-df-like-preset').forEach(function(button) {
      button.classList.toggle('is-active', button.getAttribute('data-preset') === preset);
    });
  }

  function formatDate(date) {
    var year = date.getFullYear();
    var month = String(date.getMonth() + 1).padStart(2, '0');
    var day = String(date.getDate()).padStart(2, '0');
    return year + '-' + month + '-' + day;
  }

  function setupUpdateNotice(root) {
    var notice = root.querySelector('.js-df-like-update-notice');
    var message = root.querySelector('.js-df-like-update-message');
    var close = root.querySelector('.js-df-like-update-close');
    var currentVersion = root.getAttribute('data-current-version') || '';
    if (!notice || !message || !currentVersion || !window.fetch) {
      return;
    }
    if (close) {
      close.addEventListener('click', function() {
        notice.hidden = true;
        var tag = notice.getAttribute('data-release-tag') || '';
        if (tag && window.localStorage) {
          try {
            window.localStorage.setItem('df_like_update_dismissed', tag);
          } catch (error) {
          }
        }
      });
    }
    latestRelease().then(function(release) {
      if (!release || !release.tag_name || release.prerelease || release.draft) {
        return;
      }
      var latestVersion = normalizeVersion(release.tag_name);
      if (!isNewerVersion(latestVersion, currentVersion)) {
        return;
      }
      if (isDismissed(release.tag_name)) {
        return;
      }
      var url = release.html_url || 'https://github.com/datafarmjp/acms-df-like/releases/latest';
      var asset = zipAsset(release.assets || [], latestVersion);
      if (asset && asset.browser_download_url) {
        url = asset.browser_download_url;
      }
      notice.setAttribute('data-release-tag', release.tag_name);
      message.innerHTML = '';
      message.appendChild(document.createTextNode('DF_Like ' + release.tag_name + ' が公開されています。'));
      var link = document.createElement('a');
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener';
      link.textContent = '最新版をダウンロード';
      message.appendChild(link);
      notice.hidden = false;
    }).catch(function() {
    });
  }

  function latestRelease() {
    var cacheKey = 'df_like_latest_release';
    var cachedRelease = null;
    if (window.localStorage) {
      try {
        var cached = JSON.parse(window.localStorage.getItem(cacheKey) || 'null');
        if (cached && cached.release) {
          cachedRelease = cached.release;
        }
      } catch (error) {
      }
    }
    return fetch('https://api.github.com/repos/datafarmjp/acms-df-like/releases/latest', {
      headers: {'Accept': 'application/vnd.github+json'}
    }).then(function(response) {
      if (!response.ok) {
        throw new Error('release check failed');
      }
      return response.json();
    }).then(function(release) {
      if (window.localStorage) {
        try {
          window.localStorage.setItem(cacheKey, JSON.stringify({
            checked_at: Date.now(),
            release: release
          }));
        } catch (error) {
        }
      }
      return release;
    }).catch(function(error) {
      if (cachedRelease) {
        return cachedRelease;
      }
      throw error;
    });
  }

  function zipAsset(assets, version) {
    var expected = 'DF_Like-v' + version + '.zip';
    for (var i = 0; i < assets.length; i++) {
      if (assets[i] && assets[i].name === expected) {
        return assets[i];
      }
    }
    for (var j = 0; j < assets.length; j++) {
      if (assets[j] && /\.zip$/i.test(assets[j].name || '')) {
        return assets[j];
      }
    }
    return null;
  }

  function isDismissed(tag) {
    if (!window.localStorage) {
      return false;
    }
    try {
      return window.localStorage.getItem('df_like_update_dismissed') === tag;
    } catch (error) {
      return false;
    }
  }

  function normalizeVersion(value) {
    return String(value || '').replace(/^v/i, '').trim();
  }

  function isNewerVersion(latest, current) {
    var latestParts = versionParts(latest);
    var currentParts = versionParts(current);
    for (var i = 0; i < Math.max(latestParts.length, currentParts.length); i++) {
      var left = latestParts[i] || 0;
      var right = currentParts[i] || 0;
      if (left > right) {
        return true;
      }
      if (left < right) {
        return false;
      }
    }
    return false;
  }

  function versionParts(value) {
    return normalizeVersion(value).split('.').map(function(part) {
      return parseInt(part, 10) || 0;
    });
  }

})();
