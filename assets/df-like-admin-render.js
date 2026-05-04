(function(window) {
  'use strict';

  var Admin = window.DFLikeAdmin = window.DFLikeAdmin || {};

  Admin.render = function(root, data) {
    Admin.renderSummary(root.querySelector('.js-df-like-summary'), data.summary || {});
    Admin.renderDaily(root.querySelector('.js-df-like-daily'), data.daily || [], data.filters || {});
    Admin.renderRanking(root.querySelector('.js-df-like-ranking'), data.ranking || []);
    Admin.renderHistory(root.querySelector('.js-df-like-history'), data.history || [], data);
    Admin.renderErrors(root.querySelector('.js-df-like-errors'), data.errors || []);
    Admin.updateActionVisibility(root, data || {});
  };

  Admin.updateActionVisibility = function(root, data) {
    var deleteLogs = root.querySelector('.js-df-like-delete-logs');
    if (deleteLogs) {
      var historyCount = 0;
      if (data.history_total !== undefined && data.history_total !== null && data.history_total !== '') {
        historyCount = Number(data.history_total || 0);
      } else if (data.history && data.history.length) {
        historyCount = data.history.length;
      }
      Admin.setElementHidden(deleteLogs, historyCount <= 0);
    }
    var deleteErrors = root.querySelector('.js-df-like-delete-errors');
    if (deleteErrors) {
      var errorCount = data.errors && data.errors.length ? data.errors.length : 0;
      Admin.setElementHidden(deleteErrors, errorCount <= 0);
    }
  };

  Admin.setElementHidden = function(element, hidden) {
    if (!element) {
      return;
    }
    element.hidden = !!hidden;
    element.setAttribute('aria-hidden', hidden ? 'true' : 'false');
    if (hidden) {
      element.setAttribute('hidden', 'hidden');
      element.style.display = 'none';
    } else {
      element.removeAttribute('hidden');
      element.style.removeProperty('display');
    }
  };

  Admin.updateHookWarning = function(warning, hookEnabled) {
    if (!warning) {
      return;
    }
    if (hookEnabled === false) {
      warning.hidden = false;
      warning.style.removeProperty('display');
    } else {
      warning.hidden = true;
      warning.style.setProperty('display', 'none', 'important');
    }
  };

  Admin.renderSummary = function(target, summary) {
    var items = [
      ['現在のいいね', summary.active_likes || 0],
      ['対象数', summary.liked_targets || 0],
      ['期間内のいいね', summary.like_events || 0],
      ['期間内の解除', summary.unlike_events || 0],
      ['7日間のいいね', summary.likes_7d || 0],
      ['期間内イベント', summary.total_events || 0],
    ];
    target.innerHTML = items.map(function(item) {
      return '<div class="df-like-admin-card"><span>' + Admin.escapeHtml(item[0]) + '</span><strong>' + Admin.escapeHtml(item[1]) + '</strong></div>';
    }).join('');
  };

  Admin.renderDaily = function(target, rows, filters) {
    if (!rows.length) {
      target.innerHTML = '<p class="acms-admin-text-muted">日別データはまだありません。</p>';
      return;
    }
    var data = Admin.fillDailyRows(rows, filters || {});
    var max = data.reduce(function(value, row) {
      return Math.max(value, Number(row.likes || 0));
    }, 0);
    if (max <= 0) {
      target.innerHTML = '<p class="acms-admin-text-muted">日別データはまだありません。</p>';
      return;
    }
    target.innerHTML = Admin.dailyLineChart(data, max, target) + Admin.dailyBars(rows);
  };

  Admin.fillDailyRows = function(rows, filters) {
    var map = {};
    rows.forEach(function(row) {
      if (row && row.date) {
        map[row.date] = row;
      }
    });
    var dates = rows.map(function(row) {
      return row.date;
    }).filter(Boolean).sort();
    if (!dates.length) {
      return [];
    }
    var start = Admin.parseDate(filters.start_date || '');
    var end = Admin.parseDate(filters.end_date || '');
    if (!start && !end && !(filters.start_date || filters.end_date)) {
      end = new Date();
      start = new Date(end.getTime());
      start.setDate(start.getDate() - 13);
    }
    start = start || Admin.parseDate(dates[0]);
    end = end || Admin.parseDate(dates[dates.length - 1]);
    if (!start || !end) {
      return rows;
    }
    var result = [];
    var cursor = new Date(start.getTime());
    while (cursor <= end) {
      var key = Admin.formatDate(cursor);
      var item = map[key] || {date: key, likes: 0, unlikes: 0, total: 0};
      result.push({
        date: key,
        likes: Number(item.likes || 0),
        unlikes: Number(item.unlikes || 0),
        total: Number(item.total || 0),
      });
      cursor.setDate(cursor.getDate() + 1);
    }
    return result;
  };

  Admin.dailyLineChart = function(rows, max, target) {
    var targetWidth = target ? Math.floor(target.clientWidth || target.getBoundingClientRect().width || 0) : 0;
    var width = Math.max(360, (targetWidth || 984) - 24);
    var height = 220;
    var left = 58;
    var right = 24;
    var top = 30;
    var bottom = 48;
    var chartWidth = width - left - right;
    var chartHeight = height - top - bottom;
    var scale = max <= 0 ? 1 : max;
    var points = rows.map(function(row, index) {
      var x = left + (rows.length === 1 ? chartWidth / 2 : (chartWidth / (rows.length - 1)) * index);
      var y = top + chartHeight - (Number(row.likes || 0) / scale) * chartHeight;
      return {x: x, y: y, row: row};
    });
    var polyline = points.map(function(point) {
      return point.x.toFixed(2) + ',' + point.y.toFixed(2);
    }).join(' ');
    var grid = [0, 0.5, 1].map(function(rate) {
      var y = top + chartHeight - chartHeight * rate;
      var label = Math.round(scale * rate);
      return '<line class="df-like-admin-line-chart__grid" x1="' + left + '" y1="' + y.toFixed(2) + '" x2="' + (width - right) + '" y2="' + y.toFixed(2) + '" stroke="#e5e7eb" stroke-width="1" vector-effect="non-scaling-stroke"></line>' +
        '<text class="df-like-admin-line-chart__axis" x="' + (left - 12) + '" y="' + (y + 5).toFixed(2) + '" text-anchor="end" fill="#57606a" font-size="14">' + Admin.escapeHtml(label) + '</text>';
    }).join('');
    var circles = points.map(function(point) {
      return '<circle class="df-like-admin-line-chart__point" cx="' + point.x.toFixed(2) + '" cy="' + point.y.toFixed(2) + '" r="4" fill="#fff" stroke="#2563eb" stroke-width="3" vector-effect="non-scaling-stroke">' +
        '<title>' + Admin.escapeHtml(point.row.date + ': ' + point.row.likes + '件') + '</title>' +
        '</circle>';
    }).join('');
    var dateLabels = Admin.dailyLineChartLabelIndexes(points.length).map(function(index) {
      var point = points[index];
      return '<text class="df-like-admin-line-chart__axis" x="' + point.x.toFixed(2) + '" y="' + (height - 12) + '" text-anchor="middle" fill="#57606a" font-size="14">' + Admin.escapeHtml(Admin.shortDate(point.row.date)) + '</text>';
    }).join('');
    return '<div class="df-like-admin-line-chart" role="img" aria-label="日別いいね推移">' +
      '<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="xMinYMin meet">' +
      grid +
      '<polyline class="df-like-admin-line-chart__line" points="' + polyline + '" fill="none" stroke="#2563eb" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke"></polyline>' +
      circles +
      dateLabels +
      '<text class="df-like-admin-line-chart__caption" x="' + (width - right) + '" y="18" text-anchor="end" fill="#57606a" font-size="13">いいね追加回数</text>' +
      '</svg>' +
      '</div>';
  };

  Admin.dailyLineChartLabelIndexes = function(length) {
    if (length <= 1) {
      return [0];
    }
    var step = length <= 7 ? 1 : Math.ceil((length - 1) / 4);
    var indexes = [];
    for (var i = 0; i < length; i += step) {
      indexes.push(i);
    }
    if (indexes[indexes.length - 1] !== length - 1) {
      indexes.push(length - 1);
    }
    return indexes;
  };

  Admin.dailyBars = function(rows) {
    var max = rows.reduce(function(value, row) {
      return Math.max(value, Number(row.total || 0));
    }, 1);
    return '<div class="df-like-admin-daily-bars">' + rows.map(function(row) {
      var width = Math.max(3, Math.round((Number(row.total || 0) / max) * 100));
      return '<div class="df-like-admin-bar"><span>' + Admin.escapeHtml(row.date || '') + '</span><div><i style="width:' + width + '%"></i></div><b>' + Admin.escapeHtml(row.total || 0) + '</b></div>';
    }).join('') + '</div>';
  };

  Admin.parseDate = function(value) {
    var match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) {
      return null;
    }
    return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  };

  Admin.formatDate = function(date) {
    var month = String(date.getMonth() + 1);
    var day = String(date.getDate());
    return date.getFullYear() + '-' + (month.length === 1 ? '0' + month : month) + '-' + (day.length === 1 ? '0' + day : day);
  };

  Admin.shortDate = function(value) {
    var match = String(value || '').match(/^\d{4}-(\d{2})-(\d{2})$/);
    return match ? match[1] + '/' + match[2] : value;
  };

  Admin.renderRanking = function(target, rows) {
    if (!rows.length) {
      target.innerHTML = '<p class="acms-admin-text-muted">いいねされたエントリーはまだありません。</p>';
      return;
    }
    var previousCount = null;
    var currentRank = 0;
    target.innerHTML = '<table class="adminTable acms-admin-table-admin"><thead><tr><th>順位</th><th>タイトル</th><th>対象</th><th>ブログID</th><th>エントリーID</th><th>いいね数</th></tr></thead><tbody>' +
      rows.map(function(row, index) {
        var count = Number(row.count || 0);
        if (previousCount === null || count !== previousCount) {
          currentRank = index + 1;
          previousCount = count;
        }
        return '<tr><td>' + currentRank + '</td><td>' + Admin.entryTitleLink(row.entry_title, row.like_entry_id, row.entry_url) + '</td><td>' + Admin.escapeHtml(row.like_object_type) + ':' + Admin.escapeHtml(row.like_object_id) + '</td><td>' + Admin.escapeHtml(row.like_blog_id || 0) + '</td><td>' + Admin.escapeHtml(row.like_entry_id || 0) + '</td><td><strong>' + Admin.escapeHtml(row.count || 0) + '</strong></td></tr>';
      }).join('') +
      '</tbody></table>';
  };

  Admin.renderHistory = function(target, rows, data) {
    if (!rows.length) {
      target.innerHTML = '<p class="acms-admin-text-muted">履歴はまだありません。</p>';
      return;
    }
    target.innerHTML = '<table class="adminTable acms-admin-table-admin"><thead><tr><th>日時</th><th>操作</th><th>タイトル</th><th>対象</th><th>ユーザーID</th><th>訪問者</th><th>参照元</th><th>削除</th></tr></thead><tbody>' +
      rows.map(function(row) {
        return '<tr><td>' + Admin.escapeHtml(row.log_created_at || '') + '</td><td>' + Admin.actionLabel(row.log_action) + '</td><td>' + Admin.entryTitleLink(row.entry_title, row.log_entry_id, row.entry_url) + '</td><td>' + Admin.escapeHtml(row.log_object_type) + ':' + Admin.escapeHtml(row.log_object_id) + '</td><td>' + Admin.escapeHtml(row.log_user_id || '-') + '</td><td>' + Admin.escapeHtml(row.visitor || '-') + '</td><td class="df-like-admin__referer">' + Admin.escapeHtml(row.log_referer || '-') + '</td><td class="df-like-admin__actions">' + Admin.deleteLikeButton(row) + '</td></tr>';
      }).join('') +
      '</tbody></table>' + Admin.historyPager(data || {});
  };

  Admin.renderErrors = function(target, rows) {
    if (!target) {
      return;
    }
    if (!rows.length) {
      target.innerHTML = '<p class="acms-admin-text-muted">最近のエラーはありません。</p>';
      return;
    }
    target.innerHTML = '<table class="adminTable acms-admin-table-admin"><thead><tr><th>日時</th><th>種別</th><th>ブログID</th><th>エントリーID</th><th>メッセージ</th><th>詳細</th></tr></thead><tbody>' +
      rows.map(function(row) {
        return '<tr><td>' + Admin.escapeHtml(row.error_created_at || '') + '</td><td>' + Admin.errorTypeLabel(row.error_type) + '</td><td>' + Admin.escapeHtml(row.error_blog_id || 0) + '</td><td>' + Admin.escapeHtml(row.error_entry_id || 0) + '</td><td class="df-like-admin__error-message">' + Admin.escapeHtml(row.error_message || '-') + '</td><td>' + Admin.errorDetail(row) + '</td></tr>';
      }).join('') +
      '</tbody></table>';
  };

  Admin.historyPager = function(data) {
    var totalPages = Number(data.history_total_pages || 1);
    var page = Number(data.history_page || 1);
    var total = Number(data.history_total || 0);
    if (total <= 0 || totalPages <= 1) {
      return '';
    }
    var prev = Math.max(1, page - 1);
    var next = Math.min(totalPages, page + 1);
    return '<div class="df-like-admin-pager">' +
      '<button type="button" class="acms-admin-btn-admin js-df-like-history-page" data-page="' + prev + '"' + (page <= 1 ? ' disabled' : '') + '>前へ</button>' +
      '<span>' + Admin.escapeHtml(page) + ' / ' + Admin.escapeHtml(totalPages) + 'ページ（全' + Admin.escapeHtml(total) + '件）</span>' +
      '<button type="button" class="acms-admin-btn-admin js-df-like-history-page" data-page="' + next + '"' + (page >= totalPages ? ' disabled' : '') + '>次へ</button>' +
      '</div>';
  };

  Admin.actionLabel = function(action) {
    return action === 'unlike'
      ? '<span class="acms-admin-label acms-admin-label-default">解除</span>'
      : '<span class="acms-admin-label acms-admin-label-info">いいね</span>';
  };

  Admin.errorTypeLabel = function(type) {
    var labels = {
      toggle: 'いいね操作',
      notification: '通知',
      import: 'インポート',
      rebuild: '再構築',
      sync: '同期',
      schema: 'DB補修',
    };
    return '<span class="acms-admin-label acms-admin-label-default">' + Admin.escapeHtml(labels[type] || type || '-') + '</span>';
  };

  Admin.deleteLikeButton = function(row) {
    if (!row || row.log_action !== 'like' || !row.log_id) {
      return '-';
    }
    return '<button type="button" class="acms-admin-btn-admin acms-admin-btn-admin-danger js-df-like-delete-like" data-log-id="' + Admin.escapeHtml(row.log_id) + '">いいね削除</button>';
  };

  Admin.renderImportResult = function(target, json) {
    if (!target) {
      return;
    }
    if (!json || json.status !== 'success') {
      target.innerHTML = '<div class="df-like-admin-import__result acms-admin-text-danger">' + Admin.escapeHtml((json && json.message) || 'CSVインポートに失敗しました。') + '</div>';
      return;
    }
    var html = '<div class="df-like-admin-import__result">' +
      '<p>' + (json.dry_run ? 'ドライラン結果' : 'インポート結果') +
      ': 処理 ' + Admin.escapeHtml(json.processed || 0) +
      ' / ログ ' + Admin.escapeHtml(json.imported_logs || 0) +
      ' / 追加 ' + Admin.escapeHtml(json.imported_likes || 0) +
      ' / 解除 ' + Admin.escapeHtml(json.removed_likes || 0) +
      ' / スキップ ' + Admin.escapeHtml(json.skipped || 0) +
      ' / 失敗 ' + Admin.escapeHtml(json.failed || 0) + '</p>';
    if (json.errors && json.errors.length) {
      html += '<ul>' + json.errors.map(function(error) {
        return '<li>' + Admin.escapeHtml('Line ' + error.line + ': ' + error.message) + '</li>';
      }).join('') + '</ul>';
    }
    if (Number(json.failed || 0) > 0) {
      html += '<p class="acms-admin-text-danger">失敗内容は「最近のエラー」にも記録されます。</p>';
    }
    target.innerHTML = html + '</div>';
  };

  Admin.renderImportMessage = function(target, text, type) {
    if (!target) {
      return;
    }
    target.innerHTML = '<div class="df-like-admin-import__result acms-admin-text-' + Admin.escapeHtml(type || 'muted') + '">' + Admin.escapeHtml(text || '') + '</div>';
  };

  Admin.entryTitle = function(title, id) {
    return title || (id ? 'entry_id: ' + id : '-');
  };

  Admin.entryTitleLink = function(title, id, url) {
    var text = Admin.entryTitle(title, id);
    if (!url) {
      return Admin.escapeHtml(text);
    }
    return '<a href="' + Admin.escapeAttribute(url) + '" target="_blank" rel="noopener noreferrer">' + Admin.escapeHtml(text) + '</a>';
  };

  Admin.errorDetail = function(row) {
    var context = Admin.formatErrorContext(row && row.error_context);
    if (!context) {
      return '-';
    }
    return '<details class="df-like-admin-error-detail"><summary title="詳細">?</summary><pre>' + Admin.escapeHtml(context) + '</pre></details>';
  };

  Admin.formatErrorContext = function(context) {
    if (!context) {
      return '';
    }
    var text = String(context);
    try {
      text = JSON.stringify(JSON.parse(text), null, 2);
    } catch (error) {
    }
    if (text.length > 2000) {
      text = text.slice(0, 2000) + '\n...';
    }
    return text;
  };

  Admin.escapeHtml = function(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(char) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
    });
  };

  Admin.escapeAttribute = function(value) {
    return Admin.escapeHtml(value).replace(/`/g, '&#096;');
  };
})(window);
