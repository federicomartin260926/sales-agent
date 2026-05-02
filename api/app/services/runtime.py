from __future__ import annotations

from app.schemas.agent import AgentRequest, AgentResponse
from app.services.backend_client import BackendClient, BackendConversationUpsertPayload, CommercialContext
from app.services.crm_client import CRMClient, CRMContactContext
from app.services.decision_engine import DecisionEngine
from app.services.routing_resolver import RoutingContext, RuntimeRoutingResolver


class AgentRuntime:
    def __init__(
        self,
        backend_client: BackendClient,
        crm_client: CRMClient,
        routing_resolver: RuntimeRoutingResolver,
        decision_engine: DecisionEngine,
    ) -> None:
        self.backend_client = backend_client
        self.crm_client = crm_client
        self.routing_resolver = routing_resolver
        self.decision_engine = decision_engine

    async def respond(self, payload: AgentRequest) -> AgentResponse:
        routing = await self.routing_resolver.resolve(payload)
        if routing is None:
            return AgentResponse(
                reply="No tengo suficiente contexto de routing para identificar el negocio. ¿Puedes compartir el enlace o canal correcto?",
                intent="routing",
                score=0.1,
                action="missing_routing_context",
                needs_human=True,
                data_to_save=self._missing_routing_data(payload),
            )

        backend_context = await self.backend_client.fetch_tenant_context(
            routing.tenant_id,
            routing.product_id,
            routing.playbook_id,
            routing.entry_point_id,
            routing.entrypoint_ref,
            payload.contact.phone,
            routing.external_channel_id or payload.external_channel_id,
        )

        crm_context = await self.crm_client.fetch_contact_context(payload.contact.phone)

        conversation_result = await self.backend_client.upsert_conversation(
            BackendConversationUpsertPayload(
                tenant_id=routing.tenant_id,
                product_id=routing.product_id,
                entry_point_id=routing.entry_point_id,
                entry_point_utm_id=routing.entry_point_utm_id,
                customer_phone=payload.contact.phone,
                customer_name=payload.contact.name,
                first_message=payload.message.text,
                utm_source=routing.utm_source,
                utm_medium=routing.utm_medium,
                utm_campaign=routing.utm_campaign,
                utm_term=routing.utm_term,
                utm_content=routing.utm_content,
                gclid=routing.gclid,
                fbclid=routing.fbclid,
                crm_branch_ref=routing.crm_branch_ref,
            )
        )

        if isinstance(conversation_result, dict):
            conversation = conversation_result.get("conversation")
            if isinstance(conversation, dict) and isinstance(conversation.get("id"), str):
                routing.conversation_id = conversation["id"]

        return await self.decision_engine.decide(
            payload,
            routing=routing,
            backend_context=backend_context,
            crm_context=crm_context,
        )

    def _missing_routing_data(self, payload: AgentRequest) -> dict[str, str]:
        data = {
            "topic": "missing_routing_context",
            "customer_phone": payload.contact.phone,
        }
        if payload.tenant_id is not None and payload.tenant_id.strip() != "":
            data["tenant_id"] = payload.tenant_id.strip()
        if payload.channel_type is not None and payload.channel_type.strip() != "":
            data["channel_type"] = payload.channel_type.strip()
        if payload.external_channel_id is not None and payload.external_channel_id.strip() != "":
            data["external_channel_id"] = payload.external_channel_id.strip()
        if payload.entrypoint_ref is not None and payload.entrypoint_ref.strip() != "":
            data["entrypoint_ref"] = payload.entrypoint_ref.strip()

        return data
