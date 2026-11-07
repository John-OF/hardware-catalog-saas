import './CustomersPage.css';

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import {
  ChevronLeft,
  ChevronRight,
  Download,
  Heart,
  Loader2,
  Mail,
  MessageCircle,
  Phone,
  Search,
  ShoppingBag,
  Tag,
  Upload,
  UserCheck,
  Users,
  X,
} from 'lucide-react';
import {
  getCustomer,
  getCustomers,
  getEtiquetasDeClientes,
  importarClientes,
  updateCustomer,
  type FiltrosDeClientes,
  type ModoDeImportDeClientes,
  type OrdenDeClientes,
  type ResultadoDeImportDeClientes,
} from '../../api/customers';
import { exportarClientes } from '../../api/exportaciones';
import { avisarErrorEnSesion } from '../../api/erroresDeFormulario';
import Dialogo from '../../components/ui/Dialogo';
import type { Cliente, FichaDeCliente, PaginatedResponse } from '../../types';
import { usePuede } from '../../stores/authStore';
import { useTenantStore } from '../../stores/tenantStore';
import { formatearFecha } from '../../utils/fechas';
import { formatMoney } from '../../utils/money';
import { enlaceDeWhatsapp } from '../../utils/plantillasWhatsapp';
import { aceptaDe, problemaDelArchivo } from '../../utils/subidas';

/** Cuántas etiquetas admite una persona, y cuánto mide cada una. Copia del backend. */
const MAXIMO_DE_ETIQUETAS = 10;
const LARGO_DE_ETIQUETA = 30;

/**
 * Los clientes de la tienda (MOD-10, MOD-24).
 *
 * Es el **directorio** de la tienda: cada persona que le compró —con cuenta o
 * sin ella, por el catálogo o en el mostrador— y cada una que abrió cuenta,
 * juntadas por teléfono o correo. Se llena solo con los pedidos. Lo que el
 * dueño escribe aquí son sus notas y etiquetas, que el cliente no ve nunca; y
 * puede traer su libreta de contactos o llevarse el directorio en CSV.
 */
