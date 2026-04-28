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
- `name`
- `description`
- `valueProposition`
- `salesPolicy`
- `isActive`

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

