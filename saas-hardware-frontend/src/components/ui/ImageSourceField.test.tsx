import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { toast } from 'react-hot-toast';
import ImageSourceField from './ImageSourceField';

vi.mock('react-hot-toast', () => {
  const toast = { success: vi.fn(), error: vi.fn() };
  return { toast, default: toast };
});

/** Un archivo que dice pesar `bytes` sin reservar esa memoria. */
const archivo = (nombre: string, bytes: number, type: string) => {
  const f = new File(['x'], nombre, { type });
  Object.defineProperty(f, 'size', { value: bytes });
  return f;
};

const pintar = (props: Partial<Parameters<typeof ImageSourceField>[0]> = {}) => {
  const onFileChange = vi.fn();
  const utils = render(
    <ImageSourceField
      label="Logo"
      url=""
      onUrlChange={() => {}}
      file={null}
      onFileChange={onFileChange}
      tipo="logo"
      {...props}
    />,
  );
  return { ...utils, onFileChange };
};

const elegirArchivo = async (container: HTMLElement, f: File) => {
  await userEvent.click(screen.getByRole('button', { name: /Subir/ }));
  const input = container.querySelector('input[type="file"]') as HTMLInputElement;
  await userEvent.upload(input, f);
  return input;
};

describe('ImageSourceField (UI-15)', () => {
  it('enseña los formatos y el tope del servidor, sin escribirlos a mano', () => {
    pintar();
    expect(screen.getByText('JPG, PNG o WEBP, máx. 2 MB.')).toBeInTheDocument();
  });

  it('junta la pista propia con la del tope', () => {
    pintar({ tipo: 'favicon', hint: 'Imagen cuadrada pequeña.' });
    expect(screen.getByText('Imagen cuadrada pequeña. PNG o ICO, máx. 512 KB.')).toBeInTheDocument();
  });

  /**
   * Lo que pedía UI-15: avisar ANTES de subir. El archivo no llega al
   * formulario, así que nunca viaja al servidor para rebotar al final.
   */
  it('rechaza un archivo que pasa del tope antes de subirlo', async () => {
    const { container, onFileChange } = pintar();

    const input = await elegirArchivo(container, archivo('logo.png', 3 * 1024 * 1024, 'image/png'));

    expect(onFileChange).not.toHaveBeenCalledWith(expect.any(File));
    expect(vi.mocked(toast.error)).toHaveBeenCalledWith('«logo.png» pesa 3 MB y el máximo es 2 MB.');
    // Vacío, para que elegir otra vez el mismo archivo vuelva a avisar.
    expect(input.value).toBe('');
  });

  it('deja pasar un archivo dentro del tope', async () => {
    const { container, onFileChange } = pintar();
    const bueno = archivo('logo.png', 100 * 1024, 'image/png');

    await elegirArchivo(container, bueno);

    expect(onFileChange).toHaveBeenCalledWith(bueno);
  });
});
