import json
from datetime import datetime
from zoneinfo import ZoneInfo

from app.config import Settings
from app.schemas.agent import AgentRequest, Contact
from app.schemas.llm import McpRemoteConfig
from app.services.backend_client import BackendEntryPoint, BackendPlaybook, BackendProduct, BackendSalesRuntime, BackendTenant, CommercialContext
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
        products=[],
        product_selection={
            "selection_source": "explicit_product_id",
            "candidate_count": 1,
            "needs_service_clarification": False,
            "fallback_to_mcp_allowed": False,
            "reason": "explicit product requested",
        },
        playbooks=[playbook],
        entry_point=entry_point,
        sales_runtime=BackendSalesRuntime(),
        selected_product=product,
        selected_playbook=playbook,
    )


def build_backend_context_with_candidates() -> CommercialContext:
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Contexto estable del negocio",
            "tone": "cercano",
            "salesPolicy": {"positioning": "Mensaje"},
            "isActive": True,
        }
    )
    candidates = [
        BackendProduct.model_validate(
            {
                "id": "product-1",
                "tenantId": "tenant-1",
                "name": "Depilación láser",
                "slug": "depilacion-laser",
                "description": "Depilación progresiva",
                "valueProposition": "Reduce el vello",
                "salesPolicy": {},
                "isActive": True,
            }
        ),
        BackendProduct.model_validate(
            {
                "id": "product-2",
                "tenantId": "tenant-1",
                "name": "Depilación con cera",
                "slug": "depilacion-cera",
                "description": "Depilación temporal",
                "valueProposition": "Sesiones rápidas",
                "salesPolicy": {},
                "isActive": True,
            }
        ),
    ]

    return CommercialContext(
        tenant=tenant,
        products=candidates,
        product_selection={
            "selection_source": "sa_search",
            "search_query_used": "depilación láser",
            "candidate_count": 2,
            "needs_service_clarification": True,
            "fallback_to_mcp_allowed": False,
            "reason": "multiple local product candidates",
        },
        playbooks=[],
        entry_point=None,
        sales_runtime=BackendSalesRuntime(),
        selected_product=None,
        selected_playbook=None,
    )


def build_backend_context_with_timezone(timezone: str) -> CommercialContext:
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Contexto estable del negocio",
            "tone": "cercano",
            "salesPolicy": {"positioning": "Mensaje"},
            "isActive": True,
            "timezone": timezone,
        }
    )

    return CommercialContext(
        tenant=tenant,
        products=[],
        product_selection={
            "selection_source": "explicit_product_id",
            "candidate_count": 1,
            "needs_service_clarification": False,
            "fallback_to_mcp_allowed": False,
            "reason": "explicit product requested",
        },
        playbooks=[],
        entry_point=None,
        sales_runtime=BackendSalesRuntime(),
        selected_product=None,
        selected_playbook=None,
    )


def build_backend_context_with_crm_timezone(crm_timezone: str, sa_timezone: str) -> CommercialContext:
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Contexto estable del negocio",
            "tone": "cercano",
            "salesPolicy": {"positioning": "Mensaje"},
            "isActive": True,
            "timezone": sa_timezone,
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
        crm_context={"timezone": crm_timezone, "timezone_source": "crm_branch"},
        products=[],
        product_selection={
            "selection_source": "explicit_product_id",
            "candidate_count": 1,
            "needs_service_clarification": False,
            "fallback_to_mcp_allowed": False,
            "reason": "explicit product requested",
        },
        playbooks=[],
        entry_point=entry_point,
        sales_runtime=BackendSalesRuntime(),
        selected_product=None,
        selected_playbook=None,
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
    assert list(parsed.keys())[:7] == ["temporal_context", "tenant", "product", "products", "product_selection", "playbook", "entry_point"]
    assert "effective_context" not in parsed
    assert list(parsed.keys())[-1] == "current_message"
    assert list(parsed["conversation"].keys())[:3] == ["external_id", "summary", "last_messages"]
    assert len(parsed["conversation"]["last_messages"]) <= LLMContextHelper.MAX_CONVERSATION_MESSAGES
    assert sum(len(message) for message in parsed["conversation"]["last_messages"]) <= LLMContextHelper.MAX_CONVERSATION_CHARS
    assert parsed["conversation"].get("history_truncated") is True
    assert parsed["conversation"]["summary"] == "Resumen previo de la conversación"
    assert parsed["current_message"] == "Necesito información comercial muy concreta."


