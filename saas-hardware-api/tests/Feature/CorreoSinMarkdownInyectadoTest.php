<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\NewOrderNotification;
use App\Notifications\TeamInvitationNotification;
use App\Support\TextoDeCorreo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Que nadie meta un enlace en un correo de la tienda (`SEC-8`).
 *
 * **Lo que fallaba.** Las líneas de `MailMessage` se renderizan como Markdown.
 * El HTML sí se escapa por el camino, así que `<script>` llega como texto y no
 * hay XSS; pero el Markdown se interpreta entero y
 * `[Verifica tu cuenta](http://malo)` sale como un enlace de verdad.
 * `NewOrderNotification` interpola el nombre, el teléfono, la nota y la entrega
 * del checkout —**público, sin autenticación**— en un correo que recibe el
 * dueño, enviado desde el servidor de su propia tienda con SPF y DKIM en regla:
 * phishing dentro de un aviso legítimo, y el vector no necesita ni una cuenta.
 *
 * **Se afirma sobre el HTML renderizado y no sobre `introLines`.** El fallo
 * aparece al convertir el Markdown en HTML: mirando las líneas se ve el texto
 * tal cual y todo parece correcto. Es el mismo error que se evitó en `SEC-6`
 * afirmando sobre el HTML crudo en vez de sobre el JSON decodificado.
 */
class CorreoSinMarkdownInyectadoTest extends TestCase
{
    use RefreshDatabase;

    /** El payload: un enlace de Markdown metido en un campo de texto. */
    private const ENLACE = '[Confirma tu cuenta aqui](http://malo.example/robo)';

    private Tenant $tienda;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tienda = Tenant::create([
            'slug' => 'tienda-correos',
            'name' => 'Tienda Correos',
            'whatsapp_number' => '51999000111',
            'is_active' => true,
            'is_published' => true,
            'currency' => 'PEN',
        ]);

