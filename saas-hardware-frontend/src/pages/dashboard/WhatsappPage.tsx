import './WhatsappPage.css';

import { useRef, useState } from 'react';
import { isAxiosError } from 'axios';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-hot-toast';
import { Loader2, MessageCircle, RotateCcw, Save } from 'lucide-react';
import { avisarErrorEnSesion } from '../../api/erroresDeFormulario';
import {
  getPlantillasWhatsapp,
  guardarPlantillasWhatsapp,
  type PlantillaWhatsapp,
  type PlantillasWhatsapp,
} from '../../api/plantillasWhatsapp';
import { useTenantStore } from '../../stores/tenantStore';
import type { Tenant } from '../../types';
import { formatMoney } from '../../utils/money';
import { metodosDePagoActivos } from '../../utils/paymentMethods';
import {
  lineaDeProducto,
  rellenarPlantilla,
  textoDeEntrega,
  textoDePagos,
  variablesQueNoAdmite,
  type ClaveDePlantilla,
  type ValoresDePlantilla,
} from '../../utils/plantillasWhatsapp';

const GRUPOS: { grupo: PlantillaWhatsapp['grupo']; titulo: string; descripcion: string }[] = [
  {
    grupo: 'tienda',
    titulo: 'Lo que les escribes tú',
    descripcion: 'Salen al pulsar «Escribir por WhatsApp» en el detalle de un pedido o en la lista de espera.',
  },
  {
    grupo: 'comprador',
    titulo: 'Lo que te escriben tus clientes',
    descripcion: 'El texto con el que te llega el pedido, la consulta o el armado desde tu catálogo. Lo envía el cliente.',
  },
];

/**
 * Los mensajes de WhatsApp de la tienda (MOD-25).
 *
 * Una tarjeta por mensaje: el texto, las variables que admite (un clic las
 * escribe donde está el cursor) y cómo queda con datos de ejemplo. Se guarda
 * uno (el botón de su tarjeta) o todos los cambiados (la barra de abajo), y solo
 * viaja lo que cambió; "Restaurar" vuelve al texto de fábrica, que el servidor
 * guarda como "sin texto propio".
 */
