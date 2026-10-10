<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\DosPasos;
use Illuminate\Console\Command;

/**
 * Quita la verificación en dos pasos a un operador (SEC-9).
 *
 * Es la salida para el día que se pierde el móvil **y** los códigos de
 * recuperación, o para cuando cambia la APP_KEY y la semilla cifrada ya no se
 * puede leer. Va por consola y no por el panel por lo mismo que
 * `platform:superadmin`: desde el panel, quien robara una sesión podría
 * apagarla. Aquí hace falta entrar en el servidor.
 *
 * Además cierra las sesiones abiertas de ese operador y lo anota en la bitácora.
 * En producción, al volver a entrar, el panel le pedirá activarla de nuevo.
 */
class RemoveOperatorTwoFactor extends Command
{
    protected $signature = 'platform:quitar-dos-pasos {email : Correo del operador}';

    protected $description = 'Quita la verificación en dos pasos a un operador que perdió el móvil y sus códigos (SEC-9)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        // Sin tienda resuelta, el scope de `User` ya lo ve todo; se pide igual
        // por escrito para que no dependa de eso.
        $operador = User::withoutTenant()
            ->where('email', $email)
            ->where('role', 'superadmin')
            ->first();

        if (! $operador) {
            $this->error("No hay ningún operador de la plataforma con el correo {$email}.");

            return self::FAILURE;
        }

        if ($operador->two_factor_secret === null) {
            $this->info("{$email} no tenía la verificación en dos pasos ni una activación empezada. No se ha tocado nada.");

            return self::SUCCESS;
        }

        DosPasos::quitar($operador);
        $operador->tokens()->delete();

        ActivityLog::registrar(
            ActivityLog::OPERADOR_DOS_PASOS_QUITADA,
            "Se quitó la verificación en dos pasos de {$email} desde el servidor.",
            null,
            null,
            ['email' => $email],
        );

        $this->info("Hecho: {$email} entra otra vez solo con su contraseña, y se le cerraron las sesiones abiertas.");

        if (DosPasos::obligatoria()) {
            $this->warn('En producción el panel le pedirá activarla de nuevo nada más entrar.');
        }

        return self::SUCCESS;
    }
}
