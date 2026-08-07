<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeSuperAdmin extends Command
{
    protected $signature = 'jcheckout:make-super-admin {email : E-mail de uma conta existente}';

    protected $description = 'Concede privilégio de proprietário da plataforma a uma conta existente';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error('Conta não encontrada. Crie a conta normalmente antes de conceder a função.');
            return self::FAILURE;
        }

        if (! $this->confirm("Conceder acesso total da plataforma a {$user->email}?")) {
            $this->info('Operação cancelada.');
            return self::SUCCESS;
        }

        $user->update(['role' => 'super_admin']);
        $this->info("{$user->email} agora é proprietário da plataforma.");

        return self::SUCCESS;
    }
}
