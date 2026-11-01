/**
 * Cómo se llaman en pantalla los límites de un plan, y en qué orden se leen.
 *
 * Las claves vienen de `config/plans.php` (por `GET /public/plans` y la ficha del
 * operador); aquí solo está su nombre. Lo usan la landing, la pantalla de tienda
 * cerrada (FUN-28) y la ficha del operador, que antes tenían cada una su copia.
 */
export const NOMBRES_LIMITE: Record<string, string> = {
  products: 'Productos',
  categories: 'Categorías',
  pages: 'Páginas',
  users: 'Equipo',
  images_per_product: 'Imágenes por producto',
  custom_domain: 'Dominio propio',
  csv_import: 'Importar CSV',
};

/** Orden de lectura de los límites en una tarjeta; no es el orden de `config/plans.php`. */
export const ORDEN_LIMITES = ['products', 'categories', 'pages', 'images_per_product', 'users', 'custom_domain', 'csv_import'];
