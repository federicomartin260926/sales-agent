# Modelo de dominio

Este documento fija la forma final del modelo comercial que usa `sales-agent`.

## Negocio

Visible para usuario como `negocio`.
Técnicamente se modela como `Tenant`.

Campos:

- `id` UUID
- `name`
- `slug`
- `businessContext`
- `tone`
- `whatsappPhoneNumberId` opcional
- `whatsappPublicPhone` opcional
- `salesPolicy`
- `isActive`
- `createdAt`

`salesPolicy` es un objeto estructurado con esta forma:

- `positioning` string
- `qualificationFocus` string
- `handoffRules` string
- `salesBoundaries` array de strings opcional
- `notes` string opcional

## Producto / servicio

Visible para usuario como `producto / servicio`.
Técnicamente se modela como `Product`.

Campos:

- `id` UUID
- `tenant`
- `slug` local y legible
- `externalSource` opcional
- `externalReference` opcional
- `name`
- `description`
- `valueProposition`
- `basePriceCents` opcional
- `currency` opcional
- `salesPolicy`
- `isActive`

Reglas:

- `slug` actúa como identificador local y fallback
- `externalSource = crm` y `externalReference = integration_key` cuando el producto viene importado desde CRM
- el UUID interno del CRM no forma parte del contrato de integración
- el producto puede funcionar standalone sin referencia externa

`salesPolicy` es un objeto estructurado con esta forma:

- `positioning` string
- `pricingNotes` string opcional
- `objections` array de strings opcional
- `handoffRules` string opcional
- `notes` string opcional

## Guía comercial

Visible para usuario como `guía comercial`.
Técnicamente se modela como `Playbook`.

Campos:

- `id` UUID
- `tenant`
- `product` opcional
- `name`
- `config`
- `isActive`

`config` es un objeto estructurado con esta forma:

- `objective` string
- `qualificationQuestions` array de strings no vacío
- `scoring` objeto
- `agendaRules` array de strings opcional
- `handoffRules` array de strings no vacío
- `allowedActions` array de strings no vacío
- `notes` string opcional

## Entry point

Visible como punto de entrada comercial.
Técnicamente se modela como `EntryPoint`.

Campos:

- `id` UUID
- `product`
- `playbook` opcional
- `code`
- `name`
- `crmBranchRef` opcional
- `defaultMessage` opcional
- `isActive`
- `createdAt`
- `updatedAt` opcional

Reglas:

- `code` es único, estable y URL-safe
- `EntryPoint` no guarda UTMs
- `EntryPoint` obtiene el tenant a través de `Product.tenant`
- `EntryPoint.product` es la referencia comercial principal del punto de entrada

## Entry point UTM

Visible como atribución técnica por click.
Técnicamente se modela como `EntryPointUtm`.

Campos:

- `id` UUID
- `entryPoint`
- `ref`
- `utmSource` opcional
- `utmMedium` opcional
- `utmCampaign` opcional
- `utmTerm` opcional
- `utmContent` opcional
- `gclid` opcional
- `fbclid` opcional
- `status`
- `createdAt`
- `matchedAt` opcional
- `expiresAt` opcional

Reglas:

- se crea una fila por click en el redirect público
- `ref` es corto, único y URL-safe
- `status` puede ser `pending`, `matched` o `expired`
- `EntryPointUtm` no es analítica principal, solo puente técnico entre click y conversación

## Conversation

Técnicamente se modela como `Conversation`.

Campos:

- `id` UUID
- `tenant`
- `product` opcional
- `entryPoint` opcional
- `entryPointUtm` opcional
- `externalConversationId` opcional
- `customerPhone`
- `customerName` opcional
- `status`
- `firstMessage` opcional
- `lastMessageAt` opcional
- `createdAt`
- `updatedAt` opcional
- `utmSource` opcional
- `utmMedium` opcional
- `utmCampaign` opcional
- `utmTerm` opcional
- `utmContent` opcional
- `gclid` opcional
- `fbclid` opcional
- `crmBranchRef` opcional

Reglas:

- se reutiliza la conversación activa por `tenant + customerPhone`
- la primera atribución se conserva en `entryPointUtm`
- la conversación copia las UTMs para quedar autosuficiente

## Conversation message

Técnicamente se modela como `ConversationMessage`.

Campos:

- `id` UUID
- `conversation`
- `direction`
- `body`
- `rawPayload` opcional
- `createdAt`

### `scoring`

`scoring` se usa para decidir si el lead avanza, se sigue cualificando o se deriva a humano.

Campos:

- `maxScore` entero mayor o igual que 1
- `handoffThreshold` entero no negativo
- `positiveSignals` array de strings opcional
- `negativeSignals` array de strings opcional

Regla:

- `handoffThreshold` no puede ser mayor que `maxScore`

## Criterio de cierre

El modelo se considera cerrado cuando:

- los nombres visibles son consistentes en UI y documentación
- las estructuras JSON aceptan solo las claves definidas arriba
- las APIs rechazan formas inválidas
- los playbooks iniciales y tests usan esta misma forma

Nota:

- el modelo canónico de routing y atribución es `EntryPoint -> EntryPointUtm -> Conversation`