export default function WhatsappPage() {
  const tenant = useTenantStore((s) => s.tenant);
  const queryClient = useQueryClient();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['plantillas-whatsapp'],
    queryFn: getPlantillasWhatsapp,
  });

  // Solo lo que se está editando; lo demás se lee de `data`.
  const [borradores, setBorradores] = useState<Partial<Record<ClaveDePlantilla, string>>>({});
  const [errores, setErrores] = useState<Partial<Record<ClaveDePlantilla, string>>>({});
  const areas = useRef<Partial<Record<ClaveDePlantilla, HTMLTextAreaElement | null>>>({});

  const textoDe = (p: PlantillaWhatsapp) => borradores[p.clave] ?? p.texto;
  const cambiadas = (data?.plantillas ?? []).filter((p) => textoDe(p) !== p.texto);
  const conVariablesMalas = cambiadas.filter((p) => variablesQueNoAdmite(textoDe(p), p.variables).length > 0);

  const escribir = (clave: ClaveDePlantilla, texto: string) => {
    setBorradores((b) => ({ ...b, [clave]: texto }));
    setErrores((e) => ({ ...e, [clave]: undefined }));
  };

  /** Escribe `{variable}` donde está el cursor, y lo deja justo después. */
  const insertarVariable = (p: PlantillaWhatsapp, variable: string) => {
    const area = areas.current[p.clave];
    const texto = textoDe(p);
    const marca = `{${variable}}`;
    const desde = area?.selectionStart ?? texto.length;
    const hasta = area?.selectionEnd ?? texto.length;

    escribir(p.clave, texto.slice(0, desde) + marca + texto.slice(hasta));

    requestAnimationFrame(() => {
      area?.focus();
      area?.setSelectionRange(desde + marca.length, desde + marca.length);
    });
  };

  /**
   * Guarda las plantillas que se le pasen: una (el botón de su tarjeta) o todas
   * las cambiadas (la barra de abajo). Los borradores de las que no se
   * guardaron se quedan como estaban.
   */
  const guardar = useMutation({
    mutationFn: (aGuardar: PlantillaWhatsapp[]) => guardarPlantillasWhatsapp(
      Object.fromEntries(aGuardar.map((p) => [p.clave, textoDe(p)])),
    ),
    onSuccess: (respuesta: PlantillasWhatsapp, aGuardar) => {
      queryClient.setQueryData(['plantillas-whatsapp'], respuesta);
      const guardadas = new Set(aGuardar.map((p) => p.clave));
      const sinLasGuardadas = <T,>(mapa: Partial<Record<ClaveDePlantilla, T>>) =>
        Object.fromEntries(Object.entries(mapa).filter(([clave]) => !guardadas.has(clave as ClaveDePlantilla)));
      setBorradores(sinLasGuardadas);
      setErrores(sinLasGuardadas);
      toast.success(aGuardar.length === 1 ? 'Mensaje guardado' : 'Mensajes guardados');
    },
    onError: (err) => {
      // Cada error en la tarjeta de su mensaje, además del aviso general.
      if (isAxiosError(err) && err.response?.status === 422) {
        const porCampo = (err.response.data?.errors ?? {}) as Record<string, string[]>;
        setErrores(Object.fromEntries(
          Object.entries(porCampo)
            .filter(([campo]) => campo.startsWith('plantillas.'))
            .map(([campo, mensajes]) => [campo.slice('plantillas.'.length), mensajes[0]]),
        ));
      }
      avisarErrorEnSesion(err, 'No se pudieron guardar los mensajes.');
    },
  });

  if (isLoading || !tenant) {
    return (
      <div className="whatsapp-page page-whatsapp">
        <div className="glass-card state-card">
          <Loader2 size={32} className="spinner" />
        </div>
      </div>
    );
  }

  if (isError || !data) {
    return (
      <div className="whatsapp-page page-whatsapp">
        <div className="glass-card state-card">
          <h3>No se pudieron cargar los mensajes</h3>
          <p>Vuelve a intentarlo en unos segundos.</p>
        </div>
      </div>
    );
  }

  const ejemplo = valoresDeEjemplo(tenant);

  return (
    <div className="whatsapp-page animate-fade-in page-whatsapp">
      <div className="page-header">
        <div>
          <h1>Mensajes de WhatsApp</h1>
          <p className="page-description">
            Los textos que se abren en WhatsApp al escribirle a un cliente o al recibir un pedido.
            Cambia las palabras o el tono a tu gusto: lo que va entre llaves, como{' '}
            <code>{'{cliente}'}</code>, se rellena solo con los datos de cada pedido. Una línea
            cuyas llaves quedan vacías no se envía (por ejemplo, «Nota: {'{nota}'}» sin nota).
          </p>
        </div>
      </div>

      {GRUPOS.map(({ grupo, titulo, descripcion }) => (
        <section key={grupo} className="whatsapp-group">
          <h2>{titulo}</h2>
          <p className="whatsapp-group-description">{descripcion}</p>

          {data.plantillas.filter((p) => p.grupo === grupo).map((p) => {
            const texto = textoDe(p);
            const malas = variablesQueNoAdmite(texto, p.variables);
            const vistaPrevia = rellenarPlantilla(texto, ejemplo);
            const idArea = `plantilla-${p.clave}`;

            return (
              <article key={p.clave} className="glass-card whatsapp-card">
                <header className="whatsapp-card-head">
                  <div>
                    <h3>
                      <label htmlFor={idArea}>{p.etiqueta}</label>
                    </h3>
                    <p>{p.cuando}</p>
                  </div>
                  {p.personalizada && texto === p.texto && <span className="badge badge-neutral">Personalizado</span>}
                  {texto !== p.texto && <span className="badge badge-warning">Sin guardar</span>}
                </header>

                <div className="whatsapp-card-body">
                  <div className="whatsapp-editor">
                    <textarea
                      id={idArea}
                      ref={(el) => { areas.current[p.clave] = el; }}
                      className="premium-input"
                      value={texto}
                      rows={Math.min(12, Math.max(4, texto.split('\n').length + 1))}
                      maxLength={data.max}
                      onChange={(e) => escribir(p.clave, e.target.value)}
                    />

                    <div className="whatsapp-variables" aria-label="Variables que puedes usar">
                      {p.variables.map((variable) => (
                        <button
                          key={variable}
                          type="button"
                          className="whatsapp-variable"
                          title={data.variables[variable]}
                          onClick={() => insertarVariable(p, variable)}
                        >
                          {`{${variable}}`}
                        </button>
                      ))}
                    </div>

                    {malas.length > 0 && (
                      <p className="whatsapp-error">
                        {malas.map((v) => `{${v}}`).join(', ')} no existe en este mensaje. Usa las de arriba.
                      </p>
                    )}
                    {errores[p.clave] && <p className="whatsapp-error">{errores[p.clave]}</p>}

                    <div className="whatsapp-card-foot">
                      <span className="whatsapp-count">{texto.length} / {data.max}</span>
                      <div className="whatsapp-card-actions">
                        {texto !== p.de_fabrica && (
                          <button type="button" className="btn-secondary whatsapp-restore" onClick={() => escribir(p.clave, p.de_fabrica)}>
                            <RotateCcw size={14} /> Restaurar el de fábrica
                          </button>
                        )}
                        {/* Su propio botón: con diez tarjetas, la barra de abajo
                            queda lejos de la que se está editando. */}
                        {texto !== p.texto && (
                          <button
                            type="button"
                            className="btn-primary whatsapp-save-one"
                            disabled={malas.length > 0 || guardar.isPending}
                            onClick={() => guardar.mutate([p])}
                          >
                            <Save size={14} /> Guardar este mensaje
                          </button>
                        )}
                      </div>
                    </div>
                  </div>

                  <div className="whatsapp-preview-box">
                    <span className="whatsapp-preview-title"><MessageCircle size={14} /> Así se ve, con datos de ejemplo</span>
                    <p className="whatsapp-bubble">{vistaPrevia || 'El mensaje queda vacío: WhatsApp se abrirá sin texto.'}</p>
                  </div>
                </div>
              </article>
            );
          })}
        </section>
      ))}

      <div className="whatsapp-save-bar glass-card">
        <span>
          {cambiadas.length === 0
            ? 'No hay cambios sin guardar.'
            : cambiadas.length === 1 ? '1 mensaje sin guardar.' : `${cambiadas.length} mensajes sin guardar.`}
        </span>
        <button
          type="button"
          className="btn-primary"
          disabled={cambiadas.length === 0 || conVariablesMalas.length > 0 || guardar.isPending}
          onClick={() => guardar.mutate(cambiadas)}
        >
          {guardar.isPending ? <Loader2 size={16} className="spinner" /> : <Save size={16} />} Guardar todos los cambios
        </button>
      </div>
    </div>
  );
}

