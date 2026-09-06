import { useState } from 'react';
import { createPortal } from 'react-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  FileText,
  Plus,
  Loader2,
  Trash2,
  Edit,
  FolderOpen
} from 'lucide-react';
import { toast } from 'react-hot-toast';
import { getPages, createPage, updatePage, deletePage } from '../../api/pages';
import type { Page } from '../../types';
import './PagesPage.css';

export default function PagesPage() {
  const queryClient = useQueryClient();

  // Modals & Forms State
  const [isOpen, setIsOpen] = useState(false);
  const [editingPage, setEditingPage] = useState<Page | null>(null);
  
  const [title, setTitle] = useState('');
  const [slug, setSlug] = useState('');
  const [content, setContent] = useState('');
  const [isActive, setIsActive] = useState(true);

  // Fetch Pages
  const { data: pages = [], isLoading } = useQuery<Page[]>({
    queryKey: ['pages'],
    queryFn: getPages,
  });

  // Auto-generate slug from title
  const handleTitleChange = (newTitle: string) => {
    setTitle(newTitle);
    if (!editingPage) {
      const generatedSlug = newTitle
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '') // Remove accents
        .replace(/[^a-z0-9\s-]/g, '') // Remove special chars
        .trim()
        .replace(/\s+/g, '-'); // Replace spaces with -
      setSlug(generatedSlug);
    }
  };

  // Create page mutation
  const createMutation = useMutation({
    mutationFn: createPage,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pages'] });
      toast.success('Página informativa creada');
      closeModal();
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al crear la página';
      toast.error(msg);
    }
  });

  // Update page mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<Page> }) => updatePage(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pages'] });
      toast.success('Página actualizada');
      closeModal();
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al actualizar la página';
      toast.error(msg);
    }
  });

  // Delete page mutation
  const deleteMutation = useMutation({
    mutationFn: deletePage,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pages'] });
      toast.success('Página eliminada');
    },
    onError: (err: any) => {
      const msg = err.response?.data?.message || 'Error al eliminar la página';
      toast.error(msg);
    }
  });

  const openCreateModal = () => {
    setEditingPage(null);
    setTitle('');
    setSlug('');
    setContent('');
    setIsActive(true);
    setIsOpen(true);
  };

  const openEditModal = (page: Page) => {
    setEditingPage(page);
    setTitle(page.title);
    setSlug(page.slug);
    setContent(page.content || '');
    setIsActive(page.is_active);
    setIsOpen(true);
  };

  const closeModal = () => {
    setIsOpen(false);
    setEditingPage(null);
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim() || !slug.trim()) {
      toast.error('El título y el slug son obligatorios');
      return;
    }

    const payload = {
      title,
      slug,
      content,
      is_active: isActive
    };

    if (editingPage) {
      updateMutation.mutate({ id: editingPage.id, data: payload });
    } else {
      createMutation.mutate(payload);
    }
  };

  const handleDelete = (id: string) => {
    if (confirm('¿Estás seguro de eliminar esta página informativa?')) {
      deleteMutation.mutate(id);
    }
  };

  return (
    <div className="pages-page animate-fade-in page-pages">
      {/* Header section */}
      <div className="content-header">
        <div>
          <h2>Páginas Informativas</h2>
          <p className="text-secondary">Crea contenido adicional como Quiénes Somos, Términos, Políticas de Garantía o Envíos.</p>
        </div>
        <button onClick={openCreateModal} className="btn-primary">
          <Plus size={18} /> Nueva Página
        </button>
      </div>

      {isLoading ? (
        <div className="loading-state">
          <Loader2 className="spinner" size={32} />
          <p>Cargando páginas...</p>
        </div>
      ) : pages.length === 0 ? (
        <div className="empty-state glass-card">
          <FolderOpen size={48} className="empty-icon" />
          <h3>No hay páginas creadas</h3>
          <p>Agrega páginas con información útil para generar confianza en tus clientes.</p>
          <button onClick={openCreateModal} className="btn-secondary" style={{ marginTop: '1rem' }}>
            Crear mi primera página
          </button>
        </div>
      ) : (
        <div className="glass-card table-container">
          <table className="dashboard-table">
            <thead>
              <tr>
                <th>Título</th>
                <th>Enlace / Slug</th>
                <th>Estado</th>
                <th style={{ textAlign: 'right' }}>Acciones</th>
              </tr>
            </thead>
            <tbody>
              {pages.map((page) => (
                <tr key={page.id}>
                  <td>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem' }}>
                      <FileText size={18} className="text-primary" />
                      <div>
                        <strong className="text-primary">{page.title}</strong>
                      </div>
                    </div>
                  </td>
                  <td>
                    <code className="slug-badge">/p/{page.slug}</code>
                  </td>
                  <td>
                    <span className={`badge ${page.is_active ? 'badge-success' : 'badge-danger'}`}>
                      {page.is_active ? 'Publicado' : 'Borrador'}
                    </span>
                  </td>
                  <td style={{ textAlign: 'right' }}>
                    <div className="actions-cell">
                      <button
                        onClick={() => openEditModal(page)}
                        className="btn-icon"
                        title="Editar página"
                      >
                        <Edit size={16} />
                      </button>
                      <button
                        onClick={() => handleDelete(page.id)}
                        className="btn-icon text-danger"
                        title="Eliminar página"
                      >
                        <Trash2 size={16} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Editor Modal. Va por portal para escapar del contexto de apilamiento
          que crea la animacion de .dashboard-content, que dejaba la topbar del
          panel por encima. El destino es .dashboard-layout y no <body> porque
          la paleta del panel (clara y oscura) se define en ese elemento, no en
          :root: colgarlo del body lo sacaba del tema del admin y le metia los
          colores por defecto, que son los de la tienda. */}
      {isOpen && createPortal(
        <div className="pages-modal-overlay" onClick={closeModal}>
          <div className="pages-modal glass-card" onClick={(e) => e.stopPropagation()}>
            <div className="drawer-header">
              <h3>{editingPage ? 'Editar Página' : 'Nueva Página Informativa'}</h3>
              <button onClick={closeModal} className="drawer-close">
                &times;
              </button>
            </div>

            <form onSubmit={handleSubmit} className="drawer-form">
              <div className="form-group">
                <label>Título de la Página</label>
                <input
                  type="text"
                  value={title}
                  onChange={(e) => handleTitleChange(e.target.value)}
                  placeholder="Ej. Políticas de Envíos y Entregas"
                  className="premium-input"
                  required
                />
              </div>

              <div className="form-group">
                <label>Ruta / Slug URL</label>
                <input
                  type="text"
                  value={slug}
                  onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/\s+/g, '-'))}
                  placeholder="politicas-de-envio"
                  className="premium-input"
                  required
                />
                <span className="helper-text">Se accederá desde el catálogo usando: <code>/p/{slug || 'url-amigable'}</code></span>
              </div>

              <div className="form-group">
                <label>Contenido (Soporta etiquetas HTML sencillas)</label>
                <div className="rich-toolbar">
                  <button
                    type="button"
                    onClick={() => {
                      const txtarea = document.getElementById('page-content') as HTMLTextAreaElement;
                      if (!txtarea) return;
                      const start = txtarea.selectionStart;
                      const end = txtarea.selectionEnd;
                      const text = txtarea.value;
                      const selected = text.substring(start, end);
                      const replacement = `<strong>${selected}</strong>`;
                      setContent(text.substring(0, start) + replacement + text.substring(end));
                    }}
                    title="Negrita"
                  >
                    Negrita
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      const txtarea = document.getElementById('page-content') as HTMLTextAreaElement;
                      if (!txtarea) return;
                      const start = txtarea.selectionStart;
                      const end = txtarea.selectionEnd;
                      const text = txtarea.value;
                      const selected = text.substring(start, end);
                      const replacement = `<em>${selected}</em>`;
                      setContent(text.substring(0, start) + replacement + text.substring(end));
                    }}
                    title="Cursiva"
                  >
                    Cursiva
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      const txtarea = document.getElementById('page-content') as HTMLTextAreaElement;
                      if (!txtarea) return;
                      const start = txtarea.selectionStart;
                      const end = txtarea.selectionEnd;
                      const text = txtarea.value;
                      const selected = text.substring(start, end);
                      const replacement = `<h3>${selected}</h3>`;
                      setContent(text.substring(0, start) + replacement + text.substring(end));
                    }}
                    title="Encabezado"
                  >
                    Título H3
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      const txtarea = document.getElementById('page-content') as HTMLTextAreaElement;
                      if (!txtarea) return;
                      const start = txtarea.selectionStart;
                      const end = txtarea.selectionEnd;
                      const text = txtarea.value;
                      const selected = text.substring(start, end);
                      const replacement = `<ul>\n  <li>${selected || 'Elemento'}</li>\n</ul>`;
                      setContent(text.substring(0, start) + replacement + text.substring(end));
                    }}
                    title="Lista con viñetas"
                  >
                    Lista
                  </button>
                </div>
                <textarea
                  id="page-content"
                  value={content}
                  onChange={(e) => setContent(e.target.value)}
                  placeholder="Escribe el contenido informativo aquí..."
                  className="premium-input textarea-rich"
                  style={{ minHeight: '300px', fontFamily: 'monospace', fontSize: '0.9rem' }}
                />
              </div>

              <div className="form-group checkbox-group">
                <label className="switch-container">
                  <input
                    type="checkbox"
                    checked={isActive}
                    onChange={(e) => setIsActive(e.target.checked)}
                  />
                  <span className="switch-label">
                    <strong>Página Activa / Publicada</strong>
                    <span className="text-secondary" style={{ display: 'block', fontSize: '0.8rem' }}>
                      Si está inactiva, se guardará como borrador y los clientes no podrán verla.
                    </span>
                  </span>
                </label>
              </div>

              <div className="drawer-actions">
                <button type="button" onClick={closeModal} className="btn-secondary">
                  Cancelar
                </button>
                <button
                  type="submit"
                  className="btn-primary"
                  disabled={createMutation.isPending || updateMutation.isPending}
                >
                  {(createMutation.isPending || updateMutation.isPending) ? (
                    <Loader2 className="spinner" size={16} />
                  ) : editingPage ? (
                    'Guardar Cambios'
                  ) : (
                    'Crear Página'
                  )}
                </button>
              </div>
            </form>
          </div>
        </div>,
        document.querySelector('.dashboard-layout') ?? document.body
      )}
    </div>
  );
}
