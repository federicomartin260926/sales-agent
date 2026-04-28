# TODO del proyecto

Lista ordenada de trabajo para llevar `sales-agent` desde la base actual al runtime funcional objetivo.

## 1. Cerrar el modelo de dominio

- definir el esquema final de `Tenant.salesPolicy`
- definir el esquema final de `Product.salesPolicy`
- definir el esquema final de `Playbook.config`
- confirmar campos obligatorios y opcionales en cada entidad
- añadir validaciones de dominio donde falten

## 2. Resolver contexto del tenant en el runtime

- buscar `Tenant` por `tenant_id`
- cargar productos activos del tenant
- cargar playbooks activos del tenant
- elegir playbook aplicable por tenant y, si existe, por producto
- devolver error estructurado si el tenant no existe o está inactivo

## 3. Convertir el decision engine en orquestador real

- reemplazar reglas por keyword con lógica basada en contexto
- incorporar scoring según playbook
- distinguir intent, action y necesidad de humano
- estructurar `data_to_save` como salida para integraciones posteriores
- hacer el flujo extensible por tenant y producto

## 4. Integrar CRM

- definir contrato de lectura contra CRM
- consultar estado del lead antes de responder cuando haga falta
- recuperar datos de contacto, pipeline y actividad previa
- evitar duplicar en `sales-agent` la fuente maestra del negocio

## 5. Integrar RAG

- definir contrato de consulta documental
- indexar FAQs, objeciones y material comercial
- recuperar contexto semántico por tenant/producto
- usar RAG solo cuando aporte valor real a la decisión

## 6. Integrar LLM

- definir cliente intercambiable para OpenAI u Ollama
- construir prompts por tenant y playbook
- separar razonamiento, redacción y decisión cuando sea necesario
- mantener salida estructurada estable

## 7. Completar reglas de handoff

- definir umbrales de derivación a humano
- detectar señales de intención de cierre o riesgo
- incluir reglas por producto y por tenant
- entregar motivo de handoff de forma estructurada

## 8. Persistencia operativa

- decidir qué datos se guardan fuera de `sales-agent` y en qué sistema viven
- registrar trazas de decisión
- guardar eventos conversacionales relevantes
- preparar auditoría mínima de respuestas y acciones

## 9. Endpoints y contratos

- estabilizar el contrato de `POST /agent/respond`
- documentar payloads normalizados de `wa-gateway-api`
- definir contratos de error
- añadir healthchecks y endpoints de soporte si faltan

## 10. Administración y UX interna

- mejorar la gestión de tenants, products y playbooks
- completar la edición de perfiles y roles internos
- asegurar que el panel humano refleje el estado real del modelo

## 11. Calidad y tests

- añadir tests de integración del runtime por tenant
- añadir tests de regresión para scoring y handoff
- cubrir validaciones de entidades y contratos de API
- automatizar el flujo principal en Docker

## 12. Operación y despliegue

- revisar el workflow de producción y bootstrap
- confirmar separación final entre dev y prod
- documentar variables de entorno críticas
- mantener `make` como entrada principal para operar el proyecto
