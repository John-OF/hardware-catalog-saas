import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ReportsPage from './ReportsPage';
import { getReport } from '../../api/reports';
import { exportarReporte } from '../../api/exportaciones';
import type { Reporte } from '../../api/reports';

vi.mock('../../api/reports', () => ({ getReport: vi.fn() }));
vi.mock('../../api/exportaciones', () => ({ exportarReporte: vi.fn() }));

/**
 * Lo que se prueba aquí es lo que distingue esta pantalla de la de Resumen:
 * que el rango viaja al servidor, que el costo solo se pinta si el servidor lo
 * mandó (MOD-6) y que la gráfica tiene una salida legible sin color.
 */
const reporte = (extra: Partial<Reporte> = {}): Reporte => ({
  rango: { desde: '2026-09-01', hasta: '2026-09-03', agrupacion: 'dia' },
  resumen: {
    ventas: 2550,
    envio: 0,
    pedidos: 2,
    ticket_promedio: 1275,
    unidades: 3,
    recibidos: 4,
    cancelados: 1,
  },
  serie: [
    { periodo: '2026-09-01', ventas: 0, pedidos: 0, unidades: 0 },
    { periodo: '2026-09-02', ventas: 1700, pedidos: 1, unidades: 2 },
    { periodo: '2026-09-03', ventas: 850, pedidos: 1, unidades: 1 },
  ],
  mas_vendidos: [{ product_id: 'p-1', nombre: 'Ryzen 5 7600', unidades: 3, ventas: 2550 }],
  stock_bajo: [
    { product_id: 'p-2', nombre: 'Fuente 600W', sku: 'PSU-600', stock: 2, umbral: 5 },
    { product_id: 'p-3', nombre: 'Memoria Kingston (32 GB)', sku: null, stock: 0, umbral: 3 },
  ],
  ...extra,
});

/** El mismo reporte tal como lo ve un admin: con costo y utilidad. */
const conMargen = (): Reporte => {
  const base = reporte();

  return {
    ...base,
    resumen: { ...base.resumen, costo: 2100, utilidad: 450, margen: 17.6, lineas_sin_costo: 0 },
    serie: base.serie.map((p) => ({ ...p, utilidad: p.ventas * 0.176 })),
    mas_vendidos: base.mas_vendidos.map((p) => ({ ...p, utilidad: 450 })),
  };
};

const abrir = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <ReportsPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );

