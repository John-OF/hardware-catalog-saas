import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import type { CartItem, Product } from '../types';

interface CartState {
  // Slug de la tienda a la que pertenece el carrito (evita mezclar tiendas).
  slug: string | null;
  items: CartItem[];

  addItem: (slug: string, product: Product, quantity?: number) => void;
  removeItem: (productId: string) => void;
  setQuantity: (productId: string, quantity: number) => void;
  clear: () => void;

  totalItems: () => number;
  totalAmount: () => number;
}

export const useCartStore = create<CartState>()(
  persist(
    (set, get) => ({
      slug: null,
      items: [],

      addItem: (slug, product, quantity = 1) => {
        const state = get();
        // Si el carrito es de otra tienda, se reinicia.
        const items = state.slug === slug ? [...state.items] : [];
        const existing = items.find((i) => i.product.id === product.id);
        if (existing) {
          existing.quantity += quantity;
        } else {
          items.push({ product, quantity });
        }
        set({ slug, items });
      },

      removeItem: (productId) =>
        set((s) => ({ items: s.items.filter((i) => i.product.id !== productId) })),

      setQuantity: (productId, quantity) =>
        set((s) => ({
          items: s.items
            .map((i) => (i.product.id === productId ? { ...i, quantity } : i))
            .filter((i) => i.quantity > 0),
        })),

      clear: () => set({ items: [] }),

      totalItems: () => get().items.reduce((acc, i) => acc + i.quantity, 0),
      totalAmount: () =>
        get().items.reduce((acc, i) => acc + Number(i.product.price) * i.quantity, 0),
    }),
    { name: 'catalog-cart' }
  )
);
