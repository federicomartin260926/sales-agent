from app.schemas.agent import AgentRequest, AgentResponse


class DecisionEngine:
    def decide(self, payload: AgentRequest) -> AgentResponse:
        message = payload.message.lower().strip()

        if "precio" in message or "precios" in message:
            return AgentResponse(
                reply="Perfecto, ¿qué tipo de negocio tienes y qué volumen de conversaciones esperas?",
                intent="qualification",
                score=0.8,
                action="ask_question",
                needs_human=False,
                data_to_save={"topic": "pricing"},
            )

        if "hola" in message or message.startswith("buenas"):
            return AgentResponse(
                reply="Hola, ¿en qué te puedo ayudar?",
                intent="greeting",
                score=0.95,
                action="greet",
                needs_human=False,
                data_to_save={"topic": "greeting"},
            )

        return AgentResponse(
            reply="Cuéntame un poco más sobre tu negocio para orientarte mejor.",
            intent="open_question",
            score=0.4,
            action="ask_question",
            needs_human=False,
            data_to_save={"topic": "discovery"},
        )
