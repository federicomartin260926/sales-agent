# Matriz de acceso

Esta matriz define qué puede hacer cada rol dentro del backend de `sales-agent`.

## Principios

- `admin` administra la plataforma completa.
- `manager` administra la operación comercial del negocio.
- `agent` opera y consulta, pero no administra usuarios ni configuración general.
- Las rutas y la interfaz deben reflejar esta separación de forma consistente.

## Permisos por módulo

### Usuarios

- `admin`: lectura y administración completa
- `manager`: no acceso
- `agent`: no acceso

### Negocios

- `admin`: lectura y administración completa
- `manager`: lectura y administración completa
- `agent`: solo lectura si se expone en el dashboard, sin edición

### Productos / servicios

- `admin`: lectura y administración completa
- `manager`: lectura y administración completa
- `agent`: solo lectura si se expone en el dashboard, sin edición

### Guías comerciales

- `admin`: lectura y administración completa
- `manager`: lectura y administración completa
- `agent`: solo lectura de resumen si se expone en el dashboard, sin edición

### Dashboard

- `admin`: acceso completo
- `manager`: acceso completo
- `agent`: acceso al resumen operativo

### Perfil

- `admin`: ver y editar nombre y clave
- `manager`: ver y editar nombre y clave
- `agent`: ver y editar nombre y clave

### Integración técnica

- `admin`: acceso
- `manager`: acceso
- `agent`: no acceso

### Configuración general de la app

- `admin`: acceso
- `manager`: no acceso
- `agent`: no acceso

## Resumen corto

- `admin` = plataforma
- `manager` = negocio
- `agent` = operación
