import './RouteFallback.css';

/**
 * Lo que se ve mientras llega el chunk de una ruta cargada bajo demanda
 * (AUD-18). Deliberadamente mínimo: no sabe de qué ruta se trata ni tiene por
 * qué imitar su contenido.
 */
export default function RouteFallback() {
  return (
    <div className="route-fallback" role="status" aria-live="polite">
      <div className="route-fallback__spinner" aria-hidden="true" />
      <span className="route-fallback__texto">Cargando…</span>
    </div>
  );
}
