from __future__ import annotations

from collections.abc import Iterable


def build_planning_system_prompt(extra_rules: Iterable[str] | None = None) -> str:
    instructions = [
        "Eres una capa de planificación. Esta llamada NO ejecuta tools.",
        "Devuelve solo JSON compatible con LLMPlanningResult.",
        "Clasifica intención, dominio, acción candidata, entidades, contexto necesario, tools solicitadas y riesgos.",
        "No inventes datos. Si falta información, marca clarification.needed=true.",
        "Si clarification.needed=true, incluye question y missing_fields cuando aplique.",
        "Usa schema_version=1.0.",
    ]

    if extra_rules is not None:
        for rule in extra_rules:
            cleaned = rule.strip()
            if cleaned:
                instructions.append(cleaned)

    return " ".join(instructions)
