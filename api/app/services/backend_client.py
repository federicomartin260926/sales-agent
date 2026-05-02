from __future__ import annotations

import hashlib
import logging
from typing import Any

import httpx
from pydantic import BaseModel, ConfigDict, Field

from app.config import Settings


logger = logging.getLogger(__name__)


class BackendTenant(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str
    name: str
    slug: str
    business_context: str = Field(alias="businessContext")
    tone: str | None = None
    sales_policy: dict[str, Any] = Field(default_factory=dict, alias="salesPolicy")
    is_active: bool = Field(alias="isActive")
    created_at: str = Field(alias="createdAt")


class BackendProduct(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str
    tenant_id: str = Field(alias="tenantId")
    name: str
    slug: str | None = None
    external_source: str | None = Field(default=None, alias="externalSource")
    external_reference: str | None = Field(default=None, alias="externalReference")
    description: str = ""
    value_proposition: str = Field(default="", alias="valueProposition")
    base_price_cents: int | None = Field(default=None, alias="basePriceCents")
    currency: str | None = None
    sales_policy: dict[str, Any] = Field(default_factory=dict, alias="salesPolicy")
    is_active: bool = Field(alias="isActive")


class BackendPlaybook(BaseModel):
    model_config = ConfigDict(extra="ignore")

    id: str
    tenant_id: str = Field(alias="tenantId")
    product_id: str | None = Field(default=None, alias="productId")
    name: str
    config: dict[str, Any] = Field(default_factory=dict)
    is_active: bool = Field(alias="isActive")


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


class CommercialContext(BaseModel):
    model_config = ConfigDict(extra="ignore")

    tenant: BackendTenant
    products: list[BackendProduct] = Field(default_factory=list)
    playbooks: list[BackendPlaybook] = Field(default_factory=list)
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
    ) -> CommercialContext | None:
        base_url = self.settings.backend_base_url.strip().rstrip("/")
        if base_url == "":
            return None

        timeout = httpx.Timeout(5.0, connect=2.0)
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                tenant = await self._get_json(client, f"/api/tenants/{tenant_id}")
                if tenant is None or not tenant.get("isActive", True):
                    return None

                products = await self._get_json(client, "/api/products") or []
                playbooks = await self._get_json(client, "/api/playbooks") or []
        except httpx.HTTPError:
            return None

        tenant_model = BackendTenant.model_validate(tenant)
        product_models = self._filter_active_products(products, tenant_id)
        playbook_models = self._filter_active_playbooks(playbooks, tenant_id)

        selected_product, selected_product_is_fallback = self._select_product(product_models, selected_product_id)
        selected_playbook, selected_playbook_is_fallback = self._select_playbook(playbook_models, selected_product, selected_playbook_id)

        return CommercialContext(
            tenant=tenant_model,
            products=product_models,
            playbooks=playbook_models,
            selected_product=selected_product,
            selected_playbook=selected_playbook,
            selected_product_is_fallback=selected_product_is_fallback,
            selected_playbook_is_fallback=selected_playbook_is_fallback,
        )

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
