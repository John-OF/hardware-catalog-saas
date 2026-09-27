import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import Imagen from './Imagen';

/**
 * `UI-16`: una imagen que no carga se sustituye por un respaldo local, el mismo
 * que la pantalla pinta cuando no hay imagen. jsdom no descarga nada, así que el
 * fallo se provoca disparando el evento `error` de la imagen, que es lo que hace
 * el navegador con una URL muerta.
 */
describe('<Imagen>', () => {
  it('con URL pinta la imagen, con sus atributos', () => {
    render(<Imagen src="https://cdn.test/a.webp" alt="Ryzen 7" className="foto" loading="lazy" />);

    const img = screen.getByRole('img', { name: 'Ryzen 7' });
    expect(img.tagName).toBe('IMG');
    expect(img).toHaveAttribute('src', 'https://cdn.test/a.webp');
    expect(img).toHaveClass('foto');
    expect(img).toHaveAttribute('loading', 'lazy');
  });

  it('sin URL pinta el respaldo de la pantalla', () => {
    render(<Imagen src={null} alt="Ryzen 7" respaldo={<span>sin foto</span>} />);

    expect(screen.getByText('sin foto')).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
  });

  it('si la imagen no carga, pinta el mismo respaldo que sin URL', () => {
    render(<Imagen src="https://cdn.test/borrada.webp" alt="Ryzen 7" respaldo={<span>sin foto</span>} />);

    fireEvent.error(screen.getByRole('img', { name: 'Ryzen 7' }));

    expect(screen.getByText('sin foto')).toBeInTheDocument();
    expect(document.querySelector('img')).toBeNull();
  });

  it('sin respaldo propio, uno local en la misma caja, que conserva el texto alternativo', () => {
    render(
      <Imagen
        src="https://cdn.test/borrada.webp"
        alt="Ryzen 7"
        className="product-thumb"
        style={{ width: '40px', height: '40px' }}
      />,
    );

    fireEvent.error(screen.getByRole('img', { name: 'Ryzen 7' }));

    // Sigue siendo una imagen con nombre para un lector de pantalla, pero ya
    // no es un <img>: no hay nada que descargar, ni de un tercero.
    const respaldo = screen.getByRole('img', { name: 'Ryzen 7' });
    expect(respaldo.tagName).toBe('SPAN');
    expect(respaldo).toHaveClass('imagen-respaldo', 'product-thumb');
    expect(respaldo).toHaveStyle({ width: '40px', height: '40px' });
  });

  it('con alt vacío, el respaldo por defecto es decorativo', () => {
    const { container } = render(<Imagen src="https://cdn.test/borrada.webp" alt="" />);

    fireEvent.error(container.querySelector('img')!);

    expect(container.querySelector('.imagen-respaldo')).toHaveAttribute('aria-hidden', 'true');
  });

  it('si cambia la URL tras un fallo, intenta la nueva (la galería de la ficha)', () => {
    const { rerender } = render(<Imagen src="https://cdn.test/rota.webp" alt="Foto" respaldo={<span>sin foto</span>} />);
    fireEvent.error(screen.getByRole('img', { name: 'Foto' }));
    expect(screen.getByText('sin foto')).toBeInTheDocument();

    rerender(<Imagen src="https://cdn.test/buena.webp" alt="Foto" respaldo={<span>sin foto</span>} />);

    expect(screen.getByRole('img', { name: 'Foto' })).toHaveAttribute('src', 'https://cdn.test/buena.webp');
  });
});
