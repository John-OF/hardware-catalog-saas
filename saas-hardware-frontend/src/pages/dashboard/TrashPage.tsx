import './TrashPage.css';

import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronLeft, ChevronRight, Loader2, RotateCcw, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { borrarDePapelera, getPapelera, restaurarDePapelera, vaciarPapelera } from '../../api/trash';
import { mensajeDeError } from '../../api/erroresDeFormulario';
import { useTenantStore } from '../../stores/tenantStore';
import { formatearFechaHora } from '../../utils/fechas';
import { formatMoney } from '../../utils/money';
import type { Order, Product, TipoDePapelera } from '../../types';

const TIPOS: { valor: TipoDePapelera; etiqueta: string }[] = [
  { valor: 'productos', etiqueta: 'Productos' },
  { valor: 'pedidos', etiqueta: 'Pedidos' },
];

const esTipo = (valor: string | null): valor is TipoDePapelera =>
  valor === 'productos' || valor === 'pedidos';

/**
 * Cuántos días le quedan a algo antes de que la purga se lo lleve.
 *
 * Se mide contra `corte` —la fecha que manda el servidor, a partir de la cual
 * algo sigue vivo— y no contra el reloj del navegador: si quien mira tiene la
 * hora mal, el aviso mentiría. Es la misma razón por la que las fechas del panel
 * se pintan con la zona de la tienda y no con la del navegador (MOD-13).
 */
export function diasQueLeQuedan(deletedAt: string, corte: string): number {
  const dias = (new Date(deletedAt).getTime() - new Date(corte).getTime()) / 86_400_000;

  return Math.max(0, Math.ceil(dias));
}

/**
 * La papelera de la tienda (MOD-8). Solo admin.
 *
 * Borrar un producto era definitivo y además se llevaba sus fotos; borrar un
 * pedido lo sacaba del historial de ventas. Aquí se ve lo borrado, se devuelve a
 * su sitio o se elimina del todo.
 *
 * El tipo vive en la URL, como los filtros del catálogo (UI-1): un enlace a la
 * papelera de pedidos se puede guardar o pasar.
 */
