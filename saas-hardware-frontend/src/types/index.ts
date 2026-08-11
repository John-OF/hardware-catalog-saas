export interface TenantTheme {
  hero_title?: string | null;
  hero_subtitle?: string | null;
  banner_url?: string | null;
  accent_color?: string | null;
  color_mode?: 'dark' | 'light' | null;
  page_title?: string | null;
  favicon_url?: string | null;
  layout?: 'grid' | 'compact' | 'list' | null;
  font?: 'sans' | 'serif' | 'mono' | 'heading' | null;
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

export interface User {
  id: string;
  tenant_id: string;
  name: string;
  email: string;
  phone?: string;
  role: 'admin' | 'staff' | 'customer';
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
  customer_phone: string;
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
