from fastapi import APIRouter, Depends

from app.schemas.agent import AgentRequest, AgentResponse
from app.services.backend_client import BackendClient
from app.services.decision_engine import DecisionEngine
from app.services.external_tool_client import ExternalToolClient
from app.config import get_settings
from app.security import require_internal_api_token
from app.services.routing_resolver import RuntimeRoutingResolver
from app.services.runtime import AgentRuntime

router = APIRouter(dependencies=[Depends(require_internal_api_token)])


def get_backend_client() -> BackendClient:
    return BackendClient(get_settings())


def get_external_tool_client(
    backend_client: BackendClient = Depends(get_backend_client),
) -> ExternalToolClient:
    return ExternalToolClient(get_settings(), backend_client)


def get_routing_resolver(backend_client: BackendClient = Depends(get_backend_client)) -> RuntimeRoutingResolver:
    return RuntimeRoutingResolver(backend_client)


def get_decision_engine(
    backend_client: BackendClient = Depends(get_backend_client),
) -> DecisionEngine:
    return DecisionEngine(backend_client)


def get_agent_runtime(
    backend_client: BackendClient = Depends(get_backend_client),
    external_tool_client: ExternalToolClient = Depends(get_external_tool_client),
    routing_resolver: RuntimeRoutingResolver = Depends(get_routing_resolver),
    decision_engine: DecisionEngine = Depends(get_decision_engine),
) -> AgentRuntime:
    return AgentRuntime(backend_client, external_tool_client, routing_resolver, decision_engine)


@router.post("/agent/respond", response_model=AgentResponse)
async def respond(
    payload: AgentRequest,
    runtime: AgentRuntime = Depends(get_agent_runtime),
) -> AgentResponse:
    return await runtime.respond(payload)