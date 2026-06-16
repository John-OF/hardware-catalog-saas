import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { 
  Plus, 
  Edit2, 
  Trash2, 
  X, 
  FolderPlus, 
  Sliders, 
  Eye, 
  EyeOff, 
  Loader2 
} from 'lucide-react';
import {
  getCategories,
  createCategory,
  updateCategory,
  deleteCategory
} from '../../api/categories';
import CategoryIcon from '../../components/ui/CategoryIcon';
import type { Category } from '../../types';

export default function CategoriesPage() {
  const queryClient = useQueryClient();
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingCategory, setEditingCategory] = useState<Category | null>(null);

  // Form states
  const [name, setName] = useState('');
  const [icon, setIcon] = useState('folder');
  const [sortOrder, setSortOrder] = useState('0');
  const [isActive, setIsActive] = useState(true);

  // Fetch categories
  const { data: categories = [], isLoading } = useQuery<Category[]>({
    queryKey: ['categories'],
    queryFn: getCategories,
  });

  // Mutación para Crear
  const createMutation = useMutation({
    mutationFn: createCategory,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      toast.success('Categoría creada con éxito');
      closeModal();
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al crear la categoría';
      toast.error(msg);
    }
  });

  // Mutación para Editar
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<Category> }) => 
      updateCategory(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      toast.success('Categoría actualizada con éxito');
      closeModal();
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al actualizar la categoría';
      toast.error(msg);
    }
  });

  // Mutación para Eliminar
  const deleteMutation = useMutation({
    mutationFn: deleteCategory,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      toast.success('Categoría eliminada');
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al eliminar la categoría';
      toast.error(msg);
    }
  });

  const openCreateModal = () => {
    setEditingCategory(null);
    setName('');
    setIcon('folder');
    setSortOrder('0');
    setIsActive(true);
    setIsModalOpen(true);
  };

  const openEditModal = (category: Category) => {
    setEditingCategory(category);
    setName(category.name);
    setIcon(category.icon || 'folder');
    setSortOrder(category.sort_order.toString());
    setIsActive(category.is_active);
    setIsModalOpen(true);
  };

  const closeModal = () => {
    setIsModalOpen(false);
    setEditingCategory(null);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) {
      toast.error('El nombre es obligatorio.');
      return;
    }

    const payload = {
      name,
      icon: icon || 'folder',
      sort_order: parseInt(sortOrder) || 0,
      is_active: isActive
    };

    if (editingCategory) {
      updateMutation.mutate({ id: editingCategory.id, data: payload });
    } else {
      createMutation.mutate(payload);
    }
  };

  const handleDelete = (id: string, name: string) => {
    if (window.confirm(`¿Seguro que deseas eliminar la categoría "${name}"? Los productos asociados quedarán sin categoría.`)) {
      deleteMutation.mutate(id);
    }
  };

  const isSubmitting = createMutation.isPending || updateMutation.isPending;

  return (
    <div className="categories-page animate-fade-in">
      <div className="page-header-actions">
        <p className="page-description">Organiza tus componentes de PC para que los clientes exploren tu catálogo fácilmente.</p>
        <button onClick={openCreateModal} className="btn-primary">
          <Plus size={18} />
          <span>Nueva Categoría</span>
        </button>
      </div>

      {isLoading ? (
        <div className="inner-loader">
          <Loader2 className="spinner" size={32} />
          <p>Cargando categorías...</p>
        </div>
      ) : categories.length === 0 ? (
        <div className="empty-state glass-card">
          <FolderPlus size={48} className="empty-icon" />
          <h3>No hay categorías creadas</h3>
          <p>Crea tu primera categoría (ej. Procesadores, Tarjetas Gráficas) para empezar a clasificar tus componentes.</p>
          <button onClick={openCreateModal} className="btn-primary">
            <Plus size={18} />
            <span>Crear Categoría</span>
          </button>
        </div>
      ) : (
        <div className="categories-grid">
          {categories.map((category) => (
            <div key={category.id} className="category-card glass-card">
              <div className="category-card-header">
                <div className="category-icon-sphere">
                  <CategoryIcon slug={category.icon} size={28} />
                </div>
                <div className="category-status">
                  {category.is_active ? (
                    <span className="badge badge-success"><Eye size={12} style={{marginRight: '3px'}} /> Activo</span>
                  ) : (
                    <span className="badge badge-danger"><EyeOff size={12} style={{marginRight: '3px'}} /> Inactivo</span>
                  )}
                </div>
              </div>

              <div className="category-card-body">
                <h3>{category.name}</h3>
                <div className="category-meta">
                  <div className="meta-item">
                    <Sliders size={14} />
                    <span>Orden: {category.sort_order}</span>
                  </div>
                </div>
              </div>

              <div className="category-card-actions">
                <button onClick={() => openEditModal(category)} className="action-btn edit" title="Editar">
                  <Edit2 size={16} />
                </button>
                <button onClick={() => handleDelete(category.id, category.name)} className="action-btn delete" title="Eliminar">
                  <Trash2 size={16} />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Modal Drawer */}
      {isModalOpen && (
        <div className="modal-overlay" onClick={closeModal}>
          <div className="modal-drawer glass-card animate-slide-up" onClick={(e) => e.stopPropagation()}>
            <div className="drawer-header">
              <h3>{editingCategory ? 'Editar Categoría' : 'Nueva Categoría'}</h3>
              <button onClick={closeModal} className="drawer-close">
                <X size={20} />
              </button>
            </div>

            <form onSubmit={handleSubmit} className="drawer-form">
              <div className="form-group">
                <label htmlFor="cat-name">Nombre de Categoría</label>
                <input
                  id="cat-name"
                  type="text"
                  placeholder="ej. Tarjetas de Video"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className="premium-input"
                  required
                />
              </div>

              <div className="form-group">
                <label htmlFor="cat-icon">Icono Relacionado</label>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
                  <span
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      width: '42px',
                      height: '42px',
                      flexShrink: 0,
                      borderRadius: 'var(--radius-md)',
                      border: '1px solid var(--border-color, rgba(255,255,255,0.08))',
                      color: 'var(--primary)',
                    }}
                  >
                    <CategoryIcon slug={icon} size={20} />
                  </span>
                  <select
                    id="cat-icon"
                    value={icon}
                    onChange={(e) => setIcon(e.target.value)}
                    className="premium-input select-input"
                    style={{ flex: 1 }}
                  >
                    <option value="cpu">Procesadores (CPU)</option>
                    <option value="gpu">Tarjetas de Video (GPU)</option>
                    <option value="ram">Memorias RAM</option>
                    <option value="motherboard">Placas Madre (Motherboard)</option>
                    <option value="ssd">Almacenamiento (SSD/HDD)</option>
                    <option value="power">Fuentes de Poder</option>
                    <option value="case">Gabinetes / Chasis</option>
                    <option value="cooling">Enfriamiento / Disipadores</option>
                    <option value="monitor">Monitores</option>
                    <option value="peripheral">Periféricos (Teclado/Mouse)</option>
                    <option value="folder">Genérico / Otros</option>
                  </select>
                </div>
              </div>

              <div className="form-row">
                <div className="form-group half">
                  <label htmlFor="cat-order">Orden de Visualización</label>
                  <input
                    id="cat-order"
                    type="number"
                    min="0"
                    placeholder="0"
                    value={sortOrder}
                    onChange={(e) => setSortOrder(e.target.value)}
                    className="premium-input"
                  />
                </div>

                <div className="form-group half checkbox-group">
                  <label htmlFor="cat-active">Estado</label>
                  <div className="toggle-switch-wrapper">
                    <input
                      id="cat-active"
                      type="checkbox"
                      checked={isActive}
                      onChange={(e) => setIsActive(e.target.checked)}
                      className="toggle-checkbox"
                    />
                    <label htmlFor="cat-active" className="toggle-label"></label>
                    <span className="toggle-text">{isActive ? 'Visible' : 'Oculto'}</span>
                  </div>
                </div>
              </div>

              <div className="drawer-actions">
                <button type="button" onClick={closeModal} className="btn-secondary">
                  Cancelar
                </button>
                <button type="submit" disabled={isSubmitting} className="btn-primary">
                  {isSubmitting ? <Loader2 className="spinner" size={16} /> : null}
                  {editingCategory ? 'Guardar Cambios' : 'Crear Categoría'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      <style>{`
        .categories-page {
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
        }

        .page-header-actions {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 1.5rem;
        }

        .page-description {
          color: var(--text-secondary);
          font-size: 0.95rem;
          max-width: 600px;
        }

        .inner-loader {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          padding: 6rem 0;
          color: var(--text-secondary);
          gap: 1rem;
        }

        .empty-state {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          text-align: center;
          padding: 5rem 2rem;
          gap: 1rem;
          max-width: 600px;
          margin: 2rem auto;
        }

        .empty-icon {
          color: var(--text-muted);
        }

        .empty-state h3 {
          font-size: 1.25rem;
          color: var(--text-primary);
        }

        .empty-state p {
          color: var(--text-secondary);
          font-size: 0.9rem;
          margin-bottom: 0.5rem;
        }

        .categories-grid {
          display: grid;
          grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
          gap: 1.5rem;
        }

        .category-card {
          padding: 1.5rem;
          display: flex;
          flex-direction: column;
          gap: 1.25rem;
        }

        .category-card-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
        }

        .category-icon-sphere {
          width: 44px;
          height: 44px;
          border-radius: 50%;
          background: rgba(255, 255, 255, 0.03);
          border: 1px solid var(--border);
          display: flex;
          align-items: center;
          justify-content: center;
          box-shadow: inset 0 0 10px rgba(255, 255, 255, 0.05);
          color: var(--primary);
        }

        .category-card-body h3 {
          font-size: 1.15rem;
          font-family: var(--font-heading);
          color: var(--text-primary);
          margin-bottom: 0.35rem;
        }

        .category-meta {
          display: flex;
          gap: 1rem;
          color: var(--text-secondary);
          font-size: 0.85rem;
        }

        .meta-item {
          display: flex;
          align-items: center;
          gap: 0.35rem;
        }

        .category-card-actions {
          display: flex;
          gap: 0.75rem;
          margin-top: auto;
          border-top: 1px solid var(--border);
          padding-top: 1rem;
        }

        .action-btn {
          flex: 1;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 0.6rem;
          border-radius: var(--radius-md);
          cursor: pointer;
          border: 1px solid var(--border);
          background: transparent;
          color: var(--text-secondary);
          transition: var(--transition);
        }

        .action-btn.edit:hover {
          background: rgba(37, 99, 235, 0.08);
          border-color: var(--primary);
          color: var(--primary);
        }

        .action-btn.delete:hover {
          background: rgba(239, 68, 68, 0.08);
          border-color: var(--danger);
          color: var(--danger);
        }

        /* Modal / Drawer Overlay */
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
          justify-content: flex-end; /* Drawer slide-in from right */
        }

        .modal-drawer {
          width: 100%;
          max-width: 480px;
          height: 100%;
          border-radius: 0;
          border-left: 1px solid var(--border);
          padding: 2.5rem;
          display: flex;
          flex-direction: column;
          gap: 2rem;
          overflow-y: auto;
        }

        .drawer-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
        }

        .drawer-header h3 {
          font-size: 1.35rem;
          font-family: var(--font-heading);
        }

        .drawer-close {
          background: transparent;
          border: none;
          color: var(--text-secondary);
          cursor: pointer;
          padding: 0.25rem;
          transition: var(--transition);
        }

        .drawer-close:hover {
          color: var(--text-primary);
        }

        .drawer-form {
          display: flex;
          flex-direction: column;
          gap: 1.5rem;
        }

        .form-row {
          display: flex;
          gap: 1.5rem;
        }

        .form-group.half {
          flex: 1;
        }

        .checkbox-group {
          justify-content: flex-end;
        }

        .select-input {
          cursor: pointer;
        }

        .select-input option {
          background-color: var(--bg-sidebar);
          color: var(--text-primary);
        }

        /* Toggle Switch */
        .toggle-switch-wrapper {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          margin-top: 0.5rem;
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

        .drawer-actions {
          display: flex;
          gap: 1rem;
          margin-top: 2rem;
        }

        .drawer-actions button {
          flex: 1;
        }

        @media (max-width: 580px) {
          .page-header-actions {
            flex-direction: column;
            align-items: stretch;
            gap: 1rem;
          }
          .page-header-actions button {
            width: 100%;
          }
        }
      `}</style>
    </div>
  );
}
