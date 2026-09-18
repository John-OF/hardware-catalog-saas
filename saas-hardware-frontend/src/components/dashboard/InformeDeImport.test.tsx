import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import InformeDeImport from './InformeDeImport';
import type { ImportReport } from '../../api/products';

const informe = (extra: Partial<ImportReport> = {}): ImportReport => ({
  message: 'Proceso completado: se creó 1 producto y se actualizó 1.',
  success_count: 2,
  created_count: 1,
  updated_count: 1,
  unchanged_count: 0,
  skipped_count: 0,
  changes: [],
  errors: [],
  ...extra,
});

describe('InformeDeImport', () => {
  it('dice qué cambió en cada producto, con su fila', () => {
    render(
      <InformeDeImport
        informe={informe({
          changes: [
            { fila: 2, accion: 'creado', producto: 'Kingston NV3', detalle: 'con 2 variantes' },
            { fila: 4, accion: 'actualizado', producto: 'Ryzen 5 7600', detalle: 'precio 900 → 950, stock 1 → 12' },
          ],
        })}
      />,
    );

    const actualizados = screen.getByText('Actualizados (1)').closest('details')!;
    expect(within(actualizados).getByText(/precio 900 → 950, stock 1 → 12/)).toBeInTheDocument();
    expect(within(actualizados).getByText('Fila 4')).toBeInTheDocument();

    const creados = screen.getByText('Creados (1)').closest('details')!;
    expect(within(creados).getByText('Kingston NV3')).toBeInTheDocument();
    expect(within(creados).getByText(/con 2 variantes/)).toBeInTheDocument();
  });

  it('pliega lo que no se tocó y deja abierto lo que se escribió', () => {
    render(
      <InformeDeImport
        informe={informe({
          changes: [
            { fila: 2, accion: 'actualizado', producto: 'A', detalle: 'stock 1 → 2' },
            { fila: 3, accion: 'sin_cambios', producto: 'B', detalle: null },
            { fila: 4, accion: 'omitido', producto: 'C', detalle: null },
          ],
        })}
      />,
    );

    expect(screen.getByText('Actualizados (1)').closest('details')).toHaveAttribute('open');
    expect(screen.getByText('Sin cambios (ya estaban al día) (1)').closest('details')).not.toHaveAttribute('open');
    expect(screen.getByText('Omitidos porque ya existían (1)').closest('details')).not.toHaveAttribute('open');
  });

  it('no pinta los grupos vacíos', () => {
    render(
      <InformeDeImport
        informe={informe({ changes: [{ fila: 2, accion: 'creado', producto: 'A', detalle: null }] })}
      />,
    );

    expect(screen.getByText('Creados (1)')).toBeInTheDocument();
    expect(screen.queryByText(/Actualizados/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Omitidos/)).not.toBeInTheDocument();
  });

  it('enseña los errores junto a lo que sí entró', () => {
    render(
      <InformeDeImport
        informe={informe({
          changes: [{ fila: 2, accion: 'creado', producto: 'A', detalle: null }],
          errors: ['Fila 3: La categoría «Processors» no existe en tu tienda.'],
        })}
      />,
    );

    expect(screen.getByText('Importación con avisos')).toBeInTheDocument();
    expect(screen.getByText(/«Processors» no existe/)).toBeInTheDocument();
    expect(screen.getByText('Creados (1)')).toBeInTheDocument();
  });
});
