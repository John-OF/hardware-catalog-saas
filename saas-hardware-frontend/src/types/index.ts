export interface TenantTheme {
  hero_title?: string | null;
  hero_subtitle?: string | null;
  banner_url?: string | null;
  accent_color?: string | null;
  color_mode?: 'dark' | 'light' | null;
  page_title?: string | null;
  favicon_url?: string | null;
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
}

export interface User {
  id: string;
  tenant_id: string;
  name: string;
  email: string;
  role: 'admin' | 'staff';
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

export interface Product {
  id: string;
  tenant_id: string;
  category_id: string | null;
  category?: Category;
  name: string;
  brand: string | null;
  price: number;
  stock: number;
  is_available: boolean;
  description: string | null;
  specs: Record<string, string | number> | null;
  image_url: string | null;
  thumbnail_url: string | null;
  is_active: boolean;
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
