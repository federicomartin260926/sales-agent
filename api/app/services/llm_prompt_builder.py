from __future__ import annotations

import copy
import json
from datetime import datetime
from typing import Any
from zoneinfo import ZoneInfo

from app.schemas.agent import AgentRequest
from app.services.backend_client import CommercialContext
from app.schemas.llm import McpRemoteConfig
from app.services.routing_resolver import RoutingContext
from app.services.llm_context_helper import LLMContextHelper


class LLMPromptBuilder:
    def __init__(
        self,
        context_helper: LLMContextHelper | None = None,
        use_compact_effective_context_prompt: bool = False,
    ) -> None:
        self.context_helper = context_helper or LLMContextHelper()
        self.use_compact_effective_context_prompt = use_compact_effective_context_prompt

    def build(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
        mcp_config: McpRemoteConfig | None = None,
    ) -> tuple[str, str]:
        system_prompt = (
            "Eres un asistente de ventas por WhatsApp para sales-agent/api. "
            "Responde siempre en español, breve y natural. "
            "Devuelve solo JSON válido, sin markdown ni texto adicional, con estas claves: "
            "reply, intent, score, action, needs_human, data_to_save. "
            "No inventes precios, plazos ni funcionalidades. "
            "Si preguntan por precio y no hay un precio claro, pide 1-2 datos de cualificación. "
            "Si piden humano/persona/asesor/comercial, needs_human debe ser true. "
            "Si tienes herramientas MCP nativas del tenant, úsalas cuando hagan falta para completar la respuesta. "
            "Si existe effective_context, úsalo como el contexto comercial ya priorizado y trata los bloques legacy como referencia secundaria. "
            "effective_context manda sobre los bloques legacy; usa estos últimos solo como respaldo si falta información. "
            "No inventes datos ni cites sistemas internos, CRM, n8n, webhooks, IDs internos, UTM, refs, tokens o detalles técnicos. "
            "Mantén el tono breve, útil y orientado a conversación."
        )
        if self.use_compact_effective_context_prompt:
            system_prompt += (
                " Modo compacto activo: prioriza effective_context y evita repetir bloques legacy completos. "
                "Usa legacy solo como referencia mínima si effective_context no cubre algún dato."
            )
        current_madrid_time = self._current_madrid_time()
        system_prompt += (
            f" Contexto temporal explícito: fecha y hora actual {current_madrid_time.isoformat()} "
            "en timezone Europe/Madrid. "
            "Si el usuario menciona un mes sin año, usa el año actual salvo que el contexto indique otro año. "
            "Para consultas de agenda o citas, envía date_from/date_to en formato ISO YYYY-MM-DD "
            "o ISO datetime coherente con la fecha actual. "
            "No uses años pasados salvo que el usuario lo pida explícitamente."
        )

        if mcp_config is not None and mcp_config.enabled and (mcp_config.server_label or "").strip() != "":
            allowed_tools = ", ".join(mcp_config.allowed_tools) if mcp_config.allowed_tools else "las herramientas autorizadas"
            system_prompt += (
                f" Tienes acceso a un MCP remoto nativo del tenant llamado {mcp_config.server_label.strip()}. "
                f"Úsalo cuando necesites datos o acciones cubiertas por {allowed_tools}. "
                "Si el MCP no está disponible, sigue con el flujo normal y no inventes resultados. "
                "Si el usuario pregunta por productos, servicios, catálogo, opciones disponibles, "
                "servicios por categoría, precios orientativos, servicios contratables o alternativas "
                "al producto actual, usa services_search cuando esté disponible y el contexto local "
                "no contenga una lista explícita y suficiente de servicios. "
                "Cuando uses services_search, limita por defecto la búsqueda a 5 resultados salvo que "
                "el usuario pida explícitamente ver más opciones. Usa 3-5 resultados para respuestas "
                "conversacionales normales y máximo 6 para comparativas breves. "
                "No inventes productos, precios ni disponibilidad. Si services_search devuelve resultados, "
                "responde usando esos resultados de forma breve, clara y comercial. "
                "Si no devuelve resultados o falla, orienta de forma general y pide una aclaración breve."
            )

        effective_context_payload = self._effective_context_payload(backend_context, mcp_config)
        user_payload = self._user_payload(
            payload,
            routing,
            backend_context,
            effective_context_payload,
            mcp_config,
        )

        return system_prompt, json.dumps(user_payload, ensure_ascii=False, indent=2)

    def effective_context_trace(
        self,
        backend_context: CommercialContext | None,
        mcp_config: McpRemoteConfig | None = None,
    ) -> dict[str, Any]:
        trace: dict[str, Any] = {
            "effective_context_present": False,
            "effective_context_source": "none",
            "effective_context_summary": None,
            "effective_context_priority": None,
            "mcp_runtime_available": bool(mcp_config is not None and mcp_config.enabled),
            "compact_prompt_enabled": bool(self.use_compact_effective_context_prompt),
            "prompt_mode": "compact" if self.use_compact_effective_context_prompt else "legacy",
        }

        if backend_context is None:
            return trace

        trace["effective_context_present"] = backend_context.effective_context != {}
        trace["effective_context_source"] = "backend" if trace["effective_context_present"] else "synthesized_legacy"

        effective_context_payload = self._effective_context_payload(backend_context, mcp_config)
        if isinstance(effective_context_payload, dict):
            summary = effective_context_payload.get("summary")
            if isinstance(summary, str) and summary.strip() != "":
                trace["effective_context_summary"] = summary.strip()

            priority = effective_context_payload.get("priority")
            if isinstance(priority, list) and priority != []:
                trace["effective_context_priority"] = [item for item in priority if isinstance(item, str) and item.strip() != ""]

        return self.context_helper.sanitize_jsonish(trace, max_depth=2, max_items=8, max_string_chars=400)

    def _user_payload(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        effective_context_payload: dict[str, Any] | None,
        mcp_config: McpRemoteConfig | None,
    ) -> dict[str, Any]:
        if self.use_compact_effective_context_prompt:
            return self._compact_user_payload(
                payload,
                routing,
                backend_context,
                effective_context_payload,
            )

        return self._legacy_user_payload(payload, routing, backend_context, mcp_config)

    def _legacy_user_payload(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        mcp_config: McpRemoteConfig | None,
    ) -> dict[str, Any]:
        return {
            "effective_context": self._effective_context_payload(backend_context, mcp_config),
            "tenant": self._tenant_payload(backend_context),
            "product": self._product_payload(backend_context),
            "playbook": self._playbook_payload(backend_context),
            "entry_point": self._entry_point_payload(backend_context),
            "sales_runtime": self._sales_runtime_payload(backend_context),
            "routing": self._routing_payload(routing),
            "contact": self._contact_payload(payload),
            "conversation": self._conversation_payload(payload),
            "current_message": self.context_helper.sanitize_text(payload.message.text, max_chars=2000),
        }

    def _compact_user_payload(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        effective_context_payload: dict[str, Any] | None,
    ) -> dict[str, Any]:
        user_payload: dict[str, Any] = {
            "effective_context": effective_context_payload,
            "routing": self._routing_payload(routing),
            "contact": self._contact_payload(payload),
            "conversation": self._conversation_payload(payload),
            "current_message": self.context_helper.sanitize_text(payload.message.text, max_chars=2000),
        }

        if backend_context is not None and backend_context.effective_context == {}:
            legacy_reference = self._legacy_reference_payload(backend_context)
            if legacy_reference != {}:
                user_payload["legacy_reference"] = legacy_reference

        return user_payload

    def _contact_payload(self, payload: AgentRequest) -> dict[str, Any]:
        return {
            "phone": self.context_helper.sanitize_text(payload.contact.phone, max_chars=64),
            "name": self.context_helper.sanitize_text(payload.contact.name, max_chars=255),
            "channel_type": self.context_helper.sanitize_text(payload.channel_type, max_chars=32),
            "external_channel_id": self.context_helper.sanitize_text(payload.external_channel_id, max_chars=128),
        }

    def _conversation_payload(self, payload: AgentRequest) -> dict[str, Any]:
        return {
            "external_id": self.context_helper.sanitize_text(payload.conversation.external_id, max_chars=128),
            **self.context_helper.build_conversation_payload(payload.conversation.summary, payload.conversation.last_messages),
        }

    def _legacy_reference_payload(self, backend_context: CommercialContext) -> dict[str, Any]:
        reference: dict[str, Any] = {
            "tenant": {
                "id": backend_context.tenant.id,
                "name": self.context_helper.sanitize_text(backend_context.tenant.name, max_chars=255),
                "slug": self.context_helper.sanitize_text(backend_context.tenant.slug, max_chars=180),
                "tone": self.context_helper.sanitize_text(backend_context.tenant.tone, max_chars=120),
            }
        }

        if backend_context.selected_product is not None:
            reference["product"] = {
                "id": backend_context.selected_product.id,
                "name": self.context_helper.sanitize_text(backend_context.selected_product.name, max_chars=255),
                "slug": self.context_helper.sanitize_text(backend_context.selected_product.slug, max_chars=180),
                "value_proposition": self.context_helper.sanitize_text(backend_context.selected_product.value_proposition, max_chars=400),
            }

        if backend_context.selected_playbook is not None:
            reference["playbook"] = {
                "id": backend_context.selected_playbook.id,
                "name": self.context_helper.sanitize_text(backend_context.selected_playbook.name, max_chars=255),
                "product_id": backend_context.selected_playbook.product_id,
            }

        if backend_context.entry_point is not None:
            reference["entry_point"] = {
                "id": backend_context.entry_point.id,
                "code": self.context_helper.sanitize_text(backend_context.entry_point.code, max_chars=180),
                "name": self.context_helper.sanitize_text(backend_context.entry_point.name, max_chars=255),
                "crm_branch_ref": self.context_helper.sanitize_text(backend_context.entry_point.crm_branch_ref, max_chars=255),
            }

        return reference

    def _current_madrid_time(self) -> datetime:
        return datetime.now(ZoneInfo("Europe/Madrid"))

    def _routing_payload(self, routing: RoutingContext | None) -> dict[str, Any] | None:
        if routing is None:
            return None

        return {
            "tenant_id": routing.tenant_id,
            "tenant_slug": routing.tenant_slug,
            "external_channel_id": routing.external_channel_id,
            "product_id": routing.product_id,
            "product_name": routing.product_name,
            "playbook_id": routing.playbook_id,
            "entry_point_id": routing.entry_point_id,
            "entry_point_code": routing.entry_point_code,
            "entry_point_utm_id": routing.entry_point_utm_id,
            "entrypoint_ref": routing.entrypoint_ref,
            "crm_branch_ref": routing.crm_branch_ref,
            "utm_source": routing.utm_source,
            "utm_medium": routing.utm_medium,
            "utm_campaign": routing.utm_campaign,
            "utm_term": routing.utm_term,
            "utm_content": routing.utm_content,
            "gclid": routing.gclid,
            "fbclid": routing.fbclid,
            "status": routing.status,
            "conversation_id": routing.conversation_id,
            "source": routing.source,
        }

    def _tenant_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None:
            return None

        tenant = backend_context.tenant
        return {
            "id": tenant.id,
            "name": self.context_helper.sanitize_text(tenant.name, max_chars=255),
            "slug": self.context_helper.sanitize_text(tenant.slug, max_chars=180),
            "business_context": self.context_helper.sanitize_text(tenant.business_context, max_chars=2000),
            "tone": self.context_helper.sanitize_text(tenant.tone, max_chars=120),
            "sales_policy": self.context_helper.sanitize_jsonish(tenant.sales_policy, max_depth=2, max_items=10, max_string_chars=500),
            "whatsapp_phone_number_id": self.context_helper.sanitize_text(tenant.whatsapp_phone_number_id, max_chars=255),
            "whatsapp_public_phone": self.context_helper.sanitize_text(tenant.whatsapp_public_phone, max_chars=50),
        }

    def _product_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.selected_product is None:
            return None

        product = backend_context.selected_product
        return {
            "id": product.id,
            "name": self.context_helper.sanitize_text(product.name, max_chars=255),
            "slug": self.context_helper.sanitize_text(product.slug, max_chars=180),
            "description": self.context_helper.sanitize_text(product.description, max_chars=1500),
            "value_proposition": self.context_helper.sanitize_text(product.value_proposition, max_chars=1500),
            "base_price_cents": product.base_price_cents,
            "currency": self.context_helper.sanitize_text(product.currency, max_chars=10),
            "external_source": self.context_helper.sanitize_text(product.external_source, max_chars=100),
            "external_reference": self.context_helper.sanitize_text(product.external_reference, max_chars=255),
            "sales_policy": self.context_helper.sanitize_jsonish(product.sales_policy, max_depth=2, max_items=10, max_string_chars=500),
        }

    def _playbook_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.selected_playbook is None:
            return None

        playbook = backend_context.selected_playbook
        return {
            "id": playbook.id,
            "name": self.context_helper.sanitize_text(playbook.name, max_chars=255),
            "product_id": playbook.product_id,
            "config": self.context_helper.sanitize_jsonish(playbook.config, max_depth=2, max_items=10, max_string_chars=500),
        }

    def _entry_point_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None or backend_context.entry_point is None:
            return None

        entry_point = backend_context.entry_point
        return {
            "id": entry_point.id,
            "code": self.context_helper.sanitize_text(entry_point.code, max_chars=180),
            "name": self.context_helper.sanitize_text(entry_point.name, max_chars=255),
            "description": self.context_helper.sanitize_text(entry_point.description, max_chars=500),
            "initial_message": self.context_helper.sanitize_text(entry_point.initial_message, max_chars=500),
            "crm_branch_ref": self.context_helper.sanitize_text(entry_point.crm_branch_ref, max_chars=255),
            "is_active": entry_point.is_active,
        }

    def _sales_runtime_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        if backend_context is None:
            return None

        return backend_context.sales_runtime.model_dump(exclude_none=True)

    def _effective_context_payload(self, backend_context: CommercialContext | None, mcp_config: McpRemoteConfig | None) -> dict[str, Any] | None:
        if backend_context is None:
            return None

        if backend_context.effective_context != {}:
            payload: dict[str, Any] = copy.deepcopy(backend_context.effective_context)
        else:
            payload = self._build_effective_context_from_legacy(backend_context)

        if payload == {}:
            return None

        runtime = payload.get("runtime")
        if not isinstance(runtime, dict):
            runtime = {}
            payload["runtime"] = runtime

        sales_runtime = self._sales_runtime_payload(backend_context)
        if sales_runtime is not None and sales_runtime != {}:
            runtime.setdefault("sales_runtime", sales_runtime)

        if mcp_config is not None and mcp_config.enabled:
            runtime.setdefault(
                "mcp",
                {
                    "enabled": True,
                    "server_label": self.context_helper.sanitize_text(mcp_config.server_label, max_chars=255),
                    "server_url": self.context_helper.sanitize_text(mcp_config.server_url, max_chars=500),
                    "allowed_tools": list(mcp_config.allowed_tools),
                    "require_approval": self.context_helper.sanitize_text(mcp_config.require_approval, max_chars=32),
                },
            )

        return self.context_helper.sanitize_jsonish(payload, max_depth=4, max_items=12, max_string_chars=1000)

    def _build_effective_context_from_legacy(self, backend_context: CommercialContext) -> dict[str, Any]:
        tenant = self._tenant_payload(backend_context)
        if tenant is None:
            return {}

        product = self._product_payload(backend_context)
        playbook = self._playbook_payload(backend_context)
        entry_point = self._entry_point_payload(backend_context)

        tenant_sales_policy = tenant.get("sales_policy") if isinstance(tenant.get("sales_policy"), dict) else {}
        product_sales_policy = product.get("sales_policy") if isinstance(product, dict) and isinstance(product.get("sales_policy"), dict) else {}
        playbook_config = playbook.get("config") if isinstance(playbook, dict) and isinstance(playbook.get("config"), dict) else {}

        summary_parts = []
        if entry_point is not None:
            entry_summary = f"Entrada: {entry_point.get('code')} · {entry_point.get('name')}"
            initial_message = self.context_helper.sanitize_text(entry_point.get("initial_message"), max_chars=500)
            if initial_message is not None:
                entry_summary = f"{entry_summary} · {initial_message}"
            summary_parts.append(entry_summary)

        if playbook is not None:
            playbook_summary = self._summarize_playbook_config(playbook_config)
            summary_parts.append(f"Guía: {playbook.get('name')} · {playbook_summary}")

        if product is not None:
            product_summary = self._summarize_product_policy(product_sales_policy)
            summary_parts.append(f"Producto: {product.get('name')} · {product_summary}")

        tenant_summary = self._summarize_tenant_policy(tenant_sales_policy)
        summary_parts.append(f"Negocio: {tenant.get('name')} · {tenant_summary}")

        effective: dict[str, Any] = {}
        tenant_tone = self.context_helper.sanitize_text(tenant.get("tone"), max_chars=120)
        if tenant_tone is not None:
            effective["tone"] = tenant_tone

        positioning = self._first_non_empty_string(
            self.context_helper.sanitize_text(product_sales_policy.get("positioning"), max_chars=500),
            self.context_helper.sanitize_text(tenant_sales_policy.get("positioning"), max_chars=500),
        )
        if positioning is not None:
            effective["positioning"] = positioning

        objective = self._first_non_empty_string(
            self.context_helper.sanitize_text(playbook_config.get("objective"), max_chars=500),
            self.context_helper.sanitize_text(product.get("value_proposition") if isinstance(product, dict) else None, max_chars=500),
            self.context_helper.sanitize_text(tenant_sales_policy.get("positioning"), max_chars=500),
        )
        if objective is not None:
            effective["objective"] = objective

        qualification_focus = self._merge_unique_lines(
            self._lines_from_value(playbook_config.get("qualificationQuestions")),
            self._lines_from_value(product_sales_policy.get("objections")),
            self._lines_from_value(tenant_sales_policy.get("qualificationFocus")),
        )
        if qualification_focus != []:
            effective["qualification_focus"] = qualification_focus

        handoff_rules = self._merge_unique_lines(
            self._lines_from_value(playbook_config.get("handoffRules")),
            self._lines_from_value(product_sales_policy.get("handoffRules")),
            self._lines_from_value(tenant_sales_policy.get("handoffRules")),
        )
        if handoff_rules != []:
            effective["handoff_rules"] = handoff_rules

        pricing_notes = self._lines_from_value(product_sales_policy.get("pricingNotes"))
        if pricing_notes != []:
            effective["pricing_notes"] = pricing_notes

        sales_boundaries = self._lines_from_value(tenant_sales_policy.get("salesBoundaries"))
        if sales_boundaries != []:
            effective["sales_boundaries"] = sales_boundaries

        agenda_rules = self._lines_from_value(playbook_config.get("agendaRules"))
        if agenda_rules != []:
            effective["agenda_rules"] = agenda_rules

        allowed_actions = self._lines_from_value(playbook_config.get("allowedActions"))
        if allowed_actions != []:
            effective["allowed_actions"] = allowed_actions

        notes = self._merge_unique_lines(
            self._lines_from_value(playbook_config.get("notes")),
            self._lines_from_value(product_sales_policy.get("notes")),
            self._lines_from_value(tenant_sales_policy.get("notes")),
        )
        if notes != []:
            effective["notes"] = notes

        return {
            "summary": " · ".join(summary_parts),
            "priority": ["entry_point", "playbook", "product", "tenant"],
            "conflict_policy": "Lo específico añade o restringe lo general; el orden efectivo es entry_point > playbook > product > tenant.",
            "tenant": {
                "summary": tenant_summary,
                "name": tenant.get("name"),
                "business_context": tenant.get("business_context"),
                "tone": tenant.get("tone"),
                "positioning": self.context_helper.sanitize_text(tenant_sales_policy.get("positioning"), max_chars=500),
                "qualification_focus": self.context_helper.sanitize_text(tenant_sales_policy.get("qualificationFocus"), max_chars=500),
                "handoff_rules": self.context_helper.sanitize_text(tenant_sales_policy.get("handoffRules"), max_chars=500),
                "sales_boundaries": self._lines_from_value(tenant_sales_policy.get("salesBoundaries")),
                "notes": self.context_helper.sanitize_text(tenant_sales_policy.get("notes"), max_chars=1000),
            },
            "product": product,
            "playbook": playbook,
            "entry_point": entry_point,
            "effective": effective,
        }

    def _lines_from_value(self, value: Any) -> list[str]:
        if isinstance(value, str):
            value = value.splitlines()

        if not isinstance(value, list):
            return []

        lines: list[str] = []
        for item in value:
            if not isinstance(item, str):
                continue

            trimmed = item.strip()
            if trimmed != "":
                lines.append(trimmed)

        return lines

    def _merge_unique_lines(self, *lists: list[str]) -> list[str]:
        merged: list[str] = []
        seen: set[str] = set()

        for values in lists:
            for value in values:
                if value in seen:
                    continue

                seen.add(value)
                merged.append(value)

        return merged

    def _first_non_empty_string(self, *values: str | None) -> str | None:
        for value in values:
            if not isinstance(value, str):
                continue

            trimmed = value.strip()
            if trimmed != "":
                return trimmed

        return None

    def _summarize_tenant_policy(self, policy: dict[str, Any]) -> str:
        return self._summarize_by_keys(policy, ("positioning", "qualificationFocus", "handoffRules"))

    def _summarize_product_policy(self, policy: dict[str, Any]) -> str:
        return self._summarize_by_keys(policy, ("positioning", "pricingNotes", "handoffRules"))

    def _summarize_playbook_config(self, config: dict[str, Any]) -> str:
        parts = []
        objective = self.context_helper.sanitize_text(config.get("objective"), max_chars=300)
        if objective is not None:
            parts.append(objective)

        qualification_questions = self._lines_from_value(config.get("qualificationQuestions"))
        if qualification_questions != []:
            parts.append(qualification_questions[0])

        scoring = config.get("scoring")
        if isinstance(scoring, dict):
            max_score = scoring.get("maxScore")
            handoff_threshold = scoring.get("handoffThreshold")
            if isinstance(max_score, int) and isinstance(handoff_threshold, int):
                parts.append(f"score {handoff_threshold}/{max_score}")

        cleaned = [part for part in parts if isinstance(part, str) and part.strip() != ""]
        return " · ".join(cleaned) if cleaned != [] else "Sin resumen"

    def _summarize_by_keys(self, payload: dict[str, Any], keys: tuple[str, ...]) -> str:
        parts: list[str] = []
        for key in keys:
            if key not in payload:
                continue

            value = payload[key]
            if isinstance(value, str):
                trimmed = value.strip()
                if trimmed != "":
                    parts.append(trimmed)
            elif isinstance(value, list):
                first = self._first_non_empty_string(*[item for item in value if isinstance(item, str)])
                if first is not None:
                    parts.append(first)

        cleaned = [part for part in parts if part.strip() != ""]
        return " · ".join(cleaned) if cleaned != [] else "Sin resumen"

    def _external_context_payload(self, contact_context: dict[str, Any] | None) -> dict[str, Any] | None:
        if not isinstance(contact_context, dict):
            return None

        payload: dict[str, Any] = {
            "available": bool(contact_context.get("available", False)),
            "configured": bool(contact_context.get("configured", False)),
            "provider": self._clean_string(contact_context.get("provider")),
            "ok": bool(contact_context.get("ok", False)),
            "found": bool(contact_context.get("found", False)),
            "error_code": self._clean_string(contact_context.get("error_code")),
        }

        data = contact_context.get("data")
        if not isinstance(data, dict):
            return payload

        flags = data.get("flags")
        contact = data.get("contact")
        recent_activity = data.get("recent_activity")
        open_opportunities = data.get("open_opportunities")
        appointments = data.get("appointments")
        sales = data.get("sales")

        payload.update(
            {
                "source": self._clean_string(data.get("source")),
                "summary": self._clean_string(data.get("summary")),
                "contact": self._clean_contact(contact),
                "recent_activity": self._clean_list_of_dicts(recent_activity, max_items=5),
                "open_opportunities": self._clean_list_of_dicts(open_opportunities, max_items=5),
                "appointments": self._clean_appointments(appointments),
                "sales": self._clean_sales(sales),
                "flags": self._clean_flags(flags),
            }
        )

        return payload

    def _clean_contact(self, contact: Any) -> dict[str, Any] | None:
        if not isinstance(contact, dict):
            return None

        allowed = ("type", "name", "phone", "email", "status", "stage", "owner")
        cleaned: dict[str, Any] = {}

        for key in allowed:
            value = self._clean_string(contact.get(key))
            if value is not None:
                cleaned[key] = value

        return cleaned or None

    def _clean_flags(self, flags: Any) -> dict[str, bool]:
        if not isinstance(flags, dict):
            return {
                "needs_human": False,
                "do_not_contact": False,
                "existing_customer": False,
            }

        return {
            "needs_human": bool(flags.get("needs_human", False)),
            "do_not_contact": bool(flags.get("do_not_contact", False)),
            "existing_customer": bool(flags.get("existing_customer", False)),
        }

    def _clean_list_of_dicts(self, value: Any, max_items: int) -> list[dict[str, Any]]:
        if not isinstance(value, list):
            return []

        cleaned: list[dict[str, Any]] = []
        for item in value[:max_items]:
            if not isinstance(item, dict):
                continue

            cleaned_item: dict[str, Any] = {}
            for key, item_value in item.items():
                clean_value = self._clean_scalar(item_value)
                if clean_value is not None:
                    cleaned_item[str(key)] = clean_value

            if cleaned_item:
                cleaned.append(cleaned_item)

        return cleaned

    def _clean_appointments(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        cleaned: dict[str, Any] = {}
        next_appointment = self._clean_appointment(value.get("next"))
        last_appointment = self._clean_appointment(value.get("last"))

        if next_appointment is not None:
            cleaned["next"] = next_appointment
        if last_appointment is not None:
            cleaned["last"] = last_appointment

        return cleaned or None

    def _clean_appointment(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        allowed = ("id", "title", "start_at", "end_at", "note", "summary", "status")
        cleaned: dict[str, Any] = {}
        for key in allowed:
            clean_value = self._clean_scalar(value.get(key))
            if clean_value is not None:
                cleaned[key] = clean_value

        for alias_key, target_key in (("startAt", "start_at"), ("endAt", "end_at")):
            clean_value = self._clean_scalar(value.get(alias_key))
            if clean_value is not None and target_key not in cleaned:
                cleaned[target_key] = clean_value

        return cleaned or None

    def _clean_sales(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict):
            return None

        cleaned: dict[str, Any] = {}
        has_won_deals = value.get("has_won_deals")
        if isinstance(has_won_deals, bool):
            cleaned["has_won_deals"] = has_won_deals

        won_deals_count = value.get("won_deals_count")
        if isinstance(won_deals_count, int):
            cleaned["won_deals_count"] = won_deals_count

        total_won_amount = value.get("total_won_amount")
        if isinstance(total_won_amount, (int, float)):
            cleaned["total_won_amount"] = total_won_amount

        currency = self._clean_string(value.get("currency"))
        if currency is not None:
            cleaned["currency"] = currency

        return cleaned or None

    def _clean_scalar(self, value: Any) -> str | int | float | bool | None:
        if isinstance(value, bool):
            return value

        if isinstance(value, (int, float)):
            return value

        return self._clean_string(value)

    def _clean_string(self, value: Any) -> str | None:
        if isinstance(value, str) and value.strip() != "":
            return value.strip()

        return None
