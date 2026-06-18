# Sales Agent API refactor: LLM context + tools v2 audio

Este ZIP reemplaza la API por una base mínima para la nueva arquitectura.

## Principio principal

Sales Agent no interpreta lenguaje humano con heurísticas. SA no decide que "mañana a las 5" significa un slot concreto. SA prepara contexto estructurado y herramientas; el LLM interpreta, razona, selecciona slot o pregunta aclaración.

## Flujo runtime

1. `runtime.py` resuelve tenant/routing.
2. Si el mensaje es audio, descarga y transcribe el media de WhatsApp.
3. El texto resultante entra en el mismo flujo que cualquier mensaje de texto.
4. Se persiste inbound.
5. El LLM clasifica intención en un JSON pequeño.
6. `agent_orchestration/context_builder.py` prepara contexto: histórico, slots ofrecidos, slot seleccionado, tenant, producto, playbook, contacto y timezone.
7. `agent_orchestration/tool_selector.py` habilita tools MCP según intención.
8. El LLM recibe contexto + tools y genera respuesta final, pudiendo usar MCP.
9. SA valida únicamente consistencia dura, por ejemplo que `selected_slot` exista en `appointment.offered_slots`.
10. Se persiste outbound.

## Audio

El audio está integrado como preprocesamiento:

- `AudioGatewayClient` descarga el media desde `wa-gateway-api`.
- `AudioTranscriptionClient` transcribe con OpenAI.
- La transcripción se añade a `payload.message.text` y a `payload.message.media.transcript`.
- Se registra evento de uso IA `usage_type=audio_transcription` cuando hay conversación/mensaje persistido.
- Si el audio falla, se devuelve una respuesta controlada pidiendo texto.

## Estructura nueva relevante

- `app/services/runtime.py`: runtime lineal, mínimo y documentado.
- `app/services/agent_orchestration/schemas.py`: contratos pequeños entre SA y LLM.
- `app/services/agent_orchestration/prompts.py`: prompts del planner y del turno final con tools.
- `app/services/agent_orchestration/context_builder.py`: única zona donde se permite complejidad de contexto/histórico/slots.
- `app/services/agent_orchestration/tool_selector.py`: política simple de tools por intención.

## Qué se eliminó del ZIP

- Tests.
- Carpetas legacy/shadow anteriores de `agent_orchestration`.
- Resolución local de slot por hora/índice/owner.
- Preconfirmation local y ejecución local de reglas conversacionales.

## Qué queda pendiente de depurar

- Compatibilidad exacta de prompts con `LLMClient.generate_with_mcp` y formato final devuelto por OpenAI Responses.
- End-to-end real WhatsApp/audio.
- Ajustar tool policy según confirmación explícita o confirmación directa.
- Reincorporar tests focalizados cuando el flujo manual funcione.
