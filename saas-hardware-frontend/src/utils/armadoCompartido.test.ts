import { describe, expect, it } from 'vitest';
import type { Category } from '../types';
import { unProducto, unaVariante } from '../test/fixtures';
import { armadoDesdeElEnlace, escribirArmadoEnElEnlace, leerArmadoDelEnlace } from './armadoCompartido';

const PASOS = ['cpu', 'motherboard', 'ram'] as const;

const ID_CPU = '01a0c6e3-a7de-7369-91b8-93187c1377f9';
const ID_PLACA = '01a0c6e3-a7dc-7069-ab91-911df4b7d8a8';
const ID_RAM = '01a0c6e3-a7e0-7000-8000-000000000001';
const ID_32GB = '01a0c6e3-a7e0-7000-8000-000000000032';

const categoria = (tipo: Category['component_type']): Category => ({
  id: `c-${tipo}`, tenant_id: 't-1', name: tipo, icon: null, component_type: tipo, sort_order: 0, is_active: true,
});

const ryzen = unProducto({ id: ID_CPU, name: 'Ryzen', category: categoria('cpu') });
const placa = unProducto({ id: ID_PLACA, name: 'B650', category: categoria('motherboard') });
const ram = unProducto({
  id: ID_RAM,
  name: 'Fury',
  category: categoria('ram'),
  variants: [
    unaVariante({ id: '01a0c6e3-a7e0-7000-8000-000000000016', nombre: '16 GB', stock: 0 }),
    unaVariante({ id: ID_32GB, nombre: '32 GB', stock: 4 }),
  ],
});

/** MOD-20: el armado de PC en la URL, para poder compartirlo. */
describe('armado compartido', () => {
  it('escribe cada pieza en su paso, con la variante al lado, sin tocar lo demás', () => {
    const params = escribirArmadoEnElEnlace(
      new URLSearchParams('ref=whatsapp&cpu=viejo'),
      PASOS,
      { cpu: ryzen, ram },
      { cpu: null, ram: ram.variants![1] },
    );

    expect(params.get('ref')).toBe('whatsapp');
    expect(params.get('cpu')).toBe(ID_CPU);
    expect(params.has('cpu_variante')).toBe(false);
    expect(params.get('ram')).toBe(ID_RAM);
    expect(params.get('ram_variante')).toBe(ID_32GB);
    expect(params.has('motherboard')).toBe(false);
  });

  it('lee lo escrito, e ignora pasos que no existen y valores que no son un id', () => {
    const params = new URLSearchParams(`cpu=${ID_CPU}&ram=${ID_RAM}&ram_variante=${ID_32GB}&motherboard=<script>&monitor=${ID_PLACA}`);

    expect(leerArmadoDelEnlace(params, PASOS)).toEqual({
      cpu: { id: ID_CPU, variante: null },
      ram: { id: ID_RAM, variante: ID_32GB },
    });
  });

  it('vuelve a armar las piezas con su producto y su variante', () => {
    const { selecciones, variantes, faltan } = armadoDesdeElEnlace(
      { cpu: { id: ID_CPU, variante: null }, ram: { id: ID_RAM, variante: ID_32GB } },
      [ryzen, ram],
    );

    expect(selecciones).toEqual({ cpu: ryzen, ram });
    expect(variantes.cpu).toBeNull();
    expect(variantes.ram?.nombre).toBe('32 GB');
    expect(faltan).toBe(0);
  });

  it('una variante que ya no existe se cambia por la primera con stock, como en el cajón', () => {
    const { variantes } = armadoDesdeElEnlace({ ram: { id: ID_RAM, variante: '01a0c6e3-0000-7000-8000-000000000000' } }, [ram]);

    expect(variantes.ram?.nombre).toBe('32 GB');
  });

  it('lo que el catálogo ya no tiene, o no es de su paso, no se elige y se cuenta', () => {
    const { selecciones, faltan } = armadoDesdeElEnlace(
      // La placa llega en el paso del procesador; la RAM ya no está publicada.
      { cpu: { id: ID_PLACA, variante: null }, ram: { id: ID_RAM, variante: null } },
      [placa],
    );

    expect(selecciones).toEqual({});
    expect(faltan).toBe(2);
  });
});
