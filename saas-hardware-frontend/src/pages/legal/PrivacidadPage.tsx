import { NOMBRE_DEL_SERVICIO, PAIS_DEL_TITULAR } from '../../utils/legal';
import PaginaLegal, { Correo, Titular } from './PaginaLegal';

/**
 * Política de privacidad (INF-15). Cada dato que nombra es uno que el sistema
 * guarda de verdad —comprobado en el código el 2026-09-26— y no se promete
 * nada que no haga: si cambia lo que se guarda, cambia esta página. Borrador:
 * conviene que lo revise un abogado antes de publicarlo.
 */
export default function PrivacidadPage() {
  return (
    <PaginaLegal titulo="Política de privacidad">
      <p>
        Esta política explica qué datos personales trata <strong>{NOMBRE_DEL_SERVICIO}</strong>, para
        qué, con quién los compartimos y qué derechos tienes. Se aplica a los dueños de tienda y a su
        equipo, y a los compradores que visitan los catálogos de las tiendas.
      </p>

      <h2>1. Responsable</h2>
      <p>
        El responsable de los datos de las cuentas de tienda es <Titular />, persona natural con
        domicilio en {PAIS_DEL_TITULAR}. Contacto: <Correo />.
      </p>
      <p>
        <strong>Los datos de los compradores de cada tienda son de esa tienda.</strong> La tienda es la
        responsable de ellos y nosotros solo los tratamos por su cuenta, para que pueda atender sus
        pedidos. Si eres comprador, lo primero es dirigirte a la tienda donde compraste.
      </p>

      <h2>2. Qué datos tratamos y para qué</h2>
      <p><strong>Si tienes una tienda o formas parte de su equipo:</strong></p>
      <ul>
        <li>tu nombre, tu correo y tu contraseña (guardada cifrada: no podemos leerla);</li>
        <li>los datos de la tienda que tú escribas: nombre, WhatsApp, logo, dirección, horario,
          identificación fiscal y redes sociales;</li>
        <li>la fecha de tu último acceso y un registro de los cambios que se hacen desde el panel (quién
          cambió qué y cuándo), que puede ver el administrador de la tienda.</li>
      </ul>
      <p>
        Los usamos para prestarte el servicio, para la seguridad de tu cuenta y para enviarte los correos
        del servicio: verificar tu correo, recuperar tu contraseña, invitaciones al equipo y avisos de
        pedidos. No los usamos para publicidad ni los vendemos.
      </p>

      <p><strong>Si compras en una de las tiendas</strong> (datos que tratamos por cuenta de la tienda):</p>
      <ul>
        <li>al hacer un pedido: tu nombre, teléfono, correo, la nota que escribas y lo que pediste;</li>
        <li>si creas una cuenta en la tienda: tu nombre, correo, teléfono si lo das y tu contraseña
          (cifrada), además de tus favoritos y tu historial de pedidos;</li>
        <li>si dejas una reseña: tu nombre, tu calificación y tu comentario, que se publican, y tu correo,
          que <strong>no</strong> se publica;</li>
        <li>si pides que te avisen cuando llegue un producto: tu correo, solo para ese aviso.</li>
      </ul>

      <p><strong>Si solo navegas un catálogo:</strong> tu navegador guarda un identificador aleatorio para
        contar cuántas personas distintas ven cada producto. No dice quién eres ni se cruza con ningún
        otro dato. No usamos cookies de publicidad ni herramientas de analítica de terceros.</p>

      <p><strong>Pagos del servicio:</strong> los procesa Paddle, nuestro revendedor. Nosotros no vemos ni
        guardamos los datos de tu tarjeta.</p>

      <h2>3. Qué se guarda en tu navegador</h2>
      <ul>
        <li><strong>La sesión del panel</strong>, en el almacenamiento de la pestaña: se borra al cerrarla.</li>
        <li><strong>El carrito de cada tienda, la sesión de comprador, el tema elegido y el identificador de
          visitas</strong>, en el almacenamiento local del navegador. Puedes borrarlos desde la
          configuración de tu navegador.</li>
      </ul>

      <h2>4. Con quién compartimos datos</h2>
      <p>Solo con los proveedores que necesitamos para prestar el servicio, que los tratan por nuestra
        cuenta:</p>
      <ul>
        <li>el proveedor de alojamiento del servidor y de la base de datos;</li>
        <li>Cloudflare, que guarda las imágenes de las tiendas y comprueba que quien deja una reseña no
          es un programa automático;</li>
        <li>el proveedor que envía los correos del servicio;</li>
        <li>Paddle, que cobra las suscripciones, emite las facturas y gestiona los impuestos.</li>
      </ul>
      <p>
        Algunos de estos proveedores están fuera de {PAIS_DEL_TITULAR}, así que tus datos pueden
        guardarse en otros países. Solo los compartiremos con autoridades cuando la ley nos obligue.
      </p>
      <p>
        Para darte soporte, nuestro equipo puede entrar al panel de tu tienda <strong>en modo de solo
        lectura</strong>: puede ver, pero no cambiar nada, y cada entrada queda registrada.
      </p>

      <h2>5. Cuánto tiempo los guardamos</h2>
      <ul>
        <li>Mientras tu tienda exista. Si se suspende —por ejemplo, al terminar la prueba sin elegir
          plan— sus datos se conservan para que puedas reactivarla.</li>
        <li>Lo que mandas a la papelera del panel se borra solo a los 30 días.</li>
        <li>Las copias de seguridad de la base de datos se guardan 30 días y después se borran.</li>
        <li>Si pides la baja de tu tienda, borramos sus datos en un plazo máximo de 30 días; las copias
          de seguridad que aún los contengan caducan en los 30 días siguientes. Conservaremos solo lo que
          la ley nos obligue a guardar.</li>
      </ul>

      <h2>6. Tus derechos</h2>
      <p>
        Conforme a la Ley Orgánica de Protección de Datos Personales de {PAIS_DEL_TITULAR}, puedes pedir
        acceder a tus datos, corregirlos, eliminarlos, oponerte a su tratamiento y recibirlos en un
        formato que puedas llevarte a otro servicio. Escríbenos a <Correo /> desde el correo de tu cuenta.
        El catálogo y los pedidos de tu tienda los puedes descargar tú mismo en CSV desde el panel.
      </p>
      <p>
        Si eres comprador de una tienda, dirígete primero a ella: es la responsable de tus datos. Si nos
        escribes a nosotros, se lo haremos llegar.
      </p>

      <h2>7. Seguridad</h2>
      <p>
        Las contraseñas se guardan cifradas, la conexión va cifrada, cada tienda solo puede ver sus propios
        datos, el acceso al panel depende del rol de cada persona y hacemos copias de seguridad diarias.
        Ningún sistema es infalible: si detectamos un acceso indebido que afecte a tus datos, te avisaremos.
      </p>

      <h2>8. Menores de edad</h2>
      <p>
        El servicio está pensado para negocios. No está dirigido a menores de edad ni recogemos datos de
        ellos a sabiendas.
      </p>

      <h2>9. Cambios en esta política</h2>
      <p>
        Si cambiamos esta política de forma importante, te avisaremos por correo o en el panel antes de
        que el cambio se aplique. La fecha de la última versión está al principio de esta página.
      </p>
    </PaginaLegal>
  );
}