export default function CustomersPage() {
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency ?? 'USD');
  const fecha = (iso: string | null | undefined) => formatearFecha(iso, tenant?.timezone);
  const puedeGestionar = usePuede('clientes.gestionar') === true;
  const puedeExportar = usePuede('clientes.exportar') === true;

  const [busqueda, setBusqueda] = useState('');
  const [orden, setOrden] = useState<OrdenDeClientes>('recientes');
  const [etiqueta, setEtiqueta] = useState('');
  const [cuenta, setCuenta] = useState<'' | 'con' | 'sin'>('');
  const [page, setPage] = useState(1);
  const [fichaAbierta, setFichaAbierta] = useState<Cliente | null>(null);
  const [importando, setImportando] = useState(false);
  const [exportando, setExportando] = useState(false);

  const filtros: FiltrosDeClientes = {
    search: busqueda || undefined,
    sort: orden,
    page,
    etiqueta: etiqueta || undefined,
    cuenta: cuenta || undefined,
  };

  const { data, isLoading } = useQuery<PaginatedResponse<Cliente>>({
    queryKey: ['customers', filtros],
    queryFn: () => getCustomers(filtros),
  });

  const { data: etiquetas = [] } = useQuery<string[]>({
    queryKey: ['customers-etiquetas'],
    queryFn: getEtiquetasDeClientes,
  });

  const clientes = data?.data ?? [];
  const totalPages = data?.last_page ?? 1;
  const conFiltros = Boolean(busqueda || etiqueta || cuenta);

  const cambiarFiltro = (aplicar: () => void) => {
    aplicar();
    setPage(1);
  };

  const exportar = async () => {
    setExportando(true);
    try {
      await exportarClientes({ etiqueta: etiqueta || undefined });
    } catch (err) {
      avisarErrorEnSesion(err, 'No se pudo exportar el directorio.');
    } finally {
      setExportando(false);
    }
  };

  return (
    <div className="customers-page animate-fade-in page-customers">
      <div className="page-header customers-header">
        <div>
          <h1>Clientes</h1>
          <p className="page-description">
            Quien te compró —con cuenta o sin ella, por el catálogo o en el mostrador— y quien abrió
            cuenta en tu tienda. Se junta a la misma persona por su teléfono o su correo. Los pedidos
            cancelados no cuentan.
          </p>
        </div>
        {(puedeGestionar || puedeExportar) && (
          <div className="customers-header-actions">
            {puedeGestionar && (
              <button type="button" className="btn-secondary" onClick={() => setImportando(true)}>
                <Upload size={16} /> Importar CSV
              </button>
            )}
            {puedeExportar && (
              <button type="button" className="btn-secondary" onClick={exportar} disabled={exportando}>
                {exportando ? <Loader2 size={16} className="spinner" /> : <Download size={16} />} Exportar CSV
              </button>
            )}
          </div>
        )}
      </div>

      <div className="glass-card filters-bar">
        <div className="search-box">
          <Search size={18} className="search-icon" />
          <input
            type="text"
            className="premium-input search-input"
            placeholder="Buscar por nombre, correo o teléfono..."
            value={busqueda}
            onChange={(e) => cambiarFiltro(() => setBusqueda(e.target.value))}
          />
        </div>

        {etiquetas.length > 0 && (
          <select
            className="premium-input filter-select"
            aria-label="Etiqueta"
            value={etiqueta}
            onChange={(e) => cambiarFiltro(() => setEtiqueta(e.target.value))}
          >
            <option value="">Todas las etiquetas</option>
            {etiquetas.map((e) => (
              <option key={e} value={e}>{e}</option>
            ))}
          </select>
        )}

        <select
          className="premium-input filter-select"
          aria-label="Cuenta"
          value={cuenta}
          onChange={(e) => cambiarFiltro(() => setCuenta(e.target.value as '' | 'con' | 'sin'))}
        >
          <option value="">Con y sin cuenta</option>
          <option value="con">Con cuenta en la tienda</option>
          <option value="sin">Sin cuenta</option>
        </select>

        <select
          className="premium-input filter-select"
          aria-label="Orden"
          value={orden}
          onChange={(e) => cambiarFiltro(() => setOrden(e.target.value as OrdenDeClientes))}
        >
          <option value="recientes">Más recientes</option>
          <option value="gasto">Los que más gastaron</option>
          <option value="pedidos">Los que más pedidos hicieron</option>
        </select>
      </div>

      {isLoading ? (
        <div className="glass-card state-card">
          <Loader2 size={32} className="spinner" />
        </div>
      ) : clientes.length === 0 ? (
        <div className="glass-card state-card">
          <Users size={48} />
          <h3>{conFiltros ? 'Nadie con esos filtros' : 'Todavía no tienes clientes'}</h3>
          <p>
            {conFiltros
              ? 'Prueba con otro nombre, correo, teléfono o etiqueta.'
              : 'Cuando alguien te haga un pedido o cree su cuenta desde tu catálogo, aparecerá aquí.'}
          </p>
        </div>
      ) : (
        <div className="glass-card table-card">
          <div className="table-wrapper">
            <table className="dashboard-table">
              <thead>
                <tr>
                  <th>Cliente</th>
                  <th>Contacto</th>
                  <th>Pedidos</th>
                  <th>Total gastado</th>
                  <th>Última compra</th>
                  <th className="actions-header">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {clientes.map((cliente) => (
                  <tr key={cliente.id}>
                    <td>
                      <div className="customer-name-cell">
                        <span className="customer-name">
                          {cliente.name}
                          {cliente.tiene_cuenta && (
                            <span className="customer-account" title="Tiene cuenta en tu catálogo">
                              <UserCheck size={12} /> Cuenta
                            </span>
                          )}
                        </span>
                        <span className="customer-since">Cliente desde {fecha(cliente.created_at)}</span>
                        {cliente.tags.length > 0 && (
                          <span className="customer-tags">
                            {cliente.tags.map((t) => (
                              <span key={t} className="customer-tag">{t}</span>
                            ))}
                          </span>
                        )}
                      </div>
                    </td>
                    <td>
                      <div className="customer-contact">
                        {cliente.email && (
                          <span>
                            <Mail size={13} /> {cliente.email}
                          </span>
                        )}
                        {cliente.phone && (
                          <span>
                            <Phone size={13} /> {cliente.phone}
                          </span>
                        )}
                      </div>
                    </td>
                    <td>{cliente.pedidos_count}</td>
                    <td className="customer-spent">{money(cliente.total_gastado)}</td>
                    <td>
                      {cliente.ultima_compra ? (
                        fecha(cliente.ultima_compra)
                      ) : (
                        <span className="muted-cell">Sin compras todavía</span>
                      )}
                    </td>
                    <td className="actions-cell">
                      <div className="customer-actions">
                        {cliente.phone && (
                          <a
                            href={enlaceDeWhatsapp(cliente.phone)}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="btn-secondary btn-small customer-whatsapp"
                            title={`Escribir a ${cliente.name} por WhatsApp`}
                            aria-label={`Escribir a ${cliente.name} por WhatsApp`}
                          >
                            <MessageCircle size={14} />
                          </a>
                        )}
                        <button
                          type="button"
                          className="btn-secondary btn-small"
                          onClick={() => setFichaAbierta(cliente)}
                        >
                          Ver ficha
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {totalPages > 1 && (
            <div className="pagination-bar">
              <button
                type="button"
                className="btn-secondary"
                disabled={page <= 1}
                onClick={() => setPage((p) => p - 1)}
              >
                <ChevronLeft size={16} />
              </button>
              <span className="page-indicator">
                Página {page} de {totalPages}
              </span>
              <button
                type="button"
                className="btn-secondary"
                disabled={page >= totalPages}
                onClick={() => setPage((p) => p + 1)}
              >
                <ChevronRight size={16} />
              </button>
            </div>
          )}
        </div>
      )}

      {fichaAbierta && (
        <FichaDelCliente
          cliente={fichaAbierta}
          etiquetasDeLaTienda={etiquetas}
          puedeGestionar={puedeGestionar}
          money={money}
          fecha={fecha}
          onCerrar={() => setFichaAbierta(null)}
        />
      )}

      {importando && <ImportarClientes onCerrar={() => setImportando(false)} />}
    </div>
  );
}

/**
 * La ficha: los totales que ya se ven en la lista, lo que la tienda anota de
 * la persona y sus últimas compras.
 *
 * Se pide al abrir y no con la lista: el historial de un cliente fiel son
 * decenas de filas, y traerlas para los veinte de la página sería descargar la
 * tienda entera para mirar a uno.
 */
function FichaDelCliente({
  cliente,
  etiquetasDeLaTienda,
  puedeGestionar,
  money,
  fecha,
  onCerrar,
}: {
  cliente: Cliente;
  etiquetasDeLaTienda: string[];
  puedeGestionar: boolean;
  money: (n: number | string | null | undefined) => string;
  /** Formatea en la zona de la tienda (MOD-13). Llega hecho, como `money`. */
  fecha: (iso: string | null | undefined) => string;
  onCerrar: () => void;
}) {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery<FichaDeCliente>({
    queryKey: ['customer', cliente.id],
    queryFn: () => getCustomer(cliente.id),
  });

  const ficha = data?.customer ?? cliente;
  const pedidos = data?.orders ?? [];

  const [notas, setNotas] = useState(cliente.notes ?? '');
  const [etiquetas, setEtiquetas] = useState<string[]>(cliente.tags);
  const [nuevaEtiqueta, setNuevaEtiqueta] = useState('');

  // Cuando llega la ficha fresca, lo editable parte de lo guardado.
  useEffect(() => {
    if (!data) return;
    setNotas(data.customer.notes ?? '');
    setEtiquetas(data.customer.tags);
  }, [data]);

  const guardar = useMutation({
    mutationFn: () => updateCustomer(cliente.id, { notes: notas, tags: etiquetas }),
    onSuccess: (respuesta) => {
      queryClient.setQueryData(['customer', cliente.id], respuesta);
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      queryClient.invalidateQueries({ queryKey: ['customers-etiquetas'] });
      toast.success('Notas guardadas');
    },
    onError: (err) => avisarErrorEnSesion(err, 'No se pudieron guardar las notas.'),
  });

  const agregarEtiqueta = () => {
    const texto = nuevaEtiqueta.trim().replace(/\s+/g, ' ').slice(0, LARGO_DE_ETIQUETA);
    if (!texto) return;
    if (!etiquetas.some((e) => e.toLowerCase() === texto.toLowerCase()) && etiquetas.length < MAXIMO_DE_ETIQUETAS) {
      setEtiquetas([...etiquetas, texto]);
    }
    setNuevaEtiqueta('');
  };

  const cambios = notas.trim() !== (ficha.notes ?? '') || etiquetas.join('|') !== ficha.tags.join('|');

  return (
    <Dialogo
      titulo={ficha.name}
      subtitulo={[ficha.email, ficha.phone].filter(Boolean).join(' · ') || undefined}
      onCerrar={onCerrar}
      ancho={640}
      className="page-customers"
    >
      <div className="dialogo-cuerpo">
        <div className="customer-stats">
          <div className="customer-stat">
            <ShoppingBag size={16} />
            <strong>{ficha.pedidos_count}</strong>
            <span>pedidos</span>
          </div>
          <div className="customer-stat">
            <span className="customer-stat-money">{money(ficha.total_gastado)}</span>
            <span>gastado</span>
          </div>
          <div className="customer-stat">
            <Heart size={16} />
            <strong>{ficha.tiene_cuenta ? ficha.favoritos_count ?? 0 : '—'}</strong>
            <span>{ficha.tiene_cuenta ? 'favoritos' : 'sin cuenta'}</span>
          </div>
        </div>

        <div className="customer-notes">
          <label htmlFor="customer-notes-text">
            Notas <span className="customer-notes-hint">(solo las ve tu equipo)</span>
          </label>
          <textarea
            id="customer-notes-text"
            className="premium-input"
            rows={3}
            maxLength={2000}
            value={notas}
            readOnly={!puedeGestionar}
            placeholder={puedeGestionar ? 'Ej.: paga siempre por transferencia, prefiere recoger en tienda…' : 'Sin notas.'}
            onChange={(e) => setNotas(e.target.value)}
          />

          <span className="customer-notes-label">
            <Tag size={13} /> Etiquetas
          </span>
          <div className="customer-tags editables">
            {etiquetas.map((t) => (
              <span key={t} className="customer-tag">
                {t}
                {puedeGestionar && (
                  <button type="button" onClick={() => setEtiquetas(etiquetas.filter((e) => e !== t))} aria-label={`Quitar la etiqueta ${t}`}>
                    <X size={11} />
                  </button>
                )}
              </span>
            ))}
            {etiquetas.length === 0 && !puedeGestionar && <span className="muted-cell">Sin etiquetas.</span>}
            {puedeGestionar && etiquetas.length < MAXIMO_DE_ETIQUETAS && (
              <input
                type="text"
                className="premium-input customer-tag-input"
                list="customer-tag-sugerencias"
                maxLength={LARGO_DE_ETIQUETA}
                placeholder="Añadir etiqueta…"
                value={nuevaEtiqueta}
                onChange={(e) => setNuevaEtiqueta(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    agregarEtiqueta();
                  }
                }}
                onBlur={agregarEtiqueta}
                aria-label="Nueva etiqueta"
              />
            )}
            <datalist id="customer-tag-sugerencias">
              {etiquetasDeLaTienda.filter((e) => !etiquetas.includes(e)).map((e) => (
                <option key={e} value={e} />
              ))}
            </datalist>
          </div>

          {puedeGestionar && (
            <button
              type="button"
              className="btn-primary btn-small customer-notes-save"
              disabled={!cambios || guardar.isPending}
              onClick={() => guardar.mutate()}
            >
              {guardar.isPending && <Loader2 size={14} className="spinner" />}
              Guardar notas y etiquetas
            </button>
          )}
        </div>

        {isLoading ? (
          <div className="state-card">
            <Loader2 size={24} className="spinner" />
          </div>
        ) : pedidos.length === 0 ? (
          <p className="customer-empty">
            Todavía no ha comprado nada.
            {ficha.email ? ` Tiene cuenta, así que puedes escribirle a ${ficha.email}.` : ''}
          </p>
        ) : (
          <table className="dashboard-table customer-orders">
            <thead>
              <tr>
                <th>Pedido</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th>Total</th>
              </tr>
            </thead>
            <tbody>
              {pedidos.map((pedido) => (
                <tr key={pedido.id}>
                  <td>#{pedido.number}</td>
                  <td>{fecha(pedido.created_at)}</td>
                  <td>{etiquetaDeEstado(pedido.status)}</td>
                  <td>{money(pedido.total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        <div className="dialogo-acciones">
          <button type="button" className="btn-secondary" onClick={onCerrar}>
            Cerrar
          </button>
        </div>
      </div>
    </Dialogo>
  );
}

/**
 * Traer la libreta de contactos que el negocio ya tenía (MOD-24). Mismas
 * columnas que escribe la exportación, así que lo exportado se puede editar en
 * Excel y volver a subir.
 */
function ImportarClientes({ onCerrar }: { onCerrar: () => void }) {
  const queryClient = useQueryClient();
  const [archivo, setArchivo] = useState<File | null>(null);
  const [modo, setModo] = useState<ModoDeImportDeClientes>('omitir');
  const [resultado, setResultado] = useState<ResultadoDeImportDeClientes | null>(null);

  const importar = useMutation({
    mutationFn: () => importarClientes(archivo!, modo),
    onSuccess: (r) => {
      setResultado(r);
      queryClient.invalidateQueries({ queryKey: ['customers'] });
      queryClient.invalidateQueries({ queryKey: ['customers-etiquetas'] });
    },
    onError: (err) => avisarErrorEnSesion(err, 'No se pudo importar el archivo.'),
  });

  return (
    <Dialogo titulo="Importar clientes" onCerrar={onCerrar} ancho={560} className="page-customers">
      <div className="dialogo-cuerpo">
        <div className="customers-import-help">
          <p>Un CSV con la primera fila de cabecera, separado por <code>;</code> o <code>,</code>:</p>
          <code className="customers-import-columns">nombre;telefono;correo;etiquetas;notas</code>
          <ul>
            <li>Cada fila necesita un <strong>teléfono</strong> o un <strong>correo</strong>: es lo que la identifica.</li>
            <li><strong>etiquetas</strong> va separada por <code>|</code> (ej.: <code>mayorista|frecuente</code>).</li>
            <li>Alguien <strong>ya está</strong> si en tu directorio hay otra persona con su teléfono o su correo.</li>
          </ul>
        </div>

        {resultado ? (
          <div className="customers-import-result">
            <p>
              <strong>{resultado.created_count}</strong> nuevos, <strong>{resultado.updated_count}</strong> actualizados,{' '}
              <strong>{resultado.unchanged_count}</strong> sin cambios y <strong>{resultado.skipped_count}</strong> omitidos
              porque ya estaban.
            </p>
            {resultado.errors.length > 0 && (
              <ul className="customers-import-errors">
                {resultado.errors.map((e) => (
                  <li key={e}>{e}</li>
                ))}
              </ul>
            )}
          </div>
        ) : (
          <>
            <div className="form-group">
              <label htmlFor="customers-import-file">Archivo</label>
              <input
                id="customers-import-file"
                type="file"
                accept={aceptaDe('csv')}
                className="premium-input"
                onChange={(e) => {
                  const elegido = e.target.files?.[0] ?? null;
                  const problema = elegido ? problemaDelArchivo(elegido, 'csv') : null;
                  if (problema) {
                    toast.error(problema);
                    e.target.value = '';
                    setArchivo(null);
                    return;
                  }
                  setArchivo(elegido);
                }}
              />
            </div>
            <div className="form-group">
              <label htmlFor="customers-import-mode">Si alguien ya está</label>
              <select
                id="customers-import-mode"
                className="premium-input"
                value={modo}
                onChange={(e) => setModo(e.target.value as ModoDeImportDeClientes)}
              >
                <option value="omitir">Dejarlo como está</option>
                <option value="actualizar">Actualizarlo con el archivo (una celda vacía no borra)</option>
              </select>
            </div>
          </>
        )}

        <div className="dialogo-acciones">
          <button type="button" className="btn-secondary" onClick={onCerrar}>
            {resultado ? 'Cerrar' : 'Cancelar'}
          </button>
          {!resultado && (
            <button type="button" className="btn-primary" disabled={!archivo || importar.isPending} onClick={() => importar.mutate()}>
              {importar.isPending && <Loader2 size={14} className="spinner" />}
              Importar
            </button>
          )}
        </div>
      </div>
    </Dialogo>
  );
}

const etiquetaDeEstado = (estado: string): string =>
  ({
    pending: 'Pendiente',
    processing: 'En proceso',
    attended: 'Atendido',
    cancelled: 'Cancelado',
  })[estado] ?? estado;
