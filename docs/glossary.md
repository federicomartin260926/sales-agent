# Glosario oficial

Este glosario fija la terminología visible para usuarios no técnicos en `sales-agent`.

## Negocio

Nombre visible para lo que técnicamente se modela como `tenant`.

Un negocio representa una unidad comercial aislada con su propio contexto, tono, política comercial y reglas de derivación.

Uso recomendado:

- "Crear negocio"
- "Contexto del negocio"
- "Negocio activo"

No usar como término visible:

- `tenant`
- `proyecto`
- `cliente`, salvo cuando se hable de la relación comercial externa

## Guía comercial

Nombre visible para lo que técnicamente se modela como `playbook`.

Una guía comercial define cómo debe comportarse el agente en un negocio concreto o en un producto concreto:

- preguntas de cualificación
- scoring
- señales positivas y negativas
- reglas de agenda
- reglas de handoff
- acciones permitidas

Uso recomendado:

- "Crear guía comercial"
- "Reglas de la guía comercial"
- "Guía comercial activa"

No usar como término visible:

- `playbook`
- `configuración`, salvo en contexto técnico

## Producto / servicio

Nombre visible para lo que técnicamente se modela como `product`.

Representa la oferta que se vende dentro de un negocio.

Uso recomendado:

- "Crear producto"
- "Crear servicio"
- "Producto o servicio"

## Entry point

Nombre visible para lo que técnicamente se modela como `EntryPoint`.

Representa un enlace, botón, anuncio o QR que introduce contexto comercial y atribución.

## Reglas de redacción

- Usar `negocio` cuando se hable de aislamiento comercial, contexto o configuración por cliente.
- Usar `guía comercial` cuando se hable de cualificación, scoring, handoff y agenda.
- Usar `producto / servicio` cuando se hable de la oferta comercial.
- Reservar `tenant` y `playbook` para documentación técnica, código, API interna y persistencia.
