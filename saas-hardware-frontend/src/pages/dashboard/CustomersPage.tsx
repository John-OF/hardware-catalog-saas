import './CustomersPage.css';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  ChevronLeft,
  ChevronRight,
  Heart,
  Loader2,
  Mail,
  Phone,
  Search,
  ShoppingBag,
  Users,
} from 'lucide-react';
import { getCustomer, getCustomers, type OrdenDeClientes } from '../../api/customers';
import Dialogo from '../../components/ui/Dialogo';
import type { Cliente, FichaDeCliente, PaginatedResponse } from '../../types';
import { useTenantStore } from '../../stores/tenantStore';
import { formatMoney } from '../../utils/money';

/**
 * Los clientes de la tienda (MOD-10).
 *
 * El dato ya existía —cuentas, favoritos y pedidos asociados— pero no había
 * pantalla: el dueño veía pedidos sueltos y no personas, así que no podía saber
 * quién le compra todos los meses y quién compró una vez el año pasado.
 *
 * **Solo lista y consulta.** No se crean ni se editan clientes desde aquí: la
 * cuenta la abre el propio cliente en el catálogo, y cambiarle los datos a
 * alguien desde el panel sería tocar su cuenta, no un registro de la tienda.
 *
 * Quien compra sin registrarse no aparece: su pedido está entero en Pedidos.
 */
export default function CustomersPage() {
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number | string | null | undefined) => formatMoney(n, tenant?.currency ?? 'USD');

  const [busqueda, setBusqueda] = useState('');
  const [orden, setOrden] = useState<OrdenDeClientes>('recientes');
  const [page, setPage] = useState(1);
  const [fichaAbierta, setFichaAbierta] = useState<Cliente | null>(null);

  const { data, isLoading } = useQuery<PaginatedResponse<Cliente>>({
    queryKey: ['customers', busqueda, orden, page],
    queryFn: () => getCustomers({ search: busqueda || undefined, sort: orden, page }),
  });

  const clientes = data?.data ?? [];
  const totalPages = data?.last_page ?? 1;

  return (
    <div className="customers-page animate-fade-in page-customers">
      <div className="page-header">
        <div>
          <h1>Clientes</h1>
          <p className="page-description">
            Quién tiene cuenta en tu tienda y cuánto te ha comprado. Los pedidos cancelados no
            cuentan, y quien compró sin registrarse no aparece aquí: ese pedido está en Pedidos.
          </p>
        </div>
      </div>

      <div className="glass-card filters-bar">
        <div className="search-box">
          <Search size={18} className="search-icon" />
          <input
            type="text"
            className="premium-input search-input"
            placeholder="Buscar por nombre, correo o teléfono..."
            value={busqueda}
            onChange={(e) => {
              setBusqueda(e.target.value);
              setPage(1);
            }}
          />
        </div>

        <select
          className="premium-input filter-select"
          value={orden}
          onChange={(e) => {
            setOrden(e.target.value as OrdenDeClientes);
            setPage(1);
          }}
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
          <h3>{busqueda ? 'Nadie con ese nombre' : 'Todavía no hay clientes registrados'}</h3>
          <p>
            {busqueda
              ? 'Prueba con otro nombre, correo o teléfono.'
              : 'Cuando alguien cree su cuenta desde tu catálogo para guardar favoritos o seguir sus pedidos, aparecerá aquí.'}
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
                  <th className="actions-header">Ficha</th>
                </tr>
              </thead>
              <tbody>
                {clientes.map((cliente) => (
                  <tr key={cliente.id}>
                    <td>
                      <div className="customer-name-cell">
                        <span className="customer-name">{cliente.name}</span>
                        <span className="customer-since">
                          Cliente desde {new Date(cliente.created_at).toLocaleDateString()}
                        </span>
                      </div>
                    </td>
                    <td>
                      <div className="customer-contact">
                        <span>
                          <Mail size={13} /> {cliente.email}
                        </span>
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
                        new Date(cliente.ultima_compra).toLocaleDateString()
                      ) : (
                        <span className="muted-cell">Sin compras todavía</span>
                      )}
                    </td>
                    <td className="actions-cell">
                      <button
                        type="button"
                        className="btn-secondary btn-small"
                        onClick={() => setFichaAbierta(cliente)}
                      >
                        Ver ficha
                      </button>
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
          money={money}
          onCerrar={() => setFichaAbierta(null)}
        />
      )}
    </div>
  );
}

/**
 * La ficha: los totales que ya se ven en la lista y sus últimas compras.
 *
 * Se pide al abrir y no con la lista: el historial de un cliente fiel son
 * decenas de filas, y traerlas para los veinte de la página sería descargar la
 * tienda entera para mirar a uno.
 */
function FichaDelCliente({
  cliente,
  money,
  onCerrar,
}: {
  cliente: Cliente;
  money: (n: number | string | null | undefined) => string;
  onCerrar: () => void;
}) {
  const { data, isLoading } = useQuery<FichaDeCliente>({
    queryKey: ['customer', cliente.id],
    queryFn: () => getCustomer(cliente.id),
  });

  const ficha = data?.customer ?? cliente;
  const pedidos = data?.orders ?? [];

  return (
    <Dialogo titulo={ficha.name} subtitulo={ficha.email} onCerrar={onCerrar} ancho={620} className="page-customers">
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
            <strong>{ficha.favoritos_count ?? 0}</strong>
            <span>favoritos</span>
          </div>
        </div>

        {isLoading ? (
          <div className="state-card">
            <Loader2 size={24} className="spinner" />
          </div>
        ) : pedidos.length === 0 ? (
          <p className="customer-empty">
            Todavía no ha comprado nada. Tiene cuenta, así que puedes escribirle a {ficha.email}.
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
                  <td>{new Date(pedido.created_at).toLocaleDateString()}</td>
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

const etiquetaDeEstado = (estado: string): string =>
  ({
    pending: 'Pendiente',
    processing: 'En proceso',
    attended: 'Atendido',
    cancelled: 'Cancelado',
  })[estado] ?? estado;
