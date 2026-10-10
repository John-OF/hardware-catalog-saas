import './PlatformSeguridadPage.css';

import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { CheckCircle2, Copy, Download, KeyRound, Loader2, ShieldAlert, ShieldCheck } from 'lucide-react';
import {
  confirmarDosPasos,
  getPlatformMe,
  iniciarDosPasos,
  type ActivacionDeDosPasos,
  type SeguridadDelOperador,
} from '../../api/platform';
import { avisarErrorEnSesion } from '../../api/erroresDeFormulario';
import Imagen from '../../components/ui/Imagen';
import type { User } from '../../types';

/**
 * "28 sept 2026" en la hora del navegador del operador, como la Bitácora de
 * plataforma. No `formatearFecha()`: esa escribe en la zona de una tienda —UTC si
 * no se le da ninguna—, y aquí no hay tienda.
 */
const enSuHora = (iso: string | null) =>
  iso ? new Intl.DateTimeFormat('es', { dateStyle: 'medium' }).format(new Date(iso)) : '';

/** "JBSW Y3DP EHPK 3PXP": la clave se copia mejor a mano en grupos de cuatro. */
const enGrupos = (clave: string) => clave.match(/.{1,4}/g)?.join(' ') ?? clave;

/**
 * El QR viene como SVG del servidor. Se pinta como imagen (con `<Imagen>`) y no
 * pegado en el DOM: así, aunque trajera algo ejecutable, no se ejecutaría.
 */
