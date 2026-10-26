(function(window) {
  'use strict';

  var Admin = window.DFLikeAdmin = window.DFLikeAdmin || {};
  var placements = ['inherit', 'none', 'left', 'center', 'right'];
  var placementLabels = ['継承する', '非表示', '左', '中央', '右'];

  Admin.setupCategoryPlacements = function(root) {
    var editor = root.querySelector('.js-df-like-category-placements');
    if (!editor || editor._dfLikeCategoriesReady) {
      return;
    }
    var input = editor.querySelector('.js-df-like-category-placements-value');
    var rows = editor.querySelector('.js-df-like-category-placement-rows');
    var empty = editor.querySelector('.js-df-like-category-placement-empty');
    var select = editor.querySelector('.js-df-like-category-select');
    var add = editor.querySelector('.js-df-like-category-add');
    var retry = editor.querySelector('.js-df-like-category-retry');
    var status = editor.querySelector('.js-df-like-category-status');
    if (!input || !rows || !empty || !select || !add || !retry || !status) {
      return;
    }
    editor._dfLikeCategoriesReady = true;

    var rules;
    var categories = [];
    var categoriesById = Object.create(null);
    var ready = false;
    var loading = false;
    try {
      rules = parseRules(input.value);
    } catch (error) {
      select.options[0].textContent = 'カテゴリ別設定を確認できません';
      setStatus('保存済みのカテゴリ別設定を読み取れませんでした。既存の設定を維持したまま、カテゴリ別設定の編集を停止しています。', true);
      return;
    }

    select.addEventListener('change', updateAddButton);
    add.addEventListener('click', function() {
      var id = select.value;
      if (!ready || !categoriesById[id] || Object.prototype.hasOwnProperty.call(rules, id)) {
        return;
      }
      rules[id] = {top: 'inherit', bottom: 'inherit'};
      saveRules();
      renderRows();
      renderOptions();
      showReadyStatus();
      var added = rows.querySelector('[data-category-id="' + id + '"] select');
      if (added) {
        added.focus();
      }
    });
    retry.addEventListener('click', loadCategories);
    renderRows();
    loadCategories();

    function saveRules() {
      // Only user edits update the saved value. Loading failures never clear rules.
      input.value = JSON.stringify(rules);
      input.dispatchEvent(new Event('change', {bubbles: true}));
    }

    function setStatus(message, isError) {
      status.textContent = message;
      status.classList.toggle('is-error', !!isError);
    }

    function showReadyStatus() {
      var missing = Object.keys(rules).filter(function(id) {
        return !categoriesById[id];
      }).length;
      if (missing) {
        setStatus('一覧に見つからないカテゴリの設定が ' + missing + ' 件あります。設定は保持されています。不要な場合は該当行を削除してください。', false);
      } else if (!categories.length) {
        setStatus('このブログにはカテゴリがありません。カテゴリを作成してから再度開いてください。', false);
      } else {
        setStatus('', false);
      }
    }

    function categoryLabel(id) {
      var category = categoriesById[id];
      if (!category) {
        return 'カテゴリ #' + id + (ready ? '（一覧に見つかりません）' : '');
      }
      var names = [category.name];
      var visited = Object.create(null);
      visited[id] = true;
      while (category.parent_id && categoriesById[category.parent_id] && !visited[category.parent_id]) {
        visited[category.parent_id] = true;
        category = categoriesById[category.parent_id];
        names.unshift(category.name);
      }
      return names.join(' / ') + ' (#' + id + ')';
    }

    function renderRows() {
      rows.textContent = '';
      var ids = Object.keys(rules);
      empty.hidden = ids.length > 0;
      ids.forEach(function(id) {
        var row = document.createElement('div');
        row.className = 'df-like-admin-categories__row';
        row.setAttribute('data-category-id', id);
        var name = document.createElement('span');
        name.className = 'df-like-admin-categories__name';
        name.textContent = categoryLabel(id);
        row.appendChild(name);
        ['top', 'bottom'].forEach(function(position) {
          var label = document.createElement('label');
          label.className = 'df-like-admin-categories__position';
          var caption = document.createElement('span');
          caption.textContent = position === 'top' ? '本文上' : '本文下';
          label.appendChild(caption);
          var control = document.createElement('select');
          control.setAttribute('aria-label', categoryLabel(id) + 'の' + caption.textContent);
          placements.forEach(function(value, index) {
            var option = document.createElement('option');
            option.value = value;
            option.textContent = placementLabels[index];
            control.appendChild(option);
          });
          control.value = rules[id][position] || 'inherit';
          control.disabled = !ready;
          control.addEventListener('change', function() {
            if (ready && placements.indexOf(control.value) !== -1) {
              rules[id][position] = control.value;
              saveRules();
            }
          });
          label.appendChild(control);
          row.appendChild(label);
        });
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'acms-admin-btn-admin';
        remove.textContent = '削除';
        remove.setAttribute('aria-label', categoryLabel(id) + 'の表示位置設定を削除');
        remove.disabled = !ready;
        remove.addEventListener('click', function() {
          if (!ready) {
            return;
          }
          delete rules[id];
          saveRules();
          renderRows();
          renderOptions();
          showReadyStatus();
          select.focus();
        });
        row.appendChild(remove);
        rows.appendChild(row);
      });
    }

    function renderOptions() {
      var current = select.value;
      select.textContent = '';
      var available = categories.filter(function(category) {
        return !Object.prototype.hasOwnProperty.call(rules, category.id);
      });
      var placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = available.length ? 'カテゴリを選択' : '追加できるカテゴリはありません';
      select.appendChild(placeholder);
      available.forEach(function(category) {
        var option = document.createElement('option');
        option.value = category.id;
        option.textContent = categoryLabel(category.id);
        select.appendChild(option);
      });
      select.value = available.some(function(category) { return category.id === current; }) ? current : '';
      select.disabled = !ready || !available.length;
      updateAddButton();
    }

    function updateAddButton() {
      add.disabled = !ready || !select.value || Object.prototype.hasOwnProperty.call(rules, select.value);
    }

    function loadCategories() {
      if (loading) {
        return;
      }
      loading = true;
      ready = false;
      retry.hidden = true;
      select.disabled = true;
      add.disabled = true;
      editor.setAttribute('aria-busy', 'true');
      setStatus('カテゴリを読み込んでいます…', false);
      Admin.postJsonWithFallback(
        ['ACMS_POST_DFLikeCategoryOptions', 'ACMS_POST_DF_Like_DFLikeCategoryOptions'],
        function(action) {
          var formData = new FormData();
          formData.append(action, '1');
          Admin.appendCsrfToken(formData);
          return formData;
        },
        'カテゴリ一覧を取得できませんでした。'
      ).then(function(json) {
        if (!json || json.status !== 'success' || !Array.isArray(json.categories)) {
          throw new Error('カテゴリ一覧を取得できませんでした。');
        }
        categories = [];
        categoriesById = Object.create(null);
        json.categories.forEach(function(category) {
          if (!category || !isCategoryId(String(category.id)) || categoriesById[String(category.id)]) {
            return;
          }
          var item = {
            id: String(category.id),
            name: String(category.name || '名称未設定'),
            parent_id: String(category.parent_id || '')
          };
          categories.push(item);
          categoriesById[item.id] = item;
        });
        ready = true;
        renderRows();
        renderOptions();
        showReadyStatus();
      }).catch(function() {
        setStatus('カテゴリ一覧を取得できませんでした。保存済みのカテゴリ別設定は維持されます。「再読み込み」で再試行してください。', true);
        retry.hidden = false;
      }).finally(function() {
        loading = false;
        editor.setAttribute('aria-busy', 'false');
      });
    }
  };

  function isCategoryId(value) {
    return /^[1-9][0-9]*$/.test(value);
  }

  function parseRules(value) {
    var text = String(value || '').trim();
    if (!text || text === '{df_like_category_placements}' || text === '{df_like_category_placements}[escape]') {
      return Object.create(null);
    }
    var rules = JSON.parse(text);
    if (!rules || typeof rules !== 'object' || Array.isArray(rules)) {
      throw new Error('Invalid category placements');
    }
    Object.keys(rules).forEach(function(id) {
      var rule = rules[id];
      if (!isCategoryId(id) || !rule || typeof rule !== 'object' || Array.isArray(rule)) {
        throw new Error('Invalid category placement');
      }
      ['top', 'bottom'].forEach(function(position) {
        if (rule[position] !== undefined && rule[position] !== '' && placements.indexOf(rule[position]) === -1) {
          throw new Error('Invalid category placement value');
        }
      });
    });
    return rules;
  }
})(window);
