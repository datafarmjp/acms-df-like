(function(window) {
  'use strict';

  var Feature = window.DFLikeAdminSnippetModals = window.DFLikeAdminSnippetModals || {};

  Feature.createController = function(options) {
    var root = options && options.root;
    var doc = options && options.document || document;
    var activeTrigger = null;
    var mounted = false;

    function mount() {
      if (!root || mounted) {
        return;
      }
      mounted = true;

      root.querySelectorAll('.js-df-like-snippet-open').forEach(function(button) {
        button.addEventListener('click', function() {
          var key = button.getAttribute('data-df-like-modal') || '';
          var modal = root.querySelector('.js-df-like-snippet-modal[data-df-like-modal="' + key + '"]');
          if (!modal) {
            return;
          }
          activeTrigger = button;
          open(modal);
        });
      });

      root.querySelectorAll('.js-df-like-snippet-modal').forEach(function(modal) {
        modal.addEventListener('click', function(event) {
          if (event.target === modal || event.target.closest('.js-df-like-snippet-close')) {
            close(modal, activeTrigger);
          }
        });
      });

      doc.addEventListener('keydown', function(event) {
        if (event.key !== 'Escape') {
          return;
        }
        var modal = root.querySelector('.js-df-like-snippet-modal:not([hidden])');
        if (modal) {
          close(modal, activeTrigger);
        }
      });
    }

    function open(modal) {
      clearStatus(modal);
      modal.hidden = false;
      modal.classList.remove('out');
      modal.classList.add('in', 'display');
      var dialog = modal.querySelector('[role="dialog"]');
      if (dialog) {
        dialog.focus();
      }
    }

    function close(modal, trigger) {
      modal.classList.remove('in', 'display');
      modal.classList.add('out');
      modal.hidden = true;
      if (trigger && typeof trigger.focus === 'function') {
        trigger.focus();
      }
    }

    function clearStatus(modal) {
      modal.querySelectorAll('.df-like-admin-local-status').forEach(function(status) {
        status.textContent = '';
        status.className = status.getAttribute('data-base-class') || 'df-like-admin-local-status';
        status.removeAttribute('data-base-class');
      });
    }

    return {
      mount: mount,
      open: open,
      close: close,
      clearStatus: clearStatus
    };
  };
})(window);
