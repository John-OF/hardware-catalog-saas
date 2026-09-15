import type { Product, ProductVariant, Tenant } from '../types';

/** Datos mínimos con la forma que devuelve la API. Cada test pisa solo lo que le importa. */

export const unaTienda = (datos: Partial<Tenant> = {}): Tenant => ({
  id: 't-1',
  slug: 'tienda-demo',
  name: 'Tienda Demo',
  logo_url: null,
  primary_color: '#3b82f6',
  theme: null,
  whatsapp_number: '+51 999 888 777',
  plan: 'pro',
  is_active: true,
  is_published: true,
  custom_domain: null,
  custom_domain_token: null,
  custom_domain_verified_at: null,
  trial_ends_at: null,
  currency: 'USD',
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
