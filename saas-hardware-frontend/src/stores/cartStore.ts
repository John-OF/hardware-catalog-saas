import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { CartItem, Product, ProductVariant } from '../types';
import { claveDeLinea, datosDeVenta } from '../utils/variants';

interface CartState {
  // Slug de la tienda a la que pertenece el carrito (evita mezclar tiendas).
  slug: string | null;
  items: CartItem[];

  /** `variant` es obligatoria en la práctica para un producto con variantes (MOD-5): el backend la exige. */
  addItem: (slug: string, product: Product, quantity?: number, variant?: ProductVariant | null) => void;
  removeItem: (productId: string, variantId?: string | null) => void;
  setQuantity: (productId: string, quantity: number, variantId?: string | null) => void;
  clear: () => void;

  totalItems: () => number;
  totalAmount: () => number;
}

/** Clave de una línea ya guardada. Un carrito persistido de antes de MOD-5 no trae `variant` y cae en la del producto. */
const claveDe = (item: CartItem) => claveDeLinea(item.product.id, item.variant?.id);

export const useCartStore = create<CartState>()(
  persist(
    (set, get) => ({
      slug: null,
      items: [],

      addItem: (slug, product, quantity = 1, variant = null) => {
        const state = get();
        // Si el carrito es de otra tienda, se reinicia.
        const items = state.slug === slug ? [...state.items] : [];
        const clave = claveDeLinea(product.id, variant?.id);
        const existing = items.find((i) => claveDe(i) === clave);
        if (existing) {
          existing.quantity += quantity;
        } else {
          items.push({ product, variant, quantity });
        }
        set({ slug, items });
      },

      removeItem: (productId, variantId = null) =>
        set((s) => ({ items: s.items.filter((i) => claveDe(i) !== claveDeLinea(productId, variantId)) })),

      setQuantity: (productId, quantity, variantId = null) =>
        set((s) => ({
          items: s.items
            .map((i) => (claveDe(i) === claveDeLinea(productId, variantId) ? { ...i, quantity } : i))
            .filter((i) => i.quantity > 0),
        })),

      clear: () => set({ items: [] }),

      totalItems: () => get().items.reduce((acc, i) => acc + i.quantity, 0),
      // MOD-15: el precio de cada línea depende de SU cantidad, así que la
      // cantidad entra en el cálculo y no solo multiplica al final.
      totalAmount: () =>
        get().items.reduce((acc, i) => acc + datosDeVenta(i.product, i.variant, i.quantity).precio * i.quantity, 0),
    }),
    { name: 'catalog-cart' }
  )
);
