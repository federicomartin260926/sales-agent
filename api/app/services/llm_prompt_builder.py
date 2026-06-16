from __future__ import annotations

import json
from datetime import datetime
import re
import unicodedata
from typing import Any
from zoneinfo import ZoneInfo

from app.config import Settings, get_settings
from app.schemas.agent import AgentRequest
from app.services.backend_client import CommercialContext
from app.schemas.llm import McpRemoteConfig
from app.services.routing_resolver import RoutingContext
from app.services.llm_context_helper import LLMContextHelper


class LLMPromptBuilder:
    def __init__(
        self,
        context_helper: LLMContextHelper | None = None,
        settings: Settings | None = None,
    ) -> None:
        self.context_helper = context_helper or LLMContextHelper()
        self.settings = settings or get_settings()

    def build(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
        mcp_config: McpRemoteConfig | None = None,
    ) -> tuple[str, str]:
        system_prompt = (
            "Eres un asistente de ventas por WhatsApp para sales-agent/api. "
            "Responde siempre en español, breve y natural. "
            "Devuelve solo JSON válido, sin markdown ni texto adicional, con estas claves: "
            "reply, intent, score, action, needs_human, data_to_save. "
            "No inventes precios, plazos ni funcionalidades. "
            "Si preguntan por precio y no hay un precio claro, pide 1-2 datos de cualificación. "
            "Si piden humano/persona/asesor/comercial, needs_human debe ser true. "
            "El producto o servicio ya debe venir resuelto en product o product_selection; no uses playbook para descubrirlo. "
            "Si product_selection.needs_service_clarification es true, pregunta qué servicio busca antes de profundizar. "
            "Si products incluye varios candidatos, usa solo esos candidatos y pide confirmación si hay ambigüedad. "
            "Si product_selection.fallback_to_mcp_allowed es true y no hay un producto local claro, puedes usar herramientas MCP de búsqueda de servicios si están disponibles. "
            "Si no hay catálogo local o el catálogo local no es concluyente y services_search está disponible, úsalo como fuente principal antes de responder. "
            "Si tienes herramientas MCP nativas del tenant, úsalas cuando hagan falta para completar la respuesta. "
            "No inventes datos ni cites sistemas internos, CRM, n8n, webhooks, IDs internos, UTM, refs, tokens o detalles técnicos. "
            "Mantén el tono breve, útil y orientado a conversación. "
            "La conversación es continua: usa el contexto previo relevante para mantener continuidad, "
            "prioriza siempre el último mensaje del usuario si corrige o concreta un dato, "
            "no repitas preguntas ya respondidas y combina el mensaje actual con la información previa útil "
            "cuando hables de servicios, agenda, reservas, precios, preferencias, objeciones o handoff. "
            "No arrastres detalles antiguos irrelevantes."
        )
        contact_context_payload = self._external_context_payload(contact_context)
        recent_contact_context_payload = self._recent_contact_context_payload(payload.conversation.context_messages)
        effective_contact_context_payload = contact_context_payload or recent_contact_context_payload
        current_timezone, current_timezone_source = self._resolve_business_timezone_details(
            backend_context,
            effective_contact_context_payload,
        )
        current_business_time = self._current_business_time(current_timezone)
        current_date = current_business_time.date().isoformat()
        appointment_context = self._appointment_context_payload(
            payload.conversation.context_messages,
            timezone=current_timezone,
            timezone_source=current_timezone_source,
        )
        system_prompt += (
            f" Contexto temporal explícito: current_datetime={current_business_time.isoformat()}, "
            f"current_date={current_date}, timezone={current_timezone}. "
            "Usa temporal_context.timezone como referencia local del negocio o sucursal. "
            "El bloque operational_context resume la timezone operativa y tiene prioridad para agenda y confirmación. "
            "Si el backend no proporciona timezone específica, ya se habrá aplicado el fallback configurado por el sistema. "
            "No inventes otra zona horaria. "
            "Las expresiones relativas como hoy, mañana, pasado mañana, esta tarde o mañana por la tarde "
            "se resuelven siempre respecto al mensaje actual y a este contexto temporal del turno, "
            "no respecto a mensajes anteriores, resúmenes históricos ni referencias temporales previas. "
            "Si el historial menciona fechas relativas antiguas, ignóralas para esta nueva petición salvo "
            "que el usuario diga explícitamente que quiere reutilizar ese contexto. "
            "Si el usuario menciona un mes sin año, usa el año actual salvo que el contexto indique otro año. "
            "Para consultas de agenda o citas, envía date_from/date_to en formato ISO YYYY-MM-DD "
            "o ISO datetime coherente con la fecha actual. "
            "No uses años pasados salvo que el usuario lo pida explícitamente."
        )

        if mcp_config is not None and mcp_config.enabled and (mcp_config.server_label or "").strip() != "":
            allowed_tools_list = [tool.strip() for tool in mcp_config.allowed_tools if tool.strip() != ""]
            allowed_tools = ", ".join(allowed_tools_list) if allowed_tools_list else "las herramientas autorizadas"
            system_prompt += (
                f" Tienes acceso a un MCP remoto nativo del tenant llamado {mcp_config.server_label.strip()}. "
                f"Úsalo cuando necesites datos o acciones cubiertas por {allowed_tools}. "
                "Si el MCP no está disponible, sigue con el flujo normal y no inventes resultados. "
                "Si appointment_availability está disponible y el usuario pide reservar, agendar, consultar disponibilidad, "
                "huecos, horarios o citas para esta semana, úsala para consultar agenda real. "
                "Si appointment_events está disponible y el usuario pregunta por su agenda o citas ya registradas, úsala para consultar las citas existentes. "
                "No respondas que no puedes consultar la agenda cuando esas tools están disponibles. "
                "Si el usuario pregunta por productos, servicios, catálogo, opciones disponibles, "
                "servicios por categoría, precios orientativos, servicios contratables o alternativas "
                "al producto actual, usa services_search cuando esté disponible y el contexto local "
                "no contenga una lista explícita y suficiente de servicios. "
                "Uso de services_search: para búsquedas comerciales generales formula consultas cortas y amplias. "
                "La query debe ser normalmente de 1 palabra o, como máximo, 2 palabras. "
                "No copies literalmente frases compuestas del usuario cuando mezclen varios conceptos. "
                "Si el usuario combina varios conceptos, elige primero el término más amplio o nuclear. "
                "Usa bookable=null por defecto salvo que el usuario pida explícitamente reservar, agendar, consultar disponibilidad, "
                "una cita, huecos o calendario. Usa bookable=true solo en intención clara de reserva o agenda. "
                "Evita frases compuestas largas; prefiere términos nucleares como IA, automatización, CRM, WhatsApp, ventas, auditoría o integración. "
                "Si el usuario pregunta de forma general qué servicios tenéis, opciones, soluciones o qué ofrecéis, usa una query amplia o vacía y bookable=null. "
                "Si una búsqueda exacta devuelve 0 resultados y puedes hacer otra llamada de herramienta en el mismo turno, prueba una búsqueda más amplia una vez antes de concluir que no hay servicios relacionados. "
                "Ejemplos de uso correcto de services_search: "
                "Usuario 'WhatsApp Business con IA' -> correcto query='IA' o query='automatización', bookable=null; incorrecto query='WhatsApp Business IA'. "
                "Usuario 'CRM con automatización de ventas' -> correcto query='CRM' o query='automatización', bookable=null; incorrecto query='CRM automatización ventas'. "
                "Usuario 'soluciones de ventas con IA' -> correcto query='ventas' o query='IA', bookable=null; incorrecto query='soluciones ventas IA'. "
                "Usuario 'quiero reservar una auditoría' -> correcto query='auditoría', bookable=true. "
                "Si services_search devuelve items con id, usa item.id como service_id canónico en appointment_availability, appointment_confirm y appointment_booking_invitation. "
                "Si el usuario pide disponibilidad o reserva para un servicio concreto y todavía no tienes un service_id canónico resuelto, usa primero services_search antes de appointment_availability. "
                "Prioriza siempre el service_id UUID devuelto por services_search para appointment_availability. "
                "Usa service_ref solo como fallback con slug, integration_key o referencia externa cuando no exista item.id; no inventes service_ref simplificados como laser-axilas. "
                "Nunca metas el slug o integration_key dentro de service_id. "
                "Usa duration_minutes real del servicio devuelto por services_search; no uses una duración inventada si ya tienes el servicio resuelto. "
                "Cuando uses services_search, limita por defecto la búsqueda a 5 resultados salvo que "
                "el usuario pida explícitamente ver más opciones. Usa 3-5 resultados para respuestas "
                "conversacionales normales y máximo 6 para comparativas breves. "
                "Para appointment_availability, si el usuario pide una franja como por la tarde, mañana por la tarde o cualquier rango horario concreto, envía date_from y date_to como ISO datetime completo con timezone, no como fechas sueltas sin hora. "
                "Si la tool de agenda acepta timezone, envíala explícitamente usando temporal_context.timezone. "
                "No uses UTC para franjas comerciales. "
                "Regla práctica: mañana = current_date + 1 día; pasado mañana = current_date + 2 días. "
                "Para 'por la mañana' usa un rango aproximado de 09:00 a 14:00. "
                "Para 'por la tarde' usa un rango aproximado de 15:00 a 20:00 o 21:00, pero nunca empieces a las 12:00 salvo que el usuario lo pida explícitamente. "
                "Si el usuario dice mañana o pasado y está pidiendo disponibilidad, convierte eso en días futuros concretos con horas reales; no uses rangos ambiguos sin hora. "
                "No arrastres el 'mañana' de mensajes anteriores: cada nueva consulta relativa se resuelve con el current_message y el current_datetime de este turno. "
                "For any new availability or scheduling request that starts from scratch, call appointment_availability before answering availability or unavailability; never reuse previous availability results or previous_response_id for a fresh search turn. "
                "Si el usuario elige uno de los slots que acabas de ofrecer (por ejemplo: elijo las 17:35, me quedo con la primera, confirma esa, sí, reserva), trátalo como una confirmación del slot previo: reutiliza el slot ofrecido inmediatamente antes, conserva service_id/date/timezone/duration y también owner_id/owner_ref/ownerId/ownerRef si estaban presentes en el slot, no vuelvas a llamar services_search ni appointment_availability desde cero, y usa la herramienta de confirmación o booking si existe. "
                "Si conversation.appointment_context contiene offered_slots, usa exactamente el slot seleccionado desde ese bloque y copia literalmente sus campos start/end/timezone/service_id/owner_id/owner_ref antes de confirmar. "
                "Si conversation.appointment_context.selected_slot existe, ese slot ya está resuelto: no preguntes alternativas ni especialista, no recalcules disponibilidad y llama appointment_confirm inmediatamente con esos datos exactos. "
                "Si conversation.appointment_context incluye timezone o timezone_source, esa es la timezone operativa para confirmar el slot seleccionado y prevalece sobre cualquier timezone histórica de los slots. "
                "Si conversation.context_messages incluye el último turno del asistente con trazas MCP de appointment_availability, toma de ahí el slot exacto ofrecido y sus campos owner/service/time/date antes de confirmar. "
                "owner_id y owner_ref son obligatorios para appointment_confirm: si no puedes identificar el owner del slot seleccionado, no llames a appointment_confirm y pide aclaración o deriva el caso en lugar de intentar reservar incompleto. "
                "Si la petición viene por WhatsApp y contact.phone ya existe en el payload, ese teléfono ya está conocido y debes reutilizarlo; no preguntes otra vez por el teléfono. "
                "Si falta el nombre u otro dato realmente necesario para cerrar la cita, pide solo ese dato faltante y nunca el teléfono cuando contact.phone ya esté disponible. "
                "Para appointment_confirm, sólo puedes afirmar que la cita quedó reservada o confirmada si la tool devuelve ok=true y/o confirmed=true. "
                "Si appointment_confirm devuelve ok=false, confirmed=false, validation_error, crm_error o cualquier otro error, no digas que la cita está reservada o confirmada; tampoco uses frases como te reservo, ya está reservada, confirmada, lista o similar antes de ese éxito explícito; explica que no se pudo confirmar y ofrece una alternativa o handoff humano si procede. "
                "Si no puedes resolver el servicio con suficiente confianza, pide una aclaración breve o usa services_search antes de intentar appointment_availability. "
                "No inventes productos, precios ni disponibilidad. Si services_search devuelve resultados, "
                "responde usando esos resultados de forma breve, clara y comercial. "
                "Si no devuelve resultados o falla, orienta de forma general y pide una aclaración breve."
            )
            system_prompt += (
                " CRITICAL NEXT ACTION: if conversation.appointment_context.required_next_action.must_call_tool is true, "
                "call conversation.appointment_context.required_next_action.tool immediately before any plain-text reply. "
                "Do not emit a provisional acknowledgement. Do not say \"estoy revisando\", \"te avisaré\", "
                "\"si necesito algún dato\", \"lo estoy comprobando\" or similar before the tool call. "
                "Use conversation.appointment_context.selected_slot as the source of truth for the tool arguments. "
                "Only ask a question instead if a required field is missing from selected_slot. "
                "If selected_slot is present and contact.phone or contact.email exists, never answer with an intermediate acknowledgement: "
                "either call appointment_confirm or explain the exact missing field."
            )
            if effective_contact_context_payload is not None:
                system_prompt += (
                    " Si el prompt incluye un bloque contact_context, úsalo como fuente prioritaria de timezone, sucursal y contexto externo del contacto, aunque contact_context no esté en allowed_tools. "
                    "Para agenda, no uses el fallback temporal si contact_context trae timezone. "
                    "appointment_availability.timezone debe copiar exactamente temporal_context.timezone. "
                    "Si contact_context incluye business_context.timezone, usa esa timezone como la efectiva aunque el bloque plano no la repita. "
                    "Si contact_context devuelve needs_branch_selection=true, pregunta la sucursal antes de continuar con services_search o appointment_availability. "
                    "Si contact_context devuelve timezone, úsala exactamente para interpretar hoy, mañana, esta tarde, por la tarde y cualquier franja relativa. "
                    "No inventes branch_id, branch, service_id, owner_id ni timezone. "
                    "Si contact_context no devuelve contexto suficiente, sigue cualificando de forma natural según la política comercial del negocio."
                )
            handoff_strategy = self._handoff_strategy(backend_context)
            if "handoff_request" in allowed_tools_list and handoff_strategy in {"n8n_webhook", "manual_wa_link_and_n8n"}:
                system_prompt += (
                    " Si entre las herramientas autorizadas está handoff_request y la estrategia de handoff del tenant "
                    "requiere tool externa, úsala cuando detectes frustración, queja, bloqueo, riesgo, caso sensible "
                    "o una petición compleja que requiera intervención humana aunque el usuario no lo pida de forma "
                    "explícita. Usa priority='high' para casos urgentes, sensibles o con riesgo, y priority='normal' "
                    "por defecto. "
                    "Cuando llames a handoff_request, incluye el máximo contexto útil sin mandar el historial completo: "
                    "tenant_id, reason, priority si puedes inferirla, message con el último mensaje relevante del usuario, "
                    "contact.name, contact.phone y contact.email si existen, conversation.id si existe, "
                    "conversation.external_conversation_id si existe, conversation.channel si existe, "
                    "conversation.summary si existe y conversation.last_messages con las últimas interacciones relevantes, "
                    "limitadas a un máximo razonable de 6 a 8 mensajes recientes. "
                    "No uses handoff_request para peticiones explícitas de hablar con una persona: ese caso ya lo "
                    "resuelve el runtime de forma rule-based con wa.me cuando la estrategia es manual_wa_link. "
                    "Si la estrategia es n8n_webhook, prioriza la tool externa y no añadas wa.me automáticamente. "
                    "Si la estrategia es manual_wa_link_and_n8n, además puedes incluir wa.me en la respuesta al cliente. "
                    "Si handoff_request devuelve error, no afirmes que has avisado o registrado nada; explica de forma "
                    "breve que no se pudo registrar el handoff y ofrece continuar o reintentar."
                )
            if "crm_contact_submit" in allowed_tools_list:
                system_prompt += (
                    " Si entre las herramientas autorizadas está crm_contact_submit, úsala cuando haya información comercial útil, "
                    "cuando el contacto quede cualificado, cuando se solicite cita, cuando se pida handoff, waitlist o resumen, "
                    "o cuando aparezcan datos nuevos relevantes del contacto. "
                    "Envía contact.name, contact.phone y contact.email si los tienes; source y channel como el origen o canal comercial del contacto; "
                    "nunca uses tenant_id como source; en conversaciones WhatsApp usa source=\"whatsapp\" y channel=\"whatsapp\"; "
                    "si no conoces el canal, usa el canal del contacto o de la conversación si está disponible, o omítelo en lugar de inventar tenant_id; "
                    "tenant_id solo debe usarse si la tool lo pide como argumento separado. "
                    "Incluye qualification junto con el resto del contexto comercial. "
                    "conversation.summary; conversation.intent; actions.booking_requested, handoff_requested y waitlist_requested "
                    "cuando apliquen; metadata.origin=sales_agent; metadata.sa_conversation_id si está disponible; "
                    "metadata.service_slug y metadata.service_integration_key si están disponibles. "
                    "No decidas si el resultado debe ser lead, customer o note: lo decide CRM según su configuración. "
                    "No llames crm_contact_submit en cada mensaje si no hay información nueva útil."
                )

        user_payload = {
            "temporal_context": {
                "current_datetime": current_business_time.isoformat(),
                "current_date": current_date,
                "timezone": current_timezone,
                **({"timezone_source": current_timezone_source} if current_timezone_source is not None else {}),
            },
            "operational_context": self._operational_context_payload(
                payload,
                current_timezone,
                current_timezone_source,
                effective_contact_context_payload,
            ),
            "tenant": self._tenant_payload(backend_context),
            "product": self._product_payload(backend_context),
            "products": self._products_payload(backend_context),
            "product_selection": self._product_selection_payload(backend_context),
            "playbook": self._playbook_payload(backend_context),
            "entry_point": self._entry_point_payload(backend_context),
            "sales_runtime": self._sales_runtime_payload(backend_context),
            "routing": self._routing_payload(routing),
            **({"contact_context": effective_contact_context_payload} if effective_contact_context_payload is not None else {}),
            "contact": {
                "phone": self.context_helper.sanitize_text(payload.contact.phone, max_chars=64),
                "name": self.context_helper.sanitize_text(payload.contact.name, max_chars=255),
                "channel_type": self.context_helper.sanitize_text(payload.channel_type, max_chars=32),
                "external_channel_id": self.context_helper.sanitize_text(payload.external_channel_id, max_chars=128),
            },
            "conversation": {
                "external_id": self.context_helper.sanitize_text(payload.conversation.external_id, max_chars=128),
                **self.context_helper.build_conversation_payload(
                    payload.conversation.summary,
                    payload.conversation.last_messages,
                    payload.conversation.context_messages,
                ),
                **(
                    {
                        "appointment_context": self._selected_appointment_context_payload(
                            payload,
                            routing,
                            backend_context,
                            payload.message.text,
                            appointment_context,
                            current_timezone,
                            current_timezone_source,
                        )
                    }
                    if appointment_context is not None
                    else {}
                ),
            },
            "current_message": self.context_helper.sanitize_text(payload.message.text or "", max_chars=2000),
        }

        return system_prompt, json.dumps(user_payload, ensure_ascii=False, indent=2)

    def _current_business_time(self, timezone_name: str) -> datetime:
        return datetime.now(ZoneInfo(timezone_name))

    def _resolve_business_timezone(self, backend_context: CommercialContext | None, contact_context: dict[str, Any] | None = None) -> str:
        return self._resolve_business_timezone_details(backend_context, contact_context)[0]

    def _resolve_business_timezone_details(
        self,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
    ) -> tuple[str, str | None]:
        for source_label, source in self._business_timezone_sources(backend_context, contact_context):
            candidate, candidate_source = self._first_timezone_candidate(source)
            if candidate is None:
                continue

            normalized = candidate.strip()
            if not normalized:
                continue

            if not self._is_valid_timezone(normalized):
                continue

            return normalized, candidate_source or source_label

        configured_fallback = self._configured_default_business_timezone()
        if configured_fallback is not None:
            return configured_fallback, "settings.default_business_timezone"

        return self.settings.safe_default_business_timezone(), "safety_fallback"

    def _configured_default_business_timezone(self) -> str | None:
        candidate = getattr(self.settings, "default_business_timezone", None)
        if not isinstance(candidate, str):
            return None

        normalized = candidate.strip()
        if not normalized:
            return None

        if not self._is_valid_timezone(normalized):
            return None

        return normalized

    def _is_valid_timezone(self, timezone_name: str) -> bool:
        try:
            ZoneInfo(timezone_name)
        except Exception:
            return False

        return True

    def _business_timezone_sources(
        self,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
    ) -> list[tuple[str, Any]]:
        sources: list[tuple[str, Any]] = []

        if contact_context is not None:
            business_context = None
            if isinstance(contact_context, dict):
                business_context = contact_context.get("business_context") or contact_context.get("businessContext")
            if isinstance(business_context, dict):
                sources.append(("contact_context.business_context", business_context))
            sources.append(("contact_context", contact_context))

        if backend_context is None:
            return sources

        sources.append(("backend_context", backend_context))
        for attribute in ("tenant", "entry_point", "sales_runtime"):
            source = getattr(backend_context, attribute, None)
            if source is not None:
                sources.append((attribute, source))

        return sources

    def _first_timezone_candidate(self, source: Any) -> tuple[str | None, str | None]:
        field_names = (
            "branch_timezone",
            "timezone",
            "time_zone",
            "business_timezone",
            "businessTimezone",
            "local_timezone",
            "localTimezone",
            "tenant_timezone",
            "tenantTimezone",
            "crm_timezone",
            "crmTimezone",
            "effective_timezone",
            "effectiveTimezone",
        )
        source_name_fields = ("timezone_source", "timezoneSource", "business_timezone_source", "businessTimezoneSource")

        if isinstance(source, dict):
            for field_name in field_names:
                value = source.get(field_name)
                if isinstance(value, str) and value.strip():
                    return value, self._first_string_value(source, source_name_fields)
            return None, None

        for field_name in field_names:
            value = getattr(source, field_name, None)
            if isinstance(value, str) and value.strip():
                return value, self._first_string_value(source, source_name_fields)

        return None, None

    def _first_string_value(self, source: Any, field_names: tuple[str, ...]) -> str | None:
        if isinstance(source, dict):
            for field_name in field_names:
                value = source.get(field_name)
                if isinstance(value, str) and value.strip():
                    return value.strip()
            return None

        for field_name in field_names:
            value = getattr(source, field_name, None)
            if isinstance(value, str) and value.strip():
                return value.strip()

        return None

    def _handoff_strategy(self, backend_context: CommercialContext | None) -> str:
        if backend_context is None:
            return "disabled"

        tenant = backend_context.tenant
        handoff = getattr(tenant, "handoff", None)
        if not isinstance(handoff, dict):
            return "disabled"

        strategy = handoff.get("strategy")
        if not isinstance(strategy, str):
            return "disabled"

        normalized = strategy.strip().lower()
        if normalized in {"disabled", "manual_wa_link", "n8n_webhook", "manual_wa_link_and_n8n"}:
            return normalized

        return "disabled"

    def _routing_payload(self, routing: RoutingContext | None) -> dict[str, Any] | None:
        if routing is None:
            return None

        return {
            "tenant_id": routing.tenant_id,
            "tenant_slug": routing.tenant_slug,
            "external_channel_id": routing.external_channel_id,
            "product_id": routing.product_id,
            "product_name": routing.product_name,
            "playbook_id": routing.playbook_id,
            "entry_point_id": routing.entry_point_id,
            "entry_point_code": routing.entry_point_code,
            "entry_point_utm_id": routing.entry_point_utm_id,
            "entrypoint_ref": routing.entrypoint_ref,
            "crm_branch_ref": routing.crm_branch_ref,
            "utm_source": routing.utm_source,
            "utm_medium": routing.utm_medium,
            "utm_campaign": routing.utm_campaign,
            "utm_term": routing.utm_term,
            "utm_content": routing.utm_content,
            "gclid": routing.gclid,
            "fbclid": routing.fbclid,
            "status": routing.status,
            "conversation_id": routing.conversation_id,
            "source": routing.source,
        }

    def _tenant_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None:
            return None

        tenant = backend_context.tenant
        return {
            "id": tenant.id,
            "name": self.context_helper.sanitize_text(tenant.name, max_chars=255),
            "slug": self.context_helper.sanitize_text(tenant.slug, max_chars=180),
            "business_context": self.context_helper.sanitize_text(tenant.business_context, max_chars=2000),
            "tone": self.context_helper.sanitize_text(tenant.tone, max_chars=120),
            "sales_policy": self.context_helper.sanitize_jsonish(tenant.sales_policy, max_depth=2, max_items=10, max_string_chars=500),
            "whatsapp_phone_number_id": self.context_helper.sanitize_text(tenant.whatsapp_phone_number_id, max_chars=255),
            "whatsapp_public_phone": self.context_helper.sanitize_text(tenant.whatsapp_public_phone, max_chars=50),
        }

    def _product_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.selected_product is None:
            return None

        product = backend_context.selected_product
        return {
            "id": product.id,
            "name": self.context_helper.sanitize_text(product.name, max_chars=255),
            "slug": self.context_helper.sanitize_text(product.slug, max_chars=180),
            "description": self.context_helper.sanitize_text(product.description, max_chars=1500),
            "value_proposition": self.context_helper.sanitize_text(product.value_proposition, max_chars=1500),
            "base_price_cents": product.base_price_cents,
            "currency": self.context_helper.sanitize_text(product.currency, max_chars=10),
            "external_source": self.context_helper.sanitize_text(product.external_source, max_chars=100),
            "external_reference": self.context_helper.sanitize_text(product.external_reference, max_chars=255),
            "sales_policy": self.context_helper.sanitize_jsonish(product.sales_policy, max_depth=2, max_items=10, max_string_chars=500),
        }

    def _products_payload(self, backend_context: CommercialContext | None) -> list[dict[str, Any]]:
        if backend_context is None or backend_context.products == []:
            return []

        return [
            self._product_payload_from_product(product)
            for product in backend_context.products
        ]

    def _product_payload_from_product(self, product: Any) -> dict[str, Any] | None:
        if product is None:
            return None

        return {
            "id": product.id,
            "name": self.context_helper.sanitize_text(product.name, max_chars=255),
            "slug": self.context_helper.sanitize_text(product.slug, max_chars=180),
            "description": self.context_helper.sanitize_text(product.description, max_chars=1500),
            "value_proposition": self.context_helper.sanitize_text(product.value_proposition, max_chars=1500),
            "base_price_cents": product.base_price_cents,
            "currency": self.context_helper.sanitize_text(product.currency, max_chars=10),
            "external_source": self.context_helper.sanitize_text(product.external_source, max_chars=100),
            "external_reference": self.context_helper.sanitize_text(product.external_reference, max_chars=255),
            "sales_policy": self.context_helper.sanitize_jsonish(product.sales_policy, max_depth=2, max_items=10, max_string_chars=500),
        }

    def _product_selection_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None:
            return None

        selection = backend_context.product_selection
        if selection == {} and backend_context.selected_product is None and backend_context.products == []:
            return None

        return {
            "selection_source": self.context_helper.sanitize_text(selection.get("selection_source"), max_chars=64),
            "search_query_used": self.context_helper.sanitize_text(selection.get("search_query_used"), max_chars=255),
            "candidate_count": selection.get("candidate_count") if isinstance(selection.get("candidate_count"), int) else None,
            "needs_service_clarification": bool(selection.get("needs_service_clarification", False)),
            "fallback_to_mcp_allowed": bool(selection.get("fallback_to_mcp_allowed", False)),
            "reason": self.context_helper.sanitize_text(selection.get("reason"), max_chars=255),
        }

    def _playbook_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.selected_playbook is None:
            return None

        playbook = backend_context.selected_playbook
        return {
            "id": playbook.id,
            "name": self.context_helper.sanitize_text(playbook.name, max_chars=255),
            "product_id": playbook.product_id,
            "config": self.context_helper.sanitize_jsonish(playbook.config, max_depth=2, max_items=10, max_string_chars=500),
        }

    def _entry_point_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.entry_point is None:
            return None

        entry_point = backend_context.entry_point
        return {
            "id": entry_point.id,
            "code": self.context_helper.sanitize_text(entry_point.code, max_chars=180),
            "name": self.context_helper.sanitize_text(entry_point.name, max_chars=255),
            "description": self.context_helper.sanitize_text(entry_point.description, max_chars=500),
            "initial_message": self.context_helper.sanitize_text(entry_point.initial_message, max_chars=500),
            "crm_branch_ref": self.context_helper.sanitize_text(entry_point.crm_branch_ref, max_chars=255),
            "is_active": entry_point.is_active,
        }

    def _sales_runtime_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None:
            return None

        return backend_context.sales_runtime.model_dump(exclude_none=True)

    def _external_context_payload(self, contact_context: dict[str, Any] | None) -> dict[str, Any] | None:
        if not isinstance(contact_context, dict):
            return None

        payload: dict[str, Any] = {
            "available": bool(contact_context.get("available", False)),
            "configured": bool(contact_context.get("configured", False)),
            "provider": self._clean_string(contact_context.get("provider")),
            "ok": bool(contact_context.get("ok", False)),
            "found": bool(contact_context.get("found", False)),
            "error_code": self._clean_string(contact_context.get("error_code")),
        }

        data = contact_context.get("data")
        if not isinstance(data, dict):
            return payload

        flags = data.get("flags")
        contact = data.get("contact")
        recent_activity = data.get("recent_activity")
        open_opportunities = data.get("open_opportunities")
        appointments = data.get("appointments")
        sales = data.get("sales")
        business_context = self._business_context_payload(data.get("business_context") or data.get("businessContext"))

        timezone = self._clean_string(data.get("timezone"))
        if timezone is None and business_context is not None:
            timezone = self._clean_string(business_context.get("timezone"))

        timezone_source = self._clean_string(data.get("timezone_source") or data.get("timezoneSource"))
        if timezone_source is None and business_context is not None:
            timezone_source = self._clean_string(business_context.get("timezone_source"))

        needs_branch_selection = bool(
            data.get(
                "needs_branch_selection",
                data.get(
                    "needsBranchSelection",
                    business_context.get("needs_branch_selection", False) if business_context is not None else False,
                ),
            )
        )

        branch = self._clean_branch(data.get("branch"))
        if branch is None and business_context is not None:
            branch = self._clean_branch(business_context.get("branch"))

        selected_branch = self._clean_branch(
            data.get("selected_branch") if "selected_branch" in data else data.get("selectedBranch")
        )
        if selected_branch is None and business_context is not None:
            selected_branch = self._clean_branch(business_context.get("selected_branch"))

        branches = self.context_helper.sanitize_jsonish(data.get("branches"), max_depth=5, max_items=5)
        if (not isinstance(branches, list) or branches == []) and business_context is not None:
            branches = self.context_helper.sanitize_jsonish(business_context.get("branches"), max_depth=5, max_items=5)

        payload.update(
            {
                "source": self._clean_string(data.get("source")),
                "summary": self._clean_string(data.get("summary")),
                "timezone": timezone,
                "timezone_source": timezone_source,
                "needs_branch_selection": needs_branch_selection,
                "branch": branch,
                "selected_branch": selected_branch,
                "branches": branches,
                "contact": self._clean_contact(contact),
                "recent_activity": self._clean_list_of_dicts(recent_activity, max_items=5),
                "open_opportunities": self._clean_list_of_dicts(open_opportunities, max_items=5),
                "appointments": self._clean_appointments(appointments),
                "sales": self._clean_sales(sales),
                "flags": self._clean_flags(flags),
                **({"business_context": business_context} if business_context is not None else {}),
            }
        )

        return payload

    def _operational_context_payload(
        self,
        payload: AgentRequest,
        effective_timezone: str,
        effective_timezone_source: str | None,
        contact_context: dict[str, Any] | None,
    ) -> dict[str, Any]:
        contact_context_source = None
        if isinstance(contact_context, dict):
            contact_context_source = self._clean_string(contact_context.get("source")) or self._clean_string(contact_context.get("cache_source"))

        channel = self.context_helper.sanitize_text(payload.conversation.channel or payload.channel_type, max_chars=32)
        return {
            "tenant_id": self.context_helper.sanitize_text(payload.tenant_id, max_chars=64),
            "channel": channel,
            "contact": {
                "phone": self.context_helper.sanitize_text(payload.contact.phone, max_chars=64),
                "email": self.context_helper.sanitize_text(payload.contact.email, max_chars=255),
                "name": self.context_helper.sanitize_text(payload.contact.name, max_chars=255),
            },
            "effective_timezone": effective_timezone,
            **({"effective_timezone_source": effective_timezone_source} if effective_timezone_source is not None else {}),
            "contact_context_available": isinstance(contact_context, dict),
            **({"contact_context_source": contact_context_source} if contact_context_source is not None else {}),
            "appointment_tool_timezone": effective_timezone,
        }

    def _appointment_context_payload(
        self,
        context_messages: list[dict[str, Any]] | None,
        timezone: str | None = None,
        timezone_source: str | None = None,
    ) -> dict[str, Any] | None:
        if not isinstance(context_messages, list):
            if timezone is None and timezone_source is None:
                return None

            appointment_context: dict[str, Any] = {}
            if timezone is not None:
                appointment_context["timezone"] = timezone
            if timezone_source is not None:
                appointment_context["timezone_source"] = timezone_source

            return appointment_context or None

        for message in reversed(context_messages):
            if not isinstance(message, dict):
                continue

            raw_payload = message.get("raw_payload")
            if isinstance(raw_payload, dict):
                raw_payload_data = raw_payload.get("data_to_save")
                if isinstance(raw_payload_data, dict):
                    saved_context = self._appointment_context_from_data_to_save(
                        message,
                        raw_payload_data,
                        timezone,
                        timezone_source,
                    )
                    if saved_context is not None:
                        return saved_context

            metadata = message.get("metadata")
            if not isinstance(metadata, dict):
                continue

            data_to_save = metadata.get("data_to_save")
            if isinstance(data_to_save, dict):
                saved_context = self._appointment_context_from_data_to_save(
                    message,
                    data_to_save,
                    timezone,
                    timezone_source,
                )
                if saved_context is not None:
                    return saved_context

            traces = metadata.get("mcp_tool_traces")
            if not isinstance(traces, list):
                continue

            for trace in reversed(traces):
                if not isinstance(trace, dict):
                    continue

                tool_name = self._clean_string(trace.get("tool_name") or trace.get("toolName") or trace.get("name"))
                if tool_name != "appointment_availability":
                    continue

                output = trace.get("output")
                if not isinstance(output, dict):
                    raw_output = trace.get("raw")
                    output = raw_output if isinstance(raw_output, dict) else {}

                slots = output.get("slots")
                if not isinstance(slots, list) or not slots:
                    continue

                offered_slots = self._normalize_offered_slots(slots[:6])
                if not isinstance(offered_slots, list) or offered_slots == []:
                    continue

                raw_summary = output.get("raw_summary")
                appointment_context: dict[str, Any] = {
                    "source_message_id": self._clean_string(message.get("id")),
                    "tool_name": "appointment_availability",
                    "offered_slots": offered_slots,
                }

                if timezone is not None:
                    appointment_context["timezone"] = timezone
                if timezone_source is not None:
                    appointment_context["timezone_source"] = timezone_source

                if isinstance(raw_summary, dict):
                    sanitized_raw_summary = self.context_helper.sanitize_jsonish(raw_summary, max_depth=5, max_items=8)
                    if sanitized_raw_summary not in (None, {}):
                        appointment_context["raw_summary"] = sanitized_raw_summary

                service_payload = self._appointment_context_service_payload(output)
                if service_payload is not None:
                    appointment_context.update(service_payload)

                return appointment_context

        return None

    def _appointment_context_from_data_to_save(
        self,
        message: dict[str, Any],
        data_to_save: dict[str, Any],
        timezone: str | None = None,
        timezone_source: str | None = None,
    ) -> dict[str, Any] | None:
        offered_slots = data_to_save.get("new_llm_orchestration_offered_slots")
        if not isinstance(offered_slots, list) or offered_slots == []:
            availability_trace = data_to_save.get("new_llm_orchestration_appointment_availability_trace")
            if isinstance(availability_trace, dict):
                response_payload = availability_trace.get("response_payload")
                if isinstance(response_payload, dict):
                    offered_slots = response_payload.get("slots") if isinstance(response_payload.get("slots"), list) else []
                    if not isinstance(offered_slots, list) or offered_slots == []:
                        offered_slots = []
        if not isinstance(offered_slots, list) or offered_slots == []:
            return None

        normalized_offered_slots = self._normalize_offered_slots(offered_slots[:6])
        if not isinstance(normalized_offered_slots, list) or normalized_offered_slots == []:
            return None

        appointment_context: dict[str, Any] = {
            "source_message_id": self._clean_string(message.get("id")),
            "tool_name": "appointment_availability",
            "offered_slots": normalized_offered_slots,
        }

        if timezone is not None:
            appointment_context["timezone"] = timezone
        if timezone_source is not None:
            appointment_context["timezone_source"] = timezone_source

        trace_payload: dict[str, Any] | None = None
        availability_trace = data_to_save.get("new_llm_orchestration_appointment_availability_trace")
        if isinstance(availability_trace, dict):
            trace_payload = availability_trace.get("response_payload") if isinstance(availability_trace.get("response_payload"), dict) else None
            raw_summary = availability_trace.get("response_payload")
            if isinstance(raw_summary, dict):
                sanitized_raw_summary = self.context_helper.sanitize_jsonish(raw_summary, max_depth=5, max_items=8)
                if sanitized_raw_summary not in (None, {}):
                    appointment_context["raw_summary"] = sanitized_raw_summary

        if trace_payload is None:
            trace_payload = data_to_save

        service_payload = self._appointment_context_service_payload(trace_payload)
        if service_payload is None and normalized_offered_slots != []:
            first_slot = normalized_offered_slots[0]
            if isinstance(first_slot, dict):
                service_payload = self._appointment_context_service_payload(first_slot)
                if service_payload is None:
                    service_payload = {}
                    for key in ("service_id", "service_name", "service_ref", "duration_minutes"):
                        value = first_slot.get(key)
                        if value is not None:
                            service_payload[key] = value
                    if service_payload == {}:
                        service_payload = None
        if service_payload is not None:
            appointment_context.update(service_payload)

        selected_slot = data_to_save.get("new_llm_orchestration_selected_slot")
        if isinstance(selected_slot, dict):
            appointment_context["selected_slot"] = self.context_helper.sanitize_jsonish(selected_slot, max_depth=9, max_items=6)

        return appointment_context

    def _selected_appointment_context_payload(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        current_message: str | None,
        appointment_context: dict[str, Any] | None,
        timezone: str | None = None,
        timezone_source: str | None = None,
    ) -> dict[str, Any] | None:
        if not isinstance(appointment_context, dict):
            return appointment_context

        offered_slots = appointment_context.get("offered_slots")
        if not isinstance(offered_slots, list) or offered_slots == []:
            payload_copy = dict(appointment_context)
            if timezone is not None:
                payload_copy["timezone"] = timezone
            if timezone_source is not None:
                payload_copy["timezone_source"] = timezone_source
            return payload_copy

        normalized_slots = self._normalize_offered_slots(offered_slots)
        selected_slot = self._selected_slot_from_message(current_message, normalized_slots)
        payload_copy = dict(appointment_context)
        payload_copy["offered_slots"] = normalized_slots
        if selected_slot is not None:
            enriched_selected_slot = self._enrich_selected_slot(
                selected_slot,
                appointment_context,
                payload,
                routing,
                backend_context,
                timezone,
                timezone_source,
            )
            payload_copy["selected_slot"] = enriched_selected_slot
            required_next_action = self._selected_slot_required_next_action(
                enriched_selected_slot,
                appointment_context,
                payload,
                routing,
                backend_context,
                timezone,
                timezone_source,
            )
            if required_next_action is not None:
                payload_copy["required_next_action"] = required_next_action
        elif timezone is not None:
            payload_copy["timezone"] = timezone
            if timezone_source is not None:
                payload_copy["timezone_source"] = timezone_source

        return payload_copy

    def _appointment_context_service_payload(self, output: dict[str, Any]) -> dict[str, Any] | None:
        service = output.get("service")
        service_dict = service if isinstance(service, dict) else {}
        service_id = self._clean_string(
            output.get("service_id")
            or output.get("serviceId")
            or service_dict.get("id")
            or service_dict.get("service_id")
            or service_dict.get("serviceId")
        )
        service_name = self._clean_string(
            output.get("service_name")
            or output.get("serviceName")
            or service_dict.get("name")
            or service_dict.get("display_name")
        )
        service_ref = self._clean_string(
            output.get("service_ref")
            or output.get("serviceRef")
            or service_dict.get("ref")
            or service_dict.get("integration_key")
            or service_dict.get("integrationKey")
            or service_dict.get("slug")
        )
        duration_minutes = output.get("duration_minutes")
        if duration_minutes is None:
            duration_minutes = output.get("durationMinutes")
        if duration_minutes is None and isinstance(service_dict.get("duration_minutes"), (int, float)):
            duration_minutes = service_dict.get("duration_minutes")
        if duration_minutes is None and isinstance(service_dict.get("durationMinutes"), (int, float)):
            duration_minutes = service_dict.get("durationMinutes")

        service_payload: dict[str, Any] = {}
        if service_id is not None:
            service_payload["service_id"] = service_id
        if service_name is not None:
            service_payload["service_name"] = service_name
        if service_ref is not None:
            service_payload["service_ref"] = service_ref
        if isinstance(duration_minutes, (int, float)):
            service_payload["duration_minutes"] = int(duration_minutes)

        return service_payload or None

    def _enrich_selected_slot(
        self,
        selected_slot: dict[str, Any],
        appointment_context: dict[str, Any],
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        timezone: str | None,
        timezone_source: str | None,
    ) -> dict[str, Any]:
        enriched = dict(selected_slot)

        def set_if_missing(key: str, value: Any) -> None:
            if value is None:
                return
            if key not in enriched or enriched[key] in (None, ""):
                enriched[key] = value

        for key in ("service_id", "service_ref", "service_name", "duration_minutes"):
            set_if_missing(key, appointment_context.get(key))

        if timezone is not None:
            set_if_missing("timezone", timezone)
        if timezone_source is not None:
            set_if_missing("timezone_source", timezone_source)

        tenant_id = self._clean_string(payload.tenant_id)
        if tenant_id is None and backend_context is not None:
            tenant_id = self._clean_string(getattr(backend_context.tenant, "id", None))
        set_if_missing("tenant_id", tenant_id)

        conversation_id = self._clean_string(payload.conversation.external_id)
        set_if_missing("conversation_id", conversation_id)

        entrypoint_ref = self._clean_string(payload.entrypoint_ref)
        if entrypoint_ref is None and routing is not None:
            entrypoint_ref = self._clean_string(routing.entrypoint_ref)
        set_if_missing("entrypoint_ref", entrypoint_ref)

        contact_payload: dict[str, Any] = {}
        if payload.contact.name is not None:
            contact_payload["name"] = self._clean_string(payload.contact.name)
        if payload.contact.phone is not None:
            contact_payload["phone"] = self._clean_string(payload.contact.phone)
        if payload.contact.email is not None:
            contact_payload["email"] = self._clean_string(payload.contact.email)
        contact_payload = {key: value for key, value in contact_payload.items() if value is not None}
        if contact_payload:
            set_if_missing("contact", contact_payload)

        service_name = self._clean_string(
            enriched.get("service_name")
            or appointment_context.get("service_name")
            or appointment_context.get("serviceName")
        )
        if service_name is not None:
            set_if_missing("service_name", service_name)

        owner_payload = selected_slot.get("owner") if isinstance(selected_slot.get("owner"), dict) else {}
        owner_id = self._clean_string(
            selected_slot.get("owner_id")
            or selected_slot.get("ownerId")
            or owner_payload.get("id")
            or owner_payload.get("owner_id")
            or owner_payload.get("ownerId")
        )
        if owner_id is not None:
            set_if_missing("owner_id", owner_id)

        owner_name = self._clean_string(
            selected_slot.get("owner_name")
            or selected_slot.get("ownerName")
            or owner_payload.get("name")
            or owner_payload.get("display_name")
        )
        if owner_name is not None:
            set_if_missing("owner_name", owner_name)

        owner_email = self._clean_string(
            selected_slot.get("owner_email")
            or selected_slot.get("ownerEmail")
            or owner_payload.get("email")
            or owner_payload.get("owner_email")
            or owner_payload.get("ownerEmail")
        )
        if owner_email is not None:
            set_if_missing("owner_email", owner_email)

        owner_ref = self._clean_string(
            selected_slot.get("owner_ref")
            or selected_slot.get("ownerRef")
            or owner_payload.get("ref")
            or owner_payload.get("owner_ref")
            or owner_payload.get("ownerRef")
        )
        if owner_ref is not None:
            set_if_missing("owner_ref", owner_ref)

        owner_preferred = selected_slot.get("owner_preferred")
        if owner_preferred is None:
            owner_preferred = selected_slot.get("ownerPreferred")
        if owner_preferred is None and isinstance(owner_payload, dict):
            owner_preferred = owner_payload.get("preferred")
        if owner_preferred is not None:
            set_if_missing("owner_preferred", owner_preferred)
            set_if_missing("ownerPreferred", owner_preferred)

        normalized_owner: dict[str, Any] = dict(owner_payload) if owner_payload else {}
        if owner_id is not None:
            normalized_owner["id"] = owner_id
        if owner_name is not None:
            normalized_owner["name"] = owner_name
        if owner_email is not None:
            normalized_owner["email"] = owner_email
        if owner_ref is not None:
            normalized_owner["ref"] = owner_ref
        if owner_preferred is not None:
            normalized_owner["preferred"] = owner_preferred
        if normalized_owner != {}:
            set_if_missing("owner", normalized_owner)

        return enriched

    def _selected_slot_required_next_action(
        self,
        selected_slot: dict[str, Any],
        appointment_context: dict[str, Any],
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        timezone: str | None,
        timezone_source: str | None,
    ) -> dict[str, Any] | None:
        start = self._clean_string(selected_slot.get("start"))
        end = self._clean_string(selected_slot.get("end"))
        owner_id = self._clean_string(selected_slot.get("owner_id") or selected_slot.get("ownerId"))
        service_id = self._clean_string(
            selected_slot.get("service_id")
            or selected_slot.get("serviceId")
            or appointment_context.get("service_id")
            or appointment_context.get("serviceId")
        )
        service_ref = self._clean_string(
            selected_slot.get("service_ref")
            or selected_slot.get("serviceRef")
            or appointment_context.get("service_ref")
            or appointment_context.get("serviceRef")
        )
        contact = selected_slot.get("contact") if isinstance(selected_slot.get("contact"), dict) else {}
        contact_phone = self._clean_string(contact.get("phone")) if isinstance(contact, dict) else None
        contact_email = self._clean_string(contact.get("email")) if isinstance(contact, dict) else None
        if contact_phone is None:
            contact_phone = self._clean_string(payload.contact.phone)
        if contact_email is None:
            contact_email = self._clean_string(payload.contact.email)

        if start is None or end is None or owner_id is None:
            return None

        if service_id is None and service_ref is None:
            return None

        if contact_phone is None and contact_email is None:
            return None

        selected_timezone = self._clean_string(selected_slot.get("timezone")) or timezone
        if selected_timezone is None:
            return None

        selected_slot_payload = dict(selected_slot)
        selected_slot_payload.setdefault("timezone", selected_timezone)
        if timezone_source is not None:
            selected_slot_payload.setdefault("timezone_source", timezone_source)
        if service_id is not None:
            selected_slot_payload.setdefault("service_id", service_id)
        if service_ref is not None:
            selected_slot_payload.setdefault("service_ref", service_ref)

        if backend_context is not None:
            selected_slot_payload.setdefault("tenant_id", self._clean_string(backend_context.tenant.id))

        if self._clean_string(payload.conversation.external_id) is not None:
            selected_slot_payload.setdefault("conversation_id", self._clean_string(payload.conversation.external_id))

        if self._clean_string(payload.entrypoint_ref) is not None:
            selected_slot_payload.setdefault("entrypoint_ref", self._clean_string(payload.entrypoint_ref))
        elif routing is not None and self._clean_string(routing.entrypoint_ref) is not None:
            selected_slot_payload.setdefault("entrypoint_ref", self._clean_string(routing.entrypoint_ref))

        selected_slot_payload["contact"] = {
            key: value
            for key, value in {
                "name": self._clean_string(payload.contact.name),
                "phone": contact_phone,
                "email": contact_email,
            }.items()
            if value is not None
        }

        service_name = self._clean_string(
            selected_slot.get("service_name")
            or selected_slot.get("serviceName")
            or appointment_context.get("service_name")
            or appointment_context.get("serviceName")
        )
        if service_name is not None:
            selected_slot_payload.setdefault("service_name", service_name)

        owner_name = self._clean_string(selected_slot.get("owner_name") or selected_slot.get("ownerName"))
        if owner_name is not None:
            selected_slot_payload.setdefault("owner_name", owner_name)

        return {
            "tool": "appointment_confirm",
            "must_call_tool": True,
            "reason": "The user selected an exact offered slot.",
            "do_not_reply_before_tool_call": True,
            "selected_slot": selected_slot_payload,
        }

    def _normalize_offered_slots(self, offered_slots: list[Any]) -> list[dict[str, Any]]:
        normalized_slots: list[dict[str, Any]] = []
        for slot in offered_slots:
            if not isinstance(slot, dict):
                continue

            normalized_slot = dict(slot)
            owner = slot.get("owner")
            owner_dict = owner if isinstance(owner, dict) else {}
            owner_id = self._clean_string(
                slot.get("owner_id")
                or slot.get("ownerId")
                or owner_dict.get("id")
                or owner_dict.get("owner_id")
                or owner_dict.get("ownerId")
            )
            owner_name = self._clean_string(
                slot.get("owner_name")
                or slot.get("ownerName")
                or owner_dict.get("name")
                or owner_dict.get("display_name")
            )
            owner_email = self._clean_string(
                slot.get("owner_email")
                or slot.get("ownerEmail")
                or owner_dict.get("email")
                or owner_dict.get("owner_email")
                or owner_dict.get("ownerEmail")
            )
            owner_ref = self._clean_string(
                slot.get("owner_ref")
                or slot.get("ownerRef")
                or owner_dict.get("ref")
                or owner_dict.get("owner_ref")
                or owner_dict.get("ownerRef")
            )
            owner_preferred = owner_dict.get("preferred")
            if owner_preferred is None:
                owner_preferred = slot.get("owner_preferred")
            if owner_preferred is None:
                owner_preferred = slot.get("ownerPreferred")

            if owner_id is not None:
                normalized_slot["owner_id"] = owner_id
                normalized_slot["ownerId"] = owner_id
            if owner_name is not None:
                normalized_slot["owner_name"] = owner_name
                normalized_slot["ownerName"] = owner_name
            if owner_email is not None:
                normalized_slot["owner_email"] = owner_email
                normalized_slot["ownerEmail"] = owner_email
            if owner_ref is not None:
                normalized_slot["owner_ref"] = owner_ref
                normalized_slot["ownerRef"] = owner_ref
            if owner_preferred is not None:
                normalized_slot["owner_preferred"] = owner_preferred
                normalized_slot["ownerPreferred"] = owner_preferred

            normalized_owner: dict[str, Any] = dict(owner_dict) if owner_dict else {}
            if owner_id is not None:
                normalized_owner["id"] = owner_id
            if owner_name is not None:
                normalized_owner["name"] = owner_name
            if owner_email is not None:
                normalized_owner["email"] = owner_email
            if owner_ref is not None:
                normalized_owner["ref"] = owner_ref
            if owner_preferred is not None:
                normalized_owner["preferred"] = owner_preferred
            if normalized_owner != {}:
                normalized_slot["owner"] = normalized_owner

            normalized_slots.append(normalized_slot)

        return normalized_slots

    def _selected_slot_from_message(self, message: str | None, offered_slots: list[dict[str, Any]]) -> dict[str, Any] | None:
        if not isinstance(message, str):
            return None

        normalized_message = unicodedata.normalize("NFKD", message).encode("ascii", "ignore").decode("ascii").lower().strip()
        if normalized_message == "":
            return None

        first_reference = bool(re.search(r"\b(primero|primera|first)\b", normalized_message))
        last_reference = bool(re.search(r"\b(ultimo|ultima|last)\b", normalized_message))
        if first_reference or last_reference:
            candidates = list(offered_slots)
            if not candidates:
                return None

            if first_reference:
                return candidates[0]

            return candidates[-1]

        time_match = re.search(r"\b([01]?\d|2[0-3]):[0-5]\d\b", normalized_message)
        owner_match = None
        for slot in offered_slots:
            owner_name = self._clean_string(slot.get("owner_name"))
            if owner_name is None:
                continue

            normalized_owner_name = unicodedata.normalize("NFKD", owner_name).encode("ascii", "ignore").decode("ascii").lower().strip()
            if normalized_owner_name != "" and normalized_owner_name in normalized_message:
                owner_match = normalized_owner_name
                break

        explicit_owner_reference = " con " in normalized_message or normalized_message.startswith("con ")
        if explicit_owner_reference and owner_match is None:
            return None

        candidates: list[dict[str, Any]] = []
        for slot in offered_slots:
            start = self._clean_string(slot.get("start"))
            if start is None:
                continue

            slot_time = self._slot_time_from_iso(start)
            if time_match is not None and slot_time != time_match.group(0):
                continue

            if owner_match is not None:
                owner_name = self._clean_string(slot.get("owner_name"))
                if owner_name is None:
                    continue
                normalized_owner_name = unicodedata.normalize("NFKD", owner_name).encode("ascii", "ignore").decode("ascii").lower().strip()
                if normalized_owner_name != owner_match:
                    continue

            candidates.append(slot)

        if len(candidates) != 1:
            return None

        return candidates[0]

    def _slot_time_from_iso(self, value: str) -> str | None:
        try:
            parsed = datetime.fromisoformat(value)
        except Exception:
            return None

        return f"{parsed.hour:02d}:{parsed.minute:02d}"

    def _recent_contact_context_payload(self, context_messages: list[dict[str, Any]] | None) -> dict[str, Any] | None:
        if not isinstance(context_messages, list):
            return None

        for message in reversed(context_messages):
            if not isinstance(message, dict):
                continue

            metadata = message.get("metadata")
            if not isinstance(metadata, dict):
                continue

            data_to_save = metadata.get("data_to_save")
            if not isinstance(data_to_save, dict):
                data_to_save = metadata

            if not isinstance(data_to_save, dict):
                continue

            timezone = self._clean_string(data_to_save.get("external_context_timezone"))
            timezone_source = self._clean_string(data_to_save.get("external_context_timezone_source"))
            branch = data_to_save.get("external_context_branch")
            selected_branch = data_to_save.get("external_context_selected_branch")
            business_context: dict[str, Any] = {
                "timezone": self._clean_string(data_to_save.get("external_business_context_timezone")),
                "timezone_source": self._clean_string(data_to_save.get("external_business_context_timezone_source")),
                "needs_branch_selection": bool(data_to_save.get("external_business_context_needs_branch_selection", False)),
                "branch": self._clean_branch(data_to_save.get("external_business_context_branch")),
                "selected_branch": self._clean_branch(data_to_save.get("external_business_context_selected_branch")),
            }

            if timezone is None and timezone_source is None and branch is None and selected_branch is None and all(
                value in (None, False, {}, []) for value in business_context.values()
            ):
                continue

            payload: dict[str, Any] = {
                "available": bool(data_to_save.get("external_context_available", False)),
                "configured": bool(data_to_save.get("external_context_configured", False)),
                "provider": self._clean_string(data_to_save.get("external_context_provider")),
                "ok": bool(data_to_save.get("external_context_available", False)),
                "found": bool(data_to_save.get("external_context_available", False)),
                "error_code": self._clean_string(data_to_save.get("external_context_error_code")),
                "data": {
                    "source": self._clean_string(data_to_save.get("external_context_source")),
                    "summary": self._clean_string(data_to_save.get("external_context_summary")),
                    "timezone": timezone,
                    "timezone_source": timezone_source,
                    "needs_branch_selection": bool(data_to_save.get("external_context_needs_branch_selection", False)),
                    "branch": self._clean_branch(branch),
                    "selected_branch": self._clean_branch(selected_branch),
                    "contact": {
                        "name": self._clean_string(data_to_save.get("external_contact_name")),
                        "phone": self._clean_string(data_to_save.get("external_contact_phone")),
                        "status": self._clean_string(data_to_save.get("external_contact_status")),
                        "stage": self._clean_string(data_to_save.get("external_contact_stage")),
                        "owner": self._clean_string(data_to_save.get("external_contact_owner")),
                    },
                    "flags": {
                        "needs_human": bool(data_to_save.get("external_flag_needs_human", False)),
                        "do_not_contact": bool(data_to_save.get("external_flag_do_not_contact", False)),
                        "existing_customer": bool(data_to_save.get("external_flag_existing_customer", False)),
                    },
                },
            }

            branches = data_to_save.get("external_context_branches")
            if branches is not None:
                payload["data"]["branches"] = self.context_helper.sanitize_jsonish(branches, max_depth=5, max_items=5)

            business_branches = data_to_save.get("external_business_context_branches")
            if business_branches is not None:
                business_context["branches"] = self.context_helper.sanitize_jsonish(business_branches, max_depth=5, max_items=5)

            if any(value not in (None, False, {}, []) for value in business_context.values()):
                payload["data"]["business_context"] = business_context

            if payload["data"].get("timezone") is None and business_context.get("timezone") is not None:
                payload["data"]["timezone"] = business_context["timezone"]
            if payload["data"].get("timezone_source") is None and business_context.get("timezone_source") is not None:
                payload["data"]["timezone_source"] = business_context["timezone_source"]
            if not payload["data"].get("needs_branch_selection", False):
                payload["data"]["needs_branch_selection"] = bool(business_context.get("needs_branch_selection", False))
            if payload["data"].get("branch") is None and business_context.get("branch") is not None:
                payload["data"]["branch"] = business_context["branch"]
            if payload["data"].get("selected_branch") is None and business_context.get("selected_branch") is not None:
                payload["data"]["selected_branch"] = business_context["selected_branch"]
            if (not isinstance(payload["data"].get("branches"), list) or payload["data"].get("branches") == []) and business_context.get("branches") is not None:
                payload["data"]["branches"] = business_context["branches"]

            return self._external_context_payload(payload)

        return None

    def _clean_contact(self, contact: Any) -> dict[str, Any] | None:
        if not isinstance(contact, dict):
            return None

        allowed = ("type", "name", "phone", "email", "status", "stage", "owner")
        cleaned: dict[str, Any] = {}

        for key in allowed:
            value = self._clean_string(contact.get(key))
            if value is not None:
                cleaned[key] = value

        return cleaned or None

    def _business_context_payload(self, business_context: Any) -> dict[str, Any] | None:
        if not isinstance(business_context, dict):
            return None

        payload: dict[str, Any] = {}

        timezone = self._clean_string(business_context.get("timezone") or business_context.get("business_timezone"))
        if timezone is not None:
            payload["timezone"] = timezone

        timezone_source = self._clean_string(
            business_context.get("timezone_source")
            or business_context.get("timezoneSource")
            or business_context.get("business_timezone_source")
        )
        if timezone_source is not None:
            payload["timezone_source"] = timezone_source

        needs_branch_selection = business_context.get("needs_branch_selection")
        if isinstance(needs_branch_selection, bool):
            payload["needs_branch_selection"] = needs_branch_selection
        else:
            needs_branch_selection_camel = business_context.get("needsBranchSelection")
            if isinstance(needs_branch_selection_camel, bool):
                payload["needs_branch_selection"] = needs_branch_selection_camel

        branch = self._clean_branch(business_context.get("branch"))
        if branch is not None:
            payload["branch"] = branch

        selected_branch = self._clean_branch(
            business_context.get("selected_branch") if "selected_branch" in business_context else business_context.get("selectedBranch")
        )
        if selected_branch is not None:
            payload["selected_branch"] = selected_branch

        branches = self.context_helper.sanitize_jsonish(business_context.get("branches"), max_depth=5, max_items=5)
        if isinstance(branches, list) and branches != []:
            payload["branches"] = branches

        return payload or None

    def _clean_branch(self, branch: Any) -> dict[str, Any] | str | None:
        if isinstance(branch, dict):
            cleaned = self.context_helper.sanitize_jsonish(branch, max_depth=4, max_items=8, max_string_chars=255)
            return cleaned if isinstance(cleaned, dict) and cleaned != {} else None

        return self._clean_string(branch)

    def _clean_flags(self, flags: Any) -> dict[str, bool]:
        if not isinstance(flags, dict):
            return {
                "needs_human": False,
                "do_not_contact": False,
                "existing_customer": False,
            }

        return {
            "needs_human": bool(flags.get("needs_human", False)),
            "do_not_contact": bool(flags.get("do_not_contact", False)),
            "existing_customer": bool(flags.get("existing_customer", False)),
        }

    def _clean_list_of_dicts(self, value: Any, max_items: int) -> list[dict[str, Any]]:
        if not isinstance(value, list):
            return []

        cleaned: list[dict[str, Any]] = []
        for item in value[:max_items]:
            if not isinstance(item, dict):
                continue

            cleaned_item: dict[str, Any] = {}
            for key, item_value in item.items():
                clean_value = self._clean_scalar(item_value)
                if clean_value is not None:
                    cleaned_item[str(key)] = clean_value

            if cleaned_item:
                cleaned.append(cleaned_item)

        return cleaned

    def _clean_appointments(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        cleaned: dict[str, Any] = {}
        next_appointment = self._clean_appointment(value.get("next"))
        last_appointment = self._clean_appointment(value.get("last"))

        if next_appointment is not None:
            cleaned["next"] = next_appointment
        if last_appointment is not None:
            cleaned["last"] = last_appointment

        return cleaned or None

    def _clean_appointment(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        allowed = ("id", "title", "start_at", "end_at", "note", "summary", "status")
        cleaned: dict[str, Any] = {}
        for key in allowed:
            clean_value = self._clean_scalar(value.get(key))
            if clean_value is not None:
                cleaned[key] = clean_value

        for alias_key, target_key in (("startAt", "start_at"), ("endAt", "end_at")):
            clean_value = self._clean_scalar(value.get(alias_key))
            if clean_value is not None and target_key not in cleaned:
                cleaned[target_key] = clean_value

        return cleaned or None

    def _clean_sales(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        cleaned: dict[str, Any] = {}
        has_won_deals = value.get("has_won_deals")
        if isinstance(has_won_deals, bool):
            cleaned["has_won_deals"] = has_won_deals

        won_deals_count = value.get("won_deals_count")
        if isinstance(won_deals_count, int):
            cleaned["won_deals_count"] = won_deals_count

        total_won_amount = value.get("total_won_amount")
        if isinstance(total_won_amount, (int, float)):
            cleaned["total_won_amount"] = total_won_amount

        currency = self._clean_string(value.get("currency"))
        if currency is not None:
            cleaned["currency"] = currency

        return cleaned or None

    def _clean_scalar(self, value: Any) -> str | int | float | bool | None:
        if isinstance(value, bool):
            return value

        if isinstance(value, (int, float)):
            return value

        return self._clean_string(value)

    def _clean_string(self, value: Any) -> str | None:
        if isinstance(value, str) and value.strip() != "":
            return value.strip()

        return None
