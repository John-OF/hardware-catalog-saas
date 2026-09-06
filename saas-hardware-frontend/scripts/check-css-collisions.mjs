/**
 * UI-5: empates de especificidad entre el CSS de una pagina y el de un componente.
 *
 * La convencion del proyecto (TEC-6) es que el CSS de una pagina va acotado con
 * `:where(.page-x)`, y `:where()` NO suma especificidad. Cuando una de esas
 * reglas apunta a una clase de un componente —que tiene su CSS global y sin
 * acotar— las dos valen (0,1,0) y el empate lo rompe el orden de carga de los
 * chunks, o sea por que ruta entro el usuario.
 *
 * No es teorico: `:where(.page-catalog) .catalog-footer { margin-top: auto }`
 * empataba con `.catalog-footer { margin-top: 3rem }` de StoreFooter.css, y el
 * pie quedaba bien en la pagina informativa y mal en el catalogo. Se arreglo
 * llevando la regla al componente, que es donde no hay empate posible.
 *
 * Este script comprueba las dos direcciones del problema:
 *
 *   1. EMPATE: misma especificidad y alguna propiedad en comun -> indeterminado.
 *   2. CODIGO MUERTO: la regla de la pagina pierde siempre porque el componente
 *      es mas especifico, asi que nunca se aplica y quien la escribio no se
 *      entero.
 *
 * Solo mira componentes que la pagina monta de verdad: dos reglas que nunca
 * coinciden en un elemento no chocan por mucho que compartan nombre de clase.
 *
 * Salida: codigo 1 si encuentra algo, con el fichero y el selector.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, basename, extname } from 'node:path';

const SRC = new URL('../src/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');

function walk(dir) {
  return readdirSync(dir).flatMap((f) => {
    const p = join(dir, f);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });
}

const norm = (s) => s.split(/\s+/).filter(Boolean).join(' ');

/** Especificidad ignorando :where(), que vale 0 por definicion. */
function especificidad(sel) {
  const s = sel.replace(/:where\([^)]*\)/g, '');
  const ids = (s.match(/#[\w-]+/g) || []).length;
  const clases =
    (s.match(/\.[\w-]+/g) || []).length +
    (s.match(/\[[^\]]+\]/g) || []).length +
    (s.match(/:(?!:)(?!where)[a-z-]+/g) || []).length;
  const elementos = (s.match(/(?:^|[\s>+~])[a-z][\w]*/g) || []).length;
  return [ids, clases, elementos];
}

const cmp = (a, b) => a[0] - b[0] || a[1] - b[1] || a[2] - b[2];

function reglas(file) {
  const css = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
  const out = [];
  for (const [, sel, body] of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    if (sel.trim().startsWith('@')) continue;
    const props = new Set([...body.matchAll(/([a-z-]+)\s*:/g)].map((m) => m[1]));
    for (const parte of sel.split(',')) out.push({ sel: norm(parte), props });
  }
  return out;
}

const todos = walk(SRC);
const cssComponentes = todos.filter((f) => f.includes('components') && extname(f) === '.css');
const cssPaginas = todos.filter((f) => f.includes('pages') && extname(f) === '.css');
const tsxPaginas = todos.filter((f) => f.includes('pages') && extname(f) === '.tsx');

const problemas = [];

for (const compCss of cssComponentes) {
  const nombre = basename(compCss, '.css');
  // Un componente acotado a si mismo no puede empatar con nadie.
  if (readFileSync(compCss, 'utf8').includes(':where(.page-')) continue;

  const montadoEn = new Set(
    tsxPaginas
      .filter((p) => new RegExp(`\\b${nombre}\\b`).test(readFileSync(p, 'utf8')))
      .map((p) => basename(p, '.tsx'))
  );

  for (const pagCss of cssPaginas) {
    if (!montadoEn.has(basename(pagCss, '.css'))) continue;

    for (const r of reglas(pagCss)) {
      if (!r.sel.includes(':where(.page-')) continue;
      const desnudo = norm(r.sel.replace(/:where\([^)]*\)/g, ''));
      const objetivo = (desnudo.match(/\.[\w-]+/g) || []).pop();
      if (!objetivo) continue;

      for (const c of reglas(compCss)) {
        if ((c.sel.match(/\.[\w-]+/g) || []).pop() !== objetivo) continue;
        const comunes = [...r.props].filter((p) => c.props.has(p));
        if (!comunes.length) continue;

        const orden = cmp(especificidad(r.sel), especificidad(c.sel));
        const mismoObjetivo = desnudo === norm(c.sel);

        if (orden === 0 && mismoObjetivo) {
          problemas.push(
            `EMPATE  ${basename(pagCss)}: ${r.sel}\n` +
            `        vs ${basename(compCss)}: ${c.sel}\n` +
            `        propiedades: ${comunes.join(', ')} — gana la hoja que cargue despues`
          );
        } else if (orden < 0 && mismoObjetivo) {
          problemas.push(
            `MUERTA  ${basename(pagCss)}: ${r.sel}\n` +
            `        pierde siempre contra ${basename(compCss)}: ${c.sel}\n` +
            `        propiedades: ${comunes.join(', ')}`
          );
        }
      }
    }
  }
}

if (problemas.length) {
  console.error('Empates de especificidad pagina/componente (UI-5):\n');
  for (const p of problemas) console.error(p + '\n');
  console.error(
    'Si la regla describe al componente, va en su hoja. Si es una excepcion real\n' +
    'de esa pagina, sube la especificidad a proposito y dejalo escrito.'
  );
  process.exit(1);
}

console.log('Sin empates de especificidad entre CSS de pagina y de componente.');
