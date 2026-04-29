from fastapi import APIRouter, Depends

from app.schemas.agent import AgentRequest, AgentResponse
from app.services.backend_client import BackendClient
from app.services.crm_client import CRMClient
from app.services.decision_engine import DecisionEngine
from app.config import get_settings
from app.security import require_internal_api_token
from app.services.routing_resolver import RuntimeRoutingResolver
from app.services.runtime import AgentRuntime

router = APIRouter(dependencies=[Depends(require_internal_api_token)])


def get_backend_client() -> BackendClient:
    return BackendClient(get_settings())


def get_crm_client() -> CRMClient:
    return CRMClient(get_settings())


def get_routing_resolver(backend_client: BackendClient = Depends(get_backend_client)) -> RuntimeRoutingResolver:
    return RuntimeRoutingResolver(backend_client)


def get_decision_engine(
    backend_client: BackendClient = Depends(get_backend_client),
    crm_client: CRMClient = Depends(get_crm_client),
) -> DecisionEngine:
    return DecisionEngine(backend_client, crm_client)


def get_agent_runtime(
    backend_client: BackendClient = Depends(get_backend_client),
    crm_client: CRMClient = Depends(get_crm_client),
    routing_resolver: RuntimeRoutingResolver = Depends(get_routing_resolver),
    decision_engine: DecisionEngine = Depends(get_decision_engine),
) -> AgentRuntime:
    return AgentRuntime(backend_client, crm_client, routing_resolver, decision_engine)


@router.post("/agent/respond", response_model=AgentResponse)
async def respond(
    payload: AgentRequest,
    runtime: AgentRuntime = Depends(get_agent_runtime),
) -> AgentResponse:
    return await runtime.respond(payload)
