from __future__ import annotations

from collections.abc import Iterable


DOMAIN_VALUES = ["general", "sales", "catalog", "inventory", "appointment", "crm", "support", "handoff"]
INTENT_VALUES = [
    "unknown",
    "small_talk",
    "ask_business_question",
    "ask_product_or_service_info",
    "catalog_search",
    "inventory_search",
    "inventory_similarity_search",
    "request_availability",
    "select_offered_slot",
    "request_booking_confirmation",
    "request_reschedule",
    "request_cancel",
    "provide_contact_data",
    "request_quote",
    "request_handoff",
    "complaint_or_problem",
    "support_question",
]
ACTION_CANDIDATE_VALUES = [
    "no_action",
    "answer_directly",
    "search_catalog",
    "search_inventory",
    "search_similar_items",
    "get_availability",
    "prepare_booking_confirmation",
    "prepare_reschedule",
    "prepare_cancel",
    "collect_missing_data",
    "ask_clarification",
    "handoff_to_human",
    "create_or_update_crm_contact",
]
READ_TOOL_VALUES = [
    "contact_context",
    "services_search",
    "appointment_availability",
    "appointment_events",
    "catalog_search",
    "inventory_search",
    "inventory_similarity_search",
    "knowledge_search",
]
WRITE_TOOL_VALUES = [
    "appointment_confirm",
    "appointment_reschedule",
    "appointment_cancel",
    "crm_contact_submit",
    "lead_create",
    "handoff_request",
]


