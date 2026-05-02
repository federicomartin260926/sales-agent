import pytest

from app.schemas.agent import AgentRequest, Contact
from app.services.backend_client import BackendRoutingEntryPointUtmContext
from app.services.crm_client import CRMClient
from app.services.decision_engine import DecisionEngine
from app.services.routing_resolver import RuntimeRoutingResolver
from app.services.runtime import AgentRuntime


class RecordingBackendClient:
    def __init__(
        self,
        ref_context: BackendRoutingEntryPointUtmContext | None = None,
        phone_context: dict[str, str] | None = None,
    ) -> None:
        self.ref_context = ref_context
        self.phone_context = phone_context
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def resolve_entrypoint_ref(self, ref: str) -> BackendRoutingEntryPointUtmContext | None:
        self.calls.append(("resolve_entrypoint_ref", (ref,)))
        return self.ref_context

    async def resolve_whatsapp_phone(self, phone_number_id: str):
        self.calls.append(("resolve_whatsapp_phone", (phone_number_id,)))
        return self.phone_context

    async def fetch_tenant_context(self, tenant_id: str, selected_product_id: str | None = None, selected_playbook_id: str | None = None):
        self.calls.append(("fetch_tenant_context", (tenant_id, selected_product_id, selected_playbook_id)))
        return None

    async def upsert_conversation(self, payload):
        self.calls.append(("upsert_conversation", (payload.model_dump(by_alias=True),)))
        return {"created": True, "conversation": {"id": "conversation-1"}}


class NullCRMClient(CRMClient):
    def __init__(self) -> None:
        pass

    async def fetch_contact_context(self, phone: str):
        return None


@pytest.mark.asyncio
async def test_runtime_resolves_entrypoint_ref_before_tenant_or_phone():
    backend = RecordingBackendClient(
        ref_context=BackendRoutingEntryPointUtmContext.model_validate(
            {
                "entry_point_utm_id": "utm-1",
                "ref": "abc123",
                "entry_point_id": "entrypoint-1",
                "entry_point_code": "crm-demo",
                "tenant_id": "tenant-1",
                "tenant_slug": "negocio-demo",
                "product_id": "product-1",
                "product_name": "CRM Automation",
                "playbook_id": "playbook-1",
                "crm_branch_ref": "branch-1",
                "utm_source": "google",
                "utm_medium": "cpc",
                "utm_campaign": "crm_pymes",
                "status": "matched",
            }
        )
    )
    resolver = RuntimeRoutingResolver(backend)  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        external_channel_id="123",
        message="Hola Ref: abc123",
        contact=Contact(phone="+34999999999"),
    )

    routing = await resolver.resolve(payload)

    assert routing is not None
    assert routing.source == "entrypoint_ref"
    assert routing.tenant_id == "tenant-1"
    assert routing.entrypoint_ref == "abc123"
    assert backend.calls == [("resolve_entrypoint_ref", ("abc123",))]


@pytest.mark.asyncio
async def test_runtime_resolves_whatsapp_phone_when_ref_is_missing():
    backend = RecordingBackendClient(phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"})
    resolver = RuntimeRoutingResolver(backend)  # type: ignore[arg-type]
    payload = AgentRequest(
        entrypoint_ref="missing-ref",
        external_channel_id="phone-number-id-1",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    routing = await resolver.resolve(payload)

    assert routing is not None
    assert routing.source == "whatsapp_phone_number_id"
    assert routing.tenant_id == "tenant-1"
    assert backend.calls == [("resolve_entrypoint_ref", ("missing-ref",)), ("resolve_whatsapp_phone", ("phone-number-id-1",))]


@pytest.mark.asyncio
async def test_runtime_uses_entrypoint_ref_context_in_agent_response():
    backend = RecordingBackendClient(
        ref_context=BackendRoutingEntryPointUtmContext.model_validate(
            {
                "entry_point_utm_id": "utm-1",
                "ref": "abc123",
                "entry_point_id": "entrypoint-1",
                "entry_point_code": "crm-demo",
                "tenant_id": "tenant-1",
                "tenant_slug": "negocio-demo",
                "product_id": "product-1",
                "product_name": "CRM Automation",
                "playbook_id": "playbook-1",
                "crm_branch_ref": "branch-1",
                "utm_source": "google",
                "utm_medium": "cpc",
                "utm_campaign": "crm_pymes",
                "status": "matched",
            }
        )
    )
    runtime = AgentRuntime(backend, NullCRMClient(), RuntimeRoutingResolver(backend), DecisionEngine(backend, NullCRMClient()))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.action == "greet"
    assert response.needs_human is False
    assert response.data_to_save["tenant_id"] == "tenant-1"
    assert response.data_to_save["tenant_slug"] == "negocio-demo"
    assert response.data_to_save["product_id"] == "product-1"
    assert response.data_to_save["product_name"] == "CRM Automation"
    assert response.data_to_save["playbook_id"] == "playbook-1"
    assert response.data_to_save["entry_point_id"] == "entrypoint-1"
    assert response.data_to_save["entry_point_code"] == "crm-demo"
    assert response.data_to_save["entry_point_utm_id"] == "utm-1"
    assert response.data_to_save["entrypoint_ref"] == "abc123"
    assert response.data_to_save["crm_branch_ref"] == "branch-1"
    assert response.data_to_save["utm_source"] == "google"
    assert response.data_to_save["utm_medium"] == "cpc"
    assert response.data_to_save["utm_campaign"] == "crm_pymes"
    assert backend.calls[0] == ("resolve_entrypoint_ref", ("abc123",))


@pytest.mark.asyncio
async def test_runtime_missing_routing_context_returns_human_handoff():
    backend = RecordingBackendClient()
    runtime = AgentRuntime(backend, NullCRMClient(), RuntimeRoutingResolver(backend), DecisionEngine(backend, NullCRMClient()))  # type: ignore[arg-type]
    payload = AgentRequest(
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.needs_human is True
    assert response.action == "missing_routing_context"
    assert response.intent == "routing"
    assert backend.calls == []
