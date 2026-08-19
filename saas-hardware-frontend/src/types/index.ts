/** Base neutral de la tienda (PERS-2). Ver src/utils/neutrals.ts. */
export type TenantNeutral = 'slate' | 'zinc' | 'stone' | 'navy' | 'plum';

/**
 * Pareja tipografica cerrada (PERS-6, anterior a 10.3). Ya no se edita desde
 * el panel: sobrevive como fallback de las tiendas que solo tienen esta clave.
 * Ver src/utils/fonts.ts.
 */
export type TenantFont = 'sans' | 'serif' | 'mono' | 'heading';

/** Familia tipografica suelta (PERS-6 / 10.3). Ver src/utils/fonts.ts. */
export type TenantFontFamily =
  | 'inter'
  | 'outfit'
  | 'space-grotesk'
  | 'montserrat'
  | 'playfair'
  | 'lora'
  | 'merriweather'
  | 'fira-code';

/** Plantilla de la grilla del catalogo (4.4). */
export type TenantLayout = 'grid' | 'compact' | 'list';

export type TenantColorMode = 'dark' | 'light';

/** Forma de la tienda (PERS-4). Ver src/utils/shape.ts. */
export type TenantRadius = 'sharp' | 'soft' | 'round';
export type TenantCardStyle = 'glass' | 'solid' | 'flat';
export type TenantDensity = 'compact' | 'normal' | 'comfortable';

/** Estilo de portada (PERS-5). Ver src/utils/hero.ts. */
export type TenantHeroStyle = 'classic' | 'centered' | 'split' | 'minimal';

/** Color de la barra de anuncios (PERS-7). Ver src/utils/branding.ts. */
export type TenantAnnouncementStyle = 'primary' | 'accent' | 'neutral';

export interface TenantTheme {
  hero_title?: string | null;
  hero_subtitle?: string | null;
  hero_style?: TenantHeroStyle | null;
  banner_url?: string | null;
  accent_color?: string | null;
  color_mode?: TenantColorMode | null;
  neutral?: TenantNeutral | null;
  radius?: TenantRadius | null;
  card_style?: TenantCardStyle | null;
  density?: TenantDensity | null;
  page_title?: string | null;
  favicon_url?: string | null;
  layout?: TenantLayout | null;
  /** Pareja cerrada; solo se lee si faltan las dos claves de abajo. */
  font?: TenantFont | null;
  font_heading?: TenantFontFamily | null;
  font_body?: TenantFontFamily | null;

  /**
   * Elementos de marca (PERS-7). La barra se muestra si `announcement` tiene
   * texto: no hay un booleano aparte a proposito, ver src/utils/branding.ts.
   */
  announcement?: string | null;
  announcement_style?: TenantAnnouncementStyle | null;
  footer_address?: string | null;
  footer_hours?: string | null;
  footer_tax_id?: string | null;
  footer_facebook?: string | null;
  footer_instagram?: string | null;
  footer_tiktok?: string | null;

  sections?: any;
}

export interface Tenant {
  id: string;
  slug: string;
  name: string;
  logo_url: string | null;
  primary_color: string;
  theme: TenantTheme | null;
  whatsapp_number: string;
  plan: 'free' | 'pro' | 'enterprise';
  is_active: boolean;
  custom_domain: string | null;
  /** Código ISO de la moneda de la tienda (OWN-1). Ver src/utils/money.ts. */
  currency: string;
}

/**
 * Lo que devuelve `UserResource` en el backend (TEC-4). `tenant_id` y los
 * timestamps ya no viajan al navegador: nadie los usaba.
 */
export interface User {
  id: string;
  name: string;
  email: string;
  phone?: string;
  role: 'admin' | 'staff' | 'customer' | 'superadmin';
  is_active: boolean;
}

export interface Category {
  id: string;
  tenant_id: string;
  name: string;
  icon: string | null;
  sort_order: number;
  is_active: boolean;
}

export interface ProductImage {
  id: string;
  product_id: string;
  image_url: string;
  thumbnail_url: string | null;
  sort_order: number;
}

export interface Product {
  id: string;
  tenant_id: string;
  category_id: string | null;
  category?: Category;
  name: string;
  brand: string | null;
  price: number;
  sale_price: number | null;
  stock: number;
  low_stock_threshold: number;
  sku: string | null;
  is_available: boolean;
  description: string | null;
  specs: Record<string, string | number> | null;
  image_url: string | null;
  thumbnail_url: string | null;
  images?: ProductImage[];
  is_active: boolean;
  status: 'draft' | 'published';
  views_count?: number;
  reviews_avg_rating?: string | number | null;
  reviews_count?: number;
  reviews?: Review[];
  related_products?: Product[];
  waitlist_count?: number;
  created_at: string;
}

export interface CartItem {
  product: Product;
  quantity: number;
}

export interface OrderItem {
  id: string;
  product_id: string | null;
  product_name: string;
  unit_price: number;
  quantity: number;
  subtotal: number;
}

export interface Order {
  id: string;
  customer_name: string;
  /** Opcional desde 7.5: una venta de mostrador puede no tener teléfono. */
  customer_phone: string | null;
  customer_note: string | null;
  status: 'pending' | 'processing' | 'attended' | 'cancelled';
  total: number;
  items: OrderItem[];
  items_count?: number;
  created_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface AuthResponse {
  token: string;
  user: User;
  tenant: Tenant;
}

export interface Page {
  id: string;
  tenant_id: string;
  title: string;
  slug: string;
  content: string | null;
  is_active: boolean;
  created_at?: string;
}

export interface Review {
  id: string;
  tenant_id: string;
  product_id: string;
  customer_name: string;
  customer_email: string | null;
  rating: number;
  comment: string | null;
  is_approved: boolean;
  verified_purchase: boolean;
  created_at: string;
  updated_at: string;
  product?: Product;
}
