from __future__ import annotations

from typing import Any

import httpx
from pydantic import BaseModel, ConfigDict, Field

from app.config import Settings


class CRMContact(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    phone: str
    name: str | None = None
    email: str | None = None


class CRMLead(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    id: str | None = None
    status: str | None = None
    stage: str | None = None
    owner_name: str | None = Field(default=None, alias="ownerName")
    score: float | None = None
    source: str | None = None
    is_qualified: bool | None = Field(default=None, alias="isQualified")
    last_interaction_at: str | None = Field(default=None, alias="lastInteractionAt")
    last_touch_summary: str | None = Field(default=None, alias="lastTouchSummary")
    notes: str | None = None


class CRMOpportunity(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    id: str | None = None
    pipeline: str | None = None
    stage: str | None = None
    next_action: str | None = Field(default=None, alias="nextAction")
    amount: float | None = None


class CRMInteractionFlags(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    already_contacted: bool = Field(default=False, alias="alreadyContacted")
    asked_for_price: bool = Field(default=False, alias="askedForPrice")
    asked_for_demo: bool = Field(default=False, alias="askedForDemo")
    needs_human: bool = Field(default=False, alias="needsHuman")


class CRMContactContext(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    contact: CRMContact
    lead: CRMLead | None = None
    opportunity: CRMOpportunity | None = None
    flags: CRMInteractionFlags = Field(default_factory=CRMInteractionFlags)
    recent_notes: list[str] = Field(default_factory=list, alias="recentNotes")
    last_activity_at: str | None = Field(default=None, alias="lastActivityAt")
    summary: str | None = None

    def has_active_opportunity(self) -> bool:
        return self.opportunity is not None and self.opportunity.stage not in {None, "", "closed_lost", "closed_won"}


class CRMUpdatePayload(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    phone: str
    tenant_id: str = Field(alias="tenantId")
    intent: str
    score: float
    action: str
    needs_human: bool = Field(alias="needsHuman")
    summary: str
    reply: str
    data_to_save: dict[str, Any] = Field(default_factory=dict, alias="dataToSave")


class CRMClient:
    def __init__(self, settings: Settings, transport: httpx.AsyncBaseTransport | None = None) -> None:
        self.settings = settings
        self.transport = transport

    async def fetch_contact_context(self, phone: str) -> CRMContactContext | None:
        base_url = self.settings.crm_base_url.strip().rstrip("/")
        if base_url == "":
            return None

        timeout = httpx.Timeout(5.0, connect=2.0)
        params = {"phone": phone.strip()}
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.get("/api/agent/contact-context", params=params)
                if response.status_code == httpx.codes.NOT_FOUND:
                    return None

                response.raise_for_status()
                payload = response.json()
        except (httpx.HTTPError, ValueError):
            return None

        if not isinstance(payload, dict):
            return None

        return self._parse_context(payload)

    def build_update_payload(
        self,
        phone: str,
        tenant_id: str,
        intent: str,
        score: float,
        action: str,
        needs_human: bool,
        summary: str,
        reply: str,
        data_to_save: dict[str, Any] | None = None,
    ) -> CRMUpdatePayload:
        return CRMUpdatePayload(
            phone=phone,
            tenant_id=tenant_id,
            intent=intent,
            score=score,
            action=action,
            needs_human=needs_human,
            summary=summary,
            reply=reply,
            data_to_save=data_to_save or {},
        )

    def _parse_context(self, payload: dict[str, Any]) -> CRMContactContext:
        contact_payload = payload.get("contact") if isinstance(payload.get("contact"), dict) else {}
        lead_payload = payload.get("lead") if isinstance(payload.get("lead"), dict) else None
        opportunity_payload = payload.get("opportunity") if isinstance(payload.get("opportunity"), dict) else None
        flags_payload = payload.get("flags") if isinstance(payload.get("flags"), dict) else {}
        recent_notes = payload.get("recentNotes")

        if not isinstance(contact_payload, dict) or "phone" not in contact_payload:
            raise ValueError("CRM contact context must include a contact.phone field")

        return CRMContactContext(
            contact=CRMContact.model_validate(contact_payload or {}),
            lead=CRMLead.model_validate(lead_payload) if lead_payload is not None else None,
            opportunity=CRMOpportunity.model_validate(opportunity_payload) if opportunity_payload is not None else None,
            flags=CRMInteractionFlags.model_validate(flags_payload or {}),
            recent_notes=recent_notes if isinstance(recent_notes, list) else [],
            last_activity_at=payload.get("lastActivityAt"),
            summary=payload.get("summary"),
        )
