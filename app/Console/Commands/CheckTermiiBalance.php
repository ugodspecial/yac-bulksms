<?php

namespace App\Console\Commands;

use App\Services\Provider\ProviderHealthService;
use Illuminate\Console\Command;

class CheckTermiiBalance extends Command
{
    protected $signature = 'termii:balance';

    protected $description = 'Refresh the cached Termii account balance and connection status';

    public function handle(ProviderHealthService $health): int
    {
        $result = $health->checkBalance();

        if ($result->ok) {
            $this->info(sprintf(
                'Termii balance: %s %s',
                number_format((float) $result->balance, 2),
                $result->currency ?? 'NGN',
            ));

            return self::SUCCESS;
        }

        $this->error('Balance check failed: '.$result->safeMessage());

        return self::FAILURE;
    }
}
