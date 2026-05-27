(() => {
  const root = document.querySelector('[data-tenant-draft-assistant]');
  if (!root) {
    return;
  }

  const endpoint = root.dataset.endpoint || '';
  const csrfToken = root.dataset.csrfToken || '';
  const initialMessageText = (root.dataset.initialMessage || '').trim();
  const submitLabel = (root.dataset.submitLabel || 'Crear negocio').trim() || 'Crear negocio';
  const chatLog = root.querySelector('[data-chat-log]');
  const statusBox = root.querySelector('[data-chat-status]');
  const autoApplyNote = root.querySelector('[data-auto-apply-note]');
  const chatForm = root.querySelector('[data-chat-form]');
  const chatInput = root.querySelector('[data-chat-input]');
  const sendButton = root.querySelector('[data-send-button]');
  const tenantForm = document.querySelector('form.tenant-form');
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
    slug: 'slug',
    tone: 'tone',
    whatsappPhoneNumberId: 'whatsappPhoneNumberId',
    whatsappPublicPhone: 'whatsappPublicPhone',
    humanHandoffEnabled: 'humanHandoffEnabled',
    humanHandoffWhatsappPublic: 'humanHandoffWhatsappPublic',
    humanHandoffMessage: 'humanHandoffMessage',
    humanHandoffStrategy: 'humanHandoffStrategy',
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
    const name = readValue('name');
    const tone = readValue('tone');
    const introBase = initialMessageText !== ''
      ? initialMessageText
      : 'Hola. Te ayudaré a completar la ficha del negocio.';
    const intro = name !== ''
      ? `${introBase} Veo que estás trabajando en ${name}.`
      : introBase;
    const toneHint = tone !== '' ? ` Veo que ya tienes un tono definido como "${tone}".` : '';

    return `${intro}${toneHint} Para empezar:
1. ¿Cuál es el nombre comercial del negocio?
2. ¿Qué vende u ofrece?
3. ¿En qué ciudad o zona atiende?
4. ¿Qué tipo de cliente quiere captar?
5. ¿Qué tono debe usar el agente IA?
6. ¿Cuál es el WhatsApp público del agente IA para enlaces wa.me, si ya lo tienes?
7. Si quieres derivación humana, ¿cuál es el WhatsApp humano para atender conversaciones manuales?
8. ¿Qué mensaje quieres que use el agente cuando derive a una persona?

Si no estás seguro, deja los campos de WhatsApp y handoff sin completar.

Yo no guardo nada: tú revisarás y pulsarás "${submitLabel}" al final.`;
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
      humanHandoffEnabled: readCheckbox('humanHandoffEnabled'),
      humanHandoffWhatsappPublic: readValue('humanHandoffWhatsappPublic'),
      humanHandoffMessage: readValue('humanHandoffMessage'),
      humanHandoffStrategy: readValue('humanHandoffStrategy'),
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

    if (Object.prototype.hasOwnProperty.call(draft, 'isActive') && typeof draft.isActive === 'boolean') {
      setFieldValue('isActive', draft.isActive);
      updates += 1;
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
          ? 'He actualizado la ficha con la información disponible. Puedes seguir ajustando o guardar cuando la revises.'
          : 'Ya he revisado la ficha con la información disponible. Puedes seguir ajustando o guardar cuando la revises.'
      );
    } else {
      setAutoApplyNote('');
    }

    setStatus(status === 'ready'
      ? 'Ya he completado una propuesta inicial en la ficha. Revísala y dime si quieres ajustar tono, límites, cualificación o derivación a humano.'
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
    const trigger = document.querySelector('[data-bs-target="#tenant-draft-assistant-modal"]');
    if (trigger) {
      trigger.addEventListener('click', () => {
        ensureInitialMessage();
      });
    }
  }
})();
