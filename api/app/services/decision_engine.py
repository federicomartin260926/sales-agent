from app.schemas.agent import AgentRequest, AgentResponse


class DecisionEngine:
    def decide(self, payload: AgentRequest) -> AgentResponse:
        message = payload.message.text.lower().strip()

        if any(keyword in message for keyword in ("humano", "asesor", "persona", "comercial")):
            return AgentResponse(
                reply="Perfecto, te paso con una persona del equipo para seguir contigo.",
                intent="handoff",
                score=0.9,
                action="handoff_to_human",
                needs_human=True,
                data_to_save={"topic": "handoff"},
            )

        if any(keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "costo", "coste")):
            return AgentResponse(
                reply="Perfecto, ¿qué tipo de negocio tienes y qué volumen de conversaciones esperas?",
                intent="qualification",
                score=0.8,
                action="ask_question",
                needs_human=False,
                data_to_save={"topic": "pricing"},
            )

        if message.startswith("hola") or message.startswith("buenas") or "buen día" in message or "buen dia" in message:
            return AgentResponse(
                reply="Hola, ¿en qué te puedo ayudar?",
                intent="greeting",
                score=0.95,
                action="greet",
                needs_human=False,
                data_to_save={"topic": "greeting"},
            )

        if any(keyword in message for keyword in ("demo", "agenda", "agendar", "reunión", "reunion", "cita")):
            return AgentResponse(
                reply="Puedo ayudarte con eso. ¿Qué día te viene mejor para verlo?",
                intent="agenda",
                score=0.75,
                action="propose_meeting",
                needs_human=False,
                data_to_save={"topic": "agenda"},
            )

        return AgentResponse(
            reply="Cuéntame un poco más sobre tu negocio para orientarte mejor.",
            intent="open_question",
            score=0.4,
            action="ask_question",
            needs_human=False,
            data_to_save={"topic": "discovery"},
        )
