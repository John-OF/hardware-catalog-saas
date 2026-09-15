import { describe, expect, it } from 'vitest';
import { esHostDeLaPlataforma, hostsDeLaPlataforma } from './plataforma';
import { storeThemeKey } from './theme';

describe('hostsDeLaPlataforma (INF-9)', () => {
  it('sin variable valen los de desarrollo, como antes', () => {
    expect(hostsDeLaPlataforma(undefined)).toEqual(['localhost', '127.0.0.1']);
    expect(hostsDeLaPlataforma(' , ')).toEqual(['localhost', '127.0.0.1']);
  });

  it('lee una lista separada por comas y se queda con el host aunque se escriba como URL', () => {
    expect(hostsDeLaPlataforma('plataforma.com, https://WWW.Plataforma.com/, http://staging.plataforma.com:8080/x'))
      .toEqual(['plataforma.com', 'www.plataforma.com', 'staging.plataforma.com']);
  });
});

describe('esHostDeLaPlataforma', () => {
  const produccion = hostsDeLaPlataforma('plataforma.com,www.plataforma.com');

  it('el dominio de la plataforma en producción es de la plataforma (el fallo: antes solo lo era localhost)', () => {
    expect(esHostDeLaPlataforma('plataforma.com', produccion)).toBe(true);
    expect(esHostDeLaPlataforma('WWW.plataforma.com', produccion)).toBe(true);
  });

  it('el dominio propio de una tienda no lo es, ni un subdominio que no esté en la lista', () => {
    expect(esHostDeLaPlataforma('tiendagamer.pe', produccion)).toBe(false);
    expect(esHostDeLaPlataforma('tienda.plataforma.com', produccion)).toBe(false);
  });

  it('con la lista de producción, localhost deja de serlo', () => {
    expect(esHostDeLaPlataforma('localhost', produccion)).toBe(false);
  });

  it('por defecto, localhost sí', () => {
    expect(esHostDeLaPlataforma('localhost', hostsDeLaPlataforma(undefined))).toBe(true);
  });
});

describe('storeThemeKey con la plataforma', () => {
  it('la portada de la plataforma no guarda tema de tienda; la de un dominio propio sí', () => {
    // Sin VITE_PLATFORM_HOSTS en los tests: la plataforma es localhost.
    expect(storeThemeKey('/', 'localhost')).toBeNull();
    expect(storeThemeKey('/', 'tiendagamer.pe')).toBe('theme:domain:tiendagamer.pe');
    expect(storeThemeKey('/tienda-demo', 'localhost')).toBe('theme:slug:tienda-demo');
  });
});
