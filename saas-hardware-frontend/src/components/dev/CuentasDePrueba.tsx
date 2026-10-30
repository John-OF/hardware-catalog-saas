import './CuentasDePrueba.css';

import { useEffect, useRef } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { cuentasDePrueba, LOGIN_DE, type CuentaDePrueba, type TipoDeCuenta } from '../../utils/cuentasDePrueba';

type Props = {
  /** El login en el que está: las cuentas de este tipo entran aquí mismo. */
  tipo: TipoDeCuenta;
  /** Entra con la cuenta, con la misma lógica que el formulario de la página. */
  onElegir: (cuenta: CuentaDePrueba) => void;
  ocupado?: boolean;
};

type EstadoConCuenta = { cuentaDePrueba?: string } | null;

const GRUPOS: { tipo: TipoDeCuenta; titulo: string }[] = [
  { tipo: 'plataforma', titulo: 'Plataforma' },
  { tipo: 'tienda', titulo: 'Tiendas' },
];

/**
 * Lista de cuentas de prueba para entrar con un clic (sólo en desarrollo: ver
 * `utils/cuentasDePrueba.ts`). Sin cuentas no pinta nada.
 *
 * Enseña las de los dos tipos en los dos logins. Una cuenta del otro tipo lleva
 * a su login con el correo en el estado de la navegación, y aquella página
 * entra al llegar: así cada login sigue siendo el único que sabe entrar (el de
 * plataforma, con su segundo paso si la cuenta lo tiene).
 */
export default function CuentasDePrueba({ tipo, onElegir, ocupado = false }: Props) {
  const navigate = useNavigate();
  const location = useLocation();
  const cuentas = cuentasDePrueba();
  const atendida = useRef(false);

  const pendiente = (location.state as EstadoConCuenta)?.cuentaDePrueba;

  useEffect(() => {
    // El ref evita entrar dos veces con el doble efecto de StrictMode.
    if (!pendiente || atendida.current) return;
    atendida.current = true;

    // Se borra del historial para que un refresco no vuelva a entrar.
    navigate(location.pathname, { replace: true, state: null });

    const cuenta = cuentasDePrueba().find((c) => c.tipo === tipo && c.email === pendiente);
    if (cuenta) onElegir(cuenta);
  }, [pendiente, tipo, onElegir, navigate, location.pathname]);

  if (cuentas.length === 0) return null;

  const elegir = (cuenta: CuentaDePrueba) => {
    if (cuenta.tipo === tipo) {
      onElegir(cuenta);
      return;
    }

    navigate(LOGIN_DE[cuenta.tipo], { state: { cuentaDePrueba: cuenta.email } });
  };

  return (
    <section className="cuentas-prueba" aria-label="Cuentas de prueba">
      <p className="cuentas-prueba__titulo">Cuentas de prueba · solo en desarrollo</p>

      {GRUPOS.map(({ tipo: tipoDelGrupo, titulo }) => {
        const delGrupo = cuentas.filter((c) => c.tipo === tipoDelGrupo);
        if (delGrupo.length === 0) return null;

        return (
          <div key={tipoDelGrupo} className="cuentas-prueba__grupo">
            <p className="cuentas-prueba__subtitulo">{titulo}</p>
            <ul className="cuentas-prueba__lista">
              {delGrupo.map((cuenta) => (
                <li key={cuenta.email}>
                  <button
                    type="button"
                    className="cuentas-prueba__cuenta"
                    onClick={() => elegir(cuenta)}
                    disabled={ocupado}
                  >
                    <span className="cuentas-prueba__nombre">{cuenta.etiqueta}</span>
                    <span className="cuentas-prueba__correo">{cuenta.email}</span>
                  </button>
                </li>
              ))}
            </ul>
          </div>
        );
      })}
    </section>
  );
}
