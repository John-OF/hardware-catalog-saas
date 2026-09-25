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
  /**
   * Si el catalogo publico se ve (FUN-5). NO es lo mismo que tener el correo
   * verificado: la columna nace en `true`, asi que las tiendas anteriores a
   * FUN-5 son publicas aunque su dueno nunca haya confirmado nada. Preguntar por
   * el correo para saber si la tienda se ve era, justamente, el fallo.
   */
  is_published: boolean;
  custom_domain: string | null;
  /**
   * Verificación del dominio propio (FUN-6). `custom_domain_verified_at` es
   * `null` mientras no se demuestre con el registro TXT; `custom_domain_token`
   * es el valor que hay que poner ahí. Los dos solo llegan por `GET /tenant`
   * -la pantalla del propio dueño-, no por las rutas públicas del catálogo.
   */
  custom_domain_token: string | null;
  custom_domain_verified_at: string | null;
  /**
   * Período de prueba (FUN-16). `null` significa que esta tienda no está en
   * prueba —ya eligió un plan, o es de antes de este cambio—. Mientras la
   * fecha no pasa, el plan EFECTIVO es 'trial' (límites de Pro) aunque
   * `plan` siga diciendo el plan por defecto: eso lo decide el backend
   * (`PlanGate`), no el frontend.
   */
  trial_ends_at: string | null;
  /** Código ISO de la moneda de la tienda (OWN-1). Ver src/utils/money.ts. */
  currency: string;
  /**
   * Zona horaria de la tienda (MOD-13). Identificador IANA, 'UTC' por defecto.
   *
   * No cambia nada de lo guardado —la base sigue en UTC— sino cómo se lee: en
   * qué día cae una venta al agrupar los reportes y con qué hora se pinta una
   * fecha en el panel. Ver src/utils/timezones.ts y src/utils/fechas.ts.
   */
  timezone: string;
  /**
   * Métodos de pago que la tienda le enseña al comprador (MOD-3). No es una
   * pasarela: el checkout sigue cerrándose por WhatsApp, esto es solo dónde
   * pagarle. Desde el catálogo público solo llegan los que están `enabled`;
   * desde el panel (`GET /tenant`) llegan los cuatro, encendidos o no, para
   * poder editarlos. `null` es "nunca configuró ninguno".
   */
  payment_methods: PaymentMethods | null;
  /** Envío a domicilio (MOD-1): un precio fijo, sin zonas. Si está apagado, la única opción es recojo en tienda. */
  delivery_enabled: boolean;
  delivery_cost: number | string;
  /**
   * Impuesto de la tienda (MOD-2). El nombre lo pone el dueño porque depende del
   * país ("IGV", "IVA", "ITBMS").
   *
   * `tax_included` es lo que cambia la aritmética: con `true` el precio del
   * catálogo ya lo lleva dentro y el total no sube; con `false` se suma al final
   * y el comprador paga más que la suma de su carrito — que es justo por lo que
   * el checkout tiene que enseñar el desglose.
   */
  tax_enabled: boolean;
  tax_name: string;
  tax_rate: number | string;
  tax_included: boolean;
}

/** Los cuatro métodos fijos de `Tenant::METODOS_DE_PAGO`. Ninguno es obligatorio. */
export interface PaymentMethods {
  yape?: { enabled: boolean; phone?: string | null; holder_name?: string | null };
  plin?: { enabled: boolean; phone?: string | null; holder_name?: string | null };
  transferencia?: {
    enabled: boolean;
    bank?: string | null;
    account_number?: string | null;
    account_type?: 'ahorros' | 'corriente' | null;
    holder_name?: string | null;
    cci?: string | null;
  };
  efectivo?: { enabled: boolean };
}

/**
 * Plan de la tienda, sus limites y su consumo (SAAS-3). Lo sirve `GET /plan`.
 *
 * En `limits`, un numero es un tope, `null` es "sin tope" y un booleano es una
 * funcion que el plan trae o no. La matriz manda desde `config/plans.php` del
 * backend: aqui NO hay una segunda copia que mantener, solo la forma.
 */
