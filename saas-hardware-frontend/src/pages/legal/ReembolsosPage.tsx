import { Link } from 'react-router-dom';
import PaginaLegal, { Correo } from './PaginaLegal';

/**
 * Política de reembolsos (INF-15). El plazo de 14 días desde cada cobro es una
 * decisión de negocio: generoso para empezar, y compatible con lo que Paddle
 * pide a quien vende a través suyo. Borrador: conviene que lo revise un abogado
 * antes de publicarlo.
 */
export default function ReembolsosPage() {
  return (
    <PaginaLegal titulo="Política de reembolsos">
      <p>
        Queremos que pagues solo si el servicio te sirve. Por eso puedes probarlo gratis antes de pagar,
        y si aun así cambias de opinión, te devolvemos el dinero.
      </p>

      <h2>1. Prueba antes de pagar</h2>
      <p>
        Toda tienda nueva tiene <strong>7 días de prueba gratis</strong>, sin tarjeta y con los límites del
        plan Pro. No se te cobra nada hasta que eliges un plan.
      </p>

      <h2>2. Reembolso de 14 días</h2>
      <p>
        Puedes pedir el <strong>reembolso completo de cualquier pago</strong> —incluidos los impuestos
        que se sumaron— dentro de los <strong>14 días siguientes a la fecha del cobro</strong>, sin tener
        que explicar el motivo.
      </p>

      <h2>3. Cómo pedirlo</h2>
      <p>Los pagos los gestiona nuestro revendedor, Paddle, así que tienes dos caminos:</p>
      <ul>
        <li>desde el enlace que viene en el recibo que Paddle te envía por correo después de cada pago, o
          en <a href="https://paddle.net" target="_blank" rel="noopener noreferrer">paddle.net</a>;</li>
        <li>escribiéndonos a <Correo /> desde el correo de tu cuenta, con la fecha del cobro.</li>
      </ul>
      <p>
        El dinero se devuelve al mismo medio con el que pagaste. Lo que tarde en verse en tu cuenta
        depende de tu banco o del medio de pago.
      </p>

      <h2>4. Qué pasa con tu tienda</h2>
      <p>
        Al reembolsarse un pago, esa suscripción se cancela. Si no tienes otro plan activo, la tienda se
        suspende como al terminar la prueba: <strong>no se borra nada</strong> de lo que creaste y se puede
        reactivar al contratar un plan.
      </p>

      <h2>5. Cancelar sin pedir reembolso</h2>
      <p>
        Puedes cancelar tu plan en cualquier momento. No se te vuelve a cobrar y conservas el plan hasta el
        final del periodo que ya pagaste.
      </p>

      <h2>6. Fuera de plazo</h2>
      <p>
        Pasados los 14 días no estamos obligados a reembolsar, pero escríbenos igual: si hubo un cobro por
        error o un problema del servicio, lo revisaremos. Podemos rechazar los reembolsos si vemos que se
        piden de forma repetida para usar el servicio gratis.
      </p>

      <p>
        Esta política forma parte de nuestros <Link to="/terminos">términos y condiciones</Link>.
      </p>
    </PaginaLegal>
  );
}
