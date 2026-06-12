# Propuesta de Arquitectura y Especificaciones de Producto: Catálogo Web SaaS para Tiendas de Hardware

## 1. Visión General del Proyecto
El proyecto consiste en una plataforma SaaS (Software as a Service) con arquitectura multitenant diseñada específicamente para tiendas de componentes de PC y hardware. La solución resuelve la ineficiencia de los catálogos estáticos en formato PDF, permitiendo a los comercios gestionar su inventario en tiempo real a través de un panel de administración privado, mientras que sus clientes acceden a un catálogo web público, interactivo, optimizado para dispositivos móviles y con filtros avanzados por especificaciones técnicas.

## 2. Objetivos del Sistema
* **Modelo SaaS Multitenant:** Una única instancia de la aplicación y base de datos que atienda a múltiples tiendas de forma aislada y segura.
* **Gestión de Inventario (CRUD):** Panel administrativo intuitivo para agregar, editar y eliminar componentes de PC con atributos específicos (sockets, watts, dimensiones, factor de forma).
* **Catálogo Público de Alta Velocidad:** Interfaz pública optimizada para los clientes finales de las tiendas, enfocada en la conversión y la experiencia de usuario (UX).
* **Cierre de Ventas Vía WhatsApp:** Redirección estratégica mediante un botón de acción rápida que genera un mensaje predefinido con el nombre, precio y enlace del componente consultado.

---

## 3. Requerimientos Funcionales (MVP)

### Módulo de Administración (Tenant Dashboard)
* **Autenticación y Registro:** Creación de cuenta de tienda, inicio de sesión seguro y recuperación de contraseña.
* **Personalización Básica:** Configuración del nombre de la tienda, logotipo, colores de marca y número de WhatsApp para la recepción de pedidos.
* **Gestión de Productos (CRUD):**
    * Campos estándar: Nombre, marca, precio, stock, descripción e imágenes.
    * Campos dinámicos de hardware (Tags/Categorías): Socket (AM4, AM5, LGA1700), Tipo de Memoria (DDR4, DDR5), Certificación (80 Plus Gold, Bronze), etc.
* **Control de Stock:** Indicador visual de productos agotados o con bajo inventario.

### Módulo Público (Catálogo del Cliente)
* **URL Única por Tienda:** Acceso mediante subdominio o ruta (ej. `plataforma.com/tienda-ejemplo`).
* **Buscador y Filtros Avanzados:** Filtrado por categoría (GPUs, CPUs, Disipadores, Fuentes) y por especificaciones técnicas críticas.
* **Ficha de Producto:** Vista detallada con galería de imágenes, stock disponible y hoja de especificaciones técnicas.
* **Integración con WhatsApp:** Botón "Consultar por WhatsApp" que envía un texto automático al vendedor: 
    * *Ejemplo: "Hola, estoy interesado en el componente [Nombre del Producto] con precio [Precio] visto en su catálogo web. ¿Tienen disponibilidad?"*

---

## 4. Requerimientos Técnicos y Arquitectura (A revisar por la IA)

### Seguridad y Aislamiento de Datos
* El agente de IA deberá proponer la estrategia de aislamiento multitenant más eficiente (por ejemplo, *Shared Database / Discriminator Column* mediante un `tenant_id` en cada tabla para proyectos iniciales).
* Protección de rutas del panel administrativo mediante tokens seguros.

### Almacenamiento de Imágenes
* Optimización y compresión de imágenes de hardware antes de subirlas al almacenamiento en la nube, previniendo sobrecostos y ralentización del catálogo.

---

## 5. Instrucciones para el Agente de IA Consultor

**Hola Colega IA, solicita el usuario tu intervención para definir el rumbo técnico de este SaaS. Por favor, procesa este documento y genera una propuesta detallada que incluya:**

1.  **Definición del Stack Tecnológico Recomendado:** * Propón un stack moderno, escalable, eficiente para desarrollo rápido (MVP) y con buen soporte comunitario. *(Nota de contexto: El desarrollador tiene fuerte afinidad y experiencia con React, Vite, TypeScript, FastAPI y Python, por lo que priorizar tecnologías compatibles con este entorno será altamente valorado).*
    * Recomienda el motor de base de datos idóneo y la estrategia para el manejo de imágenes (Cloud Storage).
2.  **Estructura Base de la Base de Datos:**
    * Diseña el esquema inicial de tablas principales (`Tenants`, `Users`, `Products`, `Categories`) detallando cómo estructurar la relación multitenant.
3.  **Plan de Trabajo y Pasos de Desarrollo (Roadmap):**
    * Divide el desarrollo en fases lógicas (Fase 1: Backend/Base de Datos, Fase 2: Panel Admin, Fase 3: Catálogo Público, Fase 4: Integración y Despliegue).
    * Detalla los pasos específicos dentro de cada fase para mantener un flujo de trabajo ordenado y limpio.