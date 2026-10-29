/* global $, CFG_GLPI, GLPI_PLUGINS_PATH */
/* Ticket status rules and display belong to AutoStatus. */
(function() {
   'use strict';
   if (window.glpiAutoStatus) return;
   const edited = new WeakSet();
   let revision = 0;
   let scheduled = null;
   let request = null;

   function form() {
      return $('#itil-form').filter(function() {
         const path = ($(this).attr('action') || '').split('?')[0].toLowerCase();
         return path.endsWith('/ticket.form.php') || path.endsWith('/tracking.injector.php');
      });
   }
   function ticketId() {
      const id = Number(form().children("input[name='id']").first().val());
      return Number.isSafeInteger(id) && id > 0 ? id : 0;
   }
   function render(ticket) {
      if (!ticket || Number(ticket.id) !== ticketId() || !Number.isSafeInteger(ticket.status)
         || ticket.status <= 0 || typeof ticket.label !== 'string' || typeof ticket.icon !== 'string') return;
      const current = form();
      const fields = current.find("select[name='status']");
      fields.each(function() {
         const field = $(this);
         if (edited.has(this) || String(field.val()) === String(ticket.status)) return;
         if (ticket.options && typeof ticket.options === 'object') {
            field.empty();
            Object.entries(ticket.options).forEach(([value, label]) => {
               if (/^[1-9][0-9]*$/.test(value) && typeof label === 'string') field.append(new Option(label, value));
            });
         }
         if (!field.find('option').filter(function() { return Number(this.value) === ticket.status; }).length) {
            field.append(new Option(ticket.label, String(ticket.status)));
         }
         field.val(String(ticket.status)).trigger('change.select2');
      });
      const icon = () => $('<i>', {class: ticket.icon + ' me-1', title: ticket.label, 'data-bs-toggle': 'tooltip'});
      current.find('#heading-main-item .status-recall').empty().append(icon());
      if (!fields.length) {
         const container = current.find(".form-field > label[for^='status_']").closest('.form-field').children('.field-container').first();
         if (!container.length) return;
         let value = container.children('[data-autostatus-ticket-status]');
         if (!value.length) {
            container.contents().filter(function() {
               return this.nodeType === 3 || (this.nodeType === 1 && $(this).is('i.itilstatus, [data-actualtime-parent-status]'));
            }).remove();
            value = $('<span>', {'data-autostatus-ticket-status': '1'}).prependTo(container);
         }
         value.empty().append(icon()).append(document.createTextNode(ticket.label));
      }
   }
   function refresh() {
      if (!ticketId()) return;
      const currentRevision = ++revision;
      if (scheduled !== null) clearTimeout(scheduled);
      if (request) request.abort();
      scheduled = setTimeout(function() {
         scheduled = null;
         const id = ticketId();
         if (!id) return;
         const root = CFG_GLPI.root_doc || '';
         const plugin = GLPI_PLUGINS_PATH.autostatus || 'plugins/autostatus';
         const pending = $.ajax({type: 'GET', url: root + '/' + plugin + '/ajax/status.php', dataType: 'json', cache: false, data: {tickets_id: id}});
         request = pending;
         pending.done(function(result) {
            if (currentRevision === revision && id === ticketId()) render(result.ticket);
         }).always(function() {
            if (request === pending) request = null;
         });
      }, 30);
   }
   function relevantRequest(xhr, settings) {
      const id = ticketId();
      if (!id) return false;
      const url = new URL(settings.url, window.location.href);
      if (url.origin !== window.location.origin) return false;
      const root = (CFG_GLPI.root_doc || '').replace(/\/$/, '');
      const method = (settings.type || settings.method || 'GET').toUpperCase();
      const data = typeof settings.data === 'string' ? new URLSearchParams(settings.data) : settings.data;
      const field = name => {
         const value = data && typeof data.get === 'function' ? data.get(name) : data && data[name];
         return value === undefined || value === null ? url.searchParams.get(name) : value;
      };
      const parent = field('parenttype') || field('itemtype');
      const parentId = field('tickets_id') || (parent === 'Ticket' ? field('items_id') : null);
      if (url.pathname === root + '/ajax/timeline.php') {
         return (!parent || parent === 'Ticket') && (parentId === null || Number(parentId) === id)
            && ((method === 'POST' && field('action') === 'change_task_state')
               || (field('action') === 'viewsubitem' && ['TicketTask', 'ITILFollowup'].includes(field('type'))));
      }
      if (method === 'POST' && ['/front/tickettask.form.php', '/front/commonitiltask.form.php', '/front/itilfollowup.form.php'].some(path => url.pathname === root + path)) {
         return (!parent || parent === 'Ticket') && (parentId === null || Number(parentId) === id);
      }
      // Adapter for ActualTime versions preceding the generic browser event.
      const plugin = GLPI_PLUGINS_PATH.actualtime;
      if (plugin && method === 'POST' && url.pathname === new URL(root + '/' + plugin + '/ajax/timer.php', window.location.href).pathname
         && field('itemtype') === 'TicketTask' && ['start', 'pause', 'end'].includes(field('action'))) {
         const result = xhr.responseJSON;
         return result && result.type === 'info' && result.runtime && result.runtime.parent_type === 'Ticket'
            && Number(result.runtime.parent_id) === id;
      }
      return false;
   }
   window.glpiAutoStatus = {refresh};
   $(function() {
      $(document).on('change.autostatus', "#itil-form select[name='status']", function() { edited.add(this); });
      $(document).on('ajaxSuccess.autostatus', function(event, xhr, settings) {
         if (relevantRequest(xhr, settings)) refresh();
      });
      $(document).on('click.autostatus', '.close-edit-content', refresh);
      $(document).on('shown.bs.modal.autostatus hidden.bs.modal.autostatus', '#modal_actualtime', refresh);
      window.addEventListener('actualtime:timer-changed', function(event) {
         const item = event.detail;
         if (item && item.parent_type === 'Ticket' && Number(item.parent_id) === ticketId()) refresh();
      });
      window.addEventListener('focus', refresh);
      window.addEventListener('storage', function(event) { if (event.key === 'actualtime:changed') refresh(); });
      refresh();
   });
})();
