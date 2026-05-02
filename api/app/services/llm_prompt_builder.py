from __future__ import annotations

import json
from typing import Any

from app.schemas.agent import AgentRequest
from app.services.backend_client import CommercialContext
from app.services.crm_client import CRMContactContext
from app.services.routing_resolver import RoutingContext


class LLMPromptBuilder:
    def build(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        crm_context: CRMContactContext | None,
    ) -> tuple[str, str]:
        system_prompt = (
            "Eres un asistente de ventas por WhatsApp para sales-agent/api. "
            "Responde siempre en español, breve y natural. "
            "Devuelve solo JSON válido, sin markdown ni texto adicional, con estas claves: "
            'reply, intent, score, action, needs_human, data_to_save. '
            "No inventes precios, plazos ni funcionalidades. "
            "Si preguntan por precio y no hay un precio claro, pide 1-2 datos de cualificación. "
            "Si piden humano/persona/asesor/comercial, needs_human debe ser true. "
            "No menciones que eres un modelo ni expongas IDs internos, UTM, refs, tokens o detalles técnicos. "
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
            "crm": self._crm_payload(crm_context),
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

    def _crm_payload(self, crm_context: CRMContactContext | None) -> dict[str, Any] | None:
        if crm_context is None:
            return None

        contact = crm_context.contact
        payload: dict[str, Any] = {
            "contact": {
                "phone": contact.phone,
                "name": contact.name,
                "email": contact.email,
            },
            "flags": crm_context.flags.model_dump(exclude_none=True),
            "recent_notes": crm_context.recent_notes,
            "last_activity_at": crm_context.last_activity_at,
            "summary": crm_context.summary,
        }

        if crm_context.lead is not None:
            payload["lead"] = crm_context.lead.model_dump(exclude_none=True)
        if crm_context.opportunity is not None:
            payload["opportunity"] = crm_context.opportunity.model_dump(exclude_none=True)

        return payload
