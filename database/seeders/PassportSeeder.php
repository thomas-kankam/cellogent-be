<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;

class PassportSeeder extends Seeder
{
    /**
     * One personal-access client per Passport provider (clients, admins).
     * createToken() looks these up by provider name.
     */
    public function run(): void
    {
        $clients = app(ClientRepository::class);

        foreach (['clients' => 'Cellogent Clients', 'admins' => 'Cellogent Admins'] as $provider => $name) {
            try {
                $clients->personalAccessClient($provider);
            } catch (\RuntimeException) {
                $clients->createPersonalAccessGrantClient($name, $provider);
            }
        }
    }
}
