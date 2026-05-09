import json

from app.schemas.agent import AgentRequest, Contact
from app.services.backend_client import CommercialContext, BackendEntryPoint, BackendPlaybook, BackendProduct, BackendSalesRuntime, BackendTenant
from app.services.llm_context_helper import LLMContextHelper
from app.services.llm_prompt_builder import LLMPromptBuilder


def build_backend_context() -> CommercialContext:
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

    return CommercialContext(
        tenant=tenant,
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
    assert list(parsed.keys())[:5] == ["tenant", "product", "playbook", "entry_point", "sales_runtime"]
    assert list(parsed.keys())[-1] == "current_message"
    assert list(parsed["conversation"].keys())[:3] == ["external_id", "summary", "last_messages"]
    assert len(parsed["conversation"]["last_messages"]) <= LLMContextHelper.MAX_CONVERSATION_MESSAGES
    assert sum(len(message) for message in parsed["conversation"]["last_messages"]) <= LLMContextHelper.MAX_CONVERSATION_CHARS
    assert parsed["conversation"].get("history_truncated") is True
    assert parsed["conversation"]["summary"] == "Resumen previo de la conversación"
    assert parsed["current_message"] == "Necesito información comercial muy concreta."


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
