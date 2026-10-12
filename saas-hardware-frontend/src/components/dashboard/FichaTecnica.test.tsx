import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import FichaTecnica from './FichaTecnica';
import type { CampoDeSpec } from '../../types';

const campos: CampoDeSpec[] = [
  { clave: 'socket', etiqueta: 'Socket', tipo: 'lista', unidad: null, opciones: ['AM4', 'AM5'], armador: true, ayuda: null },
  { clave: 'tdp', etiqueta: 'TDP / potencia base', tipo: 'numero', unidad: 'W', opciones: [], armador: true, ayuda: 'AMD lo llama Default TDP.' },
  { clave: 'nucleos', etiqueta: 'Núcleos', tipo: 'numero', unidad: null, opciones: [], armador: false, ayuda: null },
];

const pintar = (valores: Record<string, string>) => {
  const onChange = vi.fn();
  render(<FichaTecnica campos={campos} valores={valores} tipo="Procesadores (CPU)" onChange={onChange} />);
  return onChange;
};

/**
 * FUN-8, 13.3: el dueño elige el socket de una lista en vez de adivinar cómo se
 * llama la spec que busca el armador.
 */
describe('FichaTecnica', () => {
  it('pregunta los campos del tipo, con su lista y su unidad', () => {
    pintar({});

    expect(screen.getByRole('heading', { name: 'Ficha técnica · Procesadores (CPU)' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'AM5' })).toBeInTheDocument();
    expect(screen.getByText('W')).toBeInTheDocument();
    expect(screen.getByText('AMD lo llama Default TDP.')).toBeInTheDocument();
  });

  it('marca con "armador" solo los campos que usa el armador', () => {
    pintar({});

    // Uno en la explicación de arriba y uno por cada campo del armador.
    expect(screen.getAllByText('armador')).toHaveLength(3);
  });

  it('elegir una opción avisa con la clave del campo', async () => {
    const onChange = pintar({});

    await userEvent.setup().selectOptions(screen.getByLabelText(/^Socket/), 'AM5');

    expect(onChange).toHaveBeenCalledWith('socket', 'AM5');
  });

  it('un valor guardado que no está en la lista se enseña con un aviso, no desaparece', () => {
    pintar({ socket: 'Socket AM5' });

    expect(screen.getByRole('option', { name: 'Socket AM5 (no está en la lista)' })).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('«Socket AM5» no está en la lista');
  });

  it('un número escrito a mano se ve, con un aviso, en vez de quedar en blanco', () => {
    pintar({ tdp: 'unos 105W' });

    expect(screen.getByLabelText(/^TDP/)).toHaveValue('unos 105W');
    expect(screen.getByRole('alert')).toHaveTextContent('no es un número entero');
  });

  it('sin campos no pinta nada', () => {
    const { container } = render(<FichaTecnica campos={[]} valores={{}} tipo="" onChange={vi.fn()} />);

    expect(container).toBeEmptyDOMElement();
  });
});
