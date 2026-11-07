import type { PaymentMethod, Product, ProductVariant, Tenant } from '../types';

/** Datos mínimos con la forma que devuelve la API. Cada test pisa solo lo que le importa. */

/**
 * Las tres plantillas del comprador tal como las manda hoy el catálogo público
 * a una tienda que no las cambió (MOD-25). Copia de las de fábrica de
 * `config/plantillas_whatsapp.php` solo para los tests: lo que se prueba aquí es
 * cómo se rellenan, no la redacción; si el texto de fábrica cambia, esto no
 * tiene por qué cambiar con él.
 */
export const PLANTILLAS_DEL_COMPRADOR = {
  pedido_nuevo: 'Hola {tienda}, quiero hacer este pedido:\n\n{productos}\n\n*Total: {total}*\n\nNombre: {cliente}\nEntrega: {entrega}\nNota: {nota}\n\nFormas de pago: {pagos}\n\n(Pedido {pedido})\nMi pedido: {seguimiento}',
  consulta_producto: 'Hola, me interesa el producto *{producto}* (precio: {precio}) de tu catálogo. ¿Está disponible?\n\nEnlace del producto: {enlace}',
  armado_pc: '*¡Hola! Armé una PC desde tu catálogo:*\n\n{piezas}\n\n*Total estimado:* {total}\n*Consumo del sistema:* {consumo}\n*Ver el armado:* {enlace}\n\nPor favor, confírmenme stock y disponibilidad. ¡Gracias!',
};

export const unaTienda = (datos: Partial<Tenant> = {}): Tenant => ({
  id: 't-1',
  slug: 'tienda-demo',
  name: 'Tienda Demo',
  logo_url: null,
  primary_color: '#3b82f6',
  theme: null,
  whatsapp_number: '+51 999 888 777',
  plan: 'pro',
  // MOD-19: como toda tienda de hoy, de la vertical por defecto y con todo.
  vertical: 'hardware',
  modulos: { tipo_de_componente: true, ficha_tecnica: true, armador: true },
  is_active: true,
  is_published: true,
  custom_domain: null,
  custom_domain_token: null,
  custom_domain_verified_at: null,
  trial_ends_at: null,
  currency: 'USD',
  timezone: 'UTC',
  payment_methods: null,
  delivery_enabled: false,
  delivery_cost: 0,
  // MOD-2: apagado, como una tienda recién creada.
  tax_enabled: false,
  tax_name: 'IVA',
  tax_rate: 15,
  tax_included: true,
  plantillas_whatsapp: PLANTILLAS_DEL_COMPRADOR,
  ...datos,
});

/** Un método de pago de la tienda (MOD-23), encendido y sin QR. */
export const unMetodo = (datos: Partial<PaymentMethod> = {}): PaymentMethod => ({
  id: 'm-1',
  name: 'Yape',
  details: null,
  instructions: null,
  qr_url: null,
  enabled: true,
  ...datos,
});

export const unProducto = (datos: Partial<Product> = {}): Product => ({
  id: 'p-1',
  tenant_id: 't-1',
  category_id: null,
  name: 'Intel Core i5-13400F',
  brand: 'Intel',
  price: 200,
  sale_price: null,
  stock: 10,
  low_stock_threshold: 5,
  sku: null,
  is_available: true,
  description: null,
  specs: null,
  image_url: null,
  thumbnail_url: null,
  is_active: true,
  status: 'published',
  created_at: '2026-09-01T00:00:00Z',
  ...datos,
});

export const unaVariante = (datos: Partial<ProductVariant> = {}): ProductVariant => ({
  id: 'v-1',
  product_id: 'p-ram',
  options: [{ name: 'Capacidad', value: '16 GB' }],
  nombre: '16 GB',
  sku: null,
  price: '60.00',
  sale_price: null,
  stock: 5,
  low_stock_threshold: 5,
  image_url: null,
  thumbnail_url: null,
  sort_order: 0,
  ...datos,
});

/** Una RAM con dos capacidades: el caso que rompe si algo cobra el resumen de la ficha. */
export const unaRamConVariantes = () => {
  const de16 = unaVariante({ id: 'v-16', nombre: '16 GB', price: '60.00', stock: 5 });
  const de32 = unaVariante({
    id: 'v-32',
    nombre: '32 GB',
    options: [{ name: 'Capacidad', value: '32 GB' }],
    price: '120.00',
    sale_price: '99.90',
    stock: 3,
  });
  // Resumen como lo deja el backend: precio de la más barata y suma de stock.
  const producto = unProducto({ id: 'p-ram', name: 'Kingston Fury', price: 60, stock: 8, variants: [de16, de32] });

  return { producto, de16, de32 };
};