export default function TrashPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const tenant = useTenantStore((s) => s.tenant);
  const queryClient = useQueryClient();

  const tipoEnLaUrl = searchParams.get('tipo');
  const tipo: TipoDePapelera = esTipo(tipoEnLaUrl) ? tipoEnLaUrl : 'productos';
  const page = Math.max(1, Number(searchParams.get('pagina')) || 1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['papelera', tipo, page],
    queryFn: () => getPapelera({ tipo, page }),
  });

  const cambiar = (cambios: Record<string, string | null>) => {
    const siguiente = new URLSearchParams(searchParams);
    Object.entries(cambios).forEach(([clave, valor]) => {
      if (valor) siguiente.set(clave, valor);
      else siguiente.delete(clave);
    });
    setSearchParams(siguiente);
  };

  // Al cambiar de pestaña se vuelve a la primera página: la 3 de productos no
  // tiene por qué existir en pedidos.
  const elegirTipo = (valor: TipoDePapelera) => cambiar({ tipo: valor, pagina: null });

  /**
   * Las tres acciones invalidan además las listas de las que salió o a las que
   * vuelve lo tocado: restaurar un producto tiene que hacerlo aparecer en
   * Productos sin recargar, y el resumen y los reportes cambian con un pedido.
   */
  const refrescar = () => {
    queryClient.invalidateQueries({ queryKey: ['papelera'] });
    queryClient.invalidateQueries({ queryKey: ['products'] });
    queryClient.invalidateQueries({ queryKey: ['orders'] });
    queryClient.invalidateQueries({ queryKey: ['dashboardStats'] });
    queryClient.invalidateQueries({ queryKey: ['reportes'] });
    queryClient.invalidateQueries({ queryKey: ['plan'] });
  };

  const restaurar = useMutation({
    mutationFn: (id: string) => restaurarDePapelera(tipo, id),
    onSuccess: () => {
      toast.success(tipo === 'productos' ? 'Producto restaurado.' : 'Pedido restaurado.');
      refrescar();
    },
    // El 422 del tope del plan llega aquí: "tu plan admite N productos". Sin
    // esto se vería el mensaje genérico y el dueño no sabría qué hacer (UI-11).
    onError: (error) => toast.error(mensajeDeError(error, { contexto: 'panel' })),
  });

  const borrar = useMutation({
    mutationFn: (id: string) => borrarDePapelera(tipo, id),
    onSuccess: () => {
      toast.success('Eliminado definitivamente.');
      refrescar();
    },
    onError: (error) => toast.error(mensajeDeError(error, { contexto: 'panel' })),
  });

  const vaciar = useMutation({
    mutationFn: vaciarPapelera,
    onSuccess: (resultado) => {
      toast.success(`Papelera vaciada: ${resultado.productos} productos y ${resultado.pedidos} pedidos.`);
      refrescar();
    },
    onError: (error) => toast.error(mensajeDeError(error, { contexto: 'panel' })),
  });

  const confirmarBorrado = (id: string, nombre: string) => {
    if (window.confirm(`Se eliminará «${nombre}» definitivamente. Esto no se puede deshacer.`)) {
      borrar.mutate(id);
    }
  };

  const confirmarVaciado = () => {
    const total = (data?.totales.productos ?? 0) + (data?.totales.pedidos ?? 0);

    if (window.confirm(`Se eliminarán definitivamente ${total} elementos, con sus fotos. Esto no se puede deshacer.`)) {
      vaciar.mutate();
    }
  };

  const items = data?.items.data ?? [];
  const totales = data?.totales ?? { productos: 0, pedidos: 0 };
  const hayAlgo = totales.productos + totales.pedidos > 0;

  return (
    <div className="trash-page animate-fade-in page-trash">
      <div className="page-header">
        <div>
          <h1>Papelera</h1>
          <p className="page-description">
            Lo que borras de Productos y Pedidos pasa por aquí antes de desaparecer. Se elimina solo
            a los {data?.retencion.dias ?? 30} días; hasta entonces se puede devolver a su sitio.
          </p>
        </div>

        {hayAlgo && (
          <button
            type="button"
            className="btn-danger"
            onClick={confirmarVaciado}
            disabled={vaciar.isPending}
          >
            <Trash2 size={16} /> Vaciar papelera
          </button>
        )}
      </div>

      <div className="glass-card trash-tabs" role="tablist">
        {TIPOS.map((t) => (
          <button
            key={t.valor}
            type="button"
            role="tab"
            aria-selected={tipo === t.valor}
            className={`trash-tab ${tipo === t.valor ? 'active' : ''}`}
            onClick={() => elegirTipo(t.valor)}
          >
            {t.etiqueta} <span className="trash-tab-count">{totales[t.valor]}</span>
          </button>
        ))}
      </div>

      {isLoading ? (
        <div className="glass-card state-card">
          <Loader2 size={32} className="spinner" />
        </div>
      ) : isError ? (
        <div className="glass-card state-card">
          <h3>No se pudo cargar la papelera</h3>
          <p>Vuelve a intentarlo en unos segundos.</p>
        </div>
      ) : items.length === 0 ? (
        <div className="glass-card state-card">
          <Trash2 size={48} />
          <h3>{tipo === 'productos' ? 'No hay productos borrados' : 'No hay pedidos borrados'}</h3>
          <p>Lo que borres aparecerá aquí y podrás devolverlo durante {data?.retencion.dias ?? 30} días.</p>
        </div>
      ) : (
        <div className="glass-card list-card">
          <ul className="trash-list">
            {items.map((item) => {
              const dias = diasQueLeQuedan(item.deleted_at, data!.retencion.corte);
              const producto = item as Product & { deleted_at: string };
              const pedido = item as Order & { deleted_at: string };
              const nombre = tipo === 'productos' ? producto.name : `Pedido #${pedido.number}`;

              return (
                <li key={item.id} className="trash-item">
                  <div className="trash-item-main">
                    <span className="trash-item-name">{nombre}</span>
                    <span className="trash-item-detail">
                      {tipo === 'productos' ? (
                        <>
                          {producto.sku ? `${producto.sku} · ` : ''}
                          {formatMoney(producto.price, tenant?.currency)}
                        </>
                      ) : (
                        <>
                          {pedido.customer_name} · {formatMoney(pedido.total, tenant?.currency)}
                        </>
                      )}
                    </span>
                  </div>

                  <div className="trash-item-meta">
                    <time dateTime={item.deleted_at}>
                      Borrado el {formatearFechaHora(item.deleted_at, tenant?.timezone)}
                    </time>
                    <span className={`trash-item-dias ${dias <= 3 ? 'urgente' : ''}`}>
                      {dias === 0 ? 'Se elimina hoy' : dias === 1 ? 'Queda 1 día' : `Quedan ${dias} días`}
                    </span>
                  </div>

                  <div className="trash-item-actions">
                    <button
                      type="button"
                      className="btn-secondary"
                      onClick={() => restaurar.mutate(item.id)}
                      disabled={restaurar.isPending}
                    >
                      <RotateCcw size={14} /> Restaurar
                    </button>
                    <button
                      type="button"
                      className="btn-danger-ghost"
                      onClick={() => confirmarBorrado(item.id, nombre)}
                      disabled={borrar.isPending}
                    >
                      <Trash2 size={14} /> Eliminar
                    </button>
                  </div>
                </li>
              );
            })}
          </ul>

          {data && data.items.last_page > 1 && (
            <div className="pagination-bar">
              <span className="pagination-info">
                Página {data.items.current_page} de {data.items.last_page} · {data.items.total} en total
              </span>
              <div className="pagination-buttons">
                <button
                  type="button"
                  className="page-btn"
                  onClick={() => cambiar({ pagina: String(page - 1) })}
                  disabled={page <= 1}
                >
                  <ChevronLeft size={14} /> Anterior
                </button>
                <button
                  type="button"
                  className="page-btn"
                  onClick={() => cambiar({ pagina: String(page + 1) })}
                  disabled={page >= data.items.last_page}
                >
                  Siguiente <ChevronRight size={14} />
                </button>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
