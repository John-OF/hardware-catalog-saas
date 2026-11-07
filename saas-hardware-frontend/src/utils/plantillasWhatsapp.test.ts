import { describe, expect, it } from 'vitest';
import {
  enlaceDeProducto,
  enlaceDeSeguimiento,
  enlaceDeWhatsapp,
  plantillaDelEstado,
  rellenarPlantilla,
  textoDeEntrega,
  valoresDePedido,
  variablesQueNoAdmite,
} from './plantillasWhatsapp';
import { PLANTILLAS_DEL_COMPRADOR, unMetodo, unaTienda } from '../test/fixtures';
import type { Order } from '../types';

/**
 * Cómo se rellenan los mensajes de WhatsApp (MOD-25). La regla que más importa
 * es la de las líneas vacías: con ella una plantilla sin condicionales sirve
 * para la tienda que reparte y para la que no, con nota y sin nota.
 */
describe('rellenarPlantilla', () => {
  it('pone cada variable, también si se repite', () => {
    expect(rellenarPlantilla('Hola {cliente}, {cliente}: tu pedido {pedido}.', { cliente: 'Ana', pedido: '#7' }))
      .toBe('Hola Ana, Ana: tu pedido #7.');
  });

  it('quita la línea cuyas variables quedan todas vacías, y junta los huecos', () => {
    const texto = 'Nombre: {cliente}\nEntrega: {entrega}\nNota: {nota}\n\nFormas de pago: {pagos}\n\n(Pedido {pedido})';

    expect(rellenarPlantilla(texto, { cliente: 'Ana', entrega: '', nota: '   ', pagos: null, pedido: '#7' }))
      .toBe('Nombre: Ana\n\n(Pedido #7)');
  });

  it('deja la línea si al menos una de sus variables tiene valor', () => {
    expect(rellenarPlantilla('Entrega: {entrega} · Nota: {nota}\nFin', { entrega: 'Recojo en tienda', nota: '' }))
      .toBe('Entrega: Recojo en tienda · Nota: \nFin');
  });

  it('nunca quita una línea sin variables', () => {
    expect(rellenarPlantilla('¡Gracias!\n\nNota: {nota}', { nota: '' })).toBe('¡Gracias!');
  });

  it('una variable de varias líneas entra entera', () => {
    expect(rellenarPlantilla('Pedido:\n{productos}\nFin', { productos: '• 1 x A\n• 2 x B' }))
      .toBe('Pedido:\n• 1 x A\n• 2 x B\nFin');
  });

  it('una variable que no conoce se queda escrita, y su línea no se quita', () => {
    expect(rellenarPlantilla('Hola {clinete}', { cliente: 'Ana' })).toBe('Hola {clinete}');
  });

  it('acepta saltos de Windows y no deja blancos en los bordes', () => {
    expect(rellenarPlantilla('\r\nHola {cliente}\r\n\r\n\r\n\r\nAdiós\r\n', { cliente: 'Ana' })).toBe('Hola Ana\n\nAdiós');
  });

  it('con el pedido de fábrica, sin envío ni pagos ni nota, queda limpio', () => {
    const mensaje = rellenarPlantilla(PLANTILLAS_DEL_COMPRADOR.pedido_nuevo, {
      tienda: 'Tienda Demo', pedido: '#42', productos: '• 1 x Mouse — $10.00', total: '$10.00',
      cliente: 'Ana', entrega: '', nota: '', pagos: '', seguimiento: '',
    });

    expect(mensaje).toBe('Hola Tienda Demo, quiero hacer este pedido:\n\n• 1 x Mouse — $10.00\n\n*Total: $10.00*\n\nNombre: Ana\n\n(Pedido #42)');
  });
});

describe('variablesQueNoAdmite', () => {
  it('dice las que sobran, una vez cada una', () => {
    expect(variablesQueNoAdmite('{cliente} {clinete} {piezas} {clinete}', ['cliente', 'pedido'])).toEqual(['clinete', 'piezas']);
    expect(variablesQueNoAdmite('Sin variables', ['cliente'])).toEqual([]);
  });
});

