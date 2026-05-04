from __future__ import annotations

import json
from typing import Any

from app.schemas.agent import AgentRequest
from app.services.backend_client import CommercialContext
from app.services.routing_resolver import RoutingContext


class LLMPromptBuilder:
    def build(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
    ) -> tuple[str, str]:
        system_prompt = (
            "Eres un asistente de ventas por WhatsApp para sales-agent/api. "
            "Responde siempre en español, breve y natural. "
            "Devuelve solo JSON válido, sin markdown ni texto adicional, con estas claves: "
            "reply, intent, score, action, needs_human, data_to_save. "
            "No inventes precios, plazos ni funcionalidades. "
            "Si preguntan por precio y no hay un precio claro, pide 1-2 datos de cualificación. "
            "Si piden humano/persona/asesor/comercial, needs_human debe ser true. "
            "Si external_context.flags.needs_human es true, needs_human debe ser true. "
            "Si external_context.flags.do_not_contact es true, responde de forma conservadora y needs_human debe ser true. "
            "Puedes usar external_context.summary, recent_activity y open_opportunities para responder con más contexto, "
            "pero no menciones sistemas internos, CRM, n8n, webhooks, IDs internos, UTM, refs, tokens o detalles técnicos. "
            "Mantén el tono breve, útil y orientado a conversación."
        )

        user_payload = {
            "current_message": payload.message.text,
            "contact": {
                "phone": payload.contact.phone,
                "name": payload.contact.name,
                "channel_type": payload.channel_type,
                "external_channel_id": payload.external_channel_id,
            },
            "conversation": {
                "last_messages": payload.conversation.last_messages,
            },
            "routing": self._routing_payload(routing),
            "tenant": self._tenant_payload(backend_context),
            "product": self._product_payload(backend_context),
            "playbook": self._playbook_payload(backend_context),
            "entry_point": self._entry_point_payload(backend_context),
            "sales_runtime": self._sales_runtime_payload(backend_context),
            "external_context": self._external_context_payload(contact_context),
        }

        return system_prompt, json.dumps(user_payload, ensure_ascii=False, indent=2)

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
            "name": tenant.name,
            "slug": tenant.slug,
            "business_context": tenant.business_context,
            "tone": tenant.tone,
            "sales_policy": tenant.sales_policy,
            "whatsapp_phone_number_id": tenant.whatsapp_phone_number_id,
            "whatsapp_public_phone": tenant.whatsapp_public_phone,
        }

    def _product_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.selected_product is None:
            return None

        product = backend_context.selected_product
        return {
            "id": product.id,
            "name": product.name,
            "slug": product.slug,
            "description": product.description,
            "value_proposition": product.value_proposition,
            "base_price_cents": product.base_price_cents,
            "currency": product.currency,
            "external_source": product.external_source,
            "external_reference": product.external_reference,
            "sales_policy": product.sales_policy,
        }

    def _playbook_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.selected_playbook is None:
            return None

        playbook = backend_context.selected_playbook
        return {
            "id": playbook.id,
            "name": playbook.name,
            "product_id": playbook.product_id,
            "config": playbook.config,
        }

    def _entry_point_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.entry_point is None:
            return None

        entry_point = backend_context.entry_point
        return {
            "id": entry_point.id,
            "code": entry_point.code,
            "name": entry_point.name,
            "description": entry_point.description,
            "initial_message": entry_point.initial_message,
            "crm_branch_ref": entry_point.crm_branch_ref,
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

        payload.update(
            {
                "source": self._clean_string(data.get("source")),
                "summary": self._clean_string(data.get("summary")),
                "contact": self._clean_contact(contact),
                "recent_activity": self._clean_list_of_dicts(recent_activity, max_items=5),
                "open_opportunities": self._clean_list_of_dicts(open_opportunities, max_items=5),
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