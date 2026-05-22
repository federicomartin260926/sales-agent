import json
from datetime import datetime
from zoneinfo import ZoneInfo

from app.schemas.agent import AgentRequest, Contact
from app.schemas.llm import McpRemoteConfig
from app.services.backend_client import CommercialContext, BackendEntryPoint, BackendPlaybook, BackendProduct, BackendSalesRuntime, BackendTenant
from app.services.llm_context_helper import LLMContextHelper
from app.services.llm_prompt_builder import LLMPromptBuilder


def build_backend_context(include_effective_context: bool = True) -> CommercialContext:
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Contexto estable del negocio " + ("muy largo " * 400),
            "tone": "cercano",
            "salesPolicy": {"positioning": "Mensaje", "notes": "Muy largo " * 20},
            "isActive": True,
        }
    )
    product = BackendProduct.model_validate(
        {
            "id": "product-1",
            "tenantId": "tenant-1",
            "name": "Producto Demo",
            "slug": "producto-demo",
            "description": "Descripcion " + ("muy larga " * 220),
            "valueProposition": "Propuesta",
            "salesPolicy": {"pricingNotes": "Nota"},
            "isActive": True,
        }
    )
    playbook = BackendPlaybook.model_validate(
        {
            "id": "playbook-1",
            "tenantId": "tenant-1",
            "productId": "product-1",
            "name": "Guia Demo",
            "config": {"qualificationQuestions": ["Pregunta 1", "Pregunta 2"], "notes": "x" * 5000},
            "isActive": True,
        }
    )
    entry_point = BackendEntryPoint.model_validate(
        {
            "id": "entrypoint-1",
            "code": "demo",
            "name": "Entrada Demo",
            "description": "Descripcion del entrypoint",
            "initial_message": "Hola",
            "crm_branch_ref": "branch-1",
            "is_active": True,
        }
    )

    effective_context = None
    if include_effective_context:
        effective_context = {
            "summary": "Entrada: demo · Guía: Guia Demo · Producto: Producto Demo · Negocio: Negocio Demo",
            "priority": ["entry_point", "playbook", "product", "tenant"],
            "conflict_policy": "Lo específico añade o restringe lo general; el orden efectivo es entry_point > playbook > product > tenant.",
            "effective": {
                "tone": "cercano",
                "objective": "Calificar leads entrantes.",
            },
        }

    return CommercialContext(
        tenant=tenant,
        effective_context=effective_context or {},
        products=[product],
        playbooks=[playbook],
        entry_point=entry_point,
        sales_runtime=BackendSalesRuntime(),
        selected_product=product,
        selected_playbook=playbook,
    )


def test_prompt_builder_limits_history_and_keeps_summary_before_messages():
    helper = LLMContextHelper()
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Necesito información comercial muy concreta.",
        contact=Contact(phone="+34600000000", name="Ana"),
        conversation={
            "external_id": "conv-1",
            "summary": "Resumen previo de la conversación",
            "last_messages": [f"mensaje {index} " + ("x" * 1200) for index in range(10)],
        },
    )

    system_prompt, user_prompt = LLMPromptBuilder(helper).build(payload, None, build_backend_context(), None, None)
    assert "Devuelve solo JSON válido" in system_prompt

    parsed = json.loads(user_prompt)
    assert list(parsed.keys())[:5] == ["effective_context", "tenant", "product", "playbook", "entry_point"]
    assert parsed["effective_context"]["priority"] == ["entry_point", "playbook", "product", "tenant"]
    assert list(parsed.keys())[-1] == "current_message"
    assert list(parsed["conversation"].keys())[:3] == ["external_id", "summary", "last_messages"]
    assert len(parsed["conversation"]["last_messages"]) <= LLMContextHelper.MAX_CONVERSATION_MESSAGES
    assert sum(len(message) for message in parsed["conversation"]["last_messages"]) <= LLMContextHelper.MAX_CONVERSATION_CHARS
    assert parsed["conversation"].get("history_truncated") is True
    assert parsed["conversation"]["summary"] == "Resumen previo de la conversación"
    assert parsed["current_message"] == "Necesito información comercial muy concreta."


def test_prompt_builder_synthesizes_effective_context_when_missing():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    _, user_prompt = LLMPromptBuilder().build(payload, None, build_backend_context(include_effective_context=False), None, None)
    parsed = json.loads(user_prompt)

    assert parsed["effective_context"]["priority"] == ["entry_point", "playbook", "product", "tenant"]
    assert parsed["effective_context"]["effective"]["objective"] == "Propuesta"


def test_prompt_builder_enriches_effective_context_with_mcp_runtime():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["search_properties"],
        require_approval="never",
    )

    _, user_prompt = LLMPromptBuilder().build(payload, None, build_backend_context(), None, mcp_config)
    parsed = json.loads(user_prompt)

    assert parsed["effective_context"]["runtime"]["mcp"]["server_label"] == "tenant_main_mcp"
    assert parsed["effective_context"]["runtime"]["mcp"]["allowed_tools"] == ["search_properties"]