def test_prompt_builder_uses_legacy_blocks_without_effective_context():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    _, user_prompt = LLMPromptBuilder().build(payload, None, build_backend_context(), None, None)
    parsed = json.loads(user_prompt)

    assert "effective_context" not in parsed
    assert parsed["tenant"]["name"] == "Negocio Demo"
    assert parsed["product"]["name"] == "Producto Demo"
    assert parsed["products"] == []
    assert parsed["product_selection"]["selection_source"] == "explicit_product_id"
    assert parsed["playbook"]["name"] == "Guia Demo"
    assert parsed["entry_point"]["code"] == "demo"
    assert parsed["sales_runtime"]["has_product_context"] is False


def test_prompt_builder_enriches_prompt_with_mcp_runtime():
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

    system_prompt, user_prompt = LLMPromptBuilder().build(payload, None, build_backend_context(), None, mcp_config)
    parsed = json.loads(user_prompt)

    assert "MCP remoto nativo del tenant" in system_prompt
    assert "tenant_main_mcp" in system_prompt
    assert "search_properties" in system_prompt
    assert "Si no hay catálogo local o el catálogo local no es concluyente" in system_prompt
    assert "bookable=null" in system_prompt
    assert "bookable=true" in system_prompt
    assert "appointment_availability" in system_prompt
    assert "appointment_events" in system_prompt
    assert "1 palabra" in system_prompt
    assert "2 palabras" in system_prompt
    assert "No copies literalmente frases compuestas" in system_prompt
    assert "WhatsApp Business con IA" in system_prompt
    assert "WhatsApp Business IA" in system_prompt
    assert "query='IA'" in system_prompt or "query=\"IA\"" in system_prompt
    assert "query='automatización'" in system_prompt or "query=\"automatización\"" in system_prompt
    assert "service_id canónico" in system_prompt
    assert "appointment_availability" in system_prompt
    assert "appointment_confirm" in system_prompt
    assert "appointment_booking_invitation" in system_prompt
    assert "Nunca metas el slug o integration_key dentro de service_id" in system_prompt
    assert "never reuse previous availability results or previous_response_id" in system_prompt
    assert "contact_context está disponible" not in system_prompt
    assert "crm_contact_submit" not in system_prompt
    assert parsed["product"]["name"] == "Producto Demo"
    assert parsed["products"] == []
    assert parsed["sales_runtime"]["has_product_context"] is False
    assert parsed["tenant"]["tone"] == "cercano"


def test_prompt_builder_guides_service_lookup_before_availability_and_uses_iso_datetimes():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Quiero disponibilidad para láser axilas por la tarde",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["services_search", "appointment_availability"],
        require_approval="never",
    )

    system_prompt, _ = LLMPromptBuilder().build(payload, None, build_backend_context(), None, mcp_config)

    assert "usa primero services_search antes de appointment_availability" in system_prompt
    assert "Prioriza siempre el service_id UUID devuelto por services_search" in system_prompt
    assert "service_ref solo como fallback" in system_prompt
    assert "no inventes service_ref simplificados como laser-axilas" in system_prompt
    assert "Nunca metas el slug o integration_key dentro de service_id" in system_prompt
    assert "Usa duration_minutes real del servicio devuelto por services_search" in system_prompt
    assert "ISO datetime completo con timezone" in system_prompt
    assert "fechas sueltas sin hora" in system_prompt
    assert "mañana o pasado" in system_prompt
    assert "no uses rangos ambiguos sin hora" in system_prompt
    assert "pide una aclaración breve o usa services_search" in system_prompt
    assert "Si el usuario elige uno de los slots que acabas de ofrecer" in system_prompt
    assert "reutiliza el slot ofrecido inmediatamente antes" in system_prompt
    assert "no vuelvas a llamar services_search ni appointment_availability desde cero" in system_prompt
    assert "usa la herramienta de confirmación o booking si existe" in system_prompt
    assert "owner_id/owner_ref/ownerId/ownerRef" in system_prompt
    assert "Si conversation.context_messages incluye el último turno del asistente" in system_prompt
    assert "appointment_confirm, sólo puedes afirmar" in system_prompt
    assert "ok=true y/o confirmed=true" in system_prompt
    assert "no digas que la cita está reservada o confirmada" in system_prompt


