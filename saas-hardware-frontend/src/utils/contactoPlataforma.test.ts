import { describe, expect, it } from 'vitest';
import {
  contactoDeLaPlataforma,
  enlaceDeContacto,
  mensajeParaActivar,
  mensajeParaReabrir,
  mensajeParaRenovar,
} from './contactoPlataforma';

const tienda = { name: 'Mi Tienda', slug: 'mi-tienda' };

describe('contacto con la plataforma (FUN-28)', () => {
  it('lee el WhatsApp y el correo del build, sin espacios ni vacíos', () => {
    expect(contactoDeLaPlataforma({ VITE_PLATAFORMA_WHATSAPP: ' +593 99 123 4567 ', VITE_LEGAL_CORREO: 'hola@x.com' })).toEqual({
      whatsapp: '+593 99 123 4567',
      correo: 'hola@x.com',
    });
    expect(contactoDeLaPlataforma({ VITE_PLATAFORMA_WHATSAPP: '  ' })).toEqual({ whatsapp: null, correo: null });
  });

  it('cada mensaje dice qué se pide, la tienda y su slug', () => {
    expect(mensajeParaActivar(tienda, 'Pro')).toBe('Hola, quiero activar el plan Pro para mi tienda Mi Tienda (mi-tienda).');
    expect(mensajeParaActivar(tienda)).toBe('Hola, quiero elegir un plan para mi tienda Mi Tienda (mi-tienda).');
    expect(mensajeParaRenovar(tienda)).toBe('Hola, quiero renovar el plan de mi tienda Mi Tienda (mi-tienda).');
    expect(mensajeParaReabrir(tienda)).toBe('Hola, quiero reabrir mi tienda Mi Tienda (mi-tienda).');
  });

  it('prefiere WhatsApp, cae al correo y sin ninguno no da enlace', () => {
    const texto = mensajeParaActivar(tienda, 'Pro');

    expect(enlaceDeContacto({ whatsapp: '+593 99 123 4567', correo: 'hola@x.com' }, texto, 'Asunto')).toBe(
      `https://wa.me/593991234567?text=${encodeURIComponent(texto)}`,
    );
    expect(enlaceDeContacto({ whatsapp: null, correo: 'hola@x.com' }, texto, 'Activar plan')).toBe(
      `mailto:hola@x.com?subject=Activar%20plan&body=${encodeURIComponent(texto)}`,
    );
    expect(enlaceDeContacto({ whatsapp: null, correo: null }, texto, 'Asunto')).toBeNull();
  });
});
