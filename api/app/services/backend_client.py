from __future__ import annotations

import hashlib
import logging
from typing import Any

import httpx
from pydantic import AliasChoices, BaseModel, ConfigDict, Field, field_validator

from app.config import Settings


logger = logging.getLogger(__name__)


class BackendTenant(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str
    name: str
    slug: str
    business_context: str = Field(validation_alias=AliasChoices("businessContext", "business_context"))
    tone: str | None = None
    sales_policy: dict[str, Any] = Field(default_factory=dict, validation_alias=AliasChoices("salesPolicy", "sales_policy"))
    is_active: bool = Field(validation_alias=AliasChoices("isActive", "is_active"))
    whatsapp_phone_number_id: str | None = Field(default=None, validation_alias=AliasChoices("whatsappPhoneNumberId", "whatsapp_phone_number_id"))
    whatsapp_public_phone: str | None = Field(default=None, validation_alias=AliasChoices("whatsappPublicPhone", "whatsapp_public_phone"))
    created_at: str | None = Field(default=None, validation_alias=AliasChoices("createdAt", "created_at"))

    @field_validator("sales_policy", mode="before")
    @classmethod
    def normalize_sales_policy(cls, value: Any) -> dict[str, Any]:
        if isinstance(value, dict):
            return value

        if isinstance(value, list):
            return {}

        if value is None:
            return {}

        return {}


class BackendProduct(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str
    tenant_id: str = Field(validation_alias=AliasChoices("tenantId", "tenant_id"))
    name: str
    slug: str | None = None
    external_source: str | None = Field(default=None, validation_alias=AliasChoices("externalSource", "external_source"))
    external_reference: str | None = Field(default=None, validation_alias=AliasChoices("externalReference", "external_reference"))
    description: str = ""
    value_proposition: str = Field(default="", validation_alias=AliasChoices("valueProposition", "value_proposition"))
    base_price_cents: int | None = Field(default=None, validation_alias=AliasChoices("basePriceCents", "base_price_cents"))
    currency: str | None = None
    sales_policy: dict[str, Any] = Field(default_factory=dict, validation_alias=AliasChoices("salesPolicy", "sales_policy"))
    is_active: bool = Field(validation_alias=AliasChoices("isActive", "is_active"))

    @field_validator("sales_policy", mode="before")
    @classmethod
    def normalize_sales_policy(cls, value: Any) -> dict[str, Any]:
        if isinstance(value, dict):
            return value

        if isinstance(value, list):
            return {}

        if value is None:
            return {}

        return {}


class BackendPlaybook(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str
    tenant_id: str = Field(validation_alias=AliasChoices("tenantId", "tenant_id"))
    product_id: str | None = Field(default=None, validation_alias=AliasChoices("productId", "product_id"))
    name: str
    config: dict[str, Any] = Field(default_factory=dict)
    is_active: bool = Field(validation_alias=AliasChoices("isActive", "is_active"))

    @field_validator("config", mode="before")
    @classmethod
    def normalize_config(cls, value: Any) -> dict[str, Any]:
        if isinstance(value, dict):
            return value

        if isinstance(value, list):
            return {}

        if value is None:
            return {}

        return {}


class BackendEntryPoint(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str
    code: str
    name: str
    description: str | None = None
    initial_message: str | None = None
    crm_branch_ref: str | None = None
    is_active: bool = Field(validation_alias=AliasChoices("is_active", "isActive"))


class BackendSalesRuntime(BaseModel):
    model_config = ConfigDict(extra="ignore")

    has_product_context: bool = False
    has_playbook_context: bool = False
    has_entry_point_context: bool = False
    handoff_enabled: bool = False
    booking_enabled: bool = False
    rag_enabled: bool = False


class BackendRoutingEntryPointUtmContext(BaseModel):
    model_config = ConfigDict(extra="ignore")

    entry_point_utm_id: str
    ref: str
    entry_point_id: str
    entry_point_code: str
    tenant_id: str
    tenant_slug: str
    product_id: str
    product_name: str
    playbook_id: str | None = None
    crm_branch_ref: str | None = None
    utm_source: str | None = None
    utm_medium: str | None = None
    utm_campaign: str | None = None
    utm_term: str | None = None
    utm_content: str | None = None
    gclid: str | None = None
    fbclid: str | None = None
    status: str | None = None


class BackendConversationUpsertPayload(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    tenant_id: str = Field(alias="tenant_id")
    product_id: str | None = Field(default=None, alias="product_id")
    entry_point_id: str | None = Field(default=None, alias="entry_point_id")
    entry_point_utm_id: str | None = Field(default=None, alias="entry_point_utm_id")
    customer_phone: str = Field(alias="customer_phone")
    customer_name: str | None = Field(default=None, alias="customer_name")
    first_message: str | None = Field(default=None, alias="first_message")
    external_conversation_id: str | None = Field(default=None, alias="external_conversation_id")
    utm_source: str | None = Field(default=None, alias="utm_source")
    utm_medium: str | None = Field(default=None, alias="utm_medium")
    utm_campaign: str | None = Field(default=None, alias="utm_campaign")
    utm_term: str | None = Field(default=None, alias="utm_term")
    utm_content: str | None = Field(default=None, alias="utm_content")
    gclid: str | None = Field(default=None, alias="gclid")
    fbclid: str | None = Field(default=None, alias="fbclid")
    crm_branch_ref: str | None = Field(default=None, alias="crm_branch_ref")


class BackendConversationMessagePayload(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    conversation_id: str = Field(alias="conversation_id")
    direction: str = Field(alias="direction")
    role: str = Field(alias="role")
    message_type: str = Field(alias="message_type")
    body: str = Field(alias="body")
    external_message_id: str | None = Field(default=None, alias="external_message_id")
    external_timestamp: str | None = Field(default=None, alias="external_timestamp")
    provider: str | None = Field(default=None, alias="provider")
    model: str | None = Field(default=None, alias="model")
    latency_ms: int | None = Field(default=None, alias="latency_ms")
    intent: str | None = Field(default=None, alias="intent")
    score: int | None = Field(default=None, alias="score")
    action: str | None = Field(default=None, alias="action")
    needs_human: bool = Field(default=False, alias="needs_human")
    error_code: str | None = Field(default=None, alias="error_code")
    error_message: str | None = Field(default=None, alias="error_message")
    raw_payload: Any | None = Field(default=None, alias="raw_payload")
    metadata: dict[str, Any] = Field(default_factory=dict, alias="metadata")


class BackendConversationMessage(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str
    conversation_id: str = Field(validation_alias=AliasChoices("conversationId", "conversation_id"))
    direction: str
    role: str | None = None
    message_type: str | None = Field(default=None, validation_alias=AliasChoices("messageType", "message_type"))
    body: str
    external_message_id: str | None = Field(default=None, validation_alias=AliasChoices("externalMessageId", "external_message_id"))
    external_timestamp: str | None = Field(default=None, validation_alias=AliasChoices("externalTimestamp", "external_timestamp"))
    provider: str | None = None
    model: str | None = None
    latency_ms: int | None = Field(default=None, validation_alias=AliasChoices("latencyMs", "latency_ms"))
    intent: str | None = None
    score: int | None = None
    action: str | None = None
    needs_human: bool = Field(default=False, validation_alias=AliasChoices("needsHuman", "needs_human"))
    error_code: str | None = Field(default=None, validation_alias=AliasChoices("errorCode", "error_code"))
    error_message: str | None = Field(default=None, validation_alias=AliasChoices("errorMessage", "error_message"))
    raw_payload: Any | None = Field(default=None, validation_alias=AliasChoices("rawPayload", "raw_payload"))
    metadata: dict[str, Any] | None = None
    created_at: str | None = Field(default=None, validation_alias=AliasChoices("createdAt", "created_at"))


class BackendConversationMessageResult(BaseModel):
    model_config = ConfigDict(extra="ignore")

    created: bool
    duplicate: bool
    message: BackendConversationMessage


class CommercialContext(BaseModel):
    model_config = ConfigDict(extra="ignore")

    tenant: BackendTenant
    products: list[BackendProduct] = Field(default_factory=list)
    playbooks: list[BackendPlaybook] = Field(default_factory=list)
    entry_point: BackendEntryPoint | None = None
    sales_runtime: BackendSalesRuntime = Field(default_factory=BackendSalesRuntime)
    selected_product: BackendProduct | None = None
    selected_playbook: BackendPlaybook | None = None
    selected_product_is_fallback: bool = False
    selected_playbook_is_fallback: bool = False

    def context_summary(self) -> str:
        parts = [self.tenant.name]
        if self.selected_product is not None:
            parts.append(self.selected_product.name)
        if self.selected_playbook is not None:
            parts.append(self.selected_playbook.name)
        if self.entry_point is not None:
            parts.append(self.entry_point.name)

        return " · ".join(parts)


class BackendClient:
    def __init__(self, settings: Settings, transport: httpx.AsyncBaseTransport | None = None) -> None:
        self.settings = settings
        self.transport = transport

    def _auth_headers(self) -> dict[str, str]:
        bearer_token = self.settings.sales_agent_bearer_token.strip()
        if bearer_token == "":
            logger.debug("Using internal backend bearer token (present=%s length=%d sha256_prefix=%s)", False, 0, "")
            return {"Accept": "application/json"}

        token_digest = hashlib.sha256(bearer_token.encode("utf-8")).hexdigest()
        logger.debug(
            "Using internal backend bearer token (present=%s length=%d sha256_prefix=%s)",
            True,
            len(bearer_token),
            token_digest[:8],
        )

        return {
            "Authorization": f"Bearer {bearer_token}",
            "Accept": "application/json",
        }

    async def fetch_tenant_context(
        self,
        tenant_id: str,
        selected_product_id: str | None = None,
        selected_playbook_id: str | None = None,
        entry_point_id: str | None = None,
        entrypoint_ref: str | None = None,
        customer_phone: str | None = None,
        external_channel_id: str | None = None,
    ) -> CommercialContext | None:
        payload = await self.get_commercial_context(
            tenant_id=tenant_id,
            product_id=selected_product_id,
            playbook_id=selected_playbook_id,
            entry_point_id=entry_point_id,
            entrypoint_ref=entrypoint_ref,
            customer_phone=customer_phone,
            external_channel_id=external_channel_id,
        )
        if payload is None:
            return None

        tenant_payload = payload.get("tenant")
        if not isinstance(tenant_payload, dict):
            return None

        tenant_model = BackendTenant.model_validate(tenant_payload)

        product_payload = payload.get("product") if isinstance(payload.get("product"), dict) else None
        playbook_payload = payload.get("playbook") if isinstance(payload.get("playbook"), dict) else None
        entry_point_payload = payload.get("entry_point") if isinstance(payload.get("entry_point"), dict) else None
        sales_runtime_payload = payload.get("sales_runtime") if isinstance(payload.get("sales_runtime"), dict) else None

        selected_product = BackendProduct.model_validate(product_payload) if product_payload is not None else None
        selected_playbook = BackendPlaybook.model_validate(playbook_payload) if playbook_payload is not None else None
        entry_point = BackendEntryPoint.model_validate(entry_point_payload) if entry_point_payload is not None else None
        sales_runtime = BackendSalesRuntime.model_validate(sales_runtime_payload) if sales_runtime_payload is not None else BackendSalesRuntime()

        products = [selected_product] if selected_product is not None else []
        playbooks = [selected_playbook] if selected_playbook is not None else []

        return CommercialContext(
            tenant=tenant_model,
            products=products,
            playbooks=playbooks,
            entry_point=entry_point,
            sales_runtime=sales_runtime,
            selected_product=selected_product,
            selected_playbook=selected_playbook,
            selected_product_is_fallback=selected_product is not None and (selected_product_id is None or selected_product_id.strip() == ""),
            selected_playbook_is_fallback=selected_playbook is not None and (selected_playbook_id is None or selected_playbook_id.strip() == ""),
        )

    async def get_commercial_context(
        self,
        tenant_id: str,
        product_id: str | None = None,
        playbook_id: str | None = None,
        entry_point_id: str | None = None,
        entrypoint_ref: str | None = None,
        customer_phone: str | None = None,
        external_channel_id: str | None = None,
    ) -> dict[str, Any] | None:
        base_url = self.settings.backend_base_url.strip().rstrip("/")
        if base_url == "" or tenant_id.strip() == "":
            return None

        timeout = httpx.Timeout(5.0, connect=2.0)
        params: dict[str, str] = {"tenant_id": tenant_id.strip()}
        if product_id is not None and product_id.strip() != "":
            params["product_id"] = product_id.strip()
        if playbook_id is not None and playbook_id.strip() != "":
            params["playbook_id"] = playbook_id.strip()
        if entry_point_id is not None and entry_point_id.strip() != "":
            params["entry_point_id"] = entry_point_id.strip()
        if entrypoint_ref is not None and entrypoint_ref.strip() != "":
            params["entrypoint_ref"] = entrypoint_ref.strip()
        if customer_phone is not None and customer_phone.strip() != "":
            params["customer_phone"] = customer_phone.strip()
        if external_channel_id is not None and external_channel_id.strip() != "":
            params["external_channel_id"] = external_channel_id.strip()

        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.get("/api/internal/commercial-context", params=params, headers=self._auth_headers())
                if response.status_code == httpx.codes.NOT_FOUND:
                    logger.warning(
                        "Backend commercial context lookup returned 404 for %s %s body=%s",
                        response.request.method,
                        response.request.url,
                        self._response_snippet(response),
                    )
                    return None
                if response.status_code >= 400:
                    logger.warning(
                        "Backend commercial context lookup failed for %s %s with status %s body=%s",
                        response.request.method,
                        response.request.url,
                        response.status_code,
                        self._response_snippet(response),
                    )
                    response.raise_for_status()

                payload = response.json()
        except (httpx.HTTPError, ValueError):
            return None

        return payload if isinstance(payload, dict) else None

    async def resolve_whatsapp_phone(self, phone_number_id: str) -> dict[str, Any] | None:
        base_url = self.settings.backend_base_url.strip().rstrip("/")
        if base_url == "":
            return None

        timeout = httpx.Timeout(5.0, connect=2.0)
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                path = f"/api/internal/routing/whatsapp-phone/{phone_number_id.strip()}"
                response = await client.get(path, headers=self._auth_headers())
                if response.status_code == httpx.codes.NOT_FOUND:
                    logger.warning(
                        "Backend routing lookup returned 404 for %s %s body=%s",
                        response.request.method,
                        response.request.url,
                        self._response_snippet(response),
                    )
                    return None
                if response.status_code >= 400:
                    logger.warning(
                        "Backend routing lookup failed for %s %s with status %s body=%s",
                        response.request.method,
                        response.request.url,
                        response.status_code,
                        self._response_snippet(response),
                    )
                    response.raise_for_status()

                payload = response.json()
        except (httpx.HTTPError, ValueError):
            return None

        if not isinstance(payload, dict):
            return None

        return payload

    async def resolve_entrypoint_ref(self, ref: str) -> BackendRoutingEntryPointUtmContext | None:
        base_url = self.settings.backend_base_url.strip().rstrip("/")
        if base_url == "":
            return None

        timeout = httpx.Timeout(5.0, connect=2.0)
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                path = f"/api/internal/routing/entrypoint-ref/{ref.strip()}"
                response = await client.get(path, headers=self._auth_headers())
                if response.status_code == httpx.codes.NOT_FOUND:
                    logger.warning(
                        "Backend routing lookup returned 404 for %s %s body=%s",
                        response.request.method,
                        response.request.url,
                        self._response_snippet(response),
                    )
                    return None
                if response.status_code >= 400:
                    logger.warning(
                        "Backend routing lookup failed for %s %s with status %s body=%s",
                        response.request.method,
                        response.request.url,
                        response.status_code,
                        self._response_snippet(response),
                    )
                    response.raise_for_status()

                payload = response.json()
        except (httpx.HTTPError, ValueError):
            return None

        if not isinstance(payload, dict):
            return None

        return BackendRoutingEntryPointUtmContext.model_validate(payload)

    async def upsert_conversation(self, payload: BackendConversationUpsertPayload) -> dict[str, Any] | None:
        base_url = self.settings.backend_base_url.strip().rstrip("/")
        if base_url == "":
            return None

        timeout = httpx.Timeout(5.0, connect=2.0)
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.post(
                    "/api/internal/conversations/upsert",
                    json=payload.model_dump(by_alias=True),
                    headers=self._auth_headers(),
                )
                response.raise_for_status()
                payload_data = response.json()
        except (httpx.HTTPError, ValueError):
            return None

        return payload_data if isinstance(payload_data, dict) else None

    async def create_conversation_message(self, payload: BackendConversationMessagePayload) -> BackendConversationMessageResult | None:
        base_url = self.settings.backend_base_url.strip().rstrip("/")
        if base_url == "":
            return None

        timeout = httpx.Timeout(5.0, connect=2.0)
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.post(
                    "/api/internal/conversations/messages",
                    json=payload.model_dump(by_alias=True),
                    headers=self._auth_headers(),
                )
                response.raise_for_status()
                payload_data = response.json()
        except (httpx.HTTPError, ValueError):
            return None

        if not isinstance(payload_data, dict):
            return None

        return BackendConversationMessageResult.model_validate(payload_data)

    async def _get_json(self, client: httpx.AsyncClient, path: str) -> Any:
        response = await client.get(path)
        if response.status_code == httpx.codes.NOT_FOUND:
            return None

        response.raise_for_status()
        return response.json()

    def _response_snippet(self, response: httpx.Response) -> str:
        try:
            return response.text[:200].replace("\n", " ").replace("\r", " ")
        except Exception:
            return "<unavailable>"

    def _filter_active_products(self, payload: Any, tenant_id: str) -> list[BackendProduct]:
        if not isinstance(payload, list):
            return []

        models = []
        for item in payload:
            if not isinstance(item, dict):
                continue

            if item.get("tenantId") != tenant_id or not item.get("isActive", True):
                continue

            models.append(BackendProduct.model_validate(item))

        return models

    def _filter_active_playbooks(self, payload: Any, tenant_id: str) -> list[BackendPlaybook]:
        if not isinstance(payload, list):
            return []

        models = []
        for item in payload:
            if not isinstance(item, dict):
                continue

            if item.get("tenantId") != tenant_id or not item.get("isActive", True):
                continue

            models.append(BackendPlaybook.model_validate(item))

        return models

    def _select_product(
        self,
        products: list[BackendProduct],
        selected_product_id: str | None,
    ) -> tuple[BackendProduct | None, bool]:
        if selected_product_id is not None:
            for product in products:
                if product.id == selected_product_id:
                    return product, False

            return None, False

        if len(products) == 1:
            return products[0], True

        return None, False

    def _select_playbook(
        self,
        playbooks: list[BackendPlaybook],
        selected_product: BackendProduct | None,
        selected_playbook_id: str | None,
    ) -> tuple[BackendPlaybook | None, bool]:
        if selected_playbook_id is not None:
            for playbook in playbooks:
                if playbook.id == selected_playbook_id:
                    return playbook, False

            return None, False

        if not playbooks:
            return None, False

        if selected_product is not None:
            for playbook in playbooks:
                if playbook.product_id == selected_product.id:
                    return playbook, False

        return None, False
