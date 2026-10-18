(() => {
  const form = document.getElementById('inquiry-form');
  const status = document.getElementById('status');
  const button = document.getElementById('send-inquiry');
  const tokenField = document.getElementById('csrf-token');
  let sending = false;

  async function loadToken() {
    if (location.protocol === 'file:') {
      throw new Error('Please submit your inquiry at https://zapeks.com/contact.html.');
    }
    const response = await fetch(form.action, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    });
    const result = await response.json();
    if (!response.ok || !result.csrfToken) {
      throw new Error('The form is temporarily unavailable. Please refresh the page or email sales@zapeks.com.');
    }
    tokenField.value = result.csrfToken;
    return result.csrfToken;
  }

  let tokenRequest = loadToken().catch(() => null);

  form.addEventListener('input', (event) => {
    if (typeof event.target.setCustomValidity === 'function') {
      event.target.setCustomValidity('');
    }
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (sending || !form.reportValidity()) return;

    sending = true;
    button.disabled = true;
    button.textContent = 'Sending...';
    form.setAttribute('aria-busy', 'true');
    status.dataset.state = 'pending';
    status.textContent = 'Sending your inquiry...';

    try {
      const token = await tokenRequest || await loadToken();
      tokenField.value = token;
      const response = await fetch(form.action, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: new URLSearchParams(new FormData(form))
      });
      const result = await response.json();
      if (!response.ok || !result.ok) {
        for (const [name, message] of Object.entries(result.errors || {})) {
          const field = form.elements.namedItem(name);
          if (field && typeof field.setCustomValidity === 'function') {
            field.setCustomValidity(message);
          }
        }
        form.reportValidity();
        if (response.status === 403) {
          tokenField.value = '';
          tokenRequest = loadToken().catch(() => null);
        }
        throw new Error(result.message || 'We could not submit your inquiry. Please try again or email sales@zapeks.com.');
      }

      form.reset();
      tokenField.value = token;
      status.dataset.state = 'success';
      status.textContent = result.message + (result.reference ? ` Reference: ${result.reference}.` : '');
      status.focus();
    } catch (error) {
      status.dataset.state = 'error';
      status.textContent = error instanceof TypeError || error instanceof SyntaxError
        ? 'We could not reach the inquiry service. Your details are still here. Please try again or email sales@zapeks.com.'
        : error.message;
    } finally {
      sending = false;
      button.disabled = false;
      button.textContent = 'Send inquiry';
      form.removeAttribute('aria-busy');
    }
  });
})();
