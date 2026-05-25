from __future__ import annotations

import json
from datetime import datetime
from typing import Any
from zoneinfo import ZoneInfo

from app.schemas.agent import AgentRequest
from app.services.backend_client import CommercialContext
from app.schemas.llm import McpRemoteConfig
from app.services.routing_resolver import RoutingContext
from app.services.llm_context_helper import LLMContextHelper


class LLMPromptBuilder:
    def __init__(self, context_helper: LLMContextHelper | None = None) -> None:
        self.context_helper = context_helper or LLMContextHelper()

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
            "Mantén el tono breve, útil y orientado a conversación."
        )
        current_madrid_time = self._current_madrid_time()
        system_prompt += (
            f" Contexto temporal explícito: fecha y hora actual {current_madrid_time.isoformat()} "
            "en timezone Europe/Madrid. "
            "Si el usuario menciona un mes sin año, usa el año actual salvo que el contexto indique otro año. "
            "Para consultas de agenda o citas, envía date_from/date_to en formato ISO YYYY-MM-DD "
            "o ISO datetime coherente con la fecha actual. "
            "No uses años pasados salvo que el usuario lo pida explícitamente."
        )

        if mcp_config is not None and mcp_config.enabled and (mcp_config.server_label or "").strip() != "":
            allowed_tools = ", ".join(mcp_config.allowed_tools) if mcp_config.allowed_tools else "las herramientas autorizadas"
            system_prompt += (
                f" Tienes acceso a un MCP remoto nativo del tenant llamado {mcp_config.server_label.strip()}. "
                f"Úsalo cuando necesites datos o acciones cubiertas por {allowed_tools}. "
                "Si el MCP no está disponible, sigue con el flujo normal y no inventes resultados. "
                "Si el usuario pregunta por productos, servicios, catálogo, opciones disponibles, "
                "servicios por categoría, precios orientativos, servicios contratables o alternativas "
                "al producto actual, usa services_search cuando esté disponible y el contexto local "
                "no contenga una lista explícita y suficiente de servicios. "
                "Cuando uses services_search, limita por defecto la búsqueda a 5 resultados salvo que "
                "el usuario pida explícitamente ver más opciones. Usa 3-5 resultados para respuestas "
                "conversacionales normales y máximo 6 para comparativas breves. "
                "No inventes productos, precios ni disponibilidad. Si services_search devuelve resultados, "
                "responde usando esos resultados de forma breve, clara y comercial. "
                "Si no devuelve resultados o falla, orienta de forma general y pide una aclaración breve."
            )

        user_payload = {
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
            "current_message": self.context_helper.sanitize_text(payload.message.text, max_chars=2000),
        }

        return system_prompt, json.dumps(user_payload, ensure_ascii=False, indent=2)

    def _current_madrid_time(self) -> datetime:
        return datetime.now(ZoneInfo("Europe/Madrid"))

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
