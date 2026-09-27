import { Link } from 'react-router-dom';
import { NOMBRE_DEL_SERVICIO, PAIS_DEL_TITULAR } from '../../utils/legal';
import PaginaLegal, { Correo, Titular } from './PaginaLegal';

/**
 * Términos y condiciones del servicio (INF-15). Borrador redactado a partir de
 * lo que el sistema hace de verdad —cada afirmación está comprobada en el
 * código—; conviene que lo revise un abogado antes de publicarlo.
 */
export default function TerminosPage() {
  return (
    <PaginaLegal titulo="Términos y condiciones">
      <p>
        Estos términos regulan el uso de <strong>{NOMBRE_DEL_SERVICIO}</strong> (en adelante, «el
        servicio»), una plataforma para crear y administrar el catálogo en línea de una tienda de
        componentes de computadora. Al crear una tienda o usar el servicio aceptas estos términos. Si no
        estás de acuerdo con ellos, no uses el servicio.
      </p>

      <h2>1. Quién presta el servicio</h2>
      <p>
        El servicio lo presta <Titular />, persona natural con domicilio en {PAIS_DEL_TITULAR} (en
        adelante, «nosotros»). Puedes escribirnos a <Correo />.
      </p>

      <h2>2. Qué es el servicio</h2>
      <p>
        El servicio te da un catálogo público para tu tienda, con buscador, comparador, armador de PC y
        carrito, y un panel para administrar productos, categorías, pedidos, clientes, cupones,
        reportes y a tu equipo.
      </p>
      <p>
        <strong>El servicio no cobra a tus compradores ni interviene en tus ventas.</strong> Un pedido
        del catálogo es una solicitud que te llega a ti, y la venta se cierra entre tú y tu comprador
        —por WhatsApp y con los medios de pago que tú ofrezcas—. No somos parte de esa compraventa.
      </p>

      <h2>3. Tu cuenta</h2>
      <ul>
        <li>Para crear una tienda necesitas una dirección de correo válida y verificarla: el catálogo no
          se publica hasta que lo haces.</li>
        <li>Eres responsable de guardar tu contraseña y de lo que se haga desde tu cuenta y desde las
          cuentas de tu equipo. Tú decides a quién das acceso y con qué rol.</li>
        <li>Los datos que nos des al registrarte tienen que ser verdaderos y estar al día.</li>
      </ul>

      <h2>4. Prueba gratuita</h2>
      <p>
        Toda tienda nueva empieza con <strong>7 días de prueba gratis</strong>, sin tarjeta, con los
        límites del plan Pro. Si al terminar la prueba no has elegido un plan de pago, la tienda se
        suspende: deja de estar disponible, pero <strong>no se borra nada</strong> de lo que creaste, y
        se puede reactivar al contratar un plan.
      </p>

      <h2>5. Planes, precios e impuestos</h2>
      <ul>
        <li>Los planes, sus precios y sus límites son los que se muestran en nuestra página de inicio en
          el momento de contratar. Los precios están en dólares estadounidenses y son mensuales.</li>
        <li><strong>Los precios no incluyen impuestos.</strong> Al pagar se suma el impuesto que
          corresponda según tu país, que calcula y cobra nuestro revendedor (ver el punto 6).</li>
        <li>Bajar de plan nunca borra lo que ya tienes creado: solo impide crear más de lo que permite
          el plan nuevo.</li>
        <li>Si cambiamos los precios, te avisaremos con al menos 30 días de antelación, y el precio nuevo
          se aplicará a partir de la siguiente renovación.</li>
      </ul>

      <h2>6. Pagos: Paddle es el vendedor</h2>
      <p>
        Nuestro proceso de pedido lo gestiona nuestro revendedor en línea, <strong>Paddle.com</strong>.
        Paddle.com es el comerciante registrado (<em>Merchant of Record</em>) de todos nuestros pedidos:
        es quien te cobra, quien emite tu factura y quien se encarga de los impuestos. Paddle atiende las
        consultas sobre pagos y gestiona las devoluciones. Al pagar aceptas también los términos de
        compra de Paddle.
      </p>
      <ul>
        <li>Las suscripciones se <strong>renuevan automáticamente</strong> cada mes con el medio de pago
          que elegiste, hasta que las canceles.</li>
        <li>Puedes <strong>cancelar en cualquier momento</strong>. La cancelación evita el próximo cobro
          y conservas el plan hasta el final del periodo que ya pagaste.</li>
        <li>Si un cobro falla, podemos suspender la tienda hasta que se regularice, sin borrar sus datos.</li>
        <li>Los reembolsos se rigen por nuestra <Link to="/reembolsos">política de reembolsos</Link>.</li>
      </ul>

      <h2>7. Uso aceptable</h2>
      <p>No puedes usar el servicio para:</p>
      <ul>
        <li>vender u ofrecer productos ilegales, robados, falsificados o cuya venta esté prohibida donde
          vendes;</li>
        <li>engañar a tus compradores: precios, existencias, características o garantías falsas;</li>
        <li>enviar publicidad no solicitada, suplantar a otra persona o tienda, o recoger datos para fines
          distintos de atender tus pedidos;</li>
        <li>intentar acceder a otras tiendas, al panel de la plataforma o a datos que no son tuyos, o
          saltarte los límites de tu plan;</li>
        <li>sobrecargar, atacar o interferir con el funcionamiento del servicio;</li>
        <li>publicar contenido que infrinja derechos de otros (marcas, imágenes, textos) o que sea ilegal.</li>
      </ul>

      <h2>8. Tu contenido y tus ventas</h2>
      <ul>
        <li>El contenido de tu tienda —productos, fotos, textos, logo— es tuyo. Nos das permiso para
          guardarlo, procesarlo (por ejemplo, reducir las fotos) y mostrarlo en tu catálogo, solo para
          prestarte el servicio.</li>
        <li>Eres responsable de ese contenido, de los precios y existencias que publicas, de las garantías
          que ofreces y de cumplir las leyes que se aplican a tu negocio, incluidas las de protección
          al consumidor.</li>
        <li>Tus impuestos y comprobantes de venta son tuyos. <strong>La cotización en PDF que genera el
          panel no es un comprobante fiscal</strong>, y así lo dice el propio documento.</li>
        <li>Respecto de los datos de tus compradores, tú eres el responsable y nosotros los tratamos por
          tu cuenta, como explica la <Link to="/privacidad">política de privacidad</Link>.</li>
      </ul>

      <h2>9. Disponibilidad y copias</h2>
      <p>
        Hacemos lo razonable para que el servicio funcione de forma continua y hacemos copias de
        seguridad diarias de la base de datos, pero no podemos garantizar que funcione sin
        interrupciones ni errores: puede haber mantenimientos y fallos de proveedores. Te recomendamos
        guardar tu propia copia con la exportación a CSV del catálogo y de los pedidos que tienes en el
        panel.
      </p>

      <h2>10. Suspensión y baja</h2>
      <ul>
        <li>Podemos suspender una tienda si incumple estos términos, si un pago no se regulariza o si
          terminó la prueba sin elegir plan. Salvo casos graves o urgentes, te avisaremos antes.</li>
        <li>Puedes pedir la baja de tu tienda cuando quieras escribiéndonos. Antes, exporta lo que quieras
          conservar: tras la baja borramos los datos de la tienda como explica la política de privacidad.</li>
      </ul>

      <h2>11. Responsabilidad</h2>
      <p>
        En la medida en que la ley lo permita, el servicio se ofrece «tal cual» y no respondemos de
        pérdidas indirectas, como ventas o beneficios perdidos, ni de lo que ocurra en las ventas entre tú
        y tus compradores. Nuestra responsabilidad total frente a ti no superará lo que nos hayas pagado
        en los 12 meses anteriores al hecho que la origine. Nada de esto limita los derechos que la ley
        te reconozca y que no se puedan renunciar.
      </p>

      <h2>12. Cambios en estos términos</h2>
      <p>
        Podemos cambiar estos términos. Si el cambio es importante, te avisaremos por correo o en el
        panel con al menos 15 días de antelación. Si sigues usando el servicio después de esa fecha,
        aceptas los términos nuevos; si no estás de acuerdo, puedes cancelar tu plan.
      </p>

      <h2>13. Ley aplicable</h2>
      <p>
        Estos términos se rigen por las leyes de la República del {PAIS_DEL_TITULAR}. Cualquier
        controversia se someterá a los jueces competentes de {PAIS_DEL_TITULAR}, sin perjuicio de los
        derechos que te reconozca la ley de tu país si eres consumidor.
      </p>

      <h2>14. Contacto</h2>
      <p>
        Para cualquier duda sobre estos términos, escríbenos a <Correo />.
      </p>
    </PaginaLegal>
  );
}
