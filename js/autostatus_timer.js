(() => {
  const pad = (value) => (value < 10 ? `0${value}` : `${value}`);

  const formatSeconds = (totalSeconds) => {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return `${pad(hours)}:${pad(minutes)}:${pad(secs)}`;
  };

  const initTimer = (el) => {
    let running = el.dataset.running === '1';
    let startTs = parseInt(el.dataset.startTs || '0', 10);
    let totalCompleted = parseInt(el.dataset.total || '0', 10);

    const display = el.querySelector('.autostatus-timer-display');
    const message = el.querySelector('.autostatus-timer-message');
    const startBtn = el.querySelector('.autostatus-timer-start');
    const stopBtn = el.querySelector('.autostatus-timer-stop');

    const updateButtons = () => {
      if (startBtn) startBtn.disabled = running;
      if (stopBtn) stopBtn.disabled = !running;
    };

    const getTotal = () => {
      if (!running || !startTs) return totalCompleted;
      const now = Math.floor(Date.now() / 1000);
      return totalCompleted + Math.max(0, now - startTs);
    };

    const updateDisplay = () => {
      if (!display) return;
      display.textContent = formatSeconds(getTotal());
    };

    const applyState = (state) => {
      if (!state) return;
      running = !!state.running;
      startTs = parseInt(state.start_ts || '0', 10);
      totalCompleted = parseInt(state.total_completed || '0', 10);
      updateButtons();
      updateDisplay();
    };

    const sendAction = (action) => {
      const url = el.dataset.url;
      const token = el.dataset.token;
      const taskId = el.dataset.taskId;
      const itemtype = el.dataset.itemtype;

      const form = new FormData();
      form.append('action', action);
      form.append('items_id', taskId);
      form.append('itemtype', itemtype);
      form.append('_glpi_csrf_token', token);

      fetch(url, {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
      })
        .then((res) => res.json())
        .then((data) => {
          if (message) message.textContent = data.message || '';
          applyState(data.state);
        })
        .catch(() => {
          if (message) message.textContent = 'Request failed';
        });
    };

    if (startBtn) {
      startBtn.addEventListener('click', () => sendAction('start'));
    }
    if (stopBtn) {
      stopBtn.addEventListener('click', () => sendAction('stop'));
    }

    updateButtons();
    updateDisplay();
    setInterval(updateDisplay, 1000);
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-autostatus-timer]').forEach(initTimer);
  });
})();
