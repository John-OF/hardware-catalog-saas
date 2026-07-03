import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { 
  Plus, 
  Edit2, 
  Trash2, 
  X, 
  Search, 
  Image as ImageIcon,
  Loader2, 
  PlusCircle, 
  MinusCircle, 
  Eye, 
  EyeOff, 
  ChevronLeft, 
  ChevronRight,
  Upload,
  AlertCircle
} from 'lucide-react';
import { getProducts, createProduct, updateProduct, deleteProduct, importProductsCsv } from '../../api/products';
import { getCategories } from '../../api/categories';
import type { Product, Category, PaginatedResponse } from '../../types';

export default function ProductsPage() {
  const queryClient = useQueryClient();
  
  // Page filter states
  const [search, setSearch] = useState('');
  const [selectedCategory, setSelectedCategory] = useState('');
  const [page, setPage] = useState(1);

  // Form / Modal states
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingProduct, setEditingProduct] = useState<Product | null>(null);

  // Import states
  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importReport, setImportReport] = useState<{ message: string; success_count: number; errors: string[] } | null>(null);
  const [isImporting, setIsImporting] = useState(false);

  // Form inputs
  const [name, setName] = useState('');
  const [brand, setBrand] = useState('');
  const [price, setPrice] = useState('');
  const [salePrice, setSalePrice] = useState('');
  const [stock, setStock] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [description, setDescription] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [imagePreview, setImagePreview] = useState<string | null>(null);
  
  // Specs list state (list of { key, value })
  const [specsList, setSpecsList] = useState<{ key: string; value: string }[]>([]);

  // Fetch categories (for the filter and form dropdown)
  const { data: categories = [] } = useQuery<Category[]>({
    queryKey: ['categories'],
    queryFn: getCategories,
  });

  // Fetch products
  const { data: paginatedData, isLoading } = useQuery<PaginatedResponse<Product>>({
    queryKey: ['products', search, selectedCategory, page],
    queryFn: () => getProducts({
      search: search || undefined,
      category_id: selectedCategory || undefined,
      page,
    }),
  });

  const products = paginatedData?.data || [];
  const totalPages = paginatedData?.last_page || 1;

  // Mutation: Create Product
  const createMutation = useMutation({
    mutationFn: createProduct,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
      toast.success('Producto creado con éxito');
      closeModal();
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al crear el producto';
      toast.error(msg);
    }
  });

  // Mutation: Update Product
  const updateMutation = useMutation({
    mutationFn: ({ id, formData }: { id: string; formData: FormData }) => 
      updateProduct(id, formData),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
      toast.success('Producto actualizado con éxito');
      closeModal();
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al actualizar el producto';
      toast.error(msg);
    }
  });

  // Mutation: Delete Product
  const deleteMutation = useMutation({
    mutationFn: deleteProduct,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] });
      toast.success('Producto eliminado con éxito');
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al eliminar el producto';
      toast.error(msg);
    }
  });

  const openCreateModal = () => {
    setEditingProduct(null);
    setName('');
    setBrand('');
    setPrice('');
    setSalePrice('');
    setStock('');
    setCategoryId('');
    setDescription('');
    setIsActive(true);
    setImageFile(null);
    setImagePreview(null);
    setSpecsList([]);
    setIsModalOpen(true);
  };

  const openEditModal = (product: Product) => {
    setEditingProduct(product);
    setName(product.name);
    setBrand(product.brand || '');
    setPrice(product.price.toString());
    setSalePrice(product.sale_price ? product.sale_price.toString() : '');
    setStock(product.stock.toString());
    setCategoryId(product.category_id || '');
    setDescription(product.description || '');
    setIsActive(product.is_active);
    setImageFile(null);
    setImagePreview(product.thumbnail_url);
    
    // Map specs record to array [{key, value}]
    if (product.specs) {
      const mapped = Object.entries(product.specs).map(([key, value]) => ({
        key,
        value: value.toString()
      }));
      setSpecsList(mapped);
    } else {
      setSpecsList([]);
    }
    
    setIsModalOpen(true);
  };

  const closeModal = () => {
    setIsModalOpen(false);
    setEditingProduct(null);
  };

  // Image change handler
  const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      setImageFile(file);
      setImagePreview(URL.createObjectURL(file));
    }
  };

  // Specs helpers
  const addSpecRow = () => {
    setSpecsList([...specsList, { key: '', value: '' }]);
  };

  const removeSpecRow = (index: number) => {
    setSpecsList(specsList.filter((_, i) => i !== index));
  };

  const updateSpecRow = (index: number, field: 'key' | 'value', val: string) => {
    const updated = [...specsList];
    updated[index][field] = val;
    setSpecsList(updated);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim() || !price || !stock) {
      toast.error('Nombre, precio y stock son obligatorios.');
      return;
    }

    if (salePrice && Number(salePrice) >= Number(price)) {
      toast.error('El precio de oferta debe ser menor que el precio regular.');
      return;
    }

    const formData = new FormData();
    formData.append('name', name);
    formData.append('brand', brand);
    formData.append('price', price);
    formData.append('sale_price', salePrice || '');
    formData.append('stock', stock);
    formData.append('category_id', categoryId);
    formData.append('description', description);
    formData.append('is_active', isActive ? '1' : '0');

    if (imageFile) {
      formData.append('image', imageFile);
    }

    // Convert specs array back to JSON object
    const specsObj: Record<string, string> = {};
    specsList.forEach((item) => {
      if (item.key.trim() && item.value.trim()) {
        specsObj[item.key.trim()] = item.value.trim();
      }
    });
    
    // Append specs as JSON string or key value
    formData.append('specs', JSON.stringify(specsObj));

    if (editingProduct) {
      updateMutation.mutate({ id: editingProduct.id, formData });
    } else {
      createMutation.mutate(formData);
    }
  };

  const handleImportSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!importFile) {
      toast.error('Por favor, selecciona un archivo CSV.');
      return;
    }

    setIsImporting(true);
    setImportReport(null);

    try {
      const report = await importProductsCsv(importFile);
      setImportReport(report);
      queryClient.invalidateQueries({ queryKey: ['products'] });
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      
      if (report.errors.length === 0) {
        toast.success(report.message);
        setIsImportModalOpen(false);
        setImportFile(null);
      } else {
        toast.error(`Importación parcial: ${report.success_count} productos cargados con éxito.`);
      }
    } catch (err: any) {
      console.error(err);
      const msg = err.response?.data?.message || 'Error al importar los productos.';
      toast.error(msg);
    } finally {
      setIsImporting(false);
    }
  };

  const handleDownloadTemplate = () => {
    const headers = "nombre;marca;precio;precio_oferta;stock;categoria;descripcion;especificaciones\n";
    const row = "Intel Core i7-14700K;Intel;409.99;389.99;15;Procesadores;Procesador de alto rendimiento para socket LGA1700;Frecuencia:3.4 GHz;Núcleos:20\n";
    const blob = new Blob([headers + row], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", "plantilla_productos.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

  const handleDelete = (id: string, name: string) => {
    if (window.confirm(`¿Seguro que deseas eliminar el producto "${name}"? Esta acción no se puede deshacer.`)) {
      deleteMutation.mutate(id);
    }
  };

  const isSubmitting = createMutation.isPending || updateMutation.isPending;

  return (
    <div className="products-page animate-fade-in">
      {/* Filters bar */}
      <div className="filters-bar glass-card">
        <div className="search-box">
          <Search size={18} className="search-icon" />
          <input
            type="text"
            placeholder="Buscar productos por nombre..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="premium-input"
          />
        </div>

        <select
          value={selectedCategory}
          onChange={(e) => {
            setSelectedCategory(e.target.value);
            setPage(1);
          }}
          className="premium-input select-filter"
        >
          <option value="">Todas las Categorías</option>
          {categories.map((cat) => (
            <option key={cat.id} value={cat.id}>
              {cat.name}
            </option>
          ))}
        </select>

        <div className="action-buttons-group" style={{ display: 'flex', gap: '0.5rem' }}>
          <button onClick={() => setIsImportModalOpen(true)} className="btn-secondary import-product-btn">
            <Upload size={18} />
            <span>Importar CSV</span>
          </button>

          <button onClick={openCreateModal} className="btn-primary add-product-btn">
            <Plus size={18} />
            <span>Nuevo Producto</span>
          </button>
        </div>
      </div>

      {/* Products list */}
      {isLoading ? (
        <div className="inner-loader">
          <Loader2 className="spinner" size={32} />
          <p>Cargando productos...</p>
        </div>
      ) : products.length === 0 ? (
        <div className="empty-state glass-card">
          <ImageIcon size={48} className="empty-icon" />
          <h3>No se encontraron productos</h3>
          <p>Crea un producto o ajusta los filtros de búsqueda.</p>
          <button onClick={openCreateModal} className="btn-primary">
            <Plus size={18} />
            <span>Crear Producto</span>
          </button>
        </div>
      ) : (
        <>
          <div className="table-container glass-card">
            <table className="premium-table">
              <thead>
                <tr>
                  <th>Imagen</th>
                  <th>Producto</th>
                  <th>Categoría</th>
                  <th>Precio</th>
                  <th>Stock</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                {products.map((product) => (
                  <tr key={product.id}>
                    <td>
                      <div className="table-img-wrapper">
                        {product.thumbnail_url ? (
                          <img src={product.thumbnail_url} alt={product.name} />
                        ) : (
                          <ImageIcon size={18} className="placeholder-icon" />
                        )}
                      </div>
                    </td>
                    <td>
                      <div className="product-info-cell">
                        <span className="product-name">{product.name}</span>
                        {product.brand && <span className="product-brand">{product.brand}</span>}
                      </div>
                    </td>
                    <td>
                      <span className="category-tag">
                        {product.category?.name || 'Sin Categoría'}
                      </span>
                    </td>
                    <td className="price-cell">
                      {product.sale_price !== null && product.sale_price !== undefined ? (
                        <div className="admin-price-box">
                          <span className="strike-price" style={{ textDecoration: 'line-through', opacity: 0.5, fontSize: '0.85em', marginRight: '0.4rem', fontWeight: 'normal' }}>
                            ${parseFloat(product.price.toString()).toFixed(2)}
                          </span>
                          <span className="sale-price-active" style={{ color: 'var(--success)', fontWeight: 700 }}>
                            ${parseFloat(product.sale_price.toString()).toFixed(2)}
                          </span>
                        </div>
                      ) : (
                        `$${parseFloat(product.price.toString()).toFixed(2)}`
                      )}
                    </td>
                    <td>
                      <div className="stock-cell">
                        <span className={`stock-number ${product.stock === 0 ? 'out' : product.stock < 5 ? 'low' : ''}`}>
                          {product.stock}
                        </span>
                        <span className="stock-label">{product.stock === 0 ? 'Sin Stock' : product.stock < 5 ? 'Poco Stock' : 'Disponible'}</span>
                      </div>
                    </td>
                    <td>
                      {product.is_active ? (
                        <span className="badge badge-success"><Eye size={12} style={{marginRight: '3.5px'}} /> Visible</span>
                      ) : (
                        <span className="badge badge-danger"><EyeOff size={12} style={{marginRight: '3.5px'}} /> Oculto</span>
                      )}
                    </td>
                    <td>
                      <div className="table-actions">
                        <button onClick={() => openEditModal(product)} className="table-action-btn edit" title="Editar">
                          <Edit2 size={15} />
                        </button>
                        <button onClick={() => handleDelete(product.id, product.name)} className="table-action-btn delete" title="Eliminar">
                          <Trash2 size={15} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="pagination-bar">
              <button 
                onClick={() => setPage(page - 1)} 
                disabled={page === 1}
                className="btn-secondary pag-btn"
              >
                <ChevronLeft size={16} />
              </button>
              <span className="page-indicator">Página {page} de {totalPages}</span>
              <button 
                onClick={() => setPage(page + 1)} 
                disabled={page === totalPages}
                className="btn-secondary pag-btn"
              >
                <ChevronRight size={16} />
              </button>
            </div>
          )}
        </>
      )}

      {/* Modal Drawer */}
      {isModalOpen && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal-drawer glass-card animate-slide-up wide-drawer" onClick={(e) => e.stopPropagation()}>
            <div className="drawer-header">
              <h3>{editingProduct ? 'Editar Producto' : 'Nuevo Producto'}</h3>
              <button onClick={closeModal} className="drawer-close">
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleSubmit} className="drawer-form scrollable-form">
              
              {/* Image Uploader */}
              <div className="form-group image-upload-group">
                <label>Imagen del Producto</label>
                <div className="image-dropzone">
                  {imagePreview ? (
                    <div className="preview-container">
                      <img src={imagePreview} alt="Vista previa" />
                      <button type="button" className="remove-preview" onClick={() => { setImageFile(null); setImagePreview(null); }}>
                        <X size={14} />
                      </button>
                    </div>
                  ) : (
                    <label className="dropzone-label">
                      <ImageIcon size={28} className="dropzone-icon" />
                      <span>Subir imagen (.png, .jpg, .webp)</span>
                      <span className="file-limit">Máx 5MB</span>
                      <input type="file" accept="image/*" onChange={handleImageChange} style={{display: 'none'}} />
                    </label>
                  )}
                </div>
              </div>

              <div className="form-group">
                <label htmlFor="prod-name">Nombre del Producto</label>
                <input
                  id="prod-name"
                  type="text"
                  placeholder="ej. Memoria RAM Kingston Fury 16GB"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="premium-input"
                  required
                />
              </div>

              <div className="form-row">
                <div className="form-group half">
                  <label htmlFor="prod-brand">Marca</label>
                  <input
                    id="prod-brand"
                    type="text"
                    placeholder="ej. Kingston"
                    value={brand}
                    onChange={(e) => setBrand(e.target.value)}
                    className="premium-input"
                  />
                </div>

                <div className="form-group half">
                  <label htmlFor="prod-cat">Categoría</label>
                  <select
                    id="prod-cat"
                    value={categoryId}
                    onChange={(e) => setCategoryId(e.target.value)}
                    className="premium-input select-input"
                  >
                    <option value="">Sin Categoría</option>
                    {categories.map((cat) => (
                      <option key={cat.id} value={cat.id}>
                        {cat.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="form-row">
                <div className="form-group half">
                  <label htmlFor="prod-price">Precio Regular ($ USD)</label>
                  <input
                    id="prod-price"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    value={price}
                    onChange={(e) => setPrice(e.target.value)}
                    className="premium-input"
                    required
                  />
                </div>

                <div className="form-group half">
                  <label htmlFor="prod-saleprice">Precio de Oferta ($ USD - Opcional)</label>
                  <input
                    id="prod-saleprice"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    value={salePrice}
                    onChange={(e) => setSalePrice(e.target.value)}
                    className="premium-input"
                  />
                </div>
              </div>

              <div className="form-row">
                <div className="form-group half">
                  <label htmlFor="prod-stock">Stock disponible</label>
                  <input
                    id="prod-stock"
                    type="number"
                    min="0"
                    placeholder="0"
                    value={stock}
                    onChange={(e) => setStock(e.target.value)}
                    className="premium-input"
                    required
                  />
                </div>
                <div className="form-group half">
                  {/* Espacio vacío para grilla */}
                </div>
              </div>

              <div className="form-group">
                <label htmlFor="prod-desc">Descripción</label>
                <textarea
                  id="prod-desc"
                  rows={4}
                  placeholder="Describe las características principales del componente..."
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  className="premium-input textarea-input"
                />
              </div>

              {/* Specs Editor */}
              <div className="form-group specs-editor-group">
                <div className="specs-header">
                  <label>Especificaciones Técnicas</label>
                  <button type="button" onClick={addSpecRow} className="add-spec-btn">
                    <PlusCircle size={15} />
                    <span>Añadir</span>
                  </button>
                </div>
                
                {specsList.length === 0 ? (
                  <p className="no-specs-text">No hay especificaciones. Añade filas para detallar cores, frecuencia, capacidad, etc.</p>
                ) : (
                  <div className="specs-list">
                    {specsList.map((spec, idx) => (
                      <div key={idx} className="spec-row">
                        <input
                          type="text"
                          placeholder="Propiedad (ej. Frecuencia)"
                          value={spec.key}
                          onChange={(e) => updateSpecRow(idx, 'key', e.target.value)}
                          className="premium-input spec-key"
                        />
                        <input
                          type="text"
                          placeholder="Valor (ej. 3200 MHz)"
                          value={spec.value}
                          onChange={(e) => updateSpecRow(idx, 'value', e.target.value)}
                          className="premium-input spec-value"
                        />
                        <button type="button" onClick={() => removeSpecRow(idx)} className="remove-spec-row">
                          <MinusCircle size={18} />
                        </button>
                      </div>
                    ))}
                  </div>
                )}
              </div>

              <div className="form-group">
                <label>Visibilidad del Catálogo</label>
                <div className="toggle-switch-wrapper">
                  <input
                    id="prod-active"
                    type="checkbox"
                    checked={isActive}
                    onChange={(e) => setIsActive(e.target.checked)}
                    className="toggle-checkbox"
                  />
                  <label htmlFor="prod-active" className="toggle-label"></label>
                  <span className="toggle-text">{isActive ? 'Público en el catálogo' : 'Oculto / Borrador'}</span>
                </div>
              </div>

              <div className="drawer-actions">
                <button type="button" onClick={closeModal} className="btn-secondary">
                  Cancelar
                </button>
                <button type="submit" disabled={isSubmitting} className="btn-primary">
                  {isSubmitting ? <Loader2 className="spinner" size={16} /> : null}
                  {editingProduct ? 'Guardar Cambios' : 'Crear Producto'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {isImportModalOpen && (
        <div className="modal-overlay" onClick={() => { setIsImportModalOpen(false); setImportReport(null); setImportFile(null); }}>
          <div className="modal-content glass-card animate-scale-in" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '650px' }}>
            <header className="modal-header">
              <div>
                <h3>Importación Masiva de Productos (CSV)</h3>
                <p style={{ fontSize: '0.85rem', color: 'var(--text-secondary)', marginTop: '0.15rem' }}>
                  Sube una plantilla CSV para crear o actualizar componentes rápidamente.
                </p>
              </div>
              <button 
                className="close-btn" 
                onClick={() => { setIsImportModalOpen(false); setImportReport(null); setImportFile(null); }} 
                aria-label="Cerrar modal"
              >
                <X size={20} />
              </button>
            </header>

            <form onSubmit={handleImportSubmit} className="modal-body" style={{ display: 'flex', flexDirection: 'column', gap: '1.25rem' }}>
              <div className="customer-summary-card" style={{ fontSize: '0.85rem', color: 'var(--text-secondary)', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                <h4 style={{ fontSize: '0.9rem', color: 'var(--text-primary)', margin: 0, fontWeight: 600 }}>Formato y Columnas Permitidas</h4>
                <p>El archivo debe estar codificado en UTF-8 y tener como cabecera (primera fila):</p>
                <code style={{ background: 'rgba(255,255,255,0.03)', padding: '0.4rem 0.6rem', borderRadius: '4px', fontFamily: 'monospace', color: 'var(--primary)', display: 'block', wordBreak: 'break-all' }}>
                  nombre;marca;precio;precio_oferta;stock;categoria;descripcion;especificaciones
                </code>
                <ul style={{ paddingLeft: '1.2rem', display: 'flex', flexDirection: 'column', gap: '0.25rem', marginTop: '0.25rem' }}>
                  <li><strong>nombre</strong>, <strong>precio</strong> y <strong>stock</strong> son obligatorios.</li>
                  <li><strong>categoria</strong>: si no existe en la tienda, se creará automáticamente.</li>
                  <li><strong>especificaciones</strong>: formato de clave:valor separados por punto y coma (ej: <code>Frecuencia:3.2 GHz;Núcleos:16</code>).</li>
                </ul>
                
                <button 
                  type="button" 
                  onClick={handleDownloadTemplate} 
                  className="btn-secondary" 
                  style={{ alignSelf: 'flex-start', marginTop: '0.5rem', padding: '0.4rem 0.75rem', fontSize: '0.8rem' }}
                >
                  <Upload size={14} style={{ transform: 'rotate(180deg)', marginRight: '4px' }} /> Descargar Plantilla Modelo CSV
                </button>
              </div>

              <div className="form-group" style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                <label style={{ fontWeight: 600, fontSize: '0.9rem', color: 'var(--text-primary)' }}>Selecciona el archivo (.csv)</label>
                <input 
                  type="file" 
                  accept=".csv,text/csv" 
                  className="premium-input" 
                  onChange={(e) => {
                    const files = e.target.files;
                    if (files && files.length > 0) {
                      setImportFile(files[0]);
                      setImportReport(null);
                    }
                  }}
                  required
                />
              </div>

              {/* Import Results Report */}
              {importReport && (
                <div className="customer-summary-card" style={{ display: 'flex', flexDirection: 'column', gap: '0.6rem', maxHeight: '200px', overflowY: 'auto' }}>
                  <h4 style={{ color: importReport.errors.length > 0 ? 'var(--warning)' : 'var(--success)', margin: 0, fontWeight: 600, display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
                    <AlertCircle size={16} />
                    {importReport.errors.length > 0 ? 'Importación Parcial / Errores Detectados' : 'Importación Exitosa'}
                  </h4>
                  <p style={{ fontSize: '0.85rem', fontWeight: 500 }}>{importReport.message}</p>
                  
                  {importReport.errors.length > 0 && (
                    <div style={{ marginTop: '0.25rem', display: 'flex', flexDirection: 'column', gap: '0.25rem' }}>
                      <span style={{ fontSize: '0.8rem', fontWeight: 700, color: 'var(--text-primary)' }}>Errores por fila:</span>
                      <ul style={{ paddingLeft: '1.2rem', color: 'var(--danger)', fontSize: '0.8rem', display: 'flex', flexDirection: 'column', gap: '0.2rem' }}>
                        {importReport.errors.map((err, idx) => (
                          <li key={idx}>{err}</li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              )}

              <div className="modal-actions-bar" style={{ display: 'flex', gap: '0.75rem', marginTop: '0.5rem' }}>
                <button 
                  type="button" 
                  onClick={() => { setIsImportModalOpen(false); setImportReport(null); setImportFile(null); }} 
                  className="btn-secondary"
                  style={{ flex: 1 }}
                >
                  Cancelar
                </button>
                <button 
                  type="submit" 
                  disabled={isImporting} 
                  className="btn-primary"
                  style={{ flex: 1 }}
                >
                  {isImporting ? <Loader2 className="spinner" size={16} /> : <Upload size={16} />}
                  <span>{isImporting ? 'Importando...' : 'Iniciar Importación'}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <style>{`
        .products-page {
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
        }

        /* Filters Bar */
        .filters-bar {
          padding: 1rem 1.5rem;
          display: flex;
          gap: 1rem;
          align-items: center;
        }

        .search-box {
          position: relative;
          flex: 1;
          display: flex;
          align-items: center;
        }

        .search-icon {
          position: absolute;
          left: 1rem;
          color: var(--text-muted);
        }

        .search-box input {
          padding-left: 2.75rem;
        }

        .select-filter {
          width: 220px;
          cursor: pointer;
        }

        .select-filter option {
          background-color: var(--bg-sidebar);
          color: var(--text-primary);
        }

        .add-product-btn {
          padding: 0.75rem 1.25rem;
        }

        /* Table Styles */
        .table-container {
          overflow-x: auto;
          border-radius: var(--radius-lg);
        }

        .premium-table {
          width: 100%;
          border-collapse: collapse;
          text-align: left;
          font-size: 0.9rem;
        }

        .premium-table th {
          padding: 1rem 1.5rem;
          border-bottom: 1px solid var(--border);
          color: var(--text-secondary);
          font-weight: 600;
          font-size: 0.8rem;
          text-transform: uppercase;
          letter-spacing: 0.05em;
        }

        .premium-table td {
          padding: 1.15rem 1.5rem;
          border-bottom: 1px solid var(--border);
          color: var(--text-primary);
        }

        .premium-table tbody tr {
          transition: var(--transition);
        }

        .premium-table tbody tr:hover {
          background: rgba(255, 255, 255, 0.015);
        }

        .table-img-wrapper {
          width: 44px;
          height: 44px;
          border-radius: 8px;
          background: rgba(255, 255, 255, 0.03);
          border: 1px solid var(--border);
          display: flex;
          align-items: center;
          justify-content: center;
          overflow: hidden;
          color: var(--text-muted);
        }

        .table-img-wrapper img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .product-info-cell {
          display: flex;
          flex-direction: column;
        }

        .product-name {
          font-weight: 500;
          color: var(--text-primary);
        }

        .product-brand {
          font-size: 0.75rem;
          color: var(--text-muted);
          margin-top: 0.1rem;
        }

        .category-tag {
          display: inline-block;
          font-size: 0.75rem;
          font-weight: 500;
          background: rgba(255, 255, 255, 0.03);
          border: 1px solid var(--border);
          color: var(--text-secondary);
          padding: 0.25rem 0.6rem;
          border-radius: 6px;
        }

        .price-cell {
          font-weight: 600;
          color: white;
        }

        .stock-cell {
          display: flex;
          flex-direction: column;
        }

        .stock-number {
          font-weight: 600;
          color: var(--success);
        }

        .stock-number.low {
          color: var(--warning);
        }

        .stock-number.out {
          color: var(--danger);
        }

        .stock-label {
          font-size: 0.7rem;
          color: var(--text-muted);
          margin-top: 0.1rem;
        }

        .table-actions {
          display: flex;
          gap: 0.5rem;
        }

        .table-action-btn {
          background: transparent;
          border: 1px solid var(--border);
          width: 32px;
          height: 32px;
          border-radius: 6px;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          color: var(--text-secondary);
          transition: var(--transition);
        }

        .table-action-btn.edit:hover {
          background: rgba(37, 99, 235, 0.08);
          border-color: var(--primary);
          color: var(--primary);
        }

        .table-action-btn.delete:hover {
          background: rgba(239, 68, 68, 0.08);
          border-color: var(--danger);
          color: var(--danger);
        }

        /* Pagination styles */
        .pagination-bar {
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 1.5rem;
          margin-top: 0.5rem;
        }

        .pag-btn {
          width: 38px;
          height: 38px;
          padding: 0;
          border-radius: 50%;
        }

        .page-indicator {
          font-size: 0.85rem;
          color: var(--text-secondary);
        }

        /* Drawer Overlay */
        .modal-overlay {
          position: fixed;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          background: rgba(3, 7, 18, 0.6);
          backdrop-filter: blur(4px);
          z-index: 100;
          display: flex;
          justify-content: flex-end;
        }

        .modal-drawer.wide-drawer {
          max-width: 560px;
        }

        .scrollable-form {
          flex: 1;
          overflow-y: auto;
          padding-right: 0.25rem;
        }

        /* Image Dropzone styles */
        .image-upload-group label {
          margin-bottom: 0.5rem;
          display: block;
        }

        .image-dropzone {
          border: 2px dashed var(--border);
          border-radius: var(--radius-lg);
          padding: 1rem;
          text-align: center;
          transition: var(--transition);
          background: rgba(255, 255, 255, 0.01);
        }

        .image-dropzone:hover {
          border-color: var(--primary);
          background: rgba(37, 99, 235, 0.02);
        }

        .dropzone-label {
          display: flex;
          flex-direction: column;
          align-items: center;
          gap: 0.35rem;
          cursor: pointer;
          color: var(--text-secondary);
          font-size: 0.85rem;
          padding: 1rem 0;
        }

        .dropzone-icon {
          color: var(--text-muted);
          margin-bottom: 0.25rem;
        }

        .file-limit {
          font-size: 0.7rem;
          color: var(--text-muted);
        }

        .preview-container {
          position: relative;
          width: 140px;
          height: 140px;
          margin: 0 auto;
          border-radius: 10px;
          overflow: hidden;
          border: 1px solid var(--border);
        }

        .preview-container img {
          width: 100%;
          height: 100%;
          object-fit: cover;
        }

        .remove-preview {
          position: absolute;
          top: 0.35rem;
          right: 0.35rem;
          background: rgba(3, 7, 18, 0.75);
          border: none;
          color: white;
          width: 24px;
          height: 24px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          transition: var(--transition);
        }

        .remove-preview:hover {
          background: #ef4444;
        }

        .textarea-input {
          resize: vertical;
          min-height: 80px;
        }

        /* Specs Editor */
        .specs-editor-group {
          background: rgba(255, 255, 255, 0.01);
          border: 1px solid var(--border);
          border-radius: var(--radius-lg);
          padding: 1.25rem;
        }

        .specs-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 1rem;
        }

        .specs-header label {
          font-weight: 500;
          font-size: 0.9rem;
        }

        .add-spec-btn {
          display: flex;
          align-items: center;
          gap: 0.35rem;
          background: transparent;
          border: none;
          color: var(--primary);
          cursor: pointer;
          font-size: 0.85rem;
          font-weight: 600;
          transition: var(--transition);
        }

        .add-spec-btn:hover {
          color: var(--primary-hover);
        }

        .no-specs-text {
          font-size: 0.8rem;
          color: var(--text-muted);
          text-align: center;
          padding: 0.5rem 0;
        }

        .specs-list {
          display: flex;
          flex-direction: column;
          gap: 0.75rem;
        }

        .spec-row {
          display: flex;
          gap: 0.5rem;
          align-items: center;
        }

        .spec-key {
          flex: 1;
        }

        .spec-value {
          flex: 1.5;
        }

        .remove-spec-row {
          background: transparent;
          border: none;
          color: var(--text-muted);
          cursor: pointer;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 0.25rem;
          transition: var(--transition);
        }

        .remove-spec-row:hover {
          color: var(--danger);
        }

        /* Toggle switches style */
        .toggle-switch-wrapper {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          margin-top: 0.35rem;
        }

        .toggle-checkbox {
          display: none;
        }

        .toggle-label {
          width: 44px;
          height: 24px;
          background: var(--border);
          border-radius: 50px;
          position: relative;
          cursor: pointer;
          transition: var(--transition);
        }

        .toggle-label::after {
          content: '';
          position: absolute;
          width: 18px;
          height: 18px;
          background: var(--text-secondary);
          border-radius: 50%;
          top: 3px;
          left: 3px;
          transition: var(--transition);
        }

        .toggle-checkbox:checked + .toggle-label {
          background: var(--primary);
        }

        .toggle-checkbox:checked + .toggle-label::after {
          left: 23px;
          background: white;
        }

        .toggle-text {
          font-size: 0.9rem;
          color: var(--text-secondary);
        }

        /* Modal styling */
        .modal-overlay {
          position: fixed;
          inset: 0;
          background: rgba(0, 0, 0, 0.5);
          z-index: 150;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 1.5rem;
          backdrop-filter: blur(4px);
        }

        .modal-content {
          width: 100%;
          background: var(--glass-bg);
          border: 1px solid var(--glass-border);
          box-shadow: var(--shadow-xl);
          border-radius: var(--radius-lg);
          display: flex;
          flex-direction: column;
          max-height: 90vh;
        }

        .modal-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding: 1.25rem 1.5rem;
          border-bottom: 1px solid var(--border);
        }

        .modal-header h3 {
          font-size: 1.15rem;
          color: var(--text-primary);
          margin-bottom: 0.25rem;
        }

        .close-btn {
          background: transparent;
          border: none;
          color: var(--text-secondary);
          cursor: pointer;
          display: flex;
        }

        .modal-body {
          padding: 1.5rem;
          overflow-y: auto;
          display: flex;
          flex-direction: column;
        }

        .customer-summary-card {
          background: rgba(255, 255, 255, 0.015);
          border: 1px solid var(--border);
          border-radius: var(--radius-md);
          padding: 1.25rem;
        }

        .customer-summary-card h4 {
          font-size: 0.95rem;
          color: var(--text-primary);
          margin-bottom: 1rem;
          font-family: var(--font-heading);
        }

        .modal-actions-bar {
          display: flex;
          gap: 0.75rem;
          margin-top: 0.5rem;
        }

        @media (max-width: 768px) {
          .filters-bar {
            flex-direction: column;
            align-items: stretch;
          }
          .select-filter {
            width: 100%;
          }
        }
      `}</style>
    </div>
  );
}