def test_prompt_builder_includes_contact_context_guidance_only_when_available():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    backend_context = build_backend_context()

    system_prompt_without_contact, _ = LLMPromptBuilder().build(
        payload,
        None,
        backend_context,
        None,
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["search_properties"],
            require_approval="never",
        ),
    )

    system_prompt_with_contact, _ = LLMPromptBuilder().build(
        payload,
        None,
        backend_context,
        None,
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["search_properties", "contact_context"],
            require_approval="never",
        ),
    )

    assert "Si entre las herramientas autorizadas está contact_context" not in system_prompt_without_contact
    assert "lead o customer existente" not in system_prompt_without_contact
    assert "Si entre las herramientas autorizadas está contact_context" in system_prompt_with_contact
    assert "lead o customer existente" in system_prompt_with_contact
    assert "Si contact_context no devuelve contexto suficiente" in system_prompt_with_contact


def test_prompt_builder_includes_handoff_request_guidance_when_available():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Estoy frustrado con esto",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["services_search", "handoff_request"],
        require_approval="never",
    )

    backend_context = build_backend_context()
    backend_context.tenant.handoff = {"enabled": True, "strategy": "n8n_webhook"}

    system_prompt, _ = LLMPromptBuilder().build(payload, None, backend_context, None, mcp_config)

    assert "handoff_request" in system_prompt
    assert "priority='high'" in system_prompt
    assert "contact.name" in system_prompt
    assert "contact.phone" in system_prompt
    assert "contact.email" in system_prompt
    assert "conversation.last_messages" in system_prompt
    assert "conversation.summary" in system_prompt
    assert "6 a 8 mensajes recientes" in system_prompt
    assert "peticiones explícitas de hablar con una persona" in system_prompt
    assert "wa.me" in system_prompt
    assert "no afirmes que has avisado o registrado nada" in system_prompt


def test_prompt_builder_includes_crm_contact_submit_guidance_when_available():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Tenemos nueva información comercial",
        contact=Contact(phone="+34600000000", name="Ana"),
        conversation={"last_messages": []},
    )

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["contact_context", "crm_contact_submit"],
        require_approval="never",
    )

    system_prompt, _ = LLMPromptBuilder().build(payload, None, build_backend_context(), None, mcp_config)

    assert "crm_contact_submit" in system_prompt
    assert "contact.name" in system_prompt
    assert "conversation.summary" in system_prompt
    assert "source y channel como el origen o canal comercial del contacto" in system_prompt
    assert 'nunca uses tenant_id como source' in system_prompt
    assert 'source="whatsapp" y channel="whatsapp"' in system_prompt
    assert "tenant_id solo debe usarse si la tool lo pide como argumento separado" in system_prompt
    assert "metadata.origin=sales_agent" in system_prompt
    assert "metadata.sa_conversation_id" in system_prompt
    assert "No decidas si el resultado debe ser lead, customer o note" in system_prompt
    assert "No llames crm_contact_submit en cada mensaje" in system_prompt


def test_prompt_builder_keeps_crm_contact_submit_guidance_but_not_contact_context_when_not_allowed():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Tenemos nueva información comercial",
        contact=Contact(phone="+34600000000", name="Ana"),
        conversation={"last_messages": []},
    )

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["crm_contact_submit"],
        require_approval="never",
    )

    system_prompt, _ = LLMPromptBuilder().build(payload, None, build_backend_context(), None, mcp_config)

    assert "crm_contact_submit" in system_prompt
    assert "source y channel como el origen o canal comercial del contacto" in system_prompt
    assert 'nunca uses tenant_id como source' in system_prompt
    assert "source=\"whatsapp\" y channel=\"whatsapp\"" in system_prompt
    assert "Si entre las herramientas autorizadas está contact_context" not in system_prompt
    assert "lead o customer existente" not in system_prompt