const comoImagen = (svg: string) => `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;

/**
 * La seguridad de la cuenta del operador: la verificación en dos pasos (SEC-9).
 *
 * Tres estados, y la pantalla enseña solo el que toca:
 *
 * 1. **Sin activar**: qué es y un botón. Al pulsarlo, el QR (y la clave para
 *    escribirla a mano) y un campo para el primer código: sin ese código no se
 *    activa nada, para no dejar al operador fuera si el QR no llegó bien.
 * 2. **Recién activada**: los ocho códigos de recuperación, que el servidor
 *    guarda como hash y no se pueden volver a ver. Se quedan en pantalla hasta
 *    que se diga que están guardados.
 * 3. **Activa**: desde cuándo, cuántos códigos quedan y qué hacer si se pierde
 *    el móvil. Desde aquí no se apaga: eso es un comando del servidor.
 *
 * En producción es obligatoria, y hasta activarla el resto del panel no
 * responde (`PlatformLayout` trae aquí directamente).
 */
export default function PlatformSeguridadPage() {
  const queryClient = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['platformMe'], queryFn: getPlatformMe });

  const [activacion, setActivacion] = useState<ActivacionDeDosPasos | null>(null);
  const [codigo, setCodigo] = useState('');
  const [codigos, setCodigos] = useState<string[] | null>(null);
  const [ocupado, setOcupado] = useState(false);

  const empezar = async () => {
    setOcupado(true);
    try {
      setActivacion(await iniciarDosPasos());
      setCodigo('');
    } catch (error) {
      avisarErrorEnSesion(error, 'No se pudo empezar la activación.');
    } finally {
      setOcupado(false);
    }
  };

  const confirmar = async (e: React.FormEvent) => {
    e.preventDefault();
    setOcupado(true);
    try {
      const respuesta = await confirmarDosPasos(codigo);
      setCodigos(respuesta.codigos);
      setActivacion(null);
      queryClient.setQueryData<{ user: User; seguridad: SeguridadDelOperador }>(['platformMe'], (antes) =>
        antes ? { ...antes, seguridad: respuesta.seguridad } : antes,
      );
    } catch (error) {
      avisarErrorEnSesion(error, 'El código no es correcto.');
      setCodigo('');
    } finally {
      setOcupado(false);
    }
  };

  const copiar = async () => {
    try {
      await navigator.clipboard.writeText((codigos ?? []).join('\n'));
      toast.success('Códigos copiados.');
    } catch {
      toast.error('No se pudieron copiar: selecciónalos a mano.');
    }
  };

  const descargar = () => {
    const texto = [
      'Códigos de recuperación del panel de plataforma.',
      'Cada uno sirve una sola vez para entrar sin el móvil.',
      '',
      ...(codigos ?? []),
      '',
    ].join('\n');
    const enlace = document.createElement('a');
    enlace.href = URL.createObjectURL(new Blob([texto], { type: 'text/plain;charset=utf-8' }));
    enlace.download = 'codigos-de-recuperacion.txt';
    enlace.click();
    URL.revokeObjectURL(enlace.href);
  };

  const seguridad = data?.seguridad;

  if (isLoading || !seguridad) {
    return (
      <div className="platform-loader">
        <Loader2 className="spinner" size={28} />
      </div>
    );
  }

  if (codigos) {
    return (
      <div className="platform-seguridad animate-fade-in">
        <section className="glass-card seguridad-card">
          <h2>
            <CheckCircle2 size={20} /> Verificación en dos pasos activada
          </h2>
          <p>
            Guarda estos códigos de recuperación fuera del móvil —en un gestor de contraseñas o
            impresos—. Cada uno sirve una sola vez para entrar si no tienes el móvil a mano.{' '}
            <strong>No se vuelven a enseñar.</strong>
          </p>
          <ol className="seguridad-codigos">
            {codigos.map((c) => (
              <li key={c}>
                <code>{c}</code>
              </li>
            ))}
          </ol>
          <div className="seguridad-acciones">
            <button type="button" className="btn-secondary" onClick={copiar}>
              <Copy size={15} /> Copiar
            </button>
            <button type="button" className="btn-secondary" onClick={descargar}>
              <Download size={15} /> Descargar .txt
            </button>
            <button type="button" className="btn-primary" onClick={() => setCodigos(null)}>
              Ya los guardé
            </button>
          </div>
        </section>
      </div>
    );
  }

  if (seguridad.dos_pasos) {
    const quedan = seguridad.codigos_restantes ?? 0;

    return (
      <div className="platform-seguridad animate-fade-in">
        <section className="glass-card seguridad-card">
          <h2>
            <ShieldCheck size={20} /> Verificación en dos pasos activada
          </h2>
          <p>
            Desde el {enSuHora(seguridad.activada_el)}. Para entrar hacen falta la contraseña y el
            código de tu app de autenticación.
          </p>
          <p className={quedan <= 2 ? 'seguridad-quedan pocos' : 'seguridad-quedan'}>
            Te {quedan === 1 ? 'queda 1 código' : `quedan ${quedan} códigos`} de recuperación.
          </p>
          <p className="seguridad-nota">
            ¿Perdiste el móvil y los códigos? Quien administre el servidor puede quitarla con{' '}
            <code>php artisan platform:quitar-dos-pasos tu-correo</code>; al volver a entrar tendrás que
            activarla de nuevo.
          </p>
        </section>
      </div>
    );
  }

  return (
    <div className="platform-seguridad animate-fade-in">
      {seguridad.obligatoria && (
        <div className="seguridad-aviso" role="alert">
          <ShieldAlert size={18} />
          <span>
            En este servidor la verificación en dos pasos es obligatoria: hasta activarla, el resto del
            panel no se puede usar.
          </span>
        </div>
      )}

      <section className="glass-card seguridad-card">
        <h2>
          <KeyRound size={20} /> Verificación en dos pasos
        </h2>
        <p>
          Además de la contraseña, para entrar hará falta el código de seis cifras que genera una app de
          autenticación en tu móvil (Google Authenticator, Authy, 1Password, Microsoft Authenticator…).
          Quien consiga tu contraseña no podrá entrar sin el móvil.
        </p>

        {activacion ? (
          <form onSubmit={confirmar} className="seguridad-pasos">
            <div className="seguridad-paso">
              <span className="seguridad-numero">1</span>
              <div>
                <p>Escanea este código con la app.</p>
                <Imagen src={comoImagen(activacion.qr_svg)} alt="Código QR para la app de autenticación" className="seguridad-qr" />
              </div>
            </div>
            <div className="seguridad-paso">
              <span className="seguridad-numero">2</span>
              <div>
                <p>Si no puedes escanearlo, escribe esta clave a mano:</p>
                <code className="seguridad-clave">{enGrupos(activacion.secreto)}</code>
              </div>
            </div>
            <div className="seguridad-paso">
              <span className="seguridad-numero">3</span>
              <div>
                <label htmlFor="codigo-activacion">Escribe el código que te enseña la app:</label>
                <input
                  id="codigo-activacion"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  placeholder="123456"
                  maxLength={7}
                  className="premium-input seguridad-codigo"
                  value={codigo}
                  onChange={(e) => setCodigo(e.target.value)}
                  disabled={ocupado}
                  required
                />
              </div>
            </div>
            <div className="seguridad-acciones">
              <button type="submit" className="btn-primary" disabled={ocupado}>
                {ocupado ? <Loader2 className="spinner" size={15} /> : <ShieldCheck size={15} />} Activar
              </button>
            </div>
          </form>
        ) : (
          <div className="seguridad-acciones">
            <button type="button" className="btn-primary" onClick={empezar} disabled={ocupado}>
              {ocupado ? <Loader2 className="spinner" size={15} /> : <KeyRound size={15} />} Empezar
            </button>
          </div>
        )}
      </section>
    </div>
  );
}
