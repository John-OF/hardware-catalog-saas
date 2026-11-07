<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los comprobantes de pago que manda el comprador desde su enlace (MOD-26).
 *
 * Son archivos que sube **un desconocido** y que llevan datos bancarios, así que:
 *
 * - Van al disco **privado** (`local`, `storage/app/private`), nunca al de las
 *   fotos, que se sirve en público. Solo los lee el panel, por su ruta
 *   autenticada (`OrderController::comprobante`).
 * - Se guardan tal cual llegan —una captura o un PDF, validados por su
 *   contenido con `Subidas::reglas('comprobante')`—, sin decodificarlos: una
 *   foto de 50 megapíxeles no puede tumbar a PHP (`INF-14`), y un comprobante
 *   no se enseña en el catálogo, se mira y se borra.
 * - Se sirven con el tipo que se comprobó y con `nosniff` y `sandbox`, para que
 *   el navegador no los interprete como otra cosa.
 * - **Caducan**: `comprobantes:purgar` los borra a los `DIAS_TRAS_REVISAR` de
 *   revisarse el pago, a los `DIAS_MAXIMOS` de subirse aunque nadie los mirara,
 *   y los que se quedaran sin pedido (un pedido purgado de la papelera).
 */
class Comprobantes
{
    public const DISCO = 'local';

    /** Días que se guarda un comprobante después de confirmar o rechazar el pago. */
    public const DIAS_TRAS_REVISAR = 30;

    /** Días que se guarda como mucho, aunque nadie lo revisara. */
    public const DIAS_MAXIMOS = 90;

    private const CARPETA = 'comprobantes';

    /** Los tipos que se sirven, por la extensión con que se guardaron. */
    private const TIPOS = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    /**
     * Guarda el comprobante de un pedido, en la carpeta de su tienda y con un
     * nombre al azar. La extensión sale del contenido, no del nombre del archivo.
     */
    public static function guardar(Order $pedido, UploadedFile $archivo): string
    {
        $extension = match ($archivo->guessExtension()) {
            'jpeg', 'jpg' => 'jpg',
            'png' => 'png',
            'webp' => 'webp',
            default => 'pdf',
        };

        $ruta = self::CARPETA."/{$pedido->tenant_id}/".Str::uuid()->toString().".{$extension}";

        if (Storage::disk(self::DISCO)->put($ruta, (string) file_get_contents($archivo->getRealPath())) === false) {
            throw new \RuntimeException("No se pudo guardar el comprobante en el disco '".self::DISCO."'.");
        }

        return $ruta;
    }

    /** Borra un comprobante. Solo dentro de su carpeta, por si la ruta guardada no fuera suya. */
    public static function borrar(?string $ruta): void
    {
        if ($ruta && str_starts_with($ruta, self::CARPETA.'/') && ! str_contains($ruta, '..')) {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }

    /** El comprobante para el panel, o `null` si ya no está. */
    public static function respuesta(Order $pedido): ?StreamedResponse
    {
        $ruta = $pedido->payment_proof_path;

        if (! $ruta || ! Storage::disk(self::DISCO)->exists($ruta)) {
            return null;
        }

        $extension = pathinfo($ruta, PATHINFO_EXTENSION);
        $nombre = "comprobante-pedido-{$pedido->number}.{$extension}";

        return Storage::disk(self::DISCO)->response($ruta, $nombre, [
            'Content-Type' => self::TIPOS[$extension] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Borra lo que caducó y lo que se quedó sin pedido. Devuelve cuántos archivos
     * borró. Recorre todas las tiendas: corre desde el scheduler, sin tienda.
     */
    public static function purgar(): int
    {
        $borrados = 0;
        $revisadoAntesDe = now()->subDays(self::DIAS_TRAS_REVISAR);
        $subidoAntesDe = now()->subDays(self::DIAS_MAXIMOS);

        $caducados = DB::table('orders')
            ->whereNotNull('payment_proof_path')
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->whereIn('payment_status', [Order::PAGO_CONFIRMADO, Order::PAGO_RECHAZADO])
                    ->where('payment_reviewed_at', '<', $revisadoAntesDe))
                ->orWhere('payment_proof_uploaded_at', '<', $subidoAntesDe))
            ->get(['id', 'payment_proof_path']);

        foreach ($caducados as $pedido) {
            self::borrar($pedido->payment_proof_path);
            DB::table('orders')->where('id', $pedido->id)->update(['payment_proof_path' => null]);
            $borrados++;
        }

        // Los que no apuntan a ningún pedido: un pedido que se purgó de la
        // papelera con un borrado en lote (sin eventos), o una subida que falló
        // a medias. Solo los de más de un día, para no pisar una subida en curso.
        $enUso = DB::table('orders')->whereNotNull('payment_proof_path')->pluck('payment_proof_path')->flip();
        $disco = Storage::disk(self::DISCO);

        foreach ($disco->allFiles(self::CARPETA) as $ruta) {
            if (! isset($enUso[$ruta]) && $disco->lastModified($ruta) < now()->subDay()->getTimestamp()) {
                $disco->delete($ruta);
                $borrados++;
            }
        }

        return $borrados;
    }
}
