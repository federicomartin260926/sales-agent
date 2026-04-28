from fastapi import APIRouter, Depends

from app.schemas.agent import AgentRequest, AgentResponse
from app.services.decision_engine import DecisionEngine
from app.security import require_internal_api_token

router = APIRouter(dependencies=[Depends(require_internal_api_token)])


def get_decision_engine() -> DecisionEngine:
    return DecisionEngine()


@router.post("/agent/respond", response_model=AgentResponse)
async def respond(
    payload: AgentRequest,
    engine: DecisionEngine = Depends(get_decision_engine),
) -> AgentResponse:
    return engine.decide(payload)
