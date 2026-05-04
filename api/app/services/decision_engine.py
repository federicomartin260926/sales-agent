import logging
from typing import Any

from app.schemas.agent import AgentRequest, AgentResponse
from app.services.backend_client import BackendClient, BackendPlaybook, CommercialContext
from app.config import get_settings
from app.services.llm_decision_service import LLMDecisionDraft, LLMDecisionService
from app.services.routing_resolver import RoutingContext


logger = logging.getLogger(__name__)


class DecisionEngine:
    def __init__(self, backend_client: BackendClient | None = None) -> None:
        self.backend_client = backend_client or BackendClient(get_settings())
        self.llm_decision_service = LLMDecisionService(get_settings())

    async def decide(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None = None,
        backend_context: CommercialContext | None = None,
        contact_context: dict | None = None,
    ) -> AgentResponse:
        if backend_context is None:
            resolved_tenant_id = self._resolved_tenant_id(payload, routing)
            if resolved_tenant_id is not None:
                backend_context = await self.backend_client.fetch_tenant_context(
                    resolved_tenant_id,
                    routing.product_id if routing is not None else None,
                    routing.playbook_id if routing is not None else None,
                    routing.entry_point_id if routing is not None else None,
                    routing.entrypoint_ref if routing is not None else None,
                    payload.contact.phone,
                    routing.external_channel_id if routing is not None else payload.external_channel_id,
                )

        message = payload.message.text.lower().strip()

        llm_decision = await self.llm_decision_service.propose(payload, routing, backend_context, contact_context)
        if llm_decision is not None:
            logger.debug("LLM decision accepted intent=%s action=%s", llm_decision.intent, llm_decision.action)
            return self._build_llm_response(payload, routing, backend_context, contact_context, llm_decision)

        if backend_context is not None:
            return self._decide_with_context(payload, backend_context, routing, message, contact_context)

        return self._decide_without_context(payload, routing, message, contact_context)

    def _build_llm_response(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        contact_context: dict | None,
        llm_decision: LLMDecisionDraft,
    ) -> AgentResponse:
        topic = self._topic_from_intent(llm_decision.intent)
        if backend_context is not None:
            base_data = self._base_context_save(payload, backend_context, routing, topic, contact_context)
        else:
            base_data = self._base_fallback_save(payload, routing, topic, contact_context)

        merged_data = dict(llm_decision.data_to_save)
        merged_data.update(base_data)

        needs_human = llm_decision.needs_human
        if self._external_flag_enabled(contact_context, "needs_human") or self._external_flag_enabled(contact_context, "do_not_contact"):
            needs_human = True

        return AgentResponse(
            reply=llm_decision.reply,
            intent=llm_decision.intent,
            score=llm_decision.score,
            action=llm_decision.action,
            needs_human=needs_human,
            data_to_save=merged_data,
            provider=llm_decision.provider,
            model=llm_decision.model,
        )

    def _decide_without_context(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        message: str,
        contact_context: dict | None = None,
    ) -> AgentResponse:
        if self._external_flag_enabled(contact_context, "do_not_contact"):
            return AgentResponse(
                reply="Gracias por escribir. Voy a pasar tu caso a una persona del equipo para revisarlo correctamente.",
                intent="handoff",
                score=0.95,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_fallback_save(payload, routing, "do_not_contact", contact_context),
            )

        if self._external_flag_enabled(contact_context, "needs_human"):
            return AgentResponse(
                reply="Perfecto, te paso con una persona del equipo para seguir contigo.",
                intent="handoff",
                score=0.95,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_fallback_save(payload, routing, "handoff", contact_context),
            )

        if any(keyword in message for keyword in ("humano", "asesor", "persona", "comercial")):
            return AgentResponse(
                reply="Perfecto, te paso con una persona del equipo para seguir contigo.",
                intent="handoff",
                score=0.9,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_fallback_save(payload, routing, "handoff", contact_context),
            )

        if any(keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "costo", "coste")):
            return AgentResponse(
                reply="Perfecto, ¿qué tipo de negocio tienes y qué volumen de conversaciones esperas?",
                intent="qualification",
                score=0.8,
                action="ask_question",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, routing, "pricing", contact_context),
            )

        if message.startswith("hola") or message.startswith("buenas") or "buen día" in message or "buen dia" in message:
            external_name = self._external_contact_name(contact_context)
            reply = "Hola, ¿en qué te puedo ayudar?"
            if external_name is not None:
                reply = f"Hola {external_name}, ¿en qué te puedo ayudar?"

            return AgentResponse(
                reply=reply,
                intent="greeting",
                score=0.95,
                action="greet",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, routing, "greeting", contact_context),
            )

        if any(keyword in message for keyword in ("demo", "agenda", "agendar", "reunión", "reunion", "cita")):
            return AgentResponse(
                reply="Puedo ayudarte con eso. ¿Qué día te viene mejor para verlo?",
                intent="agenda",
                score=0.75,
                action="propose_meeting",
                needs_human=False,
                data_to_save=self._base_fallback_save(payload, routing, "agenda", contact_context),
            )

        return AgentResponse(
            reply="Cuéntame un poco más sobre tu negocio para orientarte mejor.",
            intent="open_question",
            score=0.4,
            action="ask_question",
            needs_human=False,
            data_to_save=self._base_fallback_save(payload, routing, "discovery", contact_context),
        )

    def _decide_with_context(
        self,
        payload: AgentRequest,
        context: CommercialContext,
        routing: RoutingContext | None,
        message: str,
        contact_context: dict | None = None,
    ) -> AgentResponse:
        tenant_name = context.tenant.name
        product_name = context.selected_product.name if context.selected_product is not None else None
        inferred_product_name = None if context.selected_product is not None else self._infer_product_name(context, message)
        playbook = context.selected_playbook
        first_question = self._first_qualification_question(playbook)
        external_name = self._external_contact_name(contact_context)
        external_stage = self._external_contact_stage(contact_context)

        if any(keyword in message for keyword in ("humano", "asesor", "persona", "comercial")):
            return AgentResponse(
                reply=f"Perfecto, te paso con una persona del equipo de {tenant_name} para seguir contigo.",
                intent="handoff",
                score=0.95,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_context_save(payload, context, routing, "handoff", contact_context),
            )

        if self._external_flag_enabled(contact_context, "do_not_contact"):
            return AgentResponse(
                reply=f"Gracias por escribir. Voy a pasar tu caso a una persona del equipo de {tenant_name} para revisarlo correctamente.",
                intent="handoff",
                score=0.95,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_context_save(payload, context, routing, "do_not_contact", contact_context),
            )

        if self._external_flag_enabled(contact_context, "needs_human"):
            return AgentResponse(
                reply=f"Perfecto, te paso con una persona del equipo de {tenant_name} para seguir contigo.",
                intent="handoff",
                score=0.95,
                action="handoff_to_human",
                needs_human=True,
                data_to_save=self._base_context_save(payload, context, routing, "handoff", contact_context),
            )

        if product_name is None and inferred_product_name is not None:
            reply = f"Entiendo que consultas por el servicio de {inferred_product_name}. ¿Es correcto?"
            return AgentResponse(
                reply=reply,
                intent="qualification",
                score=0.72,
                action="ask_confirmation",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, routing, "product_confirmation", contact_context),
            )

        if external_stage in {"qualified", "proposal", "negotiation"} and any(
            keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "demo", "agenda", "agendar", "reunión", "reunion", "cita")
        ):
            return AgentResponse(
                reply=self._qualification_reply(tenant_name, product_name, first_question, external_name),
                intent="qualification" if "precio" in message or "presupuesto" in message or "costo" in message or "coste" in message else "agenda",
                score=0.86,
                action="ask_question" if "precio" in message or "presupuesto" in message or "costo" in message or "coste" in message else "propose_meeting",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, routing, "warm_lead", contact_context),
            )

        if any(keyword in message for keyword in ("precio", "precios", "presupuesto", "presupuestos", "costo", "coste")):
            reply = self._qualification_reply(tenant_name, product_name, first_question, external_name)
            return AgentResponse(
                reply=reply,
                intent="qualification",
                score=0.82,
                action="ask_question",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, routing, "pricing", contact_context),
            )

        if message.startswith("hola") or message.startswith("buenas") or "buen día" in message or "buen dia" in message:
            reply = f"Hola, soy el asistente de {tenant_name}. ¿En qué te puedo ayudar?"
            if external_name is not None:
                reply = f"Hola {external_name}, soy el asistente de {tenant_name}. ¿En qué te puedo ayudar?"
            if product_name is not None:
                reply = f"Hola{f' {external_name}' if external_name is not None else ''}, soy el asistente de {tenant_name} para {product_name}. ¿En qué te puedo ayudar?"

            return AgentResponse(
                reply=reply,
                intent="greeting",
                score=0.96,
                action="greet",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, routing, "greeting", contact_context),
            )

        if any(keyword in message for keyword in ("demo", "agenda", "agendar", "reunión", "reunion", "cita")):
            reply = "Puedo ayudarte con eso. ¿Qué día te viene mejor para verlo?"
            if first_question is not None:
                reply = first_question
            if external_name is not None:
                reply = f"{external_name}, {reply}"

            return AgentResponse(
                reply=reply,
                intent="agenda",
                score=0.78,
                action="propose_meeting",
                needs_human=False,
                data_to_save=self._base_context_save(payload, context, routing, "agenda", contact_context),
            )

        reply = first_question or f"Cuéntame un poco más sobre {tenant_name} para orientarte mejor."
        if external_name is not None:
            reply = f"{external_name}, {reply}"
        return AgentResponse(
            reply=reply,
            intent="open_question",
            score=0.5,
            action="ask_question",
            needs_human=False,
            data_to_save=self._base_context_save(payload, context, routing, "discovery", contact_context),
        )

    def _base_fallback_save(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        topic: str,
        contact_context: dict | None = None,
    ) -> dict:
        data = {
            "topic": topic,
            "tenant_id": routing.tenant_id if routing is not None else payload.tenant_id,
        }

        self._apply_external_context_data(data, contact_context)
        self._apply_routing_data(data, routing)

        return data

    def _base_context_save(
        self,
        payload: AgentRequest,
        context: CommercialContext,
        routing: RoutingContext | None,
        topic: str,
        contact_context: dict | None = None,
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

        self._apply_external_context_data(data, contact_context)
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

    def _qualification_reply(self, tenant_name: str, product_name: str | None, question: str | None, contact_name: str | None) -> str:
        if product_name is not None:
            base = f"Perfecto, veo que te interesa {product_name} en {tenant_name}."
        else:
            base = f"Perfecto, veo que te interesa {tenant_name}."

        if contact_name is not None:
            base = f"{contact_name}, {base}"

        if question is not None:
            return f"{base} {question}"

        return f"{base} ¿Qué tipo de negocio tienes y qué volumen de conversaciones esperas?"

    def _resolved_tenant_id(self, payload: AgentRequest, routing: RoutingContext | None) -> str | None:
        if routing is not None:
            return routing.tenant_id

        if payload.tenant_id is not None and payload.tenant_id.strip() != "":
            return payload.tenant_id.strip()

        return None

    def _topic_from_intent(self, intent: str) -> str:
        mapping = {
            "greeting": "greeting",
            "qualification": "qualification",
            "agenda": "agenda",
            "handoff": "handoff",
            "open_question": "discovery",
            "info": "info",
            "objection": "objection",
            "not_interested": "not_interested",
            "unknown": "unknown",
        }
        return mapping.get(intent.strip().lower(), "unknown")

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

    def _apply_external_context_data(self, data: dict, contact_context: dict | None) -> None:
        if not isinstance(contact_context, dict):
            return

        data["external_context_available"] = bool(contact_context.get("available", False))
        data["external_context_configured"] = bool(contact_context.get("configured", False))

        provider = contact_context.get("provider")
        if isinstance(provider, str) and provider.strip() != "":
            data["external_context_provider"] = provider.strip()

        latency_ms = contact_context.get("latency_ms")
        if isinstance(latency_ms, int):
            data["external_context_latency_ms"] = latency_ms

        error_code = contact_context.get("error_code")
        if isinstance(error_code, str) and error_code.strip() != "":
            data["external_context_error_code"] = error_code.strip()

        payload_data = contact_context.get("data")
        if not isinstance(payload_data, dict):
            return

        source = payload_data.get("source")
        if isinstance(source, str) and source.strip() != "":
            data["external_context_source"] = source.strip()

        summary = payload_data.get("summary")
        if isinstance(summary, str) and summary.strip() != "":
            data["external_context_summary"] = summary.strip()

        contact = payload_data.get("contact")
        if isinstance(contact, dict):
            mapping = {
                "external_id": "external_contact_id",
                "type": "external_contact_type",
                "name": "external_contact_name",
                "phone": "external_contact_phone",
                "email": "external_contact_email",
                "status": "external_contact_status",
                "stage": "external_contact_stage",
                "owner": "external_contact_owner",
            }
            for source_key, target_key in mapping.items():
                value = contact.get(source_key)
                if isinstance(value, str) and value.strip() != "":
                    data[target_key] = value.strip()

        flags = payload_data.get("flags")
        if isinstance(flags, dict):
            for key in ("needs_human", "do_not_contact", "existing_customer"):
                data[f"external_flag_{key}"] = bool(flags.get(key, False))

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

    def _external_context_data(self, contact_context: dict | None) -> dict:
        if not isinstance(contact_context, dict):
            return {}

        data = contact_context.get("data")
        return data if isinstance(data, dict) else {}

    def _external_contact(self, contact_context: dict | None) -> dict:
        data = self._external_context_data(contact_context)
        contact = data.get("contact")
        return contact if isinstance(contact, dict) else {}

    def _external_flags(self, contact_context: dict | None) -> dict:
        data = self._external_context_data(contact_context)
        flags = data.get("flags")
        return flags if isinstance(flags, dict) else {}

    def _external_contact_name(self, contact_context: dict | None) -> str | None:
        contact = self._external_contact(contact_context)
        name = contact.get("name")
        if isinstance(name, str) and name.strip() != "":
            return name.strip()

        return None

    def _external_contact_stage(self, contact_context: dict | None) -> str | None:
        contact = self._external_contact(contact_context)
        stage = contact.get("stage")
        if isinstance(stage, str) and stage.strip() != "":
            return stage.strip()

        return None

    def _external_flag_enabled(self, contact_context: dict | None, flag: str) -> bool:
        flags = self._external_flags(contact_context)
        return bool(flags.get(flag, False))
