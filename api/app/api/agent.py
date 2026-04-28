from fastapi import APIRouter, Depends

from app.schemas.agent import AgentRequest, AgentResponse
from app.services.backend_client import BackendClient
from app.services.crm_client import CRMClient
from app.services.decision_engine import DecisionEngine
from app.config import get_settings
from app.security import require_internal_api_token

router = APIRouter(dependencies=[Depends(require_internal_api_token)])


def get_backend_client() -> BackendClient:
    return BackendClient(get_settings())


def get_crm_client() -> CRMClient:
    return CRMClient(get_settings())


def get_decision_engine(
    backend_client: BackendClient = Depends(get_backend_client),
    crm_client: CRMClient = Depends(get_crm_client),
) -> DecisionEngine:
    return DecisionEngine(backend_client, crm_client)


@router.post("/agent/respond", response_model=AgentResponse)
async def respond(
    payload: AgentRequest,
    engine: DecisionEngine = Depends(get_decision_engine),
) -> AgentResponse:
    return await engine.decide(payload)
