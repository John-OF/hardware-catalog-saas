import { describe, expect, it } from 'vitest';
import type { CampoDeSpec, DefinicionDeSpecs } from '../types';
import {
  alternarEnVarios,
  reacomodarSpecs,
  separarSpecs,
  specsParaMostrar,
  todosLosCampos,
  unirSpecs,
  valorDeSpec,
} from './specsFijas';

const campo = (clave: string, etiqueta: string, tipo: CampoDeSpec['tipo'], extra: Partial<CampoDeSpec> = {}): CampoDeSpec => ({
  clave, etiqueta, tipo, unidad: null, opciones: [], armador: true, ayuda: null, ...extra,
});

const socket = campo('socket', 'Socket', 'lista', { opciones: ['AM4', 'AM5', 'LGA1700'] });
const tdp = campo('tdp', 'TDP / potencia base', 'numero', { unidad: 'W' });
const graficos = campo('graficos_integrados', 'Gráficos integrados', 'si_no');
const sockets = campo('sockets', 'Sockets compatibles', 'varios', { opciones: ['AM4', 'AM5', 'LGA1700'] });
const largo = campo('largo', 'Largo', 'numero', { unidad: 'mm' });

/** Una definición como la que devuelve `GET /public/component-specs`, recortada. */
const definicion: DefinicionDeSpecs = {
  tipos: {
    cpu: [socket, tdp, graficos],
    motherboard: [socket],
    cooling: [sockets],
    gpu: [largo],
  },
};

describe('specsParaMostrar: lo que ve el comprador (FUN-8)', () => {
  it('pinta las fijas con etiqueta y unidad, primero y en el orden de la definición', () => {
    const specs = { Frecuencia: '4.7 GHz', tdp: '105', graficos_integrados: 'si', socket: 'AM5' };

    expect(specsParaMostrar(specs, definicion)).toEqual([
      { clave: 'socket', etiqueta: 'Socket', valor: 'AM5' },
      { clave: 'tdp', etiqueta: 'TDP / potencia base', valor: '105 W' },
      { clave: 'graficos_integrados', etiqueta: 'Gráficos integrados', valor: 'Sí' },
      { clave: 'Frecuencia', etiqueta: 'Frecuencia', valor: '4.7 GHz' },
    ]);
  });

  it('a un valor escrito a mano no le pega la unidad', () => {
    expect(valorDeSpec('tdp', 'unos 105W', todosLosCampos(definicion))).toBe('unos 105W');
  });

  it('sin la definición todavía, enseña las claves tal cual en vez de esconderlas', () => {
    expect(specsParaMostrar({ tdp: '105' }, undefined)).toEqual([{ clave: 'tdp', etiqueta: 'tdp', valor: '105' }]);
  });
});

describe('el formulario: campos fijos y filas libres', () => {
  it('separa por el tipo: una clave fija de OTRO tipo cae en las libres', () => {
    const { fijas, libres } = separarSpecs({ socket: 'AM5', largo: '300', Color: 'Negro' }, definicion.tipos.cpu!);

    expect(fijas).toEqual({ socket: 'AM5' });
    expect(libres).toEqual([{ key: 'largo', value: '300' }, { key: 'Color', value: 'Negro' }]);
  });

  it('al guardar, las filas a medio escribir no viajan y el campo fijo gana a una libre igual', () => {
    expect(unirSpecs({ socket: 'AM5', tdp: '' }, [{ key: 'socket', value: 'AM4' }, { key: 'Color', value: '' }])).toEqual({
      socket: 'AM5',
    });
  });

  it('al cambiar de categoría, lo que el tipo nuevo no pregunta pasa a las libres y nada se pierde', () => {
    const deCpu = { fijas: { socket: 'AM5', tdp: '105' }, libres: [{ key: 'largo', value: '300' }, { key: 'Nueva', value: '' }] };

    const aGpu = reacomodarSpecs(deCpu.fijas, deCpu.libres, definicion.tipos.gpu!);

    expect(aGpu.fijas).toEqual({ largo: '300' });
    expect(aGpu.libres).toEqual([
      { key: 'socket', value: 'AM5' },
      { key: 'tdp', value: '105' },
      { key: 'Nueva', value: '' },
    ]);
  });
});

describe('alternarEnVarios', () => {
  it('guarda en el orden de la lista, como el servidor', () => {
    expect(alternarEnVarios('LGA1700', 'AM4', sockets)).toBe('AM4, LGA1700');
  });

  it('desmarca, y conserva al final lo que no está en la lista para que se pueda quitar', () => {
    expect(alternarEnVarios('AM4, LGA9999', 'AM4', sockets)).toBe('LGA9999');
    expect(alternarEnVarios('LGA9999', 'LGA9999', sockets)).toBe('');
  });
});
