(() => {
  const root = document.querySelector('[data-playbook-draft-assistant]');
  if (!root) {
    return;
  }

  const endpoint = root.dataset.endpoint || '';
  const csrfToken = root.dataset.csrfToken || '';
  const initialMessageText = (root.dataset.initialMessage || '').trim();
  const chatLog = root.querySelector('[data-chat-log]');
  const statusBox = root.querySelector('[data-chat-status]');
  const autoApplyNote = root.querySelector('[data-auto-apply-note]');
  const chatForm = root.querySelector('[data-chat-form]');
  const chatInput = root.querySelector('[data-chat-input]');
  const sendButton = root.querySelector('[data-send-button]');
  const playbookForm = document.querySelector('form.playbook-form');
  const modal = window.bootstrap?.Modal?.getOrCreateInstance
    ? window.bootstrap.Modal.getOrCreateInstance(root)
    : null;

  const state = {
    conversation: [],
    currentDraft: null,
    initialized: false,
    userMessages: 0,
    loading: false,
  };
  const bodyScrollClass = 'ai-assistant-modal-open';

  const formFieldMap = {
    name: 'name',
    objective: 'objective',
    qualificationQuestions: 'qualificationQuestions',
    maxScore: 'maxScore',
    handoffThreshold: 'handoffThreshold',
    positiveSignals: 'positiveSignals',
    negativeSignals: 'negativeSignals',
    agendaRules: 'agendaRules',
    handoffRules: 'handoffRules',
    allowedActions: 'allowedActions',
    notes: 'notes',
    isActive: 'isActive',
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
    if (chatInput) {
      chatInput.disabled = loading;
    }
    root.setAttribute('aria-busy', loading ? 'true' : 'false');
  }

  function lockBodyScroll() {
    document.body.classList.add(bodyScrollClass);
  }

  function unlockBodyScroll() {
    document.body.classList.remove(bodyScrollClass);
  }

  function ensureInitialMessage() {
    if (state.initialized && state.userMessages > 0) {
      return;
    }

    const initialMessage = buildInitialMessage();
    if (state.initialized && state.userMessages === 0) {
      state.conversation = [];
      if (chatLog) {
        chatLog.innerHTML = '';
      }
    }

    state.initialized = true;
    state.conversation.push({ role: 'assistant', content: initialMessage });
    appendMessage('assistant', initialMessage);
  }

  function buildInitialMessage() {
    const tenantName = readTenantName();
    const productName = readProductName();
    const existingContent = hasExistingContent();

    if (tenantName === '') {
      return 'Primero selecciona un negocio para que pueda usar su contexto general. Después te ayudaré a definir una estrategia específica.';
    }

    if (existingContent) {
      const intro = productName !== ''
        ? `Veo que esta guía ya tiene contenido y está asociada a ${productName}. La usaré como base.`
        : 'Veo que esta guía ya tiene contenido. La usaré como base.';

      return `${intro} Para revisar o completar lo que falte:
1. ¿Qué quieres ajustar exactamente: objetivo, cualificación, scoring, agenda, handoff, acciones o notas?
2. ¿Qué caso concreto debe cubrir o mejorar esta guía?
3. ¿Qué debe hacer distinto el agente?
4. ¿Cuándo debe derivar a una persona?`;
    }

    if (productName !== '') {
      return `${initialMessageText !== '' ? initialMessageText : 'Hola. Te ayudaré a definir una estrategia específica para esta guía comercial.'} Veo que esta guía está asociada a ${productName}. Para empezar:
1. ¿Quieres que sirva para captar citas, resolver dudas o priorizar leads?
2. ¿En qué situación concreta se usará: campaña, canal o caso especial?
3. ¿Qué tipo de cliente o lead quieres atender?
4. ¿Cuándo debe derivar a una persona?

Si quieres, respóndeme en una frase y yo lo ordeno.`;
    }

    return `${initialMessageText !== '' ? initialMessageText : 'Hola. Te ayudaré a definir una estrategia específica para esta guía comercial.'} Para empezar:
1. ¿En qué situación concreta se usará: producto, campaña, canal o caso especial?
2. ¿Qué tipo de cliente o lead quieres captar o atender?
3. ¿Qué debe conseguir el agente?
4. ¿Cuándo debe derivar a una persona?

Si quieres, respóndeme en una frase y yo lo ordeno.`;
  }

  function readTenantName() {
    const select = playbookForm?.querySelector('[name="tenantId"]');
    if (!(select instanceof HTMLSelectElement)) {
      return '';
    }

    if (select.value.trim() === '') {
      return '';
    }

    const option = select.selectedOptions?.[0];
    return option ? String(option.textContent || '').trim() : '';
  }

  function readProductName() {
    const select = playbookForm?.querySelector('[name="productId"]');
    if (!(select instanceof HTMLSelectElement)) {
      return '';
    }

    if (select.value.trim() === '') {
      return '';
    }

    const option = select.selectedOptions?.[0];
    return option ? String(option.textContent || '').trim() : '';
  }

  function hasExistingContent() {
    return [
      'objective',
      'qualificationQuestions',
      'maxScore',
      'handoffThreshold',
      'positiveSignals',
      'negativeSignals',
      'agendaRules',
      'handoffRules',
      'allowedActions',
      'notes',
    ].some((name) => readValue(name) !== '');
  }

  function readCheckbox(name) {
    const field = playbookForm?.querySelector(`[name="${name}"]`);
    return field instanceof HTMLInputElement ? field.checked : false;
  }

  function readValue(name) {
    const field = playbookForm?.querySelector(`[name="${name}"]`);
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
      tenantId: readValue('tenantId'),
      productId: readValue('productId'),
      name: readValue('name'),
      objective: readValue('objective'),
      qualificationQuestions: readValue('qualificationQuestions'),
      maxScore: readValue('maxScore'),
      handoffThreshold: readValue('handoffThreshold'),
      positiveSignals: readValue('positiveSignals'),
      negativeSignals: readValue('negativeSignals'),
      agendaRules: readValue('agendaRules'),
      handoffRules: readValue('handoffRules'),
      allowedActions: readValue('allowedActions'),
      notes: readValue('notes'),
      isActive: readCheckbox('isActive'),
    };
  }

  function setFieldValue(name, value) {
    const field = playbookForm?.querySelector(`[name="${name}"]`);
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
      return 0;
    }

    let updates = 0;
    Object.entries(formFieldMap).forEach(([draftKey, fieldName]) => {
      if (!Object.prototype.hasOwnProperty.call(draft, draftKey)) {
        return;
      }

      const value = draft[draftKey];
      if (typeof value === 'string') {
        if (value.trim() !== '') {
          updates += 1;
          setFieldValue(fieldName, value);
        }
        return;
      }

      if (typeof value === 'boolean') {
        updates += 1;
        setFieldValue(fieldName, value);
      }
    });

    if (Object.prototype.hasOwnProperty.call(draft, 'scoring') && draft.scoring && typeof draft.scoring === 'object') {
      const scoring = draft.scoring;
      const scoringMap = {
        maxScore: 'maxScore',
        handoffThreshold: 'handoffThreshold',
        positiveSignals: 'positiveSignals',
        negativeSignals: 'negativeSignals',
      };

      Object.entries(scoringMap).forEach(([draftKey, fieldName]) => {
        if (!Object.prototype.hasOwnProperty.call(scoring, draftKey)) {
          return;
        }

        const value = scoring[draftKey];
        if (Array.isArray(value)) {
          const lines = value
            .map((line) => String(line ?? '').trim())
            .filter((line) => line !== '');
          if (lines.length > 0) {
            setFieldValue(fieldName, lines.join('\n'));
            updates += 1;
          }
          return;
        }

        if (typeof value === 'string' && value.trim() !== '') {
          setFieldValue(fieldName, value);
          updates += 1;
        }

        if (typeof value === 'number') {
          setFieldValue(fieldName, value);
          updates += 1;
        }
      });
    }

    return updates;
  }

  function setAutoApplyNote(message, isError = false) {
    if (!autoApplyNote) {
      return;
    }

    if (!message) {
      autoApplyNote.hidden = true;
      autoApplyNote.textContent = '';
      autoApplyNote.classList.remove('tenant-draft-assistant-auto-note-error');
      return;
    }

    autoApplyNote.hidden = false;
    autoApplyNote.textContent = message;
    autoApplyNote.classList.toggle('tenant-draft-assistant-auto-note-error', isError);
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
    if (draft !== null) {
      const updates = applyDraft(draft);
      setAutoApplyNote(
        updates > 0
          ? 'He actualizado la guía con la información disponible. Puedes seguir ajustando o guardar cuando la revises.'
          : 'Ya he revisado la guía con la información disponible. Puedes seguir ajustando o guardar cuando la revises.'
      );
    } else {
      setAutoApplyNote('');
    }

    setStatus(status === 'ready'
      ? 'Ya he completado una propuesta inicial en la guía. Revísala y dime si quieres ajustar el objetivo, la cualificación, el scoring, la agenda, el handoff o las notas.'
      : '');
  }

  async function sendMessage(rawMessage) {
    const message = String(rawMessage || '').trim();
    if (message === '' || state.loading) {
      return;
    }

    ensureInitialMessage();
    state.userMessages += 1;
    state.conversation.push({ role: 'user', content: message });
    appendMessage('user', message);

    if (chatInput) {
      chatInput.value = '';
    }

    setStatus('Pensando...');
    setAutoApplyNote('');
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
    } finally {
      setLoading(false);
      if (chatInput) {
        chatInput.focus();
      }
    }
  }

  if (chatInput) {
    chatInput.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        sendButton?.click();
      }
    });
  }

  if (sendButton) {
    sendButton.addEventListener('click', (event) => {
      event.preventDefault();
      sendMessage(chatInput ? chatInput.value : '');
    });
  }

  if (chatForm) {
    chatForm.addEventListener('submit', (event) => {
      event.preventDefault();
      sendButton?.click();
    });
  }

  root.addEventListener('shown.bs.modal', () => {
    lockBodyScroll();
    ensureInitialMessage();
    if (chatInput) {
      chatInput.focus();
    }
  });

  root.addEventListener('show.bs.modal', () => {
    lockBodyScroll();
  });

  root.addEventListener('hidden.bs.modal', () => {
    setStatus('');
    unlockBodyScroll();
  });

  root.addEventListener('hide.bs.modal', () => {
    unlockBodyScroll();
  });

  if (modal) {
    const trigger = document.querySelector('[data-bs-target="#playbook-draft-assistant-modal"]');
    if (trigger) {
      trigger.addEventListener('click', () => {
        ensureInitialMessage();
      });
    }
  }
})();
