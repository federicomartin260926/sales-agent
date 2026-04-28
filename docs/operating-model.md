# Guía funcional del sistema

Este documento describe el comportamiento esperado de `sales-agent` como guía de trabajo. No es solo una descripción de lo que existe hoy, sino del flujo funcional que el proyecto debe conservar mientras evoluciona.

Terminología visible:

- `negocio` para `tenant`
- `guía comercial` para `playbook`
- `producto / servicio` para `product`

Consulta el [glosario oficial](glossary.md) para el uso exacto de cada término y el [modelo de dominio](domain-model.md) para la forma estructurada de cada campo.

## Objetivo

`sales-agent` actúa como runtime conversacional comercial multi-tenant.

Su función principal es:

- recibir mensajes normalizados desde `wa-gateway-api`
- identificar el tenant correcto
- cargar contexto de negocio, producto y playbook
- consultar CRM, RAG y LLM cuando el caso lo requiera
- devolver una respuesta estructurada y una acción recomendada

El sistema no debe enviar WhatsApp directamente en el diseño ideal. La ejecución física del mensaje pertenece a `wa-gateway-api`.

## Responsabilidades por servicio

### `sales-agent/api`

Responsable de la decisión conversacional.

Debe:

- exponer `POST /agent/respond`
- validar autenticación interna con bearer token
- normalizar payloads procedentes de WhatsApp o del gateway
- cargar contexto de tenant, producto y playbook
- producir una respuesta estructurada

### `sales-agent/backend`

Responsable de la administración y configuración del dominio comercial.

Debe:

- gestionar usuarios y roles internos
- administrar tenants
- administrar productos
- administrar playbooks
- preparar datos iniciales y mantenimiento operativo

### `wa-gateway-api`

Responsable del transporte físico de mensajes.

Debe:

- recibir eventos de WhatsApp
- normalizar el payload
- llamar a `sales-agent/api`
- ejecutar el envío final del mensaje de salida

### CRM

El CRM sigue siendo el sistema maestro de:

- leads
- clientes
- agenda
- pipeline

`sales-agent` puede leer contexto del CRM, pero no debe convertirse en la fuente principal de verdad para esos datos.

## Modelo funcional

### User

Usuario interno de operación del sistema.

Campos esperados:

- `id` UUID
- `email`
- `password`
- `roles`
- `isActive`
- `createdAt`

Roles funcionales:

- `agent`
- `manager`
- `admin`

### Negocio

Cada negocio representa un cliente o unidad comercial aislada.

Debe contener:

- contexto global del negocio
- tono de comunicación
- política general de ventas
- reglas de derivación a humano
- configuración futura de CRM, RAG y LLM

Campos esperados:

- `id` UUID
- `name`
- `slug`
- `businessContext`
- `tone`
- `salesPolicy`
- `isActive`
- `createdAt`

### Product

Cada producto representa una oferta concreta dentro de un tenant.

Debe contener:

- definición del producto o servicio
- propuesta de valor
- notas de pricing
- política específica de venta
- FAQs y objeciones futuras

Relación:

- `Tenant 1:N Product`

Campos esperados:

- `id` UUID
- `tenant`
- `name`
- `description`
- `valueProposition`
- `salesPolicy`
- `isActive`

### Guía comercial

Cada guía comercial define la estrategia conversacional para un negocio y, opcionalmente, para un producto.

Debe contener:

- preguntas de cualificación
- scoring
- señales positivas y negativas
- reglas de agenda
- reglas de handoff
- acciones permitidas

Relación:

- `Tenant 1:N Playbook`
- `Product` opcional en `Playbook`

Campos esperados:

- `id` UUID
- `tenant`
- `product` opcional
- `name`
- `config` JSON
- `isActive`

## Flujo esperado de conversación

### 1. Entrada

`wa-gateway-api` envía un mensaje normalizado a `sales-agent/api`.

Ejemplo conceptual:

```json
{
  "tenant_id": "...",
  "message": "Hola, quiero automatizar WhatsApp",
  "contact": {
    "phone": "...",
    "name": null
  },
  "conversation": {
    "last_messages": []
  }
}
```

### 2. Autenticación

La API de runtime debe aceptar solo tráfico service-to-service autenticado con:

- `Authorization: Bearer <SALES_AGENT_BEARER_TOKEN>`

Ese token no representa a una persona. Es un secreto de integración entre servicios.

### 3. Resolución de tenant

La primera decisión funcional es determinar el tenant:

- por `tenant_id` explícito
- por contexto de integración
- por reglas de lookup futuras

El runtime no debe responder de forma genérica sin saber para qué tenant está trabajando.

### 4. Carga de contexto

Una vez identificado el tenant, el runtime debe cargar:

- contexto global del tenant
- productos activos relacionados
- playbook activo aplicable
- conversación previa
- señales externas si existen

### 5. Orquestación

El runtime decide si necesita apoyo adicional:

- CRM, para recuperar estado comercial del lead
- RAG, para consultar documentación, FAQs o conocimiento semántico
- LLM, para redactar o razonar sobre la respuesta

No siempre debe llamar a todo. La idea es usar la mínima cantidad de contexto necesaria para tomar una buena decisión.

### 6. Decisión

La salida del runtime debe ser estructurada, no solo texto libre.

Salida esperada:

- `reply`
- `intent`
- `score`
- `action`
- `needs_human`
- `data_to_save`

Ejemplo conceptual:

```json
{
  "reply": "Perfecto, ¿qué tipo de negocio tienes?",
  "intent": "qualification",
  "score": 0.5,
  "action": "ask_question",
  "needs_human": false,
  "data_to_save": {}
}
```

## Acciones esperadas

El runtime debe poder recomendar, como mínimo:

- saludar
- hacer una pregunta de cualificación
- seguir descubriendo necesidades
- derivar a humano
- pedir datos adicionales
- proponer agenda
- guardar información útil para CRM o analítica

## Reglas de handoff

El sistema debe poder marcar `needs_human = true` cuando:

- la conversación supera el umbral de confianza
- el usuario pide hablar con una persona
- el caso cae fuera de las reglas del playbook
- se detecta una oportunidad de alto valor que requiere intervención humana
- el runtime no tiene contexto suficiente

## Política de datos

`sales-agent` no debe ser el maestro de los datos comerciales.

Debe:

- leer contexto del CRM cuando lo necesite
- devolver datos estructurados para persistencia
- evitar duplicar la fuente de verdad de leads y clientes

El CRM sigue siendo el sistema maestro para:

- clientes
- leads
- agenda
- pipeline

## Separación de entorno

La arquitectura debe mantenerse separada por entorno:

- producción: imagen construida, sin bind mounts de código
- desarrollo Docker: bind mounts y hot reload
- desarrollo host: no debe ser la vía principal

La documentación y los comandos deben reflejar esa separación sin mezclar atajos de un entorno en otro.

## Estado funcional esperado

Hoy el proyecto ya tiene:

- la base del modelo de dominio
- el backend administrativo
- la API de runtime
- la separación Docker para desarrollo y producción

Lo que sigue evolucionando es:

- la carga real de contexto por tenant
- la integración con CRM, RAG y LLM
- el motor de scoring y derivación
- el catálogo formal de playbooks

## Criterio de aceptación

Consideramos que la app está en la dirección correcta si:

- un tenant puede configurar su contexto, producto y playbook
- una entrada de WhatsApp llega normalizada al runtime
- el runtime devuelve una decisión estructurada
- el envío final lo hace `wa-gateway-api`
- el CRM sigue siendo la fuente maestra del negocio
