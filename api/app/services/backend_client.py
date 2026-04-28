from __future__ import annotations

from typing import Any

import httpx
from pydantic import BaseModel, ConfigDict, Field

from app.config import Settings


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
    description: str = ""
    value_proposition: str = Field(default="", alias="valueProposition")
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


class CommercialContext(BaseModel):
    model_config = ConfigDict(extra="ignore")

    tenant: BackendTenant
    products: list[BackendProduct] = Field(default_factory=list)
    playbooks: list[BackendPlaybook] = Field(default_factory=list)
    selected_product: BackendProduct | None = None
    selected_playbook: BackendPlaybook | None = None

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

    async def fetch_tenant_context(self, tenant_id: str) -> CommercialContext | None:
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

        selected_product = self._select_product(product_models)
        selected_playbook = self._select_playbook(playbook_models, selected_product)

        return CommercialContext(
            tenant=tenant_model,
            products=product_models,
            playbooks=playbook_models,
            selected_product=selected_product,
            selected_playbook=selected_playbook,
        )

    async def _get_json(self, client: httpx.AsyncClient, path: str) -> Any:
        response = await client.get(path)
        if response.status_code == httpx.codes.NOT_FOUND:
            return None

        response.raise_for_status()
        return response.json()

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

    def _select_product(self, products: list[BackendProduct]) -> BackendProduct | None:
        return products[0] if products else None

    def _select_playbook(
        self,
        playbooks: list[BackendPlaybook],
        selected_product: BackendProduct | None,
    ) -> BackendPlaybook | None:
        if not playbooks:
            return None

        if selected_product is not None:
            for playbook in playbooks:
                if playbook.product_id == selected_product.id:
                    return playbook

        for playbook in playbooks:
            if playbook.product_id is None:
                return playbook

        return playbooks[0]
