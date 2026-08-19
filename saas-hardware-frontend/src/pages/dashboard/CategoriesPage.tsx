import './CategoriesPage.css';

import { useState, useEffect } from 'react';
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
  Loader2,
  GripVertical
} from 'lucide-react';
import {
  getCategories,
  createCategory,
  updateCategory,
  deleteCategory,
  reorderCategories
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

  const [localCategories, setLocalCategories] = useState<Category[]>([]);
  const [draggedIndex, setDraggedIndex] = useState<number | null>(null);

  // Sync with react-query data
  useEffect(() => {
    if (categories) {
      const sorted = [...categories].sort((a, b) => a.sort_order - b.sort_order);
      setLocalCategories(sorted);
    }
  }, [categories]);

  // Mutación para Reordenar
  const reorderMutation = useMutation({
    mutationFn: reorderCategories,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['categories'] });
      toast.success('Orden de categorías actualizado');
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al reordenar las categorías';
      toast.error(msg);
      if (categories) {
        setLocalCategories([...categories].sort((a, b) => a.sort_order - b.sort_order));
      }
    }
  });

  const handleDragStart = (e: React.DragEvent, index: number) => {
    setDraggedIndex(index);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', index.toString());
  };

  const handleDragOver = (e: React.DragEvent, index: number) => {
    e.preventDefault();
    if (draggedIndex === null || draggedIndex === index) return;
    
    const updatedList = [...localCategories];
    const draggedItem = updatedList[draggedIndex];
    updatedList.splice(draggedIndex, 1);
    updatedList.splice(index, 0, draggedItem);
    
    setDraggedIndex(index);
    setLocalCategories(updatedList);
  };

  const handleDragEnd = () => {
    setDraggedIndex(null);
    const ids = localCategories.map(c => c.id);
    reorderMutation.mutate(ids);
  };

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
    <div className="categories-page animate-fade-in page-categories">
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
      ) : localCategories.length === 0 ? (
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
          {localCategories.map((category, index) => (
            <div
              key={category.id}
              className={`category-card glass-card ${draggedIndex === index ? 'dragging' : ''}`}
              draggable
              onDragStart={(e) => handleDragStart(e, index)}
              onDragOver={(e) => handleDragOver(e, index)}
              onDragEnd={handleDragEnd}
            >
              <div className="category-card-header">
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.65rem' }}>
                  <div className="drag-handle" title="Arrastrar para ordenar" style={{ cursor: 'grab', color: 'var(--text-muted)', display: 'flex', alignItems: 'center' }}>
                    <GripVertical size={18} />
                  </div>
                  <div className="category-icon-sphere">
                    <CategoryIcon slug={category.icon} size={28} />
                  </div>
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
                      border: '1px solid var(--border)',
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

    </div>
  );
}