def test_prompt_builder_exposes_effective_context_trace():
    builder = LLMPromptBuilder()

    trace = builder.effective_context_trace(build_backend_context(), McpRemoteConfig(enabled=True, server_label="tenant_main_mcp"))

    assert trace["effective_context_present"] is True
    assert trace["effective_context_source"] == "backend"
    assert trace["effective_context_summary"] == "Entrada: demo · Guía: Guia Demo · Producto: Producto Demo · Negocio: Negocio Demo"
    assert trace["effective_context_priority"] == ["entry_point", "playbook", "product", "tenant"]
    assert trace["mcp_runtime_available"] is True
    assert trace["compact_prompt_enabled"] is False
    assert trace["prompt_mode"] == "legacy"


def test_prompt_builder_exposes_synthesized_effective_context_trace():
    builder = LLMPromptBuilder()

    trace = builder.effective_context_trace(build_backend_context(include_effective_context=False), None)

    assert trace["effective_context_present"] is False
    assert trace["effective_context_source"] == "synthesized_legacy"
    assert trace["effective_context_summary"] == "Entrada: demo · Entrada Demo · Hola · Guía: Guia Demo · Pregunta 1 · Producto: Producto Demo · Nota · Negocio: Negocio Demo · Mensaje"
    assert trace["effective_context_priority"] == ["entry_point", "playbook", "product", "tenant"]
    assert trace["mcp_runtime_available"] is False


def test_prompt_builder_exposes_compact_prompt_flag():
    builder = LLMPromptBuilder(use_compact_effective_context_prompt=True)

    trace = builder.effective_context_trace(build_backend_context(), None)

    assert trace["compact_prompt_enabled"] is True
    assert trace["prompt_mode"] == "compact"


def test_prompt_builder_compact_mode_prioritizes_effective_context_and_reduces_legacy_blocks():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34600000000", name="Ana"),
        conversation={"last_messages": ["Hola"]},
    )

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["search_properties"],
        require_approval="never",
    )

    system_prompt, user_prompt = LLMPromptBuilder(use_compact_effective_context_prompt=True).build(
        payload,
        None,
        build_backend_context(),
        None,
        mcp_config,
    )

    parsed = json.loads(user_prompt)
    assert "Modo compacto activo" in system_prompt
    assert list(parsed.keys())[:4] == ["effective_context", "routing", "contact", "conversation"]
    assert "tenant" not in parsed
    assert "product" not in parsed
    assert "playbook" not in parsed
    assert "entry_point" not in parsed
    assert "sales_runtime" not in parsed
    assert parsed["effective_context"]["runtime"]["mcp"]["server_label"] == "tenant_main_mcp"
    assert parsed["effective_context"]["runtime"]["mcp"]["allowed_tools"] == ["search_properties"]
    assert "legacy_reference" not in parsed


def test_prompt_builder_compact_mode_keeps_minimal_legacy_reference_when_effective_context_is_synthesized():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    _, user_prompt = LLMPromptBuilder(use_compact_effective_context_prompt=True).build(
        payload,
        None,
        build_backend_context(include_effective_context=False),
        None,
        None,
    )

    parsed = json.loads(user_prompt)
    assert list(parsed.keys())[:5] == ["effective_context", "routing", "contact", "conversation", "current_message"]
    assert "tenant" not in parsed
    assert "product" not in parsed
    assert "playbook" not in parsed
    assert "entry_point" not in parsed
    assert "sales_runtime" not in parsed
    assert parsed["legacy_reference"]["tenant"]["name"] == "Negocio Demo"
    assert parsed["legacy_reference"]["product"]["name"] == "Producto Demo"
    assert parsed["legacy_reference"]["playbook"]["name"] == "Guia Demo"
    assert parsed["legacy_reference"]["entry_point"]["code"] == "demo"


def test_prompt_builder_sanitizes_long_commercial_fields():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    _, user_prompt = LLMPromptBuilder().build(payload, None, build_backend_context(), None, None)
    parsed = json.loads(user_prompt)

    assert LLMContextHelper.TRUNCATION_MARKER in parsed["tenant"]["business_context"]
    assert LLMContextHelper.TRUNCATION_MARKER in parsed["product"]["description"]
    assert LLMContextHelper.TRUNCATION_MARKER in parsed["playbook"]["config"]["notes"]


def test_prompt_builder_includes_temporal_context(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Tengo citas programadas para mayo?",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    fixed_now = datetime(2026, 5, 12, 14, 30, 0, tzinfo=ZoneInfo("Europe/Madrid"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_madrid_time", lambda self: fixed_now)

    system_prompt, _ = LLMPromptBuilder().build(payload, None, build_backend_context(), None, None)

    assert "2026-05-12T14:30:00+02:00" in system_prompt
    assert "timezone Europe/Madrid" in system_prompt
    assert "Si el usuario menciona un mes sin año, usa el año actual" in system_prompt
    assert "No uses años pasados salvo que el usuario lo pida explícitamente." in system_prompt
