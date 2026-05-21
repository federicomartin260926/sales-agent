(() => {
  const root = document.querySelector('[data-tenant-draft-assistant]');
  if (!root) {
    return;
  }

  const endpoint = root.dataset.endpoint || '';
  const csrfToken = root.dataset.csrfToken || '';
  const initialMessage = root.dataset.initialMessage || 'Hola. Te ayudaré a completar la ficha del negocio.';
  const chatLog = root.querySelector('[data-chat-log]');
  const statusBox = root.querySelector('[data-chat-status]');
  const chatForm = root.querySelector('[data-chat-form]');
  const chatInput = root.querySelector('[data-chat-input]');
  const sendButton = root.querySelector('[data-send-button]');
  const applyButton = root.querySelector('[data-apply-button]');
  const tenantForm = document.querySelector('form.tenant-form');
  const modal = window.bootstrap?.Modal?.getOrCreateInstance
    ? window.bootstrap.Modal.getOrCreateInstance(root)
    : null;

  const state = {
    conversation: [],
    currentDraft: null,
    initialized: false,
    loading: false,
  };

  const formFieldMap = {
    name: 'name',
    slug: 'slug',
    tone: 'tone',
    whatsappPhoneNumberId: 'whatsappPhoneNumberId',
    whatsappPublicPhone: 'whatsappPublicPhone',
    businessContext: 'businessContext',
    salesPolicyWelcome: 'positioning',
    salesPolicyQualification: 'qualificationFocus',
    salesPolicyHandoff: 'handoffRules',
    salesPolicyLimits: 'salesBoundaries',
    salesPolicyNotes: 'notes',
  };

  function appendMessage(role, content) {
    if (!chatLog) {
      return;
    }

    const message = document.createElement('div');
    message.className = `tenant-draft-assistant-message tenant-draft-assistant-message-${role === 'user' ? 'user' : 'assistant'}`;
    message.textContent = content;
    chatLog.appendChild(message);
    chatLog.scrollTop = chatLog.scrollHeight;
  }

  function setStatus(message, isError = false) {
    if (!statusBox) {
      return;
    }

    if (!message) {
      statusBox.hidden = true;
      statusBox.textContent = '';
      statusBox.classList.remove('tenant-draft-assistant-status-error');
      return;
    }

    statusBox.hidden = false;
    statusBox.textContent = message;
    statusBox.classList.toggle('tenant-draft-assistant-status-error', isError);
  }

  function setLoading(loading) {
    state.loading = loading;
    if (sendButton) {
      sendButton.disabled = loading;
    }
    if (applyButton && !applyButton.hidden) {
      applyButton.disabled = loading;
    }
    if (chatInput) {
      chatInput.disabled = loading;
    }
    root.setAttribute('aria-busy', loading ? 'true' : 'false');
  }

  function ensureInitialMessage() {
    if (state.initialized) {
      return;
    }

    state.initialized = true;
    state.conversation.push({ role: 'assistant', content: initialMessage });
    appendMessage('assistant', initialMessage);
  }

  function readCheckbox(name) {
    const field = tenantForm?.querySelector(`[name="${name}"]`);
    return field instanceof HTMLInputElement ? field.checked : false;
  }

  function readValue(name) {
    const field = tenantForm?.querySelector(`[name="${name}"]`);
    if (!field) {
      return '';
    }

    if (field instanceof HTMLInputElement && field.type === 'checkbox') {
      return field.checked ? '1' : '';
    }

    if ('value' in field) {
      return String(field.value ?? '').trim();
    }

    return '';
  }

  function collectCurrentFormValues() {
    return {
      name: readValue('name'),
      slug: readValue('slug'),
      tone: readValue('tone'),
      whatsappPhoneNumberId: readValue('whatsappPhoneNumberId'),
      whatsappPublicPhone: readValue('whatsappPublicPhone'),
      businessContext: readValue('businessContext'),
      positioning: readValue('positioning'),
      qualificationFocus: readValue('qualificationFocus'),
      handoffRules: readValue('handoffRules'),
      salesBoundaries: readValue('salesBoundaries'),
      notes: readValue('notes'),
      isActive: readCheckbox('isActive'),
    };
  }

  function setFieldValue(name, value) {
    const field = tenantForm?.querySelector(`[name="${name}"]`);
    if (!field || value === undefined || value === null) {
      return;
    }

    if (field instanceof HTMLInputElement && field.type === 'checkbox') {
      field.checked = Boolean(value);
      field.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    if ('value' in field) {
      field.value = String(value);
      field.dispatchEvent(new Event('input', { bubbles: true }));
      field.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function applyDraft(draft) {
    if (!draft || typeof draft !== 'object') {
      return;
    }

    Object.entries(formFieldMap).forEach(([draftKey, fieldName]) => {
      if (!Object.prototype.hasOwnProperty.call(draft, draftKey)) {
        return;
      }

      const value = draft[draftKey];
      if (typeof value === 'string') {
        if (value.trim() !== '') {
          setFieldValue(fieldName, value);
        }
        return;
      }

      if (typeof value === 'boolean') {
        setFieldValue(fieldName, value);
      }
    });

    if (Object.prototype.hasOwnProperty.call(draft, 'isActive') && typeof draft.isActive === 'boolean') {
      setFieldValue('isActive', draft.isActive);
    }
  }

  function updateApplyButton(canApply) {
    if (!applyButton) {
      return;
    }

    applyButton.hidden = !canApply;
    applyButton.disabled = state.loading || !canApply;
  }

  function renderResponse(response) {
    if (!response || typeof response !== 'object') {
      setStatus('La guía devolvió una respuesta no válida.', true);
      return;
    }

    const answer = typeof response.answer === 'string' ? response.answer.trim() : '';
    const status = typeof response.status === 'string' ? response.status : 'asking';
    const draft = response.draft && typeof response.draft === 'object' ? response.draft : null;

    if (answer !== '') {
      state.conversation.push({ role: 'assistant', content: answer });
      appendMessage('assistant', answer);
    }

    state.currentDraft = draft;
    updateApplyButton(status === 'ready' && draft !== null);
    setStatus(status === 'ready'
      ? 'Borrador listo. Revisa los campos y pulsa "Aplicar a la ficha" si quieres llevarlo al formulario.'
      : '');
  }

  async function sendMessage(rawMessage) {
    const message = String(rawMessage || '').trim();
    if (message === '' || state.loading) {
      return;
    }

    ensureInitialMessage();
    state.conversation.push({ role: 'user', content: message });
    appendMessage('user', message);

    if (chatInput) {
      chatInput.value = '';
    }

    setStatus('');
    updateApplyButton(false);
    setLoading(true);

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          conversation: state.conversation,
          currentMessage: message,
          currentFormValues: collectCurrentFormValues(),
          _csrf_token: csrfToken,
        }),
      });

      const text = await response.text();
      let payload = null;
      try {
        payload = text ? JSON.parse(text) : null;
      } catch (error) {
        throw new Error('La respuesta de la guía no es JSON válido.');
      }

      if (!response.ok) {
        throw new Error((payload && payload.message) || 'La guía no ha podido responder.');
      }

      renderResponse(payload);
    } catch (error) {
      setStatus(error instanceof Error ? error.message : 'No se ha podido contactar con la guía IA.', true);
      appendMessage('assistant', 'No he podido procesar tu petición en este momento. Inténtalo de nuevo.');
    } finally {
      setLoading(false);
      if (chatInput) {
        chatInput.focus();
      }
    }
  }

  if (chatForm) {
    chatForm.addEventListener('submit', (event) => {
      event.preventDefault();
      sendMessage(chatInput ? chatInput.value : '');
    });
  }

  if (chatInput) {
    chatInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendMessage(chatInput.value);
      }
    });
  }

  if (applyButton) {
    applyButton.addEventListener('click', () => {
      if (!state.currentDraft) {
        return;
      }

      applyDraft(state.currentDraft);
      setStatus('Borrador aplicado al formulario. Revisa y guarda manualmente.');
    });
  }

  root.addEventListener('shown.bs.modal', () => {
    ensureInitialMessage();
    updateApplyButton(state.currentDraft !== null);
    if (chatInput) {
      chatInput.focus();
    }
  });

  root.addEventListener('hidden.bs.modal', () => {
    setStatus('');
  });

  if (modal) {
    const trigger = document.querySelector('[data-bs-target="#tenant-draft-assistant-modal"]');
    if (trigger) {
      trigger.addEventListener('click', () => {
        ensureInitialMessage();
        updateApplyButton(state.currentDraft !== null);
      });
    }
  }
})();
