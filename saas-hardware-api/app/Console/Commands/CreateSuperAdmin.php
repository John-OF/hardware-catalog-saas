<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Crea el usuario operador de la plataforma (SAAS-4).
 *
 * Va por consola y no por una pantalla de alta a proposito: un super-admin ve y
 * suspende todas las tiendas, asi que no puede existir ningun endpoint publico
 * capaz de crear uno.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'platform:superadmin
                            {--email= : Correo del operador}
                            {--name= : Nombre del operador}
                            {--password= : Contrasenia (si se omite, se pide por consola sin mostrarla)}';

    protected $description = 'Crea (o reactiva) el super-admin que administra la plataforma';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Correo del operador');
        $name = $this->option('name') ?: $this->ask('Nombre del operador', 'Operador');

        // Preferimos secret() para que no quede en el historial del shell.
        $password = $this->option('password') ?: $this->secret('Contrasenia (minimo 8 caracteres)');

        $validator = Validator::make(
            compact('email', 'name', 'password'),
            [
                'email'    => 'required|email',
                'name'     => 'required|string|max:200',
                'password' => 'required|string|min:8',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Un correo ya usado por el dueño de una tienda no puede reutilizarse:
        // el login de plataforma y el del panel resuelven ambos por correo.
        $enUso = User::where('email', $email)->where('role', '!=', 'superadmin')->exists();

        if ($enUso) {
            $this->error("El correo {$email} ya pertenece a un usuario de tienda. Usa otro.");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->where('role', 'superadmin')->first();

        if ($user) {
            // Sirve tambien para rescatarse a uno mismo: repetir el comando
            // resetea la contrasenia y reactiva la cuenta.
            $user->update([
                'name'      => $name,
                'password'  => $password,
                'is_active' => true,
            ]);
            $user->tokens()->delete();

            $this->info("Super-admin {$email} actualizado (contrasenia nueva, sesiones cerradas).");

            return self::SUCCESS;
        }

        $user = new User([
            'name'      => $name,
            'email'     => $email,
            'password'  => $password,
            'role'      => 'superadmin',
            'is_active' => true,
        ]);
        // tenant_id se queda en null: el operador no pertenece a ninguna tienda.
        $user->save();

        $this->info("Super-admin {$email} creado. Entra por /platform/login.");

        return self::SUCCESS;
    }
}
