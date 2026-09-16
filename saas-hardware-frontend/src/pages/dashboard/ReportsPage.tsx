import './ReportsPage.css';

import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  Download,
  Loader2,
  Package,
  Receipt,
  ShoppingBag,
  Table,
  TrendingUp,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { getReport, type Agrupacion, type PuntoDelReporte, type Reporte } from '../../api/reports';
import { exportarReporte } from '../../api/exportaciones';
import { useTenantStore } from '../../stores/tenantStore';
import { formatMoney } from '../../utils/money';

/**
 * Reportes de la tienda (MOD-9).
 *
 * La pantalla de Resumen enseña totales desde el principio de los tiempos y los
 * productos más *vistos*. Con eso no se decide nada: el dueño necesita saber
 * cuánto vendió **en un rango**, **qué** se vendió y **qué se le está acabando**.
 * Esta pantalla son esas tres preguntas, en ese orden.
 *
 * **El costo y el margen solo aparecen si el servidor los mandó.** No se mira el
 * rol aquí: el backend no incluye esas claves para staff (MOD-6), así que la
 * pantalla pregunta si vinieron. Si algún día cambia quién puede verlos, cambia
 * en un sitio y no en dos.
 */
export default function ReportsPage() {
  const tenant = useTenantStore((s) => s.tenant);
  const money = (n: number) => formatMoney(n, tenant?.currency ?? 'USD');

  const [desde, setDesde] = useState(() => haceDias(29));
  const [hasta, setHasta] = useState(() => hoy());
  const [agrupacion, setAgrupacion] = useState<Agrupacion>('dia');
  const [comoTabla, setComoTabla] = useState(false);
  const [descargando, setDescargando] = useState(false);

  const { data, isLoading, isError, error } = useQuery<Reporte>({
    queryKey: ['reports', desde, hasta, agrupacion],
    queryFn: () => getReport({ desde, hasta, agrupacion }),
  });

  const descargar = async () => {
    setDescargando(true);

    try {
      await exportarReporte({ desde, hasta, agrupacion });
    } catch {
      toast.error('No se pudo descargar el reporte.');
    } finally {
      setDescargando(false);
    }
  };

  // Las claves de costo llegan o no llegan; no hay estado intermedio.
  const conMargen = data?.resumen.utilidad !== undefined;

  if (isLoading) {
    return (
      <div className="reports-loading page-reports">
        <Loader2 className="spinner" size={32} />
        <p>Calculando el reporte...</p>
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div className="reports-error glass-card page-reports">
        <h3>No se pudo calcular el reporte</h3>
        <p>{mensajeDeError(error)}</p>
      </div>
    );
  }

  const { resumen, serie, mas_vendidos: masVendidos, stock_bajo: stockBajo } = data;

  return (
    <div className="reports-page animate-fade-in page-reports">
      <div className="page-header">
        <div>
          <h1>Reportes</h1>
          <p className="page-description">
            Cuánto vendiste en un rango de fechas, qué se vendió y qué se está acabando. Cuentan
            solo los pedidos <strong>atendidos</strong>, por su fecha de entrada.
          </p>
        </div>
      </div>

      {/* Los filtros, en una sola fila y encima de todo lo que filtran. */}
      <div className="glass-card reports-filters">
        <label className="filtro">
          <span>Desde</span>
          <input
            type="date"
            className="premium-input"
            value={desde}
            max={hasta}
            onChange={(e) => setDesde(e.target.value)}
          />
        </label>

        <label className="filtro">
          <span>Hasta</span>
          <input
            type="date"
            className="premium-input"
            value={hasta}
            min={desde}
            onChange={(e) => setHasta(e.target.value)}
          />
        </label>

        <label className="filtro">
          <span>Agrupar por</span>
          <select
            className="premium-input"
            value={agrupacion}
            onChange={(e) => setAgrupacion(e.target.value as Agrupacion)}
          >
            <option value="dia">Día</option>
            <option value="mes">Mes</option>
          </select>
        </label>

        <div className="atajos">
          <button type="button" className="btn-secondary" onClick={() => rango(7, 'dia')}>
            7 días
          </button>
          <button type="button" className="btn-secondary" onClick={() => rango(30, 'dia')}>
            30 días
          </button>
          <button type="button" className="btn-secondary" onClick={() => rango(365, 'mes')}>
            12 meses
          </button>
        </div>

        <button
          type="button"
          className="btn-primary exportar"
          onClick={descargar}
          disabled={descargando}
        >
          {descargando ? <Loader2 size={16} className="spinner" /> : <Download size={16} />}
          <span>Exportar CSV</span>
        </button>
      </div>

      {/* Las cifras del rango. */}
      <div className="kpi-grid">
        <Kpi etiqueta="Ventas" valor={money(resumen.ventas)} icono={<Receipt size={20} />}
          pie={resumen.envio > 0 ? `${money(resumen.envio)} de envío incluidos` : 'Pedidos atendidos'} />

        <Kpi etiqueta="Pedidos" valor={String(resumen.pedidos)} icono={<ShoppingBag size={20} />}
          pie={`${resumen.recibidos} recibidos · ${resumen.cancelados} cancelados`} />

        <Kpi etiqueta="Ticket promedio" valor={money(resumen.ticket_promedio)} icono={<TrendingUp size={20} />}
          pie={`${resumen.unidades} unidades vendidas`} />

        {conMargen && (
          <Kpi
            etiqueta="Utilidad"
            valor={money(resumen.utilidad ?? 0)}
            icono={<TrendingUp size={20} />}
            pie={
              resumen.lineas_sin_costo
                ? `${resumen.margen}% de margen · ${resumen.lineas_sin_costo} línea(s) sin costo`
                : `${resumen.margen}% de margen`
            }
            aviso={Boolean(resumen.lineas_sin_costo)}
          />
        )}
      </div>

      {/* La gráfica. */}
      <div className="glass-card grafica-card">
        <div className="column-header">
          <h4>Ventas por {agrupacion === 'mes' ? 'mes' : 'día'}</h4>
          <button
            type="button"
            className="btn-secondary ver-tabla"
            onClick={() => setComoTabla((v) => !v)}
          >
            <Table size={14} /> {comoTabla ? 'Ver gráfica' : 'Ver como tabla'}
          </button>
        </div>

        {comoTabla ? (
          <TablaDeLaSerie serie={serie} agrupacion={agrupacion} conMargen={conMargen} money={money} />
        ) : (
          <GraficaDeVentas serie={serie} agrupacion={agrupacion} conMargen={conMargen} money={money} />
        )}
      </div>

      <div className="reports-split">
        {/* Más vendidos. */}
        <div className="split-column glass-card">
          <div className="column-header">
            <h4>Lo más vendido</h4>
            <span className="column-hint">por unidades</span>
          </div>

          <div className="list-container">
            {masVendidos.length === 0 ? (
              <p className="empty-text">No hubo ventas atendidas en este rango.</p>
            ) : (
              masVendidos.map((p, i) => (
                <div key={`${p.product_id ?? 'borrado'}-${p.nombre}`} className="list-item-row">
                  <div className="item-left">
                    <span className="puesto">{i + 1}</span>
                    <div className="info-texto">
                      <h5>{p.nombre}</h5>
                      <span>{p.unidades} unidad(es)</span>
                    </div>
                  </div>
                  <div className="item-right">
                    <strong>{money(p.ventas)}</strong>
                    {p.utilidad !== undefined && (
                      <span className="utilidad-pill">{money(p.utilidad)} de utilidad</span>
                    )}
                  </div>
                </div>
              ))
            )}
          </div>
        </div>

        {/* Stock bajo. */}
        <div className="split-column glass-card">
          <div className="column-header">
            <h4>Se está acabando</h4>
            <span className="column-hint">según el umbral de cada ficha</span>
          </div>

          <div className="list-container">
            {stockBajo.length === 0 ? (
              <p className="empty-text">Nada por debajo de su umbral. Es una buena noticia.</p>
            ) : (
              stockBajo.map((p) => (
                <div key={`${p.product_id}-${p.nombre}`} className="list-item-row">
                  <div className="item-left">
                    <div className="icono-stock">
                      {p.stock <= 0 ? <AlertTriangle size={16} /> : <Package size={16} />}
                    </div>
                    <div className="info-texto">
                      <h5>{p.nombre}</h5>
                      <span>{p.sku || 'Sin SKU'}</span>
                    </div>
                  </div>
                  <div className="item-right">
                    <span className={`stock-pill ${p.stock <= 0 ? 'agotado' : 'bajo'}`}>
                      {p.stock <= 0 ? 'Agotado' : `${p.stock} u.`}
                    </span>
                    <span className="umbral">umbral {p.umbral}</span>
                  </div>
                </div>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );

  /** Un atajo de rango: los últimos N días, con la agrupación que le pega. */
  function rango(dias: number, como: Agrupacion) {
    setDesde(haceDias(dias - 1));
    setHasta(hoy());
    setAgrupacion(como);
  }
}

/* -------------------------------------------------------------- la gráfica */

/**
 * Barras de ventas por periodo y, si el servidor mandó la utilidad, una línea
 * encima con ella.
 *
 * **Un solo eje**: las dos series son dinero de la misma tienda, y la utilidad
 * nunca es mayor que la venta, así que comparten escala sin mentir. Dos ejes
 * harían que cualquier cruce de las dos líneas significara algo que no es.
 *
 * La identidad no va solo por color: la venta es una barra y la utilidad una
 * línea con marcadores, además de la leyenda. Quien no distinga el azul del
 * verde sigue leyendo la gráfica por la forma.
 *
 * **El ancho se mide, no se supone.** El primer intento tenía un `viewBox` fijo
 * estirado con CSS, que es más corto de escribir; en el panel con el menú
 * plegado la gráfica pasaba de 760 a 1.500 px y el navegador escalaba TODO,
 * incluido el texto: las etiquetas del eje salían al doble de tamaño que el
 * resto de la pantalla. Midiendo el contenedor, el `viewBox` va en píxeles
 * reales y 11 px son 11 px a cualquier ancho.
 */
function GraficaDeVentas({
  serie,
  agrupacion,
  conMargen,
  money,
}: {
  serie: PuntoDelReporte[];
  agrupacion: Agrupacion;
  conMargen: boolean;
  money: (n: number) => string;
}) {
  const [encima, setEncima] = useState<number | null>(null);
  const lienzo = useRef<HTMLDivElement>(null);
  const ANCHO = useAncho(lienzo, 760);

  const ALTO = 280;
  const MARGEN = { arriba: 16, derecha: 16, abajo: 34, izquierda: 62 };

  const anchoUtil = ANCHO - MARGEN.izquierda - MARGEN.derecha;
  const altoUtil = ALTO - MARGEN.arriba - MARGEN.abajo;

  const tope = useMemo(() => techo(Math.max(...serie.map((p) => p.ventas), 0)), [serie]);
  const banda = serie.length > 0 ? anchoUtil / serie.length : anchoUtil;

  // Ancho de barra: la banda menos el hueco de 2px que pide el sistema, con un
  // techo para que tres meses no salgan como tres columnas gordas.
  const anchoBarra = Math.min(Math.max(banda - 2, 1), 42);

  const y = (valor: number) => MARGEN.arriba + altoUtil - (tope === 0 ? 0 : (valor / tope) * altoUtil);
  const xCentro = (i: number) => MARGEN.izquierda + banda * i + banda / 2;

  const marcas = [0, 0.25, 0.5, 0.75, 1].map((f) => tope * f);
  const mejor = serie.reduce((mejorHasta, p, i) => (p.ventas > (serie[mejorHasta]?.ventas ?? -1) ? i : mejorHasta), 0);

  // Ni una etiqueta por punto: con 30 días se solapan y no se lee ninguna.
  const cadaCuantas = Math.max(1, Math.ceil(serie.length / 8));

  const punto = encima !== null ? serie[encima] : null;

  if (serie.length === 0) {
    return <p className="empty-text">Sin datos en este rango.</p>;
  }

  return (
    <div className="grafica">
      {conMargen && (
        <div className="leyenda">
          <span className="clave">
            <span className="muestra barra" /> Ventas
          </span>
          <span className="clave">
            <span className="muestra linea" /> Utilidad
          </span>
        </div>
      )}

      <div className="lienzo" ref={lienzo}>
        <svg
          viewBox={`0 0 ${ANCHO} ${ALTO}`}
          width={ANCHO}
          height={ALTO}
          role="img"
          aria-label={`Ventas por ${agrupacion}`}
        >
          {/* Rejilla y eje: recesivos, por detrás de todo. */}
          {marcas.map((valor, i) => (
            <g key={i}>
              <line
                className="rejilla"
                x1={MARGEN.izquierda}
                x2={ANCHO - MARGEN.derecha}
                y1={y(valor)}
                y2={y(valor)}
              />
              <text className="etiqueta-eje" x={MARGEN.izquierda - 8} y={y(valor) + 4} textAnchor="end">
                {compacto(valor)}
              </text>
            </g>
          ))}

          {/* Las barras. */}
          {serie.map((p, i) => {
            const alto = MARGEN.arriba + altoUtil - y(p.ventas);

            return (
              <path
                key={p.periodo}
                className={`barra ${encima === i ? 'activa' : ''}`}
                d={barra(xCentro(i) - anchoBarra / 2, y(p.ventas), anchoBarra, alto, 4)}
              />
            );
          })}

          {/* La utilidad, encima y con su propia forma. */}
          {conMargen && (
            <>
              <path className="linea-utilidad" d={linea(serie.map((p, i) => [xCentro(i), y(p.utilidad ?? 0)]))} />
              {serie.length <= 14 &&
                serie.map((p, i) => (
                  <circle
                    key={p.periodo}
                    className="marcador"
                    cx={xCentro(i)}
                    cy={y(p.utilidad ?? 0)}
                    r={4.5}
                  />
                ))}
            </>
          )}

          {/* Etiqueta directa sobre el mejor periodo, no sobre todos. */}
          {serie[mejor] && serie[mejor].ventas > 0 && (
            <text
              className="etiqueta-directa"
              x={xCentro(mejor)}
              y={y(serie[mejor].ventas) - 8}
              textAnchor="middle"
            >
              {compacto(serie[mejor].ventas)}
            </text>
          )}

          {/* Eje de tiempo. */}
          {serie.map((p, i) =>
            i % cadaCuantas === 0 ? (
              <text
                key={p.periodo}
                className="etiqueta-eje"
                x={xCentro(i)}
                y={ALTO - 12}
                textAnchor="middle"
              >
                {etiqueta(p.periodo, agrupacion)}
              </text>
            ) : null,
          )}

          {/* La capa que escucha: bandas invisibles más anchas que la barra, para
              que no haga falta acertarle a una columna de tres píxeles. */}
          {serie.map((p, i) => (
            <rect
              key={p.periodo}
              className="zona"
              x={MARGEN.izquierda + banda * i}
              y={MARGEN.arriba}
              width={banda}
              height={altoUtil}
              onMouseEnter={() => setEncima(i)}
              onMouseLeave={() => setEncima(null)}
            />
          ))}

          {encima !== null && (
            <line
              className="cruz"
              x1={xCentro(encima)}
              x2={xCentro(encima)}
              y1={MARGEN.arriba}
              y2={MARGEN.arriba + altoUtil}
            />
          )}
        </svg>

        {punto && (
          <div
            className="globo"
            style={{ left: `${((xCentro(encima ?? 0)) / ANCHO) * 100}%` }}
            role="status"
          >
            <strong>{etiquetaLarga(punto.periodo, agrupacion)}</strong>
            <span>{money(punto.ventas)} en ventas</span>
            <span>
              {punto.pedidos} pedido(s) · {punto.unidades} unidad(es)
            </span>
            {punto.utilidad !== undefined && <span>{money(punto.utilidad)} de utilidad</span>}
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * La misma serie en tabla.
 *
 * No es un extra: es lo que hace legible el reporte para quien usa lector de
 * pantalla o no distingue las dos series, y de paso es lo que se copia a una
 * hoja de cálculo sin descargar nada.
 */
function TablaDeLaSerie({
  serie,
  agrupacion,
  conMargen,
  money,
}: {
  serie: PuntoDelReporte[];
  agrupacion: Agrupacion;
  conMargen: boolean;
  money: (n: number) => string;
}) {
  return (
    <div className="tabla-serie">
      <table>
        <thead>
          <tr>
            <th>{agrupacion === 'mes' ? 'Mes' : 'Fecha'}</th>
            <th>Ventas</th>
            <th>Pedidos</th>
            <th>Unidades</th>
            {conMargen && <th>Utilidad</th>}
          </tr>
        </thead>
        <tbody>
          {serie.map((p) => (
            <tr key={p.periodo}>
              <td>{etiquetaLarga(p.periodo, agrupacion)}</td>
              <td>{money(p.ventas)}</td>
              <td>{p.pedidos}</td>
              <td>{p.unidades}</td>
              {conMargen && <td>{money(p.utilidad ?? 0)}</td>}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Kpi({
  etiqueta,
  valor,
  pie,
  icono,
  aviso,
}: {
  etiqueta: string;
  valor: string;
  pie: string;
  icono: React.ReactNode;
  aviso?: boolean;
}) {
  return (
    <div className="kpi-card glass-card">
      <div className="kpi-icon-wrapper">{icono}</div>
      <div className="kpi-data">
        <span className="kpi-label">{etiqueta}</span>
        <h3 className="kpi-value">{valor}</h3>
        <span className={`kpi-pie ${aviso ? 'aviso' : ''}`}>{pie}</span>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------- ayudantes */

/**
 * El ancho real del contenedor, en píxeles, y una medida nueva cada vez que
 * cambia (menú que se pliega, ventana que se redimensiona).
 *
 * `ResizeObserver` no existe en jsdom ni en un render de servidor, así que sin
 * él se usa el ancho de repuesto en vez de romper: la gráfica sale con la
 * proporción de siempre y nadie ve un error.
 */
function useAncho(referencia: React.RefObject<HTMLElement | null>, porDefecto: number): number {
  const [ancho, setAncho] = useState(porDefecto);

  useEffect(() => {
    const nodo = referencia.current;

    if (!nodo || typeof ResizeObserver === 'undefined') return;

    const observador = new ResizeObserver(([entrada]) => {
      // Un mínimo para que en un contenedor diminuto -o mientras la pestaña
      // está oculta y mide 0- las cuentas no den negativos.
      setAncho(Math.max(entrada.contentRect.width, 320));
    });

    observador.observe(nodo);

    return () => observador.disconnect();
  }, [referencia]);

  return ancho;
}

const hoy = () => new Date().toISOString().slice(0, 10);

const haceDias = (dias: number) => {
  const fecha = new Date();
  fecha.setDate(fecha.getDate() - dias);
  return fecha.toISOString().slice(0, 10);
};

/**
 * Un tope "redondo" para el eje.
 *
 * Sin esto la barra más alta toca el borde y el eje queda en 1.837, que no dice
 * nada. Se sube al siguiente múltiplo limpio del orden de magnitud.
 */
function techo(maximo: number): number {
  if (maximo <= 0) return 0;

  const orden = 10 ** Math.floor(Math.log10(maximo));
  return Math.ceil(maximo / (orden / 2)) * (orden / 2);
}

/** "1,8 k" en el eje: el importe exacto vive en el globo y en la tabla. */
function compacto(valor: number): string {
  if (valor >= 1000) return `${(valor / 1000).toFixed(valor >= 10000 ? 0 : 1)} k`;

  return String(Math.round(valor));
}

/** Barra con las dos esquinas de arriba redondeadas y el pie anclado a la base. */
function barra(x: number, y: number, ancho: number, alto: number, radio: number): string {
  if (alto <= 0) return '';

  const r = Math.min(radio, ancho / 2, alto);

  return `M ${x} ${y + alto} L ${x} ${y + r} Q ${x} ${y} ${x + r} ${y} L ${x + ancho - r} ${y} Q ${x + ancho} ${y} ${x + ancho} ${y + r} L ${x + ancho} ${y + alto} Z`;
}

function linea(puntos: Array<[number, number]>): string {
  return puntos.map(([x, y], i) => `${i === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ');
}

/** "15 sep" o "sep 26", lo corto, para el eje. */
function etiqueta(periodo: string, agrupacion: Agrupacion): string {
  const fecha = new Date(`${periodo}${agrupacion === 'mes' ? '-01' : ''}T00:00:00`);

  return agrupacion === 'mes'
    ? fecha.toLocaleDateString('es', { month: 'short', year: '2-digit' })
    : fecha.toLocaleDateString('es', { day: 'numeric', month: 'short' });
}

/** La misma fecha entera, para el globo y la tabla. */
function etiquetaLarga(periodo: string, agrupacion: Agrupacion): string {
  const fecha = new Date(`${periodo}${agrupacion === 'mes' ? '-01' : ''}T00:00:00`);

  return agrupacion === 'mes'
    ? fecha.toLocaleDateString('es', { month: 'long', year: 'numeric' })
    : fecha.toLocaleDateString('es', { day: 'numeric', month: 'long', year: 'numeric' });
}

/**
 * El rango demasiado largo lo rechaza el servidor con un 422 y un motivo
 * escrito; enseñarlo es más útil que "error al cargar".
 */
function mensajeDeError(error: unknown): string {
  const respuesta = (error as { response?: { data?: { message?: string } } })?.response;

  return respuesta?.data?.message ?? 'Inténtalo de nuevo en un momento.';
}
