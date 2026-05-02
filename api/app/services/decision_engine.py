import asyncio

from app.schemas.agent import AgentRequest, AgentResponse
from app.services.backend_client import BackendClient, BackendPlaybook, CommercialContext
from app.config import get_settings
from app.services.crm_client import CRMClient, CRMContactContext
from app.services.routing_resolver import RoutingContext


class DecisionEngine:
    def __init__(self, backend_client: BackendClient | None = None, crm_client: CRMClient | None = None) -> None:
        self.backend_client = backend_client or BackendClient(get_settings())
        self.crm_client = crm_client or CRMClient(get_settings())

    async def decide(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None = None,
        backend_context: CommercialContext | None = None,
        crm_context: CRMContactContext | None = None,
    ) -> AgentResponse:
        if backend_context is None or crm_context is None:
            resolved_tenant_id = self._resolved_tenant_id(payload, routing)
            if resolved_tenant_id is not None and backend_context is None and crm_context is None:
                backend_context, crm_context = await asyncio.gather(
                    self.backend_client.fetch_tenant_context(
                        resolved_tenant_id,
                        routing.product_id if routing is not None else None,
                        routing.playbook_id if routing is not None else None,
                    ),
                    self.crm_client.fetch_contact_context(payload.contact.phone),
                )
            elif backend_context is None and resolved_tenant_id is not None:
                backend_context = await self.backend_client.fetch_tenant_context(
                    resolved_tenant_id,
                    routing.product_id if routing is not None else None,
                    routing.playbook_id if routing is not None else None,
                )
            elif crm_context is None:
                crm_context = await self.crm_client.fetch_contact_context(payload.contact.phone)

        message = payload.message.text.lower().strip()

        if backend_context is not None:
            return self._decide_with_context(payload, backend_context, crm_context, routing, message)

        return self._decide_without_context(payload, crm_context, routing, message)

    def _decide_without_context(
        self,
        payload: AgentRequest,
        crm_context: CRMContactContext | None,
        routing: RoutingContext | None,
        message: str,
    ) -> AgentResponse:
        if any(keyword in message for keyword in ("humano", "asesor", "persona", "comercial")):
            return AgentResponse(
                reply="Perfecto, te paso con una persona del equipo para seguir contigo.",
                intent="handoff",
                score=0.9,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_fallback_save(payload, crm_context, routing, "handoff"),
            )

        if any(keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "costo", "coste")):
            return AgentResponse(
                reply="Perfecto, ¿qué tipo de negocio tienes y qué volumen de conversaciones esperas?",
                intent="qualification",
                score=0.8,
                action="ask_question",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, crm_context, routing, "pricing"),
            )

        if message.startswith("hola") or message.startswith("buenas") or "buen día" in message or "buen dia" in message:
            return AgentResponse(
                reply="Hola, ¿en qué te puedo ayudar?",
                intent="greeting",
                score=0.95,
                action="greet",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, crm_context, routing, "greeting"),
            )

        if any(keyword in message for keyword in ("demo", "agenda", "agendar", "reunión", "reunion", "cita")):
            return AgentResponse(
                reply="Puedo ayudarte con eso. ¿Qué día te viene mejor para verlo?",
                intent="agenda",
                score=0.75,
                action="propose_meeting",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, crm_context, routing, "agenda"),
            )

        return AgentResponse(
            reply="Cuéntame un poco más sobre tu negocio para orientarte mejor.",
            intent="open_question",
            score=0.4,
            action="ask_question",
            needs_human=False,
            data_to_save=self._base_fallback_save(payload, crm_context, routing, "discovery"),
        )

    def _decide_with_context(
        self,
        payload: AgentRequest,
        context: CommercialContext,
        crm_context: CRMContactContext | None,
        routing: RoutingContext | None,
        message: str,
    ) -> AgentResponse:
        tenant_name = context.tenant.name
        product_name = context.selected_product.name if context.selected_product is not None else None
        inferred_product_name = None if context.selected_product is not None else self._infer_product_name(context, message)
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
                data_to_save=self._base_context_save(payload, context, crm_context, routing, "handoff"),
            )

        if crm_context is not None and crm_context.flags.needs_human:
            return AgentResponse(
                reply=f"Perfecto, te paso con una persona del equipo de {tenant_name} para seguir contigo.",
                intent="handoff",
                score=0.95,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_context_save(payload, context, crm_context, routing, "handoff"),
            )

        if product_name is None and inferred_product_name is not None:
            reply = f"Entiendo que consultas por el servicio de {inferred_product_name}. ¿Es correcto?"
            return AgentResponse(
                reply=reply,
                intent="qualification",
                score=0.72,
                action="ask_confirmation",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, crm_context, routing, "product_confirmation"),
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
                data_to_save=self._base_context_save(payload, context, crm_context, routing, "warm_lead"),
            )

        if any(keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "costo", "coste")):
            reply = self._qualification_reply(tenant_name, product_name, first_question, crm_name)
            return AgentResponse(
                reply=reply,
                intent="qualification",
                score=0.82,
                action="ask_question",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, crm_context, routing, "pricing"),
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
                data_to_save=self._base_context_save(payload, context, crm_context, routing, "greeting"),
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
                data_to_save=self._base_context_save(payload, context, crm_context, routing, "agenda"),
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
            data_to_save=self._base_context_save(payload, context, crm_context, routing, "discovery"),
        )

    def _base_fallback_save(
        self,
        payload: AgentRequest,
        crm_context: CRMContactContext | None,
        routing: RoutingContext | None,
        topic: str,
    ) -> dict:
        data = {
            "topic": topic,
            "tenant_id": routing.tenant_id if routing is not None else payload.tenant_id,
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

        self._apply_routing_data(data, routing)

        return data

    def _base_context_save(
        self,
        payload: AgentRequest,
        context: CommercialContext,
        crm_context: CRMContactContext | None,
        routing: RoutingContext | None,
        topic: str,
    ) -> dict:
        data = {
            "topic": topic,
            "tenant_id": routing.tenant_id if routing is not None else payload.tenant_id,
            "tenant_name": context.tenant.name,
        }

        if context.selected_product is not None:
            data["product_id"] = context.selected_product.id
            data["product_name"] = context.selected_product.name
            data["product_slug"] = context.selected_product.slug
            if context.selected_product.external_source is not None:
                data["product_external_source"] = context.selected_product.external_source
            if context.selected_product.external_reference is not None:
                data["product_external_reference"] = context.selected_product.external_reference
            if context.selected_product.base_price_cents is not None:
                data["product_base_price_cents"] = context.selected_product.base_price_cents
            if context.selected_product.currency is not None:
                data["product_currency"] = context.selected_product.currency
        elif routing is not None and routing.product_id is not None:
            data["product_id"] = routing.product_id

        if context.selected_product is not None and context.selected_product_is_fallback:
            data["product_is_fallback"] = True

        if context.selected_playbook is not None:
            data["playbook_id"] = context.selected_playbook.id
            data["playbook_name"] = context.selected_playbook.name
        elif routing is not None and routing.playbook_id is not None:
            data["playbook_id"] = routing.playbook_id

        if context.selected_playbook is not None and context.selected_playbook_is_fallback:
            data["playbook_is_fallback"] = True

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

        self._apply_routing_data(data, routing)

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

    def _resolved_tenant_id(self, payload: AgentRequest, routing: RoutingContext | None) -> str | None:
        if routing is not None:
            return routing.tenant_id

        if payload.tenant_id is not None and payload.tenant_id.strip() != "":
            return payload.tenant_id.strip()

        return None

    def _infer_product_name(self, context: CommercialContext, message: str) -> str | None:
        if not context.products:
            return None

        message_terms = set(self._tokenize(message))
        best_product = None
        best_score = 0

        for product in context.products:
            score = self._product_match_score(product.name, message_terms)
            score += self._product_match_score(product.description, message_terms)
            score += self._product_match_score(product.value_proposition, message_terms)

            if score > best_score:
                best_score = score
                best_product = product

        return best_product.name if best_product is not None and best_score > 0 else None

    def _product_match_score(self, text: str, message_terms: set[str]) -> int:
        tokens = set(self._tokenize(text))
        return len(tokens & message_terms)

    def _tokenize(self, text: str) -> list[str]:
        return [token for token in "".join(ch if ch.isalnum() else " " for ch in text.lower()).split() if token]

    def _apply_routing_data(self, data: dict, routing: RoutingContext | None) -> None:
        if routing is None:
            return

        data["tenant_id"] = routing.tenant_id
        if routing.tenant_slug is not None:
            data["tenant_slug"] = routing.tenant_slug
        if routing.external_channel_id is not None:
            data["external_channel_id"] = routing.external_channel_id
        if routing.product_id is not None:
            data["product_id"] = routing.product_id
        if routing.product_name is not None:
            data["product_name"] = routing.product_name
        if routing.playbook_id is not None:
            data["playbook_id"] = routing.playbook_id
        if routing.entry_point_id is not None:
            data["entry_point_id"] = routing.entry_point_id
        if routing.entry_point_code is not None:
            data["entry_point_code"] = routing.entry_point_code
        if routing.entry_point_utm_id is not None:
            data["entry_point_utm_id"] = routing.entry_point_utm_id
        if routing.entrypoint_ref is not None:
            data["entrypoint_ref"] = routing.entrypoint_ref
        if routing.crm_branch_ref is not None:
            data["crm_branch_ref"] = routing.crm_branch_ref
        if routing.utm_source is not None:
            data["utm_source"] = routing.utm_source
        if routing.utm_medium is not None:
            data["utm_medium"] = routing.utm_medium
        if routing.utm_campaign is not None:
            data["utm_campaign"] = routing.utm_campaign
        if routing.utm_term is not None:
            data["utm_term"] = routing.utm_term
        if routing.utm_content is not None:
            data["utm_content"] = routing.utm_content
        if routing.gclid is not None:
            data["gclid"] = routing.gclid
        if routing.fbclid is not None:
            data["fbclid"] = routing.fbclid
        if routing.conversation_id is not None:
            data["conversation_id"] = routing.conversation_id