export interface PlanInfo {
  plan: string;
  label: string;
  limits: Record<string, number | boolean | null>;
  usage: Record<string, number>;
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
  /**
   * FUN-5. El backend manda el booleano, no la fecha: al panel solo le hace
   * falta saber si ensena el aviso de "confirma tu correo".
   *
   * Opcional porque el mismo tipo lo usa el cliente del catalogo publico, al
   * que no se le pide verificar nada.
   */
  email_verified?: boolean;
  /**
   * FUN-4. Quien todavia no ha entrado nunca: lo que distingue una invitacion
   * pendiente de un companiero que ya trabaja. Booleano y no la fecha, por el
   * mismo criterio que `email_verified`.
   */
  invitation_pending?: boolean;
}

/**
 * Que pieza de PC vende una categoria (FUN-8). Espejo de
 * `App\Enums\ComponentType`; las etiquetas estan en `utils/componentTypes`.
 */
export type ComponentType =
  | 'cpu'
  | 'motherboard'
  | 'ram'
  | 'gpu'
  | 'ssd'
  | 'power'
  | 'cooling'
  | 'case'
  | 'monitor'
  | 'peripheral'
  | 'other';

export interface Category {
  id: string;
  tenant_id: string;
  name: string;
  icon: string | null;
  /**
   * Lo que empareja la categoria con los pasos del armador. Antes se adivinaba
   * por un trozo del nombre, asi que una tienda que dijera "CPU" en vez de
   * "Procesadores" se quedaba sin armador y sin aviso.
   */
  component_type: ComponentType;
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

/**
 * Un escalón de precio por mayor (MOD-15): "desde 10 unidades, a 90 cada una".
 *
 * Es un **precio**, no un descuento: al llegar a `min` todas las unidades de la
 * línea valen `price`. El backend lo devuelve siempre ordenado por `min` y con
 * los precios bajando.
 */
export interface PriceTier {
  min: number;
  price: number;
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
  /**
   * Costo de compra (MOD-6). **Solo llega si quien mira es admin**: el backend
   * lo esconde para staff y para el catálogo público, así que `undefined` no
   * significa "no tiene costo" sino "no te toca verlo"; `null` sí es "no lo han
   * puesto". Con variantes es el resumen de la más barata, emparejado con
   * `price`.
   */
  cost?: number | string | null;
  /**
   * Precio por mayor (MOD-15): a partir de cuántas unidades y a cuánto sale
   * cada una. `null` es lo normal. Con variantes es, como `price` y `cost`, el
   * resumen de la más barata: **no se cobra**, cobra el de la variante.
   */
  price_tiers?: PriceTier[] | null;
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
  /**
   * MOD-5. Vacío o ausente: producto sin variantes, precio y stock son los de
   * arriba. Con variantes, `price`/`sale_price` son los de la más barata y
   * `stock` la suma: un resumen para listar y ordenar, no lo que se cobra.
   */
  variants?: ProductVariant[];
  created_at: string;
}

/** Una opción de una variante: "Capacidad" = "16 GB". */
export interface VariantOption {
  name: string;
  value: string;
}

export interface ProductVariant {
  id: string;
  product_id: string;
  options: VariantOption[];
  /** Los valores de las opciones unidos: "16 GB / Negro". Lo calcula el backend. */
  nombre: string;
  sku: string | null;
  price: number | string;
  sale_price: number | string | null;
  /** Costo de compra de ESTA variante (MOD-6). Solo para admin; ver `Product.cost`. */
  cost?: number | string | null;
  /** Precio por mayor de ESTA variante (MOD-15). Es el que se cobra. */
  price_tiers?: PriceTier[] | null;
  stock: number;
  low_stock_threshold: number;
  image_url: string | null;
  thumbnail_url: string | null;
  sort_order: number;
}

export interface CartItem {
  product: Product;
  /** La variante elegida (MOD-5); null o ausente en un producto sin variantes. */
  variant?: ProductVariant | null;
  quantity: number;
}

export interface OrderItem {
  id: string;
  product_id: string | null;
  variant_id?: string | null;
  product_name: string;
  /** Snapshot de la variante vendida ("16 GB"); null si el producto no tenía variantes. */
  variant_name?: string | null;
  unit_price: number;
  /**
   * Lo que costó esta línea el día de la venta (MOD-6). Solo llega para admin;
   * `null` cuando el producto no tenía costo puesto, que no es cero.
   */
  unit_cost?: number | string | null;
  quantity: number;
  subtotal: number;
}

export interface Order {
  id: string;
  /**
   * Correlativo de la tienda (FUN-3). Es el identificador que se le enseña a la
   * gente: el UUID no se puede dictar por teléfono. Cada tienda lleva su propia
   * serie desde 1, así que NO es único en toda la plataforma — para eso está `id`.
   */
  number: number;
  customer_name: string;
  /** Opcional desde 7.5: una venta de mostrador puede no tener teléfono. */
  customer_phone: string | null;
  /** Opcional (FUN-2): sin él no se le puede avisar por correo de nada. */
  customer_email: string | null;
  customer_note: string | null;
  status: 'pending' | 'processing' | 'attended' | 'cancelled';
  /**
   * Cómo llega el pedido (MOD-1). `null` en la venta de mostrador —el
   * cliente está delante— y en los pedidos de antes de este cambio.
   * `delivery_cost` es un snapshot: lo que costaba el envío ESE día, no lo
   * que cueste hoy en `tenant.delivery_cost`.
   */
  delivery_method?: 'pickup' | 'delivery' | null;
  delivery_cost?: number | string;
  /**
   * La foto del impuesto del día de la venta (MOD-2), no la configuración de
   * hoy: cambiar el porcentaje no reescribe lo ya vendido. `tax_rate` en `null`
   * es "se vendió sin impuesto", que no es lo mismo que "con el 0%".
   *
   * El invariante que hace que esto se pinte sin preguntar cómo se cobró:
   * **`total` es siempre lo que paga el cliente** y `tax_amount` es cuánto de
   * eso es impuesto. `base_imponible` lo manda ya restado el servidor.
   */
  tax_name?: string | null;
  tax_rate?: number | string | null;
  tax_included?: boolean | null;
  tax_amount?: number | string | null;
  base_imponible?: number | string;
  /**
   * El cupón usado (MOD-4), también como foto del día de la venta.
   * `items_subtotal` es lo que sumaban los productos ANTES de descontar, y es lo
   * que deja pintar el desglose sin recalcular nada.
   */
  items_subtotal?: number | string | null;
  coupon_code?: string | null;
  discount_amount?: number | string | null;
  total: number;
  /**
   * Utilidad del pedido (MOD-6). Los tres solo llegan para admin.
   *
   * `null` en `utilidad` y `costo_total` significa que NINGUNA línea tenía
   * costo: no es que se ganara cero. Si `lineas_sin_costo` es mayor que cero
   * con una utilidad no nula, lo que hay es una utilidad parcial y hay que
   * decirlo en pantalla. El envío cobrado no cuenta aquí.
   */
  utilidad?: number | null;
  costo_total?: number | null;
  lineas_sin_costo?: number;
  items: OrderItem[];
  items_count?: number;
  created_at: string;
}

/**
 * Un cliente de la tienda, con lo que lleva comprado (MOD-10).
 *
 * Es un `User` con rol `customer`: lo crea el propio cliente al registrarse en
 * el catálogo, nunca el panel. Los totales los calcula el backend sin contar los
 * pedidos cancelados, y `ultima_compra`/`total_gastado` valen `null`/`0` para
 * quien se registró pero todavía no ha comprado.
 */
export interface Cliente {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  is_active: boolean;
  created_at: string;
  pedidos_count: number;
  total_gastado: number;
  ultima_compra: string | null;
  favoritos_count: number;
}

/** La ficha de un cliente: él y sus últimas compras. */
export interface FichaDeCliente {
  customer: Cliente;
  orders: Array<Pick<Order, 'id' | 'number' | 'status' | 'total' | 'created_at'> & { items_count: number }>;
}

/**
 * Una espera de "avisame cuando llegue" (FUN-1b).
 *
 * `customer_contact` es un campo libre: el cliente escribe un telefono o un
 * correo, como le parece. A los correos les llega el aviso automatico al reponer
 * stock; a los telefonos no, y por eso siguen pendientes hasta que el dueno les
 * escriba por WhatsApp y los marque desde el panel.
 */
export interface StockNotification {
  id: string;
  product_id: string;
  customer_name: string;
  customer_contact: string;
  /** null mientras siga esperando. */
  notified_at: string | null;
  created_at: string;
  product?: Pick<Product, 'id' | 'name' | 'stock' | 'price' | 'sale_price' | 'thumbnail_url'>;
  /** La variante que espera (MOD-5); null si espera el producto entero. */
  variant_id?: string | null;
  variant?: Pick<ProductVariant, 'id' | 'options' | 'nombre' | 'stock' | 'price' | 'sale_price'> | null;
}

/** Áreas de la actividad del panel (INF-3): lo que va antes del punto en `action`. */
export type AreaDeActividad =
  | 'producto'
  | 'pedido'
  | 'categoria'
  | 'pagina'
  | 'resena'
  | 'espera'
  | 'papelera'
  | 'equipo'
  | 'configuracion';

/**
 * Un cupón de descuento de la tienda (MOD-4).
 *
 * Descuenta sobre el subtotal de los **productos**, nunca sobre el envío, y
 * antes del impuesto. `used_count` lo lleva el servidor y no se edita.
 */
export interface Coupon {
  id: string;
  code: string;
  type: 'percent' | 'fixed';
  value: number | string;
  /** Los tres límites, opcionales: `null` es "sin límite". */
  min_purchase: number | string | null;
  max_uses: number | null;
  used_count: number;
  starts_at: string | null;
  ends_at: string | null;
  is_active: boolean;
  created_at: string;
}

/** Los dos tipos que tienen papelera (MOD-8). */
export type TipoDePapelera = 'productos' | 'pedidos';

/**
 * Lo que devuelve `GET /api/trash` (MOD-8).
 *
 * Los elementos llegan paginados y con su `deleted_at`; los totales de los dos
 * tipos vienen siempre, aunque solo se haya pedido uno, para las pestañas.
 */
export interface PapeleraResponse<T> {
  tipo: TipoDePapelera;
  items: PaginatedResponse<T & { deleted_at: string }>;
  totales: { productos: number; pedidos: number };
  /** `corte` es la fecha a partir de la cual algo sigue vivo: lo anterior ya caducó. */
  retencion: { dias: number; corte: string };
  tenant_timezone: string;
}

/**
 * Una línea de la actividad del panel de tienda (INF-3). La descripción llega
 * ya redactada: se escribió el día que pasó y no se recompone al pintar.
 */
export interface ActividadDelPanel {
  id: string;
  /** Verbo estable, `area.accion` (p. ej. `producto.editado`). */
  action: string;
  description: string;
  /** Copia del correo en el momento: sigue ahí aunque la cuenta se borre. */
  actor_email: string | null;
  actor_role: 'admin' | 'staff' | null;
  /** La cuenta, si sigue existiendo. */
  actor: { id: string; name: string } | null;
  context: Record<string, unknown> | null;
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
  /** Sólo en el panel: el público nunca lo recibe (ACC-1). */
  user_id?: string | null;
  rating: number;
  comment: string | null;
  is_approved: boolean;
  verified_purchase: boolean;
  created_at: string;
  updated_at: string;
  product?: Product;
}
