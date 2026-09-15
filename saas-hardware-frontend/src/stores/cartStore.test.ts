import { beforeEach, describe, expect, it } from 'vitest';
import { useCartStore } from './cartStore';
import { unaRamConVariantes, unProducto } from '../test/fixtures';

const carrito = () => useCartStore.getState();

describe('cartStore', () => {
  beforeEach(() => {
    useCartStore.setState({ slug: null, items: [] });
  });

  it('suma unidades al agregar dos veces el mismo producto', () => {
    const cpu = unProducto();
    carrito().addItem('tienda-a', cpu);
    carrito().addItem('tienda-a', cpu, 2);

    expect(carrito().items).toHaveLength(1);
    expect(carrito().totalItems()).toBe(3);
  });

  it('el mismo producto en dos variantes son dos líneas (MOD-5)', () => {
    const { producto, de16, de32 } = unaRamConVariantes();
    carrito().addItem('tienda-a', producto, 1, de16);
    carrito().addItem('tienda-a', producto, 1, de32);
    carrito().addItem('tienda-a', producto, 1, de32);

    expect(carrito().items).toHaveLength(2);
    expect(carrito().items.find((i) => i.variant?.id === 'v-32')?.quantity).toBe(2);
  });

  it('cobra el precio de la variante, con su oferta, y no el resumen de la ficha', () => {
    const { producto, de16, de32 } = unaRamConVariantes();
    carrito().addItem('tienda-a', producto, 2, de16); // 2 × 60
    carrito().addItem('tienda-a', producto, 1, de32); // 1 × 99.90 (oferta), no 120 ni 60

    expect(carrito().totalAmount()).toBeCloseTo(219.9);
  });

  it('usa el precio de oferta del producto cuando lo hay', () => {
    carrito().addItem('tienda-a', unProducto({ price: 200, sale_price: 180 }), 2);

    expect(carrito().totalAmount()).toBe(360);
  });

  it('vacía el carrito al agregar desde otra tienda', () => {
    carrito().addItem('tienda-a', unProducto({ id: 'p-a' }));
    carrito().addItem('tienda-b', unProducto({ id: 'p-b' }));

    expect(carrito().slug).toBe('tienda-b');
    expect(carrito().items.map((i) => i.product.id)).toEqual(['p-b']);
  });

  it('cambiar la cantidad de una variante no toca la otra', () => {
    const { producto, de16, de32 } = unaRamConVariantes();
    carrito().addItem('tienda-a', producto, 1, de16);
    carrito().addItem('tienda-a', producto, 1, de32);

    carrito().setQuantity('p-ram', 4, 'v-32');

    expect(carrito().items.find((i) => i.variant?.id === 'v-16')?.quantity).toBe(1);
    expect(carrito().items.find((i) => i.variant?.id === 'v-32')?.quantity).toBe(4);
  });

  it('bajar a cero quita la línea', () => {
    carrito().addItem('tienda-a', unProducto());
    carrito().setQuantity('p-1', 0);

    expect(carrito().items).toEqual([]);
  });

  it('quitar una variante deja la otra', () => {
    const { producto, de16, de32 } = unaRamConVariantes();
    carrito().addItem('tienda-a', producto, 1, de16);
    carrito().addItem('tienda-a', producto, 1, de32);

    carrito().removeItem('p-ram', 'v-16');

    expect(carrito().items.map((i) => i.variant?.id)).toEqual(['v-32']);
  });

  it('un carrito guardado antes de MOD-5 (líneas sin `variant`) se sigue pudiendo editar', () => {
    const cpu = unProducto();
    useCartStore.setState({ slug: 'tienda-a', items: [{ product: cpu, quantity: 1 }] });

    carrito().addItem('tienda-a', cpu);
    expect(carrito().items).toHaveLength(1);
    expect(carrito().items[0].quantity).toBe(2);

    carrito().removeItem('p-1');
    expect(carrito().items).toEqual([]);
  });

  it('persiste en localStorage para no perder el pedido al recargar', () => {
    carrito().addItem('tienda-a', unProducto());

    const guardado = JSON.parse(localStorage.getItem('catalog-cart') ?? '{}');
    expect(guardado.state.slug).toBe('tienda-a');
    expect(guardado.state.items).toHaveLength(1);
  });
});