def test_prompt_builder_shows_candidate_products_and_clarification():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Busco depilación láser",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    system_prompt, user_prompt = LLMPromptBuilder().build(payload, None, build_backend_context_with_candidates(), None, None)
    parsed = json.loads(user_prompt)

    assert "product_selection.needs_service_clarification" in system_prompt
    assert parsed["product"] is None
    assert len(parsed["products"]) == 2
    assert parsed["product_selection"]["selection_source"] == "sa_search"
    assert parsed["product_selection"]["needs_service_clarification"] is True


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


def test_prompt_builder_uses_default_business_timezone_when_context_has_none(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Tengo citas programadas para mayo?",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    fixed_now = datetime(2026, 5, 12, 14, 30, 0, tzinfo=ZoneInfo("Europe/Madrid"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    builder = LLMPromptBuilder(settings=Settings())
    backend_context = build_backend_context()
    assert builder._resolve_business_timezone(backend_context) == "Europe/Madrid"

    system_prompt, user_prompt = builder.build(payload, None, backend_context, None, None)
    parsed = json.loads(user_prompt)

    assert "2026-05-12T14:30:00+02:00" in system_prompt
    assert "timezone=Europe/Madrid" in system_prompt
    assert "Usa temporal_context.timezone como referencia local del negocio o sucursal." in system_prompt
    assert "Si el backend no proporciona timezone específica, ya se habrá aplicado el fallback configurado por el sistema." in system_prompt
    assert "Si el usuario menciona un mes sin año, usa el año actual" in system_prompt
    assert "No uses años pasados salvo que el usuario lo pida explícitamente." in system_prompt
    assert parsed["temporal_context"]["timezone"] == "Europe/Madrid"
    assert parsed["temporal_context"]["timezone_source"] == "settings.default_business_timezone"


def test_prompt_builder_uses_business_timezone_when_context_provides_it(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    backend_context = build_backend_context_with_timezone("America/New_York")
    fixed_now = datetime(2026, 5, 12, 8, 30, 0, tzinfo=ZoneInfo("America/New_York"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    builder = LLMPromptBuilder(settings=Settings())
    assert builder._resolve_business_timezone(backend_context) == "America/New_York"

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["services_search", "appointment_availability"],
        require_approval="never",
    )

    system_prompt, user_prompt = builder.build(payload, None, backend_context, None, mcp_config)
    parsed = json.loads(user_prompt)

    assert "current_datetime=2026-05-12T08:30:00-04:00" in system_prompt
    assert "current_date=2026-05-12" in system_prompt
    assert "timezone=America/New_York" in system_prompt
    assert parsed["temporal_context"]["timezone"] == "America/New_York"
    assert parsed["temporal_context"]["current_datetime"] == "2026-05-12T08:30:00-04:00"
    assert parsed["temporal_context"]["current_date"] == "2026-05-12"
    assert parsed["temporal_context"]["timezone_source"] == "tenant"
    assert "Usa temporal_context.timezone como referencia local del negocio o sucursal." in system_prompt


def test_prompt_builder_uses_crm_timezone_over_sa_timezone(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Tengo citas programadas para mayo?",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    fixed_now = datetime(2026, 5, 12, 13, 30, 0, tzinfo=ZoneInfo("Atlantic/Canary"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    builder = LLMPromptBuilder(settings=Settings())
    backend_context = build_backend_context_with_crm_timezone("Atlantic/Canary", "Europe/Madrid")
    assert builder._resolve_business_timezone(backend_context) == "Atlantic/Canary"

    _, user_prompt = builder.build(payload, None, backend_context, None, None)
    parsed = json.loads(user_prompt)

    assert parsed["temporal_context"]["timezone"] == "Atlantic/Canary"
    assert parsed["temporal_context"]["timezone_source"] == "crm_branch"
    assert parsed["temporal_context"]["current_datetime"] == "2026-05-12T13:30:00+01:00"
    assert parsed["temporal_context"]["current_date"] == "2026-05-12"


def test_prompt_builder_uses_crm_tenant_timezone_source_when_available(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Quiero disponibilidad",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    fixed_now = datetime(2026, 5, 12, 13, 30, 0, tzinfo=ZoneInfo("Atlantic/Canary"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    builder = LLMPromptBuilder(settings=Settings())
    backend_context = build_backend_context_with_crm_timezone("Atlantic/Canary", "Europe/Madrid")
    backend_context.crm_context["timezone_source"] = "crm_tenant"

    _, user_prompt = builder.build(payload, None, backend_context, None, None)
    parsed = json.loads(user_prompt)

    assert parsed["temporal_context"]["timezone"] == "Atlantic/Canary"
    assert parsed["temporal_context"]["timezone_source"] == "crm_tenant"


def test_prompt_builder_ignores_invalid_crm_timezone_and_uses_sa_timezone(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Tengo citas programadas para mayo?",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    fixed_now = datetime(2026, 5, 12, 8, 30, 0, tzinfo=ZoneInfo("America/New_York"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    builder = LLMPromptBuilder(settings=Settings())
    backend_context = build_backend_context_with_crm_timezone("Invalid/Zone", "America/New_York")

    assert builder._resolve_business_timezone(backend_context) == "America/New_York"

    _, user_prompt = builder.build(payload, None, backend_context, None, None)
    parsed = json.loads(user_prompt)

    assert parsed["temporal_context"]["timezone"] == "America/New_York"
    assert parsed["temporal_context"]["timezone_source"] == "tenant"
    assert parsed["temporal_context"]["current_datetime"] == "2026-05-12T08:30:00-04:00"
    assert parsed["temporal_context"]["current_date"] == "2026-05-12"


def test_prompt_builder_uses_configured_fallback_timezone_when_context_has_none(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Tengo citas programadas para mayo?",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    fixed_now = datetime(2026, 5, 12, 13, 30, 0, tzinfo=ZoneInfo("Atlantic/Canary"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    builder = LLMPromptBuilder(settings=Settings(SA_DEFAULT_BUSINESS_TIMEZONE="Atlantic/Canary"))
    backend_context = build_backend_context()

    assert builder._resolve_business_timezone(backend_context) == "Atlantic/Canary"

    _, user_prompt = builder.build(payload, None, backend_context, None, None)
    parsed = json.loads(user_prompt)

    assert parsed["temporal_context"]["timezone"] == "Atlantic/Canary"
    assert parsed["temporal_context"]["timezone_source"] == "settings.default_business_timezone"
    assert parsed["temporal_context"]["current_datetime"] == "2026-05-12T13:30:00+01:00"
    assert parsed["temporal_context"]["current_date"] == "2026-05-12"


def test_prompt_builder_falls_back_to_madrid_when_configured_timezone_is_invalid(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Tengo citas programadas para mayo?",
        contact=Contact(phone="+34600000000"),
        conversation={"last_messages": []},
    )

    fixed_now = datetime(2026, 5, 12, 14, 30, 0, tzinfo=ZoneInfo("Europe/Madrid"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    builder = LLMPromptBuilder(settings=Settings(SA_DEFAULT_BUSINESS_TIMEZONE="Invalid/Zone"))
    backend_context = build_backend_context()

    assert builder._resolve_business_timezone(backend_context) == "Europe/Madrid"

    _, user_prompt = builder.build(payload, None, backend_context, None, None)
    parsed = json.loads(user_prompt)

    assert parsed["temporal_context"]["timezone"] == "Europe/Madrid"
    assert parsed["temporal_context"]["timezone_source"] == "safety_fallback"
    assert parsed["temporal_context"]["current_datetime"] == "2026-05-12T14:30:00+02:00"


def test_prompt_builder_resolves_relative_availability_against_current_turn(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34600000000"),
        conversation={
            "summary": "Historial con referencias temporales previas",
            "last_messages": [
                "2026-06-08: ¿Qué disponibilidad tienes para mañana láser cuerpo entero?",
                "2026-06-08: Por la tarde no tienes nada disponible?",
            ],
        },
    )

    fixed_now = datetime(2026, 6, 9, 19, 22, 0, tzinfo=ZoneInfo("Europe/Madrid"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["services_search", "appointment_availability"],
        require_approval="never",
    )

    system_prompt, user_prompt = LLMPromptBuilder(settings=Settings()).build(payload, None, build_backend_context(), None, mcp_config)
    parsed = json.loads(user_prompt)

    assert "current_datetime=2026-06-09T19:22:00+02:00" in system_prompt
    assert "current_date=2026-06-09" in system_prompt
    assert "timezone=Europe/Madrid" in system_prompt
    assert "mañana = current_date + 1 día" in system_prompt
    assert "pasado mañana = current_date + 2 días" in system_prompt
    assert "por la mañana" in system_prompt
    assert "09:00 a 14:00" in system_prompt
    assert "por la tarde" in system_prompt
    assert "15:00 a 20:00 o 21:00" in system_prompt
    assert "nunca empieces a las 12:00" in system_prompt
    assert "No arrastres el 'mañana' de mensajes anteriores" in system_prompt
    assert "Si la tool de agenda acepta timezone, envíala explícitamente usando temporal_context.timezone." in system_prompt
    assert "No uses UTC para franjas comerciales." in system_prompt
    assert parsed["temporal_context"]["current_datetime"] == "2026-06-09T19:22:00+02:00"
    assert parsed["temporal_context"]["current_date"] == "2026-06-09"
    assert parsed["temporal_context"]["timezone"] == "Europe/Madrid"
    assert parsed["current_message"] == "Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias."
    assert parsed["conversation"]["last_messages"][0].startswith("2026-06-08:")
    assert parsed["conversation"]["last_messages"][1].startswith("2026-06-08:")


def test_prompt_builder_keeps_previous_slot_context_with_owner_for_confirmation(monkeypatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="17:35 por favor",
        contact=Contact(phone="+34600000000"),
        conversation={
            "last_messages": [
                "SA: Para mañana por la tarde hay disponibilidad a las 17:35 y a las 19:10.",
            ],
            "context_messages": [
                {
                    "id": "message-availability-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Para mañana por la tarde hay disponibilidad a las 17:35 y a las 19:10.",
                    "metadata": {
                        "mcp_tool_traces": [
                            {
                                "tool_name": "appointment_availability",
                                "output": {
                                    "available": True,
                                    "slots": [
                                        {
                                            "start": "2026-06-11T17:35:00+02:00",
                                            "end": "2026-06-11T19:05:00+02:00",
                                            "service_id": "service-uuid",
                                            "owner_id": "owner-uuid",
                                            "owner_ref": "owner-ref-1",
                                            "timezone": "Europe/Madrid",
                                        }
                                    ],
                                },
                            }
                        ]
                    },
                }
            ],
        },
    )

    fixed_now = datetime(2026, 6, 11, 12, 0, 0, tzinfo=ZoneInfo("Europe/Madrid"))
    monkeypatch.setattr(LLMPromptBuilder, "_current_business_time", lambda self, timezone_name: fixed_now)

    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["services_search", "appointment_availability", "appointment_confirm"],
        require_approval="never",
    )

    system_prompt, user_prompt = LLMPromptBuilder(settings=Settings()).build(payload, None, build_backend_context(), None, mcp_config)
    parsed = json.loads(user_prompt)

    assert "owner_id/owner_ref/ownerId/ownerRef" in system_prompt
    assert "Si conversation.appointment_context contiene offered_slots" in system_prompt
    assert parsed["conversation"]["appointment_context"]["tool_name"] == "appointment_availability"
    assert parsed["conversation"]["appointment_context"]["source_message_id"] == "message-availability-1"
    assert parsed["conversation"]["appointment_context"]["offered_slots"][0]["start"] == "2026-06-11T17:35:00+02:00"
    assert parsed["conversation"]["appointment_context"]["offered_slots"][0]["end"] == "2026-06-11T19:05:00+02:00"
    assert parsed["conversation"]["appointment_context"]["offered_slots"][0]["owner_id"] == "owner-uuid"
    assert parsed["conversation"]["appointment_context"]["offered_slots"][0]["owner_ref"] == "owner-ref-1"
    assert parsed["conversation"]["context_messages"][0]["metadata"]["mcp_tool_traces"][0]["output"]["slots"][0]["owner_id"] == "owner-uuid"
    assert parsed["conversation"]["context_messages"][0]["metadata"]["mcp_tool_traces"][0]["output"]["slots"][0]["owner_ref"] == "owner-ref-1"
    assert parsed["conversation"]["context_messages"][0]["metadata"]["mcp_tool_traces"][0]["output"]["slots"][0]["service_id"] == "service-uuid"
