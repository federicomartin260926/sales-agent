from __future__ import annotations

import json
from datetime import datetime
from typing import Any
from zoneinfo import ZoneInfo

from app.config import Settings, get_settings
from app.schemas.agent import AgentRequest
from app.services.backend_client import CommercialContext
from app.schemas.llm import McpRemoteConfig
from app.services.routing_resolver import RoutingContext
from app.services.llm_context_helper import LLMContextHelper


class LLMPromptBuilder:
    _safety_fallback_timezone = "Europe/Madrid"

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
        current_timezone, current_timezone_source = self._resolve_business_timezone_details(backend_context)
        current_business_time = self._current_business_time(current_timezone)
        current_date = current_business_time.date().isoformat()
        system_prompt += (
            f" Contexto temporal explícito: current_datetime={current_business_time.isoformat()}, "
            f"current_date={current_date}, timezone={current_timezone}. "
            "Usa temporal_context.timezone como referencia local del negocio o sucursal. "
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
                "For any new availability or scheduling request, call appointment_availability before answering availability or unavailability; never reuse previous availability results or previous_response_id for that kind of turn. "
                "Si no puedes resolver el servicio con suficiente confianza, pide una aclaración breve o usa services_search antes de intentar appointment_availability. "
                "No inventes productos, precios ni disponibilidad. Si services_search devuelve resultados, "
                "responde usando esos resultados de forma breve, clara y comercial. "
                "Si no devuelve resultados o falla, orienta de forma general y pide una aclaración breve."
            )
            if "contact_context" in allowed_tools_list:
                system_prompt += (
                    " Si entre las herramientas autorizadas está contact_context y tienes teléfono o email, "
                    "úsala primero para saber si hablas con un lead o customer existente antes de asumir que el contacto es nuevo. "
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
            "tenant": self._tenant_payload(backend_context),
            "product": self._product_payload(backend_context),
            "products": self._products_payload(backend_context),
            "product_selection": self._product_selection_payload(backend_context),
            "playbook": self._playbook_payload(backend_context),
            "entry_point": self._entry_point_payload(backend_context),
            "sales_runtime": self._sales_runtime_payload(backend_context),
            "routing": self._routing_payload(routing),
            "contact": {
                "phone": self.context_helper.sanitize_text(payload.contact.phone, max_chars=64),
                "name": self.context_helper.sanitize_text(payload.contact.name, max_chars=255),
                "channel_type": self.context_helper.sanitize_text(payload.channel_type, max_chars=32),
                "external_channel_id": self.context_helper.sanitize_text(payload.external_channel_id, max_chars=128),
            },
            "conversation": {
                "external_id": self.context_helper.sanitize_text(payload.conversation.external_id, max_chars=128),
                **self.context_helper.build_conversation_payload(payload.conversation.summary, payload.conversation.last_messages),
            },
            "current_message": self.context_helper.sanitize_text(payload.message.text or "", max_chars=2000),
        }

        return system_prompt, json.dumps(user_payload, ensure_ascii=False, indent=2)

    def _current_business_time(self, timezone_name: str) -> datetime:
        return datetime.now(ZoneInfo(timezone_name))

    def _resolve_business_timezone(self, backend_context: CommercialContext | None) -> str:
        return self._resolve_business_timezone_details(backend_context)[0]

    def _resolve_business_timezone_details(self, backend_context: CommercialContext | None) -> tuple[str, str | None]:
        # TODO: cuando el backend exponga un timezone estable por tenant o sucursal,
        # este helper lo leerá primero y seguirá cayendo al fallback configurado como respaldo.
        for source_label, source in self._business_timezone_sources(backend_context):
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

        return self._safety_fallback_timezone, "safety_fallback"

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

    def _business_timezone_sources(self, backend_context: CommercialContext | None) -> list[tuple[str, Any]]:
        if backend_context is None:
            return []

        sources: list[tuple[str, Any]] = []
        crm_context = getattr(backend_context, "crm_context", None)
        if crm_context is not None:
            sources.append(("crm_context", crm_context))

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

        payload.update(
            {
                "source": self._clean_string(data.get("source")),
                "summary": self._clean_string(data.get("summary")),
                "contact": self._clean_contact(contact),
                "recent_activity": self._clean_list_of_dicts(recent_activity, max_items=5),
                "open_opportunities": self._clean_list_of_dicts(open_opportunities, max_items=5),
                "appointments": self._clean_appointments(appointments),
                "sales": self._clean_sales(sales),
                "flags": self._clean_flags(flags),
            }
        )

        return payload

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
