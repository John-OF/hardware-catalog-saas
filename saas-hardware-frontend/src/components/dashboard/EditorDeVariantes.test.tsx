import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import EditorDeVariantes from './EditorDeVariantes';
import { varianteVacia, type VarianteEnFormulario } from '../../utils/variantesEnFormulario';

const fila = (clave: string, capacidad: string, esperas: number): VarianteEnFormulario => ({
  ...varianteVacia(['Capacidad']),
  clave,
  id: clave,
  valores: [capacidad],
  price: '60',
  stock: '0',
  esperas,
});

const pintar = () => {
  const onChange = vi.fn();
  render(
    <EditorDeVariantes
      ejes={['Capacidad']}
      filas={[fila('v-16', '16 GB', 0), fila('v-32', '32 GB', 3)]}
      moneda="PEN"
      onChange={onChange}
    />,
  );

  return onChange;
};

const quitar = async (n: number) => {
  await userEvent.setup().click(screen.getAllByTitle('Quitar variante')[n]);
};

/**
 * FUN-25: quitar una variante y guardar borra en cascada a quien la esperaba.
 * Antes nada lo decía; ahora se ve en la fila y se pregunta al quitarla.
 */
describe('EditorDeVariantes: quitar una variante con gente esperando', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('enseña cuántos esperan cada variante, solo donde hay alguien', () => {
    pintar();

    expect(screen.getByText('3 en espera')).toBeInTheDocument();
    expect(screen.getAllByText(/en espera/)).toHaveLength(1);
  });

  it('sin nadie esperando la quita sin preguntar', async () => {
    const confirmar = vi.spyOn(window, 'confirm');
    const onChange = pintar();

    await quitar(0);

    expect(confirmar).not.toHaveBeenCalled();
    expect(onChange).toHaveBeenCalledWith(['Capacidad'], [expect.objectContaining({ clave: 'v-32' })]);
  });

  it('con gente esperando pregunta, y si se cancela no la quita', async () => {
    const confirmar = vi.spyOn(window, 'confirm').mockReturnValue(false);
    const onChange = pintar();

    await quitar(1);

    expect(confirmar).toHaveBeenCalledWith(expect.stringMatching(/^Hay 3 personas esperando a que «32 GB»/));
    expect(onChange).not.toHaveBeenCalled();
  });

  it('si se confirma, la quita', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const onChange = pintar();

    await quitar(1);

    expect(onChange).toHaveBeenCalledWith(['Capacidad'], [expect.objectContaining({ clave: 'v-16' })]);
  });
});