describe('ReportsPage (MOD-9)', () => {
  beforeEach(() => {
    vi.mocked(getReport).mockReset();
    vi.mocked(exportarReporte).mockReset().mockResolvedValue(undefined);
  });

  it('enseña las cifras del rango y separa lo recibido de lo vendido', async () => {
    vi.mocked(getReport).mockResolvedValue(reporte());
    abrir();

    // Por el <h3> y no por el texto suelto: la misma cifra sale también en la
    // lista de lo más vendido, y lo que se comprueba aquí es la cabecera.
    expect(await screen.findByRole('heading', { level: 3, name: '$2,550.00' })).toBeInTheDocument();
    // Un pedido cancelado no es una venta, pero sí entró: las dos cosas se ven.
    expect(screen.getByText('4 recibidos · 1 cancelados')).toBeInTheDocument();
  });

  it('a quien no puede ver el costo no le pinta ni la utilidad ni el margen', async () => {
    vi.mocked(getReport).mockResolvedValue(reporte());
    abrir();

    await screen.findByText('Ventas');

    expect(screen.queryByText('Utilidad')).not.toBeInTheDocument();
    expect(screen.queryByText(/margen/i)).not.toBeInTheDocument();
  });

  it('con el costo delante enseña la utilidad y el margen del rango', async () => {
    vi.mocked(getReport).mockResolvedValue(conMargen());
    abrir();

    // "Utilidad" aparece dos veces cuando hay margen -la cifra y la leyenda de
    // la gráfica-, así que se busca la cifra.
    expect(await screen.findByRole('heading', { level: 3, name: '$450.00' })).toBeInTheDocument();
    expect(screen.getByText('17.6% de margen')).toBeInTheDocument();
  });

  it('avisa cuando la utilidad está incompleta en vez de darla por buena', async () => {
    const base = conMargen();
    vi.mocked(getReport).mockResolvedValue({
      ...base,
      resumen: { ...base.resumen, lineas_sin_costo: 2 },
    });
    abrir();

    expect(await screen.findByText(/2 línea\(s\) sin costo/)).toBeInTheDocument();
  });

  it('pide al servidor el rango que se elige', async () => {
    vi.mocked(getReport).mockResolvedValue(reporte());
    abrir();

    await screen.findByText('Ventas');
    await userEvent.click(screen.getByRole('button', { name: '7 días' }));

    await vi.waitFor(() => {
      const ultima = vi.mocked(getReport).mock.calls.at(-1)?.[0];
      expect(ultima?.agrupacion).toBe('dia');
      expect(ultima?.desde).not.toBe(ultima?.hasta);
    });
  });

  it('agrupar por mes se le pide al servidor, no se calcula en el navegador', async () => {
    vi.mocked(getReport).mockResolvedValue(reporte());
    abrir();

    await screen.findByText('Ventas');
    await userEvent.selectOptions(screen.getByRole('combobox'), 'mes');

    await vi.waitFor(() => {
      expect(vi.mocked(getReport).mock.calls.at(-1)?.[0]?.agrupacion).toBe('mes');
    });
  });

  it('la serie se puede leer como tabla, no solo como gráfica', async () => {
    vi.mocked(getReport).mockResolvedValue(reporte());
    abrir();

    await screen.findByText('Ventas');
    await userEvent.click(screen.getByRole('button', { name: /ver como tabla/i }));

    const tabla = screen.getByRole('table');
    expect(within(tabla).getByText('$1,700.00')).toBeInTheDocument();
    expect(within(tabla).getByText('$850.00')).toBeInTheDocument();
  });

  it('lo más vendido va por unidades y lo agotado se dice con palabras', async () => {
    vi.mocked(getReport).mockResolvedValue(reporte());
    abrir();

    expect(await screen.findByText('Ryzen 5 7600')).toBeInTheDocument();
    expect(screen.getByText('3 unidad(es)')).toBeInTheDocument();

    // Una variante bajo mínimos se ve aunque su ficha sume de sobra (MOD-5).
    expect(screen.getByText('Memoria Kingston (32 GB)')).toBeInTheDocument();
    expect(screen.getByText('Agotado')).toBeInTheDocument();
    expect(screen.getByText('2 u.')).toBeInTheDocument();
  });

  it('exporta el mismo rango que se está mirando', async () => {
    vi.mocked(getReport).mockResolvedValue(reporte());
    abrir();

    await screen.findByText('Ventas');
    await userEvent.click(screen.getByRole('button', { name: /exportar csv/i }));

    await vi.waitFor(() => {
      expect(exportarReporte).toHaveBeenCalledWith(
        expect.objectContaining({ agrupacion: 'dia' }),
      );
    });
  });

  it('cuando el servidor rechaza el rango enseña su motivo, no un error genérico', async () => {
    vi.mocked(getReport).mockRejectedValue({
      response: { data: { message: 'Por día no se pueden pedir más de 366 días seguidos. Agrupa por mes.' } },
    });
    abrir();

    expect(await screen.findByText(/no se pueden pedir más de 366 días/i)).toBeInTheDocument();
  });

  it('un rango sin ventas lo dice en vez de dejar las listas en blanco', async () => {
    vi.mocked(getReport).mockResolvedValue({
      ...reporte(),
      mas_vendidos: [],
      stock_bajo: [],
    });
    abrir();

    expect(await screen.findByText('No hubo ventas atendidas en este rango.')).toBeInTheDocument();
    expect(screen.getByText('Nada por debajo de su umbral. Es una buena noticia.')).toBeInTheDocument();
  });
});
