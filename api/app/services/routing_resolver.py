from __future__ import annotations

import re
from dataclasses import dataclass

from app.schemas.agent import AgentRequest
from app.services.backend_client import BackendClient


@dataclass(slots=True)
class RoutingContext:
    tenant_id: str
    tenant_slug: str | None = None
    product_id: str | None = None
    product_name: str | None = None
    playbook_id: str | None = None
    entry_point_id: str | None = None
    entry_point_code: str | None = None
    entry_point_utm_id: str | None = None
    entrypoint_ref: str | None = None
    crm_branch_ref: str | None = None
    utm_source: str | None = None
    utm_medium: str | None = None
    utm_campaign: str | None = None
    utm_term: str | None = None
    utm_content: str | None = None
    gclid: str | None = None
    fbclid: str | None = None
    status: str | None = None
    conversation_id: str | None = None
    source: str = "unknown"


class RuntimeRoutingResolver:
    def __init__(self, backend_client: BackendClient) -> None:
        self.backend_client = backend_client

    async def resolve(self, payload: AgentRequest) -> RoutingContext | None:
        entrypoint_ref = self._extract_entrypoint_ref(payload)
        if entrypoint_ref is not None:
            ref_context = await self.backend_client.resolve_entrypoint_ref(entrypoint_ref)
            if ref_context is None:
                return None

            return RoutingContext(
                tenant_id=ref_context.tenant_id,
                tenant_slug=ref_context.tenant_slug,
                product_id=ref_context.product_id,
                product_name=ref_context.product_name,
                playbook_id=ref_context.playbook_id,
                entry_point_id=ref_context.entry_point_id,
                entry_point_code=ref_context.entry_point_code,
                entry_point_utm_id=ref_context.entry_point_utm_id,
                entrypoint_ref=entrypoint_ref,
                crm_branch_ref=ref_context.crm_branch_ref,
                utm_source=ref_context.utm_source,
                utm_medium=ref_context.utm_medium,
                utm_campaign=ref_context.utm_campaign,
                utm_term=ref_context.utm_term,
                utm_content=ref_context.utm_content,
                gclid=ref_context.gclid,
                fbclid=ref_context.fbclid,
                status=ref_context.status,
                source="entrypoint_ref",
            )

        external_channel_id = self._resolve_external_channel_id(payload)
        if external_channel_id is not None:
            channel_context = await self.backend_client.resolve_whatsapp_phone(external_channel_id)
            if isinstance(channel_context, dict) and channel_context.get("tenant_id"):
                return RoutingContext(
                    tenant_id=str(channel_context["tenant_id"]),
                    tenant_slug=str(channel_context.get("tenant_slug")) if channel_context.get("tenant_slug") else None,
                    source="whatsapp_phone_number_id",
                )
            if channel_context is not None:
                return RoutingContext(
                    tenant_id=str(channel_context.get("tenant_id", "")),
                    tenant_slug=str(channel_context.get("tenant_slug")) if channel_context.get("tenant_slug") else None,
                    source="whatsapp_phone_number_id",
                )

        if payload.tenant_id is not None and payload.tenant_id.strip() != "":
            return RoutingContext(
                tenant_id=payload.tenant_id.strip(),
                source="tenant_id",
            )

        return None

    def _extract_entrypoint_ref(self, payload: AgentRequest) -> str | None:
        if payload.entrypoint_ref is not None and payload.entrypoint_ref.strip() != "":
            return payload.entrypoint_ref.strip()

        message_text = payload.message.text.strip()
        patterns = (
            r"\bref(?:[:\s]+)([A-Za-z0-9_-]{3,})",
            r"(?:^|\s)#([A-Za-z0-9_-]{3,})",
        )
        for pattern in patterns:
            match = re.search(pattern, message_text, flags=re.IGNORECASE)
            if match is not None:
                return match.group(1)

        return None

    def _resolve_external_channel_id(self, payload: AgentRequest) -> str | None:
        if payload.external_channel_id is not None and payload.external_channel_id.strip() != "":
            return payload.external_channel_id.strip()

        return None
