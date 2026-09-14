import json

from app.schemas.llm import McpRemoteConfig
from app.services.llm_client import LLMClient


def client_without_init() -> LLMClient:
    return object.__new__(LLMClient)


def contact_context_payload(timezone: str) -> dict:
    return {
        "output": [
            {
                "type": "mcp_call",
                "name": "contact_context",
                "status": "completed",
                "arguments": "{}",
                "output": json.dumps(
                    {
                        "contact_context": {
                            "timezone": timezone,
                            "timezone_source": "crm_tenant",
                        }
                    }
                ),
            }
        ]
    }


def test_contact_context_refreshes_effective_timezone():
    client = client_without_init()
    config = McpRemoteConfig(
        config={
            "effective_timezone": "Europe/Madrid",
            "effective_timezone_source": "settings.default_business_timezone",
        }
    )

    client._refresh_effective_timezone_from_contact_context(
        contact_context_payload("Atlantic/Canary"),
        config,
    )

    assert config.config["effective_timezone"] == "Atlantic/Canary"
    assert config.config["effective_timezone_source"] == "crm_tenant"


def test_invalid_contact_context_timezone_does_not_replace_effective_timezone():
    client = client_without_init()
    config = McpRemoteConfig(
        config={
            "effective_timezone": "Europe/Madrid",
            "effective_timezone_source": "settings.default_business_timezone",
        }
    )

    client._refresh_effective_timezone_from_contact_context(
        contact_context_payload("Invalid/Timezone"),
        config,
    )

    assert config.config["effective_timezone"] == "Europe/Madrid"
    assert (
        config.config["effective_timezone_source"]
        == "settings.default_business_timezone"
    )