/**
 * Lo que se ve en la vista previa. Con los datos reales de la tienda donde los
 * hay (nombre, métodos de pago, si reparte), para que la vista previa quite las
 * mismas líneas que quitará el mensaje de verdad.
 */
function valoresDeEjemplo(tienda: Tenant): ValoresDePlantilla {
  const money = (n: number | string) => formatMoney(n, tienda.currency);

  return {
    tienda: tienda.name,
    cliente: 'Ana Pérez',
    pedido: '#47',
    productos: [
      lineaDeProducto(2, 'Producto de ejemplo', money(50)),
      lineaDeProducto(1, 'Otro producto', money(30)),
    ].join('\n'),
    total: money(130),
    entrega: tienda.delivery_enabled ? textoDeEntrega('delivery', money(tienda.delivery_cost)) : '',
    pagos: textoDePagos(metodosDePagoActivos(tienda.payment_methods)),
    nota: 'Lo recojo el sábado por la tarde',
    producto: 'Producto de ejemplo',
    precio: money(50),
    enlace: `${window.location.origin}/${tienda.slug}/product/…`,
    piezas: [
      `• *Procesador:* Ryzen 5 7600 (${money(220)})`,
      `• *Memoria RAM:* Kingston Fury 16 GB (${money(60)})`,
    ].join('\n'),
    consumo: '350W (recomendado: 550W)',
  };
}