        $this->admin = new User([
            'name' => 'Duenio',
            'email' => 'duenio@tienda-correos.com',
            'password' => 'password123',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->admin->tenant_id = $this->tienda->id;
        $this->admin->save();
    }

    // ------------------------------------------------- el correo mas expuesto

    public function test_el_nombre_del_comprador_no_puede_meter_un_enlace(): void
    {
        $html = $this->htmlDelAviso(['customer_name' => 'Juan '.self::ENLACE]);

        $this->assertStringNotContainsString('href="http://malo.example', $html);
        $this->assertStringContainsString('malo.example/robo', $html, 'El texto tiene que seguir llegando: se neutraliza, no se censura.');
    }

    public function test_la_nota_del_comprador_no_puede_meter_un_enlace(): void
    {
        $html = $this->htmlDelAviso(['customer_note' => 'Gracias. '.self::ENLACE]);

        $this->assertStringNotContainsString('href="http://malo.example', $html);
    }

    /**
     * **Este caso pasaba también antes del arreglo, y se queda a propósito.** Un
     * nombre con saltos de línea no llega a forjar un título ni una raya, pero no
     * por nada que hagamos nosotros: la plantilla de Laravel junta las líneas
     * antes de que CommonMark las vea. Esa garantía es de la plantilla, así que
     * el caso está aquí para que se entere quien la cambie —o quien publique una
     * propia— y no para demostrar el arreglo.
     */
    public function test_un_nombre_con_saltos_de_linea_no_puede_forjar_un_titulo(): void
    {
        $html = $this->htmlDelAviso([
            'customer_name' => "Juan\n# TU CUENTA SERA SUSPENDIDA\n---\nEscribe a soporte",
        ]);

        $normal = $this->htmlDelAviso(['customer_name' => 'Juan']);

        // Contra una línea base y no contra cero: el aviso de pedido ya trae de
        // serie un `<h1>` —el saludo— y un `<hr>` —el `---` que separa las líneas
        // del pedido—. Lo que se vigila es que el payload no añada ni uno más.
        foreach (['<h1', '<h2', '<hr'] as $etiqueta) {
            $this->assertSame(
                substr_count($normal, $etiqueta),
                substr_count($html, $etiqueta),
                "El nombre del comprador metió un {$etiqueta} de su cuenta.",
            );
        }

        // El texto sigue llegando: se neutraliza el formato, no se censura.
        $this->assertStringContainsString('TU CUENTA SERA SUSPENDIDA', $html);
    }

    public function test_el_telefono_no_puede_meter_un_enlace(): void
    {
        $html = $this->htmlDelAviso(['customer_phone' => '999 '.self::ENLACE]);

        $this->assertStringNotContainsString('href="http://malo.example', $html);
    }

    /**
     * El arreglo no puede pasarse de listo: un pedido normal tiene que seguir
     * leyéndose igual que antes, sin barras invertidas a la vista y con el
     * Markdown **nuestro** —la negrita del nombre y del total— intacto. Es la
     * mitad que un escapado a lo bruto habría roto.
     */
    public function test_un_pedido_normal_se_lee_igual_que_antes(): void
    {
        $html = $this->htmlDelAviso([
            'customer_name' => "Ana O'Brien-Smith",
            'customer_phone' => '+51 (999) 888-777',
        ]);

        $this->assertStringContainsString('<strong', $html);
        $this->assertStringContainsString("Ana O'Brien-Smith", $html);
        $this->assertStringContainsString('+51 (999) 888-777', $html);
        $this->assertStringNotContainsString('\\', $html);
    }

    // --------------------------------------------------- la otra puerta abierta

    /**
     * El segundo correo más expuesto: lo manda un admin a una dirección que él
     * escribe, y quien lo recibe todavía no tiene cuenta con la que comprobar
     * nada.
     */
    public function test_el_nombre_de_la_tienda_no_puede_meter_un_enlace_en_la_invitacion(): void
    {
        $invitado = new User([
            'name' => 'Invitada',
            'email' => 'invitada@tienda-correos.com',
            'password' => 'password123',
            'role' => 'staff',
            'is_active' => false,
        ]);
        $invitado->tenant_id = $this->tienda->id;
        $invitado->save();

        $notificacion = new TeamInvitationNotification(
            token: 'un-token',
            tienda: 'Tienda '.self::ENLACE,
            invitadoPor: 'El jefe',
        );

        $html = (string) $notificacion->toMail($invitado)->render();

        $this->assertStringNotContainsString('href="http://malo.example', $html);
    }

    // ------------------------------------------------------------- el ayudante

    public function test_escapa_lo_que_crea_estructura_y_deja_en_paz_lo_demas(): void
    {
        // Los seis que sí: barra, tilde invertida, asterisco, guion bajo y corchetes.
        // Los parentesis NO se escapan, y no hace falta: sin un `[` delante no son
        // un enlace, y dejarlos en paz es lo que salva al telefono de mas abajo.
        $this->assertSame('\\[x\\](y)', TextoDeCorreo::enLinea('[x](y)'));
        $this->assertSame('\\*no negrita\\*', TextoDeCorreo::enLinea('*no negrita*'));
        $this->assertSame('\\`no codigo\\`', TextoDeCorreo::enLinea('`no codigo`'));

        // Los que no: un teléfono y un apellido no se ensucian.
        $this->assertSame('+51 (999) 888-777', TextoDeCorreo::enLinea('+51 (999) 888-777'));
        $this->assertSame("O'Brien-Smith", TextoDeCorreo::enLinea("O'Brien-Smith"));

        // `<` y `>` los convierte en entidades la plantilla de Laravel antes de
        // que CommonMark los vea, así que aquí se dejan intactos a propósito.
        $this->assertSame('a <b> c', TextoDeCorreo::enLinea('a <b> c'));
    }

    public function test_en_linea_aplasta_los_saltos_y_en_parrafo_los_conserva(): void
    {
        $this->assertSame('Juan # Titulo', TextoDeCorreo::enLinea("Juan\n# Titulo"));

        // Un párrafo conserva el salto, así que lo que sólo cuenta al empezar una
        // línea se escapa ahí: título, raya, lista y tabla.
        $this->assertSame("Gracias\n\\# Titulo", TextoDeCorreo::enParrafo("Gracias\n# Titulo"));
        $this->assertSame("Gracias\n\\---", TextoDeCorreo::enParrafo("Gracias\n--- "));
        // De la tabla basta con escapar el primero: sin la barra que abre la
        // fila, los de dentro son texto.
        $this->assertSame("Gracias\n\\| a | b |", TextoDeCorreo::enParrafo("Gracias\n| a | b |"));

        // Cuatro espacios al principio son un bloque de código: el trim de cada
        // línea no es cosmético.
        $this->assertSame("Gracias\nno codigo", TextoDeCorreo::enParrafo("Gracias\n    no codigo"));
    }

    // -------------------------------------------------------------- ayudantes

    /**
     * El HTML del aviso de pedido nuevo, con los campos del pedido que se le
     * pasen.
     *
     * @param  array<string, mixed>  $campos
     */
    private function htmlDelAviso(array $campos): string
    {
        $pedido = new Order(array_merge([
            'customer_name' => 'Ana Compradora',
            'customer_phone' => '51988877766',
            'status' => 'pending',
            'total' => 100,
        ], $campos));
        $pedido->tenant_id = $this->tienda->id;
        $pedido->save();

        $notificacion = new NewOrderNotification($pedido->fresh()->load('items'));

        return (string) $notificacion->toMail($this->admin)->render();
    }
}
