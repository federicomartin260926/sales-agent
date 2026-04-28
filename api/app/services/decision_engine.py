import asyncio

from app.schemas.agent import AgentRequest, AgentResponse
from app.services.backend_client import BackendClient, BackendPlaybook, CommercialContext
from app.config import get_settings
from app.services.crm_client import CRMClient, CRMContactContext


class DecisionEngine:
    def __init__(self, backend_client: BackendClient | None = None, crm_client: CRMClient | None = None) -> None:
        self.backend_client = backend_client or BackendClient(get_settings())
        self.crm_client = crm_client or CRMClient(get_settings())

    async def decide(self, payload: AgentRequest) -> AgentResponse:
        context, crm_context = await asyncio.gather(
            self.backend_client.fetch_tenant_context(payload.tenant_id),
            self.crm_client.fetch_contact_context(payload.contact.phone),
        )
        message = payload.message.text.lower().strip()

        if context is not None:
            return self._decide_with_context(payload, context, crm_context, message)

        return self._decide_without_context(payload, crm_context, message)

    def _decide_without_context(
        self,
        payload: AgentRequest,
        crm_context: CRMContactContext | None,
        message: str,
    ) -> AgentResponse:
        if any(keyword in message for keyword in ("humano", "asesor", "persona", "comercial")):
            return AgentResponse(
                reply="Perfecto, te paso con una persona del equipo para seguir contigo.",
                intent="handoff",
                score=0.9,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_fallback_save(payload, crm_context, "handoff"),
            )

        if any(keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "costo", "coste")):
            return AgentResponse(
                reply="Perfecto, ¿qué tipo de negocio tienes y qué volumen de conversaciones esperas?",
                intent="qualification",
                score=0.8,
                action="ask_question",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, crm_context, "pricing"),
            )

        if message.startswith("hola") or message.startswith("buenas") or "buen día" in message or "buen dia" in message:
            return AgentResponse(
                reply="Hola, ¿en qué te puedo ayudar?",
                intent="greeting",
                score=0.95,
                action="greet",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, crm_context, "greeting"),
            )

        if any(keyword in message for keyword in ("demo", "agenda", "agendar", "reunión", "reunion", "cita")):
            return AgentResponse(
                reply="Puedo ayudarte con eso. ¿Qué día te viene mejor para verlo?",
                intent="agenda",
                score=0.75,
                action="propose_meeting",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, crm_context, "agenda"),
            )

        return AgentResponse(
            reply="Cuéntame un poco más sobre tu negocio para orientarte mejor.",
            intent="open_question",
            score=0.4,
            action="ask_question",
            needs_human=False,
            data_to_save=self._base_fallback_save(payload, crm_context, "discovery"),
        )

    def _decide_with_context(
        self,
        payload: AgentRequest,
        context: CommercialContext,
        crm_context: CRMContactContext | None,
        message: str,
    ) -> AgentResponse:
        tenant_name = context.tenant.name
        product_name = context.selected_product.name if context.selected_product is not None else None
        playbook = context.selected_playbook
        first_question = self._first_qualification_question(playbook)
        crm_name = crm_context.contact.name.strip() if crm_context is not None and crm_context.contact.name is not None else None
        crm_lead_stage = crm_context.lead.stage if crm_context is not None and crm_context.lead is not None else None

        if any(keyword in message for keyword in ("humano", "asesor", "persona", "comercial")):
            return AgentResponse(
                reply=f"Perfecto, te paso con una persona del equipo de {tenant_name} para seguir contigo.",
                intent="handoff",
                score=0.95,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_context_save(payload, context, crm_context, "handoff"),
            )

        if crm_context is not None and crm_context.flags.needs_human:
            return AgentResponse(
                reply=f"Perfecto, te paso con una persona del equipo de {tenant_name} para seguir contigo.",
                intent="handoff",
                score=0.95,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_context_save(payload, context, crm_context, "handoff"),
            )

        if crm_lead_stage in {"qualified", "proposal", "negotiation"} and any(
            keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "demo", "agenda", "agendar", "reunión", "reunion", "cita")
        ):
            return AgentResponse(
                reply=self._qualification_reply(tenant_name, product_name, first_question, crm_name),
                intent="qualification" if "precio" in message or "presupuesto" in message or "costo" in message or "coste" in message else "agenda",
                score=0.86,
                action="ask_question" if "precio" in message or "presupuesto" in message or "costo" in message or "coste" in message else "propose_meeting",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, crm_context, "warm_lead"),
            )

        if any(keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "costo", "coste")):
            reply = self._qualification_reply(tenant_name, product_name, first_question, crm_name)
            return AgentResponse(
                reply=reply,
                intent="qualification",
                score=0.82,
                action="ask_question",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, crm_context, "pricing"),
            )

        if message.startswith("hola") or message.startswith("buenas") or "buen día" in message or "buen dia" in message:
            reply = f"Hola, soy el asistente de {tenant_name}. ¿En qué te puedo ayudar?"
            if crm_name is not None:
                reply = f"Hola {crm_name}, soy el asistente de {tenant_name}. ¿En qué te puedo ayudar?"
            if product_name is not None:
                reply = f"Hola{f' {crm_name}' if crm_name is not None else ''}, soy el asistente de {tenant_name} para {product_name}. ¿En qué te puedo ayudar?"

            return AgentResponse(
                reply=reply,
                intent="greeting",
                score=0.96,
                action="greet",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, crm_context, "greeting"),
            )

        if any(keyword in message for keyword in ("demo", "agenda", "agendar", "reunión", "reunion", "cita")):
            reply = "Puedo ayudarte con eso. ¿Qué día te viene mejor para verlo?"
            if first_question is not None:
                reply = first_question
            if crm_name is not None:
                reply = f"{crm_name}, {reply}"

            return AgentResponse(
                reply=reply,
                intent="agenda",
                score=0.78,
                action="propose_meeting",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, crm_context, "agenda"),
            )

        reply = first_question or f"Cuéntame un poco más sobre {tenant_name} para orientarte mejor."
        if crm_name is not None:
            reply = f"{crm_name}, {reply}"
        return AgentResponse(
            reply=reply,
            intent="open_question",
            score=0.5,
            action="ask_question",
            needs_human=False,
            data_to_save=self._base_context_save(payload, context, crm_context, "discovery"),
        )

    def _base_fallback_save(
        self,
        payload: AgentRequest,
        crm_context: CRMContactContext | None,
        topic: str,
    ) -> dict:
        data = {
            "topic": topic,
            "tenant_id": payload.tenant_id,
        }

        if crm_context is not None:
            data["crm_contact_phone"] = crm_context.contact.phone
            if crm_context.contact.name is not None:
                data["crm_contact_name"] = crm_context.contact.name

            if crm_context.lead is not None:
                data["crm_lead_id"] = crm_context.lead.id
                if crm_context.lead.stage is not None:
                    data["crm_lead_stage"] = crm_context.lead.stage
                if crm_context.lead.status is not None:
                    data["crm_lead_status"] = crm_context.lead.status

            if crm_context.opportunity is not None:
                data["crm_opportunity_id"] = crm_context.opportunity.id
                if crm_context.opportunity.stage is not None:
                    data["crm_opportunity_stage"] = crm_context.opportunity.stage
                if crm_context.opportunity.pipeline is not None:
                    data["crm_pipeline"] = crm_context.opportunity.pipeline

            data["crm_summary"] = crm_context.summary or crm_context.contact.name or crm_context.contact.phone

        return data

    def _base_context_save(
        self,
        payload: AgentRequest,
        context: CommercialContext,
        crm_context: CRMContactContext | None,
        topic: str,
    ) -> dict:
        data = {
            "topic": topic,
            "tenant_id": payload.tenant_id,
            "tenant_name": context.tenant.name,
        }

        if context.selected_product is not None:
            data["product_id"] = context.selected_product.id
            data["product_name"] = context.selected_product.name

        if context.selected_playbook is not None:
            data["playbook_id"] = context.selected_playbook.id
            data["playbook_name"] = context.selected_playbook.name

        if crm_context is not None:
            data["crm_contact_phone"] = crm_context.contact.phone
            if crm_context.contact.name is not None:
                data["crm_contact_name"] = crm_context.contact.name

            if crm_context.lead is not None:
                data["crm_lead_id"] = crm_context.lead.id
                if crm_context.lead.stage is not None:
                    data["crm_lead_stage"] = crm_context.lead.stage
                if crm_context.lead.status is not None:
                    data["crm_lead_status"] = crm_context.lead.status
                if crm_context.lead.owner_name is not None:
                    data["crm_owner_name"] = crm_context.lead.owner_name

            if crm_context.opportunity is not None:
                data["crm_opportunity_id"] = crm_context.opportunity.id
                if crm_context.opportunity.stage is not None:
                    data["crm_opportunity_stage"] = crm_context.opportunity.stage
                if crm_context.opportunity.pipeline is not None:
                    data["crm_pipeline"] = crm_context.opportunity.pipeline

            data["crm_summary"] = crm_context.summary or crm_context.contact.name or crm_context.contact.phone

        return data

    def _first_qualification_question(self, playbook: BackendPlaybook | None) -> str | None:
        if playbook is None:
            return None

        questions = playbook.config.get("qualificationQuestions")
        if not isinstance(questions, list):
            return None

        for question in questions:
            if isinstance(question, str) and question.strip() != "":
                return question.strip()

        return None

    def _qualification_reply(self, tenant_name: str, product_name: str | None, question: str | None, crm_name: str | None) -> str:
        if product_name is not None:
            base = f"Perfecto, veo que te interesa {product_name} en {tenant_name}."
        else:
            base = f"Perfecto, veo que te interesa {tenant_name}."

        if crm_name is not None:
            base = f"{crm_name}, {base}"

        if question is not None:
            return f"{base} {question}"

        return f"{base} ¿Qué tipo de negocio tienes y qué volumen de conversaciones esperas?"