def build_planning_system_prompt(extra_rules: Iterable[str] | None = None) -> str:
    instructions = [
        "Eres una capa de planificación. Esta llamada NO ejecuta tools.",
        "Devuelve únicamente un objeto JSON válido y compatible con LLMPlanningResult.",
        "No uses markdown, no uses bloques ```json```, no añadas texto explicativo y no incluyas campos fuera del schema.",
        f"Valores permitidos para domain: {', '.join(DOMAIN_VALUES)}.",
        f"Valores permitidos para intent: {', '.join(INTENT_VALUES)}.",
        f"Valores permitidos para action_candidate: {', '.join(ACTION_CANDIDATE_VALUES)}.",
        f"Tools de lectura válidas: {', '.join(READ_TOOL_VALUES)}.",
        f"Tools de escritura válidas: {', '.join(WRITE_TOOL_VALUES)}.",
        "No uses valores traducidos como salud, informacion_tratamiento o proporcionar_informacion.",
        'Para servicios o tratamientos usa domain="catalog" e intent="ask_product_or_service_info" o intent="catalog_search".',
        'Para "láser cuerpo entero" usa entities.service_name.',
        'Para expresiones como "por la mañana", "por la tarde", "por la noche", usa entities.time_of_day con valores controlados: morning, afternoon, evening, night o any.',
        'Para "mañana por la tarde", usa entities.date="tomorrow" y entities.time_of_day="afternoon".',
        'Si el usuario pide información sobre un servicio, prefiere domain="catalog", intent="ask_product_or_service_info" y action_candidate="search_catalog" o action_candidate="answer_directly" según contexto.',
        'Si hace falta buscar el servicio, solicita "services_search" en tool_request.lookup_tools.',
        'Si el usuario está eligiendo entre slots ya ofrecidos, usa domain="appointment", intent="select_offered_slot" y action_candidate="prepare_booking_confirmation".',
        'Para selección de slot, marca context_request.include_appointment_context=true e include_offered_slots=true para reutilizar los horarios ofrecidos previamente.',
        'Para selección de slot, normaliza la intención en entidades estructuradas y no en texto libre.',
        'Si el usuario dice "el de las 16:30", devuelve entities.time="16:30" y entities.slot_reference="exact_time".',
        'Si el usuario dice "el primero", devuelve entities.selected_slot_index=0 y entities.slot_reference="first".',
        'Si el usuario dice "el último", devuelve entities.slot_reference="last".',
        'Si no puedes normalizar la selección con seguridad, usa clarification.needed=true.',
        'La selección de slot no confirma la cita todavía: no uses appointment_confirm en write_tools y no inventes tools de escritura.',
        "Clasifica intención, dominio, acción candidata, entidades, contexto necesario, tools solicitadas y riesgos.",
        "No inventes datos. Si falta información, marca clarification.needed=true.",
        "Si clarification.needed=true, incluye question y missing_fields cuando aplique.",
        "Usa schema_version=1.0.",
        (
            "Ejemplo válido de catálogo: "
            '{"schema_version":"1.0","domain":"catalog","intent":"ask_product_or_service_info","action_candidate":"search_catalog",'
            '"confidence":0.92,"entities":{"service_name":"láser cuerpo entero","query":"láser cuerpo entero","notes":"información general"},'
            '"context_request":{"include_conversation_history":true,"conversation_history_level":"recent","include_customer_context":"basic",'
            '"include_catalog_context":true,"include_inventory_context":false,"include_appointment_context":false,'
            '"include_existing_appointments":false,"include_offered_slots":false,"include_service_catalog":true},'
            '"tool_request":{"lookup_tools":["services_search"],"write_tools":[],"blocked_tools":[],"reason":"Need catalog lookup for service info."},'
            '"risk_flags":{"ambiguous_reference":false,"missing_required_data":false,"low_confidence":false,"needs_human_review":false,'
            '"explicit_booking_intent":false,"explicit_reschedule_intent":false,"explicit_cancel_intent":false},'
            '"clarification":{"needed":false,"question":null,"missing_fields":[]},'
            '"reason":"The user asks for information about a service and needs a catalog lookup."}'
        ),
        (
            "Ejemplo válido de agenda: "
            '{"schema_version":"1.0","domain":"appointment","intent":"request_availability","action_candidate":"get_availability",'
            '"confidence":0.92,"entities":{"service_name":"láser cuerpo entero","date":"tomorrow","time_of_day":"afternoon","query":"láser cuerpo entero"},'
            '"context_request":{"include_conversation_history":true,"conversation_history_level":"recent","include_customer_context":"basic",'
            '"include_catalog_context":false,"include_inventory_context":false,"include_appointment_context":true,'
            '"include_existing_appointments":false,"include_offered_slots":false,"include_service_catalog":false},'
            '"tool_request":{"lookup_tools":["services_search","appointment_availability"],"write_tools":[],"blocked_tools":[],"reason":"Need service resolution and availability lookup for a service in a time window."},'
            '"risk_flags":{"ambiguous_reference":false,"missing_required_data":false,"low_confidence":false,"needs_human_review":false,'
            '"explicit_booking_intent":false,"explicit_reschedule_intent":false,"explicit_cancel_intent":false},'
            '"clarification":{"needed":false,"question":null,"missing_fields":[]},'
            '"reason":"The user asks for appointment availability for a service and a time of day."}'
        ),
        (
            "Ejemplo válido de selección de slot: "
            '{"schema_version":"1.0","domain":"appointment","intent":"select_offered_slot","action_candidate":"prepare_booking_confirmation",'
            '"confidence":0.94,"entities":{"service_name":"láser cuerpo entero","date":"tomorrow","time":"16:30","slot_reference":"exact_time"},'
            '"context_request":{"include_conversation_history":true,"conversation_history_level":"recent","include_customer_context":"basic",'
            '"include_catalog_context":false,"include_inventory_context":false,"include_appointment_context":true,'
            '"include_existing_appointments":false,"include_offered_slots":true,"include_service_catalog":false},'
            '"tool_request":{"lookup_tools":[],"write_tools":[],"blocked_tools":[],"reason":"The user is selecting one of the previously offered slots before confirmation."},'
            '"risk_flags":{"ambiguous_reference":false,"missing_required_data":false,"low_confidence":false,"needs_human_review":false,'
            '"explicit_booking_intent":true,"explicit_reschedule_intent":false,"explicit_cancel_intent":false},'
            '"clarification":{"needed":false,"question":null,"missing_fields":[]},'
            '"reason":"The user is selecting a previously offered appointment slot and the reservation should not be confirmed yet."}'
        ),
        (
            "Ejemplo válido de selección por índice: "
            '{"schema_version":"1.0","domain":"appointment","intent":"select_offered_slot","action_candidate":"prepare_booking_confirmation",'
            '"confidence":0.93,"entities":{"service_name":"láser cuerpo entero","selected_slot_index":0,"slot_reference":"first"},'
            '"context_request":{"include_conversation_history":true,"conversation_history_level":"recent","include_customer_context":"basic",'
            '"include_catalog_context":false,"include_inventory_context":false,"include_appointment_context":true,'
            '"include_existing_appointments":false,"include_offered_slots":true,"include_service_catalog":false},'
            '"tool_request":{"lookup_tools":[],"write_tools":[],"blocked_tools":[],"reason":"The user selected the first offered slot."},'
            '"risk_flags":{"ambiguous_reference":false,"missing_required_data":false,"low_confidence":false,"needs_human_review":false,'
            '"explicit_booking_intent":true,"explicit_reschedule_intent":false,"explicit_cancel_intent":false},'
            '"clarification":{"needed":false,"question":null,"missing_fields":[]},'
            '"reason":"The user selects the first offered slot and the reservation should not be confirmed yet."}'
        ),
    ]

    if extra_rules is not None:
        for rule in extra_rules:
            cleaned = rule.strip()
            if cleaned:
                instructions.append(cleaned)

    return " ".join(instructions)