describe('enlaceDeWhatsapp', () => {
  it('deja el número en dígitos: con espacios y guiones el enlace se rompía', () => {
    expect(enlaceDeWhatsapp('+51 999-888 777')).toBe('https://wa.me/51999888777');
  });

  it('lleva el texto codificado, y sin texto abre el chat vacío', () => {
    const url = new URL(enlaceDeWhatsapp('+51999888777', 'Hola & adiós\n#1'));
    expect(url.searchParams.get('text')).toBe('Hola & adiós\n#1');
    expect(enlaceDeWhatsapp('+51999888777', '')).toBe('https://wa.me/51999888777');
  });
});

describe('mensajes de un pedido desde el panel', () => {
  const pedido = {
    id: 'o-1', number: 47, customer_name: 'Ana Pérez', customer_phone: '+51987654321',
    customer_email: null, customer_note: null, status: 'attended', total: 215,
    delivery_method: 'delivery', delivery_cost: 15,
    tracking_token: 'T'.repeat(40),
    items: [
      { id: 'i-1', product_id: 'p-1', product_name: 'Kingston Fury', variant_name: '32 GB', unit_price: 100, quantity: 2, subtotal: 200 },
    ],
    created_at: '2026-10-30T10:00:00Z',
  } as Order;

  it('cada estado tiene su mensaje', () => {
    expect(plantillaDelEstado('pending')).toBe('pedido_recibido');
    expect(plantillaDelEstado('processing')).toBe('pedido_en_proceso');
    expect(plantillaDelEstado('attended')).toBe('pedido_listo');
    expect(plantillaDelEstado('cancelled')).toBe('pedido_cancelado');
  });

  it('arma las variables con el número, los productos, la entrega y los pagos activos', () => {
    const tienda = unaTienda({
      payment_methods: [
        unMetodo({ name: 'Yape', details: '987654321 · Ana' }),
        unMetodo({ name: 'Efectivo contra entrega', enabled: false }),
      ],
    });

    expect(valoresDePedido(pedido, tienda)).toEqual({
      cliente: 'Ana Pérez',
      tienda: 'Tienda Demo',
      pedido: '#47',
      productos: '• 2 x Kingston Fury (32 GB) — $200.00',
      total: '$215.00',
      entrega: 'Delivery ($15.00)',
      pagos: 'Yape (987654321 · Ana)',
      seguimiento: `${window.location.origin}/tienda-demo/pedido/${'T'.repeat(40)}`,
      motivo: '',
    });
  });

  it('MOD-26: el motivo del rechazo sale en su variable', () => {
    expect(valoresDePedido({ ...pedido, payment_rejection_reason: 'El monto no coincide' }, unaTienda()).motivo).toBe('El monto no coincide');
  });

  it('MOD-26: el enlace de seguimiento usa el dominio propio verificado, y sin token no hay enlace', () => {
    const conDominio = unaTienda({ custom_domain: 'mitienda.com', custom_domain_verified_at: '2026-10-01T00:00:00Z' });

    expect(enlaceDeSeguimiento(conDominio, 'abc')).toBe('https://mitienda.com/pedido/abc');
    expect(enlaceDeSeguimiento(unaTienda({ custom_domain: 'mitienda.com', custom_domain_verified_at: null }), 'abc')).toBe(`${window.location.origin}/tienda-demo/pedido/abc`);
    expect(enlaceDeSeguimiento(unaTienda(), undefined)).toBe('');
  });

  it('sin forma de entrega (mostrador, o la tienda no reparte) la entrega queda vacía', () => {
    expect(textoDeEntrega(null, '$0.00')).toBe('');
    expect(textoDeEntrega('pickup', '$0.00')).toBe('Recojo en tienda');
    expect(valoresDePedido({ ...pedido, delivery_method: null }, unaTienda()).entrega).toBe('');
  });
});

describe('enlaceDeProducto', () => {
  it('con dominio propio verificado usa ese dominio', () => {
    const tienda = unaTienda({ custom_domain: 'tienda.example.com', custom_domain_verified_at: '2026-10-01T00:00:00Z' });
    expect(enlaceDeProducto(tienda, 'p-1')).toBe('https://tienda.example.com/product/p-1');
  });

  it('sin verificar, la del catálogo por slug en este mismo origen', () => {
    const tienda = unaTienda({ custom_domain: 'tienda.example.com', custom_domain_verified_at: null });
    expect(enlaceDeProducto(tienda, 'p-1')).toBe(`${window.location.origin}/tienda-demo/product/p-1`);
  });
});
